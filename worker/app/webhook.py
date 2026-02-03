from __future__ import annotations

import asyncio
import json
import logging
from typing import Any

from fastapi import APIRouter, Request, Response

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
        raw = await request.body()
        payload = json.loads(raw.decode("utf-8")) if raw else {}
    except Exception:
        logger.warning("Webhook invalid JSON | ip=%s", _client_ip(request))
        return Response(status_code=202)  

    notifications = payload.get("value") or []
    if not isinstance(notifications, list):
        logger.warning("Webhook invalid payload shape | ip=%s | keys=%s", _client_ip(request), list(payload.keys()))
        return Response(status_code=202)

    valid: list[dict[str, Any]] = []
    invalid = 0

    for n in notifications:
        cs = (n or {}).get("clientState")
        if cs and cs == settings.GRAPH_CLIENT_STATE:
            valid.append(n)
        else:
            invalid += 1

    subs = list({(n.get("subscriptionId") or "") for n in valid if isinstance(n, dict)})
    resources = list({(n.get("resource") or "") for n in valid if isinstance(n, dict)})

    logger.info(
        "Webhook received | ip=%s | total=%s | valid=%s | invalid=%s | subs=%s",
        _client_ip(request),
        len(notifications),
        len(valid),
        invalid,
        subs[:5],
    )
    if resources:
        logger.info("Webhook resources (sample) | %s", resources[:3])

    if valid:
        asyncio.create_task(_process_notifications(valid))
    else:
        if invalid:
            logger.warning("Webhook all notifications rejected by clientState | ip=%s", _client_ip(request))

    return Response(status_code=202)


def _client_ip(request: Request) -> str:
    xff = request.headers.get("x-forwarded-for")
    if xff:
        return xff.split(",")[0].strip()
    if request.client:
        return request.client.host
    return "unknown"


async def _process_notifications(notifications: list[dict[str, Any]]) -> None:
    """
    Aquí vas a llamar tu lógica real:
      - parsear resourceData.id (messageId)
      - traer el mensaje por Graph
      - persistir en BD
    """
    try:
        logger.info("Queued notifications=%s", len(notifications))
        # TODO: reemplazar por tu servicio real:
        # await sync_service.process_notifications_async(notifications)
    except Exception:
        logger.exception("Webhook processing failed")
