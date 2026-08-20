from __future__ import annotations

"""
Tests de orquestación (mocks, sin DB real) para
app.attachment_recovery._process_one_recovery - el corazón del
algoritmo de diff/estabilización de D2.

Numeración según la auditoría D2 final:
  1  - 0/3 -> recupera A,B,C
  2  - 1/3 -> recupera solo B,C
  3  - 2/3 -> recupera solo C
  4  - 3/3 primera lectura -> verifying
  5  - 3/3 segunda lectura mismo manifest -> complete
  6  - verifying ABC -> manifest ABCD -> no complete, recupera D
  8  - REJECTED_BY_POLICY -> blocked
  9  - legacy NULL -> blocked/LEGACY_ATTACHMENT_IDENTITY_UNKNOWN, 0 llamadas Graph
  17 - manifest vacío -> pending, nunca verifying/complete
  18 - expected_count previo>0 + manifest vacío -> pending/unstable
  19 - 2/3 -> descarga tercero con éxito -> verifying directo, attempts no aumenta
  20 - unsupported -> blocked, no falso complete
  21 - sin @odata.type -> pending/unverifiable
"""

from contextlib import contextmanager
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import pytest

import app.attachment_recovery as ar

pytestmark = pytest.mark.unit


def _file_attachment(**overrides):
    att = {"@odata.type": "#microsoft.graph.fileAttachment", "id": "A"}
    att.update(overrides)
    return att


def _row(**overrides):
    base = {
        "message_id": 1,
        "status": "pending",
        "attempts": 0,
        "expected_count": None,
        "manifest_hash": None,
    }
    base.update(overrides)
    return base


@pytest.fixture
def env(monkeypatch):
    @contextmanager
    def _get_db_session():
        yield Mock()

    monkeypatch.setattr(ar, "get_db_session", _get_db_session)

    has_legacy_mock = Mock(return_value=False)
    monkeypatch.setattr(ar.repo, "has_legacy_null_identity", has_legacy_mock)

    get_context_mock = Mock(
        return_value={
            "mailbox_email": "buzon@icbf.gov.co",
            "provider_message_id": "AAMk-1",
            "received_at": None,  # None -> siempre "fuera de ventana" (defensivo)
            "case_id": 10,
            "conversation_id": None,
        }
    )
    monkeypatch.setattr(ar.repo, "get_message_context", get_context_mock)

    list_attachments_mock = AsyncMock(return_value=[])
    monkeypatch.setattr(ar.graph_client, "list_attachments", list_attachments_mock)

    process_attachments_mock = AsyncMock(
        return_value={"attempted": 0, "succeeded": 0, "failed": 0, "failures": []}
    )
    monkeypatch.setattr(ar.sync_service, "_process_attachments", process_attachments_mock)

    get_persisted_mock = Mock(return_value=set())
    monkeypatch.setattr(ar.repo, "get_persisted_graph_ids", get_persisted_mock)

    mark_pending_retry_mock = Mock()
    mark_verifying_mock = Mock()
    mark_complete_mock = Mock()
    mark_blocked_mock = Mock()
    monkeypatch.setattr(ar.repo, "mark_pending_retry", mark_pending_retry_mock)
    monkeypatch.setattr(ar.repo, "mark_verifying", mark_verifying_mock)
    monkeypatch.setattr(ar.repo, "mark_complete", mark_complete_mock)
    monkeypatch.setattr(ar.repo, "mark_blocked", mark_blocked_mock)

    return SimpleNamespace(
        has_legacy_mock=has_legacy_mock,
        get_context_mock=get_context_mock,
        list_attachments_mock=list_attachments_mock,
        process_attachments_mock=process_attachments_mock,
        get_persisted_mock=get_persisted_mock,
        mark_pending_retry_mock=mark_pending_retry_mock,
        mark_verifying_mock=mark_verifying_mock,
        mark_complete_mock=mark_complete_mock,
        mark_blocked_mock=mark_blocked_mock,
    )


async def _run(row):
    return await ar._process_one_recovery(
        row=row, message_id=row["message_id"], attempts=row["attempts"]
    )


