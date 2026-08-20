from __future__ import annotations

"""
Tests unitarios para app.inbound_queue_worker._process_one.

Cobertura basada en tabla de decision (SWEBOK v4, Software Testing KA -
tecnicas de caja negra basadas en especificacion) sobre las tres variables
que determinan el desenlace de un evento de la cola:

    ok            resultado de sync_service.process_message_id_async()
    materialized  idem
    status        idem, solo relevante cuando materialized=False

+-----+--------------+---------------------------+------------------+
| ok  | materialized | status                    | accion esperada  |
+-----+--------------+---------------------------+------------------+
| *   | True         | *                         | mark_done        |
| True| False        | en _TERMINAL_BY_DESIGN... | mark_done        |
| True| False        | fuera de esa lista        | mark_retry       |
| False| False       | *                         | mark_retry       |
+-----+--------------+---------------------------+------------------+

mas: excepcion no controlada del lado de sync_service -> mark_retry
(nunca debe propagarse fuera de _process_one, porque asyncio.gather en
_run_loop no tiene manejo de excepcion por tarea).
"""

from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo
from unittest.mock import AsyncMock, Mock

import pytest

import app.inbound_queue_worker as worker
import app.sync_service as sync_service


pytestmark = pytest.mark.unit


def _patch_collaborators(monkeypatch, fake_db_session, *, result=None, side_effect=None):
    """Sustituye las tres dependencias externas de _process_one por dobles
    de prueba y devuelve los mocks para poder hacer asserts sobre ellos.
    """
    _, get_db_session_cm = fake_db_session

    process_mock = AsyncMock()
    if side_effect is not None:
        process_mock.side_effect = side_effect
    else:
        process_mock.return_value = result or {}

    mark_done_mock = Mock()
    mark_retry_mock = Mock()

    monkeypatch.setattr(worker.sync_service, "process_message_id_async", process_mock)
    monkeypatch.setattr(worker.inbound_queue_repo, "mark_done", mark_done_mock)
    monkeypatch.setattr(worker.inbound_queue_repo, "mark_retry", mark_retry_mock)
    monkeypatch.setattr(worker, "get_db_session", get_db_session_cm)

    return process_mock, mark_done_mock, mark_retry_mock


async def _run(item, *, max_attempts=8):
    sem = worker.asyncio.Semaphore(1)
    await worker._process_one(item, semaphore=sem, max_attempts=max_attempts)


# ---------------------------------------------------------------------------
# materialized=True -> siempre mark_done, sin importar ok/status
# ---------------------------------------------------------------------------

