from __future__ import annotations

"""
Tests unitarios para app.sync_service._process_attachments - cubre A5
(los fallos por adjunto ya no se pierden en un `continue` silencioso, se
acumulan y quedan en un evento ATTACHMENTS_PARTIAL_FAILURE) y el reuso
del manifiesto ya obtenido por el Completeness Gate.
"""

import base64
from contextlib import contextmanager
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest

import app.sync_service as sync_service

pytestmark = pytest.mark.unit


def _b64(data: bytes) -> str:
    return base64.b64encode(data).decode()


def _file_attachment(**overrides):
    att = {
        "@odata.type": "#microsoft.graph.fileAttachment",
        "id": "att-1",
        "name": "documento.pdf",
        "contentType": "application/pdf",
        "size": 4,
        "isInline": False,
        "contentBytes": _b64(b"data"),
    }
    att.update(overrides)
    return att


class FakeDB:
    def __init__(self):
        self.executed = []

    def execute(self, stmt, params=None):
        self.executed.append((str(stmt), params))
        result = Mock()
        result.fetchone.return_value = (0,)
        return result


@pytest.fixture
def env(monkeypatch):
    fake_db = FakeDB()

    @contextmanager
    def _get_db_session():
        yield fake_db

    monkeypatch.setattr(sync_service, "get_db_session", _get_db_session)
    monkeypatch.setattr(sync_service, "_attachments_count", Mock(return_value=0))

    insert_attachment_mock = Mock()
    insert_event_mock = Mock()
    monkeypatch.setattr(sync_service.repos, "insert_attachment", insert_attachment_mock)
    monkeypatch.setattr(sync_service.repos, "insert_case_event", insert_event_mock)

    monkeypatch.setattr(
        sync_service.graph_client, "list_attachments", AsyncMock(return_value=[])
    )
    monkeypatch.setattr(sync_service.graph_client, "get_attachment", AsyncMock())

    async def _fake_to_thread(fn, /, *args, **kwargs):
        return fn(*args, **kwargs)

    monkeypatch.setattr(sync_service.asyncio, "to_thread", _fake_to_thread)

    return SimpleNamespace(
        fake_db=fake_db,
        insert_attachment_mock=insert_attachment_mock,
        insert_event_mock=insert_event_mock,
    )


def _stored_ok(**overrides):
    stored = SimpleNamespace(
        storage_path="ab/cd/hash_documento.pdf",
        sha256="a" * 64,
        size_bytes=4,
        content_type="application/pdf",
    )
    for k, v in overrides.items():
        setattr(stored, k, v)
    return stored


class TestReusesGateManifest:
    async def test_uses_provided_manifest_without_calling_graph_again(
        self, env, monkeypatch
    ):
        manifest = [_file_attachment()]
        monkeypatch.setattr(
            sync_service, "save_attachment_bytes", Mock(return_value=_stored_ok())
        )

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=manifest,
        )

        sync_service.graph_client.list_attachments.assert_not_called()
        env.insert_attachment_mock.assert_called_once()

    async def test_fetches_manifest_when_not_provided(self, env, monkeypatch):
        sync_service.graph_client.list_attachments.return_value = [_file_attachment()]
        monkeypatch.setattr(
            sync_service, "save_attachment_bytes", Mock(return_value=_stored_ok())
        )

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=None,
        )

        sync_service.graph_client.list_attachments.assert_called_once()


class TestPartialFailuresAreStructured:
    async def test_all_succeed_no_failure_event(self, env, monkeypatch):
        monkeypatch.setattr(
            sync_service, "save_attachment_bytes", Mock(return_value=_stored_ok())
        )
        manifest = [_file_attachment()]

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=manifest,
        )

        event_types = [
            c.kwargs["event_type"] for c in env.insert_event_mock.call_args_list
        ]
        assert "ATTACHMENTS_SYNCED" in event_types
        assert "ATTACHMENTS_PARTIAL_FAILURE" not in event_types

    async def test_missing_content_bytes_recorded_as_failure_not_silently_dropped(
        self, env, monkeypatch
    ):
        att = _file_attachment(contentBytes=None, id="att-no-content")
        sync_service.graph_client.get_attachment.return_value = {}  # sin contentBytes tampoco

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[att],
        )

        env.insert_attachment_mock.assert_not_called()
        failure_calls = [
            c for c in env.insert_event_mock.call_args_list
            if c.kwargs["event_type"] == "ATTACHMENTS_PARTIAL_FAILURE"
        ]
        assert len(failure_calls) == 1
        details = failure_calls[0].kwargs["details"]
        assert details["expected"] == 1
        assert details["downloaded"] == 0
        assert details["failed"] == 1
        assert details["failures"][0]["reason"] == "NO_CONTENT_BYTES"

    async def test_invalid_base64_recorded_as_failure(self, env):
        # "abc" tiene longitud no múltiplo de 4 -> padding incorrecto,
        # dispara binascii.Error incluso con base64.b64decode en modo no
        # estricto (el que usa el código real). Nota aparte: strings con
        # longitud "válida" pero contenido basura NO siempre disparan
        # excepción en modo no estricto (b64decode es permisivo por
        # defecto) - ese es un límite conocido de la detección actual,
        # no de este test.
        att = _file_attachment(contentBytes="abc")

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[att],
        )

        failure_calls = [
            c for c in env.insert_event_mock.call_args_list
            if c.kwargs["event_type"] == "ATTACHMENTS_PARTIAL_FAILURE"
        ]
        assert len(failure_calls) == 1
        assert failure_calls[0].kwargs["details"]["failures"][0]["reason"] in (
            "INVALID_BASE64",
        )

    async def test_rejected_by_storage_policy_recorded_as_failure(
        self, env, monkeypatch
    ):
        monkeypatch.setattr(
            sync_service,
            "save_attachment_bytes",
            Mock(side_effect=ValueError("Blocked extension: .exe")),
        )
        att = _file_attachment(name="virus.exe", contentType="application/octet-stream")

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[att],
        )

        failure_calls = [
            c for c in env.insert_event_mock.call_args_list
            if c.kwargs["event_type"] == "ATTACHMENTS_PARTIAL_FAILURE"
        ]
        assert failure_calls[0].kwargs["details"]["failures"][0]["reason"] == "REJECTED_BY_POLICY"

    async def test_mixed_success_and_failure_reports_both_counts(
        self, env, monkeypatch
    ):
        def _save(*, filename, content_bytes, content_type):
            if filename == "malo.exe":
                raise ValueError("Blocked extension: .exe")
            return _stored_ok(size_bytes=len(content_bytes))

        monkeypatch.setattr(sync_service, "save_attachment_bytes", Mock(side_effect=_save))

        manifest = [
            _file_attachment(id="att-ok", name="bueno.pdf"),
            _file_attachment(id="att-bad", name="malo.exe", contentType="application/octet-stream"),
        ]

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=manifest,
        )

        assert env.insert_attachment_mock.call_count == 1
        failure_calls = [
            c for c in env.insert_event_mock.call_args_list
            if c.kwargs["event_type"] == "ATTACHMENTS_PARTIAL_FAILURE"
        ]
        details = failure_calls[0].kwargs["details"]
        assert details["expected"] == 2
        assert details["downloaded"] == 1
        assert details["failed"] == 1

    async def test_empty_manifest_returns_without_events(self, env):
        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[],
        )
        env.insert_event_mock.assert_not_called()
        env.insert_attachment_mock.assert_not_called()
