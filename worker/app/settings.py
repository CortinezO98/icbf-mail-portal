from __future__ import annotations

from pathlib import Path
from typing import Set

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",          # lee worker/.env (local) o variables del entorno (prod)
        env_file_encoding="utf-8",
        extra="ignore",
    )

    ENV: str = "dev"
    LOG_LEVEL: str = "INFO"
    HOST: str = "127.0.0.1"
    PORT: int = 8001

    DB_DIALECT: str = "mysql"
    DB_HOST: str = "127.0.0.1"
    DB_PORT: int = 3306
    DB_NAME: str = "icbf_mail"
    DB_USER: str = "root"
    DB_PASSWORD: str = ""
    DB_POOL_SIZE: int = 10
    DB_MAX_OVERFLOW: int = 20

    DB_CONFIG_ENABLED: int = 0  # 0 local / 1 prod (si luego lees system_config)

    ATTACHMENTS_DIR: str = r"C:\data\icbf_mail_attachments"
    MAX_ATTACHMENT_SIZE_MB: int = 25
    ALLOWED_ATTACHMENT_EXT: str = "pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,zip"
    BLOCKED_ATTACHMENT_EXT: str = "exe,bat,cmd,js,vbs,msi,ps1,jar,com,scr,lnk"

    GRAPH_TENANT_ID: str = ""
    GRAPH_CLIENT_ID: str = ""
    GRAPH_CLIENT_SECRET: str = ""  # MVP (secret)
    GRAPH_CERT_PRIVATE_KEY_PATH: str = ""
    GRAPH_CERT_THUMBPRINT: str = ""

    GRAPH_CLIENT_STATE: str = "CHANGE_ME_RANDOM"
    MAILBOX_EMAIL: str = ""

    def attachments_path(self) -> Path:
        return Path(self.ATTACHMENTS_DIR).expanduser()

    def allowed_ext_set(self) -> Set[str]:
        return {x.strip().lower().lstrip(".") for x in self.ALLOWED_ATTACHMENT_EXT.split(",") if x.strip()}

    def blocked_ext_set(self) -> Set[str]:
        return {x.strip().lower().lstrip(".") for x in self.BLOCKED_ATTACHMENT_EXT.split(",") if x.strip()}

    def max_attachment_bytes(self) -> int:
        return int(self.MAX_ATTACHMENT_SIZE_MB) * 1024 * 1024


# singleton global
settings = Settings()
