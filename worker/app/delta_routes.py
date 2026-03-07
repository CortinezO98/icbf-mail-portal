from __future__ import annotations

from fastapi import APIRouter, Request, HTTPException, Query
from app.settings import settings
from app.delta_service import run_delta_backstop
from app.sync_service import process_message_id_async
import logging
from sqlalchemy import text
from app.db import get_db_session
from app import inbound_queue_repo

logger = logging.getLogger("app.delta_routes")
router = APIRouter()


def _require_admin_key(request: Request) -> None:
    key = request.headers.get("x-admin-key") or request.headers.get("X-Admin-Key")
    if not key or key != settings.ADMIN_API_KEY:
        raise HTTPException(status_code=401, detail="Invalid admin key")


@router.post("/graph/delta/run")
async def run_delta(request: Request) -> dict:
    _require_admin_key(request)
    return await run_delta_backstop()


@router.post("/graph/delta/prime")
async def prime_delta(request: Request) -> dict:
    _require_admin_key(request)

    old = getattr(settings, "DELTA_PRIME_ONLY", 0)
    try:
        setattr(settings, "DELTA_PRIME_ONLY", 1)
        return await run_delta_backstop()
    finally:
        setattr(settings, "DELTA_PRIME_ONLY", old)


@router.post("/admin/reprocess")
async def reprocess_message(
    request: Request,
    message_id: str = Query(..., description="Provider message ID to reprocess")
) -> dict:
    """
    Reprocesa un mensaje específico por su provider_message_id.
    Útil para recuperar attachments fallidos.
    """
    _require_admin_key(request)

    try:
        logger.info("Manual reprocess requested for message_id=%s", message_id)
        await process_message_id_async(message_id, source="manual")
        return {"success": True, "message_id": message_id, "status": "reprocessed"}
    except Exception as e:
        logger.exception("Reprocess failed for %s", message_id)
        return {"success": False, "message_id": message_id, "error": str(e)}


@router.post("/admin/reprocess-batch")
async def reprocess_batch(
    request: Request,
    limit: int = Query(50, description="Max messages to reprocess")
) -> dict:
    """
    Reprocesa mensajes con attachments faltantes en lote.
    """
    _require_admin_key(request)

    try:
        with get_db_session() as db:
            rows = db.execute(
                text("""
                    SELECT provider_message_id
                    FROM messages m
                    LEFT JOIN attachments a ON a.message_id = m.id
                    WHERE m.has_attachments = 1
                      AND a.id IS NULL
                      AND m.received_at >= '2026-03-01'
                    LIMIT :limit
                """),
                {"limit": limit}
            ).fetchall()

        if not rows:
            return {
                "total": 0,
                "success": 0,
                "failed": 0,
                "message": "No pending messages to reprocess",
                "results": []
            }

        results = []
        for row in rows:
            msg_id = row[0]
            try:
                await process_message_id_async(msg_id, source="manual")
                results.append({"message_id": msg_id, "status": "success"})
                logger.info("Batch reprocess success: %s", msg_id)
            except Exception as e:
                results.append({"message_id": msg_id, "status": "failed", "error": str(e)[:200]})
                logger.error("Batch reprocess failed: %s - %s", msg_id, e)

        return {
            "total": len(results),
            "success": sum(1 for r in results if r["status"] == "success"),
            "failed": sum(1 for r in results if r["status"] == "failed"),
            "results": results
        }
    except Exception as e:
        logger.exception("Batch reprocess failed: %s", e)
        return {
            "success": False,
            "error": str(e)
        }


@router.get("/admin/inbound-queue/stats")
async def inbound_queue_stats(request: Request) -> dict:
    _require_admin_key(request)

    with get_db_session() as db:
        stats = inbound_queue_repo.queue_stats(db)

    return {"ok": True, "stats": stats}