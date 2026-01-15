import logging
from sqlalchemy import create_engine, text
from sqlalchemy.orm import sessionmaker
from .settings import settings

logger = logging.getLogger(__name__)

engine = create_engine(
    settings.sqlalchemy_url,
    pool_pre_ping=True,
    pool_recycle=1800,
    future=True,
)

SessionLocal = sessionmaker(bind=engine, autoflush=False, autocommit=False, future=True)


def db_ping() -> bool:
    try:
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
        return True
    except Exception as e:
        logger.exception("DB ping failed: %s", e)
        return False
