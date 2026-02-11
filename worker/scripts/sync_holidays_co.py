from __future__ import annotations

import logging
import os
import sys
from dataclasses import dataclass
from datetime import date
from typing import Iterable, List, Tuple

import pymysql
import holidays

try:
    from dotenv import load_dotenv, find_dotenv
except Exception:  # pragma: no cover
    load_dotenv = None
    find_dotenv = None


# -----------------------------
# Logging (no filtra credenciales)
# -----------------------------
logger = logging.getLogger("sync_holidays")
_handler = logging.StreamHandler(sys.stdout)
_handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
logger.addHandler(_handler)
logger.setLevel(logging.INFO)


# -----------------------------
# Helpers .env
# -----------------------------
def load_env() -> None:
    """
    Carga variables desde .env automáticamente.
    - Busca .env hacia arriba desde el cwd.
    - No rompe si python-dotenv no está instalado.
    - No sobreescribe variables del entorno (override=False).
    """
    if load_dotenv and find_dotenv:
        env_path = find_dotenv(".env", usecwd=True)
        if env_path:
            load_dotenv(env_path, override=False)


def getenv_int(key: str, default: int) -> int:
    v = os.getenv(key)
    if not v:
        return default
    try:
        return int(v)
    except ValueError:
        return default


# -----------------------------
# Config
# -----------------------------
@dataclass(frozen=True)
class DbConfig:
    host: str
    port: int
    user: str
    password: str
    database: str


def get_db_config() -> DbConfig:
    load_env()
    return DbConfig(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=getenv_int("DB_PORT", 3306),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASS", ""),
        database=os.getenv("DB_NAME", "icbf_mail"),
    )


def conn(cfg: DbConfig) -> pymysql.connections.Connection:
    """
    Abre conexión a MySQL sin loggear detalles sensibles.
    """
    return pymysql.connect(
        host=cfg.host,
        port=cfg.port,
        user=cfg.user,
        password=cfg.password,
        database=cfg.database,
        charset="utf8mb4",
        autocommit=False,
        cursorclass=pymysql.cursors.DictCursor,
    )


# -----------------------------
# Core
# -----------------------------
def build_rows(country_code: str, years: Iterable[int]) -> List[Tuple[str, str, str]]:
    cal = holidays.country_holidays(country_code, years=list(years))
    rows: List[Tuple[str, str, str]] = []
    for d, name in cal.items():
        rows.append((country_code, d.isoformat(), str(name)[:200]))
    return rows


UPSERT_SQL = """
INSERT INTO holiday_calendar (country_code, holiday_date, name, source, created_at, updated_at)
VALUES (%s, %s, %s, 'python-holidays', NOW(6), NOW(6))
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  source = VALUES(source),
  updated_at = NOW(6)
"""


def upsert_holidays(cfg: DbConfig, rows: List[Tuple[str, str, str]]) -> int:
    if not rows:
        return 0

    c = conn(cfg)
    try:
        with c.cursor() as cur:
            cur.executemany(UPSERT_SQL, rows)
        c.commit()
        return len(rows)
    except Exception:
        c.rollback()
        raise
    finally:
        c.close()


def main() -> int:
    cfg = get_db_config()

    today = date.today()
    years = [today.year, today.year + 1]

    rows = build_rows("CO", years)
    if not rows:
        logger.warning("No holidays generated (country=CO, years=%s)", years)
        return 0

    try:
        n = upsert_holidays(cfg, rows)
        logger.info("OK holidays_upserted=%s years=%s country=CO", n, years)
        return 0
    except Exception as e:
        logger.exception("ERROR syncing holidays: %s", e)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