class TestZeroOneTwoOfThree:
    async def test_0_of_3_recovers_all_three(self, env):
        """Test 1: 0/3 -> recupera A,B,C."""
        env.list_attachments_mock.return_value = [
            _file_attachment(id="A"), _file_attachment(id="B"), _file_attachment(id="C"),
        ]
        env.get_persisted_mock.side_effect = [set(), {"A", "B", "C"}]  # antes / después

        result = await _run(_row())

        assert result == "verifying"
        called_manifest = env.process_attachments_mock.call_args.kwargs["attachments_manifest"]
        assert {a["id"] for a in called_manifest} == {"A", "B", "C"}

    async def test_1_of_3_recovers_only_missing_two(self, env):
        """Test 2: 1/3 (A ya está) -> recupera solo B, C."""
        env.list_attachments_mock.return_value = [
            _file_attachment(id="A"), _file_attachment(id="B"), _file_attachment(id="C"),
        ]
        env.get_persisted_mock.side_effect = [{"A"}, {"A", "B", "C"}]

        result = await _run(_row())

        assert result == "verifying"
        called_manifest = env.process_attachments_mock.call_args.kwargs["attachments_manifest"]
        assert {a["id"] for a in called_manifest} == {"B", "C"}

    async def test_2_of_3_recovers_only_missing_one(self, env):
        """Test 3: 2/3 (A,B ya están) -> recupera solo C."""
        env.list_attachments_mock.return_value = [
            _file_attachment(id="A"), _file_attachment(id="B"), _file_attachment(id="C"),
        ]
        env.get_persisted_mock.side_effect = [{"A", "B"}, {"A", "B", "C"}]

        result = await _run(_row())

        called_manifest = env.process_attachments_mock.call_args.kwargs["attachments_manifest"]
        assert {a["id"] for a in called_manifest} == {"C"}


class TestVerifyingAndComplete:
    async def test_3_of_3_first_read_goes_to_verifying(self, env):
        """Test 4: ya N/N desde el inicio, primera vez -> verifying, no complete."""
        env.list_attachments_mock.return_value = [_file_attachment(id="A"), _file_attachment(id="B")]
        env.get_persisted_mock.return_value = {"A", "B"}

        result = await _run(_row(status="pending"))  # no venía de verifying

        assert result == "verifying"
        env.mark_complete_mock.assert_not_called()
        env.mark_verifying_mock.assert_called_once()

    async def test_3_of_3_second_read_same_hash_completes(self, env):
        """Test 5: segunda lectura, mismo manifest_hash, N/N -> complete."""
        env.list_attachments_mock.return_value = [_file_attachment(id="A"), _file_attachment(id="B")]
        env.get_persisted_mock.return_value = {"A", "B"}
        same_hash = ar._compute_manifest_hash({"A", "B"})

        result = await _run(_row(status="verifying", manifest_hash=same_hash, expected_count=2))

        assert result == "complete"
        env.mark_complete_mock.assert_called_once()

    async def test_manifest_changes_between_verifications_recovers_new_id(self, env):
        """Test 6: verifying con ABC -> ahora Graph da ABCD -> no complete, recupera D."""
        env.list_attachments_mock.return_value = [
            _file_attachment(id="A"), _file_attachment(id="B"),
            _file_attachment(id="C"), _file_attachment(id="D"),
        ]
        old_hash = ar._compute_manifest_hash({"A", "B", "C"})
        env.get_persisted_mock.side_effect = [{"A", "B", "C"}, {"A", "B", "C", "D"}]

        result = await _run(_row(status="verifying", manifest_hash=old_hash, expected_count=3))

        assert result == "verifying"
        env.mark_complete_mock.assert_not_called()
        called_manifest = env.process_attachments_mock.call_args.kwargs["attachments_manifest"]
        assert {a["id"] for a in called_manifest} == {"D"}


class TestPermanentFailureBlocked:
    async def test_rejected_by_policy_marks_blocked(self, env):
        """Test 8: descarga falla por REJECTED_BY_POLICY -> blocked."""
        env.list_attachments_mock.return_value = [_file_attachment(id="A")]
        env.get_persisted_mock.side_effect = [set(), set()]  # sigue sin persistir
        env.process_attachments_mock.return_value = {
            "attempted": 1, "succeeded": 0, "failed": 1,
            "failures": [{"graph_attachment_id": "A", "reason": "REJECTED_BY_POLICY"}],
        }

        result = await _run(_row())

        assert result == "blocked"
        env.mark_blocked_mock.assert_called_once()
        assert env.mark_blocked_mock.call_args.kwargs["reason"] == "REJECTED_BY_POLICY"


class TestLegacyGuard:
    async def test_legacy_null_identity_blocks_without_calling_graph(self, env):
        """Test 9: mensaje con adjunto legacy NULL -> blocked directo,
        CERO llamadas a Graph."""
        env.has_legacy_mock.return_value = True

        result = await _run(_row())

        assert result == "blocked"
        env.mark_blocked_mock.assert_called_once()
        assert (
            env.mark_blocked_mock.call_args.kwargs["reason"]
            == "LEGACY_ATTACHMENT_IDENTITY_UNKNOWN"
        )
        env.list_attachments_mock.assert_not_called()
        env.process_attachments_mock.assert_not_called()


