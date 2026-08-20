from __future__ import annotations

from dataclasses import dataclass
from typing import Sequence

from sqlalchemy import text
from sqlalchemy.orm import Session

from app import repos


@dataclass(frozen=True)
class AssignmentResult:
    status: str
    case_id: int | None = None
    agent_id: int | None = None
    case_subject: str = ""
    active_load_before: int | None = None


def _active_status_bindings(active_status_codes: Sequence[str]) -> tuple[str, dict[str, str]]:
    codes = [str(c).strip().upper() for c in active_status_codes if str(c).strip()]
    if not codes:
        codes = ["ASIGNADO", "EN_PROCESO"]
    params = {f"active_{idx}": code for idx, code in enumerate(codes)}
    clause = ", ".join(f":active_{idx}" for idx in range(len(codes)))
    return clause, params


def _get_status_id(db: Session, code: str) -> int:
    row = db.execute(
        text("SELECT id FROM case_statuses WHERE code = :code LIMIT 1"),
        {"code": code},
    ).fetchone()
    if not row:
        raise RuntimeError(f"Missing status in DB: {code}")
    return int(row[0])


def _pick_candidate_agent_ids(
    db: Session,
    *,
    max_active_cases: int,
    stale_seconds: int,
    active_status_codes: Sequence[str],
    candidate_limit: int = 25,
) -> list[int]:
    """
    Devuelve candidatos ordenados por menor carga y mayor antigüedad de
    última asignación. La decisión final NO se toma aquí: cada candidato
    se bloquea después sobre agent_presence y se recalcula su carga para
    impedir sobreasignación con múltiples workers concurrentes.
    """
    max_active_cases = max(1, int(max_active_cases))
    stale_seconds = max(30, int(stale_seconds))
    candidate_limit = max(1, min(100, int(candidate_limit)))
    active_clause, active_params = _active_status_bindings(active_status_codes)

    params: dict[str, object] = {
        "max_active": max_active_cases,
        "stale_seconds": stale_seconds,
        "candidate_limit": candidate_limit,
        **active_params,
    }

    rows = db.execute(
        text(
            f"""
            SELECT
                u.id,
                COALESCE(loads.active_cases, 0) AS active_cases
            FROM users u
            JOIN agent_presence ap ON ap.user_id = u.id
            JOIN agent_presence_statuses aps ON aps.id = ap.status_id
            LEFT JOIN (
                SELECT c.assigned_user_id, COUNT(*) AS active_cases
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                WHERE c.assigned_user_id IS NOT NULL
                  AND cs.code IN ({active_clause})
                GROUP BY c.assigned_user_id
            ) loads ON loads.assigned_user_id = u.id
            WHERE u.is_active = 1
              AND u.assign_enabled = 1
              AND aps.is_active = 1
              AND aps.is_assignable = 1
              AND aps.code = 'DISPONIBLE'
              AND TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) <= :stale_seconds
              AND COALESCE(loads.active_cases, 0) < :max_active
              AND EXISTS (
                    SELECT 1
                    FROM user_roles ur
                    JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = u.id
                      AND UPPER(TRIM(r.code)) IN ('AGENTE', 'AGENT')
              )
            ORDER BY
                COALESCE(loads.active_cases, 0) ASC,
                COALESCE(u.last_assigned_at, '1970-01-01') ASC,
                u.id ASC
            LIMIT :candidate_limit
            """
        ),
        params,
    ).fetchall()

    return [int(row[0]) for row in rows]


def _lock_and_recheck_agent(
    db: Session,
    *,
    agent_id: int,
    max_active_cases: int,
    stale_seconds: int,
    active_status_codes: Sequence[str],
) -> int | None:
    """
    Serializa asignaciones por agente bloqueando agent_presence. Tras ganar
    el lock vuelve a validar presencia, conexión, assign_enabled y carga.
    Retorna la carga activa actual si sigue elegible; si no, None.
    """
    presence = db.execute(
        text(
            """
            SELECT
                ap.user_id,
                u.is_active,
                u.assign_enabled,
                aps.code,
                aps.is_assignable,
                TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) AS seconds_since_seen
            FROM agent_presence ap
            JOIN users u ON u.id = ap.user_id
            JOIN agent_presence_statuses aps ON aps.id = ap.status_id
            WHERE ap.user_id = :uid
            LIMIT 1
            FOR UPDATE
            """
        ),
        {"uid": int(agent_id)},
    ).fetchone()

    if not presence:
        return None

    is_active = int(presence[1] or 0) == 1
    assign_enabled = int(presence[2] or 0) == 1
    status_code = str(presence[3] or "").upper()
    is_assignable = int(presence[4] or 0) == 1
    seconds_since_seen = int(presence[5] or 0)

    if (
        not is_active
        or not assign_enabled
        or status_code != "DISPONIBLE"
        or not is_assignable
        or seconds_since_seen > max(30, int(stale_seconds))
    ):
        return None

    active_clause, active_params = _active_status_bindings(active_status_codes)
    params: dict[str, object] = {"uid": int(agent_id), **active_params}
    active_load = int(
        db.execute(
            text(
                f"""
                SELECT COUNT(*)
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                WHERE c.assigned_user_id = :uid
                  AND cs.code IN ({active_clause})
                """
            ),
            params,
        ).scalar_one()
        or 0
    )

    if active_load >= max(1, int(max_active_cases)):
        return None

    return active_load


