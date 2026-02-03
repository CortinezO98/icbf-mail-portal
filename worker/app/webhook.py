from __future__ import annotations

import asyncio
import logging
from fastapi import APIRouter, Request, Response, HTTPException

from app.settings import settings

logger = logging.getLogger("app.webhook")
router = APIRouter()


@router.get("/graph/webhook")
async def graph_webhook_get(request: Request) -> Response:
    token = request.query_params.get("validationToken")
    if token:
        return Response(content=token, media_type="text/plain", status_code=200)
    return Response(content="OK", media_type="text/plain", status_code=200)


@router.post("/graph/webhook")
async def graph_webhook_post(request: Request) -> Response:
    token = request.query_params.get("validationToken")
    if token:
        return Response(content=token, media_type="text/plain", status_code=200)

    try:
        payload = await request.json()
    except Exception:
        raise HTTPException(status_code=400, detail="Invalid JSON")

    notifications = payload.get("value", [])
    if not isinstance(notifications, list):
        raise HTTPException(status_code=400, detail="Invalid notifications payload")

    for n in notifications:
        cs = n.get("clientState")
        if not cs or cs != settings.GRAPH_CLIENT_STATE:
            logger.warning("Rejected notification: invalid/missing clientState")
            raise HTTPException(status_code=401, detail="Invalid clientState")

    # ✅ respuesta rápida, proceso async (aquí solo simulamos)
    asyncio.create_task(_process_dummy(payload))

    return Response(content="OK", media_type="text/plain", status_code=202)


async def _process_dummy(payload: dict) -> None:
    # En tu versión final aquí llamas sync_service.process_notifications_async(payload)
    logger.info("Queued notifications=%s", len(payload.get("value", []) or []))
