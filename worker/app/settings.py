from __future__ import annotations

from pathlib import Path
from typing import Set
from datetime import datetime, timezone

from pydantic_settings import BaseSettings, SettingsConfigDict

BASE_DIR = Path(__file__).resolve().parents[1]
ENV_PATH = BASE_DIR / ".env"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=str(ENV_PATH),
        env_file_encoding="utf-8",
        extra="ignore",
    )

    APP_NAME: str = "icbf-mail"
    ENV: str = "dev"
    LOG_LEVEL: str = "INFO"
    HOST: str = "127.0.0.1"
    PORT: int = 8001
    WORKER_INSTANCE_ID: str = "worker-01"

    GO_LIVE_AT: str | None = None

    def go_live_dt(self) -> datetime | None:
        """
        Convierte GO_LIVE_AT (ISO 8601 con Z o con offset) al mismo
        instante en hora de Bogotá, sin tzinfo.

        FIX de auditoría de zona horaria (pre-Fase D): antes devolvía
        UTC naive, mientras _iso_to_dt() (que calcula received_at) y
        stop_new_inbound_dt() ya devolvían Bogotá naive. Comparar
        "UTC naive" contra "Bogotá naive" como si fueran el mismo reloj
        producía un desfase de 5 horas en el filtro GO_LIVE_AT - ver
        auditoría de timezone previa a Fase D. El comentario anterior
        ("compatible con el estilo de _iso_to_dt... UTC") era incorrecto:
        _iso_to_dt() devuelve Bogotá, no UTC.
        """
        v = (self.GO_LIVE_AT or "").strip()
        if not v:
            return None
        try:
            from zoneinfo import ZoneInfo
            dt = datetime.fromisoformat(v.replace("Z", "+00:00"))
            bogota_tz = ZoneInfo("America/Bogota")
            dt_bogota = dt.astimezone(bogota_tz)
            return dt_bogota.replace(tzinfo=None)
        except Exception:
            return None

    
    # Corte operativo de ingesta de nuevos correos

    STOP_NEW_INBOUND_AT: str | None = None

    def stop_new_inbound_dt(self) -> datetime | None:
        """
        Convierte STOP_NEW_INBOUND_AT a datetime en hora Bogotá sin tzinfo,
        igual que received_at en este sistema, para que la comparación sea directa.
        """
        v = (self.STOP_NEW_INBOUND_AT or "").strip()
        if not v:
            return None
        try:
            from zoneinfo import ZoneInfo
            dt = datetime.fromisoformat(v.replace("Z", "+00:00"))
            bogota_tz = ZoneInfo("America/Bogota")
            dt_bogota = dt.astimezone(bogota_tz)
            return dt_bogota.replace(tzinfo=None)
        except Exception:
            return None

    
    # Base de datos
    DB_DIALECT: str = "mysql"
    DB_HOST: str = "127.0.0.1"
    DB_PORT: int = 3306
    DB_NAME: str = "icbf_mail"
    DB_USER: str = "root"
    DB_PASSWORD: str = ""
    DB_POOL_SIZE: int = 10
    DB_MAX_OVERFLOW: int = 20
    DB_CONFIG_ENABLED: int = 0
    MAILBOX_ID: int | None = None

    
    # Almacenamiento de adjuntos
    ATTACHMENTS_DIR: str = r"C:\data\icbf_mail_attachments"
    MAX_ATTACHMENT_SIZE_MB: int = 25
    ALLOWED_ATTACHMENT_EXT: str = "pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,zip"
    BLOCKED_ATTACHMENT_EXT: str = "exe,bat,cmd,js,vbs,msi,ps1,jar,com,scr,lnk"

    
    # Microsoft Graph
    GRAPH_TENANT_ID: str = ""
    GRAPH_CLIENT_ID: str = ""
    GRAPH_CLIENT_SECRET: str = ""

    GRAPH_CERT_PRIVATE_KEY_PATH: str = ""
    GRAPH_CERT_THUMBPRINT: str = ""

    GRAPH_CLIENT_STATE: str = ""
    MAILBOX_EMAIL: str = ""

    # URL pública del worker (para webhooks de Graph)
    PUBLIC_BASE_URL: str = ""
    PORTAL_BASE_URL: str = ""

    def portal_url(self) -> str:
        """
        Retorna la URL base del portal para construir links a casos.
        Usa PORTAL_BASE_URL si está configurado, si no PUBLIC_BASE_URL.
        """
        url = (self.PORTAL_BASE_URL or self.PUBLIC_BASE_URL or "").rstrip("/")
        return url

    
    # Suscripciones Graph
    AUTO_ENSURE_SUBSCRIPTION: int = 0
    SUBSCRIPTION_CHANGE_TYPE: str = "created,updated"
    SUBSCRIPTION_RESOURCE: str = "users/{MAILBOX_EMAIL}/mailFolders('Inbox')/messages"
    SUBSCRIPTION_LIFETIME_MINUTES: int = 10080
    SUB_RENEW_THRESHOLD_MINUTES: int = 1440

    NOTIFICATIONS_ENABLED: bool = True

    
    # Delta backstop
    DELTA_ENABLED: int = 1
    DELTA_INTERVAL_MINUTES: int = 10
    DELTA_PAGE_SIZE: int = 50
    DELTA_MAX_PAGES_PER_RUN: int = 25
    DELTA_CONCURRENCY: int = 3

    DELTA_PRIME_ON_EMPTY_STATE: int = 1
    DELTA_PRIME_ONLY: int = 0

    DELTA_MAX_MESSAGES: int = 500
    DELTA_MAX_PAGES: int = 50

    
    # Background loops
    SUB_LOOP_ENABLED: int = 1
    DELTA_LOOP_ENABLED: int = 1
    SUB_LOOP_INTERVAL_SECONDS: int = 30
    DELTA_LOOP_INTERVAL_SECONDS: int = 60
    SUB_LOOP_JITTER_SECONDS: int = 10
    DELTA_LOOP_JITTER_SECONDS: int = 5

    
    # Admin
    ADMIN_API_KEY: str = ""

    
    # Webhook  
    WEBHOOK_QUEUE_MAXSIZE: int = 2000
    WEBHOOK_CONSUMERS: int = 4

    
    # Cola de entrada persistente  
    INBOUND_QUEUE_ENABLED: int = 1
    INBOUND_QUEUE_POLL_SECONDS: int = 2
    INBOUND_QUEUE_BATCH_SIZE: int = 20
    INBOUND_QUEUE_MAX_ATTEMPTS: int = 8
    INBOUND_QUEUE_CONCURRENCY: int = 5

    # Reconcile
    RECONCILE_ENABLED: int = 1
    RECONCILE_INTERVAL_SECONDS: int = 300
    RECONCILE_LOOKBACK_MINUTES: int = 180
    RECONCILE_PAGE_SIZE: int = 100

    RECONCILE_FORCE_REPROCESS: bool = False

    # Recuperación de adjuntos pendientes (D2, tabla operacional
    # attachment_recovery - ver attachment_recovery.py). Reemplaza el
    # motor viejo basado en "0 filas persistidas" (sync_service.
    # recover_missing_attachments, eliminado).
    #
    # POLL: cada cuánto se consulta la BD buscando trabajo vencido - es
    # una query indexada barata (idx_attachment_recovery_claim), NO una
    # llamada a Graph. Si no hay filas con available_at <= NOW(), esta
    # corrida no llama a Graph en absoluto.
    # BACKOFF real: lo decide available_at por fila (ver
    # attachment_recovery._recovery_backoff_seconds), no el poll.
    ATTACHMENT_RECOVERY_ENABLED: int = 1
    ATTACHMENT_RECOVERY_POLL_SECONDS: int = 30
    ATTACHMENT_RECOVERY_VERIFICATION_DELAY_SECONDS: int = 120
    ATTACHMENT_RECOVERY_STALE_LOCK_MINUTES: int = 10
    ATTACHMENT_RECOVERY_LONG_TAIL_SECONDS: int = 21600  # 6 horas
    ATTACHMENT_RECOVERY_BATCH_SIZE: int = 50

    # MISSING_RECEIVED_DATETIME: nunca degrada ni marca la fila 'failed'
    # (ver inbound_queue_repo.mark_retry_unbounded). Mientras la edad de
    # la fila de cola (queue_event_age, ver nota en inbound_queue_worker)
    # no supere este umbral, usa el mismo ladder de backoff normal
    # (30/120/300/900/1800s). Al superarlo, además de emitir una alerta
    # operacional (log ALERT_STALLED_MISSING_RECEIVED_DATETIME), pasa a
    # un intervalo fijo largo para no seguir golpeando Graph cada 30 min
    # de forma indefinida en un caso que quizás nunca se resuelva.
    MISSING_RECEIVED_DATETIME_ALERT_AGE_MINUTES: int = 60
    MISSING_RECEIVED_DATETIME_LONG_RETRY_SECONDS: int = 21600  # 6 horas

    # ATTACHMENTS_FLAG_UNSTABLE: hasAttachments=false puede ser un
    # snapshot temprano de consistencia eventual de Graph. lastModified-
    # DateTime es solo una SEÑAL de estabilización (Graph no documenta
    # que dos lecturas iguales certifiquen indexación completa de
    # adjuntos) - por eso, cuando se estabiliza, igual se verifica
    # list_attachments() una vez antes de confiar. Ver
    # sync_service._evaluate_attachments_flag_stability.
    ATTACHMENTS_STABILIZATION_WINDOW_MINUTES: int = 15

    # Helpers
    def allowed_ext_set(self) -> Set[str]:
        return {
            x.strip().lower().lstrip(".")
            for x in self.ALLOWED_ATTACHMENT_EXT.split(",")
            if x.strip()
        }

    def blocked_ext_set(self) -> Set[str]:
        return {
            x.strip().lower().lstrip(".")
            for x in self.BLOCKED_ATTACHMENT_EXT.split(",")
            if x.strip()
        }

    def max_attachment_bytes(self) -> int:
        return int(self.MAX_ATTACHMENT_SIZE_MB) * 1024 * 1024

    def attachments_path(self) -> Path:
        return Path(self.ATTACHMENTS_DIR).expanduser()


settings = Settings()