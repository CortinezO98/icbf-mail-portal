from pydantic_settings import BaseSettings, SettingsConfigDict
from pydantic import Field


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    app_name: str = Field(default="icbf-mail-worker", alias="APP_NAME")
    env: str = Field(default="dev", alias="ENV")
    log_level: str = Field(default="INFO", alias="LOG_LEVEL")

    host: str = Field(default="127.0.0.1", alias="HOST")
    port: int = Field(default=8001, alias="PORT")

    graph_client_state: str = Field(default="change_me", alias="GRAPH_CLIENT_STATE")

    db_host: str = Field(default="127.0.0.1", alias="DB_HOST")
    db_port: int = Field(default=3306, alias="DB_PORT")
    db_name: str = Field(default="icbf_mail", alias="DB_NAME")
    db_user: str = Field(default="root", alias="DB_USER")
    db_password: str = Field(default="", alias="DB_PASSWORD")

    attachments_dir: str = Field(default=r"C:\data\icbf_mail_attachments", alias="ATTACHMENTS_DIR")
    max_attachment_mb: int = Field(default=25, alias="MAX_ATTACHMENT_MB")

    @property
    def sqlalchemy_url(self) -> str:
        # mysql+pymysql://user:pass@host:port/db?charset=utf8mb4
        pwd = self.db_password or ""
        return (
            f"mysql+pymysql://{self.db_user}:{pwd}@{self.db_host}:{self.db_port}/{self.db_name}"
            f"?charset=utf8mb4"
        )


settings = Settings()
