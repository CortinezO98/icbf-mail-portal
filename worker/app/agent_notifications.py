from __future__ import annotations

import logging

from app.db import get_db_session
from app.graph_client import graph_client
from app.settings import settings
from app import repos

logger = logging.getLogger("app.agent_notifications")


async def notify_agent_new_case(*, case_id: int, agent_id: int, case_subject: str) -> None:
    """Preserva la notificación existente, ahora disparada por assignment worker."""
    if not settings.NOTIFICATIONS_ENABLED:
        return

    mailbox = settings.MAILBOX_EMAIL
    if not mailbox:
        return

    user_agent = f"assignment-worker/{settings.WORKER_INSTANCE_ID}"

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
                details={"agent_id": agent_id, "reason": "agent_has_no_email", "mode": "assignment_worker"},
                ip_address=None,
                user_agent=user_agent,
            )
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
                    "mode": "assignment_worker",
                },
                ip_address=None,
                user_agent=user_agent,
            )
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
      </div>
    """

    try:
        await graph_client.send_mail(
            mailbox,
            to_email=to_email,
            subject=subject,
            body_html=body_html,
        )
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
                    "mode": "assignment_worker",
                },
                ip_address=None,
                user_agent=user_agent,
            )
    except Exception as exc:
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
                    "error": str(exc)[:500],
                    "mode": "assignment_worker",
                },
                ip_address=None,
                user_agent=user_agent,
            )
        logger.warning("Notification failed case_id=%s agent_id=%s err=%s", case_id, agent_id, exc)
