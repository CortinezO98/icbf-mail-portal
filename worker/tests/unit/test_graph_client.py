from __future__ import annotations

"""
Tests unitarios para app.graph_client.GraphClient.

Cubre dos cambios de esta fase:
  - list_attachments() ahora pagina @odata.nextLink hasta agotar la
    colección, en vez de devolver solo la primera página.
  - El cliente httpx ahora es persistente (uno por proceso, reutilizado
    entre llamadas) en vez de crear uno nuevo por request.

Todas las respuestas HTTP se simulan con dobles de prueba (SimpleNamespace)
- no se abre ninguna conexión de red real.
"""

from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest

from app.graph_client import GraphClient

pytestmark = pytest.mark.unit


def _fake_response(*, status_code=200, json_data=None, headers=None, text=""):
    headers = headers or {"content-type": "application/json"}

    def _json():
        if json_data is None:
            raise ValueError("no json configured for this fake response")
        return json_data

    return SimpleNamespace(
        status_code=status_code,
        headers=headers,
        text=text,
        content=text.encode() if text else b"{}",
        json=_json,
    )


@pytest.fixture
def client():
    return GraphClient()


# ---------------------------------------------------------------------------
# list_attachments: paginación
# ---------------------------------------------------------------------------

class TestListAttachmentsPagination:
    async def test_single_page_no_next_link(self, monkeypatch, client):
        page = _fake_response(json_data={"value": [{"id": "att-1"}, {"id": "att-2"}]})
        request_mock = AsyncMock(return_value=page)
        monkeypatch.setattr(client, "_request", request_mock)

        result = await client.list_attachments("buzon@icbf.gov.co", "msg-1")

        assert result == [{"id": "att-1"}, {"id": "att-2"}]
        request_mock.assert_called_once()

    async def test_follows_next_link_across_multiple_pages(self, monkeypatch, client):
        page1 = _fake_response(
            json_data={
                "value": [{"id": "att-1"}],
                "@odata.nextLink": "https://graph.microsoft.com/v1.0/next-page-1",
            }
        )
        page2 = _fake_response(
            json_data={
                "value": [{"id": "att-2"}],
                "@odata.nextLink": "https://graph.microsoft.com/v1.0/next-page-2",
            }
        )
        page3 = _fake_response(json_data={"value": [{"id": "att-3"}]})

        request_mock = AsyncMock(side_effect=[page1, page2, page3])
        monkeypatch.setattr(client, "_request", request_mock)

        result = await client.list_attachments("buzon@icbf.gov.co", "msg-1")

        assert [a["id"] for a in result] == ["att-1", "att-2", "att-3"]
        assert request_mock.call_count == 3
        # La segunda y tercera llamada deben usar la URL del nextLink
        # devuelta por la página anterior, no reconstruir la URL base.
        second_call_url = request_mock.call_args_list[1].args[1]
        third_call_url = request_mock.call_args_list[2].args[1]
        assert second_call_url == "https://graph.microsoft.com/v1.0/next-page-1"
        assert third_call_url == "https://graph.microsoft.com/v1.0/next-page-2"

    async def test_empty_first_page_returns_empty_list(self, monkeypatch, client):
        page = _fake_response(json_data={"value": []})
        monkeypatch.setattr(client, "_request", AsyncMock(return_value=page))

        result = await client.list_attachments("buzon@icbf.gov.co", "msg-1")

        assert result == []

    async def test_non_200_status_raises(self, monkeypatch, client):
        page = _fake_response(status_code=500, text="internal error")
        monkeypatch.setattr(client, "_request", AsyncMock(return_value=page))

        with pytest.raises(RuntimeError, match="list_attachments failed"):
            await client.list_attachments("buzon@icbf.gov.co", "msg-1")

    async def test_bad_content_type_retries_then_succeeds(self, monkeypatch, client):
        bad_page = _fake_response(
            status_code=200, headers={"content-type": "text/html"}, text="<html/>"
        )
        good_page = _fake_response(json_data={"value": [{"id": "att-1"}]})

        request_mock = AsyncMock(side_effect=[bad_page, good_page])
        monkeypatch.setattr(client, "_request", request_mock)

        async def _no_sleep(_seconds):
            return None

        monkeypatch.setattr("app.graph_client.asyncio.sleep", _no_sleep)

        result = await client.list_attachments("buzon@icbf.gov.co", "msg-1")

        assert result == [{"id": "att-1"}]
        assert request_mock.call_count == 2

    async def test_json_decode_failure_exhausts_retries_and_raises(
        self, monkeypatch, client
    ):
        def _broken_json():
            raise __import__("json").JSONDecodeError("bad", "doc", 0)

        broken_page = SimpleNamespace(
            status_code=200,
            headers={"content-type": "application/json"},
            text="not json",
            content=b"not json",
            json=_broken_json,
        )
        request_mock = AsyncMock(return_value=broken_page)
        monkeypatch.setattr(client, "_request", request_mock)

        async def _no_sleep(_seconds):
            return None

        monkeypatch.setattr("app.graph_client.asyncio.sleep", _no_sleep)

        with pytest.raises(RuntimeError, match="JSON decode failed after retries"):
            await client.list_attachments("buzon@icbf.gov.co", "msg-1")

        assert request_mock.call_count == 3  # 3 intentos por página, como antes


# ---------------------------------------------------------------------------
# Cliente httpx persistente
# ---------------------------------------------------------------------------

class TestPersistentClientLifecycle:
    async def test_get_client_reuses_same_instance(self, client):
        c1 = await client._get_client()
        c2 = await client._get_client()
        assert c1 is c2
        await client.aclose()

    async def test_aclose_allows_creating_a_fresh_client_afterwards(self, client):
        c1 = await client._get_client()
        await client.aclose()
        c2 = await client._get_client()
        assert c1 is not c2
        assert not c2.is_closed
        await client.aclose()

    async def test_aclose_is_safe_to_call_when_never_opened(self, client):
        # No debe lanzar aunque nunca se haya llamado _get_client() antes.
        await client.aclose()

    async def test_aclose_is_idempotent(self, client):
        await client._get_client()
        await client.aclose()
        await client.aclose()  # segunda llamada no debe fallar
