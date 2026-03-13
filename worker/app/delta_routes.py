from __future__ import annotations

# =============================================================================
# delta_routes.py — Portal ICBF
# CAMBIOS v2 (2026-03-12):
#   - Nuevo endpoint POST /graph/delta/force-reprocess:
#     Resetea el deltaLink y reprocesa todos los mensajes para crear
#     casos faltantes. Llama run_delta_force_reprocess().
#   - Nuevo endpoint POST /admin/reconcile/force:
#     Dispara un reconcile con force_reprocess=True para recuperar
#     correos del inbox reciente sin casos.
#   - Nuevo endpoint POST /admin/reconcile/run:
#     Dispara un reconcile normal bajo demanda.
#   - /admin/reprocess y /admin/reprocess-batch sin cambios funcionales,
#     solo mejora de logs.
# =============================================================================

import logging
from typing import Any

from fastapi import APIRouter, HTTPException, Query, Request
from sqlalchemy import text

from app import inbound_queue_repo
from app.db import get_db_session
from app.delta_service import run_delta_backstop, run_delta_force_reprocess
from app.reconcile_service import reconcile_recent_inbox, reconcile_force_reprocess_all
from app.settings import settings
from app.sync_service import process_message_id_async

logger = logging.getLogger("app.delta_routes")
router = APIRouter()


# ---------------------------------------------------------------------------
# Auth helper
# ---------------------------------------------------------------------------

def _require_admin_key(request: Request) -> None:
    key = request.headers.get("x-admin-key") or request.headers.get("X-Admin-Key")
    if not key or key != settings.ADMIN_API_KEY:
        raise HTTPException(status_code=401, detail="Invalid admin key")


# ---------------------------------------------------------------------------
# Delta endpoints
# ---------------------------------------------------------------------------

@router.post("/graph/delta/run")
async def run_delta(request: Request) -> dict:
    """Corre el delta normal (sin forzar reprocesamiento)."""
    _require_admin_key(request)
    return await run_delta_backstop()


@router.post("/graph/delta/prime")
async def prime_delta(request: Request) -> dict:
    """Corre el delta en modo priming (solo actualiza deltaLink, no procesa)."""
    _require_admin_key(request)

    old = getattr(settings, "DELTA_PRIME_ONLY", 0)
    try:
        setattr(settings, "DELTA_PRIME_ONLY", 1)
        return await run_delta_backstop()
    finally:
        setattr(settings, "DELTA_PRIME_ONLY", old)


@router.post("/graph/delta/force-reprocess")
async def force_reprocess_delta(request: Request) -> dict:
    """
    Resetea el deltaLink y reprocesa TODOS los mensajes del inbox
    para crear casos que se hayan perdido.

    ⚠️  USO MANUAL ÚNICAMENTE. No llamar en producción de forma automática.
    """
    _require_admin_key(request)

    logger.warning(
        "FORCE_REPROCESS_DELTA requested by ip=%s",
        _client_ip(request),
    )

    return await run_delta_force_reprocess()


# ---------------------------------------------------------------------------
# Reconcile endpoints
# ---------------------------------------------------------------------------

@router.post("/admin/reconcile/run")
async def reconcile_run(request: Request) -> dict:
    """Dispara un reconcile normal del inbox reciente."""
    _require_admin_key(request)
    return await reconcile_recent_inbox(force_reprocess=False)


@router.post("/admin/reconcile/force")
async def reconcile_force(request: Request) -> dict:
    """
    Dispara un reconcile con force_reprocess=True.
    Re-encola TODOS los mensajes recientes para crear casos faltantes.

    ⚠️  USO MANUAL ÚNICAMENTE.
    """
    _require_admin_key(request)

    logger.warning(
        "FORCE_RECONCILE requested by ip=%s",
        _client_ip(request),
    )

    return await reconcile_force_reprocess_all()


