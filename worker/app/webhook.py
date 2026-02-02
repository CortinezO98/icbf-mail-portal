from __future__ import annotations

import logging
from fastapi import APIRouter, Request, Response
from fastapi.responses import PlainTextResponse, JSONResponse

from app.settings import settings
from app.db import get_db_session
from app.sync_service import sync_service

logger = logging.getLogger("app.webhook")

router = APIRouter(prefix="/graph", tags=["graph"])


@router.get("/webhook")
async def graph_webhook_get(request: Request):
    """
    Graph valida suscripción enviando validationToken como query param.
    Debemos responder con texto plano.
    """
    token = request.query_params.get("validationToken")
    if token:
        return PlainTextResponse(token)
    return JSONResponse({"ok": True})


@router.post("/webhook")
async def graph_webhook_post(request: Request):
    # A veces Graph puede enviar validationToken también por aquí (según flujo)
    token = request.query_params.get("validationToken")
    if token:
        return PlainTextResponse(token)

    payload = await request.json()
    logger.info("Webhook received: keys=%s", list(payload.keys()))

    with get_db_session() as db:
        result = await sync_service.process_notifications(db, payload)

    # Graph espera 202 para notificaciones (aceptado)
    return JSONResponse(result, status_code=202)
