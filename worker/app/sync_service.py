from __future__ import annotations
import asyncio
import base64
import logging
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any
from zoneinfo import ZoneInfo

from sqlalchemy import text

from app.settings import settings
from app.graph_client import graph_client
from app.db import get_db_session
from app import repos
from app.storage import save_attachment_bytes

logger = logging.getLogger("app.sync_service")



# Utilidades
def _iso_to_dt(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        dt = datetime.fromisoformat(value.replace("Z", "+00:00"))
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        bogota_tz = ZoneInfo("America/Bogota")
        dt_bogota = dt.astimezone(bogota_tz)
        return dt_bogota.replace(tzinfo=None)
    except Exception:
        return None


def _emails(recipients: list[dict[str, Any]] | None) -> str | None:
    if not recipients:
        return None
    out: list[str] = []
    for r in recipients:
        ea = (r.get("emailAddress") or {})
        addr = ea.get("address")
        if addr:
            out.append(str(addr))
    return ";".join(out) if out else None


def _header_value(headers: list[dict[str, Any]] | None, name: str) -> str | None:
    if not headers:
        return None
    lname = name.lower()
    for h in headers:
        if not isinstance(h, dict):
            continue
        if str(h.get("name", "")).lower() == lname:
            v = h.get("value")
            return str(v) if v else None
    return None


def _classify_message_kind(
    subject: str,
    from_email: str,
    body_html: str | None,
    body_text: str | None,
) -> tuple[str, str | None]:
    s = (subject or "").strip().lower()
    f = (from_email or "").strip().lower()
    b = ((body_text or "") + " " + (body_html or "")).lower()

    subject_prefixes = [
        "no se puede entregar:",
        "undeliverable:",
        "delivery status notification",
        "mail delivery failed",
        "returned mail",
        "your mailbox is full",
    ]
    system_sender_tokens = [
        "microsoftexchange",
        "postmaster@",
        "mailer-daemon",
    ]
    body_tokens = [
        "delivery has failed",
        "no se pudo entregar",
        "5.2.2",
        "quotaexceeded",
        "mailbox full",
    ]

    subject_match = next((p for p in subject_prefixes if s.startswith(p)), None)
    sender_match = next((t for t in system_sender_tokens if t in f), None)
    body_match = next((t for t in body_tokens if t in b), None)

    if subject_match and sender_match:
        return "ndr", f"subject={subject_match};sender={sender_match}"
    if sender_match and body_match:
        return "ndr", f"sender={sender_match};body={body_match}"
    return "normal", None


def _extract_message_id(notification: dict[str, Any]) -> str | None:
    rd = notification.get("resourceData")
    if isinstance(rd, dict) and rd.get("id"):
        return str(rd["id"])
    res = notification.get("resource")
    if not isinstance(res, str) or not res:
        return None
    last = res.rstrip("/").split("/")[-1]
    if last.startswith("messages(") and last.endswith(")"):
        inner = last[len("messages("): -1].strip().strip("'").strip('"')
        return inner or None
    return last or None


def _normalize_notifications(
    payload_or_list: dict[str, Any] | list[dict[str, Any]],
) -> list[dict[str, Any]]:
    if isinstance(payload_or_list, list):
        return [n for n in payload_or_list if isinstance(n, dict)]
    if isinstance(payload_or_list, dict):
        value = payload_or_list.get("value") or []
        if isinstance(value, list):
            return [n for n in value if isinstance(n, dict)]
    return []


def _should_accept(notification: dict[str, Any]) -> bool:
    cs = notification.get("clientState")
    return bool(cs) and cs == settings.GRAPH_CLIENT_STATE



# ---------------------------------------------------------------------------
# Completeness Gate
#
# Microsoft Graph puede responder HTTP 200 con un snapshot del mensaje que
# todavia no esta completo (consistencia eventual): el body puede venir sin
# el objeto `body`, o `hasAttachments=true` sin que /attachments devuelva
# nada todavia. Antes de este cambio, cualquier snapshot con HTTP 200 se
# insertaba tal cual, aunque estuviera incompleto, y quedaba asi para
# siempre.
#
# El gate corre DESPUES de obtener el mensaje de Graph y ANTES de cualquier
# INSERT. Si el snapshot no esta listo, _process_single_message retorna sin
# tocar la base de datos y el evento vuelve a la cola (mecanismo de retry ya
# existente en inbound_queue_worker/inbound_queue_repo, sin cambios) - salvo
# que se haya agotado el presupuesto de reintentos, caso en que se
# materializa en modo degradado (ver _process_single_message).
# ---------------------------------------------------------------------------

# Fuente única del status de "falta receivedDateTime", compartida entre
# sync_service (quien lo produce) e inbound_queue_worker (quien lo
# consume para decidir el camino de retry sin límite). Evita que el
# literal "incomplete:MISSING_RECEIVED_DATETIME" quede duplicado en dos
# módulos y se desincronice si alguno cambia.
REASON_MISSING_RECEIVED_DATETIME = "MISSING_RECEIVED_DATETIME"
STATUS_MISSING_RECEIVED_DATETIME = f"incomplete:{REASON_MISSING_RECEIVED_DATETIME}"

REASON_ATTACHMENTS_FLAG_UNSTABLE = "ATTACHMENTS_FLAG_UNSTABLE"


@dataclass
class CompletenessResult:
    complete: bool
    reasons: list[str] = field(default_factory=list)
    # Manifiesto de adjuntos ya obtenido de Graph durante la evaluacion
    # (si hasAttachments=True, o si se confirmó vía la verificación de
    # estabilización aunque el flag dijera False) - se reutiliza en
    # _process_attachments para no volver a pedirlo.
    attachments_manifest: list[dict[str, Any]] | None = None
    # True si específicamente el motivo de incompletitud incluye adjuntos
    # (manifiesto no listo con hasAttachments=true). Se usa para decidir,
    # al degradar, si los adjuntos quedan pendientes de recuperación
    # aparte. NO se activa por ATTACHMENTS_FLAG_UNSTABLE - ese motivo
    # siempre se resuelve con datos reales (manifest vacío o poblado)
    # antes de degradar, nunca queda "pendiente".
    attachments_pending: bool = False
    # Snapshot a persistir en inbound_event_queue (Fase C) cuando
    # ATTACHMENTS_FLAG_UNSTABLE sigue sin resolverse - None si ya no hace
    # falta seguir rastreando (se resolvió, o el motivo no aplica).
    stability_snapshot: dict[str, Any] | None = None

    @property
    def reason_code(self) -> str:
        return "+".join(self.reasons) if self.reasons else "unknown"


@dataclass
class AttachmentsStabilityResult:
    reasons: list[str] = field(default_factory=list)
    new_snapshot: dict[str, Any] | None = None
    attachments_manifest: list[dict[str, Any]] | None = None


async def _evaluate_attachments_flag_stability(
    *,
    mailbox_email: str,
    graph_message_id: str,
    received_at: datetime,
    current_last_modified: str | None,
    attachments_stability_snapshot: dict[str, Any] | None,
    stabilization_window_minutes: int,
) -> AttachmentsStabilityResult:
    """
    Evalúa si se puede confiar en hasAttachments=false, o si hace falta
    esperar una segunda lectura antes de aceptarlo.

    IMPORTANTE: lastModifiedDateTime es solo una SEÑAL de estabilización,
    no una prueba de completitud - Microsoft Graph no documenta que dos
    lecturas iguales certifiquen que la colección de adjuntos ya está
    indexada. Por eso, incluso cuando el snapshot se estabiliza (mismo
    lastModifiedDateTime en dos lecturas consecutivas), esta función
    hace UNA verificación real con list_attachments() antes de aceptar
    "sin adjuntos" - nunca se confía solo en el flag ni solo en que dos
    fechas coincidan.
    """
    # "now" debe compararse en la MISMA zona horaria que received_at:
    # _iso_to_dt() convierte receivedDateTime a hora de Bogotá naive
    # antes de devolverlo (para que coincida con cómo se almacena
    # received_at en toda la base). Comparar contra datetime.now(UTC)
    # naive introduciría un desfase sistemático de 5 horas en el cálculo
    # de "edad" del mensaje.
    now = datetime.now(ZoneInfo("America/Bogota")).replace(tzinfo=None)
    age_minutes = (now - received_at).total_seconds() / 60

    if age_minutes > stabilization_window_minutes:
        # Mensaje ya no es "reciente" según receivedDateTime real (medido,
        # no supuesto) - se confía sin más lecturas.
        return AttachmentsStabilityResult()

    if attachments_stability_snapshot is None:
        # Primera vez que vemos hasAttachments=false para este mensaje
        # reciente -> forzar una segunda lectura antes de confiar.
        return AttachmentsStabilityResult(
            reasons=[REASON_ATTACHMENTS_FLAG_UNSTABLE],
            new_snapshot={
                "last_modified": current_last_modified,
                "has_attachments": False,
            },
        )

    if attachments_stability_snapshot.get("last_modified") != current_last_modified:
        # El snapshot de Graph siguió cambiando entre lecturas -> aún
        # inestable, seguir esperando con el snapshot actualizado.
        return AttachmentsStabilityResult(
            reasons=[REASON_ATTACHMENTS_FLAG_UNSTABLE],
            new_snapshot={
                "last_modified": current_last_modified,
                "has_attachments": False,
            },
        )

    # lastModifiedDateTime estable entre dos lecturas -> verificación
    # real (una sola llamada), no se acepta solo por la coincidencia de
    # fechas.
    manifest = await graph_client.list_attachments(mailbox_email, graph_message_id)
    if not manifest:
        return AttachmentsStabilityResult()

    # El manifiesto SÍ tiene adjuntos reales pese a hasAttachments=false
    # -> no se confía en el flag, se usa el manifiesto real.
    return AttachmentsStabilityResult(attachments_manifest=manifest)


async def _evaluate_completeness(
    msg: dict[str, Any],
    *,
    mailbox_email: str,
    graph_message_id: str,
    received_at: datetime,
    attachments_stability_snapshot: dict[str, Any] | None = None,
    stabilization_window_minutes: int = 15,
) -> CompletenessResult:
    """
    Evalua completitud de body y adjuntos. NO evalua receivedDateTime -
    eso se resuelve antes de llamar esta función, porque los filtros
    GO_LIVE_AT/STOP_NEW_INBOUND_AT (ya existentes) necesitan esa fecha
    para decidir si el mensaje aplica al portal en absoluto, y esa
    decisión debe tomarse antes de gastar una llamada a Graph pidiendo el
    manifiesto de adjuntos de un mensaje que quizás ni corresponda
    procesar.
    """
    reasons: list[str] = []
    attachments_manifest: list[dict[str, Any]] | None = None
    attachments_pending = False
    stability_snapshot: dict[str, Any] | None = None

    # Body: conservar la distinción entre "Graph no mandó el objeto body"
    # (BODY_NOT_READY) y "body.content vino explícitamente vacío" (válido -
    # un correo puede legítimamente no tener texto y solo traer un adjunto).
    body_obj = msg.get("body")
    body_present = isinstance(body_obj, dict)
    if not body_present:
        reasons.append("BODY_NOT_READY")
    else:
        content_present_key = "content" in body_obj
        content_value = body_obj.get("content")
        if not content_present_key or content_value is None:
            # Graph mandó el objeto body pero sin la clave 'content', o con
            # content=null explícito - no es lo mismo que content="".
            reasons.append("BODY_NOT_READY")

    # Adjuntos: si Graph anuncia hasAttachments=true, el manifiesto real
    # (via list_attachments, ya paginado) debe tener al menos un ítem. Si
    # vuelve vacío, Graph todavía no terminó de indexarlos.
    if msg.get("hasAttachments"):
        attachments_manifest = await graph_client.list_attachments(
            mailbox_email, graph_message_id
        )
        if not attachments_manifest:
            reasons.append("ATTACHMENT_MANIFEST_NOT_READY")
            attachments_pending = True
    else:
        # hasAttachments=false puede ser un snapshot temprano de
        # consistencia eventual - ver _evaluate_attachments_flag_stability.
        stability = await _evaluate_attachments_flag_stability(
            mailbox_email=mailbox_email,
            graph_message_id=graph_message_id,
            received_at=received_at,
            current_last_modified=msg.get("lastModifiedDateTime"),
            attachments_stability_snapshot=attachments_stability_snapshot,
            stabilization_window_minutes=stabilization_window_minutes,
        )
        reasons.extend(stability.reasons)
        stability_snapshot = stability.new_snapshot
        if stability.attachments_manifest:
            attachments_manifest = stability.attachments_manifest

    return CompletenessResult(
        complete=(len(reasons) == 0),
        reasons=reasons,
        attachments_manifest=attachments_manifest,
        attachments_pending=attachments_pending,
        stability_snapshot=stability_snapshot,
    )



def _incomplete_or_degrade(
    *,
    provider_message_id: str,
    reasons: list[str],
    attempts: int,
    max_attempts: int,
) -> tuple[bool, dict[str, Any] | None]:
    """
    Decide si un snapshot incompleto debe reintentarse o degradarse.

    Aplica a BODY_NOT_READY y ATTACHMENT_MANIFEST_NOT_READY. NO aplica a
    MISSING_RECEIVED_DATETIME - ese motivo nunca degrada (no debe
    falsear el dato del que depende el SLA) y se resuelve con su propio
    camino, sin llamar a esta función (ver el bloque `received_at_missing`
    en _process_single_message y mark_retry_unbounded en
    inbound_queue_repo.py).

    Retorna (should_degrade, early_return_or_None):
      - Si quedan reintentos: (False, dict) - el llamador debe retornar
        `dict` de inmediato, sin tocar la base de datos.
      - Si se agotó el presupuesto: (True, None) - el llamador debe
        continuar el flujo de materialización, pero marcando el caso
        como degradado (ver _process_single_message).
    """
    reason_code = "+".join(reasons) if reasons else "unknown"
    retries_remaining = (attempts + 1) < max_attempts

    if retries_remaining:
        logger.info(
            "INCOMPLETE_SNAPSHOT | msg=%s | reasons=%s | attempts=%s/%s -> retry",
            provider_message_id,
            reason_code,
            attempts,
            max_attempts,
        )
        return False, {
            "ok": True,
            "status": f"incomplete:{reason_code}",
            "materialized": False,
            "provider_message_id": provider_message_id,
            "case_id": None,
            "message_pk": None,
        }

    logger.warning(
        "COMPLETENESS_BUDGET_EXHAUSTED | msg=%s | reasons=%s | attempts=%s/%s"
        " -> materializando degradado",
        provider_message_id,
        reason_code,
        attempts,
        max_attempts,
    )
    return True, None



# Helpers de BD
def _find_last_case_by_conversation(
    db, *, mailbox_id: int, conversation_id: str
) -> int | None:
    row = db.execute(
        text("""
            SELECT case_id
            FROM messages
            WHERE mailbox_id = :mbid
              AND conversation_id = :cid
              AND case_id IS NOT NULL
            ORDER BY id DESC
            LIMIT 1
        """),
        {"mbid": mailbox_id, "cid": conversation_id},
    ).fetchone()
    return int(row[0]) if row else None


def _touch_case_activity(db, *, case_id: int, last_activity_at: datetime) -> None:
    db.execute(
        text("""
            UPDATE cases
            SET last_activity_at = :dt, updated_at = NOW(6)
            WHERE id = :id
            LIMIT 1
        """),
        {"dt": last_activity_at, "id": case_id},
    )


def _get_existing_message_row(
    db, *, mailbox_id: int, provider_message_id: str
) -> tuple[int, int | None, int] | None:
    """
    Retorna (message_pk, case_id_or_None, has_attachments) si el mensaje
    ya existe en DB. case_id puede ser None si el mensaje quedó huérfano.
    """
    row = db.execute(
        text("""
            SELECT id, case_id, COALESCE(has_attachments, 0)
            FROM messages
            WHERE mailbox_id = :mbid
              AND provider_message_id = :pmid
            LIMIT 1
        """),
        {"mbid": mailbox_id, "pmid": provider_message_id},
    ).fetchone()
    if not row:
        return None
    msg_pk = int(row[0])
    case_id = int(row[1]) if row[1] is not None else None
    has_att = int(row[2])
    return msg_pk, case_id, has_att


def _attachments_count(db, *, message_pk: int) -> int:
    row = db.execute(
        text("SELECT COUNT(*) FROM attachments WHERE message_id = :mid"),
        {"mid": message_pk},
    ).fetchone()
    return int(row[0]) if row else 0


def _message_exists(db, *, mailbox_id: int, provider_message_id: str) -> bool:
    row = db.execute(
        text("""
            SELECT id
            FROM messages
            WHERE mailbox_id = :mbid
              AND provider_message_id = :pmid
            LIMIT 1
        """),
        {"mbid": mailbox_id, "pmid": provider_message_id},
    ).fetchone()
    return bool(row)



# Punto de entrada público
async def process_notifications_async(
    payload_or_list: dict[str, Any] | list[dict[str, Any]],
) -> list[dict[str, Any]]:
    if not settings.MAILBOX_EMAIL:
        logger.error("MAILBOX_EMAIL missing - cannot process")
        return []

    notifications = _normalize_notifications(payload_or_list)
    notifications = [n for n in notifications if _should_accept(n)]

    if not notifications:
        logger.info("No valid notifications to process")
        return []

    logger.info("Processing notifications=%s", len(notifications))

    with get_db_session() as db:
        mailbox_id = repos.get_or_create_mailbox(db, settings.MAILBOX_EMAIL)

    results: list[dict[str, Any]] = []

    for n in notifications:
        msg_id = _extract_message_id(n)
        if not msg_id:
            logger.warning("Skipping notification without message id")
            results.append(
                {
                    "ok": False,
                    "status": "invalid_notification",
                    "materialized": False,
                    "provider_message_id": None,
                }
            )
            continue

        try:
            result = await _process_single_message(
                mailbox_id=mailbox_id,
                message_id=msg_id,
            )
            results.append(result)
        except Exception as e:
            logger.exception("Failed processing message_id=%s err=%s", msg_id, e)
            results.append(
                {
                    "ok": False,
                    "status": "exception",
                    "materialized": False,
                    "provider_message_id": msg_id,
                    "error": str(e)[:500],
                }
            )

    return results


async def process_message_id_async(
    message_id: str,
    source: str = "unknown",
    *,
    attempts: int = 0,
    max_attempts: int = 8,
    attachments_stability_snapshot: dict[str, Any] | None = None,
) -> dict[str, Any]:
    if not settings.MAILBOX_EMAIL:
        logger.error(
            "MAILBOX_EMAIL missing - cannot process message_id=%s", message_id
        )
        return {
            "ok": False,
            "status": "no_mailbox_configured",
            "materialized": False,
            "provider_message_id": message_id,
            "case_id": None,
            "message_pk": None,
        }

    logger.info(
        "MESSAGE_PROCESS_START | source=%s | message_id=%s | attempts=%s | max_attempts=%s",
        source,
        message_id,
        attempts,
        max_attempts,
    )

    with get_db_session() as db:
        mailbox_id = repos.get_or_create_mailbox(db, settings.MAILBOX_EMAIL)

    return await _process_single_message(
        mailbox_id=mailbox_id,
        message_id=message_id,
        attempts=attempts,
        max_attempts=max_attempts,
        attachments_stability_snapshot=attachments_stability_snapshot,
    )



# Núcleo: procesamiento de un mensaje
async def _process_single_message(
    *,
    mailbox_id: int,
    message_id: str,
    attempts: int = 0,
    max_attempts: int = 8,
    attachments_stability_snapshot: dict[str, Any] | None = None,
) -> dict[str, Any]:
    mb = settings.MAILBOX_EMAIL
    msg = await graph_client.get_message(mb, message_id)

    provider_message_id = str(msg.get("id") or message_id)
    subject = str(msg.get("subject") or "(Sin asunto)")

    from_obj = msg.get("from") or {}
    from_email = (
        (from_obj.get("emailAddress") or {}).get("address")
    ) or "unknown@unknown"
    from_name = ((from_obj.get("emailAddress") or {}).get("name")) or None

    to_emails = _emails(msg.get("toRecipients"))
    cc_emails = _emails(msg.get("ccRecipients"))
    bcc_emails = _emails(msg.get("bccRecipients"))

    # received_at: YA NO se sustituye por NOW() cuando Graph todavía no lo
    # trae. El SLA se cuenta desde receivedDateTime (regla de negocio) -
    # inventar la hora de procesamiento falsearía ese dato en silencio.
    # Si falta, es motivo de espera (reintento), no de invención de dato.
    received_at = _iso_to_dt(msg.get("receivedDateTime")) or _iso_to_dt(
        msg.get("createdDateTime")
    )
    sent_at = _iso_to_dt(msg.get("sentDateTime"))

    received_at_missing = received_at is None
    is_degraded = False
    incomplete_reasons: list[str] = []

    if received_at_missing:
        # MISSING_RECEIVED_DATETIME es distinto EN NATURALEZA a
        # BODY_NOT_READY/ATTACHMENT_MANIFEST_NOT_READY: degradar ahí
        # significa insertar con menos contenido del que había, pero la
        # fecha del correo queda intacta. Degradar AQUÍ significaría
        # inventar el dato del que depende directamente el SLA
        # (cases.received_at -> due_at -> sla_state). Por eso este motivo
        # NUNCA pasa por _incomplete_or_degrade ni por materialización
        # degradada, sin importar cuántos intentos lleve: la fila vuelve
        # siempre a la cola, y es inbound_queue_worker/
        # inbound_queue_repo.mark_retry_unbounded quien decide el backoff
        # (ladder normal, luego long-tail) sin agotar nunca el
        # presupuesto ni marcar la fila 'failed'.
        logger.info(
            "INCOMPLETE_SNAPSHOT | msg=%s | reasons=%s | attempts=%s/%s"
            " -> retry (unbounded, ver mark_retry_unbounded)",
            provider_message_id,
            REASON_MISSING_RECEIVED_DATETIME,
            attempts,
            max_attempts,
        )
        return {
            "ok": True,
            "status": STATUS_MISSING_RECEIVED_DATETIME,
            "materialized": False,
            "provider_message_id": provider_message_id,
            "case_id": None,
            "message_pk": None,
        }

    # Filtro GO_LIVE_AT: descartar mensajes anteriores al arranque del portal
    go_live = settings.go_live_dt() if hasattr(settings, "go_live_dt") else None
    if go_live and received_at < go_live:
        logger.warning(
            "Skipping message before GO_LIVE_AT | msg=%s received_at=%s go_live=%s",
            provider_message_id,
            received_at,
            go_live,
        )
        return {
            "ok": True,
            "status": "before_go_live",
            "materialized": False,
            "provider_message_id": provider_message_id,
            "case_id": None,
            "message_pk": None,
        }


    stop_inbound = (
        settings.stop_new_inbound_dt()
        if hasattr(settings, "stop_new_inbound_dt")
        else None
    )
    if stop_inbound and received_at > stop_inbound:
        logger.warning(
            "SKIPPED_AFTER_OPERATIONAL_CUTOFF | msg=%s | received_at=%s | cutoff=%s",
            provider_message_id,
            received_at,
            stop_inbound,
        )
        return {
            "ok": True,
            "status": "after_operational_cutoff",
            "materialized": False,
            "provider_message_id": provider_message_id,
            "case_id": None,
            "message_pk": None,
        }

    internet_message_id = msg.get("internetMessageId")
    conversation_id = msg.get("conversationId")

    internet_headers = msg.get("internetMessageHeaders") or []
    in_reply_to = _header_value(internet_headers, "In-Reply-To")

    has_attachments = 1 if msg.get("hasAttachments") else 0
    case_id: int | None = None
    message_pk: int | None = None
    assigned_agent_id: int | None = None
    existing: tuple[int, int | None, int] | None = None
    should_resync_attachments = False
    should_return_already_materialized = False
    attachments_manifest: list[dict[str, Any]] | None = None
    attachments_pending = False

    # Paso 1: consultar estado en DB - transacción corta, sin llamadas
    # externas dentro (ver nota más abajo sobre por qué el Completeness
    # Gate se evalúa DESPUÉS de cerrar esta sesión).
    with get_db_session() as db:

        # VERIFICAR ESTADO DEL MENSAJE EN DB
        # CASO 1: No existe → insertar + crear caso (flujo normal)
        # CASO 2: Existe CON case_id → ya materializado, skip
        # CASO 3: Existe SIN case_id → hueco operativo, crear caso

        existing = _get_existing_message_row(
            db,
            mailbox_id=mailbox_id,
            provider_message_id=provider_message_id,
        )

        # CASO 2: Mensaje ya materializado con caso
        if existing is not None:
            message_pk_existing, case_id_existing, _has_att_db = existing

            if case_id_existing is not None:
                logger.info(
                    "MESSAGE_ALREADY_MATERIALIZED | message_id=%s | case_id=%s | message_pk=%s",
                    provider_message_id,
                    case_id_existing,
                    message_pk_existing,
                )

                message_pk = message_pk_existing
                case_id = case_id_existing

                if has_attachments:
                    att_count = _attachments_count(db, message_pk=message_pk_existing)
                    if att_count == 0:
                        logger.info(
                            "MESSAGE_ALREADY_MATERIALIZED_MISSING_ATTACHMENTS | message_pk=%s | case_id=%s",
                            message_pk_existing,
                            case_id_existing,
                        )
                        should_resync_attachments = True

                if not should_resync_attachments:
                    return {
                        "ok": True,
                        "status": "already_materialized",
                        "materialized": True,
                        "provider_message_id": provider_message_id,
                        "case_id": case_id,
                        "message_pk": message_pk,
                    }

                should_return_already_materialized = True

            else:

                # CASO 3: Existe en messages SIN case_id → hueco, recuperar
                message_pk = message_pk_existing
                logger.info(
                    "ORPHAN_MESSAGE_DETECTED | message_id=%s | message_pk=%s"
                    " — message exists in DB without case_id, creating case.",
                    provider_message_id,
                    message_pk,
                )

    # --- Completeness Gate (body + adjuntos) ---
    # Corre FUERA de cualquier transacción: list_attachments() es una
    # llamada HTTP a Graph, y las llamadas externas no deben ocurrir con
    # una transacción de base de datos abierta (la sesión del paso 1 ya
    # se cerró arriba). Solo se evalúa cuando vamos a crear un caso nuevo
    # (CASO 1/3) - si el mensaje ya está materializado (CASO 2), la
    # decisión de completitud ya se tomó cuando se creó el caso original.
    need_new_case = existing is None or (existing is not None and existing[1] is None)
    message_kind = "normal"
    classification_reason: str | None = None
    body_text: str | None = None
    body_html: str | None = None
    parent_case_id: int | None = None
    is_orphan_recovery = existing is not None
    internet_message_id_str = str(internet_message_id) if internet_message_id else None
    in_reply_to_str = str(in_reply_to) if in_reply_to else None
    conversation_id_str = str(conversation_id) if conversation_id else None

    if need_new_case:
        completeness = await _evaluate_completeness(
            msg,
            mailbox_email=mb,
            graph_message_id=message_id,
            received_at=received_at,
            attachments_stability_snapshot=attachments_stability_snapshot,
            stabilization_window_minutes=int(
                getattr(settings, "ATTACHMENTS_STABILIZATION_WINDOW_MINUTES", 15)
            ),
        )
        incomplete_reasons.extend(completeness.reasons)

        if not completeness.complete and not is_degraded:
            should_degrade, early_return = _incomplete_or_degrade(
                provider_message_id=provider_message_id,
                reasons=incomplete_reasons,
                attempts=attempts,
                max_attempts=max_attempts,
            )
            if not should_degrade:
                if completeness.stability_snapshot is not None:
                    early_return["attachments_stability_snapshot"] = (
                        completeness.stability_snapshot
                    )
                return early_return
            is_degraded = True

            # Al agotar el presupuesto por ATTACHMENTS_FLAG_UNSTABLE, no se
            # degrada confiando ciegamente en hasAttachments=false: se hace
            # una última verificación real antes de aceptarlo. Si Graph
            # SÍ tiene adjuntos, se usan - nunca se pierde un adjunto por
            # haber agotado el presupuesto de espera de estabilización.
            if (
                REASON_ATTACHMENTS_FLAG_UNSTABLE in incomplete_reasons
                and not completeness.attachments_manifest
            ):
                final_manifest = await graph_client.list_attachments(mb, message_id)
                if final_manifest:
                    completeness.attachments_manifest = final_manifest
                    logger.warning(
                        "ATTACHMENTS_FLAG_UNSTABLE_RECOVERED_AT_BUDGET_EXHAUSTION"
                        " | msg=%s | attachments_found=%s",
                        provider_message_id,
                        len(final_manifest),
                    )

        attachments_manifest = completeness.attachments_manifest
        attachments_pending = completeness.attachments_pending

        # has_attachments se recalcula aquí: si la verificación de
        # estabilización encontró un manifiesto real pese a
        # hasAttachments=false en el snapshot de Graph, se persiste
        # reflejando la realidad, no el flag potencialmente desactualizado.
        if attachments_manifest:
            has_attachments = 1

        body_obj = msg.get("body") if isinstance(msg.get("body"), dict) else None
        body_type = (body_obj.get("contentType") or "").lower() if body_obj else ""
        body_content = body_obj.get("content") if body_obj else None
        body_html = body_content if body_type == "html" else None
        body_text = body_content if body_type != "html" else None

        message_kind, classification_reason = _classify_message_kind(
            subject=subject,
            from_email=str(from_email),
            body_html=body_html,
            body_text=body_text,
        )

        if message_kind == "ndr":
            logger.warning(
                "NDR_DETECTED_BUT_ACCEPTED | message_id=%s | subject=%s | from=%s | reason=%s",
                provider_message_id,
                subject,
                from_email,
                classification_reason,
            )

        # Paso 2: materializar - transacción nueva y corta, ya con todo lo
        # que necesitábamos de Graph resuelto de antemano.
        with get_db_session() as db:
            if conversation_id:
                parent_case_id = _find_last_case_by_conversation(
                    db,
                    mailbox_id=mailbox_id,
                    conversation_id=str(conversation_id),
                )

            case_id = repos.create_case(
                db,
                mailbox_id=mailbox_id,
                subject=subject,
                requester_email=str(from_email),
                requester_name=(str(from_name) if from_name else None),
                received_at=received_at,
                thread_conversation_id=conversation_id_str,
                parent_case_id=parent_case_id,
                root_internet_message_id=internet_message_id_str,
                reply_to_internet_message_id=in_reply_to_str,
            )

            logger.info(
                "CASE_CREATED_FROM_INBOUND | case_id=%s | message_id=%s"
                " | from_email=%s | subject=%s | conversation_id=%s"
                " | in_reply_to=%s | message_kind=%s | is_orphan_recovery=%s"
                " | degraded=%s",
                case_id,
                provider_message_id,
                from_email,
                subject,
                conversation_id_str,
                in_reply_to_str,
                message_kind,
                is_orphan_recovery,
                is_degraded,
            )

            if is_orphan_recovery:
                db.execute(
                    text("""
                        UPDATE messages
                        SET case_id = :new_case_id
                        WHERE id = :msg_pk
                        LIMIT 1
                    """),
                    {"new_case_id": case_id, "msg_pk": message_pk},
                )
                logger.info(
                    "CASE_LINKED_TO_ORPHAN_MESSAGE | message_pk=%s | case_id=%s",
                    message_pk,
                    case_id,
                )
            else:
                repos.insert_message_inbound(
                    db,
                    case_id=case_id,
                    mailbox_id=mailbox_id,
                    folder_id=None,
                    provider_message_id=provider_message_id,
                    conversation_id=conversation_id_str,
                    internet_message_id=internet_message_id_str,
                    in_reply_to=in_reply_to_str,
                    from_email=str(from_email),
                    to_emails=to_emails,
                    cc_emails=cc_emails,
                    bcc_emails=bcc_emails,
                    subject=subject,
                    body_text=body_text,
                    body_html=body_html,
                    received_at=received_at,
                    sent_at=sent_at,
                    has_attachments=has_attachments,
                    processed_by_worker=settings.WORKER_INSTANCE_ID,
                )

                row = db.execute(
                    text("""
                        SELECT id
                        FROM messages
                        WHERE mailbox_id = :mbid
                          AND provider_message_id = :pmid
                        ORDER BY id DESC
                        LIMIT 1
                    """),
                    {"mbid": mailbox_id, "pmid": provider_message_id},
                ).fetchone()

                message_pk = int(row[0]) if row else None

                logger.info(
                    "MESSAGE_INSERTED | message_pk=%s | case_id=%s"
                    " | message_id=%s | received_at=%s | message_kind=%s",
                    message_pk,
                    case_id,
                    provider_message_id,
                    received_at,
                    message_kind,
                )

            _touch_case_activity(db, case_id=case_id, last_activity_at=received_at)

            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="CASE_CREATED_FROM_INBOUND",
                from_status_id=None,
                to_status_id=None,
                details={
                    "provider_message_id": provider_message_id,
                    "conversation_id": conversation_id_str,
                    "internet_message_id": internet_message_id_str,
                    "in_reply_to": in_reply_to_str,
                    "from_email": from_email,
                    "subject": subject,
                    "message_kind": message_kind,
                    "classification_reason": classification_reason,
                    "is_orphan_recovery": is_orphan_recovery,
                },
            )

            if is_degraded:
                # Registro explícito del correo/caso degradado - a
                # diferencia del comportamiento anterior (donde un
                # snapshot incompleto se insertaba en silencio como si
                # fuera el correo real), este evento queda disponible
                # para cualquier vista de "revisión manual" del portal.
                repos.insert_case_event(
                    db,
                    case_id=case_id,
                    actor_user_id=None,
                    source="WORKER",
                    event_type="CASE_CREATED_DEGRADED",
                    from_status_id=None,
                    to_status_id=None,
                    details={
                        "provider_message_id": provider_message_id,
                        "reasons": incomplete_reasons,
                        "attachments_pending": attachments_pending,
                        "attempts": attempts,
                        "max_attempts": max_attempts,
                    },
                )
                logger.warning(
                    "CASE_CREATED_DEGRADED | case_id=%s | message_id=%s | reasons=%s"
                    " | attachments_pending=%s",
                    case_id,
                    provider_message_id,
                    incomplete_reasons,
                    attachments_pending,
                )

            if conversation_id:
                repos.insert_case_event(
                    db,
                    case_id=case_id,
                    actor_user_id=None,
                    source="WORKER",
                    event_type="CASE_LINKED_TO_THREAD",
                    from_status_id=None,
                    to_status_id=None,
                    details={
                        "provider_message_id": provider_message_id,
                        "conversation_id": conversation_id_str,
                        "internet_message_id": internet_message_id_str,
                        "in_reply_to": in_reply_to_str,
                        "parent_case_id": parent_case_id,
                        "message_kind": message_kind,
                    },
                )

                if parent_case_id:
                    repos.insert_case_event(
                        db,
                        case_id=case_id,
                        actor_user_id=None,
                        source="WORKER",
                        event_type="CASE_PARENT_LINKED",
                        from_status_id=None,
                        to_status_id=None,
                        details={
                            "provider_message_id": provider_message_id,
                            "conversation_id": conversation_id_str,
                            "parent_case_id": parent_case_id,
                            "message_kind": message_kind,
                        },
                    )

            if message_kind == "ndr":
                repos.insert_case_event(
                    db,
                    case_id=case_id,
                    actor_user_id=None,
                    source="WORKER",
                    event_type="NDR_DETECTED_BUT_ACCEPTED",
                    from_status_id=None,
                    to_status_id=None,
                    details={
                        "provider_message_id": provider_message_id,
                        "conversation_id": conversation_id_str,
                        "from_email": from_email,
                        "subject": subject,
                        "message_kind": message_kind,
                        "classification_reason": classification_reason,
                    },
                )

            assigned_agent_id = repos.auto_assign_case(db, case_id=case_id)
            if assigned_agent_id:
                logger.info(
                    "AUTO_ASSIGNED | case_id=%s | agent_id=%s",
                    case_id,
                    assigned_agent_id,
                )
                repos.insert_case_event(
                    db,
                    case_id=case_id,
                    actor_user_id=None,
                    source="WORKER",
                    event_type="AUTO_ASSIGNED",
                    from_status_id=None,
                    to_status_id=None,
                    details={
                        "agent_id": assigned_agent_id,
                        "provider_message_id": provider_message_id,
                        "message_kind": message_kind,
                    },
                )
            else:
                logger.warning(
                    "No eligible agents found for auto-assign case_id=%s",
                    case_id,
                )
                repos.insert_case_event(
                    db,
                    case_id=case_id,
                    actor_user_id=None,
                    source="WORKER",
                    event_type="AUTO_ASSIGN_SKIPPED",
                    from_status_id=None,
                    to_status_id=None,
                    details={
                        "reason": "no_eligible_agents",
                        "provider_message_id": provider_message_id,
                        "message_kind": message_kind,
                    },
                )

    # Adjuntos (fuera de cualquier transacción de DB, sigue el mismo
    # principio que ya existía: no bloquear la sesión mientras se llama
    # a Graph / se escribe a disco). Si el manifiesto no estaba listo
    # (attachments_pending), NO se intenta procesar aquí: queda para
    # recover_missing_attachments(), que reintenta sin límite de intentos
    # de forma independiente al presupuesto de materialización.
    if has_attachments and message_pk is not None and not attachments_pending:
        await _process_attachments(
            mailbox_email=mb,
            graph_message_id=message_id,
            message_pk=message_pk,
            provider_message_id=provider_message_id,
            case_id=case_id,
            conversation_id=conversation_id_str,
            attachments_manifest=attachments_manifest,
        )
    elif has_attachments and attachments_pending:
        logger.info(
            "ATTACHMENTS_LEFT_PENDING_FOR_RECOVERY | msg=%s | case_id=%s"
            " | recover_missing_attachments() los recogerá",
            provider_message_id,
            case_id,
        )

    if should_return_already_materialized:
        return {
            "ok": True,
            "status": "already_materialized",
            "materialized": True,
            "provider_message_id": provider_message_id,
            "case_id": case_id,
            "message_pk": message_pk,
        }

    materialized = False
    with get_db_session() as db:
        materialized = _message_exists(
            db,
            mailbox_id=mailbox_id,
            provider_message_id=provider_message_id,
        )

    if not materialized:
        logger.error(
            "MESSAGE_NOT_MATERIALIZED_AFTER_PROCESS | message_id=%s"
            " | case_id=%s | message_pk=%s",
            provider_message_id,
            case_id,
            message_pk,
        )
        return {
            "ok": False,
            "status": "not_materialized",
            "materialized": False,
            "provider_message_id": provider_message_id,
            "case_id": case_id,
            "message_pk": message_pk,
        }

    if settings.NOTIFICATIONS_ENABLED and assigned_agent_id and case_id is not None:
        await _notify_agent_new_case(
            case_id=case_id,
            agent_id=assigned_agent_id,
            case_subject=subject,
        )

    return {
        "ok": True,
        "status": ("created_degraded" if is_degraded else "created"),
        "materialized": True,
        "provider_message_id": provider_message_id,
        "case_id": case_id,
        "message_pk": message_pk,
    }



