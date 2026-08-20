from __future__ import annotations

"""Regresiones estructurales del repositorio D2.

Estas pruebas no sustituyen la validación MariaDB real; protegen las
condiciones SQL que evitan degradar estados avanzados y garantizan que la
identidad Graph se compare normalizada.
"""

from types import SimpleNamespace
from unittest.mock import Mock

import pytest

from app import attachment_recovery_repo as repo

pytestmark = pytest.mark.unit


def _sql_of(call) -> str:
    stmt = call.args[0]
    return str(stmt)


def test_upsert_pending_only_mutates_unlocked_pending_rows():
    db = Mock()

    repo.upsert_pending(db, message_id=10, reason="MANIFEST_DETECTED", locked=True)

    sql = _sql_of(db.execute.call_args)
    assert "status = 'pending' AND locked_at IS NULL" in sql
    assert "VALUES(last_reason)" in sql
    assert "VALUES(locked_at)" in sql
    # No debe existir la lógica anterior que permitía tocar verifying.
    assert "status IN ('complete','blocked')" not in sql


def test_release_foreground_lock_cannot_clobber_advanced_state():
    db = Mock()

    repo.release_foreground_lock(
        db,
        message_id=10,
        status="verifying",
        reason="FOREGROUND_FIRST_PASS_COMPLETE",
    )

    sql = _sql_of(db.execute.call_args)
    assert "AND status = 'pending'" in sql
    assert "AND last_reason = 'MANIFEST_DETECTED'" in sql
    assert "AND locked_at IS NOT NULL" in sql


def test_get_persisted_graph_ids_strips_and_ignores_blank_values():
    result = Mock()
    result.fetchall.return_value = [
        (" A ",),
        ("B",),
        ("   ",),
        (None,),
    ]
    db = Mock()
    db.execute.return_value = result

    assert repo.get_persisted_graph_ids(db, message_id=10) == {"A", "B"}


def test_mark_complete_is_the_only_terminal_verified_timestamp_writer():
    db = Mock()

    repo.mark_complete(db, message_id=10)

    sql = _sql_of(db.execute.call_args)
    assert "verified_at = NOW(6)" in sql
    assert "completed_at = NOW(6)" in sql


def test_upsert_pending_resets_available_at_only_when_it_can_take_unlocked_pending():
    db = Mock()

    repo.upsert_pending(db, message_id=10, reason="MANIFEST_DETECTED", locked=True)

    sql = _sql_of(db.execute.call_args)
    assert "available_at = IF(" in sql
    assert "status = 'pending' AND locked_at IS NULL" in sql
    assert "NOW(6)" in sql


def test_release_foreground_lock_rejects_invalid_status_before_sql():
    db = Mock()

    with pytest.raises(ValueError, match="invalid foreground recovery status"):
        repo.release_foreground_lock(
            db,
            message_id=10,
            status="complete",
            reason="SHOULD_NOT_BE_ALLOWED",
        )

    db.execute.assert_not_called()
