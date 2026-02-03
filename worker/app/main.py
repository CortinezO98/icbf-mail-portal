from __future__ import annotations
from app.tls_bootstrap import bootstrap_tls_from_os_truststore

bootstrap_tls_from_os_truststore()

import logging
from fastapi import FastAPI

from app.settings import settings
from app.webhook import router as webhook_router
from app.subscriptions_routes import router as subs_router


logger = logging.getLogger("app.main")


def create_app() -> FastAPI:
    app = FastAPI(title="ICBF Mail Worker", version="1.0.0")

    @app.on_event("startup")
    def on_startup() -> None:
        # Log seguro (NO imprime secretos)
        logger.warning(
            "STARTUP | env=%s | host=%s | port=%s | mailbox=%s | admin_key_configured=%s | public_base_url=%s | env_file=%s",
            settings.ENV,
            settings.HOST,
            settings.PORT,
            settings.MAILBOX_EMAIL,
            bool(settings.ADMIN_API_KEY),
            settings.PUBLIC_BASE_URL,
            "worker/.env",
        )

    @app.get("/health")
    def health() -> dict:
        return {"status": "ok", "env": settings.ENV}

    app.include_router(webhook_router)
    app.include_router(subs_router)
    return app


app = create_app()
