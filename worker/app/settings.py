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
        Convierte GO_LIVE_AT (ISO 8601 con Z o con offset) a datetime UTC naive.
        Compatible con el estilo de _iso_to_dt en sync_service (UTC y sin tzinfo).
        """
        v = (self.GO_LIVE_AT or "").strip()
        if not v:
            return None
        try:
            dt = datetime.fromisoformat(v.replace("Z", "+00:00"))
            return dt.astimezone(timezone.utc).replace(tzinfo=None)
        except Exception:
            return None

    # DB
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

    # Storage
    ATTACHMENTS_DIR: str = r"C:\data\icbf_mail_attachments"
    MAX_ATTACHMENT_SIZE_MB: int = 25
    ALLOWED_ATTACHMENT_EXT: str = "pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,zip"
    BLOCKED_ATTACHMENT_EXT: str = "exe,bat,cmd,js,vbs,msi,ps1,jar,com,scr,lnk"

    # Graph
    GRAPH_TENANT_ID: str = ""
    GRAPH_CLIENT_ID: str = ""
    GRAPH_CLIENT_SECRET: str = ""

    # Prod recomendado
    GRAPH_CERT_PRIVATE_KEY_PATH: str = ""
    GRAPH_CERT_THUMBPRINT: str = ""

    GRAPH_CLIENT_STATE: str = ""
    MAILBOX_EMAIL: str = ""
    PUBLIC_BASE_URL: str = ""\
    

    # Subscriptions
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

    WEBHOOK_QUEUE_MAXSIZE: int = 2000
    WEBHOOK_CONSUMERS: int = 4


    # Persistent inbound queue
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

    # helpers
    def allowed_ext_set(self) -> Set[str]:
        return {x.strip().lower().lstrip(".") for x in self.ALLOWED_ATTACHMENT_EXT.split(",") if x.strip()}

    def blocked_ext_set(self) -> Set[str]:
        return {x.strip().lower().lstrip(".") for x in self.BLOCKED_ATTACHMENT_EXT.split(",") if x.strip()}

    def max_attachment_bytes(self) -> int:
        return int(self.MAX_ATTACHMENT_SIZE_MB) * 1024 * 1024

    def attachments_path(self) -> Path:
        return Path(self.ATTACHMENTS_DIR).expanduser()


settings = Settings()