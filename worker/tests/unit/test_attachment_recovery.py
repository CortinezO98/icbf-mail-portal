from __future__ import annotations

"""
Tests unitarios para las funciones puras de app.attachment_recovery
(D2): normalización de manifiesto, cálculo de hash, backoff, ventana de
estabilización reutilizada de Fase C, y clasificación transient/permanent
de fallos de descarga.

Cubre (numeración de la auditoría D2 final):
  7  - fileAttachment sin id -> unverifiable/MISSING_GRAPH_ATTACHMENT_ID
  13 - orden de IDs no afecta el hash
  15 - backoff: ladder normal vs long-tail
  20 - tipo no soportado -> unsupported_count, no entra a expected
  21 - sin @odata.type -> unverifiable/MISSING_ATTACHMENT_TYPE
"""

from datetime import datetime
from zoneinfo import ZoneInfo

import pytest

import app.attachment_recovery as ar

pytestmark = pytest.mark.unit


def _file_attachment(**overrides):
    att = {
        "@odata.type": "#microsoft.graph.fileAttachment",
        "id": "att-A",
    }
    att.update(overrides)
    return att


class TestNormalizeManifest:
    def test_file_attachments_with_id_are_expected(self):
        manifest = [_file_attachment(id="A"), _file_attachment(id="B")]
        norm = ar._normalize_attachment_manifest(manifest)

        assert norm.expected_graph_ids == {"A", "B"}
        assert norm.unverifiable is False
        assert norm.unsupported_count == 0

    def test_file_attachment_without_id_is_unverifiable(self):
        manifest = [_file_attachment(id=None)]
        norm = ar._normalize_attachment_manifest(manifest)

        assert norm.expected_graph_ids == set()
        assert norm.missing_id is True
        assert norm.unverifiable is True
        assert norm.unverifiable_reason == "MISSING_GRAPH_ATTACHMENT_ID"

    def test_file_attachment_with_blank_id_is_unverifiable(self):
        manifest = [_file_attachment(id="   ")]
        norm = ar._normalize_attachment_manifest(manifest)
        assert norm.missing_id is True

    def test_missing_odata_type_is_unverifiable_different_reason(self):
        manifest = [{"id": "A"}]  # sin @odata.type
        norm = ar._normalize_attachment_manifest(manifest)

        assert norm.missing_type is True
        assert norm.missing_id is False
        assert norm.unverifiable is True
        assert norm.unverifiable_reason == "MISSING_ATTACHMENT_TYPE"

    def test_empty_odata_type_string_is_unverifiable(self):
        manifest = [{"@odata.type": "", "id": "A"}]
        norm = ar._normalize_attachment_manifest(manifest)
        assert norm.missing_type is True

    def test_item_attachment_counts_as_unsupported_not_expected(self):
        manifest = [{"@odata.type": "#microsoft.graph.itemAttachment", "id": "X"}]
        norm = ar._normalize_attachment_manifest(manifest)

        assert norm.unsupported_count == 1
        assert norm.expected_graph_ids == set()
        assert norm.unverifiable is False  # unsupported != unverifiable

    def test_reference_attachment_counts_as_unsupported(self):
        manifest = [{"@odata.type": "#microsoft.graph.referenceAttachment", "id": "X"}]
        norm = ar._normalize_attachment_manifest(manifest)
        assert norm.unsupported_count == 1

    def test_mixed_manifest_classifies_each_item_independently(self):
        manifest = [
            _file_attachment(id="A"),
            {"@odata.type": "#microsoft.graph.itemAttachment", "id": "X"},
            _file_attachment(id=None),
        ]
        norm = ar._normalize_attachment_manifest(manifest)

        assert norm.expected_graph_ids == {"A"}
        assert norm.unsupported_count == 1
        assert norm.missing_id is True

    def test_empty_manifest_list(self):
        norm = ar._normalize_attachment_manifest([])
        assert norm.expected_graph_ids == set()
        assert norm.unverifiable is False
        assert norm.unsupported_count == 0

    def test_id_is_stripped(self):
        manifest = [_file_attachment(id="  A  ")]
        norm = ar._normalize_attachment_manifest(manifest)
        assert norm.expected_graph_ids == {"A"}


