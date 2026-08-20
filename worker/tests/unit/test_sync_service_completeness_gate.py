from __future__ import annotations

"""
Tests unitarios para las piezas puras del Completeness Gate en
app.sync_service: _evaluate_completeness (body + adjuntos) y
_incomplete_or_degrade (decide retry vs degradar).

receivedDateTime NO se evalúa en _evaluate_completeness a propósito (ver
docstring de la función en sync_service.py) - se cubre en
test_sync_service_process_single_message.py, que ejercita el flujo
completo y el orden de evaluación real.
"""

from unittest.mock import AsyncMock

import pytest

import app.sync_service as sync_service

pytestmark = pytest.mark.unit

# Fecha de referencia "vieja" (fuera de cualquier ventana de
# estabilizacion razonable) para los tests de body/adjuntos que no
# ejercitan especificamente la logica de estabilizacion de
# hasAttachments=false - asi el comportamiento de esos tests no depende
# de que tan rapido corra la suite.
from datetime import datetime as _datetime
_OLD_RECEIVED_AT = _datetime(2020, 1, 1)


# ---------------------------------------------------------------------------
# _evaluate_completeness — tabla de decisión sobre body
# ---------------------------------------------------------------------------

class TestEvaluateCompletenessBody:
    async def test_body_key_absent_is_not_ready(self, monkeypatch):
        msg = {"hasAttachments": False}
        result = await sync_service._evaluate_completeness(
            msg,
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=_OLD_RECEIVED_AT,
        )
        assert result.complete is False
        assert "BODY_NOT_READY" in result.reasons

    async def test_body_not_a_dict_is_not_ready(self, monkeypatch):
        msg = {"body": "esto no debería ser un string", "hasAttachments": False}
        result = await sync_service._evaluate_completeness(
            msg,
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=_OLD_RECEIVED_AT,
        )
        assert result.complete is False
        assert "BODY_NOT_READY" in result.reasons

    async def test_body_present_without_content_key_is_not_ready(self, monkeypatch):
        msg = {"body": {"contentType": "html"}, "hasAttachments": False}
        result = await sync_service._evaluate_completeness(
            msg,
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=_OLD_RECEIVED_AT,
        )
        assert result.complete is False
        assert "BODY_NOT_READY" in result.reasons

    async def test_body_content_explicitly_null_is_not_ready(self, monkeypatch):
        msg = {
            "body": {"contentType": "html", "content": None},
            "hasAttachments": False,
        }
        result = await sync_service._evaluate_completeness(
            msg,
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=_OLD_RECEIVED_AT,
        )
        assert result.complete is False
        assert "BODY_NOT_READY" in result.reasons

    async def test_body_content_empty_string_is_valid(self, monkeypatch):
        """Un correo puede legítimamente no tener texto (solo un adjunto).
        content='' explícito NO es lo mismo que ausencia de body - es un
        correo válido, no un motivo de espera."""
        msg = {
            "body": {"contentType": "html", "content": ""},
            "hasAttachments": False,
        }
        result = await sync_service._evaluate_completeness(
            msg,
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=_OLD_RECEIVED_AT,
        )
        assert result.complete is True
        assert result.reasons == []

    async def test_body_with_real_content_is_valid(self, monkeypatch):
        msg = {
            "body": {"contentType": "html", "content": "<p>Hola</p>"},
            "hasAttachments": False,
        }
        result = await sync_service._evaluate_completeness(
            msg,
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=_OLD_RECEIVED_AT,
        )
        assert result.complete is True


# ---------------------------------------------------------------------------
# _evaluate_completeness — tabla de decisión sobre adjuntos
# ---------------------------------------------------------------------------

