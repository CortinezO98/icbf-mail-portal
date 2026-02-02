from __future__ import annotations

import logging
from fastapi import FastAPI
from fastapi.responses import JSONResponse

from .logging_conf import setup_logging
from .settings import load_settings, apply_runtime_config
from .db import init_engine, load_runtime_config_from_db
from .webhook import router as graph_router

log = logging.getLogger("app.main")


def create_app() -> FastAPI:
    settings = load_settings()
    setup_logging(settings.LOG_LEVEL)

    engine = init_engine(settings)

    # Load DB runtime config only when enabled (recommended in prod)
    if settings.ENV.lower() == "prod" and int(settings.DB_CONFIG_ENABLED) == 1:
        try:
            runtime = load_runtime_config_from_db(engine)
            settings = apply_runtime_config(settings, runtime)
            log.info("Runtime config loaded from DB system_config")
        except Exception:
            log.exception("Failed loading runtime config from DB; continuing with ENV/.env")

    app = FastAPI(title="ICBF Mail Worker", version="1.0.0")

    @app.get("/health")
    def health():
        return JSONResponse(
            {
                "ok": True,
                "env": settings.ENV,
                "attachments_dir": settings.ATTACHMENTS_DIR,
            }
        )

    app.include_router(graph_router)

    @app.on_event("startup")
    def _startup():
        log.info(
            "Worker starting | env=%s | attachments_dir=%s",
            settings.ENV,
            settings.ATTACHMENTS_DIR,
        )

    return app


app = create_app()
