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


# ---------------------------------------------------------------------------
# _evaluate_completeness — tabla de decisión sobre body
# ---------------------------------------------------------------------------

class TestEvaluateCompletenessBody:
    async def test_body_key_absent_is_not_ready(self, monkeypatch):
        msg = {"hasAttachments": False}
        result = await sync_service._evaluate_completeness(
            msg, mailbox_email="buzon@icbf.gov.co", graph_message_id="m1"
        )
        assert result.complete is False
        assert "BODY_NOT_READY" in result.reasons

    async def test_body_not_a_dict_is_not_ready(self, monkeypatch):
        msg = {"body": "esto no debería ser un string", "hasAttachments": False}
        result = await sync_service._evaluate_completeness(
            msg, mailbox_email="buzon@icbf.gov.co", graph_message_id="m1"
        )
        assert result.complete is False
        assert "BODY_NOT_READY" in result.reasons

    async def test_body_present_without_content_key_is_not_ready(self, monkeypatch):
        msg = {"body": {"contentType": "html"}, "hasAttachments": False}
        result = await sync_service._evaluate_completeness(
            msg, mailbox_email="buzon@icbf.gov.co", graph_message_id="m1"
        )
        assert result.complete is False
        assert "BODY_NOT_READY" in result.reasons

    async def test_body_content_explicitly_null_is_not_ready(self, monkeypatch):
        msg = {
            "body": {"contentType": "html", "content": None},
            "hasAttachments": False,
        }
        result = await sync_service._evaluate_completeness(
            msg, mailbox_email="buzon@icbf.gov.co", graph_message_id="m1"
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
            msg, mailbox_email="buzon@icbf.gov.co", graph_message_id="m1"
        )
        assert result.complete is True
        assert result.reasons == []

    async def test_body_with_real_content_is_valid(self, monkeypatch):
        msg = {
            "body": {"contentType": "html", "content": "<p>Hola</p>"},
            "hasAttachments": False,
        }
        result = await sync_service._evaluate_completeness(
            msg, mailbox_email="buzon@icbf.gov.co", graph_message_id="m1"
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

    async def test_has_attachments_false_skips_manifest_lookup(self, monkeypatch):
        list_attachments_mock = AsyncMock()
        monkeypatch.setattr(
            sync_service.graph_client, "list_attachments", list_attachments_mock
        )
        msg = self._valid_body_msg(hasAttachments=False)

        result = await sync_service._evaluate_completeness(
            msg, mailbox_email="buzon@icbf.gov.co", graph_message_id="m1"
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
            msg, mailbox_email="buzon@icbf.gov.co", graph_message_id="m1"
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
            msg, mailbox_email="buzon@icbf.gov.co", graph_message_id="m1"
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
            msg, mailbox_email="buzon@icbf.gov.co", graph_message_id="m1"
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
