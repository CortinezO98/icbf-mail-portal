#!/usr/bin/env python3
"""Validación destructiva del Assignment Worker contra MariaDB TEST/SANDBOX.

No llama Microsoft Graph. Crea un esquema mínimo, prueba presencia, capacidad,
FIFO y concurrencia real con locks de InnoDB.

Variables requeridas:
  ASSIGNMENT_MARIADB_TEST_URL=mysql+pymysql://user:pass@127.0.0.1:3306/icbf_assignment_test
  ASSIGNMENT_ALLOW_DESTRUCTIVE_INTEGRATION=YES

La base debe contener "test" o "sandbox" en el nombre.
"""
from __future__ import annotations

import os
import sys
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta
from contextlib import contextmanager
from pathlib import Path
from urllib.parse import urlparse

from sqlalchemy import create_engine, text
from sqlalchemy.orm import Session, sessionmaker

WORKER_DIR = Path(__file__).resolve().parents[1]
if str(WORKER_DIR) not in sys.path:
    sys.path.insert(0, str(WORKER_DIR))

URL = os.getenv("ASSIGNMENT_MARIADB_TEST_URL", "").strip()
ALLOW = os.getenv("ASSIGNMENT_ALLOW_DESTRUCTIVE_INTEGRATION", "").strip().upper()


def die(msg: str) -> None:
    raise SystemExit(msg)


def db_name(url: str) -> str:
    return urlparse(url).path.lstrip("/").split("?", 1)[0]


if not URL:
    die("Configure ASSIGNMENT_MARIADB_TEST_URL con una base exclusiva de prueba.")
if ALLOW != "YES":
    die("Configure ASSIGNMENT_ALLOW_DESTRUCTIVE_INTEGRATION=YES.")
name = db_name(URL)
if not name or ("test" not in name.lower() and "sandbox" not in name.lower()):
    die(f"Se rechaza ejecutar contra {name!r}: el nombre debe contener test o sandbox.")

from app import assignment_repo  # noqa: E402

engine = create_engine(URL, future=True, pool_pre_ping=True)
SessionLocal = sessionmaker(bind=engine, autoflush=False, autocommit=False, future=True)


@contextmanager
def db_session():
    db: Session = SessionLocal()
    try:
        yield db
        db.commit()
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()


