from __future__ import annotations

import logging
from datetime import datetime, timedelta, timezone

from sqlalchemy import text

from app.settings import settings
from app.db import get_db_session
from app.graph_client import graph_client
from app import inbound_queue_repo

logger = logging.getLogger("app.reconcile_service")


async def reconcile_recent_inbox() -> dict:
    """
    Escanea mensajes recientes del Inbox y encola cualquier faltante
    que no exista todavía en messages ni esté ya pending/processing.
    """
    mailbox = settings.MAILBOX_EMAIL
    if not mailbox:
        logger.warning("RECONCILE_SCAN_SKIPPED | reason=no_mailbox")
        return {"ok": False, "reason": "no_mailbox"}

    lookback_minutes = int(settings.RECONCILE_LOOKBACK_MINUTES)
    page_size = int(settings.RECONCILE_PAGE_SIZE)

    logger.info(
        "RECONCILE_SCAN_START | lookback_minutes=%s | page_size=%s",
        lookback_minutes,
        page_size,
    )

    # Usa delta simple como fuente de ids recientes
    # Si quieres luego lo refinamos por filtro de fechas.
    status, data = await graph_client.messages_delta_page(
        mailbox_email=mailbox,
        folder_code="INBOX",
        graph_folder_id=None,
        url=None,
        page_size=page_size,
    )

    if status >= 300:
        logger.warning("RECONCILE_SCAN_FAILED | status=%s | data=%s", status, str(data)[:500])
        return {"ok": False, "status": status}

    values = data.get("value") or []
    if not isinstance(values, list):
        values = []

    found = 0
    enqueued = 0

    with get_db_session() as db:
        for item in values:
            if not isinstance(item, dict):
                continue

            received_raw = item.get("receivedDateTime") or item.get("createdDateTime")
            if received_raw:
                try:
                    dt = datetime.fromisoformat(str(received_raw).replace("Z", "+00:00"))
                    if dt.tzinfo is None:
                        dt = dt.replace(tzinfo=timezone.utc)

                    cutoff = datetime.now(timezone.utc) - timedelta(minutes=lookback_minutes)
                    if dt < cutoff:
                        continue
                except Exception:
                    pass

            msg_id = item.get("id")
            if not msg_id:
                continue

            found += 1

            row = db.execute(
                text("""
                    SELECT id
                    FROM messages
                    WHERE mailbox_id = (
                        SELECT id FROM mailboxes WHERE email = :mailbox LIMIT 1
                    )
                      AND provider_message_id = :pmid
                    LIMIT 1
                """),
                {"mailbox": mailbox, "pmid": msg_id},
            ).fetchone()

            if row:
                continue

            event_id = inbound_queue_repo.enqueue_event(
                db,
                source="reconcile",
                provider_message_id=str(msg_id),
                mailbox_email=mailbox,
                payload=None,
            )

            enqueued += 1
            logger.info(
                "RECONCILE_EVENT_ENQUEUED | event_id=%s | message_id=%s",
                event_id,
                msg_id,
            )

    logger.info(
        "RECONCILE_SCAN_DONE | found=%s | enqueued=%s",
        found,
        enqueued,
    )

    return {"ok": True, "found": found, "enqueued": enqueued}