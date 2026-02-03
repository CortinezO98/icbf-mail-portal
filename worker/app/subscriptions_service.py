from __future__ import annotations

import logging
from datetime import datetime, timedelta, timezone

from app.settings import settings
from app.graph_client import graph_client
from app.db import get_db_session
from app import repos

logger = logging.getLogger("app.subscriptions_service")


def _utc_iso(dt: datetime) -> str:
    dt = dt.astimezone(timezone.utc)
    return dt.replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _resolve_resource() -> str:
    return settings.SUBSCRIPTION_RESOURCE.replace("{MAILBOX_EMAIL}", settings.MAILBOX_EMAIL)


def _notification_url() -> str:
    base = (settings.PUBLIC_BASE_URL or "").rstrip("/")
    if not base:
        raise RuntimeError("PUBLIC_BASE_URL is required")
    if not base.lower().startswith("https://"):
        raise RuntimeError("PUBLIC_BASE_URL must be HTTPS")
    return f"{base}/graph/webhook"


def _needs_renew(expires_at: datetime) -> bool:
    # En tu BD MySQL normalmente viene naive (sin tz). Conservamos ese contrato.
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    delta = expires_at - now
    return delta.total_seconds() <= int(settings.SUB_RENEW_THRESHOLD_MINUTES) * 60


async def ensure_subscription(dry_run: bool = False) -> dict:
    if not settings.MAILBOX_EMAIL:
        raise RuntimeError("MAILBOX_EMAIL is required")

    notification_url = _notification_url()
    resource = _resolve_resource()

    # OJO: Graph limita el expiry (ya viste el error). Mantén <= 10070 min.
    exp_dt = datetime.now(timezone.utc) + timedelta(minutes=int(settings.SUBSCRIPTION_LIFETIME_MINUTES))

    # ✅ Dry-run explícito
    if dry_run:
        return {
            "action": "dry_run",
            "notification_url": notification_url,
            "resource": resource,
            "changeType": settings.SUBSCRIPTION_CHANGE_TYPE,
            "would_expire_at": _utc_iso(exp_dt),
            "note": "Dry run: no se llamó a Graph (dry_run=1).",
        }

    # ✅ Resolver mailbox_id desde BD (sin depender de MAILBOX_ID en .env)
    with get_db_session() as db:
        mailbox_id = repos.get_or_create_mailbox(db, settings.MAILBOX_EMAIL)
        repos.ensure_graph_subscriptions_table(db)  # NO-OP (según ajuste en repos.py)
        current = repos.get_active_subscription(db, mailbox_id=mailbox_id, resource=resource)

    # ✅ Crear si no existe
    if not current:
        logger.info("Creating Graph subscription | url=%s | resource=%s", notification_url, resource)
        created = await graph_client.create_subscription(
            change_type=settings.SUBSCRIPTION_CHANGE_TYPE,
            notification_url=notification_url,
            resource=resource,
            expiration_datetime_iso=_utc_iso(exp_dt),
            client_state=settings.GRAPH_CLIENT_STATE,
        )

        sid = created["id"]
        exp = created["expirationDateTime"]
        exp_parsed = datetime.fromisoformat(exp.replace("Z", "+00:00")).astimezone(timezone.utc).replace(tzinfo=None)

        with get_db_session() as db:
            mailbox_id = repos.get_or_create_mailbox(db, settings.MAILBOX_EMAIL)
            repos.upsert_subscription(
                db,
                subscription_id=sid,
                mailbox_id=mailbox_id,
                resource=resource,
                notification_url=notification_url,
                expires_at=exp_parsed,
                status="ACTIVE",
            )

        return {"action": "created", "subscription_id": sid, "expiration": exp}

    # current viene como Row: (subscription_id, expires_at, status)
    sid = str(current[0])
    expires_at = current[1]

    # ✅ Renovar si está por vencer
    if _needs_renew(expires_at):
        logger.info("Renewing Graph subscription | id=%s", sid)
        new_exp_dt = datetime.now(timezone.utc) + timedelta(minutes=int(settings.SUBSCRIPTION_LIFETIME_MINUTES))
        renewed = await graph_client.renew_subscription(sid, _utc_iso(new_exp_dt))

        exp = renewed["expirationDateTime"]
        exp_parsed = datetime.fromisoformat(exp.replace("Z", "+00:00")).astimezone(timezone.utc).replace(tzinfo=None)

        with get_db_session() as db:
            mailbox_id = repos.get_or_create_mailbox(db, settings.MAILBOX_EMAIL)
            repos.upsert_subscription(
                db,
                subscription_id=sid,
                mailbox_id=mailbox_id,
                resource=resource,
                notification_url=notification_url,
                expires_at=exp_parsed,
                status="ACTIVE",
            )

        return {"action": "renewed", "subscription_id": sid, "expiration": exp}

    return {"action": "ok", "subscription_id": sid, "expiration": str(expires_at)}
