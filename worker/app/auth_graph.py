from __future__ import annotations

import time
import logging
import httpx
from app.settings import settings

logger = logging.getLogger("app.auth_graph")


class GraphAuth:
    """
    Token OAuth2 client_credentials (secret).
    Cachea token hasta expirar.
    """
    def __init__(self) -> None:
        self._access_token: str | None = None
        self._expires_at: float = 0.0

    async def get_token(self) -> str:
        now = time.time()
        if self._access_token and now < (self._expires_at - 60):
            return self._access_token

        if not settings.GRAPH_TENANT_ID or not settings.GRAPH_CLIENT_ID or not settings.GRAPH_CLIENT_SECRET:
            raise RuntimeError("Graph credentials missing: set GRAPH_TENANT_ID, GRAPH_CLIENT_ID, GRAPH_CLIENT_SECRET")

        token_url = f"https://login.microsoftonline.com/{settings.GRAPH_TENANT_ID}/oauth2/v2.0/token"
        data = {
            "client_id": settings.GRAPH_CLIENT_ID,
            "client_secret": settings.GRAPH_CLIENT_SECRET,
            "grant_type": "client_credentials",
            "scope": "https://graph.microsoft.com/.default",
        }

        async with httpx.AsyncClient(timeout=30) as client:
            resp = await client.post(token_url, data=data)
            if resp.status_code != 200:
                logger.error("Token request failed: %s %s", resp.status_code, resp.text)
                raise RuntimeError("Graph token request failed")
            payload = resp.json()

        self._access_token = payload["access_token"]
        self._expires_at = now + float(payload.get("expires_in", 3599))
        return self._access_token


graph_auth = GraphAuth()
