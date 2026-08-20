from __future__ import annotations

"""
Auditoria de zona horaria pre-Fase D.

Contexto: se encontraron dos convenciones mezcladas en el codigo -
Bogota-naive (la mayoria: _iso_to_dt, stop_new_inbound_dt, la sesion de
DB del worker via SET time_zone) y UTC-naive (go_live_dt antes del fix,
_queue_event_age_seconds antes del fix). Comparar "UTC naive" contra
"Bogota naive" como si fueran el mismo reloj introduce un desfase
sistematico de 5 horas.

Estos tests fijan la convencion Bogota-naive como la unica valida para
comparar contra `received_at` (que siempre es Bogota-naive, via
_iso_to_dt) y contra `created_at` de la cola (Bogota-naive, via NOW(6)
con la sesion del worker en America/Bogota).
"""

from datetime import datetime, timezone
from zoneinfo import ZoneInfo

import pytest

import app.sync_service as sync_service
import app.inbound_queue_worker as worker

pytestmark = pytest.mark.unit


# ---------------------------------------------------------------------------
# A. Mismo instante UTC -> las tres funciones deben terminar en la misma
#    convencion Bogota-naive (no solo "parecerse", sino coincidir exacto)
# ---------------------------------------------------------------------------

class TestSameInstantConvergesOnBogotaNaive:
    def test_iso_to_dt_go_live_dt_and_stop_new_inbound_dt_agree_for_same_utc_instant(
        self, monkeypatch
    ):
        same_utc_instant = "2026-08-01T00:00:00Z"

        received_at_equivalent = sync_service._iso_to_dt(same_utc_instant)

        monkeypatch.setattr(sync_service.settings, "GO_LIVE_AT", same_utc_instant)
        go_live = sync_service.settings.go_live_dt()

        monkeypatch.setattr(
            sync_service.settings, "STOP_NEW_INBOUND_AT", same_utc_instant
        )
        stop_inbound = sync_service.settings.stop_new_inbound_dt()

        # Los tres deben representar EXACTAMENTE el mismo instante en la
        # misma convencion (Bogota naive) - no solo estar "cerca".
        assert received_at_equivalent == go_live == stop_inbound

    def test_utc_instant_converts_to_five_hours_earlier_in_bogota(self):
        """Caso concreto del ejemplo de la auditoria: 2026-08-01T00:00:00Z
        (UTC) debe convertirse a 2026-07-31 19:00 (Bogota, UTC-5)."""
        result = sync_service._iso_to_dt("2026-08-01T00:00:00Z")
        assert result == datetime(2026, 7, 31, 19, 0, 0)


# ---------------------------------------------------------------------------
# B. GO_LIVE_AT exactamente igual a receivedDateTime -> NO debe quedar
#    desplazado 5h (ese era el bug original)
# ---------------------------------------------------------------------------

class TestGoLiveAtNoLongerShifted:
    def test_go_live_equal_to_received_at_are_equal_not_shifted(self, monkeypatch):
        instant = "2026-08-01T12:00:00Z"
        monkeypatch.setattr(sync_service.settings, "GO_LIVE_AT", instant)

        received_at = sync_service._iso_to_dt(instant)
        go_live = sync_service.settings.go_live_dt()

        assert go_live == received_at
        # Explicitamente: NO debe haber 5 horas de diferencia (el bug
        # original hacia que go_live_dt() quedara 5h "adelante").
        assert (go_live - received_at).total_seconds() == 0


# ---------------------------------------------------------------------------
# C. receivedDateTime un segundo antes/despues de GO_LIVE_AT -> el filtro
#    before_go_live debe activarse/desactivarse en el segundo exacto, no
#    con un desfase de 5 horas.
# ---------------------------------------------------------------------------

class TestGoLiveAtBoundarySecond:
    def test_one_second_before_go_live_is_rejected(self, monkeypatch):
        go_live_iso = "2026-08-01T12:00:00Z"
        one_second_before = "2026-08-01T11:59:59Z"
        monkeypatch.setattr(sync_service.settings, "GO_LIVE_AT", go_live_iso)

        go_live = sync_service.settings.go_live_dt()
        received_at = sync_service._iso_to_dt(one_second_before)

        assert received_at < go_live

    def test_one_second_after_go_live_is_accepted(self, monkeypatch):
        go_live_iso = "2026-08-01T12:00:00Z"
        one_second_after = "2026-08-01T12:00:01Z"
        monkeypatch.setattr(sync_service.settings, "GO_LIVE_AT", go_live_iso)

        go_live = sync_service.settings.go_live_dt()
        received_at = sync_service._iso_to_dt(one_second_after)

        assert not (received_at < go_live)

    def test_exact_same_second_is_accepted(self, monkeypatch):
        """El filtro es `received_at < go_live` (estrictamente menor) -
        el mismo instante exacto no debe rechazarse."""
        instant = "2026-08-01T12:00:00Z"
        monkeypatch.setattr(sync_service.settings, "GO_LIVE_AT", instant)

        go_live = sync_service.settings.go_live_dt()
        received_at = sync_service._iso_to_dt(instant)

        assert not (received_at < go_live)


# ---------------------------------------------------------------------------
# D. _queue_event_age_seconds: created_at Bogota-naive hace 30 min debe
#    dar ~1800s, NUNCA ~19800s (que seria el resultado con el bug de
#    comparar contra UTC).
# ---------------------------------------------------------------------------

