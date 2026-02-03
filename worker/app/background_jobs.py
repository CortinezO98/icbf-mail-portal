from __future__ import annotations

import asyncio
import logging
import random
from typing import Any

from app.settings import settings
from app.delta_service import run_delta_backstop
from app.subscriptions_service import ensure_subscription

logger = logging.getLogger("app.background")

_stop_event: asyncio.Event | None = None
_tasks: list[asyncio.Task] = []


def _cfg_bool(name: str, default: bool) -> bool:
    """
    Lee settings.<name>. Si no existe, usa default.
    Soporta strings tipo "1", "true", "yes".
    """
    v = getattr(settings, name, default)
    if isinstance(v, str):
        return v.strip().lower() in ("1", "true", "yes", "y", "on")
    return bool(v)


def _cfg_int(name: str, default: int) -> int:
    v = getattr(settings, name, default)
    try:
        return int(v)  # soporta str/int
    except Exception:
        return int(default)


async def start_background_jobs() -> None:
    """
    Arranca loops en background. Se llama en FastAPI startup.
    """
    global _stop_event, _tasks

    # Evita doble-start si algo re-llama startup
    if _stop_event is not None:
        logger.warning("Background jobs already started - skipping")
        return

    _stop_event = asyncio.Event()
    _tasks = []

    if _cfg_bool("SUB_LOOP_ENABLED", True):
        _tasks.append(asyncio.create_task(_subscription_loop(_stop_event), name="subscription_loop"))

    if _cfg_bool("DELTA_LOOP_ENABLED", True):
        _tasks.append(asyncio.create_task(_delta_loop(_stop_event), name="delta_loop"))

    logger.warning("Background jobs started | tasks=%s", [t.get_name() for t in _tasks])


async def stop_background_jobs() -> None:
    """
    Detiene loops en background. Se llama en FastAPI shutdown.
    """
    global _stop_event, _tasks

    if _stop_event is None:
        return

    _stop_event.set()

    for t in _tasks:
        t.cancel()

    await asyncio.gather(*_tasks, return_exceptions=True)

    logger.warning("Background jobs stopped")
    _tasks = []
    _stop_event = None


async def _subscription_loop(stop_event: asyncio.Event) -> None:
    """
    Llama ensure_subscription periódicamente.
    Tu ensure_subscription ya decide: created / renewed / ok (según expires_at + threshold).
    """
    interval = _cfg_int("SUB_LOOP_INTERVAL_SECONDS", 120)  # cada 2 min
    jitter = _cfg_int("SUB_LOOP_JITTER_SECONDS", 15)

    # pequeña espera inicial para no competir con arranque
    await asyncio.sleep(1)

    while not stop_event.is_set():
        try:
            res: dict[str, Any] = await ensure_subscription(dry_run=False)
            logger.info(
                "Sub loop | action=%s | subscription_id=%s | expiration=%s",
                res.get("action"),
                res.get("subscription_id"),
                res.get("expiration"),
            )
        except Exception as e:
            logger.exception("Sub loop failed: %s", e)

        sleep_s = max(10, interval + random.randint(0, jitter))
        try:
            await asyncio.wait_for(stop_event.wait(), timeout=sleep_s)
        except asyncio.TimeoutError:
            pass


async def _delta_loop(stop_event: asyncio.Event) -> None:
    """
    Corre delta backstop periódicamente (para no depender solo del webhook).
    """
    interval = _cfg_int("DELTA_LOOP_INTERVAL_SECONDS", 300)  # cada 5 min
    jitter = _cfg_int("DELTA_LOOP_JITTER_SECONDS", 20)

    await asyncio.sleep(2)

    while not stop_event.is_set():
        try:
            res: dict[str, Any] = await run_delta_backstop()
            # resumen corto para logs
            folders = res.get("folders") or []
            processed = 0
            ok_folders = 0
            for f in folders:
                if isinstance(f, dict) and f.get("ok"):
                    ok_folders += 1
                    processed += int(f.get("processed_messages") or 0)

            logger.info(
                "Delta loop | ok=%s | folders_ok=%s/%s | processed_messages=%s",
                res.get("ok"),
                ok_folders,
                len(folders),
                processed,
            )
        except Exception as e:
            logger.exception("Delta loop failed: %s", e)

        sleep_s = max(30, interval + random.randint(0, jitter))
        try:
            await asyncio.wait_for(stop_event.wait(), timeout=sleep_s)
        except asyncio.TimeoutError:
            pass
