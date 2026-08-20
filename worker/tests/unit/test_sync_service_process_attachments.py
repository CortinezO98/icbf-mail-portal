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

    upsert_attachment_mock = Mock()
    insert_event_mock = Mock()
    monkeypatch.setattr(sync_service.repos, "upsert_attachment", upsert_attachment_mock)
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
        upsert_attachment_mock=upsert_attachment_mock,
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
        env.upsert_attachment_mock.assert_called_once()

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

        env.upsert_attachment_mock.assert_not_called()
        failure_calls = [
            c for c in env.insert_event_mock.call_args_list
            if c.kwargs["event_type"] == "ATTACHMENTS_PARTIAL_FAILURE"
        ]
        assert len(failure_calls) == 1
        details = failure_calls[0].kwargs["details"]
        assert details["attempted"] == 1
        assert details["succeeded"] == 0
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

        assert env.upsert_attachment_mock.call_count == 1
        failure_calls = [
            c for c in env.insert_event_mock.call_args_list
            if c.kwargs["event_type"] == "ATTACHMENTS_PARTIAL_FAILURE"
        ]
        details = failure_calls[0].kwargs["details"]
        assert details["attempted"] == 2
        assert details["succeeded"] == 1
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
        env.upsert_attachment_mock.assert_not_called()


# ---------------------------------------------------------------------------
# D1-A: attachment sin graph_attachment_id -> nunca se inserta como NULL,
# se trata como fallo estructurado reintentable (MISSING_GRAPH_ATTACHMENT_ID).
#
# Motivo: los 7146 registros legacy con graph_attachment_id=NULL en
# producción (auditoría D1) confirmaron con evidencia de datos que ese
# patrón viene de una versión anterior del código - insertar más filas
# así perpetuaría el problema, porque MariaDB no protege NULL contra sí
# mismo bajo UNIQUE(message_id, graph_attachment_id) (D1-C).
# ---------------------------------------------------------------------------

class TestMissingGraphAttachmentId:
    async def test_missing_id_is_not_inserted_and_reported_as_failure(
        self, env
    ):
        att = _file_attachment()
        del att["id"]

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[att],
        )

        env.upsert_attachment_mock.assert_not_called()
        failure_calls = [
            c for c in env.insert_event_mock.call_args_list
            if c.kwargs["event_type"] == "ATTACHMENTS_PARTIAL_FAILURE"
        ]
        assert len(failure_calls) == 1
        failure = failure_calls[0].kwargs["details"]["failures"][0]
        assert failure["reason"] == "MISSING_GRAPH_ATTACHMENT_ID"
        assert failure["graph_attachment_id"] is None

    async def test_none_id_is_not_inserted(self, env):
        att = _file_attachment(id=None)

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[att],
        )

        env.upsert_attachment_mock.assert_not_called()
        failure_calls = [
            c for c in env.insert_event_mock.call_args_list
            if c.kwargs["event_type"] == "ATTACHMENTS_PARTIAL_FAILURE"
        ]
        assert failure_calls[0].kwargs["details"]["failures"][0]["reason"] == (
            "MISSING_GRAPH_ATTACHMENT_ID"
        )

    async def test_empty_string_id_is_not_inserted(self, env):
        """Defensivo: Graph no debería mandar id='' nunca, pero si lo
        hiciera, debe tratarse igual que ausente - no como identidad
        válida vacía."""
        att = _file_attachment(id="")

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[att],
        )

        env.upsert_attachment_mock.assert_not_called()

    async def test_whitespace_only_id_is_not_inserted(self, env):
        att = _file_attachment(id="   ")

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[att],
        )

        env.upsert_attachment_mock.assert_not_called()

    async def test_valid_id_is_stripped_and_used_normally(self, env, monkeypatch):
        """Un id con espacios alrededor (poco probable pero defensivo)
        se limpia antes de usarse como identidad."""
        monkeypatch.setattr(
            sync_service, "save_attachment_bytes", Mock(return_value=_stored_ok())
        )
        att = _file_attachment(id="  att-123  ")

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[att],
        )

        env.upsert_attachment_mock.assert_called_once()
        assert (
            env.upsert_attachment_mock.call_args.kwargs["graph_attachment_id"]
            == "att-123"
        )

    async def test_missing_id_mixed_with_valid_attachment_only_valid_is_inserted(
        self, env, monkeypatch
    ):
        monkeypatch.setattr(
            sync_service, "save_attachment_bytes", Mock(return_value=_stored_ok())
        )
        missing_id_att = _file_attachment(id=None, name="sin_id.pdf")
        valid_att = _file_attachment(id="att-valid", name="con_id.pdf")

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[missing_id_att, valid_att],
        )

        env.upsert_attachment_mock.assert_called_once()
        details = [
            c for c in env.insert_event_mock.call_args_list
            if c.kwargs["event_type"] == "ATTACHMENTS_PARTIAL_FAILURE"
        ][0].kwargs["details"]
        assert details["attempted"] == 2
        assert details["succeeded"] == 1
        assert details["failed"] == 1
        assert details["failures"][0]["reason"] == "MISSING_GRAPH_ATTACHMENT_ID"

    async def test_missing_id_never_produces_a_null_graph_attachment_id_insert(
        self, env
    ):
        """Guarda de regresión explícita del motivo de D1-A: no debe
        existir NINGÚN camino donde insert_attachment se llame con
        graph_attachment_id=None - eso es exactamente el patrón legacy
        (7146 filas) que este fix evita reproducir hacia adelante."""
        att = _file_attachment(id=None)

        await sync_service._process_attachments(
            mailbox_email="buzon@icbf.gov.co",
            graph_message_id="m1",
            message_pk=1,
            provider_message_id="m1",
            case_id=10,
            attachments_manifest=[att],
        )

        for call in env.upsert_attachment_mock.call_args_list:
            gid = call.kwargs.get("graph_attachment_id")
            assert gid is not None
            assert str(gid).strip() != ""
