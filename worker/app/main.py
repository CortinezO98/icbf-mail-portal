import os
import logging

from fastapi import FastAPI
from fastapi.responses import JSONResponse

from .settings import settings
from .logging_conf import setup_logging
from .webhook import router as graph_router
from .db import db_ping

setup_logging()
logger = logging.getLogger(__name__)

app = FastAPI(title=settings.app_name)


@app.on_event("startup")
def on_startup():
    # Validar storage
    os.makedirs(settings.attachments_dir, exist_ok=True)
    logger.info("Worker starting | env=%s | attachments_dir=%s", settings.env, settings.attachments_dir)


@app.get("/health")
def health():
    # Salud mínima: app + storage + db (si DB no está lista aún, te lo muestra)
    storage_ok = os.path.isdir(settings.attachments_dir)
    db_ok = db_ping()
    status = "ok" if storage_ok and db_ok else "degraded"
    return JSONResponse(
        {
            "status": status,
            "app": settings.app_name,
            "env": settings.env,
            "storage_ok": storage_ok,
            "db_ok": db_ok,
        }
    )


app.include_router(graph_router)
