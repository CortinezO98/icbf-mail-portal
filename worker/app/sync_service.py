from __future__ import annotations

import logging
from datetime import datetime
from typing import Any

from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.settings import settings
from app.graph_client import graph_client
from app.storage import decode_graph_content_bytes, save_attachment_bytes
from app import repos

logger = logging.getLogger("app.sync_service")


def _parse_dt(dt_str: str | None) -> datetime | None:
    if not dt_str:
        return None
    # Graph típicamente: 2026-01-15T17:19:44Z
    try:
        if dt_str.endswith("Z"):
            dt_str = dt_str.replace("Z", "+00:00")
        return datetime.fromisoformat(dt_str).replace(tzinfo=None)
    except Exception:
        return None


def _emails_list(recipients: list[dict[str, Any]] | None) -> str | None:
    if not recipients:
        return None
    emails = []
    for r in recipients:
        addr = (((r or {}).get("emailAddress") or {}).get("address")) if r else None
        if addr:
            emails.append(addr)
    return ",".join(emails) if emails else None


class SyncService:
    async def process_notifications(self, db: Session, payload: dict[str, Any]) -> dict[str, Any]:
        """
        payload = {"value": [ ...notifications... ]}
        """
        notifs = payload.get("value") or []
        processed = 0
        skipped = 0
        errors = 0

        mailbox_email = settings.MAILBOX_EMAIL
        mailbox_id = repos.get_or_create_mailbox(db, mailbox_email)

        for n in notifs:
            try:
                # 1) Validación clientState
                client_state = n.get("clientState")
                if settings.GRAPH_CLIENT_STATE and client_state != settings.GRAPH_CLIENT_STATE:
                    skipped += 1
                    continue

                # 2) Identificar messageId
                rd = n.get("resourceData") or {}
                message_id = rd.get("id")
                if not message_id:
                    # a veces viene en resource
                    skipped += 1
                    continue

                # 3) Pull mensaje a Graph
                msg = await graph_client.get_message(mailbox_email, message_id)

                provider_message_id = str(msg.get("id", message_id))
                # Dedupe: si ya existe message para ese mailbox+provider_message_id -> skip
                existing_case = repos.get_case_by_message_dedupe(db, mailbox_id, provider_message_id)
                if existing_case:
                    skipped += 1
                    continue

                subject = (msg.get("subject") or "(Sin asunto)")[:255]

                from_obj = (msg.get("from") or {}).get("emailAddress") or {}
                from_email = (from_obj.get("address") or "").strip() or "unknown@unknown"

                requester_name = (from_obj.get("name") or None)

                received_at = _parse_dt(msg.get("receivedDateTime")) or datetime.utcnow()
                sent_at = _parse_dt(msg.get("sentDateTime"))

                to_emails = _emails_list(msg.get("toRecipients"))
                cc_emails = _emails_list(msg.get("ccRecipients"))
                bcc_emails = _emails_list(msg.get("bccRecipients"))

                conversation_id = msg.get("conversationId")
                internet_message_id = msg.get("internetMessageId")
                in_reply_to = msg.get("inReplyTo")

                body = msg.get("body") or {}
                body_ct = (body.get("contentType") or "").upper()
                body_content = body.get("content")

                body_text = body_content if body_ct == "TEXT" else None
                body_html = body_content if body_ct == "HTML" else None

                has_attachments = 1 if msg.get("hasAttachments") else 0

                # 4) Crear caso
                case_id = repos.create_case(
                    db,
                    mailbox_id=mailbox_id,
                    subject=subject,
                    requester_email=from_email,
                    requester_name=requester_name,
                    received_at=received_at,
                )

                # 5) Insert message (dedupe lo impone UNIQUE en DB)
                try:
                    repos.insert_message_inbound(
                        db,
                        case_id=case_id,
                        mailbox_id=mailbox_id,
                        folder_id=None,
                        provider_message_id=provider_message_id,
                        conversation_id=conversation_id,
                        internet_message_id=internet_message_id,
                        in_reply_to=in_reply_to,
                        from_email=from_email,
                        to_emails=to_emails,
                        cc_emails=cc_emails,
                        bcc_emails=bcc_emails,
                        subject=subject,
                        body_text=body_text,
                        body_html=body_html,
                        received_at=received_at,
                        sent_at=sent_at,
                        has_attachments=has_attachments,
                        processed_by_worker="worker",
                    )
                except IntegrityError:
                    # ya existía (race)
                    skipped += 1
                    continue

                message_pk = repos.get_message_pk(db, mailbox_id, provider_message_id)

                # 6) Adjuntos
                if has_attachments:
                    attachments = await graph_client.list_attachments(mailbox_email, provider_message_id)
                    # Necesitamos case_number para ruta (lo buscamos)
                    row = db.execute(
                        repos.text("SELECT case_number FROM cases WHERE id = :id LIMIT 1"),
                        {"id": case_id},
                    ).fetchone()
                    case_number = str(row[0]) if row else f"ICBF-{datetime.utcnow().year}-000000"

                    for a in attachments:
                        odata_type = (a.get("@odata.type") or "").lower()
                        if "fileattachment" not in odata_type:
                            # itemAttachment / referenceAttachment etc -> (MVP) skip
                            continue

                        filename = a.get("name") or "attachment.bin"
                        content_type = a.get("contentType") or "application/octet-stream"
                        size_bytes = int(a.get("size") or 0)

                        # contentBytes puede no venir; si no viene, hacemos get_attachment
                        content_b64 = a.get("contentBytes")
                        if not content_b64:
                            att_id = a.get("id")
                            if not att_id:
                                continue
                            full = await graph_client.get_attachment(mailbox_email, provider_message_id, att_id)
                            content_b64 = full.get("contentBytes")
                            size_bytes = int(full.get("size") or size_bytes)
                            content_type = full.get("contentType") or content_type
                            filename = full.get("name") or filename

                        if not content_b64:
                            continue

                        content_bytes = decode_graph_content_bytes(content_b64)
                        storage_path, sha256, real_size = save_attachment_bytes(
                            case_number=case_number,
                            message_id=provider_message_id,
                            filename=filename,
                            content_bytes=content_bytes,
                        )

                        repos.insert_attachment(
                            db,
                            message_id_pk=message_pk,
                            filename=filename,
                            content_type=content_type,
                            size_bytes=real_size,
                            sha256=sha256,
                            is_inline=int(a.get("isInline") or 0),
                            content_id=a.get("contentId"),
                            storage_path=storage_path,
                        )

                # 7) Auditoría
                repos.insert_case_event(
                    db,
                    case_id=case_id,
                    actor_user_id=None,
                    source="WORKER",
                    event_type="EMAIL_RECEIVED",
                    from_status_id=None,
                    to_status_id=repos.get_status_id_by_code(db, "NUEVO"),
                    details={
                        "provider_message_id": provider_message_id,
                        "conversation_id": conversation_id,
                        "from_email": from_email,
                        "subject": subject,
                        "has_attachments": bool(has_attachments),
                    },
                )

                processed += 1

            except Exception as e:
                errors += 1
                logger.exception("Error processing notification: %s", str(e))

        return {"processed": processed, "skipped": skipped, "errors": errors}


sync_service = SyncService()
