from __future__ import annotations

import logging
import httpx
from typing import Any

from app.auth_graph import graph_auth

logger = logging.getLogger("app.graph_client")

GRAPH_BASE = "https://graph.microsoft.com/v1.0"


class GraphClient:
    def __init__(self) -> None:
        self._timeout = 60

    async def _headers(self) -> dict[str, str]:
        token = await graph_auth.get_token()
        return {
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
        }

    async def get_message(self, mailbox_email: str, message_id: str) -> dict[str, Any]:
        """
        Trae el mensaje completo. Para adjuntos, Graph puede enviar metadata;
        si existe contentBytes lo usamos; si no, hacemos llamada por attachment.
        """
        url = f"{GRAPH_BASE}/users/{mailbox_email}/messages/{message_id}"
        params = {
            # bodyPreview no sirve para guardar HTML completo, pedimos body
            "$select": ",".join([
                "id",
                "subject",
                "receivedDateTime",
                "sentDateTime",
                "from",
                "toRecipients",
                "ccRecipients",
                "bccRecipients",
                "body",
                "internetMessageId",
                "inReplyTo",
                "conversationId",
                "hasAttachments",
            ])
        }
        headers = await self._headers()
        async with httpx.AsyncClient(timeout=self._timeout) as client:
            resp = await client.get(url, headers=headers, params=params)
            if resp.status_code != 200:
                logger.error("get_message failed: %s %s", resp.status_code, resp.text)
                raise RuntimeError("Graph get_message failed")
            return resp.json()

    async def list_attachments(self, mailbox_email: str, message_id: str) -> list[dict[str, Any]]:
        url = f"{GRAPH_BASE}/users/{mailbox_email}/messages/{message_id}/attachments"
        headers = await self._headers()
        async with httpx.AsyncClient(timeout=self._timeout) as client:
            resp = await client.get(url, headers=headers)
            if resp.status_code != 200:
                logger.error("list_attachments failed: %s %s", resp.status_code, resp.text)
                raise RuntimeError("Graph list_attachments failed")
            data = resp.json()
            return data.get("value", [])

    async def get_attachment(self, mailbox_email: str, message_id: str, attachment_id: str) -> dict[str, Any]:
        url = f"{GRAPH_BASE}/users/{mailbox_email}/messages/{message_id}/attachments/{attachment_id}"
        headers = await self._headers()
        async with httpx.AsyncClient(timeout=self._timeout) as client:
            resp = await client.get(url, headers=headers)
            if resp.status_code != 200:
                logger.error("get_attachment failed: %s %s", resp.status_code, resp.text)
                raise RuntimeError("Graph get_attachment failed")
            return resp.json()


graph_client = GraphClient()
