from __future__ import annotations

import hashlib
import mimetypes
from dataclasses import dataclass
from pathlib import Path

from app.settings import settings


@dataclass
class StoredAttachment:
    storage_path: str
    sha256: str
    size_bytes: int
    content_type: str


def attachments_base_dir() -> Path:
    base = settings.attachments_path()
    base.mkdir(parents=True, exist_ok=True)
    return base


def resolve_attachment_path(storage_path: str) -> Path:
    """
    ✅ Convierte el storage_path (que en DB será relativo) a path absoluto.
    Mantiene compatibilidad con registros viejos que tengan path absoluto.
    """
    p = Path(storage_path)
    if p.is_absolute():
        return p
    return (attachments_base_dir() / p).resolve()


def _ext_of(filename: str) -> str:
    return Path(filename).suffix.lower().lstrip(".")


def validate_attachment(filename: str, size_bytes: int, content_type: str | None = None) -> None:
    ext = _ext_of(filename)

    if ext in settings.blocked_ext_set():
        raise ValueError(f"Blocked extension: .{ext}")

    allowed = settings.allowed_ext_set()
    if allowed and ext not in allowed:
        raise ValueError(f"Extension not allowed: .{ext}")

    if size_bytes > settings.max_attachment_bytes():
        raise ValueError(f"Attachment too large: {size_bytes} bytes")

    if not content_type:
        _ = mimetypes.guess_type(filename)[0] or "application/octet-stream"


def sha256_bytes(data: bytes) -> str:
    h = hashlib.sha256()
    h.update(data)
    return h.hexdigest()


def save_attachment_bytes(filename: str, content_bytes: bytes, content_type: str | None = None) -> StoredAttachment:
    base = attachments_base_dir()

    size_bytes = len(content_bytes)
    validate_attachment(filename=filename, size_bytes=size_bytes, content_type=content_type or "")

    digest = sha256_bytes(content_bytes)

    rel_path = Path(digest[:2]) / digest[2:4] / f"{digest}_{safe_name}"
    abs_path = (base / rel_path)
    abs_path.parent.mkdir(parents=True, exist_ok=True)
    tmp_path = abs_path.with_suffix(abs_path.suffix + ".tmp")
    tmp_path.write_bytes(content_bytes)
    tmp_path.replace(abs_path)

    ct = content_type or mimetypes.guess_type(filename)[0] or "application/octet-stream"

    return StoredAttachment(
        storage_path=rel_path.as_posix(),
        sha256=digest,
        size_bytes=size_bytes,
        content_type=ct,
    )
