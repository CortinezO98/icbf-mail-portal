from __future__ import annotations

# =============================================================================
# attachment_recovery.py — Portal ICBF (D2)
#
# Reemplaza el motor viejo (sync_service.recover_missing_attachments,
# eliminado) que solo detectaba mensajes con CERO adjuntos persistidos
# ("0/N"). Este motor usa identidad real (graph_attachment_id) para
# detectar y resolver también 1/N, 2/N, ..., y trata la estabilización
# del manifiesto completo con el mismo cuidado que Fase C le dio al flag
# hasAttachments=false.
#
# Reutiliza, sin reimplementar, la lógica de descarga ya validada en
# D1-A/D1-D (sync_service._process_attachments): este módulo solo decide
# QUÉ subconjunto de adjuntos pedir (missing_ids) y CÓMO interpretar el
# resultado (transient/blocked/N-N), nunca cómo descargar/guardar un
# adjunto individual.
# =============================================================================

import hashlib
import logging
import random
from dataclasses import dataclass, field
from datetime import datetime
from typing import Any
from zoneinfo import ZoneInfo

from app import attachment_recovery_repo as repo
from app.graph_client import graph_client
from app import sync_service
from app.db import get_db_session
from app.inbound_queue_repo import _retry_delay_seconds
from app.settings import settings

logger = logging.getLogger("app.attachment_recovery")


# ---------------------------------------------------------------------------
# Normalización del manifiesto - misma función para ingestion (vía
# sync_service, que la reimplementa inline en _process_attachments/D1-A
# por ahora) y recovery (aquí). No se unificó en un módulo común en esta
# fase para no reabrir D1 ya validado - queda anotado como candidato de
# unificación futura.
# ---------------------------------------------------------------------------

@dataclass
class ManifestNormalization:
    expected_graph_ids: set[str] = field(default_factory=set)
    missing_id: bool = False
    missing_type: bool = False
    unsupported_count: int = 0

    @property
    def unverifiable(self) -> bool:
        return self.missing_id or self.missing_type

    @property
    def unverifiable_reason(self) -> str:
        # missing_id se reporta primero: es el motivo históricamente ya
        # conocido (D1-A); missing_type es una variante nueva de D2.
        if self.missing_id:
            return "MISSING_GRAPH_ATTACHMENT_ID"
        return "MISSING_ATTACHMENT_TYPE"


def _normalize_attachment_manifest(manifest: list[dict[str, Any]]) -> ManifestNormalization:
    norm = ManifestNormalization()

    for a in manifest:
        odata_type = a.get("@odata.type")
        if odata_type is None or not str(odata_type).strip():
            # Graph no mandó el tipo del adjunto - snapshot incompleto,
            # no lo mismo que "tipo no soportado" (eso sí es un tipo
            # conocido que no manejamos, ej. itemAttachment).
            norm.missing_type = True
            continue

        if "fileAttachment" not in str(odata_type):
            norm.unsupported_count += 1
            continue

        raw_id = a.get("id")
        if not raw_id or not str(raw_id).strip():
            norm.missing_id = True
            continue

        norm.expected_graph_ids.add(str(raw_id).strip())

    return norm


def _compute_manifest_hash(expected_graph_ids: set[str]) -> str:
    """Hash del CONJUNTO ordenado de IDs, no del JSON crudo - el orden en
    que Graph devuelve la colección no debe afectar la identidad del
    manifiesto (regla 13 de los tests)."""
    ordered = "\n".join(sorted(expected_graph_ids))
    return hashlib.sha256(ordered.encode("utf-8")).hexdigest()


def _recovery_backoff_seconds(attempts: int) -> int:
    """Ladder normal (30/120/300/900/1800s, reutilizado de
    inbound_queue_repo) durante los primeros 5 intentos; después,
    long-tail fijo con jitter +-15% - sin tope de intentos para fallos
    transitorios (regla H de la auditoría D2)."""
    next_attempt = attempts + 1
    if next_attempt <= 5:
        return _retry_delay_seconds(next_attempt)

    base = int(getattr(settings, "ATTACHMENT_RECOVERY_LONG_TAIL_SECONDS", 21600))
    jitter = base * random.uniform(-0.15, 0.15)
    return max(60, int(base + jitter))


def _is_message_outside_stabilization_window(received_at: datetime | None) -> bool:
    """Reutiliza ATTACHMENTS_STABILIZATION_WINDOW_MINUTES de Fase C
    (regla 4 de la auditoría D2 final) - no se crea una ventana
    independiente. Si received_at falta (no debería, pero defensivo),
    se trata como fuera de ventana para no bloquear complete
    indefinidamente por un dato ausente."""
    if received_at is None:
        return True
    now = datetime.now(ZoneInfo("America/Bogota")).replace(tzinfo=None)
    age_minutes = (now - received_at).total_seconds() / 60
    window = int(getattr(settings, "ATTACHMENTS_STABILIZATION_WINDOW_MINUTES", 15))
    return age_minutes > window


# ---------------------------------------------------------------------------
# Ciclo principal
# ---------------------------------------------------------------------------

