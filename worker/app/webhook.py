from __future__ import annotations

import asyncio
import json
import logging
from typing import Any

from fastapi import APIRouter, Request, Response

from app.settings import settings
from app import sync_service

logger = logging.getLogger("app.webhook")
router = APIRouter()


@router.get("/graph/webhook")
async def graph_webhook_get(request: Request) -> Response:
    # Graph reachability validation
    token = request.query_params.get("validationToken")
    if token:
        return Response(content=token, media_type="text/plain", status_code=200)
    return Response(content="OK", media_type="text/plain", status_code=200)


@router.post("/graph/webhook")
async def graph_webhook_post(request: Request) -> Response:
    # Graph validationToken can arrive via POST too
    token = request.query_params.get("validationToken")
    if token:
        return Response(content=token, media_type="text/plain", status_code=200)

    # Graph expects quick response. We always respond 202 and process async.
    try:
        raw = await request.body()
        payload = json.loads(raw.decode("utf-8")) if raw else {}
    except Exception:
        logger.warning("Webhook invalid JSON | ip=%s", _client_ip(request))
        return Response(status_code=202)

    notifications = payload.get("value") or []
    if not isinstance(notifications, list):
        logger.warning(
            "Webhook invalid payload shape | ip=%s | keys=%s",
            _client_ip(request),
            list(payload.keys()),
        )
        return Response(status_code=202)

    valid: list[dict[str, Any]] = []
    invalid = 0

    for n in notifications:
        # n should be dict
        if not isinstance(n, dict):
            invalid += 1
            continue

        cs = n.get("clientState")
        if cs and cs == settings.GRAPH_CLIENT_STATE:
            valid.append(n)
        else:
            invalid += 1

    subs = list({(n.get("subscriptionId") or "") for n in valid if isinstance(n, dict)})

    logger.info(
        "Webhook received | ip=%s | total=%s | valid=%s | invalid=%s | subs=%s",
        _client_ip(request),
        len(notifications),
        len(valid),
        invalid,
        subs[:5],
    )

    if valid:
        # IMPORTANT: sync_service expects {"value": [...]}
        asyncio.create_task(_process_safe({"value": valid}))
    else:
        if invalid:
            logger.warning(
                "Webhook: all notifications rejected (clientState mismatch or invalid objects) | ip=%s",
                _client_ip(request),
            )

    return Response(content="OK", media_type="text/plain", status_code=202)


async def _process_safe(payload: dict[str, Any]) -> None:
    try:
        await sync_service.process_notifications_async(payload)
        logger.info("Webhook processed notifications=%s", len(payload.get("value") or []))
    except Exception as e:
        logger.exception("Webhook processing failed: %s", e)


def _client_ip(request: Request) -> str:
    xff = request.headers.get("x-forwarded-for")
    if xff:
        return xff.split(",")[0].strip()
    if request.client:
        return request.client.host
    return "unknown"
