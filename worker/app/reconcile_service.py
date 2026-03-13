from __future__ import annotations

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
    los mensajes que no tienen caso en la DB.

    Lógica de decisión por mensaje:
      - No existe en messages                → encolar (caso nuevo)
      - Existe en messages sin case_id       → encolar (recuperar hueco)
      - Existe en messages con case_id       → skip (ya procesado)

    Args:
        force_reprocess: Si True, loguea más detalle y es equivalente
                         al modo normal en cuanto a seguridad. Solo encola
                         mensajes sin caso. No genera duplicados.
    """
    mailbox = settings.MAILBOX_EMAIL
    if not mailbox:
        logger.warning("RECONCILE_SCAN_SKIPPED | reason=no_mailbox")
        return {"ok": False, "reason": "no_mailbox"}

    # Leer configuración de .env en cada ciclo (permite cambio en caliente)
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
    # Bulk check: para cada ID del inbox, saber su estado en DB.
    #
    # Retorna DOS conjuntos:
    #   - existing_with_case:    mensajes en DB con case_id válido → SKIP
    #   - existing_without_case: mensajes en DB sin case_id → ENCOLAR
    #
    # Los IDs que no aparecen en ninguno de los dos → ENCOLAR (nuevos)
    # -------------------------------------------------------------------------
    graph_ids = [m["id"] for m in all_messages if isinstance(m, dict) and m.get("id")]

    # Mensajes con caso correcto → no tocar
    existing_with_case: set[str] = set()
    # Mensajes en DB pero sin caso → recuperar
    existing_without_case: set[str] = set()

    if graph_ids:
        try:
            with get_db_session() as db:
                placeholders = ", ".join([f":id{i}" for i in range(len(graph_ids))])
                params = {f"id{i}": gid for i, gid in enumerate(graph_ids)}

                rows = db.execute(
                    text(f"""
                        SELECT provider_message_id,
                               CASE WHEN case_id IS NOT NULL THEN 1 ELSE 0 END AS has_case
                        FROM messages
                        WHERE provider_message_id IN ({placeholders})
                    """),
                    params,
                ).fetchall()

                for row in rows:
                    pmid = row[0]
                    has_case = int(row[1])
                    if has_case:
                        existing_with_case.add(pmid)
                    else:
                        existing_without_case.add(pmid)

        except Exception as e:
            logger.error("RECONCILE_DB_CHECK_ERROR | err=%s", e)
            return {"ok": False, "error": str(e)}

    # -------------------------------------------------------------------------
    # Encolar los mensajes que necesitan caso
    # -------------------------------------------------------------------------
    found = len(graph_ids)
    enqueued = 0
    skipped = 0
    skipped_has_case = 0
    enqueued_new = 0
    enqueued_no_case = 0

    try:
        with get_db_session() as db:
            for item in all_messages:
                if not isinstance(item, dict):
                    continue
                msg_id = item.get("id")
                if not msg_id:
                    continue

                subject_preview = str(item.get("subject", ""))[:80]

                # Caso 3: Ya existe con caso correcto → SKIP siempre
                if msg_id in existing_with_case:
                    skipped += 1
                    skipped_has_case += 1
                    if force_reprocess:
                        # En modo force, logueamos explícitamente que lo saltamos
                        logger.info(
                            "RECONCILE_SKIP_HAS_CASE | message_id=%s | subject=%s"
                            " | reason=already_has_case",
                            msg_id,
                            subject_preview,
                        )
                    continue

                # Caso 1 y 2: No existe en DB O existe sin case_id → encolar
                # Usamos force=False siempre para que inbound_queue_repo
                # maneje correctamente el reciclaje de eventos existentes
                # y no genere duplicados en vuelo.
                reason = (
                    "no_case_in_db"
                    if msg_id in existing_without_case
                    else "not_in_db"
                )

                event_id = inbound_queue_repo.enqueue_event(
                    db,
                    source="reconcile",
                    provider_message_id=str(msg_id),
                    mailbox_email=mailbox,
                    payload=None,
                    force=False,  # Siempre False: no queremos duplicados
                )

                if event_id is not None:
                    enqueued += 1
                    if reason == "no_case_in_db":
                        enqueued_no_case += 1
                    else:
                        enqueued_new += 1
                    logger.info(
                        "RECONCILE_EVENT_ENQUEUED | event_id=%s | message_id=%s"
                        " | subject=%s | reason=%s | force_mode=%s",
                        event_id,
                        msg_id,
                        subject_preview,
                        reason,
                        force_reprocess,
                    )
                else:
                    skipped += 1
                    logger.info(
                        "RECONCILE_EVENT_SKIPPED_ALREADY_IN_QUEUE | message_id=%s"
                        " | reason=%s",
                        msg_id,
                        reason,
                    )

    except Exception as e:
        logger.error("RECONCILE_ENQUEUE_ERROR | err=%s", e)
        return {"ok": False, "error": str(e)}

    logger.info(
        "RECONCILE_SCAN_DONE | found=%s | enqueued=%s"
        " | enqueued_new=%s | enqueued_no_case=%s"
        " | skipped=%s | skipped_has_case=%s"
        " | pages=%s | force_mode=%s",
        found,
        enqueued,
        enqueued_new,
        enqueued_no_case,
        skipped,
        skipped_has_case,
        pages_fetched,
        force_reprocess,
    )

    return {
        "ok": True,
        "found": found,
        "enqueued": enqueued,
        "enqueued_new": enqueued_new,
        "enqueued_no_case": enqueued_no_case,
        "skipped": skipped,
        "skipped_has_case": skipped_has_case,
        "pages_fetched": pages_fetched,
        "force_reprocess": force_reprocess,
    }


async def reconcile_force_reprocess_all() -> dict:
    """
    Atajo para el endpoint POST /admin/reconcile/force.

    IMPORTANTE v4: Este modo ya NO crea duplicados. Solo encola mensajes
    que no tienen caso (nuevos o con hueco). Para forzar un caso duplicado
    en un mensaje específico, usar POST /admin/reprocess?message_id=...
    """
    return await reconcile_recent_inbox(force_reprocess=True)