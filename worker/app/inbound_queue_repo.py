from __future__ import annotations

# =============================================================================
# inbound_queue_repo.py — Portal ICBF
# CAMBIOS v2 (2026-03-12):
#   - enqueue_event ahora acepta force=True para re-encolar mensajes
#     aunque ya existan en messages. Permite recuperar casos perdidos.
#   - Lógica de reciclaje de eventos existentes (done/failed → pending)
#     se mantiene del original, pero solo cuando force=False.
#   - Con force=True: inserta siempre un evento nuevo (si no hay uno
#     pending/processing en los últimos 5 minutos).
# =============================================================================

import json
import logging
from typing import Any

from sqlalchemy import text

logger = logging.getLogger("app.inbound_queue_repo")


# ---------------------------------------------------------------------------
# Helpers internos
# ---------------------------------------------------------------------------

def _is_materialized_message(
    db, *, mailbox_email: str, provider_message_id: str
) -> bool:
    row = db.execute(
        text("""
            SELECT m.id
            FROM messages m
            JOIN mailboxes mb ON mb.id = m.mailbox_id
            WHERE mb.email = :mailbox
              AND m.provider_message_id = :pmid
              AND m.case_id IS NOT NULL
            LIMIT 1
        """),
        {"mailbox": mailbox_email, "pmid": provider_message_id},
    ).fetchone()
    return bool(row)


# ---------------------------------------------------------------------------
# enqueue_event
# ---------------------------------------------------------------------------

def enqueue_event(
    db,
    *,
    source: str,
    provider_message_id: str,
    mailbox_email: str,
    payload: dict[str, Any] | None = None,
    force: bool = False,
) -> int | None:
    """
    Encola un mensaje para procesamiento.

    Comportamiento normal (force=False):
        - Si el mensaje ya existe en messages → retorna None (ya materializado).
        - Si existe un evento previo done/processing/pending sin materialización
          → recicla ese evento a 'pending' (comportamiento original mejorado).
        - Si no existe nada → inserta evento nuevo.

    Comportamiento forzado (force=True):
        - Ignora si el mensaje ya existe en messages.
        - Si hay un evento pending/processing creado en los últimos 5 minutos
          → retorna None (evita duplicados en vuelo).
        - En cualquier otro caso → inserta evento nuevo.
        - Útil para recuperar casos perdidos o reprocesamiento masivo.

    Returns:
        ID del evento creado/reciclado, o None si se saltó.
    """

    payload_json = json.dumps(payload, ensure_ascii=False) if payload else None

    # ------------------------------------------------------------------
    # MODO NORMAL (force=False): comportamiento original
    # ------------------------------------------------------------------
    if not force:
        # 1. Si ya está materializado en messages → skip
        if _is_materialized_message(
            db,
            mailbox_email=mailbox_email,
            provider_message_id=provider_message_id,
        ):
            return None

        # 2. Si existe un evento previo (en cualquier estado) → reciclar a pending
        #    Esto cubre el caso de done sin materialización (hueco operativo)
        row_existing = db.execute(
            text("""
                SELECT id, status
                FROM inbound_event_queue
                WHERE provider_message_id = :pmid
                  AND mailbox_email = :mailbox
                ORDER BY id DESC
                LIMIT 1
            """),
            {"pmid": provider_message_id, "mailbox": mailbox_email},
        ).fetchone()

        if row_existing:
            event_id = int(row_existing[0])
            current_status = str(row_existing[1] or "").lower()

            # Si ya está pending o processing, no hacer nada
            if current_status in ("pending", "processing"):
                return None

            # Reciclar evento fallido o done a pending
            db.execute(
                text("""
                    UPDATE inbound_event_queue
                    SET source       = :source,
                        payload_json = :payload,
                        status       = 'pending',
                        attempts     = CASE
                                           WHEN status = 'failed' THEN 0
                                           ELSE attempts
                                       END,
                        last_error   = NULL,
                        available_at = NOW(6),
                        locked_at    = NULL,
                        processed_at = NULL,
                        updated_at   = NOW(6)
                    WHERE id = :id
                """),
                {
                    "id": event_id,
                    "source": source,
                    "payload": payload_json,
                },
            )
            logger.debug(
                "ENQUEUE_RECYCLED | source=%s | event_id=%s | prev_status=%s | message_id=%s",
                source, event_id, current_status, provider_message_id,
            )
            return event_id

        # 3. No existe nada → insertar evento nuevo
        db.execute(
            text("""
                INSERT INTO inbound_event_queue (
                    source,
                    provider_message_id,
                    mailbox_email,
                    payload_json,
                    status,
                    attempts,
                    available_at,
                    created_at,
                    updated_at
                )
                VALUES (
                    :source,
                    :pmid,
                    :mailbox,
                    :payload,
                    'pending',
                    0,
                    NOW(6),
                    NOW(6),
                    NOW(6)
                )
            """),
            {
                "source": source,
                "pmid": provider_message_id,
                "mailbox": mailbox_email,
                "payload": payload_json,
            },
        )

        row2 = db.execute(text("SELECT LAST_INSERT_ID()")).fetchone()
        event_id = int(row2[0]) if row2 else None

        if event_id:
            logger.debug(
                "ENQUEUE_OK | source=%s | event_id=%s | message_id=%s",
                source, event_id, provider_message_id,
            )
        return event_id

    # ------------------------------------------------------------------
    # MODO FORZADO (force=True): siempre crea caso nuevo
    # ------------------------------------------------------------------

    # Evitar duplicar si ya hay un pending/processing reciente (últimos 5 min)
    recent = db.execute(
        text("""
            SELECT id
            FROM inbound_event_queue
            WHERE provider_message_id = :pmid
              AND mailbox_email        = :mailbox
              AND status               IN ('pending', 'processing')
              AND created_at          >= DATE_SUB(NOW(6), INTERVAL 5 MINUTE)
            LIMIT 1
        """),
        {"pmid": provider_message_id, "mailbox": mailbox_email},
    ).fetchone()

    if recent:
        logger.info(
            "ENQUEUE_SKIP_RECENT_PENDING | force=True | message_id=%s",
            provider_message_id,
        )
        return None

    # Insertar evento nuevo (ignorando si ya existe en messages)
    db.execute(
        text("""
            INSERT INTO inbound_event_queue (
                source,
                provider_message_id,
                mailbox_email,
                payload_json,
                status,
                attempts,
                available_at,
                created_at,
                updated_at
            )
            VALUES (
                :source,
                :pmid,
                :mailbox,
                :payload,
                'pending',
                0,
                NOW(6),
                NOW(6),
                NOW(6)
            )
        """),
        {
            "source": source,
            "pmid": provider_message_id,
            "mailbox": mailbox_email,
            "payload": payload_json,
        },
    )

    row2 = db.execute(text("SELECT LAST_INSERT_ID()")).fetchone()
    event_id = int(row2[0]) if row2 else None

    if event_id:
        logger.debug(
            "ENQUEUE_FORCE_OK | source=%s | event_id=%s | message_id=%s",
            source, event_id, provider_message_id,
        )
    return event_id


