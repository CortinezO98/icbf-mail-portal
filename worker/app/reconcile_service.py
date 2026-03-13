from __future__ import annotations

# =============================================================================
# reconcile_service.py — Portal ICBF
# CAMBIOS v3 (2026-03-13):
#
#   PROBLEMA RESUELTO:
#   El reconcile v2 usaba messages_delta_page() (incremental/deltaLink) para
#   obtener los mensajes del inbox. Si el webhook fallaba Y el delta ya había
#   avanzado pasando el correo, el reconcile nunca lo veía porque el deltaLink
#   ya no lo incluía. Resultado: correos en Graph sin caso en DB.
#
#   SOLUCIÓN:
#   El reconcile ahora hace un GET directo al inbox de Graph ordenado por
#   receivedDateTime desc, filtrando por la ventana de lookback. Esto es
#   independiente del deltaLink y garantiza que SIEMPRE comparamos los
#   mensajes reales del inbox contra la DB.
#
#   FLUJO NORMAL (force_reprocess=False):
#   1. Obtener IDs del inbox Graph (últimas RECONCILE_LOOKBACK_MINUTES)
#   2. Bulk query: cuáles ya existen en messages
#   3. Los que NO están → enqueue_event() → crean caso nuevo automáticamente
#   4. Los que SÍ están → skip
#
#   FLUJO FORZADO (force_reprocess=True):
#   1. Igual pero todos se encolan con force=True
#   2. sync_service crea caso nuevo aunque el message_id ya exista
#
#   VENTAJA vs v2:
#   - No depende del deltaLink
#   - Detecta correos que nunca llegaron al worker (webhook fallido)
#   - Bulk query a DB: una sola consulta para todos los IDs de la página
# =============================================================================

import logging
from datetime import datetime, timedelta, timezone

from sqlalchemy import text

from app.settings import settings
from app.db import get_db_session
from app.graph_client import GraphClient
from app import inbound_queue_repo

logger = logging.getLogger("app.reconcile_service")

_graph = GraphClient()


