from __future__ import annotations

import datetime as dt
from typing import Optional, Dict, Any, Tuple

from sqlalchemy import text
from sqlalchemy.engine import Connection


def utcnow() -> dt.datetime:
    return dt.datetime.utcnow()


def ensure_mailbox(conn: Connection, email: str) -> int:
    row = conn.execute(
        text("SELECT id FROM mailboxes WHERE email = :email LIMIT 1"),
        {"email": email},
    ).fetchone()
    if row:
        return int(row[0])

    conn.execute(
        text("""
            INSERT INTO mailboxes (email, display_name, tenant_id, graph_user_id, is_active, created_at, updated_at)
            VALUES (:email, NULL, NULL, NULL, 1, :now, :now)
        """),
        {"email": email, "now": utcnow()},
    )
    new_id = conn.execute(text("SELECT LAST_INSERT_ID()")).scalar_one()
    return int(new_id)


def get_status_id_by_code(conn: Connection, code: str) -> int:
    row = conn.execute(
        text("SELECT id FROM case_statuses WHERE code = :code LIMIT 1"),
        {"code": code},
    ).fetchone()
    if not row:
        raise RuntimeError(f"Missing case_statuses code={code}. Seed required.")
    return int(row[0])


def get_case_by_conversation_id(conn: Connection, mailbox_id: int, conversation_id: str) -> Optional[int]:
    row = conn.execute(
        text("""
            SELECT m.case_id
            FROM messages m
            WHERE m.mailbox_id = :mailbox_id
              AND m.conversation_id = :conversation_id
            ORDER BY m.id ASC
            LIMIT 1
        """),
        {"mailbox_id": mailbox_id, "conversation_id": conversation_id},
    ).fetchone()
    return int(row[0]) if row else None


def next_case_number(conn: Connection, year: int) -> str:
    # lock row for update to avoid duplicates
    row = conn.execute(
        text("SELECT last_value FROM case_sequences WHERE year = :year FOR UPDATE"),
        {"year": year},
    ).fetchone()

    if row is None:
        conn.execute(
            text("INSERT INTO case_sequences (year, last_value, updated_at) VALUES (:year, 0, :now)"),
            {"year": year, "now": utcnow()},
        )
        last_value = 0
    else:
        last_value = int(row[0])

    new_value = last_value + 1
    conn.execute(
        text("UPDATE case_sequences SET last_value = :v, updated_at = :now WHERE year = :year"),
        {"v": new_value, "now": utcnow(), "year": year},
    )

    return f"ICBF-{year}-{new_value:06d}"


def create_case(
    conn: Connection,
    mailbox_id: int,
    subject: str,
    requester_email: str,
    requester_name: Optional[str],
    received_at_utc: dt.datetime,
) -> int:
    year = received_at_utc.year
    case_number = next_case_number(conn, year)
    status_id = get_status_id_by_code(conn, "NUEVO")

    now = utcnow()
    conn.execute(
        text("""
            INSERT INTO cases (
              mailbox_id, case_number, subject, requester_email, requester_name,
              status_id, category_id, assigned_user_id, assigned_group_id,
              received_at, assigned_at, first_response_at, closed_at,
              last_activity_at, is_responded, due_at, sla_state,
              locked_by, locked_at, created_at, updated_at
            ) VALUES (
              :mailbox_id, :case_number, :subject, :requester_email, :requester_name,
              :status_id, NULL, NULL, NULL,
              :received_at, NULL, NULL, NULL,
              :last_activity_at, 0, NULL, 'OK',
              NULL, NULL, :now, :now
            )
        """),
        {
            "mailbox_id": mailbox_id,
            "case_number": case_number,
            "subject": subject[:255],
            "requester_email": requester_email[:190],
            "requester_name": (requester_name[:190] if requester_name else None),
            "status_id": status_id,
            "received_at": received_at_utc,
            "last_activity_at": received_at_utc,
            "now": now,
        },
    )
    case_id = int(conn.execute(text("SELECT LAST_INSERT_ID()")).scalar_one())

    # audit event
    conn.execute(
        text("""
            INSERT INTO case_events (
              case_id, actor_user_id, source, ip_address, user_agent,
              event_type, from_status_id, to_status_id, details_json, created_at
            ) VALUES (
              :case_id, NULL, 'WORKER', NULL, NULL,
              'CASE_CREATED', NULL, :to_status_id, :details, :now
            )
        """),
        {
            "case_id": case_id,
            "to_status_id": status_id,
            "details": '{"reason":"new inbound email"}',
            "now": utcnow(),
        },
    )

    return case_id


