from __future__ import annotations

from fastapi import APIRouter, Header, HTTPException, Query

from app.settings import settings
from app.subscriptions_service import ensure_subscription

router = APIRouter(prefix="/graph/subscription", tags=["graph-subscription"])


def _check_admin_key(x_admin_key: str | None) -> None:
    if not settings.ADMIN_API_KEY:
        raise HTTPException(status_code=500, detail="ADMIN_API_KEY not configured")
    if not x_admin_key or x_admin_key != settings.ADMIN_API_KEY:
        raise HTTPException(status_code=401, detail="Unauthorized")


@router.post("/ensure")
async def ensure(
    dry_run: bool = Query(default=False),
    x_admin_key: str | None = Header(default=None),
) -> dict:
    _check_admin_key(x_admin_key)
    return await ensure_subscription(dry_run=dry_run)