async def reconcile_recent_inbox(*, force_reprocess: bool = False) -> dict:
    """
    Escanea el inbox de Graph directamente (NO via deltaLink) y encola
    los mensajes que no existen en la tabla messages.

    Args:
        force_reprocess: Si True, encola TODOS los mensajes aunque ya
                         existan en messages. Crea un caso nuevo por cada uno.
    """
    mailbox = settings.MAILBOX_EMAIL
    if not mailbox:
        logger.warning("RECONCILE_SCAN_SKIPPED | reason=no_mailbox")
        return {"ok": False, "reason": "no_mailbox"}

    if not force_reprocess:
        cfg = getattr(settings, "RECONCILE_FORCE_REPROCESS", False)
        if isinstance(cfg, str):
            force_reprocess = cfg.strip().lower() in ("1", "true", "yes", "y", "on")
        else:
            force_reprocess = bool(cfg)

    lookback_minutes = int(getattr(settings, "RECONCILE_LOOKBACK_MINUTES", 180))
    page_size = int(getattr(settings, "RECONCILE_PAGE_SIZE", 100))
    max_pages = int(getattr(settings, "RECONCILE_MAX_PAGES", 10))

    cutoff = datetime.now(timezone.utc) - timedelta(minutes=lookback_minutes)
    cutoff_iso = cutoff.strftime("%Y-%m-%dT%H:%M:%SZ")

    logger.info(
        "RECONCILE_SCAN_START | lookback_minutes=%s | cutoff=%s | page_size=%s | force_reprocess=%s",
        lookback_minutes,
        cutoff_iso,
        page_size,
        force_reprocess,
    )

    # -------------------------------------------------------------------------
    # GET directo al inbox — independiente del deltaLink
    # -------------------------------------------------------------------------
    url = (
        f"https://graph.microsoft.com/v1.0/users/{mailbox}"
        f"/mailFolders/Inbox/messages"
        f"?$select=id,receivedDateTime,subject"
        f"&$filter=receivedDateTime ge {cutoff_iso}"
        f"&$orderby=receivedDateTime desc"
        f"&$top={page_size}"
    )

    all_messages: list[dict] = []
    pages_fetched = 0

    while url and pages_fetched < max_pages:
        try:
            data = await _graph.get_by_url(url)
        except Exception as e:
            logger.error("RECONCILE_GRAPH_ERROR | err=%s", e)
            return {"ok": False, "error": str(e)}

        if not isinstance(data, dict):
            logger.error("RECONCILE_GRAPH_INVALID_RESPONSE | data=%s", str(data)[:200])
            return {"ok": False, "error": "invalid_response"}

        values = data.get("value") or []
        if not isinstance(values, list):
            break

        all_messages.extend(values)
        pages_fetched += 1
        url = data.get("@odata.nextLink")

    if not all_messages:
        logger.info(
            "RECONCILE_SCAN_DONE | found=0 | enqueued=0 | skipped=0 | force=%s",
            force_reprocess,
        )
        return {"ok": True, "found": 0, "enqueued": 0, "skipped": 0, "force_reprocess": force_reprocess}

    # -------------------------------------------------------------------------
    # Bulk check: cuáles de estos IDs ya existen en messages (1 sola query)
    # -------------------------------------------------------------------------
    graph_ids = [m["id"] for m in all_messages if isinstance(m, dict) and m.get("id")]
    existing_ids: set[str] = set()

    if not force_reprocess and graph_ids:
        try:
            with get_db_session() as db:
                placeholders = ", ".join([f":id{i}" for i in range(len(graph_ids))])
                params = {f"id{i}": gid for i, gid in enumerate(graph_ids)}
                rows = db.execute(
                    text(f"SELECT provider_message_id FROM messages WHERE provider_message_id IN ({placeholders})"),
                    params,
                ).fetchall()
                existing_ids = {r[0] for r in rows}
        except Exception as e:
            logger.error("RECONCILE_DB_CHECK_ERROR | err=%s", e)
            return {"ok": False, "error": str(e)}

    # -------------------------------------------------------------------------
    # Encolar los mensajes faltantes
    # -------------------------------------------------------------------------
    found = len(graph_ids)
    enqueued = 0
    skipped = 0

    try:
        with get_db_session() as db:
            for item in all_messages:
                if not isinstance(item, dict):
                    continue
                msg_id = item.get("id")
                if not msg_id:
                    continue

                if not force_reprocess and msg_id in existing_ids:
                    skipped += 1
                    continue

                event_id = inbound_queue_repo.enqueue_event(
                    db,
                    source="reconcile",
                    provider_message_id=str(msg_id),
                    mailbox_email=mailbox,
                    payload=None,
                    force=force_reprocess,
                )

                if event_id is not None:
                    enqueued += 1
                    logger.info(
                        "RECONCILE_EVENT_ENQUEUED | event_id=%s | message_id=%s | subject=%s | force=%s",
                        event_id,
                        msg_id,
                        str(item.get("subject", ""))[:80],
                        force_reprocess,
                    )
                else:
                    skipped += 1
                    logger.info(
                        "RECONCILE_EVENT_SKIPPED_ALREADY_IN_QUEUE | message_id=%s",
                        msg_id,
                    )

    except Exception as e:
        logger.error("RECONCILE_ENQUEUE_ERROR | err=%s", e)
        return {"ok": False, "error": str(e)}

    logger.info(
        "RECONCILE_SCAN_DONE | found=%s | enqueued=%s | skipped=%s | pages=%s | force=%s",
        found,
        enqueued,
        skipped,
        pages_fetched,
        force_reprocess,
    )

    return {
        "ok": True,
        "found": found,
        "enqueued": enqueued,
        "skipped": skipped,
        "pages_fetched": pages_fetched,
        "force_reprocess": force_reprocess,
    }


async def reconcile_force_reprocess_all() -> dict:
    """
    Atajo para reprocesar todos los correos recientes y crear los casos
    faltantes. Equivalente a reconcile_recent_inbox(force_reprocess=True).
    """
    return await reconcile_recent_inbox(force_reprocess=True)