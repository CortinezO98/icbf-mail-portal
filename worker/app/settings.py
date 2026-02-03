from __future__ import annotations

from pathlib import Path
from typing import Set

from pydantic_settings import BaseSettings, SettingsConfigDict


# worker/app/settings.py -> worker/
BASE_DIR = Path(__file__).resolve().parents[1]
ENV_PATH = BASE_DIR / ".env"


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=str(ENV_PATH),
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # Básico
    APP_NAME: str = "icbf-mail"
    ENV: str = "dev"
    LOG_LEVEL: str = "INFO"
    HOST: str = "127.0.0.1"
    PORT: int = 8001
    WORKER_INSTANCE_ID: str = "worker-01"

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
    PUBLIC_BASE_URL: str = ""

    # Subscriptions
    AUTO_ENSURE_SUBSCRIPTION: int = 0
    SUBSCRIPTION_CHANGE_TYPE: str = "created"
    SUBSCRIPTION_RESOURCE: str = "users/{MAILBOX_EMAIL}/mailFolders('Inbox')/messages"
    SUBSCRIPTION_LIFETIME_MINUTES: int = 10080
    SUB_RENEW_THRESHOLD_MINUTES: int = 1440

    # Admin
    ADMIN_API_KEY: str = ""

    # ---------- helpers ----------
    def allowed_ext_set(self) -> Set[str]:
        return {x.strip().lower().lstrip(".") for x in self.ALLOWED_ATTACHMENT_EXT.split(",") if x.strip()}

    def blocked_ext_set(self) -> Set[str]:
        return {x.strip().lower().lstrip(".") for x in self.BLOCKED_ATTACHMENT_EXT.split(",") if x.strip()}

    def max_attachment_bytes(self) -> int:
        return int(self.MAX_ATTACHMENT_SIZE_MB) * 1024 * 1024

    def attachments_path(self) -> Path:
        # Carpeta fuera del webroot (como definimos en arquitectura)
        return Path(self.ATTACHMENTS_DIR).expanduser()


settings = Settings()