# Notificación al agente
async def _notify_agent_new_case(
    *, case_id: int, agent_id: int, case_subject: str
) -> None:
    mb = settings.MAILBOX_EMAIL
    if not mb:
        return

    ua = f"worker/{settings.WORKER_INSTANCE_ID}"

    with get_db_session() as db:
        to_email = repos.get_user_email(db, user_id=agent_id)

        if not to_email:
            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="NOTIFY_SKIPPED_NO_EMAIL",
                from_status_id=None,
                to_status_id=None,
                details={
                    "agent_id": agent_id,
                    "reason": "agent_has_no_email",
                    "mode": "cooldown_mvp",
                },
                ip_address=None,
                user_agent=ua,
            )
            logger.info("Agent has no email user_id=%s -> skip notify", agent_id)
            return

        allowed = repos.try_mark_agent_notified(
            db,
            user_id=agent_id,
            cooldown_minutes=5,
        )
        if not allowed:
            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="NOTIFY_SKIPPED_COOLDOWN",
                from_status_id=None,
                to_status_id=None,
                details={
                    "agent_id": agent_id,
                    "to_email": to_email,
                    "cooldown_minutes": 5,
                    "reason": "cooldown_active",
                    "mode": "cooldown_mvp",
                },
                ip_address=None,
                user_agent=ua,
            )
            logger.info(
                "Notify cooldown active agent_id=%s -> skip notify",
                agent_id,
            )
            return

    portal = (getattr(settings, "PORTAL_BASE_URL", "") or "").rstrip("/")
    link = f"{portal}/cases/{case_id}" if portal else ""
    subject = "Nuevo caso asignado en Portal ICBF"
    body_html = f"""
      <div style="font-family:Segoe UI, Arial, sans-serif; font-size:14px; color:#111">
        <p>Hola,</p>
        <p>Se te ha asignado un <strong>nuevo caso</strong> en el Portal de Gestión de Correo.</p>
        <p style="margin:12px 0">
          <strong>ID del caso:</strong> {case_id}<br>
          <strong>Asunto:</strong> {case_subject}
        </p>
        {"<p>Puedes revisarlo aquí: <a href='" + link + "'>" + link + "</a></p>" if link else "<p>Ingresa al portal para revisarlo.</p>"}
        <p style="color:#666; font-size:12px; margin-top:18px">
          Este mensaje es automático. Para evitar spam, el sistema limita notificaciones por ventana de tiempo.
        </p>
      </div>
    """

    try:
        await graph_client.send_mail(
            mb,
            to_email=to_email,
            subject=subject,
            body_html=body_html,
        )
        with get_db_session() as db:
            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="NOTIFY_SENT",
                from_status_id=None,
                to_status_id=None,
                details={
                    "agent_id": agent_id,
                    "to_email": to_email,
                    "subject": subject,
                    "portal_link": link,
                    "mode": "cooldown_mvp",
                },
                ip_address=None,
                user_agent=ua,
            )
        logger.info(
            "Notification sent case_id=%s agent_id=%s to=%s",
            case_id,
            agent_id,
            to_email,
        )
    except Exception as e:
        with get_db_session() as db:
            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="NOTIFY_FAILED",
                from_status_id=None,
                to_status_id=None,
                details={
                    "agent_id": agent_id,
                    "to_email": to_email,
                    "subject": subject,
                    "portal_link": link,
                    "error": str(e)[:500],
                    "mode": "cooldown_mvp",
                },
                ip_address=None,
                user_agent=ua,
            )
        logger.warning(
            "Notification failed case_id=%s agent_id=%s err=%s",
            case_id,
            agent_id,
            e,
        )