# Clasificación de motivos de fallo individual de adjunto (ver
# sync_service._process_attachments / A5, D1-A). PERMANENT nunca se
# reintenta indefinidamente - un archivo rechazado por política no se
# arregla solo. Cualquier motivo no listado aquí se trata como
# TRANSIENT por defecto (más seguro: seguir reintentando que bloquear
# de más).
_PERMANENT_ATTACHMENT_FAILURE_REASONS = frozenset({
    # Único fallo individual que hoy sabemos con certeza que no cambiará
    # con reintentos. MISSING_SHA256 permanece TRANSIENT por diseño D2:
    # si la capa de almacenamiento devolvió un snapshot anómalo, preferimos
    # reintentar antes que bloquear definitivamente el mensaje.
    "REJECTED_BY_POLICY",
})


def _classify_still_missing(
    still_missing: set[str], failures: list[dict[str, Any]]
) -> str:
    """Retorna 'blocked' si TODOS los ids que siguen faltando fallaron
    por un motivo permanente; 'pending' en cualquier otro caso (incluye
    ids sin failure asociado registrado - default seguro: reintentar)."""
    reasons_by_id = {
        f.get("graph_attachment_id"): f.get("reason")
        for f in failures
        if f.get("graph_attachment_id")
    }
    for gid in still_missing:
        reason = reasons_by_id.get(gid)
        if reason not in _PERMANENT_ATTACHMENT_FAILURE_REASONS:
            return "pending"
    return "blocked" if still_missing else "pending"


async def run_attachment_recovery_cycle(*, limit: int | None = None) -> dict[str, int]:
    batch_size = int(limit or getattr(settings, "ATTACHMENT_RECOVERY_BATCH_SIZE", 50))
    stale_lock_minutes = int(getattr(settings, "ATTACHMENT_RECOVERY_STALE_LOCK_MINUTES", 10))

    with get_db_session() as db:
        claimed = repo.claim_batch(
            db, batch_size=batch_size, stale_lock_minutes=stale_lock_minutes
        )

    counts = {"checked": len(claimed), "verifying": 0, "complete": 0, "blocked": 0, "pending": 0}
    if not claimed:
        return counts

    for row in claimed:
        message_id = int(row["message_id"])
        attempts = int(row["attempts"])
        status_bucket = await _process_one_recovery(row=row, message_id=message_id, attempts=attempts)
        counts[status_bucket] += 1

    logger.info(
        "ATTACHMENT_RECOVERY_CYCLE_DONE | checked=%s | verifying=%s | complete=%s | blocked=%s | pending=%s",
        counts["checked"], counts["verifying"], counts["complete"], counts["blocked"], counts["pending"],
    )
    return counts