DDL = [
    """
    CREATE TABLE roles (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      code VARCHAR(50) NOT NULL,
      PRIMARY KEY(id), UNIQUE KEY uq_roles_code(code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """,
    """
    CREATE TABLE users (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      full_name VARCHAR(190) NOT NULL,
      email VARCHAR(190) DEFAULT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      assign_enabled TINYINT(1) NOT NULL DEFAULT 1,
      last_assigned_at DATETIME(6) DEFAULT NULL,
      updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
      PRIMARY KEY(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """,
    """
    CREATE TABLE user_roles (
      user_id BIGINT UNSIGNED NOT NULL,
      role_id BIGINT UNSIGNED NOT NULL,
      PRIMARY KEY(user_id, role_id),
      CONSTRAINT fk_ur_u FOREIGN KEY(user_id) REFERENCES users(id),
      CONSTRAINT fk_ur_r FOREIGN KEY(role_id) REFERENCES roles(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """,
    """
    CREATE TABLE case_statuses (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      code VARCHAR(50) NOT NULL,
      PRIMARY KEY(id), UNIQUE KEY uq_case_status_code(code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """,
    """
    CREATE TABLE cases (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      subject VARCHAR(255) NOT NULL,
      status_id BIGINT UNSIGNED NOT NULL,
      assigned_user_id BIGINT UNSIGNED DEFAULT NULL,
      received_at DATETIME(6) NOT NULL,
      assigned_at DATETIME(6) DEFAULT NULL,
      last_activity_at DATETIME(6) DEFAULT NULL,
      updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
      PRIMARY KEY(id),
      KEY idx_cases_assignment(status_id, assigned_user_id, received_at),
      CONSTRAINT fk_cases_status FOREIGN KEY(status_id) REFERENCES case_statuses(id),
      CONSTRAINT fk_cases_user FOREIGN KEY(assigned_user_id) REFERENCES users(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """,
    """
    CREATE TABLE case_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      case_id BIGINT UNSIGNED NOT NULL,
      actor_user_id BIGINT UNSIGNED DEFAULT NULL,
      source VARCHAR(30) NOT NULL,
      ip_address VARCHAR(80) DEFAULT NULL,
      user_agent VARCHAR(255) DEFAULT NULL,
      event_type VARCHAR(40) NOT NULL,
      from_status_id BIGINT UNSIGNED DEFAULT NULL,
      to_status_id BIGINT UNSIGNED DEFAULT NULL,
      details_json TEXT DEFAULT NULL,
      created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
      PRIMARY KEY(id), KEY idx_case_events_case(case_id),
      CONSTRAINT fk_ce_case FOREIGN KEY(case_id) REFERENCES cases(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """,
    """
    CREATE TABLE agent_presence_statuses (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      code VARCHAR(50) NOT NULL,
      name VARCHAR(100) NOT NULL,
      is_assignable TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY(id), UNIQUE KEY uq_presence_status_code(code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """,
    """
    CREATE TABLE agent_presence (
      user_id BIGINT UNSIGNED NOT NULL,
      status_id BIGINT UNSIGNED NOT NULL,
      status_since DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
      last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
      updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
      PRIMARY KEY(user_id), KEY idx_presence_status(status_id),
      CONSTRAINT fk_ap_user FOREIGN KEY(user_id) REFERENCES users(id),
      CONSTRAINT fk_ap_status FOREIGN KEY(status_id) REFERENCES agent_presence_statuses(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """,
]


def reset() -> None:
    tables = ["agent_presence", "agent_presence_statuses", "case_events", "cases", "case_statuses", "user_roles", "users", "roles"]
    with engine.begin() as c:
        c.execute(text("SET FOREIGN_KEY_CHECKS=0"))
        for t in tables:
            c.execute(text(f"DROP TABLE IF EXISTS {t}"))
        c.execute(text("SET FOREIGN_KEY_CHECKS=1"))
        for ddl in DDL:
            c.execute(text(ddl))
        c.execute(text("INSERT INTO roles(code) VALUES ('AGENTE')"))
        c.execute(text("INSERT INTO case_statuses(code) VALUES ('NUEVO'),('ASIGNADO'),('EN_PROCESO'),('RESPONDIDO')"))
        c.execute(text("INSERT INTO agent_presence_statuses(code,name,is_assignable,is_active) VALUES ('DISPONIBLE','Disponible',1,1),('AUSENTE','Ausente',0,1),('DESCONECTADO','Desconectado',0,1)"))


def seed_agent(name: str, *, status: str = "DISPONIBLE", seen_seconds_ago: int = 0) -> int:
    with engine.begin() as c:
        uid = int(c.execute(text("INSERT INTO users(full_name,email) VALUES (:n,:e)"), {"n": name, "e": f"{name.lower()}@test.local"}).lastrowid)
        c.execute(text("INSERT INTO user_roles(user_id,role_id) VALUES (:u,1)"), {"u": uid})
        seen_at = datetime.now() - timedelta(seconds=max(0, int(seen_seconds_ago)))
        c.execute(text("""
            INSERT INTO agent_presence(user_id,status_id,status_since,last_seen_at)
            SELECT :u,id,:seen_at,:seen_at
            FROM agent_presence_statuses WHERE code=:code
        """), {"u": uid, "seen_at": seen_at, "code": status})
        return uid


