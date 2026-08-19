from __future__ import annotations

"""
Tests de escenario (con mocks) para app.sync_service._process_single_message
- el flujo completo con el Completeness Gate integrado.

Estrategia de mocking: todas las dependencias externas (Graph, DB, repos)
se sustituyen. La sesión de DB (`get_db_session`) se reemplaza por un
doble que registra las queries crudas ejecutadas, para poder verificar
`SELECT id FROM messages ...` (usado para obtener message_pk tras insertar)
sin necesitar una base de datos real.

Cobertura basada en tabla de decisión sobre las tres fuentes de
incompletitud (receivedDateTime, body, adjuntos) cruzadas con el
presupuesto de reintentos, más las guardas de que el gate NO corre cuando
no debe (mensaje ya materializado, filtros de fecha existentes).
"""

from contextlib import contextmanager
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest

import app.sync_service as sync_service

pytestmark = pytest.mark.unit


def _base_msg(**overrides):
    msg = {
        "id": "graph-msg-1",
        "subject": "Consulta ciudadana",
        "from": {
            "emailAddress": {"address": "ciudadano@example.com", "name": "Juan Pérez"}
        },
        "toRecipients": [],
        "ccRecipients": [],
        "bccRecipients": [],
        "receivedDateTime": "2026-08-18T10:00:00Z",
        "sentDateTime": "2026-08-18T09:59:00Z",
        "body": {"contentType": "html", "content": "<p>Hola</p>"},
        "hasAttachments": False,
        "internetMessageId": "<msg1@example.com>",
        "internetMessageHeaders": [],
        "conversationId": None,
    }
    msg.update(overrides)
    return msg


class FakeDB:
    """Registra cada `db.execute(text(...), params)` crudo y responde con
    un Mock cuyo .fetchone() devuelve (message_pk,) - es lo único que
    _process_single_message necesita de una query cruda directa (además
    de lo que ya cubren los repos mockeados por separado)."""

    def __init__(self, message_pk=999):
        self.message_pk = message_pk
        self.executed: list[tuple] = []

    def execute(self, stmt, params=None):
        self.executed.append((str(stmt), params))
        result = Mock()
        result.fetchone.return_value = (self.message_pk,)
        return result


@pytest.fixture
def env(monkeypatch):
    """Entorno 'feliz' por defecto: mensaje nuevo (CASO 1), todo
    disponible, el caso se crea sin fricción. Cada test sobreescribe lo
    que necesite antes de llamar a _process_single_message."""
    fake_db = FakeDB()

    @contextmanager
    def _get_db_session():
        yield fake_db

    monkeypatch.setattr(sync_service, "get_db_session", _get_db_session)
    monkeypatch.setattr(sync_service.settings, "MAILBOX_EMAIL", "buzon@icbf.gov.co")
    monkeypatch.setattr(sync_service.settings, "WORKER_INSTANCE_ID", "worker-test")
    monkeypatch.setattr(sync_service.settings, "NOTIFICATIONS_ENABLED", False)
    monkeypatch.setattr(type(sync_service.settings), "go_live_dt", lambda self: None)
    monkeypatch.setattr(type(sync_service.settings), "stop_new_inbound_dt", lambda self: None)

    get_existing_mock = Mock(return_value=None)  # CASO 1: mensaje nuevo
    monkeypatch.setattr(sync_service, "_get_existing_message_row", get_existing_mock)
    monkeypatch.setattr(sync_service, "_message_exists", Mock(return_value=True))
    monkeypatch.setattr(sync_service, "_attachments_count", Mock(return_value=0))
    monkeypatch.setattr(sync_service, "_touch_case_activity", Mock())
    monkeypatch.setattr(
        sync_service, "_find_last_case_by_conversation", Mock(return_value=None)
    )

    create_case_mock = Mock(return_value=555)
    insert_message_mock = Mock()
    insert_event_mock = Mock()
    monkeypatch.setattr(sync_service.repos, "create_case", create_case_mock)
    monkeypatch.setattr(sync_service.repos, "insert_message_inbound", insert_message_mock)
    monkeypatch.setattr(sync_service.repos, "insert_case_event", insert_event_mock)
    monkeypatch.setattr(sync_service.repos, "auto_assign_case", Mock(return_value=None))

    get_message_mock = AsyncMock(return_value=_base_msg())
    list_attachments_mock = AsyncMock(return_value=[])
    monkeypatch.setattr(sync_service.graph_client, "get_message", get_message_mock)
    monkeypatch.setattr(
        sync_service.graph_client, "list_attachments", list_attachments_mock
    )

    process_attachments_mock = AsyncMock()
    monkeypatch.setattr(sync_service, "_process_attachments", process_attachments_mock)

    return SimpleNamespace(
        fake_db=fake_db,
        get_existing_mock=get_existing_mock,
        create_case_mock=create_case_mock,
        insert_message_mock=insert_message_mock,
        insert_event_mock=insert_event_mock,
        get_message_mock=get_message_mock,
        list_attachments_mock=list_attachments_mock,
        process_attachments_mock=process_attachments_mock,
    )


