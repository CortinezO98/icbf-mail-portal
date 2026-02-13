from __future__ import annotations

import os
import smtplib
import socket
import random
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from datetime import datetime, timezone, timedelta

import pymysql

try:
    from dotenv import load_dotenv
except Exception:
    load_dotenv = None


def _load_env():
    if load_dotenv is None:
        return
    env_file = (os.getenv("ENV_FILE") or "").strip()
    candidates = []
    if env_file:
        candidates.append(env_file)
    else:
        base = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
        candidates.extend([
            os.path.join(base, ".env"),
            os.path.join(base, "worker", ".env"),
            os.path.join(base, "portal", ".env"),
        ])
    for p in candidates:
        if os.path.isfile(p):
            load_dotenv(p, override=False)
            return
    load_dotenv(override=False)


_load_env()


def now_utc_naive() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


def conn():
    return pymysql.connect(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASS", ""),
        database=os.getenv("DB_NAME", "icbf_mail"),
        port=int(os.getenv("DB_PORT", "3306")),
        charset=os.getenv("DB_CHARSET", "utf8mb4"),
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )


def smtp_send(to_email: str, subject: str, html_body: str, to_name: str | None = None) -> None:
    driver = (os.getenv("MAIL_DRIVER") or "smtp").strip().lower()
    if driver != "smtp":
        raise RuntimeError(f"MAIL_DRIVER no soportado en worker: {driver}")

    host = (os.getenv("MAIL_HOST") or "").strip()
    port = int(os.getenv("MAIL_PORT", "587"))
    user = (os.getenv("MAIL_USER") or "").strip()
    pwd = os.getenv("MAIL_PASS", "") or ""
    use_tls = (os.getenv("MAIL_TLS", "1").strip().lower() in ("1", "true", "yes", "si"))

    from_email = os.getenv("MAIL_FROM", "noreply@icbf.gov.co")
    from_name = os.getenv("MAIL_FROM_NAME", "ICBF Mail")

    if not host:
        raise RuntimeError("MAIL_HOST vacío. Configura SMTP en .env")

    msg = MIMEMultipart("alternative")
    msg["Subject"] = subject
    msg["From"] = f"{from_name} <{from_email}>"
    msg["To"] = f"{to_name} <{to_email}>" if to_name else to_email

    msg.attach(MIMEText(html_body, "html", "utf-8"))

    timeout = int(os.getenv("MAIL_SMTP_TIMEOUT", "20"))
    with smtplib.SMTP(host, port, timeout=timeout) as server:
        server.ehlo()
        if use_tls:
            server.starttls()
            server.ehlo()
        if user:
            server.login(user, pwd)
        server.sendmail(from_email, [to_email], msg.as_string())


def release_stuck_running(cur, minutes: int, locked_by: str):
    """
    Si el worker muere, quedan RUNNING eternos.
    Esto los libera si locked_at es muy viejo.
    """
    cur.execute(
        """
        UPDATE email_queue
        SET status='PENDING',
            locked_at=NULL,
            locked_by=NULL,
            updated_at=NOW(6),
            last_error=CONCAT(IFNULL(last_error,''), ' | released_stuck_running')
        WHERE status='RUNNING'
          AND locked_at IS NOT NULL
          AND locked_at < (NOW(6) - INTERVAL %s MINUTE)
        """,
        (minutes,),
    )


def fetch_batch(cur, batch: int, locked_by: str) -> list[dict]:
    use_skip_locked = (os.getenv("MAIL_SKIP_LOCKED", "0").strip().lower() in ("1", "true", "yes", "si"))

    if use_skip_locked:
        cur.execute(
            """
            SELECT *
            FROM email_queue
            WHERE status='PENDING'
              AND next_attempt_at <= NOW(6)
            ORDER BY priority ASC, id ASC
            LIMIT %s
            FOR UPDATE SKIP LOCKED
            """,
            (batch,),
        )
    else:
        cur.execute(
            """
            SELECT *
            FROM email_queue
            WHERE status='PENDING'
              AND next_attempt_at <= NOW(6)
            ORDER BY priority ASC, id ASC
            LIMIT %s
            FOR UPDATE
            """,
            (batch,),
        )

    rows = list(cur.fetchall())
    if not rows:
        return []

    ids = [r["id"] for r in rows]
    cur.execute(
        f"""
        UPDATE email_queue
        SET status='RUNNING',
            locked_at=NOW(6),
            locked_by=%s,
            updated_at=NOW(6)
        WHERE id IN ({",".join(["%s"] * len(ids))})
        """,
        tuple([locked_by] + ids),
    )
    return rows


def mark_sent(cur, job_id: int):
    cur.execute(
        """
        UPDATE email_queue
        SET status='SENT',
            sent_at=NOW(6),
            locked_at=NULL,
            locked_by=NULL,
            last_error=NULL,
            updated_at=NOW(6)
        WHERE id=%s
        """,
        (job_id,),
    )


def mark_failed(cur, job_id: int, attempts: int, max_attempts: int, err: str):
    base_delay = min(60, 2 ** min(attempts, 10))  
    jitter = random.randint(0, 10)                
    delay_min = min(60, base_delay + jitter)

    next_at = now_utc_naive() + timedelta(minutes=delay_min)

    final_status = "PENDING" if attempts < max_attempts else "DEAD"
    cur.execute(
        """
        UPDATE email_queue
        SET status=%s,
            attempts=%s,
            next_attempt_at=%s,
            locked_at=NULL,
            locked_by=NULL,
            last_error=%s,
            updated_at=NOW(6)
        WHERE id=%s
        """,
        (final_status, attempts, next_at.strftime("%Y-%m-%d %H:%M:%S.%f"), err[:500], job_id),
    )


def main():
    batch = int(os.getenv("MAIL_WORKER_BATCH", "20"))
    locked_by = (os.getenv("MAIL_WORKER_LOCKED_BY") or f"worker-mail-{socket.gethostname()}")[:64]
    stuck_minutes = int(os.getenv("MAIL_RUNNING_STUCK_MINUTES", "10"))

    c = conn()
    try:
        with c.cursor() as cur0:
            release_stuck_running(cur0, stuck_minutes, locked_by)
        c.commit()

        with c.cursor() as cur:
            jobs = fetch_batch(cur, batch, locked_by)
            if not jobs:
                c.commit()
                print("OK no jobs")
                return
            c.commit()

        sent = 0
        failed = 0

        for job in jobs:
            job_id = int(job["id"])
            try:
                smtp_send(
                    to_email=str(job["to_email"]),
                    subject=str(job["subject"]),
                    html_body=str(job["body_html"]),
                    to_name=(str(job["to_name"]) if job.get("to_name") else None),
                )
                with c.cursor() as cur2:
                    mark_sent(cur2, job_id)
                c.commit()
                sent += 1
                print(f"SENT id={job_id} to={job['to_email']}")
            except Exception as e:
                attempts = int(job.get("attempts") or 0) + 1
                max_attempts = int(job.get("max_attempts") or 8)
                with c.cursor() as cur3:
                    mark_failed(cur3, job_id, attempts, max_attempts, str(e))
                c.commit()
                failed += 1
                print(f"FAILED id={job_id} attempts={attempts}/{max_attempts} err={e}")

        print(f"DONE sent={sent} failed={failed} batch={len(jobs)}")

    finally:
        c.close()


if __name__ == "__main__":
    main()
