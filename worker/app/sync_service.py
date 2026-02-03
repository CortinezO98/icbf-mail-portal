from __future__ import annotations

import logging

logger = logging.getLogger("app.sync_service")


async def process_notifications_async(payload: dict) -> None:
    """
    En el siguiente paso:
    - validar notificación
    - pull a Graph
    - dedupe
    - persistir en BD
    - guardar adjuntos
    """
    count = len(payload.get("value", []) or [])
    logger.info("Received notifications: %s", count)