def assign_one_case(
    db: Session,
    *,
    max_active_cases: int,
    stale_seconds: int,
    active_status_codes: Sequence[str],
) -> AssignmentResult:
    """
    Asigna, como máximo, un caso NUEVO sin agente.

    Concurrencia:
    1. bloquea el caso FIFO con FOR UPDATE;
    2. obtiene candidatos por carga;
    3. bloquea agent_presence del candidato;
    4. recalcula carga con el lock adquirido;
    5. actualiza el caso solo si continúa NUEVO + sin asignar.

    Dos workers pueden competir, pero nunca deben llevar un agente por encima
    de max_active_cases porque la fila de presencia serializa la decisión.
    """
    max_active_cases = max(1, int(max_active_cases))
    stale_seconds = max(30, int(stale_seconds))
    status_nuevo_id = _get_status_id(db, "NUEVO")
    status_asignado_id = _get_status_id(db, "ASIGNADO")

    case_row = db.execute(
        text(
            """
            SELECT id, subject
            FROM cases
            WHERE assigned_user_id IS NULL
              AND status_id = :nuevo
            ORDER BY received_at ASC, id ASC
            LIMIT 1
            FOR UPDATE
            """
        ),
        {"nuevo": status_nuevo_id},
    ).fetchone()

    if not case_row:
        return AssignmentResult(status="no_cases")

    case_id = int(case_row[0])
    case_subject = str(case_row[1] or "")

    candidate_ids = _pick_candidate_agent_ids(
        db,
        max_active_cases=max_active_cases,
        stale_seconds=stale_seconds,
        active_status_codes=active_status_codes,
    )
    if not candidate_ids:
        return AssignmentResult(status="no_capacity", case_id=case_id, case_subject=case_subject)

    for agent_id in candidate_ids:
        active_load = _lock_and_recheck_agent(
            db,
            agent_id=agent_id,
            max_active_cases=max_active_cases,
            stale_seconds=stale_seconds,
            active_status_codes=active_status_codes,
        )
        if active_load is None:
            continue

        result = db.execute(
            text(
                """
                UPDATE cases
                SET assigned_user_id = :uid,
                    status_id = :asignado,
                    assigned_at = NOW(6),
                    last_activity_at = NOW(6),
                    updated_at = NOW(6)
                WHERE id = :cid
                  AND assigned_user_id IS NULL
                  AND status_id = :nuevo
                LIMIT 1
                """
            ),
            {
                "uid": agent_id,
                "asignado": status_asignado_id,
                "cid": case_id,
                "nuevo": status_nuevo_id,
            },
        )
        if int(getattr(result, "rowcount", 0) or 0) != 1:
            return AssignmentResult(status="lost_case_race", case_id=case_id)

        db.execute(
            text(
                """
                UPDATE users
                SET last_assigned_at = NOW(6), updated_at = NOW(6)
                WHERE id = :uid
                LIMIT 1
                """
            ),
            {"uid": agent_id},
        )

        repos.insert_case_event(
            db,
            case_id=case_id,
            actor_user_id=None,
            source="WORKER",
            event_type="ASSIGNED",
            from_status_id=status_nuevo_id,
            to_status_id=status_asignado_id,
            details={
                "mode": "availability_worker",
                "assigned_user_id": agent_id,
                "active_load_before": active_load,
                "max_active_cases": max_active_cases,
                "presence_required": "DISPONIBLE",
            },
        )

        return AssignmentResult(
            status="assigned",
            case_id=case_id,
            agent_id=agent_id,
            case_subject=case_subject,
            active_load_before=active_load,
        )

    return AssignmentResult(status="no_capacity", case_id=case_id, case_subject=case_subject)


def pending_unassigned_count(db: Session) -> int:
    return int(
        db.execute(
            text(
                """
                SELECT COUNT(*)
                FROM cases c
                JOIN case_statuses cs ON cs.id = c.status_id
                WHERE c.assigned_user_id IS NULL
                  AND cs.code = 'NUEVO'
                """
            )
        ).scalar_one()
        or 0
    )
