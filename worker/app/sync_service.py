from __future__ import annotations

import base64
import logging
from datetime import datetime, timezone
from typing import Any, Iterable

from sqlalchemy import text

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
    out: list[str] = []
    for r in recipients:
        ea = (r.get("emailAddress") or {})
        addr = ea.get("address")
        if addr:
            out.append(str(addr))
    return ";".join(out) if out else None


def _extract_message_id(notification: dict[str, Any]) -> str | None:
    """
    Preferimos resourceData.id (Graph normalmente lo trae).
    Si no viene, intentamos parsear resource.
    """
    rd = notification.get("resourceData")
    if isinstance(rd, dict) and rd.get("id"):
        return str(rd["id"])

    res = notification.get("resource")
    if not isinstance(res, str) or not res:
        return None

    last = res.rstrip("/").split("/")[-1]

    # soporta messages('ID') o messages(ID)
    if last.startswith("messages(") and last.endswith(")"):
        inner = last[len("messages(") : -1].strip().strip("'").strip('"')
        return inner or None

    return last or None


def _normalize_notifications(payload_or_list: dict[str, Any] | list[dict[str, Any]]) -> list[dict[str, Any]]:
    """
    Webhook manda {"value":[...]}.
    Pero dejamos compatibilidad si alguien llama directo con una lista.
    """
    if isinstance(payload_or_list, list):
        return [n for n in payload_or_list if isinstance(n, dict)]
    if isinstance(payload_or_list, dict):
        value = payload_or_list.get("value") or []
        if isinstance(value, list):
            return [n for n in value if isinstance(n, dict)]
    return []


def _should_accept(notification: dict[str, Any]) -> bool:
    """
    Defensa extra: el webhook ya filtró por clientState,
    pero aquí evitamos procesar basura si llega algo sucio.
    """
    cs = notification.get("clientState")
    return bool(cs) and cs == settings.GRAPH_CLIENT_STATE


def _find_case_by_conversation(db, *, mailbox_id: int, conversation_id: str) -> int | None:
    # Forma simple sin depender de repos.get_case_by_conversation_id:
    row = db.execute(
        text("""
            SELECT case_id
            FROM messages
            WHERE mailbox_id = :mbid AND conversation_id = :cid
            ORDER BY id DESC
            LIMIT 1
        """),
        {"mbid": mailbox_id, "cid": conversation_id},
    ).fetchone()
    return int(row[0]) if row else None


def _touch_case_activity(db, *, case_id: int, last_activity_at: datetime) -> None:
    # También sin depender de repos.touch_case_activity
    db.execute(
        text("""
            UPDATE cases
            SET last_activity_at = :dt, updated_at = NOW(6)
            WHERE id = :id
            LIMIT 1
        """),
        {"dt": last_activity_at, "id": case_id},
    )


def _get_existing_message_row(db, *, mailbox_id: int, provider_message_id: str) -> tuple[int, int, int] | None:
    """
    Retorna (message_pk, case_id, has_attachments)
    """
    row = db.execute(
        text("""
            SELECT id, case_id, COALESCE(has_attachments, 0)
            FROM messages
            WHERE mailbox_id = :mbid AND provider_message_id = :pmid
            LIMIT 1
        """),
        {"mbid": mailbox_id, "pmid": provider_message_id},
    ).fetchone()
    if not row:
        return None
    return int(row[0]), int(row[1]), int(row[2])


def _attachments_count(db, *, message_pk: int) -> int:
    row = db.execute(
        text("SELECT COUNT(*) FROM attachments WHERE message_id = :mid"),
        {"mid": message_pk},
    ).fetchone()
    return int(row[0]) if row else 0


async def process_notifications_async(payload_or_list: dict[str, Any] | list[dict[str, Any]]) -> None:
    """
    Entrada esperada desde webhook:
      {"value":[{notification},{notification},...]}
    """
    if not settings.MAILBOX_EMAIL:
        logger.error("MAILBOX_EMAIL missing - cannot process")
        return

    notifications = _normalize_notifications(payload_or_list)

    # filtro extra por clientState (defensa)
    notifications = [n for n in notifications if _should_accept(n)]

    if not notifications:
        logger.info("No valid notifications to process")
        return

    logger.info("Processing notifications=%s", len(notifications))

    # mailbox_id una sola vez
    with get_db_session() as db:
        mailbox_id = repos.get_or_create_mailbox(db, settings.MAILBOX_EMAIL)

    for n in notifications:
        msg_id = _extract_message_id(n)
        if not msg_id:
            logger.warning("Skipping notification without message id")
            continue

        try:
            await _process_single_message(mailbox_id=mailbox_id, message_id=msg_id)
        except Exception as e:
            logger.exception("Failed processing message_id=%s err=%s", msg_id, e)


