"""The console assistant's tools: fetched from the portal for the person signed
in, run through the portal, and never more than that person may do."""
import json
from datetime import datetime
from types import SimpleNamespace

import httpx
import pytest
from fastapi import FastAPI
from httpx import ASGITransport, AsyncClient
from sqlalchemy import select

import agent
import console_tools
from connectors.rest import CallResult
from database import BotProfile, ChatConversation, ChatToolCall, get_db
from routers import chat
from sources import attempts
from tests.test_chat_route import answering, factory  # noqa: F401  (fixture)

TOOLS = {"ok": True, "user": {"name": "Aina", "role": "system_admin", "super_admin": False}, "tools": [
    {"name": "list_bots", "description": "The bots I can see.", "parameters": {"type": "object", "properties": {}},
     "writes": False},
    {"name": "set_bot_active", "description": "Switch a bot on or off.",
     "parameters": {"type": "object", "properties": {"bot": {"type": "string"}}}, "writes": True},
]}


def portal(seen, tools=TOOLS, status=200, called=None):
    """A fake portal: answers the tools list, and records each call."""
    def handle(request: httpx.Request) -> httpx.Response:
        body = json.loads(request.content or b"{}")
        seen.append((request.url.path, request.headers.get("x-portal-token"), body))
        if request.url.path.endswith("/tools"):
            return httpx.Response(status, json=tools if status == 200 else {"ok": False})
        return httpx.Response(200, json=called or {"ok": True, "text": "HR Helper is now off."})
    return httpx.MockTransport(handle)


@pytest.fixture(autouse=True)
def portal_settings(monkeypatch):
    monkeypatch.setattr(console_tools.app_settings, "PORTAL_BASE_URL", "http://portal")
    monkeypatch.setattr(console_tools.app_settings, "PORTAL_INTERNAL_TOKEN", "engine-secret")


async def test_load_brings_the_persons_tools_and_sends_both_tokens():
    seen = []

    console = await console_tools.load("tok-aina", transport=portal(seen))

    assert console.user["name"] == "Aina" and console.super_admin is False
    assert [t.name for t in console.tools] == ["list_bots", "set_bot_active"]
    assert [t.writes for t in console.tools] == [False, True]
    assert seen == [("/internal/assistant/tools", "engine-secret", {"token": "tok-aina"})]


async def test_a_read_runs_through_the_portal_as_that_person():
    seen = []
    console = await console_tools.load("tok-aina", transport=portal(seen))

    outcome = await console.tools[0].local({})

    assert outcome.ok and "HR Helper" in outcome.text
    assert seen[-1] == ("/internal/assistant/call", "engine-secret",
                        {"token": "tok-aina", "name": "list_bots", "arguments": {}})


async def test_a_refusal_comes_back_as_a_failed_call_with_the_reason():
    seen = []
    outcome = await console_tools.call("tok", "get_bot", {"bot": "Sales"},
                                       transport=portal(seen, called={"ok": False, "error": "No bot \"Sales\" that you can reach."}))

    assert outcome.ok is False and "No bot" in outcome.error


async def test_a_refused_read_tells_the_model_why_rather_than_failing():
    seen = []
    console = await console_tools.load("tok", transport=portal(seen, called={"ok": False, "error": "No bot \"Sales\" that you can reach."}))

    outcome = await console.tools[0].local({"bot": "Sales"})

    assert outcome.ok and outcome.text == "Not done: No bot \"Sales\" that you can reach."


async def test_no_token_or_a_bad_one_means_no_tools():
    seen = []

    assert (await console_tools.load(None, transport=portal(seen))).tools == []
    assert seen == []
    bad = await console_tools.load("expired", transport=portal(seen, status=401))
    assert bad.tools == [] and bad.user is None and bad.super_admin is False


async def test_an_unreachable_portal_means_no_tools():
    def down(request):
        raise httpx.ConnectError("refused")

    console = await console_tools.load("tok", transport=httpx.MockTransport(down))

    assert console.tools == [] and console.user is None


async def test_the_tools_source_takes_the_console_tools_too():
    given = []

    async def run(bot, message, tools, endpoint, **kwargs):
        given.extend(tools)
        return agent.AgentResult()

    async def load(session, bot):
        return []

    async def no_skills(session, bot, names):
        return []

    extra = [agent.Tool(id=None, name="list_bots", description="", parameters={}, writes=False,
                        connector=console_tools.CONSOLE, row=None, local=None)]
    bot = SimpleNamespace(id="b", provider=None, model_name="m", agentic_max_calls=3)
    await attempts.tools(None, bot, "which bots?", {}, run=run, load=load, load_skills=no_skills, extra_tools=extra)

    assert [t.name for t in given] == ["list_bots"]


# --- through the chat route --------------------------------------------------------


async def console_bot(factory):
    async with factory() as session:
        session.add(BotProfile(id="bot_console_assistant", system_id=None, name="Console Assistant",
                               provider_id="aip_1", model_name="qwen3.5:4b", is_active=True, is_platform=True,
                               agentic_enabled=True, db_query_enabled=True, retrieval_enabled=False,
                               web_search_enabled=False, combine_sources=False,
                               source_order="tools,database,documents,web"))
        await session.commit()