# ---------------------------------------------------------------------------
# claim_pending_events
# ---------------------------------------------------------------------------

def claim_pending_events(db, *, batch_size: int) -> list[dict[str, Any]]:
    # Liberar eventos que llevan más de 10 minutos en 'processing' (stale locks)
    db.execute(
        text("""
            UPDATE inbound_event_queue
            SET status    = 'pending',
                locked_at = NULL,
                updated_at = NOW(6)
            WHERE status     = 'processing'
              AND locked_at  IS NOT NULL
              AND locked_at  < DATE_SUB(NOW(6), INTERVAL 10 MINUTE)
        """)
    )

    rows = db.execute(
        text("""
            SELECT id, source, provider_message_id, mailbox_email, attempts,
                   created_at, payload_json
            FROM inbound_event_queue
            WHERE status       = 'pending'
              AND available_at <= NOW(6)
            ORDER BY available_at ASC, id ASC
            LIMIT :limit
        """),
        {"limit": batch_size},
    ).mappings().all()

    claimed: list[dict[str, Any]] = []

    for row in rows:
        upd = db.execute(
            text("""
                UPDATE inbound_event_queue
                SET status    = 'processing',
                    locked_at = NOW(6),
                    updated_at = NOW(6)
                WHERE id     = :id
                  AND status = 'pending'
            """),
            {"id": row["id"]},
        )
        if upd.rowcount == 1:
            claimed.append(dict(row))

    return claimed


# ---------------------------------------------------------------------------
# mark_done / mark_retry
# ---------------------------------------------------------------------------

