from __future__ import annotations

import json
from typing import Any

from sqlalchemy import text


def enqueue_event(
    db,
    *,
    source: str,
    provider_message_id: str,
    mailbox_email: str,
    payload: dict[str, Any] | None = None,
) -> int | None:
    """
    Regla operativa alineada al portal:

    - Cada correo real NUEVO debe crear un caso nuevo.
    - Solo el mismo provider_message_id NO debe volver a procesarse.
    - Se conserva la resiliencia del doble camino (webhook + cola DB),
      pero evitando reprocesos innecesarios del mismo mensaje exacto.

    Comportamiento:
    1) Si el mensaje ya fue materializado en `messages`, NO reinsertar en cola.
    2) Si ya existe en cola en pending/processing/done, NO duplicar el evento.
    3) Si existe en failed, reciclar ese mismo evento a pending.
    4) Si no existe en ningún lado, insertar nuevo evento pending.
    """

    payload_json = json.dumps(payload, ensure_ascii=False) if payload else None

    # ============================================================
    # 1) Si ya existe en messages, significa que ese correo exacto
    #    ya fue procesado/materializado. No reinsertar en cola.
    # ============================================================
    row_msg = db.execute(
        text("""
            SELECT m.id
            FROM messages m
            JOIN mailboxes mb ON mb.id = m.mailbox_id
            WHERE mb.email = :mailbox
              AND m.provider_message_id = :pmid
            LIMIT 1
        """),
        {"mailbox": mailbox_email, "pmid": provider_message_id},
    ).fetchone()

    if row_msg:
        return None

    # ============================================================
    # 2) Si ya existe un evento activo o ya completado para este
    #    mismo provider_message_id, no crear otro.
    #
    #    Ojo:
    #    - pending / processing evita carreras y duplicados simultáneos
    #    - done evita reprocesar una y otra vez el mismo mensaje exacto
    # ============================================================
    row_existing = db.execute(
        text("""
            SELECT id
            FROM inbound_event_queue
            WHERE provider_message_id = :pmid
              AND mailbox_email = :mailbox
              AND status IN ('pending', 'processing', 'done')
            ORDER BY id DESC
            LIMIT 1
        """),
        {"pmid": provider_message_id, "mailbox": mailbox_email},
    ).fetchone()

    if row_existing:
        return int(row_existing[0])

    # ============================================================
    # 3) Si existe failed para el mismo mensaje exacto, reciclamos
    #    ese evento para reintento controlado en vez de crear otro.
    # ============================================================
    row_failed = db.execute(
        text("""
            SELECT id
            FROM inbound_event_queue
            WHERE provider_message_id = :pmid
              AND mailbox_email = :mailbox
              AND status = 'failed'
            ORDER BY id DESC
            LIMIT 1
        """),
        {"pmid": provider_message_id, "mailbox": mailbox_email},
    ).fetchone()

    if row_failed:
        event_id = int(row_failed[0])

        db.execute(
            text("""
                UPDATE inbound_event_queue
                SET source = :source,
                    payload_json = :payload,
                    status = 'pending',
                    attempts = 0,
                    last_error = NULL,
                    available_at = NOW(6),
                    locked_at = NULL,
                    processed_at = NULL,
                    updated_at = NOW(6)
                WHERE id = :id
            """),
            {
                "id": event_id,
                "source": source,
                "payload": payload_json,
            },
        )

        return event_id

    # ============================================================
    # 4) Insertar evento nuevo
    # ============================================================
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
    return int(row2[0]) if row2 else None


def claim_pending_events(db, *, batch_size: int) -> list[dict[str, Any]]:
    """
    Reclama eventos pending disponibles y los marca processing.
    Además, libera eventos atascados en processing.
    """
    # 1) Liberar eventos atascados en processing por más de 10 minutos
    db.execute(
        text("""
            UPDATE inbound_event_queue
            SET status = 'pending',
                locked_at = NULL,
                updated_at = NOW(6)
            WHERE status = 'processing'
              AND locked_at IS NOT NULL
              AND locked_at < DATE_SUB(NOW(6), INTERVAL 10 MINUTE)
        """)
    )

    # 2) Reclamar nuevos pending
    rows = db.execute(
        text("""
            SELECT id, source, provider_message_id, mailbox_email, attempts
            FROM inbound_event_queue
            WHERE status = 'pending'
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
                SET status = 'processing',
                    locked_at = NOW(6),
                    updated_at = NOW(6)
                WHERE id = :id
                  AND status = 'pending'
            """),
            {"id": row["id"]},
        )
        if upd.rowcount == 1:
            claimed.append(dict(row))

    return claimed


def mark_done(db, *, event_id: int) -> None:
    db.execute(
        text("""
            UPDATE inbound_event_queue
            SET status = 'done',
                processed_at = NOW(6),
                updated_at = NOW(6)
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
) -> None:
    next_attempt = attempts + 1

    if next_attempt >= max_attempts:
        db.execute(
            text("""
                UPDATE inbound_event_queue
                SET status = 'failed',
                    attempts = :attempts,
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
            SET status = 'pending',
                attempts = :attempts,
                last_error = :err,
                available_at = DATE_ADD(NOW(6), INTERVAL :delay SECOND),
                locked_at = NULL,
                updated_at = NOW(6)
            WHERE id = :id
        """),
        {
            "id": event_id,
            "attempts": next_attempt,
            "err": error[:1000],
            "delay": delay_seconds,
        },
    )


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
            SELECT id, source, provider_message_id, mailbox_email, attempts, last_error, updated_at
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
            SET status = 'pending',
                attempts = 0,
                last_error = NULL,
                available_at = NOW(6),
                locked_at = NULL,
                processed_at = NULL,
                updated_at = NOW(6)
            WHERE status = 'failed'
            ORDER BY updated_at DESC
            LIMIT :limit
        """),
        {"limit": limit},
    )
    return int(upd.rowcount or 0)


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