def insert_message_dedupe(
    conn: Connection,
    case_id: int,
    mailbox_id: int,
    folder_id: Optional[int],
    direction: str,
    provider_message_id: str,
    conversation_id: Optional[str],
    internet_message_id: Optional[str],
    in_reply_to: Optional[str],
    from_email: str,
    to_emails: Optional[str],
    cc_emails: Optional[str],
    bcc_emails: Optional[str],
    subject: str,
    body_text: Optional[str],
    body_html: Optional[str],
    received_at_utc: Optional[dt.datetime],
    sent_at_utc: Optional[dt.datetime],
    has_attachments: int,
    processed_by_worker: Optional[str],
) -> Tuple[bool, int]:
    """
    Returns (inserted, message_id). Dedupe on (mailbox_id, provider_message_id) UNIQUE.
    """
    existing = conn.execute(
        text("""
            SELECT id, case_id
            FROM messages
            WHERE mailbox_id = :mailbox_id AND provider_message_id = :pmid
            LIMIT 1
        """),
        {"mailbox_id": mailbox_id, "pmid": provider_message_id},
    ).fetchone()

    if existing:
        return False, int(existing[0])

    conn.execute(
        text("""
            INSERT INTO messages (
              case_id, mailbox_id, folder_id, direction,
              provider_message_id, conversation_id, internet_message_id, in_reply_to,
              from_email, to_emails, cc_emails, bcc_emails,
              subject, body_text, body_html,
              received_at, sent_at, has_attachments, processed_by_worker, created_at
            ) VALUES (
              :case_id, :mailbox_id, :folder_id, :direction,
              :provider_message_id, :conversation_id, :internet_message_id, :in_reply_to,
              :from_email, :to_emails, :cc_emails, :bcc_emails,
              :subject, :body_text, :body_html,
              :received_at, :sent_at, :has_attachments, :processed_by_worker, :created_at
            )
        """),
        {
            "case_id": case_id,
            "mailbox_id": mailbox_id,
            "folder_id": folder_id,
            "direction": direction,
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
            "received_at": received_at_utc,
            "sent_at": sent_at_utc,
            "has_attachments": int(has_attachments),
            "processed_by_worker": (processed_by_worker[:50] if processed_by_worker else None),
            "created_at": utcnow(),
        },
    )
    message_id = int(conn.execute(text("SELECT LAST_INSERT_ID()")).scalar_one())

    # update case last_activity_at
    conn.execute(
        text("UPDATE cases SET last_activity_at = :t, updated_at = :now WHERE id = :case_id"),
        {"t": received_at_utc or utcnow(), "now": utcnow(), "case_id": case_id},
    )

    # audit
    conn.execute(
        text("""
            INSERT INTO case_events (case_id, actor_user_id, source, event_type, details_json, created_at)
            VALUES (:case_id, NULL, 'WORKER', 'MESSAGE_INGESTED', :details, :now)
        """),
        {
            "case_id": case_id,
            "details": f'{{"message_id":{message_id},"direction":"{direction}"}}',
            "now": utcnow(),
        },
    )

    return True, message_id


def insert_attachment(
    conn: Connection,
    message_id: int,
    filename: str,
    content_type: str,
    size_bytes: int,
    sha256: Optional[str],
    is_inline: int,
    content_id: Optional[str],
    storage_path: str,
) -> int:
    conn.execute(
        text("""
            INSERT INTO attachments (
              message_id, filename, content_type, size_bytes,
              sha256, is_inline, content_id, storage_path, created_at
            ) VALUES (
              :message_id, :filename, :content_type, :size_bytes,
              :sha256, :is_inline, :content_id, :storage_path, :created_at
            )
        """),
        {
            "message_id": message_id,
            "filename": filename[:255],
            "content_type": content_type[:120],
            "size_bytes": int(size_bytes),
            "sha256": sha256,
            "is_inline": int(is_inline),
            "content_id": (content_id[:190] if content_id else None),
            "storage_path": storage_path[:600],
            "created_at": utcnow(),
        },
    )
    return int(conn.execute(text("SELECT LAST_INSERT_ID()")).scalar_one())