def mark_done(db, *, event_id: int) -> None:
    db.execute(
        text("""
            UPDATE inbound_event_queue
            SET status       = 'done',
                processed_at = NOW(6),
                updated_at   = NOW(6)
            WHERE id = :id
        """),
        {"id": event_id},
    )


def mark_retry(
    db,
    *,
    event_id: int,
    attempts: int,
    error: str,
    max_attempts: int,
    payload_json: str | None = None,
) -> None:
    """
    payload_json (opcional, Fase C): si viene, actualiza la columna con
    el string ya combinado (ver merge_stability_snapshot) - se aplica
    solo en la rama 'pending' vía COALESCE, para no pisar nada cuando no
    se pasa, y para no tener sentido escribirlo si la fila pasa a
    'failed' (ese intento ya terminó).
    """
    next_attempt = attempts + 1

    if next_attempt >= max_attempts:
        db.execute(
            text("""
                UPDATE inbound_event_queue
                SET status    = 'failed',
                    attempts  = :attempts,
                    last_error = :err,
                    locked_at = NULL,
                    updated_at = NOW(6)
                WHERE id = :id
            """),
            {
                "id": event_id,
                "attempts": next_attempt,
                "err": error[:1000],
            },
        )
        return

    delay_seconds = _retry_delay_seconds(next_attempt)

    db.execute(
        text("""
            UPDATE inbound_event_queue
            SET status       = 'pending',
                attempts     = :attempts,
                last_error   = :err,
                available_at = DATE_ADD(NOW(6), INTERVAL :delay SECOND),
                locked_at    = NULL,
                updated_at   = NOW(6),
                payload_json = COALESCE(:payload_json, payload_json)
            WHERE id = :id
        """),
        {
            "id": event_id,
            "attempts": next_attempt,
            "err": error[:1000],
            "delay": delay_seconds,
            "payload_json": payload_json,
        },
    )


# ---------------------------------------------------------------------------
# Namespace reservado dentro de payload_json para el snapshot de
# estabilización de hasAttachments=false (Fase C). payload_json se
# escribía pero nunca se leía en el código (confirmado antes de
# reutilizarlo) - aun así, estos helpers NUNCA descartan el contenido
# original: lo preservan bajo la misma clave, o bajo una clave de
# respaldo si no tiene la forma esperada.
# ---------------------------------------------------------------------------

_STABILITY_NAMESPACE_KEY = "_internal"
_STABILITY_SNAPSHOT_KEY = "attachments_stability"


def extract_stability_snapshot(payload_json: str | None) -> dict[str, Any] | None:
    """
    Lee el snapshot de estabilización de adjuntos guardado en
    payload_json._internal.attachments_stability. Defensivo: cualquier
    forma inesperada (JSON inválido, no es un objeto, falta la clave) se
    trata como "sin snapshot" - nunca lanza.
    """
    if not payload_json:
        return None
    try:
        parsed = json.loads(payload_json)
    except (json.JSONDecodeError, TypeError):
        return None
    if not isinstance(parsed, dict):
        return None
    internal = parsed.get(_STABILITY_NAMESPACE_KEY)
    if not isinstance(internal, dict):
        return None
    snapshot = internal.get(_STABILITY_SNAPSHOT_KEY)
    return snapshot if isinstance(snapshot, dict) else None


def merge_stability_snapshot(payload_json: str | None, snapshot: dict[str, Any]) -> str:
    """
    Combina `snapshot` dentro del namespace reservado
    _internal.attachments_stability, preservando cualquier contenido
    previo de payload_json (ej. la notificación cruda del webhook, o
    cualquier otra clave ya presente en _internal).

    Defensivo ante payload_json existente con forma inesperada:
      - JSON inválido -> se conserva el string original bajo
        "_original_unparseable_payload", no se pierde.
      - JSON válido pero no es un objeto (ej. una lista) -> se conserva
        bajo "_original_non_object_payload".
    """
    parsed: dict[str, Any]

    if not payload_json:
        parsed = {}
    else:
        try:
            candidate = json.loads(payload_json)
        except (json.JSONDecodeError, TypeError):
            parsed = {"_original_unparseable_payload": payload_json}
        else:
            parsed = candidate if isinstance(candidate, dict) else {
                "_original_non_object_payload": candidate
            }

    internal = parsed.get(_STABILITY_NAMESPACE_KEY)
    if not isinstance(internal, dict):
        internal = {}
    internal[_STABILITY_SNAPSHOT_KEY] = snapshot
    parsed[_STABILITY_NAMESPACE_KEY] = internal

    return json.dumps(parsed, ensure_ascii=False)