async def _process_single_message(*, mailbox_id: int, message_id: str) -> None:
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

    # 2) Persistencia (transacción corta y SIN awaits)
    case_id: int | None = None
    message_pk_existing: int | None = None
    should_process_attachments_even_if_dedupe = False
    event_type: str = "CASE_CREATED"

    with get_db_session() as db:
        # ✅ Dedupe duro por provider_message_id
        existing = _get_existing_message_row(db, mailbox_id=mailbox_id, provider_message_id=provider_message_id)
        if existing:
            message_pk_existing, case_id_existing, has_att_db = existing
            logger.info("Dedupe hit message_id=%s case_id=%s", provider_message_id, case_id_existing)

            # Si el mensaje indica adjuntos y no hay adjuntos guardados, intentamos recuperarlos
            if has_att_db or has_attachments:
                if _attachments_count(db, message_pk=message_pk_existing) == 0:
                    should_process_attachments_even_if_dedupe = True
                    logger.warning("Attachments missing in DB for message_id=%s -> will fetch now", provider_message_id)

            # Si fue dedupe, no creamos nada nuevo
            case_id = case_id_existing
        else:
            # Reusar caso por conversationId (hilo)
            if conversation_id:
                case_id = _find_case_by_conversation(db, mailbox_id=mailbox_id, conversation_id=str(conversation_id))

            if case_id:
                event_type = "MESSAGE_ADDED"
            else:
                case_id = repos.create_case(
                    db,
                    mailbox_id=mailbox_id,
                    subject=subject,
                    requester_email=str(from_email),
                    requester_name=(str(from_name) if from_name else None),
                    received_at=received_at,
                )
                event_type = "CASE_CREATED"

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

            _touch_case_activity(db, case_id=case_id, last_activity_at=received_at)

            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type=event_type,
                from_status_id=None,
                to_status_id=None,
                details={
                    "provider_message_id": provider_message_id,
                    "conversation_id": (str(conversation_id) if conversation_id else None),
                    "from_email": from_email,
                    "subject": subject,
                },
            )

    # 3) Attachments fuera de la transacción
    if (has_attachments or should_process_attachments_even_if_dedupe) and mailbox_id is not None:
        await _process_attachments(
            mailbox_id=mailbox_id,
            provider_message_id=provider_message_id,
            mailbox_email=mb,
            message_id=message_id,
        )


async def _process_attachments(*, mailbox_id: int, provider_message_id: str, mailbox_email: str, message_id: str) -> None:
    """
    ✅ Importante:
    - NO hacemos awaits dentro de una transacción DB.
    - Primero traemos/decodificamos/guardamos a disco.
    - Luego persistimos rows en attachments.
    """
    atts = await graph_client.list_attachments(mailbox_email, message_id)
    if not atts:
        return

    prepared: list[dict[str, Any]] = []

    # 1) Preparar (descargar/decodificar/guardar en storage) fuera de DB
    for a in atts:
        odata_type = str(a.get("@odata.type") or "")
        att_id = str(a.get("id") or "")

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

        if size and len(raw) != size:
            size = len(raw)

        try:
            stored = save_attachment_bytes(filename=filename, content_bytes=raw, content_type=content_type)
        except Exception as e:
            logger.warning("Attachment rejected filename=%s reason=%s", filename, e)
            continue

        prepared.append(
            {
                "filename": filename,
                "content_type": stored.content_type,
                "size_bytes": stored.size_bytes,
                "sha256": stored.sha256,
                "is_inline": is_inline,
                "content_id": (str(content_id) if content_id else None),
                "storage_path": stored.storage_path,
            }
        )

        logger.info("Prepared attachment filename=%s bytes=%s sha=%s", filename, stored.size_bytes, stored.sha256[:12])

    if not prepared:
        return

    # 2) Persistir en DB (una sola transacción corta)
    with get_db_session() as db:
        message_pk = repos.get_message_pk(db, mailbox_id, provider_message_id)

        # Evita duplicar adjuntos si ya estaban
        existing_count = _attachments_count(db, message_pk=message_pk)
        if existing_count > 0:
            logger.info("Attachments already exist for message_pk=%s count=%s -> skip insert", message_pk, existing_count)
            return

        for p in prepared:
            repos.insert_attachment(
                db,
                message_id_pk=message_pk,
                filename=p["filename"],
                content_type=p["content_type"],
                size_bytes=p["size_bytes"],
                sha256=p["sha256"],
                is_inline=p["is_inline"],
                content_id=p["content_id"],
                storage_path=p["storage_path"],
            )

    logger.info("Inserted attachments=%s for provider_message_id=%s", len(prepared), provider_message_id)

async def process_message_id_async(message_id: str) -> None:
    """
    Entry-point para Delta backstop: procesa 1 correo por message_id.
    Reusa la misma lógica de _process_single_message.
    """
    if not settings.MAILBOX_EMAIL:
        logger.error("MAILBOX_EMAIL missing - cannot process message_id=%s", message_id)
        return

    # mailbox_id una sola vez
    with get_db_session() as db:
        mailbox_id = repos.get_or_create_mailbox(db, settings.MAILBOX_EMAIL)

    await _process_single_message(
        mailbox_id=mailbox_id,
        message_id=message_id,
    )
