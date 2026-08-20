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
import app.attachment_recovery_repo as attachment_recovery_repo

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

    process_attachments_mock = AsyncMock(
        return_value={"attempted": 0, "succeeded": 0, "failed": 0, "failures": []}
    )
    monkeypatch.setattr(sync_service, "_process_attachments", process_attachments_mock)

    # D2: puntos de integración con attachment_recovery_repo (import
    # perezoso dentro de _process_single_message - se mockea el módulo
    # real, ya que el import local resuelve al mismo objeto en sys.modules).
    upsert_pending_mock = Mock()
    release_foreground_lock_mock = Mock()
    get_persisted_graph_ids_mock = Mock(return_value=set())
    monkeypatch.setattr(attachment_recovery_repo, "upsert_pending", upsert_pending_mock)
    monkeypatch.setattr(
        attachment_recovery_repo, "release_foreground_lock", release_foreground_lock_mock
    )
    monkeypatch.setattr(
        attachment_recovery_repo, "get_persisted_graph_ids", get_persisted_graph_ids_mock
    )

    return SimpleNamespace(
        fake_db=fake_db,
        get_existing_mock=get_existing_mock,
        create_case_mock=create_case_mock,
        insert_message_mock=insert_message_mock,
        insert_event_mock=insert_event_mock,
        get_message_mock=get_message_mock,
        list_attachments_mock=list_attachments_mock,
        process_attachments_mock=process_attachments_mock,
        upsert_pending_mock=upsert_pending_mock,
        release_foreground_lock_mock=release_foreground_lock_mock,
        get_persisted_graph_ids_mock=get_persisted_graph_ids_mock,
    )


