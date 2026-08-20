from __future__ import annotations

"""
Tests unitarios para app.inbound_queue_repo.mark_retry_unbounded.

Son tests de "verificación de query" (SWEBOK v4, Software Testing KA):
se mockea db.execute() y se inspecciona el SQL/params que se le pasan,
sin una base de datos real. Esto NO reemplaza un test de integración
contra MariaDB (que validaría que el SQL es sintácticamente correcto y
que el UPDATE realmente hace lo que dice) - ese sigue pendiente para la
Fase H, ya documentado en tests/README.md.

Lo que SÍ se puede afirmar con confianza desde este nivel: la función
nunca construye el UPDATE que marca 'failed' (a diferencia de
mark_retry), y elige el delay correcto según queue_event_age_seconds.
"""

from unittest.mock import Mock
import json

import pytest

import app.inbound_queue_repo as inbound_queue_repo

pytestmark = pytest.mark.unit


class FakeDB:
    def __init__(self):
        self.calls: list[tuple[str, dict]] = []

    def execute(self, stmt, params=None):
        self.calls.append((str(stmt), params or {}))
        return Mock()


class TestMarkRetryUnbounded:
    def test_never_sets_status_failed(self):
        """A diferencia de mark_retry, esta función no tiene ninguna
        rama que escriba status='failed' - es la garantía central de
        Fase B (la fila nunca se pierde por agotar MAX_ATTEMPTS)."""
        db = FakeDB()

        inbound_queue_repo.mark_retry_unbounded(
            db,
            event_id=1,
            attempts=999,
            error="not_materialized:incomplete:MISSING_RECEIVED_DATETIME",
            queue_event_age_seconds=10,
            alert_age_seconds=3600,
            long_retry_seconds=21600,
        )

        assert len(db.calls) == 1
        sql, params = db.calls[0]
        assert "failed" not in sql.lower()
        assert "status" in sql.lower() and "'pending'" in sql

    def test_always_increments_attempts(self):
        db = FakeDB()

        inbound_queue_repo.mark_retry_unbounded(
            db,
            event_id=1,
            attempts=7,
            error="err",
            queue_event_age_seconds=0,
            alert_age_seconds=3600,
            long_retry_seconds=21600,
        )

        _, params = db.calls[0]
        assert params["attempts"] == 8

    def test_uses_normal_ladder_when_age_within_threshold(self):
        db = FakeDB()

        inbound_queue_repo.mark_retry_unbounded(
            db,
            event_id=1,
            attempts=0,  # next_attempt=1 -> _retry_delay_seconds(1) == 30
            error="err",
            queue_event_age_seconds=100,
            alert_age_seconds=3600,
            long_retry_seconds=21600,
        )

        _, params = db.calls[0]
        assert params["delay"] == 30

    def test_uses_long_tail_when_age_exceeds_threshold(self):
        db = FakeDB()

        inbound_queue_repo.mark_retry_unbounded(
            db,
            event_id=1,
            attempts=0,
            error="err",
            queue_event_age_seconds=3601,
            alert_age_seconds=3600,
            long_retry_seconds=21600,
        )

        _, params = db.calls[0]
        assert params["delay"] == 21600

    def test_age_exactly_at_threshold_still_uses_normal_ladder(self):
        """Límite: age == alert_age_seconds (no mayor) todavía debe usar
        el ladder normal - la condición es estrictamente '>' , no '>='."""
        db = FakeDB()

        inbound_queue_repo.mark_retry_unbounded(
            db,
            event_id=1,
            attempts=3,  # next_attempt=4 -> _retry_delay_seconds(4) == 900
            error="err",
            queue_event_age_seconds=3600,
            alert_age_seconds=3600,
            long_retry_seconds=21600,
        )

        _, params = db.calls[0]
        assert params["delay"] == 900

    def test_error_message_is_truncated_to_1000_chars(self):
        db = FakeDB()
        long_error = "x" * 5000

        inbound_queue_repo.mark_retry_unbounded(
            db,
            event_id=1,
            attempts=0,
            error=long_error,
            queue_event_age_seconds=0,
            alert_age_seconds=3600,
            long_retry_seconds=21600,
        )

        _, params = db.calls[0]
        assert len(params["err"]) == 1000

    def test_ladder_matches_mark_retry_for_same_attempt(self):
        """El ladder reutilizado (_retry_delay_seconds) debe ser
        exactamente el mismo que usa mark_retry() para el camino normal -
        no se duplica ni diverge la tabla de backoff."""
        db_unbounded = FakeDB()
        db_bounded = FakeDB()

        inbound_queue_repo.mark_retry_unbounded(
            db_unbounded,
            event_id=1,
            attempts=2,
            error="err",
            queue_event_age_seconds=0,
            alert_age_seconds=3600,
            long_retry_seconds=21600,
        )
        inbound_queue_repo.mark_retry(
            db_bounded,
            event_id=1,
            attempts=2,
            error="err",
            max_attempts=8,
        )

        _, params_unbounded = db_unbounded.calls[0]
        _, params_bounded = db_bounded.calls[-1]
        assert params_unbounded["delay"] == params_bounded["delay"]