class TestManifestHash:
    def test_same_set_different_order_same_hash(self):
        h1 = ar._compute_manifest_hash({"A", "B", "C"})
        h2 = ar._compute_manifest_hash({"C", "A", "B"})
        assert h1 == h2

    def test_different_sets_different_hash(self):
        h1 = ar._compute_manifest_hash({"A", "B", "C"})
        h2 = ar._compute_manifest_hash({"A", "B", "C", "D"})
        assert h1 != h2

    def test_hash_is_deterministic_sha256_hex(self):
        h = ar._compute_manifest_hash({"A"})
        assert len(h) == 64
        assert all(c in "0123456789abcdef" for c in h)


class TestRecoveryBackoff:
    def test_first_five_attempts_use_normal_ladder(self):
        assert ar._recovery_backoff_seconds(0) == 30
        assert ar._recovery_backoff_seconds(1) == 120
        assert ar._recovery_backoff_seconds(2) == 300
        assert ar._recovery_backoff_seconds(3) == 900
        assert ar._recovery_backoff_seconds(4) == 1800

    def test_after_five_attempts_uses_long_tail_with_jitter(self, monkeypatch):
        monkeypatch.setattr(ar.settings, "ATTACHMENT_RECOVERY_LONG_TAIL_SECONDS", 21600)
        delay = ar._recovery_backoff_seconds(10)
        assert 18000 <= delay <= 25000

    def test_long_tail_never_below_60_seconds(self, monkeypatch):
        monkeypatch.setattr(ar.settings, "ATTACHMENT_RECOVERY_LONG_TAIL_SECONDS", 10)
        delay = ar._recovery_backoff_seconds(10)
        assert delay >= 60


class TestStabilizationWindow:
    def _freeze_now(self, monkeypatch, now: datetime):
        class _Frozen(datetime):
            @classmethod
            def now(cls, tz=None):
                return now.astimezone(tz) if tz else now

        monkeypatch.setattr(ar, "datetime", _Frozen)

    def test_recent_message_is_inside_window(self, monkeypatch):
        now = datetime(2026, 8, 20, 12, 0, tzinfo=ZoneInfo("America/Bogota"))
        self._freeze_now(monkeypatch, now)
        monkeypatch.setattr(ar.settings, "ATTACHMENTS_STABILIZATION_WINDOW_MINUTES", 15)

        received_at = datetime(2026, 8, 20, 11, 55)
        assert ar._is_message_outside_stabilization_window(received_at) is False

    def test_old_message_is_outside_window(self, monkeypatch):
        now = datetime(2026, 8, 20, 12, 0, tzinfo=ZoneInfo("America/Bogota"))
        self._freeze_now(monkeypatch, now)
        monkeypatch.setattr(ar.settings, "ATTACHMENTS_STABILIZATION_WINDOW_MINUTES", 15)

        received_at = datetime(2026, 8, 20, 11, 0)
        assert ar._is_message_outside_stabilization_window(received_at) is True

    def test_missing_received_at_defaults_to_outside_window(self):
        assert ar._is_message_outside_stabilization_window(None) is True


class TestClassifyStillMissing:
    def test_all_permanent_failures_is_blocked(self):
        still_missing = {"A", "B"}
        failures = [
            {"graph_attachment_id": "A", "reason": "REJECTED_BY_POLICY"},
            {"graph_attachment_id": "B", "reason": "MISSING_SHA256"},
        ]
        assert ar._classify_still_missing(still_missing, failures) == "blocked"

    def test_any_transient_failure_is_pending(self):
        still_missing = {"A", "B"}
        failures = [
            {"graph_attachment_id": "A", "reason": "REJECTED_BY_POLICY"},
            {"graph_attachment_id": "B", "reason": "NO_CONTENT_BYTES"},
        ]
        assert ar._classify_still_missing(still_missing, failures) == "pending"

    def test_missing_id_without_recorded_failure_defaults_to_pending(self):
        still_missing = {"C"}
        failures = []
        assert ar._classify_still_missing(still_missing, failures) == "pending"

    def test_empty_still_missing_is_pending_not_blocked(self):
        assert ar._classify_still_missing(set(), []) == "pending"
