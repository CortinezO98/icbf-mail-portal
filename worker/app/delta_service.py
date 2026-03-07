from __future__ import annotations

import asyncio
import logging
from datetime import datetime, timezone
from typing import Any, Iterable

from app.settings import settings
from app.db import get_db_session
from app.graph_client import graph_client
from app import repos, inbound_queue_repo

logger = logging.getLogger("app.delta_service")


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


def _is_removed(item: dict[str, Any]) -> bool:
    return isinstance(item, dict) and ("@removed" in item)


def _safe_json(resp) -> dict[str, Any]:
    try:
        return resp.json()
    except Exception:
        return {"raw": getattr(resp, "text", "")}


def _unpack_delta_state(st: Any) -> tuple[str | None, str | None]:
    """
    Tu repos.get_delta_state puede devolver:
      - (id, delta_link, next_link, last_sync_at, last_status_code, last_error)
    o (delta_link, next_link, last_sync_at, last_status_code, last_error)
    según cómo lo tengas.

    Aquí lo hacemos tolerante.
    """
    if not st:
        return None, None

    try:
        if len(st) >= 3 and (isinstance(st[0], int) or str(st[0]).isdigit()):
            delta_link = str(st[1]) if st[1] else None
            next_link = str(st[2]) if st[2] else None
            return delta_link, next_link
    except Exception:
        pass

    try:
        delta_link = str(st[0]) if st[0] else None
        next_link = str(st[1]) if len(st) > 1 and st[1] else None
        return delta_link, next_link
    except Exception:
        return None, None


def _iter_folders(folders: Any) -> Iterable[tuple[int, str, str | None]]:
    """
    Soporta:
      - list[dict] como tú lo tienes hoy: {"folder_id","folder_code","graph_folder_id"...}
      - list[tuple] si luego migras a tuples (folder_id, folder_code, graph_folder_id)
    """
    if not folders:
        return []

    out: list[tuple[int, str, str | None]] = []
    for f in folders:
        if isinstance(f, dict):
            out.append(
                (
                    int(f["folder_id"]),
                    str(f["folder_code"]),
                    (str(f.get("graph_folder_id")) if f.get("graph_folder_id") else None),
                )
            )
        elif isinstance(f, (list, tuple)) and len(f) >= 2:
            fid = int(f[0])
            fcode = str(f[1])
            gfid = (str(f[2]) if len(f) >= 3 and f[2] else None)
            out.append((fid, fcode, gfid))
    return out


async def run_delta_backstop(*, mailbox_email: str | None = None) -> dict[str, Any]:
    """
    Ejecuta delta para todas las carpetas monitoreadas del mailbox.
    Guarda deltaLink/nextLink en DB.
    """
    mb = mailbox_email or settings.MAILBOX_EMAIL
    if not mb:
        return {"ok": False, "error": "MAILBOX_EMAIL is required"}

    results: list[dict[str, Any]] = []

    with get_db_session() as db:
        mailbox_id = repos.get_or_create_mailbox(db, mb)
        if hasattr(repos, "ensure_graph_delta_state_table"):
            repos.ensure_graph_delta_state_table(db)
        folders_raw = repos.list_monitored_folders(db, mailbox_id=mailbox_id)

    folders = list(_iter_folders(folders_raw))

    if not folders:
        return {"ok": True, "mailbox": mb, "note": "No monitored folders in mailbox_folders", "folders": []}

    for folder_id, folder_code, graph_folder_id in folders:
        try:
            r = await _run_delta_for_folder(
                mailbox_email=mb,
                mailbox_id=mailbox_id,
                folder_id=folder_id,
                folder_code=folder_code,
                graph_folder_id=graph_folder_id,
            )
            results.append(r)
        except Exception as e:
            logger.exception("Delta failed folder_id=%s code=%s err=%s", folder_id, folder_code, e)
            results.append({"folder_id": folder_id, "folder_code": folder_code, "ok": False, "error": str(e)})

    return {"ok": True, "mailbox": mb, "folders": results}


