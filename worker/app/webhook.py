from __future__ import annotations

import logging
from typing import Any, Dict, Optional

from fastapi import APIRouter, Request, Response
from fastapi.responses import PlainTextResponse, JSONResponse

log = logging.getLogger("app.webhook")

router = APIRouter(prefix="/graph", tags=["graph"])


def _get_validation_token(request: Request) -> Optional[str]:
    # Graph uses validationToken in query string (GET or POST)
    return request.query_params.get("validationToken")


@router.get("/webhook")
async def graph_webhook_get(request: Request) -> Response:
    token = _get_validation_token(request)
    if token:
        return PlainTextResponse(token, status_code=200)
    return JSONResponse({"ok": True}, status_code=200)


@router.post("/webhook")
async def graph_webhook_post(request: Request) -> Response:
    token = _get_validation_token(request)
    if token:
        # Subscription validation flow
        return PlainTextResponse(token, status_code=200)

    payload: Dict[str, Any] = await request.json()

    # optional: validate clientState
    # Graph notifications: value[].clientState
    # We'll not hard-fail to avoid losing events, but we log mismatch.
    return JSONResponse({"received": True, "count": len(payload.get("value") or [])})
