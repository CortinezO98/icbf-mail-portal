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

_webhook_queue: asyncio.Queue[str] | None = None
_worker_tasks: list[asyncio.Task] = []


async def start_webhook_workers() -> None:
    global _webhook_queue, _worker_tasks

    if _webhook_queue is not None:
        logger.warning("Webhook queue already started")
        return

    _webhook_queue = asyncio.Queue(maxsize=settings.WEBHOOK_QUEUE_MAXSIZE)
    _worker_tasks = [
        asyncio.create_task(_webhook_consumer(i + 1), name=f"webhook_consumer_{i+1}")
        for i in range(settings.WEBHOOK_CONSUMERS)
    ]

    logger.warning(
        "Webhook queue started | consumers=%s | maxsize=%s",
        settings.WEBHOOK_CONSUMERS,
        settings.WEBHOOK_QUEUE_MAXSIZE,
    )


async def stop_webhook_workers() -> None:
    global _webhook_queue, _worker_tasks

    for t in _worker_tasks:
        t.cancel()

    if _worker_tasks:
        await asyncio.gather(*_worker_tasks, return_exceptions=True)

    _worker_tasks = []
    _webhook_queue = None
    logger.warning("Webhook queue stopped")


async def _webhook_consumer(worker_no: int) -> None:
    assert _webhook_queue is not None

    while True:
        message_id = await _webhook_queue.get()
        try:
            logger.info("WEBHOOK_PROCESS_START | worker=%s | message_id=%s", worker_no, message_id)
            await sync_service.process_message_id_async(message_id)
            logger.info("WEBHOOK_PROCESS_DONE | worker=%s | message_id=%s", worker_no, message_id)
        except Exception as e:
            logger.exception(
                "WEBHOOK_PROCESS_FAILED | worker=%s | message_id=%s | err=%s",
                worker_no,
                message_id,
                e,
            )
        finally:
            _webhook_queue.task_done()


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
        logger.warning(
            "Webhook invalid payload shape | ip=%s | keys=%s",
            _client_ip(request),
            list(payload.keys()),
        )
        return Response(status_code=202)

    valid: list[dict[str, Any]] = []
    invalid = 0

    for n in notifications:
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

    if not valid:
        if invalid:
            logger.warning(
                "Webhook: all notifications rejected (clientState mismatch or invalid objects) | ip=%s",
                _client_ip(request),
            )
        return Response(content="OK", media_type="text/plain", status_code=202)

    if _webhook_queue is None:
        logger.error("Webhook queue is not initialized")
        return Response(content="OK", media_type="text/plain", status_code=202)

    enqueued = 0
    for n in valid:
        msg_id = sync_service._extract_message_id(n)
        if not msg_id:
            logger.warning("Skipping notification without message id")
            continue

        try:
            _webhook_queue.put_nowait(msg_id)
            enqueued += 1
            logger.info("WEBHOOK_ENQUEUED | message_id=%s", msg_id)
        except asyncio.QueueFull:
            logger.error("WEBHOOK_QUEUE_FULL | message_id=%s", msg_id)

    logger.info("Webhook enqueued notifications=%s", enqueued)
    return Response(content="OK", media_type="text/plain", status_code=202)


def _client_ip(request: Request) -> str:
    xff = request.headers.get("x-forwarded-for")
    if xff:
        return xff.split(",")[0].strip()
    if request.client:
        return request.client.host
    return "unknown"