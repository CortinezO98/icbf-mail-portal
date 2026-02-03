from __future__ import annotations

import asyncio
import logging
from datetime import datetime, timezone
from typing import Any

from app.settings import settings
from app.db import get_db_session
from app.graph_client import graph_client
from app import repos, sync_service

logger = logging.getLogger("app.delta_service")


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


def _is_removed(item: dict[str, Any]) -> bool:
    # Delta puede traer "deleted" con @removed
    return isinstance(item, dict) and ("@removed" in item)


async def run_delta_backstop(*, mailbox_email: str | None = None) -> dict[str, Any]:
    """
    Ejecuta delta para todas las carpetas monitoreadas del mailbox.
    Guarda deltaLink/nextLink en DB.
    """
    mb = mailbox_email or settings.MAILBOX_EMAIL
    if not mb:
        raise RuntimeError("MAILBOX_EMAIL is required")

    results: list[dict[str, Any]] = []

    with get_db_session() as db:
        mailbox_id = repos.get_or_create_mailbox(db, mb)
        repos.ensure_graph_delta_state_table(db)
        folders = repos.list_monitored_folders(db, mailbox_id=mailbox_id)

    if not folders:
        return {"ok": True, "mailbox": mb, "note": "No monitored folders in mailbox_folders", "folders": []}

    for f in folders:
        try:
            r = await _run_delta_for_folder(
                mailbox_email=mb,
                mailbox_id=mailbox_id,
                folder_id=f["folder_id"],
                folder_code=f["folder_code"],
                graph_folder_id=f["graph_folder_id"],
            )
            results.append(r)
        except Exception as e:
            logger.exception("Delta failed folder_id=%s code=%s err=%s", f["folder_id"], f["folder_code"], e)
            results.append({"folder_id": f["folder_id"], "folder_code": f["folder_code"], "ok": False, "error": str(e)})

    return {"ok": True, "mailbox": mb, "folders": results}


async def _run_delta_for_folder(
    *,
    mailbox_email: str,
    mailbox_id: int,
    folder_id: int,
    folder_code: str,
    graph_folder_id: str | None,
) -> dict[str, Any]:
    # Load state
    with get_db_session() as db:
        st = repos.get_delta_state(db, mailbox_id=mailbox_id, folder_id=folder_id)

    delta_link = st[1] if st else None
    next_link = st[2] if st else None

    url = next_link or delta_link  # resume if mid-pagination
    page_size = int(getattr(settings, "DELTA_PAGE_SIZE", 50))
    max_pages = int(getattr(settings, "DELTA_MAX_PAGES_PER_RUN", 25))

    total_items = 0
    total_processed = 0
    pages = 0
    finished = False

    while True:
        pages += 1
        status, data = await graph_client.messages_delta_page(
            mailbox_email=mailbox_email,
            folder_code=folder_code,
            graph_folder_id=graph_folder_id,
            url=url,
            page_size=page_size,
        )

        # Handle hard failures (410 = deltaLink expired)
        if status == 410:
            with get_db_session() as db:
                repos.reset_delta_state(db, mailbox_id=mailbox_id, folder_id=folder_id, note="deltaLink expired (410) reset")
            return {
                "folder_id": folder_id,
                "folder_code": folder_code,
                "ok": True,
                "action": "reset",
                "status": 410,
                "note": "deltaLink expired; reset done; run again.",
            }

        if status != 200:
            err = str(data)[:500]
            with get_db_session() as db:
                repos.upsert_delta_state(
                    db,
                    mailbox_id=mailbox_id,
                    folder_id=folder_id,
                    delta_link=delta_link,
                    next_link=url,
                    last_sync_at=utcnow(),
                    last_status_code=status,
                    last_error=err,
                )
            return {"folder_id": folder_id, "folder_code": folder_code, "ok": False, "status": status, "error": err}

        items = data.get("value") or []
        if not isinstance(items, list):
            items = []

        # collect message ids (skip removed)
        msg_ids: list[str] = []
        for it in items:
            if not isinstance(it, dict):
                continue
            if _is_removed(it):
                continue
            mid = it.get("id")
            if mid:
                msg_ids.append(str(mid))

        total_items += len(items)

        if msg_ids:
            # Procesa (dedupe + casos + adjuntos) usando tu pipeline existente
            await _process_message_ids(msg_ids)
            total_processed += len(msg_ids)

        next_link = data.get("@odata.nextLink")
        delta_link = data.get("@odata.deltaLink")

        # persist state after every page
        with get_db_session() as db:
            repos.upsert_delta_state(
                db,
                mailbox_id=mailbox_id,
                folder_id=folder_id,
                delta_link=(str(delta_link) if delta_link else None),
                next_link=(str(next_link) if next_link else None),
                last_sync_at=utcnow(),
                last_status_code=200,
                last_error=None,
            )

        if next_link:
            url = str(next_link)
            if pages >= max_pages:
                # stop early, will resume via stored next_link
                break
            continue

        # If no nextLink, we should have deltaLink (end)
        finished = True
        break

    return {
        "folder_id": folder_id,
        "folder_code": folder_code,
        "ok": True,
        "pages": pages,
        "total_items": total_items,
        "processed_messages": total_processed,
        "finished": finished,
    }


async def _process_message_ids(message_ids: list[str]) -> None:
    """
    Concurrency control para no saturar Graph/DB.
    """
    concurrency = int(getattr(settings, "DELTA_CONCURRENCY", 3))
    sem = asyncio.Semaphore(concurrency)

    async def _one(mid: str) -> None:
        async with sem:
            try:
                # reutiliza el mismo pipeline que webhook
                await sync_service.process_message_id_async(mid)
            except Exception:
                logger.exception("Delta processing failed message_id=%s", mid)

    await asyncio.gather(*[_one(m) for m in message_ids])