# Procesamiento de adjuntos
async def _process_attachments(
    *,
    mailbox_email: str,
    graph_message_id: str,
    message_pk: int,
    provider_message_id: str,
    case_id: int | None = None,
    conversation_id: str | None = None,
    attachments_manifest: list[dict[str, Any]] | None = None,
) -> None:
    # Si el Completeness Gate ya obtuvo el manifiesto (caso normal: mensaje
    # nuevo, completo desde el primer intento), se reutiliza tal cual en
    # vez de volver a pedirlo a Graph. Si no vino (ej. resync de un mensaje
    # ya materializado, camino que no pasa por el gate), se pide aquí como
    # antes.
    if attachments_manifest is not None:
        atts = attachments_manifest
    else:
        atts = await graph_client.list_attachments(mailbox_email, graph_message_id)

    if not atts:
        return

    prepared: list[dict[str, Any]] = []
    failures: list[dict[str, Any]] = []

    for a in atts:
        odata_type = str(a.get("@odata.type") or "")
        raw_id = a.get("id")
        filename = str(a.get("name") or "attachment.bin")

        if "fileAttachment" not in odata_type:
            logger.warning(
                "Skipping non-file attachment type=%s id=%s",
                odata_type,
                raw_id,
            )
            # No es una falla real (item/reference attachments no aplican
            # a este flujo) - no se cuenta como failure, es intencional.
            continue

        # D1-A: el id de Graph es el identificador que Graph usa para
        # direccionar este adjunto específico (incluso para descargar su
        # contenido vía get_attachment más abajo) - sin él no hay forma
        # de aplicar identidad real ni de recuperarlo individualmente
        # después. Ausencia de id = snapshot incompleto, no "adjunto sin
        # nombre" - se trata como fallo reintentable, NUNCA se guarda
        # como NULL (evitar más filas legacy como las 7146 ya
        # existentes en producción - ver auditoría D1 - que MariaDB no
        # protege entre sí bajo UNIQUE porque NULL no colisiona consigo
        # mismo).
        if not raw_id or not str(raw_id).strip():
            logger.warning(
                "Attachment without Graph id filename=%s -> incomplete/retryable",
                filename,
            )
            failures.append({
                "filename": filename, "graph_attachment_id": None,
                "reason": "MISSING_GRAPH_ATTACHMENT_ID",
            })
            continue

        att_id = str(raw_id).strip()

        content_type = str(a.get("contentType") or "application/octet-stream")
        size = int(a.get("size") or 0)
        is_inline = 1 if a.get("isInline") else 0
        content_id = a.get("contentId")

        content_b64 = a.get("contentBytes")
        if not content_b64 and att_id:
            full = await graph_client.get_attachment(
                mailbox_email,
                graph_message_id,
                att_id,
            )
            content_b64 = full.get("contentBytes")

        if not content_b64:
            logger.warning(
                "Attachment without contentBytes filename=%s id=%s",
                filename,
                att_id,
            )
            failures.append({
                "filename": filename, "graph_attachment_id": att_id,
                "reason": "NO_CONTENT_BYTES",
            })
            continue

        try:
            raw = base64.b64decode(content_b64)
        except Exception:
            logger.warning(
                "Invalid base64 attachment filename=%s id=%s",
                filename,
                att_id,
            )
            failures.append({
                "filename": filename, "graph_attachment_id": att_id,
                "reason": "INVALID_BASE64",
            })
            continue

        if size and len(raw) != size:
            size = len(raw)

        try:
            stored = await asyncio.to_thread(
                save_attachment_bytes,
                filename=filename,
                content_bytes=raw,
                content_type=content_type,
            )
        except Exception as e:
            logger.warning("Attachment rejected filename=%s reason=%s", filename, e)
            failures.append({
                "filename": filename, "graph_attachment_id": att_id,
                "reason": "REJECTED_BY_POLICY", "detail": str(e)[:200],
            })
            continue

        if not stored.sha256:
            logger.warning("Attachment without sha256 filename=%s -> skip", filename)
            failures.append({
                "filename": filename, "graph_attachment_id": att_id,
                "reason": "MISSING_SHA256",
            })
            continue

        prepared.append(
            {
                "filename": filename,
                "graph_attachment_id": att_id,
                "content_type": stored.content_type,
                "size_bytes": stored.size_bytes,
                "sha256": stored.sha256,
                "is_inline": is_inline,
                "content_id": (str(content_id) if content_id else None),
                "storage_path": stored.storage_path,
            }
        )

        logger.info(
            "Prepared attachment msg_pk=%s graph_msg_id=%s filename=%s bytes=%s sha=%s",
            message_pk,
            graph_message_id,
            filename,
            stored.size_bytes,
            stored.sha256[:12],
        )

    if not prepared and not failures:
        return

    with get_db_session() as db:
        existing_count = _attachments_count(db, message_pk=message_pk)
        if existing_count > 0:
            logger.info(
                "Attachments already exist for message_pk=%s count=%s"
                " -> will continue (idempotent insert)",
                message_pk,
                existing_count,
            )

        inserted = 0
        for p in prepared:
            repos.upsert_attachment(
                db,
                message_id_pk=message_pk,
                graph_attachment_id=p.get("graph_attachment_id"),
                filename=p["filename"],
                content_type=p["content_type"],
                size_bytes=p["size_bytes"],
                sha256=p["sha256"],
                is_inline=p["is_inline"],
                content_id=p["content_id"],
                storage_path=p["storage_path"],
            )
            inserted += 1

        if case_id and inserted:
            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="ATTACHMENTS_SYNCED",
                from_status_id=None,
                to_status_id=None,
                details={
                    "provider_message_id": provider_message_id,
                    "conversation_id": conversation_id,
                    "message_pk": message_pk,
                    "attachments_count": inserted,
                },
            )

        if case_id and failures:
            # A5: los fallos por adjunto (sin contentBytes, base64
            # inválido, rechazado por política de extensión/tamaño, sin
            # sha256) ya no desaparecen en un `continue` silencioso -
            # quedan aquí, consultables desde el portal. No se reintentan
            # automáticamente: son errores de contenido/política, no de
            # disponibilidad temporal, así que reintentar no los resuelve.
            repos.insert_case_event(
                db,
                case_id=case_id,
                actor_user_id=None,
                source="WORKER",
                event_type="ATTACHMENTS_PARTIAL_FAILURE",
                from_status_id=None,
                to_status_id=None,
                details={
                    "provider_message_id": provider_message_id,
                    "message_pk": message_pk,
                    "expected": len(atts),
                    "downloaded": inserted,
                    "failed": len(failures),
                    "failures": failures,
                },
            )
            logger.warning(
                "ATTACHMENTS_PARTIAL_FAILURE | message_pk=%s | expected=%s | downloaded=%s | failed=%s",
                message_pk,
                len(atts),
                inserted,
                len(failures),
            )

    logger.info(
        "Inserted attachments=%s failed=%s for provider_message_id=%s",
        len(prepared),
        len(failures),
        provider_message_id,
    )


