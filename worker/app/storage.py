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


def ensure_attachments_dir() -> Path:
    base = settings.attachments_path()
    base.mkdir(parents=True, exist_ok=True)
    return base


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

    # content_type best-effort
    if not content_type:
        content_type = mimetypes.guess_type(filename)[0] or "application/octet-stream"


def sha256_bytes(data: bytes) -> str:
    h = hashlib.sha256()
    h.update(data)
    return h.hexdigest()


def save_attachment_bytes(filename: str, content_bytes: bytes, content_type: str | None = None) -> StoredAttachment:
    ensure_attachments_dir()

    size_bytes = len(content_bytes)
    validate_attachment(filename=filename, size_bytes=size_bytes, content_type=content_type or "")

    digest = sha256_bytes(content_bytes)

    # Path deterministic by hash: /attachments/ab/cd/<hash>_<filename>
    base = settings.attachments_path()
    sub = base / digest[:2] / digest[2:4]
    sub.mkdir(parents=True, exist_ok=True)

    safe_name = Path(filename).name  # remove path traversal
    out_path = sub / f"{digest}_{safe_name}"
    out_path.write_bytes(content_bytes)

    ct = content_type or mimetypes.guess_type(filename)[0] or "application/octet-stream"
    return StoredAttachment(storage_path=str(out_path), sha256=digest, size_bytes=size_bytes, content_type=ct)
