from __future__ import annotations

"""
Tests unitarios para app.repos.upsert_attachment (D1-D).

Estrategia: mockear db.execute() e inspeccionar el SQL/params generados,
sin base de datos real - consistente con el resto de tests de repos de
acceso crudo (test_inbound_queue_repo.py). El comportamiento real de
MariaDB ante ON DUPLICATE KEY UPDATE (identidad preservada, PK estable,
created_at intacto) se valida por separado con integración contra
MariaDB 10.11 real (ver informe de validación D1-D), no aquí.

Cobertura de esta suite:
  D, E, F - guard runtime (segunda barrera, independiente de D1-A)
  I       - message_id/graph_attachment_id nunca en la cláusula UPDATE
  + estructura general del UPSERT (ON DUPLICATE KEY UPDATE, campos
    actualizables, normalización de graph_attachment_id)
"""

from unittest.mock import Mock

import pytest

import app.repos as repos

pytestmark = pytest.mark.unit


class FakeDB:
    def __init__(self):
        self.calls: list[tuple[str, dict]] = []

    def execute(self, stmt, params=None):
        self.calls.append((str(stmt), params or {}))
        return Mock()


def _valid_kwargs(**overrides):
    kwargs = dict(
        message_id_pk=1,
        graph_attachment_id="graph-A",
        filename="doc.pdf",
        content_type="application/pdf",
        size_bytes=1000,
        sha256="a" * 64,
        is_inline=0,
        content_id=None,
        storage_path="ab/cd/doc.pdf",
    )
    kwargs.update(overrides)
    return kwargs


# ---------------------------------------------------------------------------
# D, E, F - guard runtime: segunda barrera de defensa en profundidad,
# independiente de D1-A (sync_service.py). Debe rechazar ANTES de tocar
# la base de datos, sin importar qué haga el caller.
# ---------------------------------------------------------------------------

class TestRuntimeGuard:
    def test_none_graph_id_raises_value_error(self):
        db = FakeDB()

        with pytest.raises(ValueError, match="graph_attachment_id is required"):
            repos.upsert_attachment(db, **_valid_kwargs(graph_attachment_id=None))

        assert db.calls == []

    def test_empty_string_graph_id_raises_value_error(self):
        db = FakeDB()

        with pytest.raises(ValueError, match="graph_attachment_id is required"):
            repos.upsert_attachment(db, **_valid_kwargs(graph_attachment_id=""))

        assert db.calls == []

    def test_whitespace_only_graph_id_raises_value_error(self):
        db = FakeDB()

        with pytest.raises(ValueError, match="graph_attachment_id is required"):
            repos.upsert_attachment(db, **_valid_kwargs(graph_attachment_id="   "))

        assert db.calls == []

    def test_valid_graph_id_does_not_raise(self):
        db = FakeDB()
        repos.upsert_attachment(db, **_valid_kwargs(graph_attachment_id="graph-A"))
        assert len(db.calls) == 1

    def test_graph_id_with_surrounding_whitespace_is_stripped(self):
        db = FakeDB()
        repos.upsert_attachment(db, **_valid_kwargs(graph_attachment_id="  graph-A  "))

        _, params = db.calls[0]
        assert params["graph_attachment_id"] == "graph-A"


# ---------------------------------------------------------------------------
# Estructura del SQL: UPSERT real, no INSERT IGNORE
# ---------------------------------------------------------------------------

class TestUpsertSqlStructure:
    def test_uses_on_duplicate_key_update_not_insert_ignore(self):
        db = FakeDB()
        repos.upsert_attachment(db, **_valid_kwargs())

        sql, _ = db.calls[0]
        assert "ON DUPLICATE KEY UPDATE" in sql
        assert "INSERT IGNORE" not in sql

    def test_updatable_fields_present_in_update_clause(self):
        db = FakeDB()
        repos.upsert_attachment(db, **_valid_kwargs())

        sql, _ = db.calls[0]
        update_clause = sql.split("ON DUPLICATE KEY UPDATE", 1)[1]

        for field in [
            "filename",
            "content_type",
            "size_bytes",
            "sha256",
            "is_inline",
            "content_id",
            "storage_path",
        ]:
            assert field in update_clause, f"{field} debería ser actualizable"

    def test_identity_and_created_at_never_in_update_clause(self):
        """Test I: message_id y graph_attachment_id (la identidad) y
        created_at (fecha del primer insert) NUNCA deben aparecer del
        lado derecho de ON DUPLICATE KEY UPDATE - solo en el INSERT
        inicial."""
        db = FakeDB()
        repos.upsert_attachment(db, **_valid_kwargs())

        sql, _ = db.calls[0]
        update_clause = sql.split("ON DUPLICATE KEY UPDATE", 1)[1]

        # message_id y graph_attachment_id no deben ser asignados dentro
        # del UPDATE (no debe existir "message_id = ..." ni
        # "graph_attachment_id = ..." en esa cláusula).
        assert "message_id " not in update_clause.replace("\n", " ")
        assert "graph_attachment_id " not in update_clause.replace("\n", " ")
        assert "created_at" not in update_clause

    def test_all_params_forwarded_correctly(self):
        db = FakeDB()
        repos.upsert_attachment(
            db,
            **_valid_kwargs(
                message_id_pk=42,
                filename="reporte.pdf",
                content_type="application/pdf",
                size_bytes=12345,
                sha256="b" * 64,
                is_inline=1,
                content_id="cid-1",
                storage_path="xy/zw/reporte.pdf",
            ),
        )

        _, params = db.calls[0]
        assert params["message_id"] == 42
        assert params["filename"] == "reporte.pdf"
        assert params["content_type"] == "application/pdf"
        assert params["size_bytes"] == 12345
        assert params["sha256"] == "b" * 64
        assert params["is_inline"] == 1
        assert params["content_id"] == "cid-1"
        assert params["storage_path"] == "xy/zw/reporte.pdf"

    def test_long_fields_are_truncated_same_as_before(self):
        db = FakeDB()
        repos.upsert_attachment(
            db,
            **_valid_kwargs(
                filename="x" * 300,
                content_type="y" * 200,
                content_id="z" * 250,
                storage_path="w" * 700,
            ),
        )

        _, params = db.calls[0]
        assert len(params["filename"]) == 255
        assert len(params["content_type"]) == 120
        assert len(params["content_id"]) == 190
        assert len(params["storage_path"]) == 600