async def _process_one_recovery(*, row: dict[str, Any], message_id: int, attempts: int) -> str:
    # Guard C: identidad legacy desconocida -> nunca llamar a Graph,
    # nunca intentar diff - bloquear directo.
    with get_db_session() as db:
        if repo.has_legacy_null_identity(db, message_id=message_id):
            repo.mark_blocked(
                db, message_id=message_id,
                reason="LEGACY_ATTACHMENT_IDENTITY_UNKNOWN",
            )
            return "blocked"

        ctx = repo.get_message_context(db, message_id=message_id)

    if ctx is None:
        # El mensaje ya no existe (no debería pasar, FK ON DELETE CASCADE
        # normalmente elimina también la fila de recovery). Si hubo una
        # carrera y la fila aún existe, se libera el lock y se deja marcada
        # para diagnóstico en vez de quedar reclamándose cada 10 minutos.
        logger.warning("ATTACHMENT_RECOVERY_MESSAGE_NOT_FOUND | message_id=%s", message_id)
        with get_db_session() as db:
            repo.mark_blocked(
                db,
                message_id=message_id,
                reason="MESSAGE_CONTEXT_NOT_FOUND",
            )
        return "blocked"

    mailbox_email = ctx["mailbox_email"]
    provider_message_id = ctx["provider_message_id"]

    try:
        manifest = await graph_client.list_attachments(mailbox_email, provider_message_id)
    except Exception as e:
        with get_db_session() as db:
            repo.mark_pending_retry(
                db, message_id=message_id, attempts=attempts,
                reason="GRAPH_FETCH_FAILED", error=str(e),
                delay_seconds=_recovery_backoff_seconds(attempts),
            )
        return "pending"

    norm = _normalize_attachment_manifest(manifest)

    if norm.unverifiable:
        with get_db_session() as db:
            repo.mark_pending_retry(
                db, message_id=message_id, attempts=attempts,
                reason=norm.unverifiable_reason, error="",
                delay_seconds=_recovery_backoff_seconds(attempts),
            )
        return "pending"

    if not norm.expected_graph_ids and norm.unsupported_count == 0:
        # Manifiesto vacío en cuanto a adjuntos soportados. NUNCA se
        # trata como "0 esperados = completo" (regla 2 de la auditoría
        # D2 final) - Graph puede estar sirviendo un snapshot temprano.
        reason = (
            "ATTACHMENT_MANIFEST_EMPTY_UNSTABLE"
            if row.get("expected_count")
            else "ATTACHMENT_MANIFEST_EMPTY"
        )
        with get_db_session() as db:
            repo.mark_pending_retry(
                db, message_id=message_id, attempts=attempts,
                reason=reason, error="",
                delay_seconds=_recovery_backoff_seconds(attempts),
            )
        return "pending"

    with get_db_session() as db:
        persisted_ids = repo.get_persisted_graph_ids(db, message_id=message_id)

    missing_ids = norm.expected_graph_ids - persisted_ids

    if missing_ids:
        subset_manifest = [
            a for a in manifest
            if str(a.get("id") or "").strip() in missing_ids
        ]
        result = await sync_service._process_attachments(
            mailbox_email=mailbox_email,
            graph_message_id=provider_message_id,
            message_pk=message_id,
            provider_message_id=provider_message_id,
            case_id=ctx.get("case_id"),
            conversation_id=ctx.get("conversation_id"),
            attachments_manifest=subset_manifest,
        )

        with get_db_session() as db:
            persisted_ids = repo.get_persisted_graph_ids(db, message_id=message_id)
        still_missing = norm.expected_graph_ids - persisted_ids

        if not still_missing:
            # Todo lo esperado quedó persistido EN ESTE ciclo -> directo
            # a verifying/blocked, sin gastar un backoff extra en
            # "descubrir" lo que ya sabemos (regla 3 de la auditoría D2
            # final). attempts NO se incrementa: esto fue un éxito.
            return await _advance_after_full_manifest(
                message_id=message_id, row=row, norm=norm,
                received_at=ctx.get("received_at"),
            )

        downloaded_now = norm.expected_graph_ids & persisted_ids
        bucket = _classify_still_missing(still_missing, result.get("failures") or [])

        if bucket == "blocked":
            with get_db_session() as db:
                repo.mark_blocked(
                    db, message_id=message_id,
                    reason="REJECTED_BY_POLICY",
                    error=str(result.get("failures", ""))[:1000],
                )
            return "blocked"

        with get_db_session() as db:
            repo.mark_pending_retry(
                db, message_id=message_id, attempts=attempts,
                reason=("PARTIAL_DOWNLOAD" if downloaded_now else "NO_PROGRESS"),
                error=str(result.get("failures", ""))[:1000],
                delay_seconds=_recovery_backoff_seconds(attempts),
                expected_count=len(norm.expected_graph_ids),
                downloaded_count=len(downloaded_now),
            )
        return "pending"

    # missing_ids vacío desde el inicio: ya era N/N antes de intentar nada.
    return await _advance_after_full_manifest(
        message_id=message_id, row=row, norm=norm, received_at=ctx.get("received_at"),
    )


async def _advance_after_full_manifest(
    *,
    message_id: int,
    row: dict[str, Any],
    norm: ManifestNormalization,
    received_at: datetime | None,
) -> str:
    """Se llama SOLO cuando expected_graph_ids está totalmente en
    persisted (N/N real). Decide entre blocked (tipos no soportados
    presentes), verifying (primera lectura N/N, o el manifiesto cambió
    desde la última) o complete (segunda lectura estable Y fuera de la
    ventana de estabilización de Fase C)."""
    if norm.unsupported_count > 0:
        with get_db_session() as db:
            repo.mark_blocked(
                db, message_id=message_id,
                reason="UNSUPPORTED_ATTACHMENT_TYPE",
                error=f"{norm.unsupported_count} adjunto(s) de tipo no soportado",
            )
        return "blocked"

    current_hash = _compute_manifest_hash(norm.expected_graph_ids)
    expected_count = len(norm.expected_graph_ids)
    verification_delay = int(
        getattr(settings, "ATTACHMENT_RECOVERY_VERIFICATION_DELAY_SECONDS", 120)
    )

    was_verifying = row.get("status") == "verifying"
    same_hash = row.get("manifest_hash") == current_hash

    if was_verifying and same_hash:
        # Segunda (o más) lectura estable: mismo manifest_hash, N/N. Solo
        # falta confirmar que el mensaje ya no está en la ventana de
        # estabilización de Fase C antes de declarar complete (regla 4).
        if _is_message_outside_stabilization_window(received_at):
            with get_db_session() as db:
                repo.mark_complete(db, message_id=message_id)
            return "complete"

        # Sigue dentro de la ventana: se renueva la espera sin cambiar
        # el hash (no es una regresión, es "todavía reciente").
        with get_db_session() as db:
            repo.mark_verifying(
                db, message_id=message_id, manifest_hash=current_hash,
                expected_count=expected_count, downloaded_count=expected_count,
                verification_delay_seconds=verification_delay,
            )
        return "verifying"

    # Primera vez en N/N, o el manifiesto cambió desde la última lectura
    # (regla F: reset de la estabilización, no un complete falso).
    with get_db_session() as db:
        repo.mark_verifying(
            db, message_id=message_id, manifest_hash=current_hash,
            expected_count=expected_count, downloaded_count=expected_count,
            verification_delay_seconds=verification_delay,
        )
    return "verifying"
