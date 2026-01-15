import logging
from typing import Any, Dict, Optional

from fastapi import APIRouter, Request, Query, BackgroundTasks, HTTPException
from fastapi.responses import PlainTextResponse, JSONResponse

from .settings import settings

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/graph", tags=["graph"])


def _process_notifications(payload: Dict[str, Any]) -> None:
    # En este punto (paso siguiente) vas a:
    # - validar y extraer messageId/conversationId
    # - hacer "pull" a Graph
    # - persistir en BD (cases/messages/attachments/case_events)
    logger.info("Graph notification received (async). keys=%s", list(payload.keys()))


@router.get("/webhook", response_class=PlainTextResponse)
async def webhook_get(validationToken: Optional[str] = Query(default=None)) -> PlainTextResponse:
    # Graph normalmente valida por POST, pero GET lo dejamos habilitado para pruebas.
    if validationToken:
        return PlainTextResponse(content=validationToken, media_type="text/plain")
    return PlainTextResponse(content="ok", media_type="text/plain")


@router.post("/webhook")
async def webhook_post(
    request: Request,
    background_tasks: BackgroundTasks,
    validationToken: Optional[str] = Query(default=None),
):
    # 1) VALIDATION TOKEN (obligatorio para crear/renovar suscripción en Graph)
    if validationToken:
        return PlainTextResponse(content=validationToken, media_type="text/plain")

    # 2) NOTIFICACIONES REALES
    payload = await request.json()

    # Validación básica recomendada: clientState
    # Graph manda: {"value":[{"clientState":"..."}]}
    values = payload.get("value") or []
    for n in values:
        cs = n.get("clientState")
        if cs is not None and cs != settings.graph_client_state:
            logger.warning("Invalid clientState received: %s", cs)
            raise HTTPException(status_code=401, detail="Invalid clientState")

    background_tasks.add_task(_process_notifications, payload)
    return JSONResponse(status_code=202, content={"status": "accepted"})
