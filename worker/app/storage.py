from __future__ import annotations

import base64
import hashlib
import logging
import os
from dataclasses import dataclass
from pathlib import Path
from typing import Optional, Tuple

log = logging.getLogger("app.storage")


@dataclass
class SavedFile:
    path: str
    sha256: str
    size_bytes: int


def _safe_filename(name: str) -> str:
    # minimal hardening for filesystem
    name = name.replace("\\", "_").replace("/", "_").strip()
    if not name:
        return "attachment.bin"
    return name[:200]


def _ext(name: str) -> str:
    p = Path(name)
    return p.suffix.lower().lstrip(".")


def validate_attachment(filename: str, size_bytes: int, max_mb: int, allowed_ext: list[str], blocked_ext: list[str]) -> None:
    if size_bytes > max_mb * 1024 * 1024:
        raise ValueError(f"Attachment too large: {size_bytes} bytes > {max_mb}MB")

    ext = _ext(filename)
    if ext in blocked_ext:
        raise ValueError(f"Blocked attachment extension: .{ext}")

    if allowed_ext and ext and ext not in allowed_ext:
        # if ext empty -> allow (some rare cases), else enforce allowlist
        raise ValueError(f"Attachment extension not allowed: .{ext}")


def ensure_dir(path: Path) -> None:
    path.mkdir(parents=True, exist_ok=True)


def save_attachment_bytes(
    base_dir: Path,
    case_number: str,
    message_id: str,
    filename: str,
    content_bytes: bytes,
) -> SavedFile:
    safe = _safe_filename(filename)
    # folder structure: /base/YYYY/CASE/MSG/
    year = case_number.split("-")[1] if "-" in case_number else "unknown"
    target_dir = base_dir / year / case_number / message_id
    ensure_dir(target_dir)

    file_path = target_dir / safe

    sha = hashlib.sha256(content_bytes).hexdigest()
    file_path.write_bytes(content_bytes)

    return SavedFile(path=str(file_path), sha256=sha, size_bytes=len(content_bytes))


def decode_graph_content_bytes(content_bytes_b64: str) -> bytes:
    return base64.b64decode(content_bytes_b64)
