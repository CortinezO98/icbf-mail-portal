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
from zoneinfo import ZoneInfo

logger = logging.getLogger("app.sync_service")


def _iso_to_dt(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        dt = datetime.fromisoformat(value.replace("Z", "+00:00"))

        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)

        bogota_tz = ZoneInfo("America/Bogota")
        dt_bogota = dt.astimezone(bogota_tz)

        return dt_bogota.replace(tzinfo=None)
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


def _header_value(headers: list[dict[str, Any]] | None, name: str) -> str | None:
    """
    Busca un header por nombre (case-insensitive) en internetMessageHeaders.
    Devuelve el value como string o None.
    """
    if not headers:
        return None
    lname = name.lower()
    for h in headers:
        if not isinstance(h, dict):
            continue
        if str(h.get("name", "")).lower() == lname:
            v = h.get("value")
            return str(v) if v else None
    return None


def _is_ndr(subject: str, from_email: str, body_html: str | None, body_text: str | None) -> bool:
    s = (subject or "").strip().lower()
    f = (from_email or "").strip().lower()
    b = ((body_text or "") + " " + (body_html or "")).lower()

    if s.startswith("no se puede entregar:") or s.startswith("undeliverable:"):
        return True

    if "microsoftexchange" in f or f.startswith("postmaster@"):
        return True

    if "delivery has failed" in b or "no se pudo entregar" in b:
        return True

    if "5.2.2" in b or "quotaexceeded" in b or "mailbox full" in b:
        return True

    return False


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

    if last.startswith("messages(") and last.endswith(")"):
        inner = last[len("messages("): -1].strip().strip("'").strip('"')
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
    notifications = [n for n in notifications if _should_accept(n)]

    if not notifications:
        logger.info("No valid notifications to process")
        return

    logger.info("Processing notifications=%s", len(notifications))

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
    msg = await graph_client.get_message(mb, message_id)

    provider_message_id = str(msg.get("id") or message_id)
    subject = str(msg.get("subject") or "(Sin asunto)")

    from_obj = msg.get("from") or {}
    from_email = ((from_obj.get("emailAddress") or {}).get("address")) or "unknown@unknown"
    from_name = ((from_obj.get("emailAddress") or {}).get("name")) or None

    to_emails = _emails(msg.get("toRecipients"))
    cc_emails = _emails(msg.get("ccRecipients"))
    bcc_emails = _emails(msg.get("bccRecipients"))

    received_at = (
        _iso_to_dt(msg.get("receivedDateTime"))
        or _iso_to_dt(msg.get("createdDateTime"))
        or datetime.now(timezone.utc).replace(tzinfo=None)
    )
    sent_at = _iso_to_dt(msg.get("sentDateTime"))

    go_live = settings.go_live_dt() if hasattr(settings, "go_live_dt") else None
    if go_live and received_at and received_at < go_live:
        logger.warning(
            "Skipping message before GO_LIVE_AT | msg=%s received_at=%s go_live=%s",
            provider_message_id, received_at, go_live
        )
        return

    internet_message_id = msg.get("internetMessageId")
    conversation_id = msg.get("conversationId")

    internet_headers = msg.get("internetMessageHeaders") or []
    in_reply_to = _header_value(internet_headers, "In-Reply-To")

    body = msg.get("body") or {}
    body_type = (body.get("contentType") or "").lower()
    body_content = body.get("content") or ""

    body_html = body_content if body_type == "html" else None
    body_text = body_content if body_type != "html" else None

    has_attachments = 1 if msg.get("hasAttachments") else 0

    if _is_ndr(
        subject=subject,
        from_email=str(from_email),
        body_html=body_html,
        body_text=body_text,
    ):
        logger.warning(
            "Skipping NDR/Undeliverable message_id=%s subject=%s from=%s",
            provider_message_id,
            subject,
            from_email,
        )
        return

    case_id: int | None = None
    message_pk_existing: int | None = None
    message_pk: int | None = None
    should_process_attachments_even_if_dedupe = False
    event_type: str = "CASE_CREATED"

    assigned_agent_id: int | None = None

    with get_db_session() as db:
        existing = _get_existing_message_row(db, mailbox_id=mailbox_id, provider_message_id=provider_message_id)
        if existing:
            message_pk_existing, case_id_existing, has_att_db = existing
            message_pk = message_pk_existing
            logger.info("DEDUPE_HIT | message_id=%s | case_id=%s", provider_message_id, case_id_existing)

            if has_att_db or has_attachments:
                if _attachments_count(db, message_pk=message_pk_existing) == 0:
                    should_process_attachments_even_if_dedupe = True
                    logger.warning(
                        "Attachments missing in DB for message_id=%s -> will fetch now",
                        provider_message_id
                    )

            case_id = case_id_existing

        else:
            if conversation_id:
                case_id = _find_case_by_conversation(
                    db,
                    mailbox_id=mailbox_id,
                    conversation_id=str(conversation_id)
                )

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

                logger.info(
                    "CASE_CREATED | case_id=%s | message_id=%s | from_email=%s | subject=%s",
                    case_id,
                    provider_message_id,
                    from_email,
                    subject,
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

            row = db.execute(
                text("""
                    SELECT id
                    FROM messages
                    WHERE mailbox_id = :mbid AND provider_message_id = :pmid
                    ORDER BY id DESC
                    LIMIT 1
                """),
                {"mbid": mailbox_id, "pmid": provider_message_id},
            ).fetchone()

            message_pk = int(row[0]) if row else None

            logger.info(
                "MESSAGE_INSERTED | message_pk=%s | case_id=%s | message_id=%s | received_at=%s",
                message_pk,
                case_id,
                provider_message_id,
                received_at,
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

            if event_type == "CASE_CREATED":
                assigned_agent_id = repos.auto_assign_case(db, case_id=case_id)
                if assigned_agent_id:
                    logger.info("AUTO_ASSIGNED | case_id=%s | agent_id=%s", case_id, assigned_agent_id)
                else:
                    logger.warning("No eligible agents found for auto-assign case_id=%s", case_id)

                    repos.insert_case_event(
                        db,
                        case_id=case_id,
                        actor_user_id=None,
                        source="WORKER",
                        event_type="AUTO_ASSIGN_SKIPPED",
                        from_status_id=None,
                        to_status_id=None,
                        details={"reason": "no_eligible_agents"},
                    )

    if (has_attachments or should_process_attachments_even_if_dedupe) and message_pk is not None:
        await _process_attachments(
            mailbox_email=mb,
            graph_message_id=message_id,
            message_pk=message_pk,
            provider_message_id=provider_message_id,
        )

    if settings.NOTIFICATIONS_ENABLED and assigned_agent_id and case_id is not None:
        await _notify_agent_new_case(case_id=case_id, agent_id=assigned_agent_id, case_subject=subject)


async def _notify_agent_new_case(*, case_id: int, agent_id: int, case_subject: str) -> None:
    """
    Envía notificación al agente asignado + trazabilidad en case_events.
    MVP: cooldown 5 min por agente (anti-spam).
    """
    mb = settings.MAILBOX_EMAIL
    if not mb:
        return

    ua = f"worker/{settings.WORKER_INSTANCE_ID}"

    with get_db_session() as db:
        to_email = repos.get_user_email(db, user_id=agent_id)

        if not to_email:
            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="NOTIFY_SKIPPED_NO_EMAIL",
                from_status_id=None,
                to_status_id=None,
                details={"agent_id": agent_id, "reason": "agent_has_no_email", "mode": "cooldown_mvp"},
                ip_address=None,
                user_agent=ua,
            )
            logger.info("Agent has no email user_id=%s -> skip notify", agent_id)
            return

        allowed = repos.try_mark_agent_notified(db, user_id=agent_id, cooldown_minutes=5)
        if not allowed:
            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="NOTIFY_SKIPPED_COOLDOWN",
                from_status_id=None,
                to_status_id=None,
                details={
                    "agent_id": agent_id,
                    "to_email": to_email,
                    "cooldown_minutes": 5,
                    "reason": "cooldown_active",
                    "mode": "cooldown_mvp",
                },
                ip_address=None,
                user_agent=ua,
            )
            logger.info("Notify cooldown active agent_id=%s -> skip notify", agent_id)
            return

    portal = (getattr(settings, "PORTAL_BASE_URL", "") or "").rstrip("/")
    link = f"{portal}/cases/{case_id}" if portal else ""

    subject = "Nuevo caso asignado en Portal ICBF"

    body_html = f"""
      <div style="font-family:Segoe UI, Arial, sans-serif; font-size:14px; color:#111">
        <p>Hola,</p>
        <p>Se te ha asignado un <strong>nuevo caso</strong> en el Portal de Gestión de Correo.</p>
        <p style="margin:12px 0">
          <strong>ID del caso:</strong> {case_id}<br>
          <strong>Asunto:</strong> {case_subject}
        </p>
        {"<p>Puedes revisarlo aquí: <a href='" + link + "'>" + link + "</a></p>" if link else "<p>Ingresa al portal para revisarlo.</p>"}
        <p style="color:#666; font-size:12px; margin-top:18px">
          Este mensaje es automático. Para evitar spam, el sistema limita notificaciones por ventana de tiempo.
        </p>
      </div>
    """

    try:
        await graph_client.send_mail(mb, to_email=to_email, subject=subject, body_html=body_html)

        with get_db_session() as db:
            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="NOTIFY_SENT",
                from_status_id=None,
                to_status_id=None,
                details={
                    "agent_id": agent_id,
                    "to_email": to_email,
                    "subject": subject,
                    "portal_link": link,
                    "mode": "cooldown_mvp",
                },
                ip_address=None,
                user_agent=ua,
            )

        logger.info("Notification sent case_id=%s agent_id=%s to=%s", case_id, agent_id, to_email)

    except Exception as e:
        with get_db_session() as db:
            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="NOTIFY_FAILED",
                from_status_id=None,
                to_status_id=None,
                details={
                    "agent_id": agent_id,
                    "to_email": to_email,
                    "subject": subject,
                    "portal_link": link,
                    "error": str(e)[:500],
                    "mode": "cooldown_mvp",
                },
                ip_address=None,
                user_agent=ua,
            )

        logger.warning("Notification failed case_id=%s agent_id=%s err=%s", case_id, agent_id, e)


