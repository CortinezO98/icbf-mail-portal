from __future__ import annotations

# =============================================================================
# reconcile_service.py — Portal ICBF
# CAMBIOS v2 (2026-03-12):
#   - Se elimina el guard ALREADY_MATERIALIZED del reconcile.
#     Ahora TODOS los mensajes encontrados en el inbox se encolan,
#     independientemente de si ya existen en la tabla messages.
#   - La deduplicación inteligente ocurre en sync_service (nivel de caso),
#     no aquí. El reconcile es ahora un "net de seguridad" que garantiza
#     que ningún correo se pierda.
#   - Se agrega el parámetro RECONCILE_FORCE_REPROCESS (bool, default False)
#     para alternar entre modo normal (no re-encola conocidos) y modo
#     forzado (re-encola todos para crear casos faltantes).
# =============================================================================

import logging
from datetime import datetime, timedelta, timezone

from sqlalchemy import text

from app.settings import settings
from app.db import get_db_session
from app.graph_client import graph_client
from app import inbound_queue_repo

logger = logging.getLogger("app.reconcile_service")


async def reconcile_recent_inbox(*, force_reprocess: bool = False) -> dict:
    """
    Escanea el inbox reciente y encola mensajes para crear casos.

    Args:
        force_reprocess: Si True, encola TODOS los mensajes encontrados
                         aunque ya existan en la tabla messages.
                         Útil para recuperar correos perdidos.
    """
    mailbox = settings.MAILBOX_EMAIL
    if not mailbox:
        logger.warning("RECONCILE_SCAN_SKIPPED | reason=no_mailbox")
        return {"ok": False, "reason": "no_mailbox"}

    # Leer force_reprocess también desde settings si no se pasó por parámetro
    if not force_reprocess:
        cfg = getattr(settings, "RECONCILE_FORCE_REPROCESS", False)
        if isinstance(cfg, str):
            force_reprocess = cfg.strip().lower() in ("1", "true", "yes", "y", "on")
        else:
            force_reprocess = bool(cfg)

    lookback_minutes = int(settings.RECONCILE_LOOKBACK_MINUTES)
    page_size = int(settings.RECONCILE_PAGE_SIZE)

    logger.info(
        "RECONCILE_SCAN_START | lookback_minutes=%s | page_size=%s | force_reprocess=%s",
        lookback_minutes,
        page_size,
        force_reprocess,
    )

    status, data = await graph_client.messages_delta_page(
        mailbox_email=mailbox,
        folder_code="INBOX",
        graph_folder_id=None,
        url=None,
        page_size=page_size,
    )

    if status >= 300:
        logger.warning(
            "RECONCILE_SCAN_FAILED | status=%s | data=%s", status, str(data)[:500]
        )
        return {"ok": False, "status": status}

    values = data.get("value") or []
    if not isinstance(values, list):
        values = []

    found = 0
    enqueued = 0
    skipped = 0

    with get_db_session() as db:
        for item in values:
            if not isinstance(item, dict):
                continue

            # Filtro por lookback
            received_raw = item.get("receivedDateTime") or item.get("createdDateTime")
            if received_raw:
                try:
                    dt = datetime.fromisoformat(
                        str(received_raw).replace("Z", "+00:00")
                    )
                    if dt.tzinfo is None:
                        dt = dt.replace(tzinfo=timezone.utc)

                    cutoff = datetime.now(timezone.utc) - timedelta(
                        minutes=lookback_minutes
                    )
                    if dt < cutoff:
                        continue
                except Exception:
                    pass

            msg_id = item.get("id")
            if not msg_id:
                continue

            found += 1

            # En modo normal: saltamos si ya está en messages (comportamiento anterior)
            # En modo force_reprocess: siempre encolar para crear nuevo caso
            if not force_reprocess:
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
                    skipped += 1
                    logger.info(
                        "RECONCILE_EVENT_SKIPPED_ALREADY_MATERIALIZED | message_id=%s",
                        msg_id,
                    )
                    continue

            # Encolar siempre (en force_reprocess) o si es nuevo
            event_id = inbound_queue_repo.enqueue_event(
                db,
                source="reconcile",
                provider_message_id=str(msg_id),
                mailbox_email=mailbox,
                payload=None,
                force=force_reprocess,  # permite re-encolar aunque ya exista
            )

            if event_id is not None:
                enqueued += 1
                logger.info(
                    "RECONCILE_EVENT_ENQUEUED | event_id=%s | message_id=%s | force=%s",
                    event_id,
                    msg_id,
                    force_reprocess,
                )
            else:
                skipped += 1
                logger.info(
                    "RECONCILE_EVENT_SKIPPED_ALREADY_IN_QUEUE | message_id=%s",
                    msg_id,
                )

    logger.info(
        "RECONCILE_SCAN_DONE | found=%s | enqueued=%s | skipped=%s | force=%s",
        found,
        enqueued,
        skipped,
        force_reprocess,
    )

    return {
        "ok": True,
        "found": found,
        "enqueued": enqueued,
        "skipped": skipped,
        "force_reprocess": force_reprocess,
    }


async def reconcile_force_reprocess_all() -> dict:
    """
    Atajo para reprocesar todos los correos recientes y crear los casos
    faltantes. Equivalente a llamar reconcile_recent_inbox(force_reprocess=True).
    """
    return await reconcile_recent_inbox(force_reprocess=True)