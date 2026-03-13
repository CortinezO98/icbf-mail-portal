#!/usr/bin/env python3
"""
=============================================================================
recover_missing_cases.py — Portal ICBF
=============================================================================
Script de recuperación para reprocesar todos los correos del buzón que
NO tienen un caso asociado en el portal.

USO:
    python recover_missing_cases.py [--dry-run] [--lookback-hours N]

OPCIONES:
    --dry-run           Solo muestra qué se procesaría, sin crear casos.
    --lookback-hours N  Cuántas horas hacia atrás buscar (default: 72).
    --limit N           Máximo de mensajes a procesar (default: 500).

DESCRIPCIÓN:
    1. Consulta Graph API para obtener los últimos N mensajes del inbox.
    2. Por cada mensaje, verifica si ya existe un caso en la BD.
    3. Si NO existe caso: lo encola para que sync_service cree el caso.
    4. Genera un reporte al final.

IMPORTANTE:
    - Ejecutar con el entorno virtual del proyecto activado.
    - Requiere que las variables de entorno del proyecto estén cargadas.
    - El script es SEGURO de ejecutar múltiples veces (idempotente).
=============================================================================
"""

from __future__ import annotations

import argparse
import asyncio
import logging
import sys
from datetime import datetime, timedelta, timezone

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s | %(levelname)s | %(name)s | %(message)s",
    stream=sys.stdout,
)
logger = logging.getLogger("recover_missing_cases")


