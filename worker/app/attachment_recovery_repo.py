from __future__ import annotations

# =============================================================================
# attachment_recovery_repo.py — Portal ICBF (D2)
#
# Acceso crudo a la tabla operacional `attachment_recovery`. Reemplaza el
# criterio viejo "0 filas persistidas" (COUNT(*) == 0) por identidad real
# vía graph_attachment_id - ver attachment_recovery.py para el algoritmo
# de diff/estabilización que usa estas funciones.
#
# Patrón CAS reforzado (auditoría D2 final): el claim hace
# SELECT candidatos -> UPDATE compare-and-swap por fila incluyendo
# selected_status + available_at + stale-lock en el WHERE -> SOLO si
# gana (rowcount==1), vuelve a leer la fila completa desde la BD antes de
# procesarla. Nunca se usan los valores del SELECT inicial para decidir
# nada - podrían estar obsoletos si alguien más tocó la fila entre el
# SELECT y el UPDATE.
# =============================================================================

import json
import logging
from typing import Any

from sqlalchemy import text

logger = logging.getLogger("app.attachment_recovery_repo")


def upsert_pending(
    db,
    *,
    message_id: int,
    reason: str,
    locked: bool = False,
) -> None:
    """
    Crea o recicla la fila de recovery para un mensaje.

    locked=True (MANIFEST_DETECTED, foreground): la fila queda
    locked_at=NOW() - el foreground es temporalmente el dueño mientras
    _process_attachments corre; el background NO puede reclamarla hasta
    que el lock quede stale (crash) o el foreground lo libere
    explícitamente al terminar.

    locked=False (ATTACHMENT_MANIFEST_NOT_READY): no hay procesamiento
    foreground activo - la fila queda inmediatamente disponible para el
    background.

    Es un upsert intencionalmente conservador: si la fila ya existe y
    está en un estado más avanzado (verifying/complete/blocked), no la
    regresa a pending - eso solo debe pasar a través del ciclo normal de
    recovery (manifest_diff/stabilization), nunca al re-detectar el
    mismo manifiesto dos veces.
    """
    locked_clause = "NOW(6)" if locked else "NULL"
    db.execute(
        text(f"""
            INSERT INTO attachment_recovery
                (message_id, status, last_reason, locked_at, first_seen_at)
            VALUES
                (:message_id, 'pending', :reason, {locked_clause}, NOW(6))
            ON DUPLICATE KEY UPDATE
                last_reason = IF(
                    status IN ('complete','blocked'), last_reason, VALUES(last_reason)
                ),
                locked_at = IF(
                    status IN ('complete','blocked'), locked_at, VALUES(locked_at)
                )
        """),
        {"message_id": message_id, "reason": reason[:80]},
    )


def release_foreground_lock(
    db,
    *,
    message_id: int,
    status: str,
    reason: str,
    error: str = "",
    expected_count: int | None = None,
    downloaded_count: int | None = None,
    available_at_delay_seconds: int = 0,
) -> None:
    """
    Libera el lock que puso upsert_pending(locked=True) al terminar el
    procesamiento foreground (_process_attachments dentro de
    _process_single_message). status debe ser 'pending' (transient),
    'verifying' (N/N primera lectura) o 'blocked' (permanente) - nunca
    'complete' directo desde el foreground (la estabilización de dos
    lecturas siempre pasa por el background, ver regla F).
    """
    db.execute(
        text("""
            UPDATE attachment_recovery
            SET status = :status,
                last_reason = :reason,
                last_error = :error,
                expected_count = COALESCE(:expected, expected_count),
                downloaded_count = COALESCE(:downloaded, downloaded_count),
                available_at = DATE_ADD(NOW(6), INTERVAL :delay SECOND),
                locked_at = NULL,
                last_checked_at = NOW(6),
                updated_at = NOW(6)
            WHERE message_id = :message_id
        """),
        {
            "message_id": message_id,
            "status": status,
            "reason": reason[:80],
            "error": error[:1000],
            "expected": expected_count,
            "downloaded": downloaded_count,
            "delay": available_at_delay_seconds,
        },
    )


def claim_batch(
    db,
    *,
    batch_size: int,
    stale_lock_minutes: int,
) -> list[dict[str, Any]]:
    """
    SELECT candidatos + UPDATE CAS por fila + relectura post-claim.
    Solo reclama status IN ('pending','verifying') con available_at
    vencido y sin lock activo (o con lock stale). Cada fila devuelta es
    el estado FRESCO leído después de ganar el claim, nunca el snapshot
    del SELECT inicial.
    """
    candidates = db.execute(
        text("""
            SELECT message_id, status
            FROM attachment_recovery
            WHERE status IN ('pending','verifying')
              AND available_at <= NOW(6)
              AND (locked_at IS NULL OR locked_at < NOW(6) - INTERVAL :stale_min MINUTE)
            ORDER BY available_at ASC
            LIMIT :limit
        """),
        {"limit": batch_size, "stale_min": stale_lock_minutes},
    ).mappings().all()

    claimed: list[dict[str, Any]] = []
    for row in candidates:
        result = db.execute(
            text("""
                UPDATE attachment_recovery
                SET locked_at = NOW(6)
                WHERE message_id = :mid
                  AND status = :selected_status
                  AND available_at <= NOW(6)
                  AND (locked_at IS NULL OR locked_at < NOW(6) - INTERVAL :stale_min MINUTE)
            """),
            {
                "mid": row["message_id"],
                "selected_status": row["status"],
                "stale_min": stale_lock_minutes,
            },
        )
        if result.rowcount == 1:
            fresh = db.execute(
                text("SELECT * FROM attachment_recovery WHERE message_id = :mid"),
                {"mid": row["message_id"]},
            ).mappings().first()
            if fresh is not None:
                claimed.append(dict(fresh))

    return claimed