# ---------------------------------------------------------------------------
# mark_retry con payload_json (Fase C): el parámetro nuevo solo debe
# tocar la columna en la rama 'pending' vía COALESCE, sin afectar el
# comportamiento existente cuando no se pasa.
# ---------------------------------------------------------------------------

class TestMarkRetryPayloadJson:
    def test_payload_json_none_by_default_does_not_break_existing_calls(self):
        """Regresión: llamadas existentes sin el nuevo parámetro deben
        seguir funcionando igual (payload_json=None -> COALESCE conserva
        lo que ya había en la fila, no lo pisa)."""
        db = FakeDB()

        inbound_queue_repo.mark_retry(
            db, event_id=1, attempts=0, error="err", max_attempts=8
        )

        _, params = db.calls[0]
        assert params["payload_json"] is None

    def test_payload_json_passed_through_when_provided(self):
        db = FakeDB()

        inbound_queue_repo.mark_retry(
            db,
            event_id=1,
            attempts=0,
            error="err",
            max_attempts=8,
            payload_json='{"_internal": {"attachments_stability": {"a": 1}}}',
        )

        _, params = db.calls[0]
        assert params["payload_json"] == '{"_internal": {"attachments_stability": {"a": 1}}}'

    def test_payload_json_not_written_when_falling_to_failed_branch(self):
        """Si la fila va a 'failed' (presupuesto agotado), no tiene
        sentido escribir payload_json - ese intento ya terminó."""
        db = FakeDB()

        inbound_queue_repo.mark_retry(
            db,
            event_id=1,
            attempts=7,
            error="err",
            max_attempts=8,
            payload_json='{"_internal": {}}',
        )

        sql, params = db.calls[0]
        assert "failed" in sql.lower()
        assert "payload_json" not in params


# ---------------------------------------------------------------------------
# extract_stability_snapshot / merge_stability_snapshot (Fase C)
#
# El requisito central: NUNCA perder el contenido original de
# payload_json, y guardar/leer el snapshot en un namespace reservado
# (_internal.attachments_stability) que no colisione con nada más.
# ---------------------------------------------------------------------------

class TestStabilitySnapshotNamespace:
    def test_extract_returns_none_when_payload_is_none(self):
        assert inbound_queue_repo.extract_stability_snapshot(None) is None

    def test_extract_returns_none_on_invalid_json(self):
        assert inbound_queue_repo.extract_stability_snapshot("{not valid json") is None

    def test_extract_returns_none_when_payload_is_not_an_object(self):
        assert inbound_queue_repo.extract_stability_snapshot("[1, 2, 3]") is None

    def test_extract_returns_none_when_no_internal_key(self):
        assert inbound_queue_repo.extract_stability_snapshot('{"foo": "bar"}') is None

    def test_extract_returns_none_when_internal_is_not_an_object(self):
        assert (
            inbound_queue_repo.extract_stability_snapshot('{"_internal": "no-es-objeto"}')
            is None
        )

    def test_extract_reads_snapshot_correctly(self):
        payload = json.dumps({
            "_internal": {
                "attachments_stability": {"last_modified": "lmd1", "has_attachments": False}
            }
        })
        result = inbound_queue_repo.extract_stability_snapshot(payload)
        assert result == {"last_modified": "lmd1", "has_attachments": False}

    def test_merge_preserves_original_webhook_notification_payload(self):
        """Requisito central: el payload crudo del webhook (u otro
        contenido) NUNCA debe perderse al guardar el snapshot."""
        original = json.dumps({
            "subscriptionId": "sub-1",
            "resourceData": {"id": "AAMk-1"},
            "clientState": "secret",
        })
        snapshot = {"last_modified": "lmd2", "has_attachments": False}

        merged_str = inbound_queue_repo.merge_stability_snapshot(original, snapshot)
        merged = json.loads(merged_str)

        # Contenido original intacto
        assert merged["subscriptionId"] == "sub-1"
        assert merged["resourceData"] == {"id": "AAMk-1"}
        assert merged["clientState"] == "secret"
        # Snapshot en el namespace reservado
        assert merged["_internal"]["attachments_stability"] == snapshot

    def test_merge_preserves_other_keys_already_in_internal_namespace(self):
        """Si _internal ya tenía otra clave (de una versión futura, por
        ejemplo), no debe perderse al actualizar solo
        attachments_stability."""
        original = json.dumps({
            "_internal": {
                "attachments_stability": {"last_modified": "old", "has_attachments": False},
                "some_other_future_field": "no tocar esto",
            }
        })
        new_snapshot = {"last_modified": "new", "has_attachments": False}

        merged_str = inbound_queue_repo.merge_stability_snapshot(original, new_snapshot)
        merged = json.loads(merged_str)

        assert merged["_internal"]["attachments_stability"] == new_snapshot
        assert merged["_internal"]["some_other_future_field"] == "no tocar esto"

    def test_merge_handles_none_payload(self):
        snapshot = {"last_modified": "lmd", "has_attachments": False}
        merged_str = inbound_queue_repo.merge_stability_snapshot(None, snapshot)
        merged = json.loads(merged_str)
        assert merged["_internal"]["attachments_stability"] == snapshot

    def test_merge_preserves_unparseable_payload_defensively(self):
        """Si payload_json existente no es JSON válido, no se pierde
        silenciosamente - se conserva bajo una clave de respaldo."""
        broken = "{esto no es json valido"
        snapshot = {"last_modified": "lmd", "has_attachments": False}

        merged_str = inbound_queue_repo.merge_stability_snapshot(broken, snapshot)
        merged = json.loads(merged_str)

        assert merged["_original_unparseable_payload"] == broken
        assert merged["_internal"]["attachments_stability"] == snapshot

    def test_merge_preserves_non_object_payload_defensively(self):
        """Si payload_json existente es JSON válido pero no es un objeto
        (ej. una lista), tampoco se pierde."""
        non_object = json.dumps([1, 2, 3])
        snapshot = {"last_modified": "lmd", "has_attachments": False}

        merged_str = inbound_queue_repo.merge_stability_snapshot(non_object, snapshot)
        merged = json.loads(merged_str)

        assert merged["_original_non_object_payload"] == [1, 2, 3]
        assert merged["_internal"]["attachments_stability"] == snapshot

    def test_round_trip_merge_then_extract(self):
        """El caso de uso real: merge (persistir) seguido de extract
        (leer en el siguiente intento) debe devolver exactamente el
        mismo snapshot."""
        original = json.dumps({"subscriptionId": "sub-1"})
        snapshot = {"last_modified": "lmd-xyz", "has_attachments": False}

        merged_str = inbound_queue_repo.merge_stability_snapshot(original, snapshot)
        extracted = inbound_queue_repo.extract_stability_snapshot(merged_str)

        assert extracted == snapshot


