"""The bot form's model list and inference test carry a provider's API key and
call any URL they are given, so only the portal may use them."""
import pytest
from fastapi import FastAPI
from httpx import ASGITransport, AsyncClient

from config import settings
from routers import bot


async def call(path, headers=None):
    app = FastAPI()
    app.include_router(bot.router)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        return await client.post(path, headers=headers or {},
                                 json={"base_url": "http://127.0.0.1:9/v1", "api_key": "k", "model_name": "m"})


@pytest.mark.asyncio
@pytest.mark.parametrize("path", ["/api/v1/bot/fetch-models", "/api/v1/bot/test-connection"])
async def test_an_endpoint_call_without_the_admin_token_is_refused(monkeypatch, path):
    monkeypatch.setattr(settings, "ADMIN_API_TOKEN", "portal-token")

    response = await call(path)

    assert response.status_code == 401


@pytest.mark.asyncio
@pytest.mark.parametrize("path", ["/api/v1/bot/fetch-models", "/api/v1/bot/test-connection"])
async def test_the_portal_token_is_let_through(monkeypatch, path):
    monkeypatch.setattr(settings, "ADMIN_API_TOKEN", "portal-token")

    async def fake(cls=None, **kwargs):
        return {"success": True, "models": [], "count": 0, "message": "ok"}

    monkeypatch.setattr(bot.LLMAdapter, "fetch_models", fake)
    monkeypatch.setattr(bot.LLMAdapter, "test_connection", fake)

    response = await call(path, headers={"X-Admin-Token": "portal-token"})

    assert response.status_code == 200
