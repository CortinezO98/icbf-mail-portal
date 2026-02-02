from __future__ import annotations

import logging
from datetime import datetime, timezone
from typing import Any, Dict, Optional, List

from sqlalchemy.engine import Engine

from .graph_client import GraphClient, parse_message_id_from_notification
from . import repos
from .storage import (
    validate_attachment,
    save_attachment_bytes,
    decode_graph_content_bytes,
)

log = logging.getLogger("app.sync_service")


def _parse_dt(dt_str: Optional[str]) -> Optional[datetime]:
    if not dt_str:
        return None
    # Graph gives ISO8601 like 2026-01-15T15:22:11Z
    # store as UTC naive (DATETIME without TZ)
    d = datetime.fromisoformat(dt_str.replace("Z", "+00:00"))
    d = d.astimezone(timezone.utc).replace(tzinfo=None)
    return d


def _emails_list(recips: Any) -> str:
    if not recips:
        return ""
    out = []
    for r in recips:
        addr = (r.get("emailAddress") or {}).get("address")
        if addr:
            out.append(addr)
    return ",".join(out)


class SyncService:
    def __init__(
        self,
        engine: Engine,
        graph: GraphClient,
        mailbox_email: str,
        attachments_dir: str,
        max_attachment_mb: int,
        allowed_ext: list[str],
        blocked_ext: list[str],
        worker_version: str = "1.0.0",
    ) -> None:
        self.engine = engine
        self.graph = graph
        self.mailbox_email = mailbox_email
        self.attachments_dir = attachments_dir
        self.max_attachment_mb = max_attachment_mb
        self.allowed_ext = allowed_ext
        self.blocked_ext = blocked_ext
        self.worker_version = worker_version

    def process_notifications(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        """
        Graph notifications payload: {"value":[{notification},...]}
        For each notification:
          - pull message from Graph
          - dedupe insert messages
          - create case if new conversation
          - save attachments to disk
          - audit case_events
        """
        notifications = payload.get("value") or []
        processed = 0
        inserted_messages = 0
        skipped_dupe = 0
        attachments_saved = 0
        errors: List[str] = []

        for n in notifications:
            try:
                res = self._process_single_notification(n)
                processed += 1
                inserted_messages += (1 if res.get("inserted_message") else 0)
                skipped_dupe += (1 if res.get("skipped_dupe") else 0)
                attachments_saved += int(res.get("attachments_saved") or 0)
            except Exception as e:
                log.exception("Failed processing notification")
                errors.append(str(e)[:300])

        return {
            "processed": processed,
            "inserted_messages": inserted_messages,
            "skipped_dupe": skipped_dupe,
            "attachments_saved": attachments_saved,
            "errors": errors,
        }

    def _process_single_notification(self, n: Dict[str, Any]) -> Dict[str, Any]:
        msg_id = parse_message_id_from_notification(n)
        if not msg_id:
            raise ValueError("Notification without message id")

        message = self.graph.get_message(self.mailbox_email, msg_id)

        provider_message_id = str(message.get("id"))
        conversation_id = message.get("conversationId")
        internet_message_id = message.get("internetMessageId")
        in_reply_to = message.get("inReplyTo")
        subject = message.get("subject") or "(sin asunto)"

        from_email = ((message.get("from") or {}).get("emailAddress") or {}).get("address") or ""
        to_emails = _emails_list(message.get("toRecipients"))
        cc_emails = _emails_list(message.get("ccRecipients"))
        bcc_emails = _emails_list(message.get("bccRecipients"))

        received_at = _parse_dt(message.get("receivedDateTime"))
        sent_at = _parse_dt(message.get("sentDateTime"))
        has_attachments = 1 if message.get("hasAttachments") else 0

        body = message.get("body") or {}
        body_type = (body.get("contentType") or "").lower()
        body_content = body.get("content") or ""
        body_text = None
        body_html = None
        if body_type == "html":
            body_html = body_content
        else:
            body_text = body_content

        # business decision: IN messages create/attach cases
        direction = "IN"  # webhook we process for inbound folder (MVP)
        requester_email = from_email or "unknown@unknown"
        requester_name = None  # could be enriched later

        with repos.db_conn(self.engine) as conn:
            mailbox_id = repos.ensure_mailbox(conn, self.mailbox_email)

            # case association by conversation
            case_id = None
            if conversation_id:
                case_id = repos.get_case_by_conversation_id(conn, mailbox_id, conversation_id)

            if case_id is None:
                # create new case for new conversation
                if not received_at:
                    received_at = repos.utcnow()
                case_id = repos.create_case(
                    conn=conn,
                    mailbox_id=mailbox_id,
                    subject=subject,
                    requester_email=requester_email,
                    requester_name=requester_name,
                    received_at_utc=received_at,
                )

            inserted, db_message_id = repos.insert_message_dedupe(
                conn=conn,
                case_id=case_id,
                mailbox_id=mailbox_id,
                folder_id=None,
                direction=direction,
                provider_message_id=provider_message_id,
                conversation_id=conversation_id,
                internet_message_id=internet_message_id,
                in_reply_to=in_reply_to,
                from_email=from_email[:190] if from_email else "",
                to_emails=to_emails,
                cc_emails=cc_emails,
                bcc_emails=bcc_emails,
                subject=subject,
                body_text=body_text,
                body_html=body_html,
                received_at_utc=received_at,
                sent_at_utc=sent_at,
                has_attachments=has_attachments,
                processed_by_worker=self.worker_version,
            )

            if not inserted:
                return {"inserted_message": False, "skipped_dupe": True, "attachments_saved": 0}

            # If attachments exist, fetch + store
            saved_count = 0
            if has_attachments:
                # need case_number for path
                row = conn.execute(
                    repos.text("SELECT case_number FROM cases WHERE id = :id LIMIT 1"),
                    {"id": case_id},
                ).fetchone()
                case_number = str(row[0]) if row else f"ICBF-{datetime.utcnow().year}-000000"

                att_list = self.graph.list_attachments(self.mailbox_email, provider_message_id)
                for att in att_list:
                    # Graph attachment types: fileAttachment, itemAttachment, referenceAttachment
                    odata_type = (att.get("@odata.type") or "").lower()
                    if "fileattachment" not in odata_type:
                        continue  # ignore non-file for MVP

                    filename = att.get("name") or "attachment.bin"
                    content_type = att.get("contentType") or "application/octet-stream"
                    size_bytes = int(att.get("size") or 0)
                    is_inline = 1 if att.get("isInline") else 0
                    content_id = att.get("contentId")

                    validate_attachment(
                        filename=filename,
                        size_bytes=size_bytes,
                        max_mb=self.max_attachment_mb,
                        allowed_ext=self.allowed_ext,
                        blocked_ext=self.blocked_ext,
                    )

                    # Pull full attachment to obtain contentBytes
                    att_id = str(att.get("id"))
                    full_att = self.graph.get_attachment(self.mailbox_email, provider_message_id, att_id)
                    content_b64 = full_att.get("contentBytes")
                    if not content_b64:
                        continue

                    content_bytes = decode_graph_content_bytes(content_b64)
                    saved = save_attachment_bytes(
                        base_dir=repos.Path(self.attachments_dir),
                        case_number=case_number,
                        message_id=str(db_message_id),
                        filename=filename,
                        content_bytes=content_bytes,
                    )

                    repos.insert_attachment(
                        conn=conn,
                        message_id=db_message_id,
                        filename=filename,
                        content_type=content_type,
                        size_bytes=saved.size_bytes,
                        sha256=saved.sha256,
                        is_inline=is_inline,
                        content_id=content_id,
                        storage_path=saved.path,
                    )
                    saved_count += 1

            return {"inserted_message": True, "skipped_dupe": False, "attachments_saved": saved_count}
