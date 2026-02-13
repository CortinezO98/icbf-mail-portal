from __future__ import annotations

import os
from datetime import datetime, date, timedelta, timezone
import pymysql

try:
    from dotenv import load_dotenv
except Exception:
    load_dotenv = None


def _load_env() -> None:
    """
    Carga variables desde .env si python-dotenv está instalado.
    - Si ENV_FILE está definido, usa esa ruta.
    - Si no, intenta rutas típicas del repo.
    """
    if load_dotenv is None:
        return

    env_file = (os.getenv("ENV_FILE") or "").strip()
    candidates: list[str] = []

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


UPSERT_SQL = """
INSERT INTO agent_daily_metrics
  (agent_id, metric_date, cases_assigned, cases_resolved, cases_overdue, avg_response_hours, sla_compliance_rate, created_at, updated_at)
SELECT
  u.id AS agent_id,
  %(d)s AS metric_date,

  -- Casos asignados ese día (cuando se asignaron)
  SUM(CASE WHEN DATE(c.assigned_at) = %(d)s THEN 1 ELSE 0 END) AS cases_assigned,

  -- Casos cerrados ese día (resueltos)
  SUM(CASE WHEN DATE(c.closed_at)   = %(d)s THEN 1 ELSE 0 END) AS cases_resolved,

  -- Casos en breach cuyo tracking se “tocó” ese día (proxy vencidos)
  SUM(CASE
        WHEN COALESCE(cst.breached,0)=1
         AND DATE(cst.last_updated) = %(d)s
        THEN 1 ELSE 0
      END) AS cases_overdue,

  -- Promedio horas primera respuesta (casos con primera respuesta ese día)
  ROUND(AVG(
    CASE
      WHEN c.first_response_at IS NOT NULL
       AND DATE(c.first_response_at) = %(d)s
      THEN TIMESTAMPDIFF(MINUTE, c.received_at, c.first_response_at) / 60
      ELSE NULL
    END
  ), 2) AS avg_response_hours,

  -- % cumplimiento SLA sobre cerrados ese día (cerrados sin breached / cerrados)
  ROUND(
    CASE
      WHEN SUM(CASE WHEN DATE(c.closed_at) = %(d)s THEN 1 ELSE 0 END) = 0
      THEN 0
      ELSE
        100 * (
          1 - (
            SUM(CASE WHEN DATE(c.closed_at)=%(d)s AND COALESCE(cst.breached,0)=1 THEN 1 ELSE 0 END)
            /
            SUM(CASE WHEN DATE(c.closed_at)=%(d)s THEN 1 ELSE 0 END)
          )
        )
    END
  , 2) AS sla_compliance_rate,

  NOW(6), NOW(6)

FROM users u
LEFT JOIN cases c ON c.assigned_user_id = u.id
LEFT JOIN case_sla_tracking cst ON cst.case_id = c.id
WHERE u.is_active = 1
GROUP BY u.id
ON DUPLICATE KEY UPDATE
  cases_assigned = VALUES(cases_assigned),
  cases_resolved = VALUES(cases_resolved),
  cases_overdue = VALUES(cases_overdue),
  avg_response_hours = VALUES(avg_response_hours),
  sla_compliance_rate = VALUES(sla_compliance_rate),
  updated_at = NOW(6);
"""


def run_for_day(cur, d: date) -> int:
    """
    Ejecuta UPSERT para una fecha (YYYY-MM-DD).
    Retorna filas afectadas (aprox) del execute().
    """
    return cur.execute(UPSERT_SQL, {"d": d.strftime("%Y-%m-%d")})


def parse_day_from_env_or_default() -> date:
    """
    - Si AGENT_METRICS_DAY viene (YYYY-MM-DD): usa ese.
    - Si no: usa AYER (por compatibilidad).
    """
    s = (os.getenv("AGENT_METRICS_DAY") or "").strip()
    if s:
        return datetime.strptime(s, "%Y-%m-%d").date()
    return date.today() - timedelta(days=1)


def parse_range_from_env() -> tuple[date, date] | None:
    """
    Backfill opcional:
      AGENT_METRICS_START=YYYY-MM-DD
      AGENT_METRICS_END=YYYY-MM-DD
    """
    s1 = (os.getenv("AGENT_METRICS_START") or "").strip()
    s2 = (os.getenv("AGENT_METRICS_END") or "").strip()
    if not s1 or not s2:
        return None
    d1 = datetime.strptime(s1, "%Y-%m-%d").date()
    d2 = datetime.strptime(s2, "%Y-%m-%d").date()
    if d2 < d1:
        d1, d2 = d2, d1
    return d1, d2


def main():
    """
    ✅ Modo cada 10 minutos:
    - Si hay rango por ENV -> backfill.
    - Si no -> recalcula HOY y AYER (por defecto) para mantener métricas frescas.
      Controlado por AGENT_METRICS_DAYS_BACK:
        - 1 => hoy y ayer (recomendado)
        - 0 => solo hoy
        - 2 => últimos 3 días, etc.
    """
    c = conn()
    try:
        with c.cursor() as cur:
            r = parse_range_from_env()
            if r is not None:
                start, end = r
            else:
                days_back = int((os.getenv("AGENT_METRICS_DAYS_BACK") or "1").strip() or "1")
                end = date.today()
                start = end - timedelta(days=days_back)

                forced_day = (os.getenv("AGENT_METRICS_DAY") or "").strip()
                if forced_day:
                    d = datetime.strptime(forced_day, "%Y-%m-%d").date()
                    start, end = d, d

            d = start
            total_affected = 0
            try:
                while d <= end:
                    total_affected += run_for_day(cur, d)
                    d += timedelta(days=1)
                c.commit()
                print(f"READY agent_daily_metrics range={start}..{end} affected={total_affected}")
            except Exception as e:
                c.rollback()
                c.commit()
                print(f"FAILED agent_daily_metrics range={start}..{end}: {e}")
    finally:
        c.close()


if __name__ == "__main__":
    main()
