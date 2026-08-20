from __future__ import annotations

from contextlib import contextmanager
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest

from app import assignment_worker
from app.assignment_repo import AssignmentResult

pytestmark = pytest.mark.unit


@contextmanager
def _fake_session():
    yield object()


def _settings(monkeypatch):
    monkeypatch.setattr(assignment_worker.settings, "ASSIGNMENT_BATCH_SIZE", 50)
    monkeypatch.setattr(assignment_worker.settings, "ASSIGNMENT_MAX_ACTIVE_CASES", 2)
    monkeypatch.setattr(assignment_worker.settings, "AGENT_PRESENCE_STALE_SECONDS", 90)
    monkeypatch.setattr(assignment_worker.settings, "ASSIGNMENT_ACTIVE_STATUS_CODES", "ASIGNADO,EN_PROCESO")


def test_active_status_codes_are_configurable(monkeypatch):
    monkeypatch.setattr(assignment_worker.settings, "ASSIGNMENT_ACTIVE_STATUS_CODES", " ASIGNADO , EN_PROCESO,ASIGNADO ")
    assert assignment_worker._active_status_codes() == ("ASIGNADO", "EN_PROCESO")


async def test_cycle_assigns_until_queue_empty_and_notifies(monkeypatch):
    _settings(monkeypatch)
    monkeypatch.setattr(assignment_worker, "_db_session", _fake_session)
    results = iter([
        AssignmentResult(status="assigned", case_id=1, agent_id=10, case_subject="A", active_load_before=0),
        AssignmentResult(status="assigned", case_id=2, agent_id=10, case_subject="B", active_load_before=1),
        AssignmentResult(status="no_cases"),
    ])
    assign = Mock(side_effect=lambda *args, **kwargs: next(results))
    pending = Mock(return_value=0)
    notify = AsyncMock()
    monkeypatch.setattr(assignment_worker.assignment_repo, "assign_one_case", assign)
    monkeypatch.setattr(assignment_worker.assignment_repo, "pending_unassigned_count", pending)
    monkeypatch.setattr(assignment_worker, "_notify_assignment", notify)

    result = await assignment_worker.run_assignment_cycle(limit=10)

    assert result == {"assigned": 2, "pending": 0, "no_capacity": False}
    assert notify.await_count == 2
    assert assign.call_count == 3


async def test_cycle_stops_when_capacity_is_exhausted_and_leaves_pending(monkeypatch):
    _settings(monkeypatch)
    monkeypatch.setattr(assignment_worker, "_db_session", _fake_session)
    assign = Mock(return_value=AssignmentResult(status="no_capacity", case_id=99))
    pending = Mock(return_value=12)
    notify = AsyncMock()
    monkeypatch.setattr(assignment_worker.assignment_repo, "assign_one_case", assign)
    monkeypatch.setattr(assignment_worker.assignment_repo, "pending_unassigned_count", pending)
    monkeypatch.setattr(assignment_worker, "_notify_assignment", notify)

    result = await assignment_worker.run_assignment_cycle(limit=50)

    assert result == {"assigned": 0, "pending": 12, "no_capacity": True}
    assert assign.call_count == 1
    notify.assert_not_awaited()


async def test_cycle_forwards_capacity_presence_and_active_status_config(monkeypatch):
    _settings(monkeypatch)
    monkeypatch.setattr(assignment_worker, "_db_session", _fake_session)
    assign = Mock(return_value=AssignmentResult(status="no_cases"))
    monkeypatch.setattr(assignment_worker.assignment_repo, "assign_one_case", assign)
    monkeypatch.setattr(assignment_worker.assignment_repo, "pending_unassigned_count", Mock(return_value=0))

    await assignment_worker.run_assignment_cycle(limit=1)

    kwargs = assign.call_args.kwargs
    assert kwargs["max_active_cases"] == 2
    assert kwargs["stale_seconds"] == 90
    assert kwargs["active_status_codes"] == ("ASIGNADO", "EN_PROCESO")