def mark_pending_retry(
    db,
    *,
    message_id: int,
    attempts: int,
    reason: str,
    error: str,
    delay_seconds: int,
    expected_count: int | None = None,
    downloaded_count: int | None = None,
) -> None:
    """attempts SIEMPRE se incrementa aquí - solo se llama cuando el
    ciclo NO logró completitud en este intento (regla: una recuperación
    exitosa nunca pasa por aquí, va directo a verifying/complete)."""
    db.execute(
        text("""
            UPDATE attachment_recovery
            SET status = 'pending',
                attempts = :attempts,
                last_reason = :reason,
                last_error = :error,
                available_at = DATE_ADD(NOW(6), INTERVAL :delay SECOND),
                last_checked_at = NOW(6),
                locked_at = NULL,
                updated_at = NOW(6),
                expected_count = COALESCE(:expected, expected_count),
                downloaded_count = COALESCE(:downloaded, downloaded_count)
            WHERE message_id = :message_id
        """),
        {
            "message_id": message_id,
            "attempts": attempts + 1,
            "reason": reason[:80],
            "error": error[:1000],
            "delay": delay_seconds,
            "expected": expected_count,
            "downloaded": downloaded_count,
        },
    )


def mark_verifying(
    db,
    *,
    message_id: int,
    manifest_hash: str,
    expected_count: int,
    downloaded_count: int,
    verification_delay_seconds: int,
) -> None:
    """verified_at NO se toca aquí - todavía no ha sido verificado, solo
    detectado N/N por primera vez (o el manifiesto cambió y se recalculó).
    Solo mark_complete() escribe verified_at."""
    db.execute(
        text("""
            UPDATE attachment_recovery
            SET status = 'verifying',
                manifest_hash = :hash,
                expected_count = :expected,
                downloaded_count = :downloaded,
                available_at = DATE_ADD(NOW(6), INTERVAL :delay SECOND),
                last_checked_at = NOW(6),
                locked_at = NULL,
                updated_at = NOW(6)
            WHERE message_id = :message_id
        """),
        {
            "message_id": message_id,
            "hash": manifest_hash,
            "expected": expected_count,
            "downloaded": downloaded_count,
            "delay": verification_delay_seconds,
        },
    )


def mark_complete(db, *, message_id: int) -> None:
    db.execute(
        text("""
            UPDATE attachment_recovery
            SET status = 'complete',
                verified_at = NOW(6),
                completed_at = NOW(6),
                locked_at = NULL,
                last_checked_at = NOW(6),
                updated_at = NOW(6)
            WHERE message_id = :message_id
        """),
        {"message_id": message_id},
    )


def mark_blocked(
    db,
    *,
    message_id: int,
    reason: str,
    error: str = "",
) -> None:
    db.execute(
        text("""
            UPDATE attachment_recovery
            SET status = 'blocked',
                last_reason = :reason,
                last_error = :error,
                locked_at = NULL,
                last_checked_at = NOW(6),
                updated_at = NOW(6)
            WHERE message_id = :message_id
        """),
        {"message_id": message_id, "reason": reason[:80], "error": error[:1000]},
    )


def has_legacy_null_identity(db, *, message_id: int) -> bool:
    """Guard C de la auditoría D2: cubre ALL_NULL y MIXED con una sola
    condición. Si algún adjunto YA persistido para este mensaje no tiene
    graph_attachment_id (dato legacy, previo al fix de identidad), el
    diff por identidad real no puede confiar en lo que ya existe -
    podría redescargar/duplicar contenido que en realidad ya está. Estos
    mensajes se bloquean sin llamar a Graph."""
    row = db.execute(
        text("""
            SELECT 1 FROM attachments
            WHERE message_id = :message_id AND graph_attachment_id IS NULL
            LIMIT 1
        """),
        {"message_id": message_id},
    ).fetchone()
    return row is not None


def get_message_context(db, *, message_id: int) -> dict[str, Any] | None:
    """Datos mínimos de Graph/negocio necesarios para el ciclo de
    recovery: identidad para llamar a Graph, y received_at para aplicar
    la ventana de estabilización (regla 4 de la auditoría D2 final)."""
    row = db.execute(
        text("""
            SELECT m.provider_message_id, mb.email AS mailbox_email,
                   m.received_at, m.case_id, m.conversation_id
            FROM messages m
            JOIN mailboxes mb ON mb.id = m.mailbox_id
            WHERE m.id = :message_id
        """),
        {"message_id": message_id},
    ).mappings().first()
    return dict(row) if row is not None else None


def get_persisted_graph_ids(db, *, message_id: int) -> set[str]:
    rows = db.execute(
        text("""
            SELECT graph_attachment_id FROM attachments
            WHERE message_id = :message_id AND graph_attachment_id IS NOT NULL
        """),
        {"message_id": message_id},
    ).fetchall()
    return {r[0] for r in rows}
