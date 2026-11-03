"""Connector admin routes for the portal. Guarded by the shared admin token:
they carry a secret and call whatever address they are given."""
from typing import Optional

from fastapi import APIRouter, Depends
from pydantic import BaseModel

from connectors import rest
from routers.kb import require_admin_token

router = APIRouter(prefix="/api/v1/connectors", tags=["connectors"])


class TestRequest(BaseModel):
    base_url: str
    auth_type: str = "none"
    auth_header: Optional[str] = None
    auth_secret: Optional[str] = None


@router.post("/test", dependencies=[Depends(require_admin_token)])
async def test_connector(req: TestRequest) -> dict:
    return await rest.ping(req.base_url, req.auth_type, req.auth_header, req.auth_secret)
