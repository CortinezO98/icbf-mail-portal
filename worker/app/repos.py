from __future__ import annotations

import json
import logging
from datetime import datetime, timezone
from sqlalchemy import text
from sqlalchemy.orm import Session

logger = logging.getLogger("app.repos")


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


def get_or_create_mailbox(db: Session, email: str) -> int:
    row = db.execute(text("SELECT id FROM mailboxes WHERE email = :email LIMIT 1"), {"email": email}).fetchone()
    if row:
        return int(row[0])
    db.execute(
        text("""
            INSERT INTO mailboxes (email, display_name, tenant_id, graph_user_id, is_active, created_at, updated_at)
            VALUES (:email, NULL, NULL, NULL, 1, NOW(6), NOW(6))
        """),
        {"email": email},
    )
    row2 = db.execute(text("SELECT id FROM mailboxes WHERE email = :email LIMIT 1"), {"email": email}).fetchone()
    return int(row2[0])


def get_status_id_by_code(db: Session, code: str) -> int:
    row = db.execute(text("SELECT id FROM case_statuses WHERE code = :code LIMIT 1"), {"code": code}).fetchone()
    if not row:
        raise RuntimeError(f"Missing status in DB: {code}")
    return int(row[0])


def next_case_number(db: Session) -> str:
    year = datetime.utcnow().year
    db.execute(text("INSERT IGNORE INTO case_sequences (year, last_value, updated_at) VALUES (:y, 0, NOW(6))"), {"y": year})
    row = db.execute(
        text("SELECT last_value FROM case_sequences WHERE year = :y FOR UPDATE"),
        {"y": year},
    ).fetchone()
    last = int(row[0]) if row else 0
    new_val = last + 1
    db.execute(
        text("UPDATE case_sequences SET last_value = :v, updated_at = NOW(6) WHERE year = :y"),
        {"v": new_val, "y": year},
    )
    return f"ICBF-{year}-{new_val:06d}"


def create_case(
    db: Session,
    *,
    mailbox_id: int,
    subject: str,
    requester_email: str,
    requester_name: str | None,
    received_at: datetime,
) -> int:
    status_id = get_status_id_by_code(db, "NUEVO")
    case_number = next_case_number(db)

    db.execute(
        text("""
            INSERT INTO cases (
              mailbox_id, case_number, subject, requester_email, requester_name,
              status_id, category_id,
              assigned_user_id, assigned_group_id,
              received_at, assigned_at, first_response_at, closed_at, last_activity_at,
              is_responded, due_at, sla_state,
              locked_by, locked_at,
              created_at, updated_at
            )
            VALUES (
              :mailbox_id, :case_number, :subject, :requester_email, :requester_name,
              :status_id, NULL,
              NULL, NULL,
              :received_at, NULL, NULL, NULL, :last_activity_at,
              0, NULL, 'OK',
              NULL, NULL,
              NOW(6), NOW(6)
            )
        """),
        {
            "mailbox_id": mailbox_id,
            "case_number": case_number,
            "subject": subject[:255],
            "requester_email": requester_email[:190],
            "requester_name": (requester_name[:190] if requester_name else None),
            "status_id": status_id,
            "received_at": received_at,
            "last_activity_at": received_at,
        },
    )
    row = db.execute(text("SELECT id, case_number FROM cases WHERE case_number = :cn LIMIT 1"), {"cn": case_number}).fetchone()
    return int(row[0])


def get_case_by_message_dedupe(db: Session, mailbox_id: int, provider_message_id: str) -> int | None:
    row = db.execute(
        text("""
            SELECT case_id FROM messages
            WHERE mailbox_id = :mailbox_id AND provider_message_id = :pmid
            LIMIT 1
        """),
        {"mailbox_id": mailbox_id, "pmid": provider_message_id},
    ).fetchone()
    return int(row[0]) if row else None


def insert_message_inbound(
    db: Session,
    *,
    case_id: int,
    mailbox_id: int,
    folder_id: int | None,
    provider_message_id: str,
    conversation_id: str | None,
    internet_message_id: str | None,
    in_reply_to: str | None,
    from_email: str,
    to_emails: str | None,
    cc_emails: str | None,
    bcc_emails: str | None,
    subject: str,
    body_text: str | None,
    body_html: str | None,
    received_at: datetime | None,
    sent_at: datetime | None,
    has_attachments: int,
    processed_by_worker: str | None,
) -> None:
    db.execute(
        text("""
            INSERT INTO messages (
              case_id, mailbox_id, folder_id, direction,
              provider_message_id, conversation_id, internet_message_id, in_reply_to,
              from_email, to_emails, cc_emails, bcc_emails,
              subject, body_text, body_html,
              received_at, sent_at,
              has_attachments, processed_by_worker,
              created_at
            )
            VALUES (
              :case_id, :mailbox_id, :folder_id, 'IN',
              :provider_message_id, :conversation_id, :internet_message_id, :in_reply_to,
              :from_email, :to_emails, :cc_emails, :bcc_emails,
              :subject, :body_text, :body_html,
              :received_at, :sent_at,
              :has_attachments, :processed_by_worker,
              NOW(6)
            )
        """),
        {
            "case_id": case_id,
            "mailbox_id": mailbox_id,
            "folder_id": folder_id,
            "provider_message_id": provider_message_id[:190],
            "conversation_id": (conversation_id[:190] if conversation_id else None),
            "internet_message_id": (internet_message_id[:255] if internet_message_id else None),
            "in_reply_to": (in_reply_to[:255] if in_reply_to else None),
            "from_email": from_email[:190],
            "to_emails": to_emails,
            "cc_emails": cc_emails,
            "bcc_emails": bcc_emails,
            "subject": subject[:255],
            "body_text": body_text,
            "body_html": body_html,
            "received_at": received_at,
            "sent_at": sent_at,
            "has_attachments": int(has_attachments),
            "processed_by_worker": (processed_by_worker[:50] if processed_by_worker else None),
        },
    )