async def _run(env, *, msg=None, attempts=0, max_attempts=8):
    if msg is not None:
        env.get_message_mock.return_value = msg
    return await sync_service._process_single_message(
        mailbox_id=1,
        message_id="graph-msg-1",
        attempts=attempts,
        max_attempts=max_attempts,
    )


def _degraded_event_details(env):
    for call in env.insert_event_mock.call_args_list:
        if call.kwargs.get("event_type") == "CASE_CREATED_DEGRADED":
            return call.kwargs["details"]
    return None


# ---------------------------------------------------------------------------
# Camino feliz: mensaje completo
# ---------------------------------------------------------------------------

class TestCompleteMessage:
    async def test_complete_message_without_attachments_creates_case(self, env):
        result = await _run(env)

        assert result["status"] == "created"
        assert result["materialized"] is True
        env.create_case_mock.assert_called_once()
        env.process_attachments_mock.assert_not_called()
        assert _degraded_event_details(env) is None

    async def test_complete_message_with_attachments_reuses_gate_manifest(self, env):
        manifest = [{"id": "att-1", "@odata.type": "#microsoft.graph.fileAttachment"}]
        env.list_attachments_mock.return_value = manifest

        result = await _run(env, msg=_base_msg(hasAttachments=True))

        assert result["status"] == "created"
        env.process_attachments_mock.assert_called_once()
        assert env.process_attachments_mock.call_args.kwargs["attachments_manifest"] is manifest
        # El manifiesto se pide UNA sola vez (en el gate) - no se vuelve a
        # pedir dentro de _process_attachments (que está mockeado, pero
        # confirmamos aquí que el gate es la única fuente).
        env.list_attachments_mock.assert_called_once()


# ---------------------------------------------------------------------------
# receivedDateTime ausente
# ---------------------------------------------------------------------------

class TestMissingReceivedDateTime:
    async def test_retries_remaining_returns_incomplete_without_touching_db(self, env):
        msg = _base_msg()
        del msg["receivedDateTime"]
        msg.pop("createdDateTime", None)

        result = await _run(env, msg=msg, attempts=0, max_attempts=8)

        assert result["status"] == "incomplete:MISSING_RECEIVED_DATETIME"
        assert result["materialized"] is False
        env.get_existing_mock.assert_not_called()
        env.create_case_mock.assert_not_called()
        env.list_attachments_mock.assert_not_called()

    async def test_budget_exhausted_degrades_with_now_as_received_at(self, env):
        msg = _base_msg()
        del msg["receivedDateTime"]
        msg.pop("createdDateTime", None)

        result = await _run(env, msg=msg, attempts=7, max_attempts=8)

        assert result["status"] == "created_degraded"
        assert result["materialized"] is True
        details = _degraded_event_details(env)
        assert details is not None
        assert "MISSING_RECEIVED_DATETIME" in details["reasons"]

    async def test_date_filters_are_not_applied_when_date_missing(self, env, monkeypatch):
        """El orden importa: si falta receivedDateTime, los filtros
        GO_LIVE_AT/STOP_NEW_INBOUND_AT (que necesitan esa fecha) no deben
        ni evaluarse - el mensaje se resuelve como incomplete/degradado
        antes de llegar a esa comparación."""
        far_future = Mock(return_value=__import__("datetime").datetime(2099, 1, 1))
        monkeypatch.setattr(type(sync_service.settings), "go_live_dt", lambda self: far_future.return_value)

        msg = _base_msg()
        del msg["receivedDateTime"]
        msg.pop("createdDateTime", None)

        result = await _run(env, msg=msg, attempts=0, max_attempts=8)

        # Si el filtro GO_LIVE_AT se hubiera aplicado con NOW() como
        # received_at, probablemente habría dado before_go_live. Debe dar
        # incomplete, confirmando que el chequeo de fecha ausente ocurre
        # primero.
        assert result["status"] == "incomplete:MISSING_RECEIVED_DATETIME"


# ---------------------------------------------------------------------------
# Body no listo
# ---------------------------------------------------------------------------