# ---------------------------------------------------------------------------
# Reprocess individual / batch
# ---------------------------------------------------------------------------

@router.post("/admin/reprocess")
async def reprocess_message(
    request: Request,
    message_id: str = Query(..., description="Provider message ID to reprocess"),
) -> dict:
    """
    Reprocesa un mensaje específico por su provider_message_id.
    Crea un caso nuevo si no existe (comportamiento v2).
    """
    _require_admin_key(request)

    try:
        logger.info(
            "Manual reprocess requested for message_id=%s by ip=%s",
            message_id,
            _client_ip(request),
        )
        result = await process_message_id_async(message_id, source="manual")
        return {
            "success": True,
            "message_id": message_id,
            "status": result.get("status"),
            "case_id": result.get("case_id"),
            "materialized": result.get("materialized"),
        }
    except Exception as e:
        logger.exception("Reprocess failed for %s", message_id)
        return {"success": False, "message_id": message_id, "error": str(e)}


@router.post("/admin/reprocess-batch")
async def reprocess_batch(
    request: Request,
    limit: int = Query(50, description="Max messages to reprocess"),
) -> dict:
    """
    Reprocesa mensajes con adjuntos faltantes en lote.
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
                {"limit": limit},
            ).fetchall()

        if not rows:
            return {
                "total": 0,
                "success": 0,
                "failed": 0,
                "message": "No pending messages to reprocess",
                "results": [],
            }

        results: list[dict[str, Any]] = []
        for row in rows:
            msg_id = row[0]
            try:
                result = await process_message_id_async(msg_id, source="manual")
                results.append({
                    "message_id": msg_id,
                    "status": "success",
                    "case_id": result.get("case_id"),
                })
                logger.info("Batch reprocess success: %s", msg_id)
            except Exception as e:
                results.append({
                    "message_id": msg_id,
                    "status": "failed",
                    "error": str(e)[:200],
                })
                logger.error("Batch reprocess failed: %s - %s", msg_id, e)

        return {
            "total": len(results),
            "success": sum(1 for r in results if r["status"] == "success"),
            "failed": sum(1 for r in results if r["status"] == "failed"),
            "results": results,
        }
    except Exception as e:
        logger.exception("Batch reprocess failed: %s", e)
        return {"success": False, "error": str(e)}


# ---------------------------------------------------------------------------
# Inbound queue management
# ---------------------------------------------------------------------------

@router.get("/admin/inbound-queue/stats")
async def inbound_queue_stats(request: Request) -> dict:
    """Estadísticas de la cola de procesamiento."""
    _require_admin_key(request)

    with get_db_session() as db:
        stats = inbound_queue_repo.queue_stats(db)

    return {"ok": True, "stats": stats}


@router.get("/admin/inbound-queue/failed")
async def inbound_queue_failed(
    request: Request,
    limit: int = Query(100, description="Max failed events to return"),
) -> dict:
    """Lista los eventos fallidos de la cola."""
    _require_admin_key(request)

    with get_db_session() as db:
        rows = inbound_queue_repo.list_failed_events(db, limit=limit)

    return {"ok": True, "total": len(rows), "rows": rows}


@router.post("/admin/inbound-queue/requeue-failed")
async def inbound_queue_requeue_failed(
    request: Request,
    limit: int = Query(1000, description="Max failed events to requeue"),
) -> dict:
    """Re-encola los eventos fallidos para reintento."""
    _require_admin_key(request)

    with get_db_session() as db:
        updated = inbound_queue_repo.requeue_failed_events(db, limit=limit)

    logger.info(
        "Requeue failed events | count=%s | by ip=%s",
        updated,
        _client_ip(request),
    )

    return {"ok": True, "requeued": updated}


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _client_ip(request: Request) -> str:
    xff = request.headers.get("x-forwarded-for")
    if xff:
        return xff.split(",")[0].strip()
    if request.client:
        return request.client.host
    return "unknown"