async def _process_attachments(*, mailbox_email: str, graph_message_id: str, message_pk: int, provider_message_id: str) -> None:
    """
    ✅ Importante:
    - NO hacemos awaits dentro de una transacción DB.
    - Primero traemos/decodificamos/guardamos a disco.
    - Luego persistimos rows en attachments.
    """
    atts = await graph_client.list_attachments(mailbox_email, graph_message_id)
    if not atts:
        return

    prepared: list[dict[str, Any]] = []

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
            full = await graph_client.get_attachment(mailbox_email, graph_message_id, att_id)
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

        if not stored.sha256:
            logger.warning("Attachment without sha256 filename=%s -> skip", filename)
            continue

        prepared.append(
            {
                "filename": filename,
                "graph_attachment_id": att_id,
                "content_type": stored.content_type,
                "size_bytes": stored.size_bytes,
                "sha256": stored.sha256,
                "is_inline": is_inline,
                "content_id": (str(content_id) if content_id else None),
                "storage_path": stored.storage_path,
            }
        )

        logger.info(
            "Prepared attachment msg_pk=%s graph_msg_id=%s filename=%s bytes=%s sha=%s",
            message_pk, graph_message_id, filename, stored.size_bytes, stored.sha256[:12]
        )

    if not prepared:
        return

    with get_db_session() as db:
        existing_count = _attachments_count(db, message_pk=message_pk)
        if existing_count > 0:
            logger.info(
                "Attachments already exist for message_pk=%s count=%s -> will continue (idempotent insert)",
                message_pk,
                existing_count,
            )

        for p in prepared:
            repos.insert_attachment(
                db,
                message_id_pk=message_pk,
                graph_attachment_id=p.get("graph_attachment_id"),
                filename=p["filename"],
                content_type=p["content_type"],
                size_bytes=p["size_bytes"],
                sha256=p["sha256"],
                is_inline=p["is_inline"],
                content_id=p["content_id"],
                storage_path=p["storage_path"],
            )

    logger.info("Inserted attachments=%s for provider_message_id=%s", len(prepared), provider_message_id)


async def process_message_id_async(message_id: str, source: str = "unknown") -> None:
    """
    Entry-point unificado para webhook, delta, reconcile y manual.
    Reusa la misma lógica de _process_single_message.
    """
    if not settings.MAILBOX_EMAIL:
        logger.error("MAILBOX_EMAIL missing - cannot process message_id=%s", message_id)
        return

    logger.info("MESSAGE_PROCESS_START | source=%s | message_id=%s", source, message_id)

    with get_db_session() as db:
        mailbox_id = repos.get_or_create_mailbox(db, settings.MAILBOX_EMAIL)

    await _process_single_message(
        mailbox_id=mailbox_id,
        message_id=message_id,
    )