from __future__ import annotations

import asyncio
import logging
from typing import Any

import httpx

from app.auth_graph import graph_auth

logger = logging.getLogger("app.graph_client")

GRAPH_BASE = "https://graph.microsoft.com/v1.0"

FOLDER_CODE_TO_GRAPH = {
    "INBOX": "Inbox",
    "DRAFTS": "Drafts",
    "SENT": "SentItems",
    "DELETED": "DeletedItems",
    "JUNK": "JunkEmail",
}


class GraphClient:
    def __init__(self) -> None:
        self._timeout = 60

    async def _headers(self) -> dict[str, str]:
        token = await graph_auth.get_token()
        return {
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
        }

    async def _request(self, method: str, url: str, **kwargs) -> httpx.Response:
        headers = await self._headers()
        kwargs.setdefault("headers", headers)
        kwargs.setdefault("timeout", self._timeout)

        async with httpx.AsyncClient() as client:
            resp: httpx.Response | None = None
            for attempt in range(1, 4):
                resp = await client.request(method, url, **kwargs)

                if resp.status_code in (429, 500, 502, 503, 504):
                    retry_after = resp.headers.get("Retry-After")
                    sleep_s = int(retry_after) if (retry_after and retry_after.isdigit()) else attempt * 2
                    logger.warning(
                        "Graph retry %s %s status=%s sleep=%ss",
                        method, url, resp.status_code, sleep_s
                    )
                    await asyncio.sleep(sleep_s)
                    continue

                return resp

        return resp  # type: ignore[return-value]

    # ============================================================
    # Generic GET by URL (deltaLink / nextLink support) ✅ NUEVO
    # ============================================================

    async def get_by_url(self, url: str) -> dict[str, Any]:
        """
        Graph deltaLink/nextLink traen URL completa.
        Aquí hacemos GET directo a esa URL.
        """
        resp = await self._request("GET", url)
        if resp.status_code != 200:
            logger.error("get_by_url failed: %s %s", resp.status_code, resp.text)
            raise RuntimeError(f"Graph get_by_url failed status={resp.status_code}")
        return resp.json()

    # ============================================================
    # Messages + attachments
    # ============================================================

    async def get_message(self, mailbox_email: str, message_id: str) -> dict[str, Any]:
        url = f"{GRAPH_BASE}/users/{mailbox_email}/messages/{message_id}"

        # OJO:
        # - NO existe "inReplyTo" en Graph v1.0 => NO lo selecciones
        # - Si necesitas "In-Reply-To", viene como header dentro de internetMessageHeaders
        params = {
            "$select": ",".join(
                [
                    "id",
                    "subject",
                    "receivedDateTime",
                    "sentDateTime",
                    "from",
                    "toRecipients",
                    "ccRecipients",
                    "bccRecipients",
                    "replyTo",
                    "body",
                    "internetMessageId",
                    "internetMessageHeaders",  # ✅ para leer In-Reply-To desde headers
                    "conversationId",
                    "hasAttachments",
                ]
            )
        }

        resp = await self._request("GET", url, params=params)
        if resp.status_code != 200:
            logger.error("get_message failed: %s %s", resp.status_code, resp.text)
            raise RuntimeError("Graph get_message failed")
        return resp.json()

    async def list_attachments(self, mailbox_email: str, message_id: str) -> list[dict[str, Any]]:
        url = f"{GRAPH_BASE}/users/{mailbox_email}/messages/{message_id}/attachments"
        resp = await self._request("GET", url)
        if resp.status_code != 200:
            logger.error("list_attachments failed: %s %s", resp.status_code, resp.text)
            raise RuntimeError("Graph list_attachments failed")
        data = resp.json()
        return data.get("value", [])

    async def get_attachment(self, mailbox_email: str, message_id: str, attachment_id: str) -> dict[str, Any]:
        url = f"{GRAPH_BASE}/users/{mailbox_email}/messages/{message_id}/attachments/{attachment_id}"
        resp = await self._request("GET", url)
        if resp.status_code != 200:
            logger.error("get_attachment failed: %s %s", resp.status_code, resp.text)
            raise RuntimeError("Graph get_attachment failed")
        return resp.json()

    # ============================
    # Subscriptions (webhooks)
    # ============================

    async def create_subscription(
        self,
        *,
        change_type: str,
        notification_url: str,
        resource: str,
        expiration_datetime_iso: str,
        client_state: str,
    ) -> dict[str, Any]:
        url = f"{GRAPH_BASE}/subscriptions"
        payload = {
            "changeType": change_type,
            "notificationUrl": notification_url,
            "resource": resource,
            "expirationDateTime": expiration_datetime_iso,
            "clientState": client_state,
            "latestSupportedTlsVersion": "v1_2",
        }
        resp = await self._request("POST", url, json=payload)
        if resp.status_code not in (200, 201):
            logger.error("create_subscription failed: %s %s", resp.status_code, resp.text)
            raise RuntimeError("Graph create_subscription failed")
        return resp.json()

    async def renew_subscription(self, subscription_id: str, expiration_datetime_iso: str) -> dict[str, Any]:
        url = f"{GRAPH_BASE}/subscriptions/{subscription_id}"
        payload = {"expirationDateTime": expiration_datetime_iso}
        resp = await self._request("PATCH", url, json=payload)
        if resp.status_code != 200:
            logger.error("renew_subscription failed: %s %s", resp.status_code, resp.text)
            raise RuntimeError("Graph renew_subscription failed")
        return resp.json()

    async def get_subscription(self, subscription_id: str) -> dict[str, Any]:
        url = f"{GRAPH_BASE}/subscriptions/{subscription_id}"
        resp = await self._request("GET", url)
        if resp.status_code != 200:
            logger.error("get_subscription failed: %s %s", resp.status_code, resp.text)
            raise RuntimeError("Graph get_subscription failed")
        return resp.json()

    # ============================
    # Delta helpers (Backstop)
    # ============================

    def _folder_ref(self, *, folder_code: str, graph_folder_id: str | None) -> str:
        if graph_folder_id:
            return graph_folder_id
        return FOLDER_CODE_TO_GRAPH.get(folder_code.upper(), "Inbox")

    async def messages_delta_page(
        self,
        *,
        mailbox_email: str,
        folder_code: str,
        graph_folder_id: str | None,
        url: str | None,
        page_size: int = 50,
    ) -> tuple[int, dict[str, Any]]:
        """
        Returns (status_code, json).
        If url is provided, it is used directly (nextLink/deltaLink).
        Otherwise starts a fresh delta query.
        """
        if url:
            resp = await self._request("GET", url)
        else:
            folder_ref = self._folder_ref(folder_code=folder_code, graph_folder_id=graph_folder_id)
            delta_url = f"{GRAPH_BASE}/users/{mailbox_email}/mailFolders('{folder_ref}')/messages/delta"
            params = {
                "$top": str(int(page_size)),
                "$select": "id",  # ✅ delta minimalista
            }
            headers = await self._headers()
            headers["Prefer"] = f"odata.maxpagesize={int(page_size)}"
            resp = await self._request("GET", delta_url, params=params, headers=headers)

        status = resp.status_code
        try:
            data = resp.json()
        except Exception:
            data = {"raw": resp.text}

        return status, data


graph_client = GraphClient()
