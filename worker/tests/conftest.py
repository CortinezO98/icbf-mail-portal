from __future__ import annotations

"""
Fixtures compartidas para la suite de tests unitarios del worker.

Principio de diseno (SWEBOK v4, Software Testing KA - test doubles):
los tests de esta suite son unitarios: nunca abren una conexion real a
MySQL ni llaman a Microsoft Graph. Toda dependencia externa se sustituye
por un doble de prueba (fake/mock) inyectado via monkeypatch en el modulo
bajo prueba, siguiendo la convencion "patch where it's used" (se parchea
el nombre importado en el modulo que lo usa, no en su modulo de origen).
"""

from contextlib import contextmanager
from typing import Any

import pytest


class FakeDBSession:
    """Sentinel reconocible que representa una sesion de SQLAlchemy.

    No implementa ningun metodo real de Session a proposito: si algun
    codigo bajo prueba intenta usarla como una sesion de verdad (ej.
    db.execute(...)) sin que el test la haya configurado explicitamente,
    debe fallar de forma ruidosa (AttributeError) en vez de simular
    silenciosamente una base de datos que no existe.
    """

    def __repr__(self) -> str:  # pragma: no cover - solo ayuda de debug
        return "<FakeDBSession>"


@pytest.fixture
def fake_db_session():
    """Devuelve (session, context_manager_factory).

    `context_manager_factory` es una funcion sin argumentos que, al
    llamarse, se comporta igual que `app.db.get_db_session()`: es un
    context manager que al entrar entrega `session` y al salir no hace
    nada especial (no hay commit/rollback real que verificar en un test
    unitario - eso es responsabilidad de un test de integracion contra
    una DB real).
    """
    session = FakeDBSession()

    @contextmanager
    def _get_db_session():
        yield session

    return session, _get_db_session


@pytest.fixture
def make_queue_item():
    """Fabrica de items con la forma que devuelve
    inbound_queue_repo.claim_pending_events() (dict con id, source,
    provider_message_id, attempts, mailbox_email).
    """

    def _make(
        *,
        event_id: int = 1,
        source: str = "webhook",
        provider_message_id: str = "AAMkAG-test-message-id",
        mailbox_email: str = "buzon@icbf.gov.co",
        attempts: int = 0,
    ) -> dict[str, Any]:
        return {
            "id": event_id,
            "source": source,
            "provider_message_id": provider_message_id,
            "mailbox_email": mailbox_email,
            "attempts": attempts,
        }

    return _make