def mark_retry_unbounded(
    db,
    *,
    event_id: int,
    attempts: int,
    error: str,
    queue_event_age_seconds: int,
    alert_age_seconds: int,
    long_retry_seconds: int,
) -> None:
    """
    Variante de mark_retry() para motivos que NUNCA deben agotar el
    presupuesto ni marcarse 'failed' (hoy: solo MISSING_RECEIVED_DATETIME
    - ver sync_service.STATUS_MISSING_RECEIVED_DATETIME). La fila siempre
    vuelve a 'pending', nunca a 'failed', sin importar cuántos intentos
    lleve.

    Backoff en dos tramos, para no golpear Graph cada ~30 min de forma
    indefinida en un mensaje que quizás nunca resuelva su fecha:
      - Mientras queue_event_age_seconds <= alert_age_seconds: mismo
        ladder que mark_retry() (30/120/300/900/1800s vía
        _retry_delay_seconds), sin cambios de comportamiento percibido
        para el caso común (se resuelve en los primeros minutos).
      - Al superar alert_age_seconds: intervalo fijo largo
        (long_retry_seconds) - long-tail retry. La responsabilidad de
        loguear la alerta operacional (ALERT_STALLED_MISSING_RECEIVED_
        DATETIME) es de quien llama a esta función (inbound_queue_worker),
        no de este módulo de acceso a datos.

    queue_event_age_seconds es la edad de ESTA fila de
    inbound_event_queue (columna created_at), no necesariamente el
    momento en que Graph notificó el mensaje por primera vez:
    enqueue_event(force=True) puede crear una fila nueva con
    created_at=NOW() para un mensaje ya visto antes (ej. reprocesamiento
    administrativo). En el reciclaje normal (force=False) sí se conserva
    el created_at original.
    """
    next_attempt = attempts + 1

    if queue_event_age_seconds > alert_age_seconds:
        delay_seconds = long_retry_seconds
    else:
        delay_seconds = _retry_delay_seconds(next_attempt)

    db.execute(
        text("""
            UPDATE inbound_event_queue
            SET status       = 'pending',
                attempts     = :attempts,
                last_error   = :err,
                available_at = DATE_ADD(NOW(6), INTERVAL :delay SECOND),
                locked_at    = NULL,
                updated_at   = NOW(6)
            WHERE id = :id
        """),
        {
            "id": event_id,
            "attempts": next_attempt,
            "err": error[:1000],
            "delay": delay_seconds,
        },
    )


# ---------------------------------------------------------------------------
# Utilidades de operación / monitoreo
# ---------------------------------------------------------------------------

def queue_stats(db) -> dict[str, int]:
    rows = db.execute(
        text("""
            SELECT status, COUNT(*) AS total
            FROM inbound_event_queue
            GROUP BY status
        """)
    ).fetchall()

    out = {"pending": 0, "processing": 0, "done": 0, "failed": 0}
    for status, total in rows:
        out[str(status)] = int(total)
    return out


def list_failed_events(db, *, limit: int = 100) -> list[dict[str, Any]]:
    rows = db.execute(
        text("""
            SELECT id, source, provider_message_id, mailbox_email,
                   attempts, last_error, updated_at
            FROM inbound_event_queue
            WHERE status = 'failed'
            ORDER BY updated_at DESC
            LIMIT :limit
        """),
        {"limit": limit},
    ).mappings().all()

    return [dict(r) for r in rows]


def requeue_failed_events(db, *, limit: int = 1000) -> int:
    upd = db.execute(
        text("""
            UPDATE inbound_event_queue
            SET status       = 'pending',
                attempts     = 0,
                last_error   = NULL,
                available_at = NOW(6),
                locked_at    = NULL,
                processed_at = NULL,
                updated_at   = NOW(6)
            WHERE status = 'failed'
            ORDER BY updated_at DESC
            LIMIT :limit
        """),
        {"limit": limit},
    )
    return int(upd.rowcount or 0)


# ---------------------------------------------------------------------------
# Retry delay (backoff exponencial)
# ---------------------------------------------------------------------------

def _retry_delay_seconds(attempt_no: int) -> int:
    if attempt_no <= 1:
        return 30
    if attempt_no == 2:
        return 120
    if attempt_no == 3:
        return 300
    if attempt_no == 4:
        return 900
    return 1800