class TestEvaluateCompletenessAttachments:
    def _valid_body_msg(self, **extra):
        msg = {"body": {"contentType": "html", "content": "hola"}}
        msg.update(extra)
        return msg

    async def test_has_attachments_false_outside_stabilization_window_skips_manifest_lookup(
        self, monkeypatch
    ):
        """Fase C: hasAttachments=false para un mensaje FUERA de la
        ventana de estabilización (received_at viejo) se acepta directo,
        sin ninguna llamada a Graph adicional - ver
        TestAttachmentsFlagStability para el comportamiento dentro de la
        ventana."""
        list_attachments_mock = AsyncMock()
        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", list_attachments_mock
        )
        msg = self._valid_body_msg(hasAttachments=False)

        result = await sync_service._evaluate_completeness(
            msg,
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=_OLD_RECEIVED_AT,
        )

        assert result.complete is True
        assert result.attachments_manifest is None
        assert result.attachments_pending is False
        list_attachments_mock.assert_not_called()

    async def test_has_attachments_true_empty_manifest_is_pending(self, monkeypatch):
        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", AsyncMock(return_value=[])
        )
        msg = self._valid_body_msg(hasAttachments=True)

        result = await sync_service._evaluate_completeness(
            msg,
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=_OLD_RECEIVED_AT,
        )

        assert result.complete is False
        assert "ATTACHMENT_MANIFEST_NOT_READY" in result.reasons
        assert result.attachments_pending is True
        assert result.attachments_manifest == []

    async def test_has_attachments_true_populated_manifest_is_complete(
        self, monkeypatch
    ):
        manifest = [{"id": "att-1", "@odata.type": "#microsoft.graph.fileAttachment"}]
        monkeypatch.setattr(
            sync_service.graph_client,
            "list_attachments",
            AsyncMock(return_value=manifest),
        )
        msg = self._valid_body_msg(hasAttachments=True)

        result = await sync_service._evaluate_completeness(
            msg,
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=_OLD_RECEIVED_AT,
        )

        assert result.complete is True
        assert result.attachments_pending is False
        assert result.attachments_manifest == manifest

    async def test_body_and_attachments_both_incomplete_combine_reasons(
        self, monkeypatch
    ):
        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", AsyncMock(return_value=[])
        )
        msg = {"hasAttachments": True}  # sin body en absoluto

        result = await sync_service._evaluate_completeness(
            msg,
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=_OLD_RECEIVED_AT,
        )

        assert result.complete is False
        assert set(result.reasons) == {"BODY_NOT_READY", "ATTACHMENT_MANIFEST_NOT_READY"}


# ---------------------------------------------------------------------------
# _incomplete_or_degrade — decisión retry vs degradar
# ---------------------------------------------------------------------------

class TestIncompleteOrDegrade:
    def test_retries_remaining_returns_incomplete_status(self):
        should_degrade, payload = sync_service._incomplete_or_degrade(
            provider_message_id="msg-1",
            reasons=["BODY_NOT_READY"],
            attempts=2,
            max_attempts=8,
        )
        assert should_degrade is False
        assert payload["ok"] is True
        assert payload["materialized"] is False
        assert payload["status"] == "incomplete:BODY_NOT_READY"
        assert payload["case_id"] is None

    def test_last_retry_before_exhaustion_still_retries(self):
        # attempts=6 -> next attempt sería el 7 (índice 7 < 8) -> aún reintenta
        should_degrade, payload = sync_service._incomplete_or_degrade(
            provider_message_id="msg-1",
            reasons=["BODY_NOT_READY"],
            attempts=6,
            max_attempts=8,
        )
        assert should_degrade is False
        assert payload is not None

    def test_budget_exhausted_degrades(self):
        # attempts=7 -> next attempt sería el 8 (índice 8 >= 8) -> degrada
        should_degrade, payload = sync_service._incomplete_or_degrade(
            provider_message_id="msg-1",
            reasons=["BODY_NOT_READY"],
            attempts=7,
            max_attempts=8,
        )
        assert should_degrade is True
        assert payload is None

    def test_multiple_reasons_joined_with_plus(self):
        should_degrade, payload = sync_service._incomplete_or_degrade(
            provider_message_id="msg-1",
            reasons=["BODY_NOT_READY", "ATTACHMENT_MANIFEST_NOT_READY"],
            attempts=0,
            max_attempts=8,
        )
        assert payload["status"] == "incomplete:BODY_NOT_READY+ATTACHMENT_MANIFEST_NOT_READY"

    def test_empty_reasons_uses_unknown_placeholder(self):
        should_degrade, payload = sync_service._incomplete_or_degrade(
            provider_message_id="msg-1",
            reasons=[],
            attempts=0,
            max_attempts=8,
        )
        assert payload["status"] == "incomplete:unknown"

    def test_max_attempts_of_one_degrades_immediately(self):
        """Caso límite: si max_attempts=1, no hay margen para reintentar -
        el primer intento (attempts=0) ya debe degradar."""
        should_degrade, payload = sync_service._incomplete_or_degrade(
            provider_message_id="msg-1",
            reasons=["BODY_NOT_READY"],
            attempts=0,
            max_attempts=1,
        )
        assert should_degrade is True
        assert payload is None


# ---------------------------------------------------------------------------
# _evaluate_attachments_flag_stability (Fase C)
#
# lastModifiedDateTime es solo una SEÑAL de estabilización, no una prueba
# de completitud - por eso incluso con dos lecturas estables, la función
# hace una verificación real con list_attachments() antes de aceptar
# "sin adjuntos".
# ---------------------------------------------------------------------------