# ---------------------------------------------------------------------------
# enqueue_event: filas pending/processing no deben perder el snapshot
# (Fase C, requisito explícito) - se verifica que, en ese camino, NO se
# ejecuta ningún UPDATE en absoluto sobre la fila.
# ---------------------------------------------------------------------------

class FakeDBForEnqueue:
    """Simula las dos consultas de lectura que hace enqueue_event() en
    modo normal (force=False) antes de decidir qué hacer, y registra
    cualquier UPDATE/INSERT posterior para poder afirmar que NO ocurrió
    ninguno cuando la fila está pending/processing."""

    def __init__(self, *, is_materialized: bool, existing_row):
        self.is_materialized = is_materialized
        self.existing_row = existing_row
        self.write_statements: list[str] = []

    def execute(self, stmt, params=None):
        sql = str(stmt)
        result = Mock()

        if "FROM messages m" in sql:
            result.fetchone.return_value = (1,) if self.is_materialized else None
        elif "SELECT id, status" in sql and "FROM inbound_event_queue" in sql:
            result.fetchone.return_value = self.existing_row
        elif sql.strip().upper().startswith(("UPDATE", "INSERT")):
            self.write_statements.append(sql)
            result.fetchone.return_value = None
        else:
            result.fetchone.return_value = None

        return result


class TestEnqueueEventDoesNotClobberPendingRows:
    def test_pending_row_returns_none_without_any_write(self):
        db = FakeDBForEnqueue(is_materialized=False, existing_row=(42, "pending"))

        result = inbound_queue_repo.enqueue_event(
            db,
            source="webhook",
            provider_message_id="AAMk-1",
            mailbox_email="buzon@icbf.gov.co",
            payload={"some": "notification"},
        )

        assert result is None
        assert db.write_statements == []

    def test_processing_row_returns_none_without_any_write(self):
        db = FakeDBForEnqueue(is_materialized=False, existing_row=(42, "processing"))

        result = inbound_queue_repo.enqueue_event(
            db,
            source="delta",
            provider_message_id="AAMk-1",
            mailbox_email="buzon@icbf.gov.co",
            payload=None,
        )

        assert result is None
        assert db.write_statements == []

    def test_failed_row_is_recycled_with_a_write(self):
        """Contraste: a diferencia de pending/processing, una fila
        'failed' SÍ se recicla (comportamiento ya existente, no
        cambiado) - confirma que el test anterior no está vacío por
        error de mock."""
        db = FakeDBForEnqueue(is_materialized=False, existing_row=(42, "failed"))

        result = inbound_queue_repo.enqueue_event(
            db,
            source="webhook",
            provider_message_id="AAMk-1",
            mailbox_email="buzon@icbf.gov.co",
            payload=None,
        )

        assert result == 42
        assert len(db.write_statements) == 1
        assert "UPDATE" in db.write_statements[0].upper()