def seed_case(subject: str, seconds_offset: int) -> int:
    with engine.begin() as c:
        received_at = datetime.now() + timedelta(seconds=int(seconds_offset))
        return int(c.execute(text("""
          INSERT INTO cases(subject,status_id,received_at)
          SELECT :s,id,:received_at
          FROM case_statuses WHERE code='NUEVO'
        """), {"s": subject, "received_at": received_at}).lastrowid)


def assigned_rows() -> list[tuple]:
    with engine.connect() as c:
        return list(c.execute(text("SELECT id,subject,assigned_user_id FROM cases ORDER BY id")).fetchall())


def assign_once():
    with db_session() as db:
        return assignment_repo.assign_one_case(
            db,
            max_active_cases=2,
            stale_seconds=90,
            active_status_codes=("ASIGNADO", "EN_PROCESO"),
        )


def main() -> None:
    reset()

    # A: FIFO + capacidad 2. Un agente recibe exactamente 2; tercero espera.
    a1 = seed_agent("AgenteUno")
    c1 = seed_case("primero", -30)
    c2 = seed_case("segundo", -20)
    c3 = seed_case("tercero", -10)
    r1, r2, r3 = assign_once(), assign_once(), assign_once()
    assert [r1.status, r2.status, r3.status] == ["assigned", "assigned", "no_capacity"]
    rows = assigned_rows()
    assert rows[0][2] == a1 and rows[1][2] == a1 and rows[2][2] is None
    print("A OK - FIFO + max 2 + excedente permanece en bandeja")

    # B: Ausente no recibe.
    reset()
    seed_agent("Ausente", status="AUSENTE")
    seed_case("espera", -1)
    assert assign_once().status == "no_capacity"
    print("B OK - estado no asignable no recibe")

    # C: Disponible con heartbeat stale no recibe.
    reset()
    seed_agent("Stale", status="DISPONIBLE", seen_seconds_ago=91)
    seed_case("espera", -1)
    assert assign_once().status == "no_capacity"
    print("C OK - heartbeat stale no recibe")

    # D: Balanceo por menor carga.
    reset()
    a1 = seed_agent("A")
    a2 = seed_agent("B")
    seed_case("uno", -20)
    seed_case("dos", -10)
    x1, x2 = assign_once(), assign_once()
    assert x1.agent_id != x2.agent_id
    assert {x1.agent_id, x2.agent_id} == {a1, a2}
    print("D OK - least-loaded reparte entre disponibles")

    # E: concurrencia real: agente con 1/2 y dos casos -> solo un caso nuevo.
    reset()
    a1 = seed_agent("Concurrente")
    existing = seed_case("ya-asignado", -100)
    with engine.begin() as c:
        c.execute(text("""
          UPDATE cases SET assigned_user_id=:u,
            status_id=(SELECT id FROM case_statuses WHERE code='ASIGNADO'),
            assigned_at=NOW(6) WHERE id=:cid
        """), {"u": a1, "cid": existing})
    seed_case("competidor-1", -20)
    seed_case("competidor-2", -10)
    with ThreadPoolExecutor(max_workers=2) as pool:
        results = [f.result() for f in [pool.submit(assign_once), pool.submit(assign_once)]]
    with engine.connect() as c:
        active = int(c.execute(text("""
          SELECT COUNT(*) FROM cases c JOIN case_statuses cs ON cs.id=c.status_id
          WHERE c.assigned_user_id=:u AND cs.code IN ('ASIGNADO','EN_PROCESO')
        """), {"u": a1}).scalar_one())
        pending = int(c.execute(text("""
          SELECT COUNT(*) FROM cases c JOIN case_statuses cs ON cs.id=c.status_id
          WHERE c.assigned_user_id IS NULL AND cs.code='NUEVO'
        """)).scalar_one())
    assert active == 2, (active, results)
    assert pending == 1, (pending, results)
    print("E OK - concurrencia no supera 2/2")

    print("ALL ASSIGNMENT MARIADB TESTS PASSED")


if __name__ == "__main__":
    main()