async def main(dry_run: bool, lookback_hours: int, limit: int) -> None:
    # Importaciones dentro de main para respetar el entorno del proyecto
    from app.settings import settings
    from app.db import get_db_session
    from app.graph_client import graph_client
    from app import inbound_queue_repo, repos
    from sqlalchemy import text

    mailbox = settings.MAILBOX_EMAIL
    if not mailbox:
        logger.error("MAILBOX_EMAIL no configurado. Abortando.")
        sys.exit(1)

    logger.info("=" * 70)
    logger.info("RECUPERACIÓN DE CASOS FALTANTES — Portal ICBF")
    logger.info("Buzón        : %s", mailbox)
    logger.info("Lookback     : %s horas", lookback_hours)
    logger.info("Límite       : %s mensajes", limit)
    logger.info("Modo dry-run : %s", dry_run)
    logger.info("=" * 70)

    # Obtener mailbox_id
    with get_db_session() as db:
        mailbox_id = repos.get_or_create_mailbox(db, mailbox)
    logger.info("mailbox_id=%s", mailbox_id)

    # Calcular cutoff
    cutoff = datetime.now(timezone.utc) - timedelta(hours=lookback_hours)
    logger.info("Buscando mensajes desde: %s UTC", cutoff.strftime("%Y-%m-%d %H:%M"))

    # Recolectar mensajes del inbox via Graph API (paginando)
    all_messages: list[dict] = []
    url = None
    page = 0
    page_size = 50

    logger.info("Consultando Graph API...")

    while len(all_messages) < limit:
        page += 1
        if url:
            resp = await graph_client._request("GET", url)
            status = resp.status_code
            try:
                data = resp.json()
            except Exception:
                data = {}
        else:
            status, data = await graph_client.messages_delta_page(
                mailbox_email=mailbox,
                folder_code="INBOX",
                graph_folder_id=None,
                url=None,
                page_size=page_size,
            )

        if status != 200:
            logger.error("Graph API error status=%s | Abortando.", status)
            sys.exit(1)

        items = data.get("value") or []
        if not isinstance(items, list):
            break

        added_this_page = 0
        for item in items:
            if not isinstance(item, dict):
                continue

            # Filtro por fecha
            received_raw = (
                item.get("receivedDateTime") or item.get("createdDateTime")
            )
            if received_raw:
                try:
                    dt = datetime.fromisoformat(
                        str(received_raw).replace("Z", "+00:00")
                    )
                    if dt.tzinfo is None:
                        dt = dt.replace(tzinfo=timezone.utc)
                    if dt < cutoff:
                        continue
                except Exception:
                    pass

            msg_id = item.get("id")
            if not msg_id:
                continue

            all_messages.append(item)
            added_this_page += 1

            if len(all_messages) >= limit:
                break

        logger.info("Página %s: %s mensajes en rango", page, added_this_page)

        next_link = data.get("@odata.nextLink")
        if not next_link or len(all_messages) >= limit:
            break

        url = next_link

    logger.info("Total mensajes encontrados en inbox: %s", len(all_messages))

    # Analizar cuáles NO tienen caso
    missing: list[str] = []
    already_ok: list[str] = []
    no_case_found: list[str] = []

    with get_db_session() as db:
        for item in all_messages:
            msg_id = str(item.get("id") or "")
            if not msg_id:
                continue

            # Verificar si existe en messages con un case_id
            row = db.execute(
                text("""
                    SELECT m.id, m.case_id, c.id as case_exists
                    FROM messages m
                    LEFT JOIN cases c ON c.id = m.case_id
                    WHERE m.mailbox_id = :mbid
                      AND m.provider_message_id = :pmid
                    LIMIT 1
                """),
                {"mbid": mailbox_id, "pmid": msg_id},
            ).fetchone()

            if row and row[2]:
                # Mensaje existe y tiene caso válido
                already_ok.append(msg_id)
            else:
                # No existe mensaje O existe pero sin caso -> necesita reprocesamiento
                missing.append(msg_id)
                subject = str(item.get("subject") or "(Sin asunto)")[:60]
                received = item.get("receivedDateTime", "?")
                from_addr = (
                    (item.get("from") or {})
                    .get("emailAddress", {})
                    .get("address", "?")
                )
                logger.info(
                    "  SIN CASO | id=...%s | from=%s | received=%s | subject=%s",
                    msg_id[-12:],
                    from_addr,
                    received,
                    subject,
                )

    logger.info("")
    logger.info("=" * 70)
    logger.info("RESUMEN DEL ANÁLISIS:")
    logger.info("  Mensajes con caso OK  : %s", len(already_ok))
    logger.info("  Mensajes SIN caso     : %s", len(missing))
    logger.info("=" * 70)

    if not missing:
        logger.info("No hay mensajes sin caso. Todo está sincronizado.")
        return

    if dry_run:
        logger.info(
            "DRY-RUN: Se encolarían %s mensajes. No se hizo ningún cambio.",
            len(missing),
        )
        return

    # Encolar los mensajes faltantes
    logger.info("Encolando %s mensajes para crear sus casos...", len(missing))

    enqueued = 0
    failed = 0

    with get_db_session() as db:
        for msg_id in missing:
            try:
                event_id = inbound_queue_repo.enqueue_event(
                    db,
                    source="manual",
                    provider_message_id=msg_id,
                    mailbox_email=mailbox,
                    payload=None,
                    force=True,  # Forzar re-encolamiento
                )
                if event_id is not None:
                    enqueued += 1
                    logger.info(
                        "ENQUEUED | event_id=%s | message_id=...%s",
                        event_id, msg_id[-12:],
                    )
                else:
                    # Ya está en la cola pendiente
                    enqueued += 1
                    logger.info(
                        "ALREADY_IN_QUEUE | message_id=...%s", msg_id[-12:]
                    )
            except Exception as e:
                failed += 1
                logger.error(
                    "ENQUEUE_FAILED | message_id=...%s | err=%s",
                    msg_id[-12:], e,
                )

    logger.info("")
    logger.info("=" * 70)
    logger.info("RECUPERACIÓN COMPLETADA:")
    logger.info("  Encolados exitosamente : %s", enqueued)
    logger.info("  Fallidos               : %s", failed)
    logger.info("")
    logger.info(
        "Los casos se crearán en los próximos ciclos del worker."
    )
    logger.info(
        "Monitorea los logs del worker con: "
        "journalctl -u Portal-ICBF -f | grep CASE_CREATED"
    )
    logger.info("=" * 70)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Recupera correos sin caso en el Portal ICBF"
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Solo analiza sin hacer cambios",
    )
    parser.add_argument(
        "--lookback-hours",
        type=int,
        default=72,
        help="Horas hacia atrás a buscar (default: 72)",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=500,
        help="Máximo de mensajes a procesar (default: 500)",
    )
    return parser.parse_args()


if __name__ == "__main__":
    args = parse_args()
    asyncio.run(
        main(
            dry_run=args.dry_run,
            lookback_hours=args.lookback_hours,
            limit=args.limit,
        )
    )
