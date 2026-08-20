from __future__ import annotations

import asyncio
import logging
from typing import Any

from app.settings import settings
from app import assignment_repo

logger = logging.getLogger("app.assignment_worker")


def _db_session():
    # Import perezoso para que el módulo pueda cargarse/testearse sin abrir
    # el engine hasta que el proceso realmente ejecute un ciclo.
    from app.db import get_db_session
    return get_db_session()


def _active_status_codes() -> tuple[str, ...]:
    raw = str(getattr(settings, "ASSIGNMENT_ACTIVE_STATUS_CODES", "ASIGNADO,EN_PROCESO") or "")
    values = tuple(
        dict.fromkeys(
            code.strip().upper()
            for code in raw.split(",")
            if code.strip()
        )
    )
    return values or ("ASIGNADO", "EN_PROCESO")


async def _notify_assignment(*, case_id: int, agent_id: int, case_subject: str) -> None:
    # Import perezoso: el assignment worker solo carga Graph cuando realmente
    # tiene que notificar una asignación; el ciclo de capacidad sigue siendo
    # puramente BD.
    from app.agent_notifications import notify_agent_new_case
    await notify_agent_new_case(
        case_id=case_id, agent_id=agent_id, case_subject=case_subject
    )


async def run_assignment_cycle(limit: int | None = None) -> dict[str, Any]:
    """Procesa hasta `limit` casos FIFO. Se detiene cuando no hay casos o capacidad."""
    batch_size = max(1, int(limit or settings.ASSIGNMENT_BATCH_SIZE))
    assigned = 0
    no_capacity = False

    for _ in range(batch_size):
        with _db_session() as db:
            result = assignment_repo.assign_one_case(
                db,
                max_active_cases=settings.ASSIGNMENT_MAX_ACTIVE_CASES,
                stale_seconds=settings.AGENT_PRESENCE_STALE_SECONDS,
                active_status_codes=_active_status_codes(),
            )

        if result.status == "assigned":
            assigned += 1
            logger.info(
                "CASE_ASSIGNED | case_id=%s | agent_id=%s | load_before=%s",
                result.case_id,
                result.agent_id,
                result.active_load_before,
            )
            if result.case_id is not None and result.agent_id is not None:
                await _notify_assignment(
                    case_id=result.case_id,
                    agent_id=result.agent_id,
                    case_subject=result.case_subject,
                )
            continue

        if result.status == "no_capacity":
            no_capacity = True
            break

        if result.status in {"no_cases", "lost_case_race"}:
            break

        logger.warning("Unknown assignment result: %s", result.status)
        break

    with _db_session() as db:
        pending = assignment_repo.pending_unassigned_count(db)

    return {
        "assigned": assigned,
        "pending": pending,
        "no_capacity": no_capacity,
    }


async def run_forever() -> None:
    if not bool(settings.ASSIGNMENT_WORKER_ENABLED):
        raise RuntimeError(
            "ASSIGNMENT_WORKER_ENABLED=0. Habilítelo solo después de aplicar el esquema de presencia."
        )

    poll_seconds = max(1, int(settings.ASSIGNMENT_POLL_SECONDS))
    logger.warning(
        "Assignment worker started | poll=%ss | batch=%s | max_active=%s | stale=%ss | active_statuses=%s",
        poll_seconds,
        settings.ASSIGNMENT_BATCH_SIZE,
        settings.ASSIGNMENT_MAX_ACTIVE_CASES,
        settings.AGENT_PRESENCE_STALE_SECONDS,
        _active_status_codes(),
    )

    while True:
        try:
            result = await run_assignment_cycle()
            logger.info(
                "Assignment cycle | assigned=%s | pending=%s | no_capacity=%s",
                result["assigned"],
                result["pending"],
                result["no_capacity"],
            )
        except asyncio.CancelledError:
            raise
        except Exception as exc:
            logger.exception("Assignment cycle failed: %s", exc)

        await asyncio.sleep(poll_seconds)


if __name__ == "__main__":
    try:
        asyncio.run(run_forever())
    except KeyboardInterrupt:
        pass
