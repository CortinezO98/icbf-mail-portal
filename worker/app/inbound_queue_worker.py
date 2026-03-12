from __future__ import annotations

import asyncio
import logging

from app.settings import settings
from app.db import get_db_session
from app import inbound_queue_repo
from app import sync_service

logger = logging.getLogger("app.inbound_queue_worker")

_stop_event: asyncio.Event | None = None
_task: asyncio.Task | None = None


async def start_inbound_queue_worker() -> None:
    global _stop_event, _task

    if not int(getattr(settings, "INBOUND_QUEUE_ENABLED", 1)):
        logger.warning("Inbound queue worker disabled by config")
        return

    if _task and not _task.done():
        logger.warning("Inbound queue worker already started")
        return

    _stop_event = asyncio.Event()
    _task = asyncio.create_task(_run_loop(), name="inbound_queue_worker")

    logger.warning(
        "Inbound queue worker started | poll=%s | batch=%s | max_attempts=%s | concurrency=%s",
        int(getattr(settings, "INBOUND_QUEUE_POLL_SECONDS", 2)),
        int(getattr(settings, "INBOUND_QUEUE_BATCH_SIZE", 20)),
        int(getattr(settings, "INBOUND_QUEUE_MAX_ATTEMPTS", 8)),
        int(getattr(settings, "INBOUND_QUEUE_CONCURRENCY", 4)),
    )


async def stop_inbound_queue_worker() -> None:
    global _stop_event, _task

    if _stop_event:
        _stop_event.set()

    if _task:
        try:
            await _task
        except Exception:
            pass

    _task = None
    _stop_event = None
    logger.warning("Inbound queue worker stopped")


async def _run_loop() -> None:
    assert _stop_event is not None

    poll_seconds = int(getattr(settings, "INBOUND_QUEUE_POLL_SECONDS", 2))
    batch_size = int(getattr(settings, "INBOUND_QUEUE_BATCH_SIZE", 20))
    max_attempts = int(getattr(settings, "INBOUND_QUEUE_MAX_ATTEMPTS", 8))
    concurrency = int(getattr(settings, "INBOUND_QUEUE_CONCURRENCY", 4))

    while not _stop_event.is_set():
        try:
            with get_db_session() as db:
                claimed = inbound_queue_repo.claim_pending_events(
                    db,
                    batch_size=batch_size,
                )

            if not claimed:
                await _sleep_or_stop(poll_seconds)
                continue

            sem = asyncio.Semaphore(max(1, concurrency))

            async def _process_one(item: dict) -> None:
                event_id = int(item["id"])
                source = str(item["source"])
                message_id = str(item["provider_message_id"])
                attempts = int(item["attempts"])

                async with sem:
                    try:
                        logger.info(
                            "QUEUE_EVENT_PICKED | event_id=%s | source=%s | message_id=%s | attempts=%s",
                            event_id,
                            source,
                            message_id,
                            attempts,
                        )

                        await sync_service.process_message_id_async(
                            message_id,
                            source=source,
                        )

                        with get_db_session() as db:
                            inbound_queue_repo.mark_done(db, event_id=event_id)

                        logger.info(
                            "QUEUE_EVENT_DONE | event_id=%s | source=%s | message_id=%s",
                            event_id,
                            source,
                            message_id,
                        )

                    except Exception as e:
                        logger.exception(
                            "QUEUE_EVENT_FAILED | event_id=%s | source=%s | message_id=%s | err=%s",
                            event_id,
                            source,
                            message_id,
                            e,
                        )

                        with get_db_session() as db:
                            inbound_queue_repo.mark_retry(
                                db,
                                event_id=event_id,
                                attempts=attempts,
                                error=str(e),
                                max_attempts=max_attempts,
                            )

            await asyncio.gather(*[_process_one(item) for item in claimed])

        except Exception as e:
            logger.exception("Inbound queue loop failed: %s", e)
            await _sleep_or_stop(max(2, poll_seconds))


async def _sleep_or_stop(seconds: int) -> None:
    assert _stop_event is not None
    try:
        await asyncio.wait_for(_stop_event.wait(), timeout=max(1, seconds))
    except asyncio.TimeoutError:
        pass