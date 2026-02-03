from __future__ import annotations

import base64
import logging
from datetime import datetime, timezone
from typing import Any

from app.settings import settings
from app.graph_client import graph_client
from app.db import get_db_session
from app import repos
from app.storage import save_attachment_bytes

logger = logging.getLogger("app.sync_service")


def _iso_to_dt(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone(timezone.utc).replace(tzinfo=None)
    except Exception:
        return None


def _emails(recipients: list[dict[str, Any]] | None) -> str | None:
    if not recipients:
        return None
    out = []
    for r in recipients:
        ea = (r.get("emailAddress") or {})
        addr = ea.get("address")
        if addr:
            out.append(str(addr))
    return ";".join(out) if out else None


def _extract_message_id(notification: dict[str, Any]) -> str | None:
    rd = notification.get("resourceData")
    if isinstance(rd, dict) and rd.get("id"):
        return str(rd["id"])
    res = notification.get("resource")
    if isinstance(res, str) and res:
        return res.rstrip("/").split("/")[-1]
    return None


async def process_notifications_async(payload: dict) -> None:
    notifications = payload.get("value", []) or []
    count = len(notifications)

    if not settings.MAILBOX_EMAIL:
        logger.error("MAILBOX_EMAIL missing - cannot process")
        return

    logger.info("Received notifications=%s", count)

    for n in notifications:
        msg_id = _extract_message_id(n)
        if not msg_id:
            logger.warning("Skipping notification without message id")
            continue

        try:
            await _process_single_message(message_id=msg_id)
        except Exception as e:
            logger.exception("Failed processing message_id=%s err=%s", msg_id, e)


async def _process_single_message(message_id: str) -> None:
    mb = settings.MAILBOX_EMAIL

    # 1) Pull full message from Graph
    msg = await graph_client.get_message(mb, message_id)

    provider_message_id = str(msg.get("id") or message_id)
    subject = str(msg.get("subject") or "(Sin asunto)")

    from_obj = msg.get("from") or {}
    from_email = ((from_obj.get("emailAddress") or {}).get("address")) or "unknown@unknown"
    from_name = ((from_obj.get("emailAddress") or {}).get("name")) or None

    to_emails = _emails(msg.get("toRecipients"))
    cc_emails = _emails(msg.get("ccRecipients"))
    bcc_emails = _emails(msg.get("bccRecipients"))

    received_at = _iso_to_dt(msg.get("receivedDateTime")) or datetime.now(timezone.utc).replace(tzinfo=None)
    sent_at = _iso_to_dt(msg.get("sentDateTime"))

    internet_message_id = msg.get("internetMessageId")
    conversation_id = msg.get("conversationId")
    in_reply_to = msg.get("inReplyTo")

    body = msg.get("body") or {}
    body_type = (body.get("contentType") or "").lower()
    body_content = body.get("content") or ""

    body_html = body_content if body_type == "html" else None
    body_text = body_content if body_type != "html" else None

    has_attachments = 1 if msg.get("hasAttachments") else 0

    with get_db_session() as db:
        mailbox_id = repos.get_or_create_mailbox(db, mb)

        # 2) Dedupe
        existing_case_id = repos.get_case_by_message_dedupe(db, mailbox_id, provider_message_id)
        if existing_case_id:
            logger.info("Dedupe hit message_id=%s case_id=%s", provider_message_id, existing_case_id)
            return

        # 3) Create case + insert message
        case_id = repos.create_case(
            db,
            mailbox_id=mailbox_id,
            subject=subject,
            requester_email=str(from_email),
            requester_name=(str(from_name) if from_name else None),
            received_at=received_at,
        )

        repos.insert_message_inbound(
            db,
            case_id=case_id,
            mailbox_id=mailbox_id,
            folder_id=None,
            provider_message_id=provider_message_id,
            conversation_id=(str(conversation_id) if conversation_id else None),
            internet_message_id=(str(internet_message_id) if internet_message_id else None),
            in_reply_to=(str(in_reply_to) if in_reply_to else None),
            from_email=str(from_email),
            to_emails=to_emails,
            cc_emails=cc_emails,
            bcc_emails=bcc_emails,
            subject=subject,
            body_text=body_text,
            body_html=body_html,
            received_at=received_at,
            sent_at=sent_at,
            has_attachments=has_attachments,
            processed_by_worker=settings.WORKER_INSTANCE_ID,
        )

        repos.insert_case_event(
            db,
            case_id=case_id,
            actor_user_id=None,
            source="WORKER",
            event_type="CASE_CREATED",
            from_status_id=None,
            to_status_id=None,
            details={
                "provider_message_id": provider_message_id,
                "from_email": from_email,
                "subject": subject,
            },
        )

        # 4) Attachments
        if has_attachments:
            await _process_attachments(db, mailbox_id, provider_message_id, mb, message_id)


async def _process_attachments(db, mailbox_id: int, provider_message_id: str, mailbox_email: str, message_id: str) -> None:
    atts = await graph_client.list_attachments(mailbox_email, message_id)
    if not atts:
        return

    message_pk = repos.get_message_pk(db, mailbox_id, provider_message_id)

    for a in atts:
        odata_type = str(a.get("@odata.type") or "")
        att_id = str(a.get("id") or "")

        # soportamos fileAttachment; los otros se registran como skip
        if "fileAttachment" not in odata_type:
            logger.warning("Skipping non-file attachment type=%s id=%s", odata_type, att_id)
            continue

        filename = str(a.get("name") or "attachment.bin")
        content_type = str(a.get("contentType") or "application/octet-stream")
        size = int(a.get("size") or 0)
        is_inline = 1 if a.get("isInline") else 0
        content_id = a.get("contentId")

        content_b64 = a.get("contentBytes")
        if not content_b64 and att_id:
            full = await graph_client.get_attachment(mailbox_email, message_id, att_id)
            content_b64 = full.get("contentBytes")

        if not content_b64:
            logger.warning("Attachment without contentBytes filename=%s id=%s", filename, att_id)
            continue

        try:
            raw = base64.b64decode(content_b64)
        except Exception:
            logger.warning("Invalid base64 attachment filename=%s id=%s", filename, att_id)
            continue

        # size check (Graph size sometimes matches)
        if size and len(raw) != size:
            size = len(raw)

        stored = save_attachment_bytes(filename=filename, content_bytes=raw, content_type=content_type)

        repos.insert_attachment(
            db,
            message_id_pk=message_pk,
            filename=filename,
            content_type=stored.content_type,
            size_bytes=stored.size_bytes,
            sha256=stored.sha256,
            is_inline=is_inline,
            content_id=(str(content_id) if content_id else None),
            storage_path=stored.storage_path,
        )

        logger.info("Saved attachment filename=%s bytes=%s sha=%s", filename, stored.size_bytes, stored.sha256[:12])
