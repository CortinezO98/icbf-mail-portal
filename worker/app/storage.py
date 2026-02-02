from __future__ import annotations

import base64
import hashlib
import logging
import os
import re
from pathlib import Path
from typing import Tuple

from app.settings import settings

logger = logging.getLogger("app.storage")


def _safe_filename(name: str) -> str:
    name = name.strip().replace("\x00", "")
    name = re.sub(r"[^\w\-.() ]+", "_", name)
    name = re.sub(r"\s+", " ", name).strip()
    return name[:180] if len(name) > 180 else name


def _get_ext(filename: str) -> str:
    ext = Path(filename).suffix.lower().lstrip(".")
    return ext


def validate_attachment(filename: str, size_bytes: int) -> None:
    ext = _get_ext(filename)
    allowed = settings.allowed_ext_set()
    blocked = settings.blocked_ext_set()

    if ext in blocked:
        raise ValueError(f"Blocked extension: .{ext}")
    if allowed and ext not in allowed:
        raise ValueError(f"Extension not allowed: .{ext}")

    max_bytes = int(settings.MAX_ATTACHMENT_SIZE_MB) * 1024 * 1024
    if size_bytes > max_bytes:
        raise ValueError(f"Attachment too large: {size_bytes} bytes > {max_bytes} bytes")


def ensure_base_dir() -> Path:
    base = Path(settings.ATTACHMENTS_DIR)
    base.mkdir(parents=True, exist_ok=True)
    return base


def save_attachment_bytes(
    *,
    case_number: str,
    message_id: str,
    filename: str,
    content_bytes: bytes,
) -> Tuple[str, str, int]:
    """
    Guarda adjunto en disco:
    /ATTACHMENTS_DIR/<case_number>/<message_id>/<filename>
    Retorna: (storage_path, sha256, size_bytes)
    """
    safe_name = _safe_filename(filename)
    size_bytes = len(content_bytes)
    validate_attachment(safe_name, size_bytes)

    sha256 = hashlib.sha256(content_bytes).hexdigest()
    base = ensure_base_dir()

    target_dir = base / case_number / message_id
    target_dir.mkdir(parents=True, exist_ok=True)

    target_path = target_dir / safe_name

    # Si ya existe, no lo reescribas (dedupe por contenido)
    if target_path.exists():
        logger.info("Attachment already exists on disk: %s", str(target_path))
        return str(target_path), sha256, size_bytes

    with open(target_path, "wb") as f:
        f.write(content_bytes)

    # Endurecer permisos en Linux (en Windows no aplica igual)
    if os.name != "nt":
        os.chmod(target_path, 0o640)

    return str(target_path), sha256, size_bytes


def decode_graph_content_bytes(content_bytes_b64: str) -> bytes:
    return base64.b64decode(content_bytes_b64)
