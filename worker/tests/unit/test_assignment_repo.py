from __future__ import annotations

from unittest.mock import Mock

import pytest

from app import assignment_repo

pytestmark = pytest.mark.unit


class FakeResult:
    def __init__(self, *, rows=None, row=None, scalar=None, rowcount=0):
        self._rows = rows or []
        self._row = row
        self._scalar = scalar
        self.rowcount = rowcount

    def fetchall(self):
        return self._rows

    def fetchone(self):
        return self._row

    def scalar_one(self):
        return self._scalar


class ScriptedDB:
    def __init__(self, *, case_exists=True, candidates=None, presence=None, active_load=0, update_rowcount=1):
        self.case_exists = case_exists
        self.candidates = candidates if candidates is not None else [(7, 0)]
        self.presence = presence if presence is not None else (7, 1, 1, "DISPONIBLE", 1, 10)
        self.active_load = active_load
        self.update_rowcount = update_rowcount
        self.executed = []

    def execute(self, stmt, params=None):
        sql = str(stmt)
        self.executed.append((sql, params or {}))

        if "FROM case_statuses WHERE code" in sql:
            code = (params or {}).get("code")
            return FakeResult(row=(1 if code == "NUEVO" else 2,))
        if "SELECT id, subject" in sql and "FROM cases" in sql:
            return FakeResult(row=(101, "Caso de prueba") if self.case_exists else None)
        if "SELECT\n                u.id" in sql and "FROM users u" in sql:
            return FakeResult(rows=self.candidates)
        if "FROM agent_presence ap" in sql and "FOR UPDATE" in sql:
            return FakeResult(row=self.presence)
        if "SELECT COUNT(*)" in sql and "WHERE c.assigned_user_id = :uid" in sql:
            return FakeResult(scalar=self.active_load)
        if "UPDATE cases" in sql:
            return FakeResult(rowcount=self.update_rowcount)
        if "UPDATE users" in sql:
            return FakeResult(rowcount=1)
        if "SELECT COUNT(*)" in sql and "cs.code = 'NUEVO'" in sql:
            return FakeResult(scalar=3)
        raise AssertionError(f"SQL no esperado: {sql}")


def test_active_status_bindings_are_normalized_and_parameterized():
    clause, params = assignment_repo._active_status_bindings(
        [" asignado ", "EN_PROCESO", ""]
    )
    assert clause == ":active_0, :active_1"
    assert params == {"active_0": "ASIGNADO", "active_1": "EN_PROCESO"}


def test_candidate_query_requires_available_live_agent_and_capacity():
    db = ScriptedDB(candidates=[(9, 1)])
    ids = assignment_repo._pick_candidate_agent_ids(
        db,
        max_active_cases=2,
        stale_seconds=90,
        active_status_codes=("ASIGNADO", "EN_PROCESO"),
    )
    assert ids == [9]
    sql, params = db.executed[-1]
    assert "JOIN agent_presence ap" in sql
    assert "aps.code = 'DISPONIBLE'" in sql
    assert "aps.is_assignable = 1" in sql
    assert "TIMESTAMPDIFF(SECOND, ap.last_seen_at, NOW(6)) <= :stale_seconds" in sql
    assert "COALESCE(loads.active_cases, 0) < :max_active" in sql
    assert params["max_active"] == 2
    assert params["stale_seconds"] == 90


def test_lock_recheck_rejects_non_available_agent_without_count_query():
    db = ScriptedDB(presence=(7, 1, 1, "AUSENTE", 0, 10))
    load = assignment_repo._lock_and_recheck_agent(
        db,
        agent_id=7,
        max_active_cases=2,
        stale_seconds=90,
        active_status_codes=("ASIGNADO", "EN_PROCESO"),
    )
    assert load is None
    assert len(db.executed) == 1


def test_lock_recheck_rejects_stale_heartbeat():
    db = ScriptedDB(presence=(7, 1, 1, "DISPONIBLE", 1, 91))
    load = assignment_repo._lock_and_recheck_agent(
        db,
        agent_id=7,
        max_active_cases=2,
        stale_seconds=90,
        active_status_codes=("ASIGNADO", "EN_PROCESO"),
    )
    assert load is None


def test_lock_recheck_rejects_agent_at_capacity():
    db = ScriptedDB(active_load=2)
    load = assignment_repo._lock_and_recheck_agent(
        db,
        agent_id=7,
        max_active_cases=2,
        stale_seconds=90,
        active_status_codes=("ASIGNADO", "EN_PROCESO"),
    )
    assert load is None


def test_assign_one_assigns_fifo_case_and_records_event(monkeypatch):
    db = ScriptedDB(active_load=1)
    event = Mock()
    monkeypatch.setattr(assignment_repo.repos, "insert_case_event", event)

    result = assignment_repo.assign_one_case(
        db,
        max_active_cases=2,
        stale_seconds=90,
        active_status_codes=("ASIGNADO", "EN_PROCESO"),
    )

    assert result.status == "assigned"
    assert result.case_id == 101
    assert result.agent_id == 7
    assert result.active_load_before == 1
    assert result.case_subject == "Caso de prueba"
    event.assert_called_once()
    assert event.call_args.kwargs["event_type"] == "ASSIGNED"
    assert event.call_args.kwargs["details"]["mode"] == "availability_worker"
    assert event.call_args.kwargs["details"]["max_active_cases"] == 2

    case_select = next(sql for sql, _ in db.executed if "SELECT id, subject" in sql)
    assert "ORDER BY received_at ASC, id ASC" in case_select
    assert "FOR UPDATE" in case_select
    presence_lock = next(sql for sql, _ in db.executed if "FROM agent_presence ap" in sql and "FOR UPDATE" in sql)
    assert "FOR UPDATE" in presence_lock


def test_assign_one_keeps_case_waiting_when_no_capacity(monkeypatch):
    db = ScriptedDB(candidates=[])
    event = Mock()
    monkeypatch.setattr(assignment_repo.repos, "insert_case_event", event)

    result = assignment_repo.assign_one_case(
        db,
        max_active_cases=2,
        stale_seconds=90,
        active_status_codes=("ASIGNADO", "EN_PROCESO"),
    )
    assert result.status == "no_capacity"
    assert result.case_id == 101
    event.assert_not_called()
    assert not any("UPDATE cases" in sql for sql, _ in db.executed)


def test_assign_one_returns_no_cases_without_agent_lookup(monkeypatch):
    db = ScriptedDB(case_exists=False)
    event = Mock()
    monkeypatch.setattr(assignment_repo.repos, "insert_case_event", event)
    result = assignment_repo.assign_one_case(
        db,
        max_active_cases=2,
        stale_seconds=90,
        active_status_codes=("ASIGNADO", "EN_PROCESO"),
    )
    assert result.status == "no_cases"
    event.assert_not_called()
    assert not any("FROM agent_presence ap" in sql for sql, _ in db.executed)
