from __future__ import annotations

"""
Tests unitarios para app.delta_routes, acotados a los dos endpoints que
tocamos en el fix de C1: /admin/reprocess y /admin/reprocess-batch.

Los demas endpoints del modulo (/graph/delta/*, /admin/reconcile/*,
/admin/inbound-queue/*) no cambiaron de comportamiento en esta fase y
quedan fuera de alcance de esta suite (podrian cubrirse en un pase de
regresion mas amplio antes de tocarlos).
"""

from contextlib import contextmanager
from unittest.mock import Mock

import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient

import app.delta_routes as delta_routes_module

pytestmark = pytest.mark.unit

ADMIN_KEY = "test-admin-key"
MAILBOX = "buzon@icbf.gov.co"


@pytest.fixture
def app_client(monkeypatch):
    monkeypatch.setattr(delta_routes_module.settings, "ADMIN_API_KEY", ADMIN_KEY)
    monkeypatch.setattr(delta_routes_module.settings, "MAILBOX_EMAIL", MAILBOX)

    app = FastAPI()
    app.include_router(delta_routes_module.router)
    return TestClient(app)


@pytest.fixture
def auth_headers():
    return {"x-admin-key": ADMIN_KEY}


@pytest.fixture
def patched_enqueue(monkeypatch, fake_db_session):
    """get_db_session + inbound_queue_repo.enqueue_event mockeados, para
    los tests que no necesitan una consulta SQL real (todo /admin/reprocess,
    y la mitad de /admin/reprocess-batch)."""
    _, get_db_session_cm = fake_db_session
    monkeypatch.setattr(delta_routes_module, "get_db_session", get_db_session_cm)

    enqueue_mock = Mock(return_value=1)
    monkeypatch.setattr(
        delta_routes_module.inbound_queue_repo, "enqueue_event", enqueue_mock
    )
    return enqueue_mock


@pytest.fixture
def fake_db_with_rows(monkeypatch):
    """Para /admin/reprocess-batch: simula el SELECT inicial devolviendo
    filas configurables. `db.execute(...).fetchall()` es lo unico que el
    endpoint invoca sobre la sesion en ese paso."""

    def _make(rows: list[tuple]):
        db = Mock()
        db.execute.return_value.fetchall.return_value = rows

        @contextmanager
        def _cm():
            yield db

        monkeypatch.setattr(delta_routes_module, "get_db_session", _cm)
        return db

    return _make


# ---------------------------------------------------------------------------
# Autenticacion (compartida por ambos endpoints via _require_admin_key)
# ---------------------------------------------------------------------------

class TestAdminKeyAuth:
    def test_missing_key_returns_401(self, app_client):
        resp = app_client.post("/admin/reprocess", params={"message_id": "AAMk-1"})
        assert resp.status_code == 401

    def test_wrong_key_returns_401(self, app_client):
        resp = app_client.post(
            "/admin/reprocess",
            params={"message_id": "AAMk-1"},
            headers={"x-admin-key": "clave-incorrecta"},
        )
        assert resp.status_code == 401

    def test_missing_message_id_param_returns_422(self, app_client, auth_headers):
        resp = app_client.post("/admin/reprocess", headers=auth_headers)
        assert resp.status_code == 422


# ---------------------------------------------------------------------------
# /admin/reprocess
# ---------------------------------------------------------------------------

class TestReprocessSingle:
    def test_successful_enqueue_returns_event_id(
        self, app_client, auth_headers, patched_enqueue
    ):
        patched_enqueue.return_value = 555

        resp = app_client.post(
            "/admin/reprocess",
            params={"message_id": "AAMk-abc"},
            headers=auth_headers,
        )

        assert resp.status_code == 200
        body = resp.json()
        assert body == {
            "success": True,
            "message_id": "AAMk-abc",
            "queued": True,
            "event_id": 555,
        }

    def test_enqueue_called_with_force_true_and_source_manual(
        self, app_client, auth_headers, patched_enqueue
    ):
        app_client.post(
            "/admin/reprocess",
            params={"message_id": "AAMk-abc"},
            headers=auth_headers,
        )

        patched_enqueue.assert_called_once()
        kwargs = patched_enqueue.call_args.kwargs
        assert kwargs["source"] == "manual"
        assert kwargs["provider_message_id"] == "AAMk-abc"
        assert kwargs["mailbox_email"] == MAILBOX
        assert kwargs["force"] is True

    def test_enqueue_returning_none_reports_not_queued(
        self, app_client, auth_headers, patched_enqueue
    ):
        patched_enqueue.return_value = None

        resp = app_client.post(
            "/admin/reprocess",
            params={"message_id": "AAMk-abc"},
            headers=auth_headers,
        )

        assert resp.status_code == 200
        body = resp.json()
        assert body["success"] is True
        assert body["queued"] is False
        assert "note" in body

    def test_enqueue_exception_is_caught_and_reported(
        self, app_client, auth_headers, patched_enqueue
    ):
        patched_enqueue.side_effect = RuntimeError("DB no disponible")

        resp = app_client.post(
            "/admin/reprocess",
            params={"message_id": "AAMk-abc"},
            headers=auth_headers,
        )

        # No debe ser 500: el endpoint captura la excepcion y responde 200
        # con success=False, consistente con el contrato ya usado por
        # /admin/reprocess-batch.
        assert resp.status_code == 200
        body = resp.json()
        assert body["success"] is False
        assert "DB no disponible" in body["error"]


