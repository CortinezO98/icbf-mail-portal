from __future__ import annotations

import logging
from pathlib import Path
from typing import Optional, Dict, Any

import msal

log = logging.getLogger("app.auth_graph")


class GraphAuth:
    def __init__(
        self,
        tenant_id: str,
        client_id: str,
        client_secret: str = "",
        cert_private_key_path: str = "",
        cert_thumbprint: str = "",
    ) -> None:
        self.tenant_id = tenant_id
        self.client_id = client_id
        self.client_secret = client_secret
        self.cert_private_key_path = cert_private_key_path
        self.cert_thumbprint = cert_thumbprint

        self.authority = f"https://login.microsoftonline.com/{tenant_id}"
        self.scope = ["https://graph.microsoft.com/.default"]

        self._app = self._build_app()

    def _build_app(self) -> msal.ConfidentialClientApplication:
        if self.cert_private_key_path and self.cert_thumbprint:
            key_pem = Path(self.cert_private_key_path).read_text(encoding="utf-8")
            cred = {"private_key": key_pem, "thumbprint": self.cert_thumbprint}
            log.info("GraphAuth using certificate-based auth")
            return msal.ConfidentialClientApplication(
                client_id=self.client_id,
                authority=self.authority,
                client_credential=cred,
            )

        if self.client_secret:
            log.info("GraphAuth using client-secret auth")
            return msal.ConfidentialClientApplication(
                client_id=self.client_id,
                authority=self.authority,
                client_credential=self.client_secret,
            )

        raise RuntimeError("Graph credentials missing. Set GRAPH_CLIENT_SECRET or certificate settings.")

    def get_access_token(self) -> str:
        # Try cache first
        result = self._app.acquire_token_silent(self.scope, account=None)
        if not result:
            result = self._app.acquire_token_for_client(scopes=self.scope)

        if "access_token" not in result:
            raise RuntimeError(f"Graph token error: {result.get('error')} - {result.get('error_description')}")

        return str(result["access_token"])