async def _run_delta_for_folder(
    *,
    mailbox_email: str,
    mailbox_id: int,
    folder_id: int,
    folder_code: str,
    graph_folder_id: str | None,
) -> dict[str, Any]:

    page_size = int(getattr(settings, "DELTA_PAGE_SIZE", 50))
    max_pages = int(getattr(settings, "DELTA_MAX_PAGES_PER_RUN", getattr(settings, "DELTA_MAX_PAGES", 50)))
    max_messages = int(getattr(settings, "DELTA_MAX_MESSAGES", 500))

    with get_db_session() as db:
        st = repos.get_delta_state(db, mailbox_id=mailbox_id, folder_id=folder_id)

    delta_link, next_link = _unpack_delta_state(st)

    def _cfg_bool(name: str, default: bool) -> bool:
        v = getattr(settings, name, default)
        if isinstance(v, str):
            return v.strip().lower() in ("1", "true", "yes", "y", "on")
        return bool(v)

    is_first_run = (delta_link is None and next_link is None)

    prime_on_empty = _cfg_bool("DELTA_PRIME_ON_EMPTY_STATE", True)
    prime_only = _cfg_bool("DELTA_PRIME_ONLY", False)

    priming = prime_only or (prime_on_empty and is_first_run)
    url = next_link or delta_link

    pages = 0
    total_items = 0
    enqueued_messages = 0
    finished = False

    while True:
        if pages >= max_pages:
            break
        if not priming and enqueued_messages >= max_messages:
            break

        pages += 1

        status: int
        data: dict[str, Any]

        if url:
            resp = await graph_client._request("GET", url)
            status = resp.status_code
            data = _safe_json(resp)
        else:
            status, data = await graph_client.messages_delta_page(
                mailbox_email=mailbox_email,
                folder_code=folder_code,
                graph_folder_id=graph_folder_id,
                url=None,
                page_size=page_size,
            )

        if status == 410:
            with get_db_session() as db:
                repos.reset_delta_state(
                    db,
                    mailbox_id=mailbox_id,
                    folder_id=folder_id,
                    note="deltaLink expired (410) reset",
                )
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
            return {
                "folder_id": folder_id,
                "folder_code": folder_code,
                "ok": False,
                "status": status,
                "error": err,
                "pages": pages,
                "total_items": total_items,
                "enqueued_messages": enqueued_messages,
                "finished": False,
            }

        items = data.get("value") or []
        if not isinstance(items, list):
            items = []

        total_items += len(items)

        msg_ids: list[str] = []
        for it in items:
            if not isinstance(it, dict):
                continue
            if _is_removed(it):
                continue
            mid = it.get("id")
            if mid:
                msg_ids.append(str(mid))

        if msg_ids and not priming:
            enqueued_ok = await _enqueue_message_ids(msg_ids, mailbox_email=mailbox_email)
            enqueued_messages += enqueued_ok

        new_next = data.get("@odata.nextLink")
        new_delta = data.get("@odata.deltaLink")

        if new_delta:
            delta_link = str(new_delta)

        next_link = str(new_next) if new_next else None

        with get_db_session() as db:
            repos.upsert_delta_state(
                db,
                mailbox_id=mailbox_id,
                folder_id=folder_id,
                delta_link=(delta_link if delta_link else None),
                next_link=(next_link if next_link else None),
                last_sync_at=utcnow(),
                last_status_code=200,
                last_error=None,
            )

        if next_link:
            url = next_link
            continue

        finished = True
        break

    return {
        "folder_id": folder_id,
        "folder_code": folder_code,
        "ok": True,
        "action": ("primed" if priming else "synced"),
        "priming": bool(priming),
        "pages": pages,
        "total_items": total_items,
        "enqueued_messages": enqueued_messages,
        "finished": bool(finished),
        "note": ("stopped_by_limits" if not finished else None),
    }


async def _enqueue_message_ids(message_ids: list[str], *, mailbox_email: str) -> int:
    """
    Encola provider_message_id encontrados por delta para que los procese
    inbound_queue_worker. Evita procesar directo aquí y centraliza retries.
    """
    concurrency = int(getattr(settings, "DELTA_CONCURRENCY", 3))
    sem = asyncio.Semaphore(concurrency)

    enqueued_count = 0

    async def _one(mid: str) -> None:
        nonlocal enqueued_count
        async with sem:
            try:
                with get_db_session() as db:
                    event_id = inbound_queue_repo.enqueue_event(
                        db,
                        source="delta",
                        provider_message_id=mid,
                        mailbox_email=mailbox_email,
                        payload=None,
                    )

                logger.info(
                    "QUEUE_EVENT_CREATED | source=delta | event_id=%s | message_id=%s",
                    event_id,
                    mid,
                )
                enqueued_count += 1
            except Exception:
                logger.exception("Delta enqueue failed message_id=%s", mid)

    await asyncio.gather(*[_one(m) for m in message_ids])
    return enqueued_count