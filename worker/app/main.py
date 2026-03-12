from __future__ import annotations

from app.tls_bootstrap import bootstrap_tls_from_os_truststore

bootstrap_tls_from_os_truststore()

from app.logging_conf import setup_logging

setup_logging()

import logging
from fastapi import FastAPI

from app.settings import settings
from app.webhook import (
    router as webhook_router,
    start_webhook_workers,
    stop_webhook_workers,
)
from app.inbound_queue_worker import (
    start_inbound_queue_worker,
    stop_inbound_queue_worker,
)
from app.subscriptions_routes import router as subs_router
from app.delta_routes import router as delta_router
from app.background_jobs import start_background_jobs, stop_background_jobs

logger = logging.getLogger("app.main")


def create_app() -> FastAPI:
    app = FastAPI(title="ICBF Mail Worker", version="1.0.0")

    @app.on_event("startup")
    async def on_startup() -> None:
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

        # 1) Cola rápida en memoria para webhook
        await start_webhook_workers()

        # 2) Cola persistente en base de datos
        await start_inbound_queue_worker()

        # 3) Jobs de respaldo: subscription / delta / reconcile
        await start_background_jobs()

    @app.on_event("shutdown")
    async def on_shutdown() -> None:
        # Orden inverso al startup
        await stop_background_jobs()
        await stop_inbound_queue_worker()
        await stop_webhook_workers()

    @app.get("/health")
    @app.head("/health")
    def health() -> dict:
        return {"status": "ok", "env": settings.ENV}

    app.include_router(webhook_router)
    app.include_router(subs_router)
    app.include_router(delta_router)

    return app
app = create_app()