# ---------------------------------------------------------------------------
# Recuperación de adjuntos pendientes (sin límite de intentos)
#
# Cubre dos caminos que dejan un mensaje materializado con has_attachments=1
# pero sin filas en `attachments`:
#   1. El caso se creó en modo degradado porque el manifiesto de adjuntos
#      no estaba listo tras agotar el presupuesto de la cola principal
#      (ver CASE_CREATED_DEGRADED / attachments_pending en
#      _process_single_message).
#   2. Cualquier otro hueco histórico con la misma forma (ej. datos
#      previos a este cambio).
#
# A diferencia de inbound_event_queue (presupuesto fijo de
# INBOUND_QUEUE_MAX_ATTEMPTS intentos), esta función no tiene límite
# propio: se invoca periódicamente desde background_jobs y cada corrida
# reintenta lo que siga pendiente. El caso ya es visible para el agente
# desde que se creó - esto solo completa los archivos.
#
# Limitación conocida: si TODOS los adjuntos de un mensaje fallan de forma
# permanente (ej. contenido corrupto en origen), este mecanismo los
# reintentará indefinidamente en cada corrida sin converger. No se
# implementó todavía la distinción TRANSIENT vs PERMANENT para esos casos
# (ver diagnóstico) - queda como mejora de una fase posterior. El impacto
# práctico es bajo: son llamadas de bajo costo (una por mensaje pendiente,
# acotadas por ATTACHMENT_RECOVERY_BATCH_SIZE) y no afectan el SLA del
# caso, que ya está visible.
# ---------------------------------------------------------------------------