async def _run(
    env, *, msg=None, attempts=0, max_attempts=8, attachments_stability_snapshot=None
):
    if msg is not None:
        env.get_message_mock.return_value = msg
    return await sync_service._process_single_message(
        mailbox_id=1,
        message_id="graph-msg-1",
        attempts=attempts,
        max_attempts=max_attempts,
        attachments_stability_snapshot=attachments_stability_snapshot,
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

        assert result["status"] == sync_service.STATUS_MISSING_RECEIVED_DATETIME
        assert result["materialized"] is False
        env.get_existing_mock.assert_not_called()
        env.create_case_mock.assert_not_called()
        env.list_attachments_mock.assert_not_called()

    @pytest.mark.parametrize("attempts,max_attempts", [(0, 8), (7, 8), (49, 50), (999, 1000)])
    async def test_never_degrades_regardless_of_attempts(self, env, attempts, max_attempts):
        """MISSING_RECEIVED_DATETIME es distinto en naturaleza a
        BODY_NOT_READY/ATTACHMENT_MANIFEST_NOT_READY: nunca debe
        materializar ni inventar un received_at, sin importar cuántos
        intentos lleve (el límite de reintentos para este motivo lo
        maneja inbound_queue_worker/mark_retry_unbounded, no
        sync_service). No se verifica datetime.now() con un spy global
        (no hace falta): la prueba concluyente es que nada se persiste."""
        msg = _base_msg()
        del msg["receivedDateTime"]
        msg.pop("createdDateTime", None)

        result = await _run(env, msg=msg, attempts=attempts, max_attempts=max_attempts)

        assert result["status"] == sync_service.STATUS_MISSING_RECEIVED_DATETIME
        assert result["materialized"] is False
        assert result["case_id"] is None
        assert result["message_pk"] is None
        env.create_case_mock.assert_not_called()
        env.insert_message_mock.assert_not_called()
        # Ningún evento CASE_CREATED_DEGRADED - no hubo degradación.
        assert _degraded_event_details(env) is None

    async def test_date_filters_are_not_applied_when_date_missing(self, env, monkeypatch):
        """El orden importa: si falta receivedDateTime, los filtros
        GO_LIVE_AT/STOP_NEW_INBOUND_AT (que necesitan esa fecha) no deben
        ni evaluarse - el mensaje se resuelve como incomplete antes de
        llegar a esa comparación."""
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


# ---------------------------------------------------------------------------
# Fase C: hasAttachments=false transitorio - flujo end-to-end
# ---------------------------------------------------------------------------

class TestAttachmentsFlagStabilization:
    # receivedDateTime va en UTC (como lo entrega Graph); _iso_to_dt lo
    # convierte a hora de Bogotá naive (UTC-5) antes de compararlo con
    # "ahora" - por eso 16:50Z equivale a 11:50 Bogotá, y el "ahora"
    # congelado (NOW_FOR_TEST) se define directamente en esa misma
    # familia naive para que la resta de 10 minutos sea consistente con
    # _evaluate_attachments_flag_stability (que también compara en hora
    # de Bogotá naive - ver sync_service.py).
    RECENT_RECEIVED_AT_ISO = "2026-08-19T16:50:00Z"  # = 11:50 Bogotá
    RECENT_LAST_MODIFIED = "2026-08-19T16:50:00Z"
    # Instante ABSOLUTO real (no un naive "pegado" a cualquier tz que se
    # pida): 17:00 UTC == 12:00 Bogotá (UTC-5). Esto es necesario para
    # que el mock detecte de verdad un bug de zona horaria - un mock que
    # ignora el argumento `tz` y devuelve el mismo naive sin importar
    # qué zona se pidió no puede distinguir "código usa UTC" de "código
    # usa Bogotá" (confirmado con una prueba de mutación real: la
    # primera versión de este mock no detectaba el bug).
    _ABSOLUTE_INSTANT = __import__("datetime").datetime(
        2026, 8, 19, 17, 0, tzinfo=__import__("datetime").timezone.utc
    )

    def _freeze_now(self, monkeypatch):
        import datetime as dt_module

        absolute_instant = self._ABSOLUTE_INSTANT

        class _FrozenDatetime(dt_module.datetime):
            @classmethod
            def now(cls, tz=None):
                if tz is None:
                    return absolute_instant.replace(tzinfo=None)
                return absolute_instant.astimezone(tz)

        monkeypatch.setattr(sync_service, "datetime", _FrozenDatetime)
        monkeypatch.setattr(
            sync_service.settings, "ATTACHMENTS_STABILIZATION_WINDOW_MINUTES", 15
        )

    def _recent_msg(self, **overrides):
        overrides.setdefault("lastModifiedDateTime", self.RECENT_LAST_MODIFIED)
        return _base_msg(
            receivedDateTime=self.RECENT_RECEIVED_AT_ISO,
            hasAttachments=False,
            **overrides,
        )

    async def test_recent_first_read_returns_incomplete_with_snapshot(
        self, env, monkeypatch
    ):
        self._freeze_now(monkeypatch)

        result = await _run(env, msg=self._recent_msg(), attempts=0, max_attempts=8)

        assert (
            result["status"]
            == f"incomplete:{sync_service.REASON_ATTACHMENTS_FLAG_UNSTABLE}"
        )
        assert result["materialized"] is False
        assert result["attachments_stability_snapshot"] == {
            "last_modified": self.RECENT_LAST_MODIFIED,
            "has_attachments": False,
        }
        env.create_case_mock.assert_not_called()
        env.list_attachments_mock.assert_not_called()

    async def test_recent_second_read_lmd_changed_returns_incomplete_again(
        self, env, monkeypatch
    ):
        self._freeze_now(monkeypatch)
        previous = {"last_modified": "2026-08-19T11:40:00Z", "has_attachments": False}

        result = await _run(
            env,
            msg=self._recent_msg(),  # lastModifiedDateTime distinto al previo
            attempts=1,
            max_attempts=8,
            attachments_stability_snapshot=previous,
        )

        assert (
            result["status"]
            == f"incomplete:{sync_service.REASON_ATTACHMENTS_FLAG_UNSTABLE}"
        )
        assert result["attachments_stability_snapshot"]["last_modified"] == (
            self.RECENT_LAST_MODIFIED
        )
        env.create_case_mock.assert_not_called()

    async def test_recent_lmd_stable_empty_manifest_creates_case_without_attachments(
        self, env, monkeypatch
    ):
        self._freeze_now(monkeypatch)
        previous = {
            "last_modified": self.RECENT_LAST_MODIFIED,
            "has_attachments": False,
        }
        env.list_attachments_mock.return_value = []

        result = await _run(
            env,
            msg=self._recent_msg(),
            attempts=2,
            max_attempts=8,
            attachments_stability_snapshot=previous,
        )

        assert result["status"] == "created"
        assert result["materialized"] is True
        env.process_attachments_mock.assert_not_called()
        kwargs = env.insert_message_mock.call_args.kwargs
        assert kwargs["has_attachments"] == 0

    async def test_recent_lmd_stable_manifest_has_attachment_uses_real_manifest(
        self, env, monkeypatch
    ):
        self._freeze_now(monkeypatch)
        previous = {
            "last_modified": self.RECENT_LAST_MODIFIED,
            "has_attachments": False,
        }
        manifest = [{"id": "att-1", "@odata.type": "#microsoft.graph.fileAttachment"}]
        env.list_attachments_mock.return_value = manifest

        result = await _run(
            env,
            msg=self._recent_msg(),
            attempts=2,
            max_attempts=8,
            attachments_stability_snapshot=previous,
        )

        assert result["status"] == "created"
        env.process_attachments_mock.assert_called_once()
        assert (
            env.process_attachments_mock.call_args.kwargs["attachments_manifest"]
            is manifest
        )
        # has_attachments persistido refleja la realidad (manifest real),
        # no el flag potencialmente desactualizado de Graph.
        kwargs = env.insert_message_mock.call_args.kwargs
        assert kwargs["has_attachments"] == 1

    async def test_budget_exhausted_empty_final_check_degrades_without_attachments(
        self, env, monkeypatch
    ):
        self._freeze_now(monkeypatch)
        env.list_attachments_mock.return_value = []  # última verificación: vacío

        result = await _run(
            env,
            msg=self._recent_msg(),
            attempts=7,
            max_attempts=8,
            attachments_stability_snapshot=None,  # sigue siendo la 1ra lectura util
        )

        assert result["status"] == "created_degraded"
        assert result["materialized"] is True
        env.process_attachments_mock.assert_not_called()
        env.list_attachments_mock.assert_called_once()  # la verificación final sí ocurrió

    async def test_budget_exhausted_manifest_found_does_not_degrade_as_empty(
        self, env, monkeypatch
    ):
        """El ajuste central pedido: al agotar presupuesto, NO se degrada
        confiando ciegamente en hasAttachments=false - se verifica una
        última vez, y si hay adjuntos reales, se usan."""
        self._freeze_now(monkeypatch)
        manifest = [{"id": "att-1", "@odata.type": "#microsoft.graph.fileAttachment"}]
        env.list_attachments_mock.return_value = manifest

        result = await _run(
            env,
            msg=self._recent_msg(),
            attempts=7,
            max_attempts=8,
            attachments_stability_snapshot=None,
        )

        assert result["status"] == "created_degraded"
        assert result["materialized"] is True
        env.process_attachments_mock.assert_called_once()
        assert (
            env.process_attachments_mock.call_args.kwargs["attachments_manifest"]
            is manifest
        )

    async def test_outside_stabilization_window_trusts_false_immediately(
        self, env, monkeypatch
    ):
        self._freeze_now(monkeypatch)
        old_msg = _base_msg(
            receivedDateTime="2026-08-19T10:00:00Z",  # 2h atrás, fuera de ventana
            hasAttachments=False,
        )

        result = await _run(env, msg=old_msg, attempts=0, max_attempts=8)

        assert result["status"] == "created"
        assert result["materialized"] is True
        env.list_attachments_mock.assert_not_called()


# ---------------------------------------------------------------------------
# D2: integración crash-safe con attachment_recovery. La fila de recovery
# debe crearse y quedar bloqueada por el foreground ANTES de tocar
# cualquier attachment - si el worker muere a mitad de _process_
# attachments, la fila ya existe y su lock eventualmente queda stale
# para que el background la reclame (nunca invisible).
# ---------------------------------------------------------------------------

class TestAttachmentRecoveryForegroundIntegration:
    def _manifest(self, *ids):
        return [
            {"@odata.type": "#microsoft.graph.fileAttachment", "id": i} for i in ids
        ]

    async def test_upsert_pending_called_before_process_attachments_with_lock(
        self, env
    ):
        """Crash-safety: upsert_pending(locked=True) debe ocurrir ANTES
        de _process_attachments, no después - si no, un crash a mitad de
        la descarga dejaría el mensaje sin fila de recovery (el bug
        original que motivó D2)."""
        call_order: list[str] = []
        env.upsert_pending_mock.side_effect = lambda *a, **k: call_order.append("upsert_pending")
        env.process_attachments_mock.side_effect = (
            lambda *a, **k: call_order.append("process_attachments")
            or {"attempted": 1, "succeeded": 1, "failed": 0, "failures": []}
        )

        manifest = self._manifest("A")
        env.list_attachments_mock.return_value = manifest
        env.get_persisted_graph_ids_mock.return_value = {"A"}

        await _run(env, msg=_base_msg(hasAttachments=True))

        assert call_order == ["upsert_pending", "process_attachments"]
        kwargs = env.upsert_pending_mock.call_args.kwargs
        assert kwargs["reason"] == "MANIFEST_DETECTED"
        assert kwargs["locked"] is True

    async def test_full_success_releases_lock_as_verifying(self, env):
        manifest = self._manifest("A", "B")
        env.list_attachments_mock.return_value = manifest
        env.get_persisted_graph_ids_mock.return_value = {"A", "B"}

        await _run(env, msg=_base_msg(hasAttachments=True))

        env.release_foreground_lock_mock.assert_called_once()
        kwargs = env.release_foreground_lock_mock.call_args.kwargs
        assert kwargs["status"] == "verifying"

    async def test_partial_success_releases_lock_as_pending(self, env):
        manifest = self._manifest("A", "B")
        env.list_attachments_mock.return_value = manifest
        env.get_persisted_graph_ids_mock.return_value = {"A"}  # solo 1 de 2

        await _run(env, msg=_base_msg(hasAttachments=True))

        kwargs = env.release_foreground_lock_mock.call_args.kwargs
        assert kwargs["status"] == "pending"

    async def test_degraded_manifest_not_ready_creates_unlocked_pending_row(self, env):
        """Camino degradado (gate nunca confirmó el manifiesto): la fila
        de recovery se crea SIN lock (locked=False) - no hubo
        procesamiento foreground activo, el background puede tomarla de
        inmediato."""
        env.list_attachments_mock.return_value = []  # nunca se estabiliza

        result = await _run(
            env, msg=_base_msg(hasAttachments=True), attempts=7, max_attempts=8
        )

        assert result["status"] == "created_degraded"
        kwargs = env.upsert_pending_mock.call_args.kwargs
        assert kwargs["reason"] == "ATTACHMENT_MANIFEST_NOT_READY"
        assert kwargs["locked"] is False

    async def test_no_attachments_never_touches_recovery_table(self, env):
        result = await _run(env, msg=_base_msg(hasAttachments=False))

        assert result["status"] == "created"
        env.upsert_pending_mock.assert_not_called()
        env.release_foreground_lock_mock.assert_not_called()