class TestAttachmentsFlagStability:
    RECENT = _datetime(2026, 8, 19, 11, 50)  # "ahora" simulado: 10 min atrás
    NOW_FOR_TEST = _datetime(2026, 8, 19, 12, 0)

    def _freeze_now(self, monkeypatch):
        class _FrozenDatetime(_datetime):
            @classmethod
            def now(cls, tz=None):
                return self.NOW_FOR_TEST.replace(tzinfo=tz)

        monkeypatch.setattr(sync_service, "datetime", _FrozenDatetime)

    async def test_recent_first_read_returns_unstable_and_saves_snapshot(
        self, monkeypatch
    ):
        self._freeze_now(monkeypatch)
        list_attachments_mock = AsyncMock()
        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", list_attachments_mock
        )

        result = await sync_service._evaluate_attachments_flag_stability(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=self.RECENT,
            current_last_modified="2026-08-19T11:50:00Z",
            attachments_stability_snapshot=None,
            stabilization_window_minutes=15,
        )

        assert result.reasons == [sync_service.REASON_ATTACHMENTS_FLAG_UNSTABLE]
        assert result.new_snapshot == {
            "last_modified": "2026-08-19T11:50:00Z",
            "has_attachments": False,
        }
        assert result.attachments_manifest is None
        list_attachments_mock.assert_not_called()

    async def test_recent_second_read_lmd_changed_still_unstable(self, monkeypatch):
        self._freeze_now(monkeypatch)
        list_attachments_mock = AsyncMock()
        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", list_attachments_mock
        )
        previous = {"last_modified": "2026-08-19T11:50:00Z", "has_attachments": False}

        result = await sync_service._evaluate_attachments_flag_stability(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=self.RECENT,
            current_last_modified="2026-08-19T11:55:00Z",  # cambió
            attachments_stability_snapshot=previous,
            stabilization_window_minutes=15,
        )

        assert result.reasons == [sync_service.REASON_ATTACHMENTS_FLAG_UNSTABLE]
        assert result.new_snapshot["last_modified"] == "2026-08-19T11:55:00Z"
        list_attachments_mock.assert_not_called()

    async def test_recent_second_read_lmd_stable_empty_manifest_is_complete(
        self, monkeypatch
    ):
        self._freeze_now(monkeypatch)
        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", AsyncMock(return_value=[])
        )
        previous = {"last_modified": "2026-08-19T11:50:00Z", "has_attachments": False}

        result = await sync_service._evaluate_attachments_flag_stability(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=self.RECENT,
            current_last_modified="2026-08-19T11:50:00Z",  # igual
            attachments_stability_snapshot=previous,
            stabilization_window_minutes=15,
        )

        assert result.reasons == []
        assert result.new_snapshot is None
        assert result.attachments_manifest is None

    async def test_recent_second_read_lmd_stable_but_manifest_has_attachment(
        self, monkeypatch
    ):
        """El punto central de la política ajustada: lastModifiedDateTime
        estable NO es prueba suficiente - si list_attachments() SÍ trae
        algo, no se confía en hasAttachments=false."""
        self._freeze_now(monkeypatch)
        manifest = [{"id": "att-1", "@odata.type": "#microsoft.graph.fileAttachment"}]
        monkeypatch.setattr(
            sync_service.graph_client,
            "list_attachments",
            AsyncMock(return_value=manifest),
        )
        previous = {"last_modified": "2026-08-19T11:50:00Z", "has_attachments": False}

        result = await sync_service._evaluate_attachments_flag_stability(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=self.RECENT,
            current_last_modified="2026-08-19T11:50:00Z",
            attachments_stability_snapshot=previous,
            stabilization_window_minutes=15,
        )

        assert result.reasons == []
        assert result.new_snapshot is None
        assert result.attachments_manifest == manifest

    async def test_outside_window_trusts_false_without_extra_reads(self, monkeypatch):
        self._freeze_now(monkeypatch)
        list_attachments_mock = AsyncMock()
        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", list_attachments_mock
        )
        old_received_at = _datetime(2026, 8, 19, 11, 0)  # 60 min atrás

        result = await sync_service._evaluate_attachments_flag_stability(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=old_received_at,
            current_last_modified="2026-08-19T11:00:00Z",
            attachments_stability_snapshot=None,
            stabilization_window_minutes=15,
        )

        assert result.reasons == []
        assert result.new_snapshot is None
        list_attachments_mock.assert_not_called()

    async def test_age_exactly_at_window_boundary_still_treated_as_recent(
        self, monkeypatch
    ):
        """Límite: edad == ventana (no mayor) todavía se trata como
        'dentro de la ventana' - la condición es estrictamente '>'."""
        self._freeze_now(monkeypatch)
        list_attachments_mock = AsyncMock()
        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", list_attachments_mock
        )
        boundary_received_at = _datetime(2026, 8, 19, 11, 45)  # exactamente 15 min

        result = await sync_service._evaluate_attachments_flag_stability(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            received_at=boundary_received_at,
            current_last_modified="lmd",
            attachments_stability_snapshot=None,
            stabilization_window_minutes=15,
        )

        assert result.reasons == [sync_service.REASON_ATTACHMENTS_FLAG_UNSTABLE]