async def recover_missing_attachments(*, limit: int = 50) -> dict[str, Any]:
    mb = settings.MAILBOX_EMAIL
    if not mb:
        return {"ok": False, "error": "MAILBOX_EMAIL is required"}

    with get_db_session() as db:
        rows = db.execute(
            text("""
                SELECT m.id, m.provider_message_id, m.case_id, m.conversation_id
                FROM messages m
                LEFT JOIN attachments a ON a.message_id = m.id
                WHERE m.has_attachments = 1
                  AND a.id IS NULL
                  AND m.case_id IS NOT NULL
                ORDER BY m.id ASC
                LIMIT :limit
            """),
            {"limit": limit},
        ).fetchall()

    if not rows:
        return {"ok": True, "checked": 0, "recovered": 0, "still_pending": 0}

    recovered = 0
    still_pending = 0

    for message_pk, provider_message_id, case_id, conversation_id in rows:
        try:
            manifest = await graph_client.list_attachments(mb, provider_message_id)
        except Exception as e:
            logger.warning(
                "ATTACHMENT_RECOVERY_FETCH_FAILED | message_pk=%s | err=%s",
                message_pk,
                e,
            )
            still_pending += 1
            continue

        if not manifest:
            logger.info(
                "ATTACHMENT_RECOVERY_STILL_NOT_READY | message_pk=%s"
                " | provider_message_id=%s",
                message_pk,
                provider_message_id,
            )
            still_pending += 1
            continue

        await _process_attachments(
            mailbox_email=mb,
            graph_message_id=provider_message_id,
            message_pk=int(message_pk),
            provider_message_id=provider_message_id,
            case_id=int(case_id) if case_id else None,
            conversation_id=conversation_id,
            attachments_manifest=manifest,
        )

        with get_db_session() as db:
            still_missing = _attachments_count(db, message_pk=int(message_pk)) == 0

        if still_missing:
            still_pending += 1
        else:
            recovered += 1

    logger.info(
        "ATTACHMENT_RECOVERY_DONE | checked=%s | recovered=%s | still_pending=%s",
        len(rows),
        recovered,
        still_pending,
    )
    return {
        "ok": True,
        "checked": len(rows),
        "recovered": recovered,
        "still_pending": still_pending,
    }