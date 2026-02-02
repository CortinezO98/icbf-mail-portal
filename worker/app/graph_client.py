from __future__ import annotations

import logging
import re
from typing import Any, Dict, List, Optional
from datetime import datetime

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

from .auth_graph import GraphAuth

log = logging.getLogger("app.graph_client")


def _requests_session() -> requests.Session:
    s = requests.Session()
    retry = Retry(
        total=5,
        backoff_factor=0.5,
        status_forcelist=(429, 500, 502, 503, 504),
        allowed_methods=("GET", "POST"),
        raise_on_status=False,
    )
    s.mount("https://", HTTPAdapter(max_retries=retry))
    return s


def parse_message_id_from_notification(n: Dict[str, Any]) -> Optional[str]:
    # 1) resourceData.id
    rd = n.get("resourceData") or {}
    if isinstance(rd, dict) and rd.get("id"):
        return str(rd["id"])

    # 2) parse from resource path .../messages/{id}
    resource = n.get("resource") or ""
    m = re.search(r"/messages/([A-Za-z0-9\-_=]+)", resource)
    if m:
        return m.group(1)

    return None


class GraphClient:
    BASE = "https://graph.microsoft.com/v1.0"

    def __init__(self, auth: GraphAuth) -> None:
        self.auth = auth
        self.session = _requests_session()

    def _headers(self) -> Dict[str, str]:
        token = self.auth.get_access_token()
        return {"Authorization": f"Bearer {token}", "Accept": "application/json"}

    def get_message(self, mailbox_email: str, message_id: str) -> Dict[str, Any]:
        # We use /users/{mail}/messages/{id} for app permissions
        url = f"{self.BASE}/users/{mailbox_email}/messages/{message_id}"
        params = {
            "$select": "id,conversationId,internetMessageId,inReplyTo,subject,from,toRecipients,ccRecipients,bccRecipients,receivedDateTime,sentDateTime,hasAttachments,body,bodyPreview",
        }
        r = self.session.get(url, headers=self._headers(), params=params, timeout=30)
        if r.status_code >= 300:
            raise RuntimeError(f"Graph get_message failed {r.status_code}: {r.text[:400]}")
        return r.json()

    def list_attachments(self, mailbox_email: str, message_id: str) -> List[Dict[str, Any]]:
        url = f"{self.BASE}/users/{mailbox_email}/messages/{message_id}/attachments"
        r = self.session.get(url, headers=self._headers(), timeout=30)
        if r.status_code >= 300:
            raise RuntimeError(f"Graph list_attachments failed {r.status_code}: {r.text[:400]}")
        data = r.json()
        return data.get("value", []) if isinstance(data, dict) else []

    def get_attachment(self, mailbox_email: str, message_id: str, attachment_id: str) -> Dict[str, Any]:
        url = f"{self.BASE}/users/{mailbox_email}/messages/{message_id}/attachments/{attachment_id}"
        r = self.session.get(url, headers=self._headers(), timeout=30)
        if r.status_code >= 300:
            raise RuntimeError(f"Graph get_attachment failed {r.status_code}: {r.text[:400]}")
        return r.json()