def insert_attachment(
    db: Session,
    *,
    message_id_pk: int,
    filename: str,
    content_type: str,
    size_bytes: int,
    sha256: str | None,
    is_inline: int,
    content_id: str | None,
    storage_path: str,
) -> None:
    db.execute(
        text("""
            INSERT INTO attachments (
              message_id, filename, content_type, size_bytes,
              sha256, is_inline, content_id, storage_path, created_at
            )
            VALUES (
              :message_id, :filename, :content_type, :size_bytes,
              :sha256, :is_inline, :content_id, :storage_path, NOW(6)
            )
        """),
        {
            "message_id": message_id_pk,
            "filename": filename[:255],
            "content_type": content_type[:120],
            "size_bytes": int(size_bytes),
            "sha256": sha256,
            "is_inline": int(is_inline),
            "content_id": (content_id[:190] if content_id else None),
            "storage_path": storage_path[:600],
        },
    )


def get_message_pk(db: Session, mailbox_id: int, provider_message_id: str) -> int:
    row = db.execute(
        text("""
            SELECT id FROM messages
            WHERE mailbox_id = :mailbox_id AND provider_message_id = :pmid
            LIMIT 1
        """),
        {"mailbox_id": mailbox_id, "pmid": provider_message_id},
    ).fetchone()
    if not row:
        raise RuntimeError("Message PK not found after insert")
    return int(row[0])


def insert_case_event(
    db: Session,
    *,
    case_id: int,
    actor_user_id: int | None,
    source: str,
    event_type: str,
    from_status_id: int | None,
    to_status_id: int | None,
    details: dict,
    ip_address: str | None = None,
    user_agent: str | None = None,
) -> None:
    db.execute(
        text("""
            INSERT INTO case_events (
              case_id, actor_user_id,
              source, ip_address, user_agent,
              event_type, from_status_id, to_status_id,
              details_json, created_at
            )
            VALUES (
              :case_id, :actor_user_id,
              :source, :ip_address, :user_agent,
              :event_type, :from_status_id, :to_status_id,
              :details_json, NOW(6)
            )
        """),
        {
            "case_id": case_id,
            "actor_user_id": actor_user_id,
            "source": source,
            "ip_address": ip_address,
            "user_agent": user_agent,
            "event_type": event_type[:40],
            "from_status_id": from_status_id,
            "to_status_id": to_status_id,
            "details_json": json.dumps(details, ensure_ascii=False),
        },
    )


def load_system_config(db: Session) -> dict[str, str]:
    rows = db.execute(text("SELECT config_key, config_value FROM system_config")).fetchall()
    return {str(k): ("" if v is None else str(v)) for k, v in rows}


# ============================================================
# ✅ Subscriptions persistence (graph_subscriptions table)
# ============================================================

def ensure_graph_subscriptions_table(db: Session) -> None:
    db.execute(text("""
        CREATE TABLE IF NOT EXISTS graph_subscriptions (
          id INT AUTO_INCREMENT PRIMARY KEY,
          mailbox_id INT NOT NULL,
          subscription_id VARCHAR(190) NOT NULL,
          resource VARCHAR(255) NOT NULL,
          notification_url VARCHAR(600) NOT NULL,
          expires_at DATETIME(6) NOT NULL,
          status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
          last_renew_at DATETIME(6) NULL,
          created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
          updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
          UNIQUE KEY uq_subscription_id (subscription_id),
          UNIQUE KEY uq_mailbox_resource (mailbox_id, resource),
          KEY idx_mailbox_status (mailbox_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    """))


def upsert_subscription(
    db: Session,
    *,
    subscription_id: str,
    mailbox_id: int,
    resource: str,
    notification_url: str,
    expires_at: datetime,
    status: str = "ACTIVE",
) -> None:
    db.execute(text("""
        INSERT INTO graph_subscriptions
          (mailbox_id, resource, subscription_id, notification_url, expires_at, status, last_renew_at)
        VALUES
          (:mailbox_id, :resource, :subscription_id, :notification_url, :expires_at, :status, CURRENT_TIMESTAMP(6))
        ON DUPLICATE KEY UPDATE
          subscription_id = VALUES(subscription_id),
          notification_url = VALUES(notification_url),
          expires_at = VALUES(expires_at),
          status = VALUES(status),
          last_renew_at = CURRENT_TIMESTAMP(6),
          updated_at = CURRENT_TIMESTAMP(6)
    """), {
        "mailbox_id": mailbox_id,
        "resource": resource,
        "subscription_id": subscription_id,
        "notification_url": notification_url,
        "expires_at": expires_at,
        "status": status,
    })


def get_active_subscription(db: Session, *, mailbox_id: int, resource: str):
    return db.execute(text("""
        SELECT subscription_id, expires_at, status
        FROM graph_subscriptions
        WHERE mailbox_id = :mailbox_id
          AND resource = :resource
          AND status = 'ACTIVE'
        ORDER BY COALESCE(last_renew_at, created_at) DESC
        LIMIT 1
    """), {"mailbox_id": mailbox_id, "resource": resource}).fetchone()


def mark_subscription_status(db: Session, *, subscription_id: str, status: str) -> None:
    db.execute(text("""
        UPDATE graph_subscriptions
        SET status = :status,
            updated_at = CURRENT_TIMESTAMP(6)
        WHERE subscription_id = :subscription_id
        LIMIT 1
    """), {"subscription_id": subscription_id, "status": status})