from __future__ import annotations

"""
Tests unitarios para app.webhook (endpoint /graph/webhook).

Ademas de la cobertura funcional normal, esta suite incluye guardas de
regresion explicitas para el fix de C1 (SWEBOK v4, Software Testing KA -
"regression testing": pruebas que fijan un comportamiento corregido para
que un cambio futuro no lo reintroduzca sin que un test falle primero):

  - sync_service.process_message_id_async NUNCA debe ser invocado desde
    este modulo. El webhook solo valida y encola; la materializacion es
    responsabilidad exclusiva de inbound_queue_worker.
  - Las funciones start_webhook_workers / stop_webhook_workers ya no
    existen en el modulo (se eliminaron junto con la cola en memoria).
"""

from unittest.mock import AsyncMock, Mock

import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient

import app.webhook as webhook_module

pytestmark = pytest.mark.unit

CLIENT_STATE = "test-secret-client-state"
MAILBOX = "buzon@icbf.gov.co"


@pytest.fixture
def app_client(monkeypatch):
    """App minima que solo monta el router de webhook, sin arrastrar el
    resto de app.main (que exige TLS bootstrap, logging, etc.)."""
    monkeypatch.setattr(webhook_module.settings, "GRAPH_CLIENT_STATE", CLIENT_STATE)
    monkeypatch.setattr(webhook_module.settings, "MAILBOX_EMAIL", MAILBOX)

    app = FastAPI()
    app.include_router(webhook_module.router)
    return TestClient(app)


@pytest.fixture
def patched_enqueue(monkeypatch, fake_db_session):
    """Sustituye inbound_queue_repo.enqueue_event y get_db_session; por
    defecto simula que cada llamada crea un evento nuevo (retorna un id
    incremental)."""
    _, get_db_session_cm = fake_db_session
    monkeypatch.setattr(webhook_module, "get_db_session", get_db_session_cm)

    counter = {"n": 0}

    def _default_enqueue(*args, **kwargs):
        counter["n"] += 1
        return counter["n"]

    enqueue_mock = Mock(side_effect=_default_enqueue)
    monkeypatch.setattr(webhook_module.inbound_queue_repo, "enqueue_event", enqueue_mock)
    return enqueue_mock


@pytest.fixture
def sync_service_spy(monkeypatch):
    """Espia sync_service.process_message_id_async para poder afirmar,
    en cada test relevante, que jamas se invoca desde el webhook."""
    spy = AsyncMock()
    monkeypatch.setattr(
        webhook_module.sync_service, "process_message_id_async", spy
    )
    return spy


def _notification(*, client_state=CLIENT_STATE, message_id="AAMk-msg-1", subscription_id="sub-1"):
    return {
        "subscriptionId": subscription_id,
        "clientState": client_state,
        "resourceData": {"id": message_id},
    }


# ---------------------------------------------------------------------------
# Validacion de suscripcion (handshake GET / POST con validationToken)
# ---------------------------------------------------------------------------

class TestSubscriptionValidation:
    def test_get_with_validation_token_echoes_token(self, app_client):
        resp = app_client.get("/graph/webhook", params={"validationToken": "abc123"})
        assert resp.status_code == 200
        assert resp.text == "abc123"
        assert resp.headers["content-type"].startswith("text/plain")

    def test_get_without_validation_token_returns_ok(self, app_client):
        resp = app_client.get("/graph/webhook")
        assert resp.status_code == 200
        assert resp.text == "OK"

    def test_post_with_validation_token_echoes_and_skips_body(
        self, app_client, patched_enqueue
    ):
        resp = app_client.post(
            "/graph/webhook", params={"validationToken": "xyz789"}
        )
        assert resp.status_code == 200
        assert resp.text == "xyz789"
        patched_enqueue.assert_not_called()


# ---------------------------------------------------------------------------
# Payloads malformados - deben responder 202 sin romper, sin encolar nada
# ---------------------------------------------------------------------------

