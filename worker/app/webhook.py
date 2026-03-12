from __future__ import annotations

import asyncio
import json
import logging
from typing import Any

from fastapi import APIRouter, Request, Response

from app.settings import settings
from app import sync_service
from app.db import get_db_session
from app import inbound_queue_repo

logger = logging.getLogger("app.webhook")
router = APIRouter()

_webhook_queue: asyncio.Queue[str] | None = None
_worker_tasks: list[asyncio.Task] = []


async def start_webhook_workers() -> None:
    global _webhook_queue, _worker_tasks

    if _webhook_queue is not None:
        logger.warning("Webhook queue already started")
        return

    maxsize = int(getattr(settings, "WEBHOOK_QUEUE_MAXSIZE", 1000))
    consumers = int(getattr(settings, "WEBHOOK_CONSUMERS", 2))

    _webhook_queue = asyncio.Queue(maxsize=max(1, maxsize))
    _worker_tasks = [
        asyncio.create_task(_webhook_consumer(i + 1), name=f"webhook_consumer_{i + 1}")
        for i in range(max(1, consumers))
    ]

    logger.warning(
        "Webhook queue started | consumers=%s | maxsize=%s",
        consumers,
        maxsize,
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
            logger.info(
                "WEBHOOK_PROCESS_START | worker=%s | message_id=%s",
                worker_no,
                message_id,
            )

            result = await sync_service.process_message_id_async(
                message_id,
                source="webhook",
            )

            logger.info(
                "WEBHOOK_PROCESS_DONE | worker=%s | message_id=%s | result_status=%s | materialized=%s",
                worker_no,
                message_id,
                result.get("status"),
                result.get("materialized"),
            )

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

    persisted = 0
    enqueued = 0
    skipped = 0

    for n in valid:
        msg_id = sync_service._extract_message_id(n)
        if not msg_id:
            logger.warning("Skipping notification without message id")
            continue

        try:
            with get_db_session() as db:
                event_id = inbound_queue_repo.enqueue_event(
                    db,
                    source="webhook",
                    provider_message_id=msg_id,
                    mailbox_email=settings.MAILBOX_EMAIL,
                    payload=n,
                )

            if event_id is not None:
                persisted += 1
                logger.info(
                    "QUEUE_EVENT_CREATED | source=webhook | event_id=%s | message_id=%s",
                    event_id,
                    msg_id,
                )
            else:
                skipped += 1
                logger.info(
                    "QUEUE_EVENT_SKIPPED_ALREADY_MATERIALIZED | source=webhook | message_id=%s",
                    msg_id,
                )

            if _webhook_queue is not None:
                try:
                    _webhook_queue.put_nowait(msg_id)
                    enqueued += 1
                    logger.info("WEBHOOK_ENQUEUED | message_id=%s", msg_id)
                except asyncio.QueueFull:
                    logger.error("WEBHOOK_QUEUE_FULL | message_id=%s", msg_id)
            else:
                logger.warning(
                    "WEBHOOK_QUEUE_NOT_AVAILABLE | message_id=%s | persisted_only=%s",
                    msg_id,
                    "true" if event_id is not None else "false",
                )

        except Exception as e:
            logger.exception(
                "WEBHOOK_PERSIST_FAILED | message_id=%s | err=%s",
                msg_id,
                e,
            )

    logger.info(
        "Webhook processed notifications | persisted=%s | enqueued=%s | skipped=%s",
        persisted,
        enqueued,
        skipped,
    )
    return Response(content="OK", media_type="text/plain", status_code=202)


def _client_ip(request: Request) -> str:
    xff = request.headers.get("x-forwarded-for")
    if xff:
        return xff.split(",")[0].strip()
    if request.client:
        return request.client.host
    return "unknown"