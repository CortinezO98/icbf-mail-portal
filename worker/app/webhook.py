from __future__ import annotations

# =============================================================================
# webhook.py — Portal ICBF
# CAMBIOS v3 (2026-08-18):
#   - Se elimina la cola en memoria (_webhook_queue) y su consumidor
#     (_webhook_consumer), que llamaban directamente a
#     sync_service.process_message_id_async(). Esa llamada directa era una
#     puerta de materialización paralela a inbound_queue_worker: bajo
#     concurrencia, ambas podían procesar el mismo message_id casi a la
#     vez. La constraint UNIQUE(mailbox_id, provider_message_id) de
#     `messages` ya evitaba que esto duplicara datos (la transacción
#     perdedora hace rollback completo), pero igual desperdiciaba llamadas
#     a Graph y generaba ruido en los logs de fallo.
#   - El webhook ahora SOLO valida la notificación, la persiste en
#     inbound_event_queue y responde 202. inbound_queue_worker (poll cada
#     INBOUND_QUEUE_POLL_SECONDS, default 2s) es la única puerta de
#     materialización. La latencia adicional (hasta ~2s) es despreciable
#     frente al SLA de 4 horas.
# CAMBIOS v2 (2026-03-12):
#   - El enqueue_event del webhook ya NO bloquea mensajes conocidos gracias
#     al nuevo inbound_queue_repo. El log QUEUE_EVENT_SKIPPED_ALREADY_MATERIALIZED
#     se renombra a QUEUE_EVENT_SKIPPED_ALREADY_IN_QUEUE para mayor claridad.
# =============================================================================

import json
import logging
from typing import Any

from fastapi import APIRouter, Request, Response

from app.settings import settings
from app import sync_service
from app.db import get_db_session
from app import inbound_queue_repo

logger = logging.getLogger("app.webhook")
router = APIRouter()


@router.get("/graph/webhook")
async def graph_webhook_get(request: Request) -> Response:
    token = request.query_params.get("validationToken")
    if token:
        return Response(content=token, media_type="text/plain", status_code=200)
    return Response(content="OK", media_type="text/plain", status_code=200)


@router.post("/graph/webhook")
async def graph_webhook_post(request: Request) -> Response:
    token = request.query_params.get("validationToken")
    if token:
        return Response(content=token, media_type="text/plain", status_code=200)

    try:
        raw = await request.body()
        payload = json.loads(raw.decode("utf-8")) if raw else {}
    except Exception:
        logger.warning("Webhook invalid JSON | ip=%s", _client_ip(request))
        return Response(status_code=202)

    notifications = payload.get("value") or []
    if not isinstance(notifications, list):
        logger.warning(
            "Webhook invalid payload shape | ip=%s | keys=%s",
            _client_ip(request),
            list(payload.keys()),
        )
        return Response(status_code=202)

    valid: list[dict[str, Any]] = []
    invalid = 0

    for n in notifications:
        if not isinstance(n, dict):
            invalid += 1
            continue

        cs = n.get("clientState")
        if cs and cs == settings.GRAPH_CLIENT_STATE:
            valid.append(n)
        else:
            invalid += 1

    subs = list({(n.get("subscriptionId") or "") for n in valid if isinstance(n, dict)})

    logger.info(
        "Webhook received | ip=%s | total=%s | valid=%s | invalid=%s | subs=%s",
        _client_ip(request),
        len(notifications),
        len(valid),
        invalid,
        subs[:5],
    )

    if not valid:
        if invalid:
            logger.warning(
                "Webhook: all notifications rejected (clientState mismatch or invalid objects) | ip=%s",
                _client_ip(request),
            )
        return Response(content="OK", media_type="text/plain", status_code=202)

    persisted = 0
    skipped = 0

    for n in valid:
        msg_id = sync_service._extract_message_id(n)
        if not msg_id:
            logger.warning("Skipping notification without message id")
            continue

        try:
            # Persistir en la cola durable (inbound_event_queue).
            # inbound_queue_worker es quien recoge y materializa: única
            # puerta de procesamiento, sin atajo en memoria.
            with get_db_session() as db:
                event_id = inbound_queue_repo.enqueue_event(
                    db,
                    source="webhook",
                    provider_message_id=msg_id,
                    mailbox_email=settings.MAILBOX_EMAIL,
                    payload=n,
                    # force=False en webhook: el inbound_queue_repo ya maneja
                    # el reciclaje correcto. No forzamos aquí porque el webhook
                    # trae mensajes genuinamente nuevos.
                )

            if event_id is not None:
                persisted += 1
                logger.info(
                    "QUEUE_EVENT_CREATED | source=webhook | event_id=%s | message_id=%s",
                    event_id,
                    msg_id,
                )
            else:
                # El evento ya estaba en cola pendiente o fue reciclado
                skipped += 1
                logger.info(
                    "QUEUE_EVENT_SKIPPED_ALREADY_IN_QUEUE | source=webhook | message_id=%s",
                    msg_id,
                )

        except Exception as e:
            logger.exception(
                "WEBHOOK_PERSIST_FAILED | message_id=%s | err=%s",
                msg_id,
                e,
            )

    logger.info(
        "Webhook processed notifications | persisted=%s | skipped=%s",
        persisted,
        skipped,
    )
    return Response(content="OK", media_type="text/plain", status_code=202)


def _client_ip(request: Request) -> str:
    xff = request.headers.get("x-forwarded-for")
    if xff:
        return xff.split(",")[0].strip()
    if request.client:
        return request.client.host
    return "unknown"