from __future__ import annotations

import json
from datetime import datetime, timedelta
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
    Inserta evento pendiente si no existe otro pendiente/processing reciente
    para el mismo provider_message_id + mailbox_email.
    """
    row = db.execute(
        text("""
            SELECT id
            FROM inbound_event_queue
            WHERE provider_message_id = :pmid
              AND mailbox_email = :mailbox
              AND status IN ('pending', 'processing')
            ORDER BY id DESC
            LIMIT 1
        """),
        {"pmid": provider_message_id, "mailbox": mailbox_email},
    ).fetchone()

    if row:
        return int(row[0])

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
            "payload": json.dumps(payload, ensure_ascii=False) if payload else None,
        },
    )

    row2 = db.execute(text("SELECT LAST_INSERT_ID()")).fetchone()
    return int(row2[0]) if row2 else None


def claim_pending_events(db, *, batch_size: int) -> list[dict[str, Any]]:
    """
    Reclama eventos pending disponibles y los marca processing.
    """
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