class TestMalformedPayloads:
    def test_invalid_json_body_returns_202(self, app_client, patched_enqueue):
        resp = app_client.post(
            "/graph/webhook",
            content=b"{not valid json",
            headers={"content-type": "application/json"},
        )
        assert resp.status_code == 202
        patched_enqueue.assert_not_called()

    def test_value_not_a_list_returns_202(self, app_client, patched_enqueue):
        resp = app_client.post("/graph/webhook", json={"value": "no-deberia-ser-string"})
        assert resp.status_code == 202
        patched_enqueue.assert_not_called()

    def test_empty_notifications_list_returns_202(self, app_client, patched_enqueue):
        resp = app_client.post("/graph/webhook", json={"value": []})
        assert resp.status_code == 202
        patched_enqueue.assert_not_called()

    def test_missing_value_key_returns_202(self, app_client, patched_enqueue):
        resp = app_client.post("/graph/webhook", json={})
        assert resp.status_code == 202
        patched_enqueue.assert_not_called()


# ---------------------------------------------------------------------------
# clientState: solo notificaciones con el secreto correcto se procesan
# ---------------------------------------------------------------------------

class TestClientStateValidation:
    def test_wrong_client_state_is_rejected(self, app_client, patched_enqueue):
        resp = app_client.post(
            "/graph/webhook",
            json={"value": [_notification(client_state="secreto-incorrecto")]},
        )
        assert resp.status_code == 202
        patched_enqueue.assert_not_called()

    def test_missing_client_state_is_rejected(self, app_client, patched_enqueue):
        notif = _notification()
        del notif["clientState"]
        resp = app_client.post("/graph/webhook", json={"value": [notif]})
        assert resp.status_code == 202
        patched_enqueue.assert_not_called()

    def test_correct_client_state_is_accepted(self, app_client, patched_enqueue):
        resp = app_client.post(
            "/graph/webhook", json={"value": [_notification()]}
        )
        assert resp.status_code == 202
        patched_enqueue.assert_called_once()

    def test_mixed_valid_and_invalid_only_processes_valid(
        self, app_client, patched_enqueue
    ):
        resp = app_client.post(
            "/graph/webhook",
            json={
                "value": [
                    _notification(message_id="AAMk-valido"),
                    _notification(client_state="malo", message_id="AAMk-invalido"),
                ]
            },
        )
        assert resp.status_code == 202
        patched_enqueue.assert_called_once()
        assert (
            patched_enqueue.call_args.kwargs["provider_message_id"] == "AAMk-valido"
        )


# ---------------------------------------------------------------------------
# Encolado: verifica los argumentos exactos pasados a enqueue_event
# ---------------------------------------------------------------------------

class TestEnqueueBehavior:
    def test_enqueue_called_with_expected_arguments(self, app_client, patched_enqueue):
        notif = _notification(message_id="AAMk-xyz")
        app_client.post("/graph/webhook", json={"value": [notif]})

        patched_enqueue.assert_called_once()
        kwargs = patched_enqueue.call_args.kwargs
        assert kwargs["source"] == "webhook"
        assert kwargs["provider_message_id"] == "AAMk-xyz"
        assert kwargs["mailbox_email"] == MAILBOX
        assert kwargs["payload"] == notif
        # force no se pasa explicitamente -> debe tomar el default (False)
        # del propio inbound_queue_repo.enqueue_event, no algo distinto.
        assert "force" not in kwargs or kwargs["force"] is False

    def test_multiple_valid_notifications_enqueue_each_once(
        self, app_client, patched_enqueue
    ):
        notifs = [_notification(message_id=f"AAMk-{i}") for i in range(3)]
        resp = app_client.post("/graph/webhook", json={"value": notifs})

        assert resp.status_code == 202
        assert patched_enqueue.call_count == 3

    def test_notification_without_extractable_message_id_is_skipped(
        self, app_client, patched_enqueue
    ):
        notif = {"clientState": CLIENT_STATE}  # sin resourceData ni resource
        resp = app_client.post("/graph/webhook", json={"value": [notif]})

        assert resp.status_code == 202
        patched_enqueue.assert_not_called()

    def test_enqueue_returning_none_does_not_raise(self, app_client, patched_enqueue):
        """enqueue_event() devuelve None cuando el evento ya estaba
        pending/processing (reciclado silenciosamente) - no es un error."""
        patched_enqueue.side_effect = None
        patched_enqueue.return_value = None

        resp = app_client.post(
            "/graph/webhook", json={"value": [_notification()]}
        )
        assert resp.status_code == 202


