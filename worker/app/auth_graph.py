from __future__ import annotations

import asyncio
import logging
from pathlib import Path
from typing import Optional

import msal

from app.settings import settings

logger = logging.getLogger("app.auth_graph")


class GraphAuth:
    """
    Auth robusta para Microsoft Graph.

    - DEV: client_secret
    - PROD: certificate (private key PEM + thumbprint)
    """

    def __init__(self) -> None:
        self._app: Optional[msal.ConfidentialClientApplication] = None

    def _build_msal_app(self) -> msal.ConfidentialClientApplication:
        if not settings.GRAPH_TENANT_ID or not settings.GRAPH_CLIENT_ID:
            raise RuntimeError("Missing GRAPH_TENANT_ID / GRAPH_CLIENT_ID")

        authority = f"https://login.microsoftonline.com/{settings.GRAPH_TENANT_ID}"

        # ✅ Prefer certificate if provided
        if settings.GRAPH_CERT_PRIVATE_KEY_PATH and settings.GRAPH_CERT_THUMBPRINT:
            key_path = Path(settings.GRAPH_CERT_PRIVATE_KEY_PATH).expanduser()
            if not key_path.exists():
                raise RuntimeError(f"GRAPH_CERT_PRIVATE_KEY_PATH not found: {key_path}")

            private_key_pem = key_path.read_text(encoding="utf-8")
            thumb = settings.GRAPH_CERT_THUMBPRINT.strip().replace(" ", "").lower()

            client_credential = {
                "private_key": private_key_pem,
                "thumbprint": thumb,
            }
            logger.info("GraphAuth using CERT auth (thumbprint=%s...)", thumb[:8])
        else:
            if not settings.GRAPH_CLIENT_SECRET:
                raise RuntimeError("Missing GRAPH_CLIENT_SECRET (or provide certificate settings)")
            client_credential = settings.GRAPH_CLIENT_SECRET
            logger.warning("GraphAuth using CLIENT SECRET (recommended only for dev)")

        return msal.ConfidentialClientApplication(
            client_id=settings.GRAPH_CLIENT_ID,
            authority=authority,
            client_credential=client_credential,
        )

    def _ensure_app(self) -> msal.ConfidentialClientApplication:
        if self._app is None:
            self._app = self._build_msal_app()
        return self._app

    async def get_token(self) -> str:
        app = self._ensure_app()
        scopes = ["https://graph.microsoft.com/.default"]

        def _acquire() -> dict:
            # Silent uses in-memory cache first
            result = app.acquire_token_silent(scopes=scopes, account=None)
            if not result:
                result = app.acquire_token_for_client(scopes=scopes)
            return result or {}

        result = await asyncio.to_thread(_acquire)

        if "access_token" not in result:
            logger.error("Token acquisition failed: %s", result)
            raise RuntimeError("Graph token request failed")

        return str(result["access_token"])


graph_auth = GraphAuth()
