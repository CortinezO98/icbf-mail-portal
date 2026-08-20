#!/usr/bin/env python3
"""Destructive D2 integration harness for a MariaDB TEST database only.

Validates the real D2 SQL/repository/orchestration against MariaDB without
calling Microsoft Graph or writing attachment files. Graph responses and the
individual attachment writer are deterministic fakes; all state transitions,
claims, FK/UNIQUE behavior and recovery queries use the real MariaDB engine.

Required environment variables:
    D2_MARIADB_TEST_URL=mysql+pymysql://user:pass@127.0.0.1:3306/icbf_mail_d2_test
    D2_ALLOW_DESTRUCTIVE_INTEGRATION=YES

Safety: the database name MUST contain "test" or "sandbox". The script drops
and recreates the minimal ICBF tables inside that database.
"""

from __future__ import annotations

import asyncio
import hashlib
import os
import sys
from contextlib import contextmanager
from datetime import datetime, timedelta
from pathlib import Path
from urllib.parse import urlparse

from sqlalchemy import create_engine, text
from sqlalchemy.orm import Session, sessionmaker

WORKER_DIR = Path(__file__).resolve().parents[1]
if str(WORKER_DIR) not in sys.path:
    sys.path.insert(0, str(WORKER_DIR))

TEST_URL = os.getenv("D2_MARIADB_TEST_URL", "").strip()
ALLOW = os.getenv("D2_ALLOW_DESTRUCTIVE_INTEGRATION", "").strip().upper()


def _die(message: str) -> None:
    raise SystemExit(message)


def _database_name(url: str) -> str:
    # SQLAlchemy URLs use mysql+pymysql://; urllib is enough for the DB path.
    parsed = urlparse(url)
    return parsed.path.lstrip("/").split("?", 1)[0]


if not TEST_URL:
    _die("Set D2_MARIADB_TEST_URL to a dedicated MariaDB test database URL.")
if ALLOW != "YES":
    _die("Set D2_ALLOW_DESTRUCTIVE_INTEGRATION=YES to acknowledge destructive test DDL.")

DB_NAME = _database_name(TEST_URL)
if not DB_NAME or ("test" not in DB_NAME.lower() and "sandbox" not in DB_NAME.lower()):
    _die(
        f"Refusing destructive integration against database {DB_NAME!r}. "
        "Its name must contain 'test' or 'sandbox'."
    )

# Import only after the safety guard. app.attachment_recovery imports app.db,
# but this harness replaces get_db_session before any application DB connection
# is opened.
from app import attachment_recovery as ar  # noqa: E402
from app import attachment_recovery_repo as repo  # noqa: E402

engine = create_engine(TEST_URL, future=True, pool_pre_ping=True)
SessionLocal = sessionmaker(bind=engine, autoflush=False, autocommit=False, future=True)


@contextmanager
def test_db_session():
    db: Session = SessionLocal()
    try:
        yield db
        db.commit()
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()


