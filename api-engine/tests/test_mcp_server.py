"""C⁴ as an MCP server: other AI tools ask its bots, limited to the key's workspace."""
import hashlib
import json

import pytest
from fastapi import FastAPI
from fastmcp import Client
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine
from sqlalchemy.pool import StaticPool

import database
import mcp_server
from database import AiProvider, Base, BotProfile, McpKey, System, get_db
from routers import chat

TOKEN = "c4mcp_" + "a" * 40


@pytest.fixture
async def made(monkeypatch):
    engine = create_async_engine("sqlite+aiosqlite://", poolclass=StaticPool)
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    factory = async_sessionmaker(engine, expire_on_commit=False)
    monkeypatch.setattr(database, "async_session_factory", factory)
    async with factory() as s:
        s.add_all([System(id="sys_1", name="Ours", allowed_origins="*"), System(id="sys_2", name="Theirs")])
        s.add(AiProvider(id="aip_1", system_id="sys_1", name="Local", base_url="http://llm/v1", api_key=""))
        s.add(BotProfile(id="bot_1", system_id="sys_1", name="Helper", provider_id="aip_1", is_active=True,
                         retrieval_enabled=False, db_query_enabled=False, web_search_enabled=False))
        s.add(BotProfile(id="bot_x", system_id="sys_2", name="Theirs", is_active=True))
        s.add(McpKey(id="mk_1", system_id="sys_1", name="Copilot", token_hash=hashlib.sha256(TOKEN.encode()).hexdigest(),
                     hint="aaaa"))
        await s.commit()

    app = FastAPI()
    app.include_router(chat.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session
    monkeypatch.setattr(mcp_server, "APP", app)
    yield factory
    await engine.dispose()


def as_key(monkeypatch, token=TOKEN):
    monkeypatch.setattr(mcp_server, "bearer", lambda: token)


async def call(name, arguments=None):
    async with Client(mcp_server.server) as client:
        result = await client.call_tool(name, arguments or {}, raise_on_error=False)
    text = "".join(getattr(c, "text", "") for c in result.content)
    return result.is_error, text


async def test_a_key_lists_only_its_workspaces_bots(made, monkeypatch):
    as_key(monkeypatch)

    failed, text = await call("list_bots")

    assert not failed
    assert [b["id"] for b in json.loads(text)] == ["bot_1"]


async def test_no_key_or_a_wrong_key_is_refused(made, monkeypatch):
    for token in (None, "c4mcp_wrong"):
        as_key(monkeypatch, token)
        failed, text = await call("list_bots")
        assert failed and "key" in text.lower()


async def test_another_workspaces_bot_is_out_of_reach(made, monkeypatch):
    as_key(monkeypatch)

    failed, text = await call("ask_bot", {"bot_id": "bot_x", "question": "hi"})

    assert failed and "No such bot in this workspace." in text


async def test_ask_bot_runs_the_normal_chat(made, monkeypatch):
    as_key(monkeypatch)

    async def stream_chat(**kwargs):
        yield f"data: {json.dumps({'content': 'We open at 9am.'})}\n\n"
        yield "data: [DONE]\n\n"

    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", stream_chat)

    failed, text = await call("ask_bot", {"bot_id": "bot_1", "question": "when do you open?"})

    body = json.loads(text)
    assert not failed
    assert body["answer"] == "We open at 9am."
    assert body["session_id"].startswith("mcp-")

    async with made() as s:
        key = await s.get(McpKey, "mk_1")
    assert key.last_used_at is not None


async def test_search_knowledge_returns_passages_of_the_bots_collections(made, monkeypatch):
    from database import BotKbCollection, KbCollection
    from sources import attempts
    from sources.result import SourceResult
    as_key(monkeypatch)
    async with made() as s:
        s.add(KbCollection(id="kbc_1", system_id="sys_1", name="Hours"))
        s.add(BotKbCollection(bot_id="bot_1", collection_id="kbc_1"))
        await s.commit()
    seen = {}

    async def documents(session, bot, query, settings, ids, **kwargs):
        seen["ids"] = ids
        return SourceResult(kind="documents", context_block="[1] Hours\nWe open at 9am.",
                            citations=[{"n": 1, "title": "Hours"}], has_content=True)

    monkeypatch.setattr(attempts, "documents", documents)

    failed, text = await call("search_knowledge", {"bot_id": "bot_1", "query": "opening hours"})

    assert not failed
    assert json.loads(text) == {"passages": "[1] Hours\nWe open at 9am.", "sources": ["Hours"]}
    assert seen["ids"] == ["kbc_1"]
