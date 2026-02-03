from __future__ import annotations

import logging
from fastapi import FastAPI

from app.logging_conf import setup_logging
from app.settings import settings
from app.db import ping_db
from app.webhook import router as webhook_router

logger = logging.getLogger("app.main")


def create_app() -> FastAPI:
    setup_logging()

    app = FastAPI(title="ICBF Mail Worker", version="1.0.0")

    @app.on_event("startup")
    def on_startup() -> None:
        logger.info(
            "Worker starting | env=%s | attachments_dir=%s | db=%s@%s:%s/%s",
            settings.ENV,
            str(settings.attachments_path()),
            settings.DB_USER,
            settings.DB_HOST,
            settings.DB_PORT,
            settings.DB_NAME,
        )

        # Verifica DB (si falla: es mejor que te lo diga en startup)
        ping_db()

        # Asegura carpeta adjuntos exista (local/prod)
        settings.attachments_path().mkdir(parents=True, exist_ok=True)

    @app.get("/health")
    def health() -> dict:
        return {"status": "ok", "env": settings.ENV}

    app.include_router(webhook_router)

    return app


# ✅ ESTA ES LA VARIABLE QUE Uvicorn BUSCA: app
app = create_app()