class TestMaterializedTrue:
    async def test_materialized_true_marks_done(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        process_mock, mark_done_mock, mark_retry_mock = _patch_collaborators(
            monkeypatch,
            fake_db_session,
            result={"ok": True, "materialized": True, "status": "created"},
        )
        item = make_queue_item(event_id=42)

        await _run(item)

        mark_done_mock.assert_called_once()
        assert mark_done_mock.call_args.kwargs["event_id"] == 42
        mark_retry_mock.assert_not_called()

    async def test_materialized_true_with_ok_false_still_marks_done(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        """materialized=True es autoritativo: si por alguna razon ok viene
        False pero el mensaje SI quedo materializado, igual debe cerrarse
        como done. No es un caso esperado hoy en sync_service, pero
        _process_one no debe asumir que ok y materialized siempre viajan
        de acuerdo - solo mira materialized primero, por diseno."""
        process_mock, mark_done_mock, mark_retry_mock = _patch_collaborators(
            monkeypatch,
            fake_db_session,
            result={"ok": False, "materialized": True, "status": "created"},
        )
        item = make_queue_item()

        await _run(item)

        mark_done_mock.assert_called_once()
        mark_retry_mock.assert_not_called()


# ---------------------------------------------------------------------------
# materialized=False, ok=True, status en _TERMINAL_BY_DESIGN_STATUSES
# -> mark_done (regression test del fix: antes esto consumia reintentos)
# ---------------------------------------------------------------------------

class TestDiscardedByDesign:
    @pytest.mark.parametrize("status", sorted(worker._TERMINAL_BY_DESIGN_STATUSES))
    async def test_terminal_status_marks_done_not_retry(
        self, monkeypatch, fake_db_session, make_queue_item, status
    ):
        process_mock, mark_done_mock, mark_retry_mock = _patch_collaborators(
            monkeypatch,
            fake_db_session,
            result={"ok": True, "materialized": False, "status": status},
        )
        item = make_queue_item(event_id=7)

        await _run(item)

        mark_done_mock.assert_called_once()
        assert mark_done_mock.call_args.kwargs["event_id"] == 7
        mark_retry_mock.assert_not_called()

    async def test_terminal_status_requires_ok_true(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        """Si status coincide con un valor terminal pero ok=False, no debe
        tratarse como descarte intencional - eso significaria que
        sync_service fallo de verdad con ese texto de status por
        coincidencia, y hay que reintentar, no descartar silenciosamente."""
        process_mock, mark_done_mock, mark_retry_mock = _patch_collaborators(
            monkeypatch,
            fake_db_session,
            result={
                "ok": False,
                "materialized": False,
                "status": "after_operational_cutoff",
            },
        )
        item = make_queue_item()

        await _run(item)

        mark_done_mock.assert_not_called()
        mark_retry_mock.assert_called_once()


# ---------------------------------------------------------------------------
# materialized=False y status NO terminal -> mark_retry (camino normal)
# ---------------------------------------------------------------------------

class TestNormalRetry:
    async def test_incomplete_status_marks_retry_with_correct_error(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        _patch_collaborators(
            monkeypatch,
            fake_db_session,
            result={"ok": True, "materialized": False, "status": "not_materialized"},
        )
        item = make_queue_item(event_id=99, attempts=3)

        await _run(item, max_attempts=8)

        # Se re-obtiene el mock via monkeypatch: como _patch_collaborators
        # ya hizo el setattr, basta con inspeccionar worker.inbound_queue_repo
        mark_retry_mock = worker.inbound_queue_repo.mark_retry
        mark_retry_mock.assert_called_once()
        kwargs = mark_retry_mock.call_args.kwargs
        assert kwargs["event_id"] == 99
        assert kwargs["attempts"] == 3
        assert kwargs["max_attempts"] == 8
        assert kwargs["error"] == "not_materialized:not_materialized"

    async def test_ok_false_marks_retry(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        _patch_collaborators(
            monkeypatch,
            fake_db_session,
            result={"ok": False, "materialized": False, "status": "exception"},
        )
        item = make_queue_item()

        await _run(item)

        worker.inbound_queue_repo.mark_retry.assert_called_once()
        worker.inbound_queue_repo.mark_done.assert_not_called()

    async def test_missing_status_key_defaults_to_unknown(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        """Robustez: si sync_service devolviera un dict sin 'status' (no
        deberia pasar hoy, pero el contrato entre modulos no esta forzado
        por un tipo), _process_one no debe lanzar KeyError."""
        _patch_collaborators(
            monkeypatch,
            fake_db_session,
            result={"ok": True, "materialized": False},
        )
        item = make_queue_item()

        await _run(item)

        kwargs = worker.inbound_queue_repo.mark_retry.call_args.kwargs
        assert kwargs["error"] == "not_materialized:unknown"


# ---------------------------------------------------------------------------
# MISSING_RECEIVED_DATETIME: SIEMPRE usa mark_retry_unbounded, nunca
# mark_retry - la fila no debe poder marcarse 'failed' por este motivo,
# sin importar cuántos intentos lleve (Fase B).
# ---------------------------------------------------------------------------

class TestMissingReceivedDateTimeUnboundedRetry:
    def _patch(self, monkeypatch, fake_db_session, *, result):
        _, get_db_session_cm = fake_db_session
        process_mock = AsyncMock(return_value=result)
        mark_done_mock = Mock()
        mark_retry_mock = Mock()
        mark_retry_unbounded_mock = Mock()

        monkeypatch.setattr(worker.sync_service, "process_message_id_async", process_mock)
        monkeypatch.setattr(worker.inbound_queue_repo, "mark_done", mark_done_mock)
        monkeypatch.setattr(worker.inbound_queue_repo, "mark_retry", mark_retry_mock)
        monkeypatch.setattr(
            worker.inbound_queue_repo, "mark_retry_unbounded", mark_retry_unbounded_mock
        )
        monkeypatch.setattr(worker, "get_db_session", get_db_session_cm)

        return mark_done_mock, mark_retry_mock, mark_retry_unbounded_mock

    def _missing_received_datetime_result(self):
        return {
            "ok": True,
            "materialized": False,
            "status": sync_service.STATUS_MISSING_RECEIVED_DATETIME,
        }

    async def test_uses_mark_retry_unbounded_not_mark_retry(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        mark_done_mock, mark_retry_mock, mark_retry_unbounded_mock = self._patch(
            monkeypatch, fake_db_session, result=self._missing_received_datetime_result()
        )
        item = make_queue_item(event_id=1, attempts=0)

        await _run(item, max_attempts=8)

        mark_retry_unbounded_mock.assert_called_once()
        mark_retry_mock.assert_not_called()
        mark_done_mock.assert_not_called()

    @pytest.mark.parametrize("attempts", [7, 50, 1000])
    async def test_never_uses_bounded_retry_even_past_max_attempts(
        self, monkeypatch, fake_db_session, make_queue_item, attempts
    ):
        """Con mark_retry normal, attempts=7 y max_attempts=8 marcaría la
        fila 'failed'. Para este motivo NUNCA debe llamarse mark_retry en
        absoluto, sin importar que attempts supere max_attempts - la fila
        siempre permanece recuperable."""
        _, mark_retry_mock, mark_retry_unbounded_mock = self._patch(
            monkeypatch, fake_db_session, result=self._missing_received_datetime_result()
        )
        item = make_queue_item(attempts=attempts)

        await _run(item, max_attempts=8)

        mark_retry_mock.assert_not_called()
        mark_retry_unbounded_mock.assert_called_once()
        assert mark_retry_unbounded_mock.call_args.kwargs["attempts"] == attempts

    async def test_recent_event_does_not_log_alert(
        self, monkeypatch, fake_db_session, make_queue_item, caplog
    ):
        monkeypatch.setattr(worker.settings, "MISSING_RECEIVED_DATETIME_ALERT_AGE_MINUTES", 60)
        self._patch(
            monkeypatch, fake_db_session, result=self._missing_received_datetime_result()
        )
        recent = datetime.now(ZoneInfo("America/Bogota")).replace(tzinfo=None) - timedelta(minutes=5)
        item = make_queue_item(created_at=recent)

        with caplog.at_level("ERROR"):
            await _run(item, max_attempts=8)

        assert "ALERT_STALLED_MISSING_RECEIVED_DATETIME" not in caplog.text

    async def test_old_event_logs_alert(
        self, monkeypatch, fake_db_session, make_queue_item, caplog
    ):
        monkeypatch.setattr(worker.settings, "MISSING_RECEIVED_DATETIME_ALERT_AGE_MINUTES", 60)
        self._patch(
            monkeypatch, fake_db_session, result=self._missing_received_datetime_result()
        )
        old = datetime.now(ZoneInfo("America/Bogota")).replace(tzinfo=None) - timedelta(minutes=120)
        item = make_queue_item(created_at=old)

        with caplog.at_level("ERROR"):
            await _run(item, max_attempts=8)

        assert "ALERT_STALLED_MISSING_RECEIVED_DATETIME" in caplog.text

    async def test_long_tail_delay_used_once_age_exceeds_threshold(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        monkeypatch.setattr(worker.settings, "MISSING_RECEIVED_DATETIME_ALERT_AGE_MINUTES", 60)
        monkeypatch.setattr(worker.settings, "MISSING_RECEIVED_DATETIME_LONG_RETRY_SECONDS", 21600)
        _, _, mark_retry_unbounded_mock = self._patch(
            monkeypatch, fake_db_session, result=self._missing_received_datetime_result()
        )
        old = datetime.now(ZoneInfo("America/Bogota")).replace(tzinfo=None) - timedelta(minutes=120)
        item = make_queue_item(created_at=old)

        await _run(item, max_attempts=8)

        kwargs = mark_retry_unbounded_mock.call_args.kwargs
        assert kwargs["queue_event_age_seconds"] > kwargs["alert_age_seconds"]
        assert kwargs["long_retry_seconds"] == 21600

    async def test_normal_ladder_used_before_threshold(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        monkeypatch.setattr(worker.settings, "MISSING_RECEIVED_DATETIME_ALERT_AGE_MINUTES", 60)
        _, _, mark_retry_unbounded_mock = self._patch(
            monkeypatch, fake_db_session, result=self._missing_received_datetime_result()
        )
        recent = datetime.now(ZoneInfo("America/Bogota")).replace(tzinfo=None) - timedelta(minutes=5)
        item = make_queue_item(created_at=recent)

        await _run(item, max_attempts=8)

        kwargs = mark_retry_unbounded_mock.call_args.kwargs
        assert kwargs["queue_event_age_seconds"] <= kwargs["alert_age_seconds"]

    async def test_missing_created_at_treated_as_age_zero(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        """Robustez: si por algún motivo la fila no trae created_at (no
        debería pasar, la columna es NOT NULL), no debe reventar - se
        trata como edad 0 (sin alerta, ladder normal)."""
        _, _, mark_retry_unbounded_mock = self._patch(
            monkeypatch, fake_db_session, result=self._missing_received_datetime_result()
        )
        item = make_queue_item(created_at=None)

        await _run(item, max_attempts=8)

        kwargs = mark_retry_unbounded_mock.call_args.kwargs
        assert kwargs["queue_event_age_seconds"] == 0


class TestExceptionHandling:
    async def test_exception_is_caught_and_marks_retry(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        _patch_collaborators(
            monkeypatch,
            fake_db_session,
            side_effect=RuntimeError("Graph timeout"),
        )
        item = make_queue_item(event_id=5, attempts=1)

        # No debe lanzar - esta es la aserción principal del test
        await _run(item, max_attempts=8)

        kwargs = worker.inbound_queue_repo.mark_retry.call_args.kwargs
        assert kwargs["event_id"] == 5
        assert kwargs["attempts"] == 1
        assert kwargs["error"] == "Graph timeout"
        worker.inbound_queue_repo.mark_done.assert_not_called()

    async def test_exception_does_not_prevent_other_tasks_via_gather(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        """Simula el uso real dentro de _run_loop: dos items en el mismo
        asyncio.gather, uno de los cuales falla. El fallo de uno no debe
        cancelar ni afectar el resultado del otro."""
        _, get_db_session_cm = fake_db_session
        monkeypatch.setattr(worker, "get_db_session", get_db_session_cm)
        monkeypatch.setattr(worker.inbound_queue_repo, "mark_done", Mock())
        monkeypatch.setattr(worker.inbound_queue_repo, "mark_retry", Mock())

        async def fake_process(message_id, source, **_kwargs):
            if message_id == "boom":
                raise RuntimeError("falla solo este")
            return {"ok": True, "materialized": True, "status": "created"}

        monkeypatch.setattr(
            worker.sync_service, "process_message_id_async", fake_process
        )

        sem = worker.asyncio.Semaphore(2)
        item_ok = make_queue_item(event_id=1, provider_message_id="ok-1")
        item_fail = make_queue_item(event_id=2, provider_message_id="boom")

        await worker.asyncio.gather(
            worker._process_one(item_ok, semaphore=sem, max_attempts=8),
            worker._process_one(item_fail, semaphore=sem, max_attempts=8),
        )

        worker.inbound_queue_repo.mark_done.assert_called_once()
        assert worker.inbound_queue_repo.mark_done.call_args.kwargs["event_id"] == 1
        worker.inbound_queue_repo.mark_retry.assert_called_once()
        assert worker.inbound_queue_repo.mark_retry.call_args.kwargs["event_id"] == 2


# ---------------------------------------------------------------------------
# Concurrencia: el semaforo debe limitar cuantos _process_one corren a la
# vez dentro de la seccion critica marcada por `async with semaphore`.
# ---------------------------------------------------------------------------

class TestConcurrencyLimit:
    async def test_semaphore_of_one_serializes_execution(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        _, get_db_session_cm = fake_db_session
        monkeypatch.setattr(worker, "get_db_session", get_db_session_cm)
        monkeypatch.setattr(worker.inbound_queue_repo, "mark_done", Mock())
        monkeypatch.setattr(worker.inbound_queue_repo, "mark_retry", Mock())

        active = 0
        max_observed = 0

        async def fake_process(message_id, source, **_kwargs):
            nonlocal active, max_observed
            active += 1
            max_observed = max(max_observed, active)
            await worker.asyncio.sleep(0.01)
            active -= 1
            return {"ok": True, "materialized": True, "status": "created"}

        monkeypatch.setattr(
            worker.sync_service, "process_message_id_async", fake_process
        )

        sem = worker.asyncio.Semaphore(1)
        items = [make_queue_item(event_id=i) for i in range(5)]

        await worker.asyncio.gather(
            *[worker._process_one(it, semaphore=sem, max_attempts=8) for it in items]
        )

        assert max_observed == 1, (
            "con Semaphore(1) nunca debieron correr dos process_message_id_async "
            "en simultaneo, pero se observaron hasta %d" % max_observed
        )

    async def test_semaphore_of_two_allows_up_to_two_concurrent(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        _, get_db_session_cm = fake_db_session
        monkeypatch.setattr(worker, "get_db_session", get_db_session_cm)
        monkeypatch.setattr(worker.inbound_queue_repo, "mark_done", Mock())
        monkeypatch.setattr(worker.inbound_queue_repo, "mark_retry", Mock())

        active = 0
        max_observed = 0

        async def fake_process(message_id, source, **_kwargs):
            nonlocal active, max_observed
            active += 1
            max_observed = max(max_observed, active)
            await worker.asyncio.sleep(0.01)
            active -= 1
            return {"ok": True, "materialized": True, "status": "created"}

        monkeypatch.setattr(
            worker.sync_service, "process_message_id_async", fake_process
        )

        sem = worker.asyncio.Semaphore(2)
        items = [make_queue_item(event_id=i) for i in range(6)]

        await worker.asyncio.gather(
            *[worker._process_one(it, semaphore=sem, max_attempts=8) for it in items]
        )

        assert max_observed == 2


# ---------------------------------------------------------------------------
# Ciclo de vida: start_inbound_queue_worker / stop_inbound_queue_worker
# ---------------------------------------------------------------------------

class TestWorkerLifecycle:
    @contextmanager
    def _no_db(self):
        yield Mock()

    async def test_start_then_stop_completes_cleanly(self, monkeypatch):
        monkeypatch.setattr(worker.settings, "INBOUND_QUEUE_ENABLED", 1)
        monkeypatch.setattr(worker.settings, "INBOUND_QUEUE_POLL_SECONDS", 0.01)
        monkeypatch.setattr(worker, "get_db_session", self._no_db)
        monkeypatch.setattr(
            worker.inbound_queue_repo, "claim_pending_events", Mock(return_value=[])
        )

        await worker.start_inbound_queue_worker()
        assert worker._task is not None
        assert not worker._task.done()

        await worker.asyncio.sleep(0.03)  # deja correr un par de ciclos de poll
        await worker.stop_inbound_queue_worker()

        assert worker._task is None
        assert worker._stop_event is None

    async def test_disabled_by_config_does_not_start_task(self, monkeypatch):
        monkeypatch.setattr(worker.settings, "INBOUND_QUEUE_ENABLED", 0)

        await worker.start_inbound_queue_worker()

        assert worker._task is None
        assert worker._stop_event is None

    async def test_starting_twice_does_not_create_a_second_task(self, monkeypatch):
        monkeypatch.setattr(worker.settings, "INBOUND_QUEUE_ENABLED", 1)
        monkeypatch.setattr(worker.settings, "INBOUND_QUEUE_POLL_SECONDS", 0.01)
        monkeypatch.setattr(worker, "get_db_session", self._no_db)
        monkeypatch.setattr(
            worker.inbound_queue_repo, "claim_pending_events", Mock(return_value=[])
        )

        await worker.start_inbound_queue_worker()
        first_task = worker._task

        await worker.start_inbound_queue_worker()  # segunda llamada, debe ser no-op
        assert worker._task is first_task

        await worker.stop_inbound_queue_worker()

    async def test_run_loop_calls_process_one_for_each_claimed_item(
        self, monkeypatch, make_queue_item
    ):
        """Test de integracion liviano: ejercita _run_loop de verdad (no
        _process_one aislado) para confirmar que el cableado entre
        claim_pending_events y _process_one funciona de punta a punta,
        con sync_service y la DB mockeados."""
        monkeypatch.setattr(worker.settings, "INBOUND_QUEUE_ENABLED", 1)
        monkeypatch.setattr(worker.settings, "INBOUND_QUEUE_POLL_SECONDS", 0.01)
        monkeypatch.setattr(worker, "get_db_session", self._no_db)

        items = [make_queue_item(event_id=1), make_queue_item(event_id=2)]
        claim_mock = Mock(side_effect=[items, [], [], []])
        monkeypatch.setattr(worker.inbound_queue_repo, "claim_pending_events", claim_mock)

        process_mock = AsyncMock(
            return_value={"ok": True, "materialized": True, "status": "created"}
        )
        monkeypatch.setattr(worker.sync_service, "process_message_id_async", process_mock)
        mark_done_mock = Mock()
        monkeypatch.setattr(worker.inbound_queue_repo, "mark_done", mark_done_mock)

        await worker.start_inbound_queue_worker()
        await worker.asyncio.sleep(0.05)
        await worker.stop_inbound_queue_worker()

        assert process_mock.call_count == 2
        assert mark_done_mock.call_count == 2
# segun el driver de DB (ej. Decimal para columnas numericas). _process_one
# hace int()/str() explicito - se prueba con inputs ya-string para asegurar
# que no rompe con datos "demasiado" tipados.
# ---------------------------------------------------------------------------

class TestAttemptsPropagation:
    """
    Guarda de regresión: process_message_id_async necesita saber en qué
    intento va (attempts/max_attempts) para que el Completeness Gate
    pueda decidir si reintentar o degradar. Si _process_one dejara de
    pasar estos argumentos, el gate siempre vería attempts=0 y nunca
    degradaría un mensaje permanentemente incompleto.
    """

    async def test_attempts_and_max_attempts_are_forwarded(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        process_mock, mark_done_mock, mark_retry_mock = _patch_collaborators(
            monkeypatch,
            fake_db_session,
            result={"ok": True, "materialized": True, "status": "created"},
        )
        item = make_queue_item(attempts=5)

        await _run(item, max_attempts=8)

        process_mock.assert_called_once()
        kwargs = process_mock.call_args.kwargs
        assert kwargs["attempts"] == 5
        assert kwargs["max_attempts"] == 8


class TestFieldCoercion:
    async def test_string_typed_fields_are_coerced_correctly(
        self, monkeypatch, fake_db_session
    ):
        _patch_collaborators(
            monkeypatch,
            fake_db_session,
            result={"ok": True, "materialized": True, "status": "created"},
        )
        item = {
            "id": "123",
            "source": "delta",
            "provider_message_id": "AAMk-abc",
            "mailbox_email": "buzon@icbf.gov.co",
            "attempts": "4",
        }

        await _run(item)

        kwargs = worker.inbound_queue_repo.mark_done.call_args.kwargs
        assert kwargs["event_id"] == 123
        assert isinstance(kwargs["event_id"], int)
