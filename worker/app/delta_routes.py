from __future__ import annotations

from fastapi import APIRouter, Request, HTTPException
from app.settings import settings
from app.delta_service import run_delta_backstop

router = APIRouter()

def _require_admin_key(request: Request) -> None:
    key = request.headers.get("x-admin-key") or request.headers.get("X-Admin-Key")
    if not key or key != settings.ADMIN_API_KEY:
        raise HTTPException(status_code=401, detail="Invalid admin key")

@router.post("/graph/delta/run")
async def run_delta(request: Request) -> dict:
    _require_admin_key(request)
    return await run_delta_backstop()


@router.post("/graph/delta/prime")
async def prime_delta(request: Request) -> dict:
    _require_admin_key(request)

    old = getattr(settings, "DELTA_PRIME_ONLY", 0)
    try:
        setattr(settings, "DELTA_PRIME_ONLY", 1)
        return await run_delta_backstop()
    finally:
        setattr(settings, "DELTA_PRIME_ONLY", old)