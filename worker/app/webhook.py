from __future__ import annotations

import logging
from fastapi import APIRouter, Request, Response, HTTPException

from app.settings import settings
from app.sync_service import process_notifications_async

logger = logging.getLogger("app.webhook")
router = APIRouter()


@router.get("/graph/webhook")
async def graph_webhook_get(request: Request) -> Response:
    # Graph validation handshake
    token = request.query_params.get("validationToken")
    if token:
        return Response(content=token, media_type="text/plain", status_code=200)
    return Response(content="OK", media_type="text/plain", status_code=200)


@router.post("/graph/webhook")
async def graph_webhook_post(request: Request) -> Response:
    # Graph validation handshake (también puede venir por POST)
    token = request.query_params.get("validationToken")
    if token:
        return Response(content=token, media_type="text/plain", status_code=200)

    payload = await request.json()

    # Validación mínima de seguridad: clientState
    notifications = payload.get("value", [])
    if not isinstance(notifications, list):
        raise HTTPException(status_code=400, detail="Invalid notifications payload")

    for n in notifications:
        cs = n.get("clientState")
        if cs and settings.GRAPH_CLIENT_STATE and cs != settings.GRAPH_CLIENT_STATE:
            logger.warning("Rejected notification: invalid clientState")
            raise HTTPException(status_code=401, detail="Invalid clientState")

    # Procesar async (no bloquear webhook)
    await process_notifications_async(payload)

    return Response(content="OK", media_type="text/plain", status_code=202)