class TestEmptyManifest:
    async def test_empty_manifest_first_time_is_pending_not_verifying(self, env):
        """Test 17: manifest vacío -> pending, nunca verifying/complete."""
        env.list_attachments_mock.return_value = []

        result = await _run(_row(expected_count=None))

        assert result == "pending"
        env.mark_verifying_mock.assert_not_called()
        env.mark_complete_mock.assert_not_called()
        kwargs = env.mark_pending_retry_mock.call_args.kwargs
        assert kwargs["reason"] == "ATTACHMENT_MANIFEST_EMPTY"

    async def test_empty_manifest_with_previous_expected_count_is_unstable(self, env):
        """Test 18: ya sabíamos expected_count=3, Graph ahora da vacío ->
        pending/ATTACHMENT_MANIFEST_EMPTY_UNSTABLE, no se reemplaza ABC
        por nada."""
        env.list_attachments_mock.return_value = []

        result = await _run(_row(expected_count=3))

        assert result == "pending"
        kwargs = env.mark_pending_retry_mock.call_args.kwargs
        assert kwargs["reason"] == "ATTACHMENT_MANIFEST_EMPTY_UNSTABLE"


class TestSuccessfulRecoveryGoesDirectlyToVerifying:
    async def test_2_of_3_success_skips_pending_goes_to_verifying_without_incrementing_attempts(
        self, env
    ):
        """Test 19: 2/3 -> se descarga el 3ro exitosamente -> directo a
        verifying, NUNCA pasa por mark_pending_retry (que incrementaría
        attempts)."""
        env.list_attachments_mock.return_value = [
            _file_attachment(id="A"), _file_attachment(id="B"), _file_attachment(id="C"),
        ]
        env.get_persisted_mock.side_effect = [{"A", "B"}, {"A", "B", "C"}]
        env.process_attachments_mock.return_value = {
            "attempted": 1, "succeeded": 1, "failed": 0, "failures": [],
        }

        result = await _run(_row(attempts=3))

        assert result == "verifying"
        env.mark_pending_retry_mock.assert_not_called()
        env.mark_verifying_mock.assert_called_once()


class TestUnsupportedType:
    async def test_unsupported_attachment_present_blocks_instead_of_complete(self, env):
        """Test 20: hay un itemAttachment (no soportado) además de
        fileAttachments completos -> blocked, nunca complete silencioso."""
        env.list_attachments_mock.return_value = [
            _file_attachment(id="A"),
            {"@odata.type": "#microsoft.graph.itemAttachment", "id": "X"},
        ]
        env.get_persisted_mock.return_value = {"A"}

        result = await _run(_row())

        assert result == "blocked"
        assert (
            env.mark_blocked_mock.call_args.kwargs["reason"]
            == "UNSUPPORTED_ATTACHMENT_TYPE"
        )
        env.mark_complete_mock.assert_not_called()


class TestMissingODataType:
    async def test_missing_type_is_pending_unverifiable(self, env):
        """Test 21: adjunto sin @odata.type -> pending/MISSING_ATTACHMENT_TYPE."""
        env.list_attachments_mock.return_value = [{"id": "A"}]  # sin @odata.type

        result = await _run(_row())

        assert result == "pending"
        kwargs = env.mark_pending_retry_mock.call_args.kwargs
        assert kwargs["reason"] == "MISSING_ATTACHMENT_TYPE"
        env.process_attachments_mock.assert_not_called()

    async def test_missing_id_is_pending_unverifiable(self, env):
        env.list_attachments_mock.return_value = [_file_attachment(id=None)]

        result = await _run(_row())

        assert result == "pending"
        kwargs = env.mark_pending_retry_mock.call_args.kwargs
        assert kwargs["reason"] == "MISSING_GRAPH_ATTACHMENT_ID"


class TestGraphFetchFailureIsTransient:
    async def test_graph_exception_marks_pending_retry(self, env):
        env.list_attachments_mock.side_effect = RuntimeError("Graph timeout")

        result = await _run(_row(attempts=2))

        assert result == "pending"
        kwargs = env.mark_pending_retry_mock.call_args.kwargs
        assert kwargs["reason"] == "GRAPH_FETCH_FAILED"
        assert kwargs["attempts"] == 2


class TestPollDoesNotCallGraphWithoutWork:
    async def test_empty_claim_batch_never_calls_graph(self, monkeypatch, env):
        """Test 24: si claim_batch() no devuelve filas (nada con
        available_at vencido), el ciclo completo termina sin llamar a
        Graph ni una sola vez - el poll es una query barata, no un
        golpe a la API externa."""
        from contextlib import contextmanager
        from unittest.mock import Mock

        @contextmanager
        def _get_db_session():
            yield Mock()

        monkeypatch.setattr(ar, "get_db_session", _get_db_session)
        monkeypatch.setattr(ar.repo, "claim_batch", Mock(return_value=[]))

        counts = await ar.run_attachment_recovery_cycle(limit=50)

        assert counts["checked"] == 0
        env.list_attachments_mock.assert_not_called()
