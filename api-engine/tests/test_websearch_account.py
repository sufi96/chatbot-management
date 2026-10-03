"""Which search a bot uses, and whose key pays for it."""
from types import SimpleNamespace

import httpx
import pytest
from fastapi import FastAPI
from httpx import ASGITransport, AsyncClient
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

import websearch
from config import settings as app_settings
from database import Base, System, WebSearchKey
from routers import kb
from sources import attempts
from websearch.account import Account, account_for

PLATFORM = {"web_search_provider": "brave", "web_search_brave_key": "platform-key"}


def bot(**fields):
    base = dict(id="b1", system_id="sys_1", web_search_mode="platform", web_search_key_id=None,
                is_platform=False, web_search_max_results=3, web_search_country=None)
    return SimpleNamespace(**{**base, **fields})


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        s.add(System(id="sys_1", name="Mine"))
        s.add(System(id="sys_2", name="Theirs"))
        s.add(WebSearchKey(id="wsk_mine", system_id="sys_1", name="Ours", provider="tavily", api_key="ours-key"))
        s.add(WebSearchKey(id="wsk_theirs", system_id="sys_2", name="Theirs", provider="brave", api_key="their-key"))
        await s.commit()
        yield s
    await engine.dispose()


@pytest.mark.asyncio
async def test_a_bot_on_the_platform_search_uses_the_platform_key(session):
    assert await account_for(session, bot(), PLATFORM) == Account("brave", "platform-key", "platform")


@pytest.mark.asyncio
async def test_a_bot_with_its_own_key_pays_with_it(session):
    chosen = await account_for(session, bot(web_search_mode="own", web_search_key_id="wsk_mine"), PLATFORM)
    assert chosen == Account("tavily", "ours-key", "workspace")


@pytest.mark.asyncio
async def test_another_workspaces_key_is_never_used(session):
    chosen = await account_for(session, bot(web_search_mode="own", web_search_key_id="wsk_theirs"), PLATFORM)
    assert chosen == Account("duckduckgo")


@pytest.mark.asyncio
async def test_a_missing_own_key_falls_to_duckduckgo_not_the_platform(session):
    chosen = await account_for(session, bot(web_search_mode="own", web_search_key_id=None), PLATFORM)
    assert chosen.provider == "duckduckgo"


@pytest.mark.asyncio
async def test_duckduckgo_is_free_whatever_the_platform_has(session):
    assert await account_for(session, bot(web_search_mode="duckduckgo"), PLATFORM) == Account("duckduckgo")


@pytest.mark.asyncio
async def test_a_platform_that_does_not_lend_gives_workspaces_duckduckgo(session):
    closed = {**PLATFORM, "web_search_lending": "none"}
    assert (await account_for(session, bot(), closed)).provider == "duckduckgo"
    # The console's own bots still use the platform's account.
    assert (await account_for(session, bot(is_platform=True), closed)).api_key == "platform-key"


@pytest.mark.asyncio
async def test_a_paid_provider_with_no_key_is_duckduckgo(session):
    assert (await account_for(session, bot(), {"web_search_provider": "tavily"})).provider == "duckduckgo"


@pytest.mark.asyncio
async def test_the_web_attempt_searches_on_the_chosen_account(session):
    seen = {}

    async def search(**kwargs):
        seen.update(kwargs)
        return []

    await attempts.web(bot(web_search_mode="own", web_search_key_id="wsk_mine"), "hours?",
                       {**PLATFORM, "context_char_budget": "6000"},
                       search=search, session=session)

    assert seen["provider"] == "tavily"
    assert seen["api_key"] == "ours-key"


async def post_test(monkeypatch, provider, adapter):
    monkeypatch.setattr(app_settings, "ADMIN_API_TOKEN", "secret")
    monkeypatch.setitem(websearch.PROVIDERS, provider, adapter)
    app = FastAPI()
    app.include_router(kb.router)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post("/api/v1/kb/websearch/test", headers={"X-Admin-Token": "secret"},
                                     json={"provider": provider, "api_key": "k"})
    return response.json()


@pytest.mark.asyncio
async def test_a_good_key_says_so(monkeypatch):
    async def adapter(query, count, country, api_key, transport):
        return [websearch.SearchResult("t", "u", "x")]

    body = await post_test(monkeypatch, "brave", adapter)
    assert body["ok"] is True and "1 results" in body["message"]


@pytest.mark.asyncio
async def test_a_rejected_key_says_why(monkeypatch):
    async def adapter(query, count, country, api_key, transport):
        request = httpx.Request("GET", "https://x")
        raise httpx.HTTPStatusError("no", request=request, response=httpx.Response(401, request=request))

    body = await post_test(monkeypatch, "tavily", adapter)
    assert body == {"ok": False, "message": "HTTP 401: the key was rejected."}
