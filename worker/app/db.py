from __future__ import annotations

from sqlalchemy import create_engine, text
from sqlalchemy.orm import sessionmaker, Session
from contextlib import contextmanager
from app.settings import settings


def build_db_url() -> str:
    # MySQL/MariaDB via PyMySQL
    # mysql+pymysql://user:pass@host:port/db?charset=utf8mb4
    user = settings.DB_USER
    pwd = settings.DB_PASSWORD or ""
    host = settings.DB_HOST
    port = settings.DB_PORT
    db = settings.DB_NAME
    return f"mysql+pymysql://{user}:{pwd}@{host}:{port}/{db}?charset=utf8mb4"


engine = create_engine(
    build_db_url(),
    pool_pre_ping=True,
    pool_size=settings.DB_POOL_SIZE,
    max_overflow=settings.DB_MAX_OVERFLOW,
    future=True,
)

SessionLocal = sessionmaker(bind=engine, autoflush=False, autocommit=False, future=True)


@contextmanager
def get_db_session() -> Session:
    db: Session = SessionLocal()
    try:
        yield db
        db.commit()
    except Exception:
        db.rollback()
        raise
    finally:
        db.close()


def ping_db() -> None:
    with engine.connect() as conn:
        conn.execute(text("SELECT 1"))
