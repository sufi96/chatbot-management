"""A skill drafted by a workspace's own model, for an admin to edit before saving."""
import pytest
from fastapi import FastAPI
from httpx import ASGITransport, AsyncClient
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine
from sqlalchemy.pool import StaticPool

from config import settings
from database import AiProvider, Base, System, get_db
from routers import skills


@pytest.fixture
async def client(monkeypatch):
    monkeypatch.setattr(settings, "ADMIN_API_TOKEN", "portal-token")
    engine = create_async_engine("sqlite+aiosqlite://", poolclass=StaticPool)
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    made = async_sessionmaker(engine, expire_on_commit=False)
    async with made() as s:
        s.add(System(id="sys_1", name="W"))
        s.add(AiProvider(id="aip_1", system_id="sys_1", name="Hosted", base_url="http://llm/v1", api_key="k"))
        await s.commit()

    app = FastAPI()
    app.include_router(skills.router)

    async def session():
        async with made() as s:
            yield s

    app.dependency_overrides[get_db] = session
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as c:
        yield c
    await engine.dispose()


def draft(client, token="portal-token", provider_id="aip_1"):
    return client.post("/api/v1/skills/draft", headers={"X-Admin-Token": token} if token else {},
                       json={"provider_id": provider_id, "model": "m", "description": "Check refund status"})


async def test_the_draft_is_the_models_text(client, monkeypatch):
    seen = {}

    async def complete(**kwargs):
        seen.update(kwargs)
        return "---\nname: refund-status\ndescription: Use when asked about a refund.\n---\n\nAsk for the order."

    monkeypatch.setattr(skills.LLMAdapter, "complete", complete)

    response = await draft(client)

    assert response.json()["success"] is True
    assert response.json()["skill"].startswith("---\nname: refund-status")
    assert seen["base_url"] == "http://llm/v1" and seen["model_name"] == "m"
    assert "Check refund status" in seen["user_message"]
    assert "SKILL.md" in seen["system_prompt"]


async def test_an_empty_answer_is_a_sentence(client, monkeypatch):
    async def complete(**kwargs):
        return ""

    monkeypatch.setattr(skills.LLMAdapter, "complete", complete)

    body = (await draft(client)).json()

    assert body["success"] is False and body["message"]


async def test_an_unknown_provider_is_a_sentence(client):
    body = (await draft(client, provider_id="nope")).json()

    assert body["success"] is False and body["message"]


async def test_the_admin_token_is_required(client):
    assert (await draft(client, token=None)).status_code == 401
