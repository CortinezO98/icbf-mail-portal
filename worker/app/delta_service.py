from __future__ import annotations

import asyncio
import logging
from datetime import datetime, timezone
from typing import Any, Iterable

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

    # Si viene con id adelante (len >= 3 y st[0] es int/decimal), usamos [1],[2]
    try:
        if len(st) >= 3 and (isinstance(st[0], int) or str(st[0]).isdigit()):
            delta_link = str(st[1]) if st[1] else None
            next_link = str(st[2]) if st[2] else None
            return delta_link, next_link
    except Exception:
        pass

    # Si viene sin id, asumimos [0],[1]
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
        # safe-guard (si ya la creaste manual, no hace daño)
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
    # Settings (acepta tus nombres y los “nuevos”)
    page_size = int(getattr(settings, "DELTA_PAGE_SIZE", 50))
    max_pages = int(getattr(settings, "DELTA_MAX_PAGES_PER_RUN", getattr(settings, "DELTA_MAX_PAGES", 50)))
    max_messages = int(getattr(settings, "DELTA_MAX_MESSAGES", 500))

    # 1) Load state (delta_link / next_link)
    with get_db_session() as db:
        st = repos.get_delta_state(db, mailbox_id=mailbox_id, folder_id=folder_id)

    delta_link, next_link = _unpack_delta_state(st)

    # 2) Decide URL inicial:
    #    resume paging (next_link) > delta_link > initial delta query
    url = next_link or delta_link  # si quedó a mitad de paginación, next_link manda

    pages = 0
    total_items = 0
    processed_messages = 0
    finished = False

    while True:
        if pages >= max_pages:
            break
        if processed_messages >= max_messages:
            break

        pages += 1

        # 3) GET delta page
        status: int
        data: dict[str, Any]

        if url:
            # Necesitamos el status para manejar 410 (delta expirado)
            resp = await graph_client._request("GET", url)  # usa auth+retries del cliente
            status = resp.status_code
            data = _safe_json(resp)
        else:
            # Primera vez: construye delta “inicial” con el helper del graph_client
            status, data = await graph_client.messages_delta_page(
                mailbox_email=mailbox_email,
                folder_code=folder_code,
                graph_folder_id=graph_folder_id,
                url=None,
                page_size=page_size,
            )

        # 4) Manejo delta expirado (410)
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

        # 5) Otros errores
        if status != 200:
            err = str(data)[:500]
            with get_db_session() as db:
                repos.upsert_delta_state(
                    db,
                    mailbox_id=mailbox_id,
                    folder_id=folder_id,
                    delta_link=delta_link,
                    next_link=url,  # dejamos dónde iba para reintentar
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
                "processed_messages": processed_messages,
                "finished": False,
            }

        items = data.get("value") or []
        if not isinstance(items, list):
            items = []

        total_items += len(items)

        # 6) Extraer message ids (skip removed)
        msg_ids: list[str] = []
        for it in items:
            if not isinstance(it, dict):
                continue
            if _is_removed(it):
                continue
            mid = it.get("id")
            if mid:
                msg_ids.append(str(mid))

        # 7) Procesar ids (reusa pipeline real: dedupe + cases + attachments)
        if msg_ids:
            processed_ok = await _process_message_ids(msg_ids)
            processed_messages += processed_ok

        # 8) Links
        new_next = data.get("@odata.nextLink")
        new_delta = data.get("@odata.deltaLink")

        if new_delta:
            delta_link = str(new_delta)

        next_link = str(new_next) if new_next else None

        # 9) Persist state after every page
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

        # 10) Continuar o terminar
        if next_link:
            url = next_link
            continue

        finished = True
        break

    return {
        "folder_id": folder_id,
        "folder_code": folder_code,
        "ok": True,
        "pages": pages,
        "total_items": total_items,
        "processed_messages": processed_messages,
        "finished": bool(finished),
        "note": ("stopped_by_limits" if not finished else None),
    }


async def _process_message_ids(message_ids: list[str]) -> int:
    """
    Concurrency control para no saturar Graph/DB.
    Retorna cuántos se intentaron procesar (ok contabilizados).
    """
    concurrency = int(getattr(settings, "DELTA_CONCURRENCY", 3))
    sem = asyncio.Semaphore(concurrency)

    ok_count = 0

    async def _one(mid: str) -> None:
        nonlocal ok_count
        async with sem:
            try:
                # IMPORTANTE: este método debe existir en tu sync_service
                # (tú ya lo estás usando y te funcionó con 50 mensajes)
                await sync_service.process_message_id_async(mid)
                ok_count += 1
            except Exception:
                logger.exception("Delta processing failed message_id=%s", mid)

    await asyncio.gather(*[_one(m) for m in message_ids])
    return ok_count