async def post_console(factory, token="tok-aina", message="which bots are off?"):
    app = FastAPI()
    app.include_router(chat.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post("/api/v1/chat/stream", headers={"origin": "http://localhost:8080"}, json={
            "bot_id": "bot_console_assistant", "session_id": "s1", "message": message, "history": [],
            "console_token": token})
    return response, [line[6:] for line in response.text.splitlines() if line.startswith("data: ")]


def watch(monkeypatch, super_admin=False):
    """Fakes for the portal and the agent loop; returns what each saw."""
    seen = {"tokens": [], "tools": [], "database": 0}

    async def load(token, transport=None):
        seen["tokens"].append(token)
        if token is None:
            return console_tools.Console()
        user = {"name": "Aina", "role": "super_admin" if super_admin else "system_admin", "super_admin": super_admin}
        return console_tools.Console(user=user, tools=[
            agent.Tool(id=None, name="set_bot_active", description="Switch a bot on or off.", parameters={},
                       writes=True, connector=console_tools.CONSOLE, row=None, local=None)], token=token)

    async def run(bot, message, tools, endpoint, **kwargs):
        seen["tools"].append([t.name for t in tools])
        return agent.AgentResult()

    async def database(*args, **kwargs):
        seen["database"] += 1
        from sources.result import SourceResult
        return SourceResult(kind="database")

    monkeypatch.setattr(console_tools, "load", load)
    monkeypatch.setattr(agent, "run", run)
    monkeypatch.setattr(attempts, "database", database)
    monkeypatch.setattr(chat.app_settings, "CONSOLE_ORIGIN", "http://localhost:8080")
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Here you go."))
    return seen


async def test_the_console_assistant_offers_the_persons_tools_and_no_database_to_a_workspace_admin(factory, monkeypatch):
    await console_bot(factory)
    seen = watch(monkeypatch)

    response, _ = await post_console(factory)

    assert response.status_code == 200
    assert seen["tokens"] == ["tok-aina"]
    assert seen["tools"] == [["set_bot_active"]]
    assert seen["database"] == 0


async def test_a_super_admin_keeps_the_console_database(factory, monkeypatch):
    await console_bot(factory)
    seen = watch(monkeypatch, super_admin=True)

    await post_console(factory)

    assert seen["database"] == 1


async def test_without_a_token_there_are_no_tools_and_no_database(factory, monkeypatch):
    await console_bot(factory)
    seen = watch(monkeypatch)

    await post_console(factory, token=None)

    assert seen["tools"] == [] and seen["database"] == 0


async def test_a_workspace_bot_never_asks_the_portal_for_console_tools(factory, monkeypatch):
    seen = watch(monkeypatch)
    app = FastAPI()
    app.include_router(chat.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        await client.post("/api/v1/chat/stream", json={"bot_id": "bot_1", "session_id": "s1", "message": "hi",
                                                       "history": [], "console_token": "tok-aina"})

    assert seen["tokens"] == []


# --- Confirm ------------------------------------------------------------------------


async def waiting_console_action(factory):
    await console_bot(factory)
    async with factory() as s:
        s.add(ChatConversation(id="conv_c", bot_id="bot_console_assistant", session_id="s1", origin=""))
        s.add(ChatToolCall(id="act_c", conversation_id="conv_c", connector_tool_id=None,
                           tool_name="set_bot_active", arguments={"bot": "HR Helper", "active": False},
                           status="awaiting_confirm", created_at=datetime.utcnow()))
        await s.commit()


async def decide_console(factory, token="tok-aina", decision="confirm"):
    app = FastAPI()
    app.include_router(chat.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        return await client.post("/api/v1/chat/actions/act_c", headers={"origin": "http://localhost:8080"}, json={
            "bot_id": "bot_console_assistant", "session_id": "s1", "decision": decision, "console_token": token})


def calling(monkeypatch, outcome):
    calls = []

    async def call(token, name, arguments, transport=None):
        calls.append((token, name, arguments))
        return outcome

    monkeypatch.setattr(console_tools, "call", call)
    monkeypatch.setattr(chat.app_settings, "CONSOLE_ORIGIN", "http://localhost:8080")
    return calls


async def test_confirm_runs_the_console_tool_as_the_person_who_clicked(factory, monkeypatch):
    await waiting_console_action(factory)
    calls = calling(monkeypatch, CallResult(ok=True, text="HR Helper is now off."))

    response = await decide_console(factory)

    assert response.json()["status"] == "confirmed"
    assert "HR Helper is now off." in response.json()["text"]
    assert calls == [("tok-aina", "set_bot_active", {"bot": "HR Helper", "active": False})]


async def test_a_refused_console_change_says_why(factory, monkeypatch):
    await waiting_console_action(factory)
    calling(monkeypatch, CallResult(ok=False, error="Your role does not let you do this to bot \"HR Helper\"."))

    response = await decide_console(factory)

    assert response.json()["status"] == "failed"
    assert "role" in response.json()["text"]
    async with factory() as s:
        assert (await s.get(ChatToolCall, "act_c")).status == "failed"


async def test_a_console_confirm_without_a_token_runs_nothing(factory, monkeypatch):
    await waiting_console_action(factory)
    calls = calling(monkeypatch, CallResult(ok=True, text="x"))

    response = await decide_console(factory, token=None)

    assert response.json()["status"] == "failed"
    assert calls == []