# ---------------------------------------------------------------------------
# Resiliencia: un fallo al persistir una notificacion no debe tumbar la
# request completa ni impedir que se procesen las demas notificaciones.
# ---------------------------------------------------------------------------

class TestPartialFailureResilience:
    def test_one_failing_enqueue_does_not_block_the_others(
        self, app_client, patched_enqueue
    ):
        def _side_effect(*args, **kwargs):
            if kwargs["provider_message_id"] == "AAMk-falla":
                raise RuntimeError("DB caida momentaneamente")
            return 1

        patched_enqueue.side_effect = _side_effect

        resp = app_client.post(
            "/graph/webhook",
            json={
                "value": [
                    _notification(message_id="AAMk-falla"),
                    _notification(message_id="AAMk-ok"),
                ]
            },
        )

        assert resp.status_code == 202
        assert patched_enqueue.call_count == 2


# ---------------------------------------------------------------------------
# Guarda de regresion de C1
# ---------------------------------------------------------------------------

class TestSingleMaterializationGateRegression:
    def test_process_message_id_async_is_never_called(
        self, app_client, patched_enqueue, sync_service_spy
    ):
        app_client.post(
            "/graph/webhook",
            json={"value": [_notification(message_id="AAMk-1"), _notification(message_id="AAMk-2")]},
        )
        sync_service_spy.assert_not_called()

    def test_in_memory_queue_functions_no_longer_exist(self):
        assert not hasattr(webhook_module, "start_webhook_workers")
        assert not hasattr(webhook_module, "stop_webhook_workers")
        assert not hasattr(webhook_module, "_webhook_consumer")
        assert not hasattr(webhook_module, "_webhook_queue")


# ---------------------------------------------------------------------------
# Item no-dict dentro de la lista "value" - no debe romper el resto
# ---------------------------------------------------------------------------

class TestNonDictNotificationItems:
    def test_non_dict_item_is_skipped_valid_ones_still_processed(
        self, app_client, patched_enqueue
    ):
        resp = app_client.post(
            "/graph/webhook",
            json={"value": ["esto-no-es-un-objeto", _notification(message_id="AAMk-1")]},
        )
        assert resp.status_code == 202
        patched_enqueue.assert_called_once()


# ---------------------------------------------------------------------------
# _client_ip: funcion pura, se prueba con un doble minimo de Request en
# vez de levantar todo el stack HTTP - ejemplo de test unitario de caja
# blanca sobre una unidad pequena (SWEBOK v4, Software Testing KA).
# ---------------------------------------------------------------------------

class _DummyClient:
    def __init__(self, host):
        self.host = host


class _DummyRequest:
    def __init__(self, headers=None, client_host=None):
        self.headers = headers or {}
        self.client = _DummyClient(client_host) if client_host else None


class TestClientIpHelper:
    def test_prefers_x_forwarded_for_first_value(self):
        req = _DummyRequest(
            headers={"x-forwarded-for": "1.2.3.4, 5.6.7.8"}, client_host="9.9.9.9"
        )
        assert webhook_module._client_ip(req) == "1.2.3.4"

    def test_falls_back_to_request_client_host(self):
        req = _DummyRequest(client_host="9.9.9.9")
        assert webhook_module._client_ip(req) == "9.9.9.9"

    def test_returns_unknown_when_nothing_available(self):
        req = _DummyRequest()
        assert webhook_module._client_ip(req) == "unknown"
