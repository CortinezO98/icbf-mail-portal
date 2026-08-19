from __future__ import annotations

# =============================================================================
# background_jobs.py — Portal ICBF
# CAMBIOS v2 (2026-03-12):
#   - _reconcile_loop ahora loguea también `skipped` para visibilidad completa.
#   - Lee RECONCILE_FORCE_REPROCESS desde settings para activar el modo
#     de recuperación masiva sin necesidad de reiniciar el worker.
#   - _delta_loop loguea skipped_known_messages para trazabilidad.
# =============================================================================

import asyncio
import logging
import random
from typing import Any

from app.settings import settings
from app.delta_service import run_delta_backstop
from app.subscriptions_service import ensure_subscription
from app.reconcile_service import reconcile_recent_inbox
from app import sync_service

logger = logging.getLogger("app.background")

_stop_event: asyncio.Event | None = None
_tasks: list[asyncio.Task] = []
_reconcile_task: asyncio.Task | None = None


def _cfg_bool(name: str, default: bool) -> bool:
    v = getattr(settings, name, default)
    if isinstance(v, str):
        return v.strip().lower() in ("1", "true", "yes", "y", "on")
    return bool(v)


def _cfg_int(name: str, default: int) -> int:
    v = getattr(settings, name, default)
    try:
        return int(v)
    except Exception:
        return int(default)


async def start_background_jobs() -> None:
    global _stop_event, _tasks, _reconcile_task

    if _stop_event is not None:
        logger.warning("Background jobs already started - skipping")
        return

    _stop_event = asyncio.Event()
    _tasks = []
    _reconcile_task = None

    if _cfg_bool("SUB_LOOP_ENABLED", True):
        _tasks.append(
            asyncio.create_task(_subscription_loop(_stop_event), name="subscription_loop")
        )

    if _cfg_bool("DELTA_LOOP_ENABLED", True):
        _tasks.append(
            asyncio.create_task(_delta_loop(_stop_event), name="delta_loop")
        )

    if _cfg_bool("RECONCILE_ENABLED", True):
        _reconcile_task = asyncio.create_task(
            _reconcile_loop(_stop_event), name="reconcile_loop"
        )
        _tasks.append(_reconcile_task)

    if _cfg_bool("ATTACHMENT_RECOVERY_ENABLED", True):
        _tasks.append(
            asyncio.create_task(
                _attachment_recovery_loop(_stop_event), name="attachment_recovery_loop"
            )
        )

    logger.warning(
        "Background jobs started | tasks=%s", [t.get_name() for t in _tasks]
    )


async def stop_background_jobs() -> None:
    global _stop_event, _tasks, _reconcile_task

    if _stop_event is None:
        return

    _stop_event.set()

    for t in _tasks:
        t.cancel()

    await asyncio.gather(*_tasks, return_exceptions=True)

    logger.warning("Background jobs stopped")
    _tasks = []
    _reconcile_task = None
    _stop_event = None


async def _subscription_loop(stop_event: asyncio.Event) -> None:
    interval = _cfg_int("SUB_LOOP_INTERVAL_SECONDS", 120)
    jitter = _cfg_int("SUB_LOOP_JITTER_SECONDS", 15)
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
    interval = _cfg_int("DELTA_LOOP_INTERVAL_SECONDS", 300)
    jitter = _cfg_int("DELTA_LOOP_JITTER_SECONDS", 20)

    await asyncio.sleep(2)

    while not stop_event.is_set():
        try:
            res: dict[str, Any] = await run_delta_backstop()
            folders = res.get("folders") or []
            enqueued = 0
            skipped = 0
            ok_folders = 0

            for f in folders:
                if isinstance(f, dict) and f.get("ok"):
                    ok_folders += 1
                    enqueued += int(f.get("enqueued_messages") or 0)
                    skipped += int(f.get("skipped_known_messages") or 0)

            logger.info(
                "Delta loop | ok=%s | folders_ok=%s/%s | enqueued_messages=%s | skipped_known=%s",
                res.get("ok"),
                ok_folders,
                len(folders),
                enqueued,
                skipped,
            )
        except Exception as e:
            logger.exception("Delta loop failed: %s", e)

        sleep_s = max(30, interval + random.randint(0, jitter))
        try:
            await asyncio.wait_for(stop_event.wait(), timeout=sleep_s)
        except asyncio.TimeoutError:
            pass


async def _reconcile_loop(stop_event: asyncio.Event) -> None:
    interval = _cfg_int("RECONCILE_INTERVAL_SECONDS", 300)

    await asyncio.sleep(3)

    while not stop_event.is_set():
        try:
            if _cfg_bool("RECONCILE_ENABLED", True):
                # Lee RECONCILE_FORCE_REPROCESS desde settings en cada ciclo.
                # Permite activar el modo de recuperación masiva en caliente
                # sin reiniciar el worker: solo cambiar la variable de entorno
                # y hacer reload de la config.
                force = _cfg_bool("RECONCILE_FORCE_REPROCESS", False)

                res = await reconcile_recent_inbox(force_reprocess=force)

                logger.info(
                    "Reconcile loop | ok=%s | found=%s | enqueued=%s | skipped=%s | force=%s",
                    res.get("ok"),
                    res.get("found"),
                    res.get("enqueued"),
                    res.get("skipped"),
                    res.get("force_reprocess"),
                )
        except Exception as e:
            logger.exception("Reconcile loop failed: %s", e)

        try:
            await asyncio.wait_for(stop_event.wait(), timeout=max(30, interval))
        except asyncio.TimeoutError:
            pass


async def _attachment_recovery_loop(stop_event: asyncio.Event) -> None:
    """
    Reintenta, sin límite propio de intentos, los mensajes ya
    materializados que se quedaron con has_attachments=1 y sin adjuntos
    descargados (ver sync_service.recover_missing_attachments). Distinto
    del presupuesto de INBOUND_QUEUE_MAX_ATTEMPTS de la cola principal -
    ese presupuesto es para decidir si crear el caso; este loop solo
    completa archivos de casos que ya existen y son visibles al agente.
    """
    interval = _cfg_int("ATTACHMENT_RECOVERY_INTERVAL_SECONDS", 600)
    jitter = _cfg_int("ATTACHMENT_RECOVERY_JITTER_SECONDS", 30)
    batch_size = _cfg_int("ATTACHMENT_RECOVERY_BATCH_SIZE", 50)

    await asyncio.sleep(5)

    while not stop_event.is_set():
        try:
            res = await sync_service.recover_missing_attachments(limit=batch_size)
            logger.info(
                "Attachment recovery loop | ok=%s | checked=%s | recovered=%s | still_pending=%s",
                res.get("ok"),
                res.get("checked"),
                res.get("recovered"),
                res.get("still_pending"),
            )
        except Exception as e:
            logger.exception("Attachment recovery loop failed: %s", e)

        sleep_s = max(60, interval + random.randint(0, jitter))
        try:
            await asyncio.wait_for(stop_event.wait(), timeout=sleep_s)
        except asyncio.TimeoutError:
            pass