SCHEMA_STATEMENTS = [
    """
    CREATE TABLE mailboxes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_mailboxes_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE cases (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        case_id BIGINT UNSIGNED NOT NULL,
        mailbox_id BIGINT UNSIGNED NOT NULL,
        provider_message_id VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        conversation_id VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
        received_at DATETIME(6) DEFAULT NULL,
        has_attachments TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY uq_messages_mailbox_provider (mailbox_id, provider_message_id),
        CONSTRAINT fk_messages_case FOREIGN KEY (case_id) REFERENCES cases(id),
        CONSTRAINT fk_messages_mailbox FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE attachments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        message_id BIGINT UNSIGNED NOT NULL,
        graph_attachment_id VARCHAR(190) DEFAULT NULL,
        filename VARCHAR(255) NOT NULL,
        content_type VARCHAR(120) NOT NULL,
        size_bytes BIGINT UNSIGNED NOT NULL,
        sha256 CHAR(64) NOT NULL,
        is_inline TINYINT(1) NOT NULL DEFAULT 0,
        content_id VARCHAR(190) DEFAULT NULL,
        storage_path VARCHAR(600) NOT NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (id),
        UNIQUE KEY uq_attachments_message_graph (message_id, graph_attachment_id),
        KEY idx_attachments_message (message_id),
        KEY idx_attachments_sha (sha256),
        KEY idx_attachments_graph_att (graph_attachment_id),
        KEY idx_attachments_message_sha (message_id, sha256),
        CONSTRAINT fk_attachments_message FOREIGN KEY (message_id) REFERENCES messages(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
    """
    CREATE TABLE attachment_recovery (
        message_id BIGINT UNSIGNED NOT NULL,
        status ENUM('pending','verifying','complete','blocked') NOT NULL DEFAULT 'pending',
        expected_count INT UNSIGNED DEFAULT NULL,
        downloaded_count INT UNSIGNED NOT NULL DEFAULT 0,
        manifest_hash CHAR(64) DEFAULT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        locked_at DATETIME(6) DEFAULT NULL,
        last_reason VARCHAR(80) DEFAULT NULL,
        last_error VARCHAR(1000) DEFAULT NULL,
        first_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        last_checked_at DATETIME(6) DEFAULT NULL,
        verified_at DATETIME(6) DEFAULT NULL,
        completed_at DATETIME(6) DEFAULT NULL,
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        PRIMARY KEY (message_id),
        KEY idx_attachment_recovery_claim (status, available_at),
        CONSTRAINT fk_attachment_recovery_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    """,
]


def reset_schema() -> None:
    with engine.begin() as conn:
        conn.execute(text("SET FOREIGN_KEY_CHECKS=0"))
        for table in ("attachment_recovery", "attachments", "messages", "cases", "mailboxes"):
            conn.execute(text(f"DROP TABLE IF EXISTS {table}"))
        conn.execute(text("SET FOREIGN_KEY_CHECKS=1"))
        for ddl in SCHEMA_STATEMENTS:
            conn.execute(text(ddl))
        conn.execute(text("INSERT INTO mailboxes(email) VALUES ('test@icbf.local')"))
        conn.execute(text("INSERT INTO cases() VALUES ()"))


def new_message(provider_id: str) -> int:
    received = datetime.now() - timedelta(days=1)
    with engine.begin() as conn:
        result = conn.execute(
            text("""
                INSERT INTO messages(case_id, mailbox_id, provider_message_id, conversation_id, received_at, has_attachments)
                VALUES (1, 1, :provider_id, :conversation_id, :received_at, 1)
            """),
            {
                "provider_id": provider_id,
                "conversation_id": f"conv-{provider_id}",
                "received_at": received,
            },
        )
        return int(result.lastrowid)


def seed_attachment(message_id: int, graph_id: str) -> None:
    gid = graph_id.strip()
    sha = hashlib.sha256(f"{message_id}:{gid}".encode()).hexdigest()
    with engine.begin() as conn:
        conn.execute(
            text("""
                INSERT INTO attachments(
                    message_id, graph_attachment_id, filename, content_type,
                    size_bytes, sha256, is_inline, content_id, storage_path
                ) VALUES (
                    :message_id, :gid, :filename, 'application/octet-stream',
                    1, :sha, 0, NULL, :path
                )
                ON DUPLICATE KEY UPDATE filename=VALUES(filename)
            """),
            {
                "message_id": message_id,
                "gid": gid,
                "filename": f"{gid}.bin",
                "sha": sha,
                "path": f"test/{sha}_{gid}.bin",
            },
        )


def graph_ids(message_id: int) -> set[str]:
    with engine.connect() as conn:
        rows = conn.execute(
            text("SELECT graph_attachment_id FROM attachments WHERE message_id=:mid ORDER BY id"),
            {"mid": message_id},
        ).fetchall()
    return {str(r[0]).strip() for r in rows if r[0] is not None}


def recovery_row(message_id: int) -> dict:
    with engine.connect() as conn:
        row = conn.execute(
            text("SELECT * FROM attachment_recovery WHERE message_id=:mid"),
            {"mid": message_id},
        ).mappings().first()
    assert row is not None
    return dict(row)


def seed_pending(message_id: int, *, locked_at_sql: str = "NULL") -> None:
    with engine.begin() as conn:
        conn.execute(
            text(f"""
                INSERT INTO attachment_recovery(message_id, status, last_reason, available_at, locked_at)
                VALUES (:mid, 'pending', 'TEST', NOW(6), {locked_at_sql})
            """),
            {"mid": message_id},
        )


def manifest(*ids: str) -> list[dict]:
    return [
        {
            "@odata.type": "#microsoft.graph.fileAttachment",
            "id": gid,
            "name": f"{gid.strip()}.bin",
        }
        for gid in ids
    ]


async def main() -> None:
    reset_schema()

    # Route all D2 sessions to the real test MariaDB.
    ar.get_db_session = test_db_session
    ar.settings.ATTACHMENT_RECOVERY_STALE_LOCK_MINUTES = 10
    ar.settings.ATTACHMENT_RECOVERY_VERIFICATION_DELAY_SECONDS = 0
    ar.settings.ATTACHMENTS_STABILIZATION_WINDOW_MINUTES = 15

    manifests: dict[str, list[dict]] = {}
    attempted: list[tuple[str, ...]] = []

    async def fake_list_attachments(mailbox_email: str, provider_message_id: str):
        return manifests[provider_message_id]

    async def fake_process_attachments(**kwargs):
        subset = kwargs.get("attachments_manifest") or []
        ids = tuple(str(a.get("id") or "").strip() for a in subset)
        attempted.append(ids)
        for gid in ids:
            seed_attachment(int(kwargs["message_pk"]), gid)
        return {"attempted": len(ids), "succeeded": len(ids), "failed": 0, "failures": []}

    ar.graph_client.list_attachments = fake_list_attachments
    ar.sync_service._process_attachments = fake_process_attachments

    async def run_one(mid: int):
        # Ensure only this message is due to keep assertions deterministic.
        with engine.begin() as conn:
            conn.execute(text("UPDATE attachment_recovery SET available_at=DATE_ADD(NOW(6), INTERVAL 1 DAY) WHERE message_id<>:mid"), {"mid": mid})
            conn.execute(text("UPDATE attachment_recovery SET available_at=NOW(6) WHERE message_id=:mid"), {"mid": mid})
        return await ar.run_attachment_recovery_cycle(limit=1)

    # A: 0/3 -> recover A,B,C.
    m_a = new_message("MSG-A")
    seed_pending(m_a)
    manifests["MSG-A"] = manifest("A", "B", "C")
    attempted.clear()
    await run_one(m_a)
    assert graph_ids(m_a) == {"A", "B", "C"}
    assert attempted[-1] == ("A", "B", "C")
    assert recovery_row(m_a)["status"] == "verifying"
    print("A PASS 0/3 -> A,B,C")

    # B: 1/3 -> only B,C.
    m_b = new_message("MSG-B")
    seed_attachment(m_b, "A")
    seed_pending(m_b)
    manifests["MSG-B"] = manifest("A", "B", "C")
    attempted.clear()
    await run_one(m_b)
    assert graph_ids(m_b) == {"A", "B", "C"}
    assert set(attempted[-1]) == {"B", "C"}
    print("B PASS 1/3 -> only B,C")

    # C: 2/3 -> only C.
    m_c = new_message("MSG-C")
    seed_attachment(m_c, "A")
    seed_attachment(m_c, "B")
    seed_pending(m_c)
    manifests["MSG-C"] = manifest("A", "B", "C")
    attempted.clear()
    await run_one(m_c)
    assert graph_ids(m_c) == {"A", "B", "C"}
    assert attempted[-1] == ("C",)
    print("C PASS 2/3 -> only C")

    # D/E: first N/N -> verifying, second same manifest -> complete.
    m_de = new_message("MSG-DE")
    for gid in ("A", "B", "C"):
        seed_attachment(m_de, gid)
    seed_pending(m_de)
    manifests["MSG-DE"] = manifest("A", "B", "C")
    await run_one(m_de)
    row = recovery_row(m_de)
    assert row["status"] == "verifying" and row["manifest_hash"]
    print("D PASS N/N first observation -> verifying")
    await run_one(m_de)
    row = recovery_row(m_de)
    assert row["status"] == "complete" and row["verified_at"] is not None
    print("E PASS same stable N/N -> complete")

    # F: verifying ABC -> Graph ABCD; recover D and stay verifying with new hash.
    m_f = new_message("MSG-F")
    for gid in ("A", "B", "C"):
        seed_attachment(m_f, gid)
    old_hash = ar._compute_manifest_hash({"A", "B", "C"})
    with engine.begin() as conn:
        conn.execute(
            text("""
                INSERT INTO attachment_recovery(message_id,status,expected_count,downloaded_count,manifest_hash,available_at,last_reason)
                VALUES (:mid,'verifying',3,3,:hash,NOW(6),'TEST_VERIFYING')
            """),
            {"mid": m_f, "hash": old_hash},
        )
    manifests["MSG-F"] = manifest("A", "B", "C", "D")
    attempted.clear()
    await run_one(m_f)
    row = recovery_row(m_f)
    assert graph_ids(m_f) == {"A", "B", "C", "D"}
    assert attempted[-1] == ("D",)
    assert row["status"] == "verifying"
    assert row["manifest_hash"] == ar._compute_manifest_hash({"A", "B", "C", "D"})
    print("F PASS manifest ABC -> ABCD recovers D and does not false-complete")

    # G: simulated foreground crash after 2/3 left pending + stale lock.
    m_g = new_message("MSG-G")
    seed_attachment(m_g, "A")
    seed_attachment(m_g, "B")
    seed_pending(m_g, locked_at_sql="DATE_SUB(NOW(6), INTERVAL 11 MINUTE)")
    manifests["MSG-G"] = manifest("A", "B", "C")
    attempted.clear()
    await run_one(m_g)
    assert graph_ids(m_g) == {"A", "B", "C"}
    assert attempted[-1] == ("C",)
    assert recovery_row(m_g)["status"] == "verifying"
    print("G PASS stale crash lock 2/3 -> reclaim + recover C")

    # Real SQL no-clobber sanity: advanced states stay advanced when foreground redetects.
    for state in ("verifying", "complete", "blocked"):
        mid = new_message(f"NO-CLOBBER-{state}")
        with engine.begin() as conn:
            conn.execute(
                text("""
                    INSERT INTO attachment_recovery(message_id,status,expected_count,downloaded_count,manifest_hash,last_reason)
                    VALUES (:mid,:status,3,3,'abc','ORIGINAL')
                """),
                {"mid": mid, "status": state},
            )
        with test_db_session() as db:
            repo.upsert_pending(db, message_id=mid, reason="MANIFEST_DETECTED", locked=True)
        row = recovery_row(mid)
        assert row["status"] == state
        assert row["manifest_hash"] == "abc"
        assert row["expected_count"] == 3
        assert row["locked_at"] is None
    print("NO-CLOBBER PASS verifying/complete/blocked preserved")

    # FK proof.
    try:
        with engine.begin() as conn:
            conn.execute(text("INSERT INTO attachment_recovery(message_id) VALUES (999999999)"))
    except Exception:
        print("FK PASS orphan recovery rejected")
    else:
        raise AssertionError("FK did not reject orphan attachment_recovery row")

    with engine.connect() as conn:
        version = conn.execute(text("SELECT VERSION()" )).scalar_one()
        show = conn.execute(text("SHOW CREATE TABLE attachment_recovery")).fetchone()
    print(f"MariaDB version: {version}")
    print("SHOW CREATE TABLE attachment_recovery:")
    print(show[1])
    print("\nALL D2 MARIADB SCENARIOS PASSED")


if __name__ == "__main__":
    asyncio.run(main())
