from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, Optional, List

from dotenv import load_dotenv
from pydantic_settings import BaseSettings, SettingsConfigDict
from pydantic import Field


def _split_csv(value: str) -> List[str]:
    return [v.strip().lower() for v in value.split(",") if v.strip()]


class Settings(BaseSettings):
    """
    Dual-config strategy:
      - dev: from .env
      - prod: secrets from ENV, operational params optionally from DB system_config
    """

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # env
    ENV: str = Field(default="dev")  # dev|prod
    LOG_LEVEL: str = Field(default="INFO")
    HOST: str = Field(default="127.0.0.1")
    PORT: int = Field(default=8001)

    # db
    DB_DIALECT: str = Field(default="mysql")
    DB_HOST: str = Field(default="127.0.0.1")
    DB_PORT: int = Field(default=3306)
    DB_NAME: str = Field(default="icbf_mail")
    DB_USER: str = Field(default="root")
    DB_PASSWORD: str = Field(default="")
    DB_POOL_SIZE: int = Field(default=10)
    DB_MAX_OVERFLOW: int = Field(default=20)

    DB_CONFIG_ENABLED: int = Field(default=0)  # 1 to read from system_config (prod)

    # storage
    ATTACHMENTS_DIR: str = Field(default=r"C:\data\icbf_mail_attachments")
    MAX_ATTACHMENT_SIZE_MB: int = Field(default=25)
    ALLOWED_ATTACHMENT_EXT: str = Field(default="pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,zip")
    BLOCKED_ATTACHMENT_EXT: str = Field(default="exe,bat,cmd,js,vbs,msi,ps1,jar,com,scr,lnk")

    # graph
    GRAPH_TENANT_ID: str = Field(default="")
    GRAPH_CLIENT_ID: str = Field(default="")
    GRAPH_CLIENT_SECRET: str = Field(default="")

    GRAPH_CERT_PRIVATE_KEY_PATH: str = Field(default="")
    GRAPH_CERT_THUMBPRINT: str = Field(default="")

    GRAPH_CLIENT_STATE: str = Field(default="CHANGE_ME_RANDOM")

    # mailbox
    MAILBOX_EMAIL: str = Field(default="Atencion.Ciudadano@icbf.gov.co")

    def db_url(self) -> str:
        # mysql+pymysql://user:pass@host:port/db?charset=utf8mb4
        pwd = self.DB_PASSWORD or ""
        return f"mysql+pymysql://{self.DB_USER}:{pwd}@{self.DB_HOST}:{self.DB_PORT}/{self.DB_NAME}?charset=utf8mb4"

    def attachments_path(self) -> Path:
        return Path(self.ATTACHMENTS_DIR)

    def allowed_ext(self) -> List[str]:
        return _split_csv(self.ALLOWED_ATTACHMENT_EXT)

    def blocked_ext(self) -> List[str]:
        return _split_csv(self.BLOCKED_ATTACHMENT_EXT)


@dataclass
class RuntimeConfig:
    """
    Loaded from DB system_config when enabled (non-secrets).
    Secrets should remain only in environment variables.
    """
    ATTACHMENTS_DIR: Optional[str] = None
    MAX_ATTACHMENT_SIZE_MB: Optional[int] = None
    ALLOWED_ATTACHMENT_EXT: Optional[str] = None
    BLOCKED_ATTACHMENT_EXT: Optional[str] = None
    MAILBOX_EMAIL: Optional[str] = None


def load_settings() -> Settings:
    # ensure .env is loaded if present (local)
    load_dotenv(override=False)
    return Settings()


def apply_runtime_config(settings: Settings, runtime: RuntimeConfig) -> Settings:
    # Apply only if present; keep fallback to env otherwise.
    if runtime.ATTACHMENTS_DIR:
        settings.ATTACHMENTS_DIR = runtime.ATTACHMENTS_DIR
    if runtime.MAX_ATTACHMENT_SIZE_MB is not None:
        settings.MAX_ATTACHMENT_SIZE_MB = int(runtime.MAX_ATTACHMENT_SIZE_MB)
    if runtime.ALLOWED_ATTACHMENT_EXT:
        settings.ALLOWED_ATTACHMENT_EXT = runtime.ALLOWED_ATTACHMENT_EXT
    if runtime.BLOCKED_ATTACHMENT_EXT:
        settings.BLOCKED_ATTACHMENT_EXT = runtime.BLOCKED_ATTACHMENT_EXT
    if runtime.MAILBOX_EMAIL:
        settings.MAILBOX_EMAIL = runtime.MAILBOX_EMAIL
    return settings