class TestQueueEventAgeSecondsNoTimezoneDrift:
    def test_thirty_minutes_ago_gives_approximately_1800_seconds(self):
        thirty_min_ago = datetime.now(ZoneInfo("America/Bogota")).replace(
            tzinfo=None
        ) - __import__("datetime").timedelta(minutes=30)

        age = worker._queue_event_age_seconds(thirty_min_ago)

        assert 1750 <= age <= 1850  # tolerancia de segundos por tiempo de ejecución
        # Explícitamente: NO debe dar ~19800s (1800 + 5h*3600 = 19800),
        # que es exactamente el síntoma del bug de comparar contra UTC.
        assert age < 19000

    def test_now_gives_approximately_zero(self):
        now_bogota = datetime.now(ZoneInfo("America/Bogota")).replace(tzinfo=None)
        age = worker._queue_event_age_seconds(now_bogota)
        assert 0 <= age <= 5


# ---------------------------------------------------------------------------
# E. Umbral de Fase B: 59 min -> ladder normal, 61 min -> long-tail, sin
#    adelanto artificial de 5h en ninguno de los dos casos.
# ---------------------------------------------------------------------------

class TestMissingReceivedDateTimeThresholdNoTimezoneDrift:
    async def test_59_minutes_old_uses_normal_ladder(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        from unittest.mock import AsyncMock, Mock

        _, get_db_session_cm = fake_db_session
        monkeypatch.setattr(worker, "get_db_session", get_db_session_cm)
        monkeypatch.setattr(
            worker.settings, "MISSING_RECEIVED_DATETIME_ALERT_AGE_MINUTES", 60
        )
        monkeypatch.setattr(
            worker.sync_service,
            "process_message_id_async",
            AsyncMock(
                return_value={
                    "ok": True,
                    "materialized": False,
                    "status": sync_service.STATUS_MISSING_RECEIVED_DATETIME,
                }
            ),
        )
        mark_retry_unbounded_mock = Mock()
        monkeypatch.setattr(
            worker.inbound_queue_repo, "mark_retry_unbounded", mark_retry_unbounded_mock
        )

        fifty_nine_min_ago = datetime.now(ZoneInfo("America/Bogota")).replace(
            tzinfo=None
        ) - __import__("datetime").timedelta(minutes=59)
        item = make_queue_item(created_at=fifty_nine_min_ago)

        sem = worker.asyncio.Semaphore(1)
        await worker._process_one(item, semaphore=sem, max_attempts=8)

        kwargs = mark_retry_unbounded_mock.call_args.kwargs
        assert kwargs["queue_event_age_seconds"] <= kwargs["alert_age_seconds"]

    async def test_61_minutes_old_uses_long_tail(
        self, monkeypatch, fake_db_session, make_queue_item
    ):
        from unittest.mock import AsyncMock, Mock

        _, get_db_session_cm = fake_db_session
        monkeypatch.setattr(worker, "get_db_session", get_db_session_cm)
        monkeypatch.setattr(
            worker.settings, "MISSING_RECEIVED_DATETIME_ALERT_AGE_MINUTES", 60
        )
        monkeypatch.setattr(
            worker.sync_service,
            "process_message_id_async",
            AsyncMock(
                return_value={
                    "ok": True,
                    "materialized": False,
                    "status": sync_service.STATUS_MISSING_RECEIVED_DATETIME,
                }
            ),
        )
        mark_retry_unbounded_mock = Mock()
        monkeypatch.setattr(
            worker.inbound_queue_repo, "mark_retry_unbounded", mark_retry_unbounded_mock
        )

        sixty_one_min_ago = datetime.now(ZoneInfo("America/Bogota")).replace(
            tzinfo=None
        ) - __import__("datetime").timedelta(minutes=61)
        item = make_queue_item(created_at=sixty_one_min_ago)

        sem = worker.asyncio.Semaphore(1)
        await worker._process_one(item, semaphore=sem, max_attempts=8)

        kwargs = mark_retry_unbounded_mock.call_args.kwargs
        assert kwargs["queue_event_age_seconds"] > kwargs["alert_age_seconds"]


# ---------------------------------------------------------------------------
# F. Fase C: la ventana de estabilizacion de hasAttachments=false (15 min)
#    sigue funcionando sin regresion tras el fix de timezone - usa
#    ZoneInfo("America/Bogota") desde su implementacion original en
#    Fase C, no se tocó en esta ronda, pero se re-verifica explícitamente.
# ---------------------------------------------------------------------------

class TestPhaseCStabilizationWindowNoRegression:
    async def test_recent_message_still_triggers_stabilization(self, monkeypatch):
        from unittest.mock import AsyncMock

        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", AsyncMock()
        )
        received_at_now_bogota = datetime.now(ZoneInfo("America/Bogota")).replace(
            tzinfo=None
        )

        result = await sync_service._evaluate_attachments_flag_stability(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=received_at_now_bogota,
            current_last_modified="lmd1",
            attachments_stability_snapshot=None,
            stabilization_window_minutes=15,
        )

        assert result.reasons == [sync_service.REASON_ATTACHMENTS_FLAG_UNSTABLE]

    async def test_old_message_still_trusted_without_extra_reads(self, monkeypatch):
        from unittest.mock import AsyncMock

        list_attachments_mock = AsyncMock()
        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", list_attachments_mock
        )
        received_at_old = datetime.now(ZoneInfo("America/Bogota")).replace(
            tzinfo=None
        ) - __import__("datetime").timedelta(minutes=60)

        result = await sync_service._evaluate_attachments_flag_stability(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=received_at_old,
            current_last_modified="lmd1",
            attachments_stability_snapshot=None,
            stabilization_window_minutes=15,
        )

        assert result.reasons == []
        list_attachments_mock.assert_not_called()