class TestBodyNotReady:
    async def test_retries_remaining_returns_incomplete_after_checking_existence(
        self, env
    ):
        msg = _base_msg()
        del msg["body"]

        result = await _run(env, msg=msg, attempts=0, max_attempts=8)

        assert result["status"] == "incomplete:BODY_NOT_READY"
        # A diferencia de MISSING_RECEIVED_DATETIME, el chequeo de
        # existencia en DB SÍ ocurre antes de evaluar body/adjuntos (el
        # gate de body solo corre si hace falta crear un caso nuevo).
        env.get_existing_mock.assert_called_once()
        env.create_case_mock.assert_not_called()

    async def test_budget_exhausted_inserts_null_body_not_empty_string(self, env):
        msg = _base_msg()
        del msg["body"]

        result = await _run(env, msg=msg, attempts=7, max_attempts=8)

        assert result["status"] == "created_degraded"
        kwargs = env.insert_message_mock.call_args.kwargs
        assert kwargs["body_text"] is None
        assert kwargs["body_html"] is None


# ---------------------------------------------------------------------------
# Manifiesto de adjuntos no listo
# ---------------------------------------------------------------------------

class TestAttachmentManifestNotReady:
    async def test_retries_remaining_returns_incomplete(self, env):
        env.list_attachments_mock.return_value = []

        result = await _run(env, msg=_base_msg(hasAttachments=True), attempts=0, max_attempts=8)

        assert result["status"] == "incomplete:ATTACHMENT_MANIFEST_NOT_READY"
        env.create_case_mock.assert_not_called()

    async def test_budget_exhausted_creates_case_but_skips_attachment_processing(
        self, env
    ):
        env.list_attachments_mock.return_value = []

        result = await _run(
            env, msg=_base_msg(hasAttachments=True), attempts=7, max_attempts=8
        )

        assert result["status"] == "created_degraded"
        assert result["materialized"] is True
        env.create_case_mock.assert_called_once()
        env.process_attachments_mock.assert_not_called()

        details = _degraded_event_details(env)
        assert details["attachments_pending"] is True


# ---------------------------------------------------------------------------
# Mensaje ya materializado - el gate no debe correr en absoluto
# ---------------------------------------------------------------------------

class TestAlreadyMaterializedSkipsGate:
    async def test_already_materialized_skips_gate_and_creation(self, env):
        env.get_existing_mock.return_value = (111, 222, 0)  # message_pk, case_id, has_att

        result = await _run(env, msg=_base_msg(hasAttachments=False))

        assert result["status"] == "already_materialized"
        assert result["materialized"] is True
        env.list_attachments_mock.assert_not_called()
        env.create_case_mock.assert_not_called()

    async def test_already_materialized_with_missing_attachments_resyncs_without_gate_manifest(
        self, env
    ):
        env.get_existing_mock.return_value = (111, 222, 1)
        env.list_attachments_mock.return_value = []  # no importa para este test

        result = await _run(env, msg=_base_msg(hasAttachments=True))

        assert result["materialized"] is True
        env.process_attachments_mock.assert_called_once()
        # El resync de un mensaje ya materializado no pasa por el gate,
        # así que no hay manifiesto pre-obtenido para reutilizar.
        assert env.process_attachments_mock.call_args.kwargs["attachments_manifest"] is None


# ---------------------------------------------------------------------------
# Filtros de fecha existentes (GO_LIVE_AT / STOP_NEW_INBOUND_AT) - deben
# seguir funcionando igual, y el gate no debe correr si el mensaje ni
# siquiera aplica al portal.
# ---------------------------------------------------------------------------

class TestExistingDateFiltersUnaffected:
    async def test_before_go_live_skips_gate(self, env, monkeypatch):
        import datetime

        monkeypatch.setattr(
            type(sync_service.settings),
            "go_live_dt",
            lambda self: datetime.datetime(2099, 1, 1),
        )

        result = await _run(env, msg=_base_msg())

        assert result["status"] == "before_go_live"
        assert result["materialized"] is False
        env.get_existing_mock.assert_not_called()
        env.list_attachments_mock.assert_not_called()

    async def test_after_operational_cutoff_skips_gate(self, env, monkeypatch):
        import datetime

        monkeypatch.setattr(
            type(sync_service.settings),
            "stop_new_inbound_dt",
            lambda self: datetime.datetime(2020, 1, 1),
        )

        result = await _run(env, msg=_base_msg())

        assert result["status"] == "after_operational_cutoff"
        assert result["materialized"] is False
        env.get_existing_mock.assert_not_called()
