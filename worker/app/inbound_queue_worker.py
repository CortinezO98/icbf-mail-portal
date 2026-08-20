from __future__ import annotations

import asyncio
import logging
from datetime import datetime
from zoneinfo import ZoneInfo

from app.settings import settings
from app.db import get_db_session
from app import inbound_queue_repo
from app import sync_service

logger = logging.getLogger("app.inbound_queue_worker")

_stop_event: asyncio.Event | None = None
_task: asyncio.Task | None = None

# Statuses que representan un descarte intencional y permanente por parte
# de _process_single_message (ok=True, materialized=False). La decisión no
# depende de que Graph "termine de servir" el mensaje, así que reintentar
# no cambia el resultado -> se marcan done, no retry.
#
# Si en el futuro se agrega un nuevo filtro determinístico en sync_service
# (otro corte operativo, otra ventana de fechas, etc.), su status debe
# sumarse aquí explícitamente. Deliberado: preferimos que un filtro nuevo
# se reintente "de más" hasta que alguien lo agregue a la lista, antes que
# el worker adivine por texto qué statuses son terminales.
_TERMINAL_BY_DESIGN_STATUSES = {
    "after_operational_cutoff",
    "before_go_live",
}


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


async def _process_one(
    item: dict,
    *,
    semaphore: asyncio.Semaphore,
    max_attempts: int,
) -> None:
    """Procesa un evento reclamado de la cola: llama a sync_service y decide
    el siguiente estado (done / descartado por diseño / retry / retry sin
    límite para MISSING_RECEIVED_DATETIME).

    Extraída a nivel de módulo (en vez de closure dentro de _run_loop) para
    ser testeable de forma aislada: todas sus dependencias externas
    (sync_service.process_message_id_async, get_db_session,
    inbound_queue_repo) se referencian vía el módulo, por lo que los tests
    pueden sustituirlas con mocks sin necesitar un event loop de fondo ni
    una base de datos real.
    """
    event_id = int(item["id"])
    source = str(item["source"])
    message_id = str(item["provider_message_id"])
    attempts = int(item["attempts"])
    created_at = item.get("created_at")
    payload_json_raw = item.get("payload_json")

    async with semaphore:
        try:
            logger.info(
                "QUEUE_EVENT_PICKED | event_id=%s | source=%s | message_id=%s | attempts=%s",
                event_id,
                source,
                message_id,
                attempts,
            )

            attachments_stability_snapshot = inbound_queue_repo.extract_stability_snapshot(
                payload_json_raw
            )

            result = await sync_service.process_message_id_async(
                message_id,
                source=source,
                attempts=attempts,
                max_attempts=max_attempts,
                attachments_stability_snapshot=attachments_stability_snapshot,
            )

            ok = bool(result.get("ok"))
            materialized = bool(result.get("materialized"))
            status = str(result.get("status") or "unknown")

            if materialized:
                with get_db_session() as db:
                    inbound_queue_repo.mark_done(db, event_id=event_id)

                logger.info(
                    "QUEUE_EVENT_DONE | event_id=%s | source=%s | message_id=%s | result_status=%s",
                    event_id,
                    source,
                    message_id,
                    status,
                )
                return

            if ok and status in _TERMINAL_BY_DESIGN_STATUSES:
                with get_db_session() as db:
                    inbound_queue_repo.mark_done(db, event_id=event_id)

                logger.info(
                    "QUEUE_EVENT_DISCARDED_BY_DESIGN | event_id=%s | source=%s"
                    " | message_id=%s | reason=%s",
                    event_id,
                    source,
                    message_id,
                    status,
                )
                return

            if status == sync_service.STATUS_MISSING_RECEIVED_DATETIME:
                queue_event_age_seconds = _queue_event_age_seconds(created_at)
                alert_age_seconds = (
                    int(
                        getattr(
                            settings,
                            "MISSING_RECEIVED_DATETIME_ALERT_AGE_MINUTES",
                            60,
                        )
                    )
                    * 60
                )
                long_retry_seconds = int(
                    getattr(
                        settings,
                        "MISSING_RECEIVED_DATETIME_LONG_RETRY_SECONDS",
                        21600,
                    )
                )

                if queue_event_age_seconds > alert_age_seconds:
                    logger.error(
                        "ALERT_STALLED_MISSING_RECEIVED_DATETIME | event_id=%s"
                        " | source=%s | message_id=%s | queue_event_age_seconds=%s"
                        " | alert_age_seconds=%s",
                        event_id,
                        source,
                        message_id,
                        queue_event_age_seconds,
                        alert_age_seconds,
                    )
                else:
                    logger.warning(
                        "QUEUE_EVENT_MISSING_RECEIVED_DATETIME | event_id=%s"
                        " | source=%s | message_id=%s | queue_event_age_seconds=%s",
                        event_id,
                        source,
                        message_id,
                        queue_event_age_seconds,
                    )

                with get_db_session() as db:
                    inbound_queue_repo.mark_retry_unbounded(
                        db,
                        event_id=event_id,
                        attempts=attempts,
                        error=f"not_materialized:{status}",
                        queue_event_age_seconds=queue_event_age_seconds,
                        alert_age_seconds=alert_age_seconds,
                        long_retry_seconds=long_retry_seconds,
                    )
                return

            logger.warning(
                "QUEUE_EVENT_NOT_MATERIALIZED | event_id=%s | source=%s | message_id=%s | result_status=%s",
                event_id,
                source,
                message_id,
                status,
            )

            # Fase C: si sync_service devolvió un nuevo snapshot de
            # estabilización de adjuntos (ATTACHMENTS_FLAG_UNSTABLE), se
            # combina con el payload_json existente (sin perderlo) antes
            # de reintentar, para poder comparar lastModifiedDateTime en
            # el próximo intento.
            new_stability_snapshot = result.get("attachments_stability_snapshot")
            payload_to_persist = None
            if new_stability_snapshot is not None:
                payload_to_persist = inbound_queue_repo.merge_stability_snapshot(
                    payload_json_raw, new_stability_snapshot
                )

            with get_db_session() as db:
                inbound_queue_repo.mark_retry(
                    db,
                    event_id=event_id,
                    attempts=attempts,
                    error=f"not_materialized:{status}",
                    max_attempts=max_attempts,
                    payload_json=payload_to_persist,
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


def _queue_event_age_seconds(created_at) -> int:
    """
    Antigüedad de ESTA fila de inbound_event_queue (queue_event_age), NO
    necesariamente el momento en que Graph notificó por primera vez de
    este mensaje: enqueue_event(force=True) puede crear una fila nueva
    con created_at=NOW() para un mensaje ya visto antes (ej.
    /admin/reprocess). En el reciclaje normal (force=False), created_at
    de la fila original SÍ se conserva - ver inbound_queue_repo.py.

    FIX de auditoría de zona horaria (pre-Fase D): created_at viene de
    NOW(6) de MySQL, y la sesión del worker fuerza
    SET time_zone='America/Bogota' en cada conexión (ver db.py) - como
    las columnas son DATETIME (no TIMESTAMP), ese valor se lee de vuelta
    tal cual, en hora de Bogotá, sin conversión. Comparar contra
    datetime.now(UTC) introducía un desfase de 5 horas en el cálculo de
    edad, adelantando la alerta operacional y el long-tail retry ~5h
    antes de lo configurado.
    """
    if created_at is None:
        return 0
    now = datetime.now(ZoneInfo("America/Bogota")).replace(tzinfo=None)
    return max(0, int((now - created_at).total_seconds()))


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

            await asyncio.gather(*[
                _process_one(item, semaphore=sem, max_attempts=max_attempts)
                for item in claimed
            ])

        except Exception as e:
            logger.exception("Inbound queue loop failed: %s", e)
            await _sleep_or_stop(max(2, poll_seconds))


async def _sleep_or_stop(seconds: int) -> None:
    assert _stop_event is not None
    try:
        await asyncio.wait_for(_stop_event.wait(), timeout=max(1, seconds))
    except asyncio.TimeoutError:
        pass

