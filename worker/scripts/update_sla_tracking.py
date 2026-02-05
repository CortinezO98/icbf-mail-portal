from __future__ import annotations

import os, csv, json, hashlib
from datetime import datetime, timezone
import pymysql

REPORTS_DIR = os.getenv("REPORTS_DIR", "/var/www/icbf/reports")  # ajusta
os.makedirs(REPORTS_DIR, exist_ok=True)

def now_utc_naive():
    return datetime.now(timezone.utc).replace(tzinfo=None)

def conn():
    return pymysql.connect(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASS", ""),
        database=os.getenv("DB_NAME", "icbf_mail"),
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )

def sha256_file(path: str) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()

def fetch_one_job(cur):
    cur.execute("""
      SELECT *
      FROM generated_reports
      WHERE status='PENDING'
      ORDER BY created_at ASC
      LIMIT 1
      FOR UPDATE
    """)
    return cur.fetchone()

def mark_running(cur, report_id: int):
    cur.execute("""
      UPDATE generated_reports SET status='RUNNING', started_at=NOW(6) WHERE id=%s
    """, (report_id,))

def mark_failed(cur, report_id: int, msg: str):
    cur.execute("""
      UPDATE generated_reports
      SET status='FAILED', error_message=%s, finished_at=NOW(6)
      WHERE id=%s
    """, (msg[:500], report_id))

def mark_ready(cur, report_id: int, storage_path: str, row_count: int, sha: str):
    cur.execute("""
      UPDATE generated_reports
      SET status='READY', storage_path=%s, row_count=%s, sha256=%s, finished_at=NOW(6)
      WHERE id=%s
    """, (storage_path, row_count, sha, report_id))

def run_query(cur, params: dict) -> list[dict]:
    """
    params: {"start":"YYYY-MM-DD", "end":"YYYY-MM-DD", "mailbox_id": optional}
    Export SLA dataset: vw_cases_sla para auditoría.
    """
    where_mb = ""
    args = {"start": params["start"], "end": params["end"]}
    if params.get("mailbox_id"):
        where_mb = " AND v.mailbox_id = %(mb)s "
        args["mb"] = int(params["mailbox_id"])

    cur.execute(f"""
      SELECT
        v.case_id, v.mailbox_id, v.subject, v.sender_email,
        v.received_at, v.created_at, v.first_response_at, v.closed_at,
        v.assigned_user_id, v.status_code,
        v.current_sla_state, v.breached, v.sla_due_at, v.minutes_since_creation
      FROM vw_cases_sla v
      WHERE v.received_at BETWEEN %(start)s AND %(end)s
      {where_mb}
      ORDER BY v.received_at ASC
    """, args)
    return list(cur.fetchall())

def write_csv(rows: list[dict], out_path: str) -> int:
    if not rows:
        # crea vacío con headers mínimos
        with open(out_path, "w", newline="", encoding="utf-8") as f:
            f.write("")
        return 0

    headers = list(rows[0].keys())
    with open(out_path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=headers)
        w.writeheader()
        for r in rows:
            w.writerow(r)
    return len(rows)

def main():
    c = conn()
    try:
        with c.cursor() as cur:
            job = fetch_one_job(cur)
            if not job:
                c.commit()
                return

            report_id = int(job["id"])
            try:
                mark_running(cur, report_id)
                params = json.loads(job["params_json"] or "{}")

                rows = run_query(cur, params)
                filename = f"report_{report_id}_{int(now_utc_naive().timestamp())}.csv"
                out_path = os.path.join(REPORTS_DIR, filename)

                row_count = write_csv(rows, out_path)
                sha = sha256_file(out_path)

                # Guardar path relativo recomendado
                storage_path = f"reports/{filename}"
                mark_ready(cur, report_id, storage_path, row_count, sha)
                c.commit()
                print(f"READY report_id={report_id} rows={row_count}")
            except Exception as e:
                c.rollback()
                with c.cursor() as cur2:
                    mark_failed(cur2, report_id, str(e))
                c.commit()
                print(f"FAILED report_id={report_id}: {e}")
    finally:
        c.close()

if __name__ == "__main__":
    main()