# ---------------------------------------------------------------------------
# /admin/reprocess-batch
# ---------------------------------------------------------------------------

class TestReprocessBatch:
    def test_no_pending_messages_returns_empty_result(
        self, app_client, auth_headers, fake_db_with_rows
    ):
        fake_db_with_rows([])

        resp = app_client.post("/admin/reprocess-batch", headers=auth_headers)

        assert resp.status_code == 200
        body = resp.json()
        assert body["total"] == 0
        assert body["queued"] == 0
        assert body["skipped"] == 0
        assert body["results"] == []

    def test_all_rows_enqueue_successfully(
        self, monkeypatch, app_client, auth_headers, fake_db_with_rows
    ):
        fake_db_with_rows([("AAMk-1",), ("AAMk-2",), ("AAMk-3",)])
        enqueue_mock = Mock(side_effect=[10, 11, 12])
        monkeypatch.setattr(
            delta_routes_module.inbound_queue_repo, "enqueue_event", enqueue_mock
        )

        resp = app_client.post("/admin/reprocess-batch", headers=auth_headers)

        assert resp.status_code == 200
        body = resp.json()
        assert body["total"] == 3
        assert body["queued"] == 3
        assert body["skipped"] == 0
        assert enqueue_mock.call_count == 3
        for call in enqueue_mock.call_args_list:
            assert call.kwargs["force"] is True
            assert call.kwargs["source"] == "manual"

    def test_one_failing_row_does_not_abort_the_batch(
        self, monkeypatch, app_client, auth_headers, fake_db_with_rows
    ):
        """Aislamiento por fila: si encolar el mensaje 2 explota, los
        mensajes 1 y 3 igual deben procesarse y quedar reflejados en el
        resultado. Antes del fix esto ya se garantizaba porque cada
        iteracion llamaba directo a sync_service con su propio try/except;
        con el cambio a enqueue_event el mismo contrato debe preservarse.
        """
        fake_db_with_rows([("AAMk-1",), ("AAMk-falla",), ("AAMk-3",)])

        def _side_effect(*args, **kwargs):
            if kwargs["provider_message_id"] == "AAMk-falla":
                raise RuntimeError("constraint violation")
            return 1

        monkeypatch.setattr(
            delta_routes_module.inbound_queue_repo,
            "enqueue_event",
            Mock(side_effect=_side_effect),
        )

        resp = app_client.post("/admin/reprocess-batch", headers=auth_headers)

        assert resp.status_code == 200
        body = resp.json()
        assert body["total"] == 3
        assert body["queued"] == 2
        statuses = {r["message_id"]: r["status"] for r in body["results"]}
        assert statuses["AAMk-1"] == "queued"
        assert statuses["AAMk-falla"] == "failed"
        assert statuses["AAMk-3"] == "queued"

    def test_row_with_recent_pending_event_is_marked_skipped(
        self, monkeypatch, app_client, auth_headers, fake_db_with_rows
    ):
        fake_db_with_rows([("AAMk-1",)])
        monkeypatch.setattr(
            delta_routes_module.inbound_queue_repo,
            "enqueue_event",
            Mock(return_value=None),
        )

        resp = app_client.post("/admin/reprocess-batch", headers=auth_headers)

        body = resp.json()
        assert body["queued"] == 0
        assert body["skipped"] == 1
        assert body["results"][0]["status"] == "skipped_recent_pending"

    def test_limit_query_param_is_passed_to_sql(
        self, app_client, auth_headers, fake_db_with_rows
    ):
        db = fake_db_with_rows([])

        app_client.post(
            "/admin/reprocess-batch", params={"limit": 7}, headers=auth_headers
        )

        params_used = db.execute.call_args.args[1]
        assert params_used["limit"] == 7

    def test_default_limit_is_fifty(self, app_client, auth_headers, fake_db_with_rows):
        db = fake_db_with_rows([])

        app_client.post("/admin/reprocess-batch", headers=auth_headers)

        params_used = db.execute.call_args.args[1]
        assert params_used["limit"] == 50


# ---------------------------------------------------------------------------
# Guarda de regresion de C1
# ---------------------------------------------------------------------------

class TestSingleMaterializationGateRegression:
    def test_process_message_id_async_is_not_imported_anymore(self):
        assert not hasattr(delta_routes_module, "process_message_id_async")
