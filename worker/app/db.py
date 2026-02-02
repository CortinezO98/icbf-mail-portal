from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator, Optional, Dict

from sqlalchemy import create_engine, text
from sqlalchemy.engine import Engine, Connection
from sqlalchemy.exc import SQLAlchemyError

from .settings import Settings, RuntimeConfig


_engine: Optional[Engine] = None


def init_engine(settings: Settings) -> Engine:
    global _engine
    if _engine is None:
        _engine = create_engine(
            settings.db_url(),
            pool_pre_ping=True,
            pool_size=settings.DB_POOL_SIZE,
            max_overflow=settings.DB_MAX_OVERFLOW,
            future=True,
        )
    return _engine


@contextmanager
def db_conn(engine: Engine) -> Iterator[Connection]:
    conn = engine.connect()
    trans = conn.begin()
    try:
        yield conn
        trans.commit()
    except Exception:
        trans.rollback()
        raise
    finally:
        conn.close()


def load_runtime_config_from_db(engine: Engine) -> RuntimeConfig:
    """
    Reads system_config for operational (non-secret) settings.
    Missing keys are ignored.
    """
    keys = [
        "ATTACHMENTS_DIR",
        "MAX_ATTACHMENT_SIZE_MB",
        "ALLOWED_ATTACHMENT_EXT",
        "BLOCKED_ATTACHMENT_EXT",
        "MAILBOX_EMAIL",
    ]
    cfg: Dict[str, str] = {}
    with engine.connect() as conn:
        rows = conn.execute(
            text("SELECT config_key, config_value FROM system_config WHERE config_key IN :keys"),
            {"keys": tuple(keys)},
        ).fetchall()
        for k, v in rows:
            if v is not None:
                cfg[str(k)] = str(v)

    return RuntimeConfig(
        ATTACHMENTS_DIR=cfg.get("ATTACHMENTS_DIR"),
        MAX_ATTACHMENT_SIZE_MB=int(cfg["MAX_ATTACHMENT_SIZE_MB"]) if "MAX_ATTACHMENT_SIZE_MB" in cfg else None,
        ALLOWED_ATTACHMENT_EXT=cfg.get("ALLOWED_ATTACHMENT_EXT"),
        BLOCKED_ATTACHMENT_EXT=cfg.get("BLOCKED_ATTACHMENT_EXT"),
        MAILBOX_EMAIL=cfg.get("MAILBOX_EMAIL"),
    )
