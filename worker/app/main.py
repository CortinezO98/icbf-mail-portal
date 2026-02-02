from __future__ import annotations

import logging
from fastapi import FastAPI
from fastapi.responses import JSONResponse
from app.settings import Settings          
from app.logging_conf import setup_logging
from app.db import ping_db, get_db_session
from app.storage import ensure_base_dir
from app.webhook import router as webhook_router
from app.repos import load_system_config

logger = logging.getLogger("app.main")

# 👇 INSTANCIA AQUÍ (NO importar settings)
settings = Settings()


def create_app() -> FastAPI:
    setup_logging(settings.LOG_LEVEL)

    app = FastAPI(
        title="ICBF Mail Worker",
        version="1.0.0",
    )

    @app.on_event("startup")
    def on_startup():
        ensure_base_dir()
        ping_db()

        # Opcional: cargar config de system_config (prod)
        if int(settings.DB_CONFIG_ENABLED) == 1:
            with get_db_session() as db:
                cfg = load_system_config(db)

            # Sobrescrituras seguras
            if cfg.get("MAX_ATTACHMENT_SIZE_MB"):
                settings.MAX_ATTACHMENT_SIZE_MB = int(cfg["MAX_ATTACHMENT_SIZE_MB"])

            logger.info("Loaded system_config overrides (enabled=1)")

        logger.info(
            "Worker starting | env=%s | attachments_dir=%s",
            settings.ENV,
            settings.ATTACHMENTS_DIR,
        )

    @app.get("/health")
    def health():
        return JSONResponse({"ok": True, "env": settings.ENV})

    app.include_router(webhook_router)
    return app


app = create_app()
