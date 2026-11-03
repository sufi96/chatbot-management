"""Tools as a source: what an agentic bot sends the widget and writes down."""
import json
from types import SimpleNamespace

from sqlalchemy import select

import agent
from database import ChatMessage, ChatToolCall
from routers import chat
from sources import attempts
from tests.test_chat_route import answering, factory, post, update_bot  # noqa: F401  (fixture)

CONNECTOR = SimpleNamespace(name="Tickets API")


def a_tool(name, writes):
    return agent.Tool(id=f"ctl_{name}", name=name, description="Open a ticket", parameters={},
                      writes=writes, connector=CONNECTOR, row=SimpleNamespace())


def fake_agent(monkeypatch, outcome, seen=None):
    async def tools_for(session, bot):
        return [a_tool("createTicket", True)]

    async def run(bot, message, tools, endpoint, **kwargs):
        if seen is not None:
            seen.append(message)
        return outcome

    monkeypatch.setattr(agent, "tools_for", tools_for)
    monkeypatch.setattr(agent, "run", run)


def pending_ticket():
    record = agent.CallRecord(a_tool("createTicket", True), "createTicket", {"subject": "Broken login"},
                              "awaiting_confirm")
    return agent.AgentResult(calls=[record], pending=record, model="m")


def a_read():
    record = agent.CallRecord(a_tool("getTicket", False), "getTicket", {"id": "7"}, "ran",
                              excerpt="open", text="Ticket 7 is open")
    return agent.AgentResult(calls=[record], model="m",
                             context_block="<context>[1] Tickets API: getTicket\nTicket 7 is open</context>")


async def test_tools_attempt_answers_with_what_the_tools_returned():
    log = []

    async def run(*args, **kwargs):
        return a_read()

    async def load(session, bot):
        return [a_tool("getTicket", False)]

    bot = SimpleNamespace(id="b", provider=None, model_name="m", agentic_max_calls=3)
    found = await attempts.tools(None, bot, "is ticket 7 open?", {}, run=run, load=load, log=log)

    assert found.has_content and found.kind == "tools"
    assert "Ticket 7 is open" in found.context_block
    assert found.citations == [{"n": 1, "title": "Tickets API: getTicket"}]
    assert [c.name for c in log] == ["getTicket"]


async def test_tools_attempt_with_nothing_is_a_miss_but_still_logged():
    log = []
    failed = agent.CallRecord(None, "nope", {}, "failed")

    async def run(*args, **kwargs):
        return agent.AgentResult(calls=[failed])

    async def load(session, bot):
        return [a_tool("getTicket", False)]

    bot = SimpleNamespace(id="b", provider=None, model_name="m", agentic_max_calls=3)
    found = await attempts.tools(None, bot, "x", {}, run=run, load=load, log=log)

    assert found.has_content is False
    assert log == [failed]


async def test_a_pending_write_asks_the_visitor_to_confirm(factory, monkeypatch):
    await update_bot(factory, agentic_enabled=True, source_order="tools,documents,database,web",
                     retrieval_enabled=False, db_query_enabled=False, web_search_enabled=False,
                     combine_sources=False)
    prompts = []
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Press Confirm to open it.", calls=prompts))
    fake_agent(monkeypatch, pending_ticket())

    _, events = await post(factory, message="my login is broken, open a ticket?")

    parsed = [json.loads(e) for e in events if e != "[DONE]"]
    confirm = [p for p in parsed if p.get("type") == "confirm"]
    assert len(confirm) == 1
    assert "subject=Broken login" in confirm[0]["summary"]
    first_content = next(i for i, p in enumerate(parsed) if p.get("content"))
    assert parsed.index(confirm[0]) < first_content
    assert "waiting for the visitor to confirm" in prompts[0]["system_prompt"]

    async with factory() as session:
        call = (await session.execute(select(ChatToolCall))).scalars().one()
        answer = (await session.execute(select(ChatMessage).where(ChatMessage.sender == "assistant"))).scalars().one()
    assert call.id == confirm[0]["action_id"]
    assert call.status == "awaiting_confirm"
    assert call.arguments == {"subject": "Broken login"}
    assert call.message_id == answer.id
    assert answer.source_kind == "tools"


async def test_a_bot_that_is_not_agentic_never_runs_tools(factory, monkeypatch):
    await update_bot(factory, agentic_enabled=False, retrieval_enabled=False, db_query_enabled=False,
                     web_search_enabled=False)
    seen = []
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Hello."))
    fake_agent(monkeypatch, pending_ticket(), seen)

    _, events = await post(factory, message="my login is broken, open a ticket?")

    assert seen == []
    assert not any('"confirm"' in e for e in events)


def test_combined_tools_are_numbered_after_the_documents():
    import sources
    from sources.result import SourceResult

    docs = SourceResult(kind="documents", context_block="[1] Policy\nRefunds in 7 days",
                        citations=[{"n": 1, "title": "Policy"}], has_content=True)
    tools_found = SourceResult(kind="tools", context_block="[1] Tickets API: getTicket\nopen",
                               citations=[{"n": 1, "title": "Tickets API: getTicket"}], has_content=True)

    merged = sources.merge([docs, tools_found])

    assert [c["n"] for c in merged.citations] == [1, 2]
    assert "[2] Tickets API: getTicket" in merged.context_block
