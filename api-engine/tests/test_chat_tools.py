"""Tools as a source: what an agentic bot sends the widget and writes down."""
import json
from types import SimpleNamespace

from sqlalchemy import select

import agent
from database import ChatMessage, ChatToolCall
from routers import chat
from sources import attempts
from tests.test_chat_route import answering, factory, post, rows, update_bot  # noqa: F401  (fixture)

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


async def no_skills(session, bot, names):
    return []


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
    found = await attempts.tools(None, bot, "is ticket 7 open?", {}, run=run, load=load, log=log,
                                 load_skills=no_skills)

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
    found = await attempts.tools(None, bot, "x", {}, run=run, load=load, log=log, load_skills=no_skills)

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
    from kb.retrieval import NO_CONTEXT_INSTRUCTION
    assert NO_CONTEXT_INSTRUCTION.strip() not in prompts[0]["system_prompt"]

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


# The visitor's Confirm or Cancel, by the action id the widget was given.

from datetime import datetime, timedelta  # noqa: E402

from httpx import ASGITransport, AsyncClient  # noqa: E402
from fastapi import FastAPI  # noqa: E402

from connectors import rest  # noqa: E402
from database import (BotConnectorTool, ChatConversation, Connector, ConnectorTool,  # noqa: E402
                      get_db)


async def waiting_action(factory, created=None, session_id="s1", status="awaiting_confirm"):
    await update_bot(factory, agentic_enabled=True)
    async with factory() as s:
        s.add(Connector(id="con_1", system_id="sys_1", name="Tickets API", base_url="https://t.test"))
        s.add(ConnectorTool(id="ctl_1", connector_id="con_1", name="createTicket", method="post",
                            path="/tickets", writes=True, enabled=True, parameters={}))
        s.add(BotConnectorTool(bot_id="bot_1", connector_tool_id="ctl_1"))
        s.add(ChatConversation(id="conv_1", bot_id="bot_1", session_id=session_id, origin=""))
        s.add(ChatToolCall(id="act_1", conversation_id="conv_1", connector_tool_id="ctl_1",
                           tool_name="createTicket", arguments={"body": {"subject": "x"}},
                           status=status, created_at=created or datetime.utcnow()))
        await s.commit()


async def decide(factory, decision="confirm", session_id="s1", action_id="act_1"):
    app = FastAPI()
    app.include_router(chat.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        return await client.post(f"/api/v1/chat/actions/{action_id}", json={
            "bot_id": "bot_1", "session_id": session_id, "decision": decision})


def counting_call(monkeypatch, ok=True):
    calls = []

    async def call(connector, row, arguments, **kwargs):
        calls.append(arguments)
        return rest.CallResult(ok=ok, status=201 if ok else 500, error="" if ok else "The API answered 500.")

    monkeypatch.setattr(rest, "call", call)
    return calls


async def status_of(factory, action_id="act_1"):
    async with factory() as s:
        return (await s.get(ChatToolCall, action_id)).status


async def test_confirm_runs_the_action_once(factory, monkeypatch):
    await waiting_action(factory)
    calls = counting_call(monkeypatch)

    first = await decide(factory)
    second = await decide(factory)

    assert first.status_code == 200 and first.json()["status"] == "confirmed"
    assert first.json()["text"] == "Done."
    assert calls == [{"body": {"subject": "x"}}]
    assert second.status_code == 409
    assert await status_of(factory) == "confirmed"
    saved = (await rows(factory))["assistant"]
    assert saved.content == "Done."


async def test_another_sessions_action_is_not_found(factory, monkeypatch):
    await waiting_action(factory)
    calls = counting_call(monkeypatch)

    response = await decide(factory, session_id="someone-else")

    assert response.status_code == 404
    assert calls == []


async def test_an_old_action_expires(factory, monkeypatch):
    await waiting_action(factory, created=datetime.utcnow() - timedelta(minutes=11))
    calls = counting_call(monkeypatch)

    response = await decide(factory)

    assert response.status_code == 409
    assert calls == []
    assert await status_of(factory) == "expired"


async def test_decline_never_calls(factory, monkeypatch):
    await waiting_action(factory)
    calls = counting_call(monkeypatch)

    response = await decide(factory, decision="decline")

    assert response.json() == {"status": "declined", "text": "Cancelled. Nothing was sent."}
    assert calls == []


async def test_a_failed_action_says_so(factory, monkeypatch):
    await waiting_action(factory)
    counting_call(monkeypatch, ok=False)

    response = await decide(factory)

    assert response.json()["status"] == "failed"
    assert "500" in response.json()["text"]


async def test_a_tool_switched_off_since_is_not_run(factory, monkeypatch):
    await waiting_action(factory)
    calls = counting_call(monkeypatch)
    async with factory() as s:
        (await s.get(ConnectorTool, "ctl_1")).enabled = False
        await s.commit()

    response = await decide(factory)

    assert response.json()["status"] == "failed"
    assert calls == []


async def test_tools_attempt_hands_the_bots_skills_to_the_loop():
    seen = {}

    async def run(bot, message, tools, endpoint, **kwargs):
        seen.update(kwargs)
        return agent.AgentResult()

    async def load(session, bot):
        return [a_tool("getTicket", False)]

    async def load_skills(session, bot, names):
        seen["names"] = names
        return ["a skill"]

    bot = SimpleNamespace(id="b", provider=None, model_name="m", agentic_max_calls=3)
    await attempts.tools(None, bot, "x", {}, run=run, load=load, load_skills=load_skills)

    assert seen["skills"] == ["a skill"]
    assert seen["names"] == {"getTicket"}


async def test_skills_alone_reach_the_loop():
    seen = []

    async def run(bot, message, tools, endpoint, **kwargs):
        seen.append(kwargs["skills"])
        return agent.AgentResult()

    async def load(session, bot):
        return []

    async def load_skills(session, bot, names):
        return ["human-handover"]

    bot = SimpleNamespace(id="b", provider=None, model_name="m", agentic_max_calls=3)
    await attempts.tools(None, bot, "x", {}, run=run, load=load, load_skills=load_skills)

    assert seen == [["human-handover"]]


async def test_a_loaded_skill_guides_the_answer(factory, monkeypatch):
    await update_bot(factory, agentic_enabled=True, source_order="tools,documents,database,web",
                     retrieval_enabled=False, db_query_enabled=False, web_search_enabled=False,
                     combine_sources=False)
    prompts = []
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("What is your reference number?", calls=prompts))
    fake_agent(monkeypatch, agent.AgentResult(guidance="1. Ask for the reference number."))

    await post(factory, message="where is my application?")

    from kb.retrieval import NO_CONTEXT_INSTRUCTION
    assert "1. Ask for the reference number." in prompts[0]["system_prompt"]
    assert NO_CONTEXT_INSTRUCTION.strip() not in prompts[0]["system_prompt"]


async def test_tools_attempt_passes_the_conversation_and_skill_guidance():
    seen = {}

    async def run(bot, message, tools, endpoint, **kwargs):
        seen.update(kwargs)
        return agent.AgentResult(guidance="steps")

    async def load(session, bot):
        return [a_tool("getTicket", False)]

    bot = SimpleNamespace(id="b", provider=None, model_name="m", agentic_max_calls=3)
    turns = [{"role": "user", "content": "hi"}]
    found = await attempts.tools(None, bot, "x", {}, run=run, load=load, load_skills=no_skills, history=turns)

    assert seen["history"] == turns
    assert found.has_content and found.guidance == "steps"


async def test_a_database_query_with_no_rows_still_says_nothing_was_found(factory, monkeypatch):
    from kb.retrieval import NO_CONTEXT_INSTRUCTION
    from sources.result import SourceResult

    await update_bot(factory, retrieval_enabled=False, db_query_enabled=True, web_search_enabled=False,
                     combine_sources=False)
    prompts = []
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Not found.", calls=prompts))

    async def no_rows():
        return SourceResult(kind="database", has_content=True, citations=[{"n": 1, "title": "Orders"}])

    monkeypatch.setattr(chat.sources, "build_attempts", lambda *a, **k: {"database": no_rows})

    await post(factory, message="what is the status of order 991?")

    assert NO_CONTEXT_INSTRUCTION.strip() in prompts[0]["system_prompt"]


async def test_a_second_confirm_while_the_first_runs_is_refused(factory, monkeypatch):
    await waiting_action(factory)
    calls, second = [], []

    async def call(connector, row, arguments, **kwargs):
        calls.append(arguments)
        # A double click: the same Confirm arrives while this one is running.
        second.append((await decide(factory)).status_code)
        return rest.CallResult(ok=True, status=201)

    monkeypatch.setattr(rest, "call", call)

    first = await decide(factory)

    assert first.json()["status"] == "confirmed"
    assert second == [409]
    assert len(calls) == 1


async def test_a_bot_switched_off_agentic_runs_nothing(factory, monkeypatch):
    await waiting_action(factory)
    await update_bot(factory, agentic_enabled=False)
    calls = counting_call(monkeypatch)

    response = await decide(factory)

    assert response.json()["status"] == "failed"
    assert calls == []


async def test_asking_for_a_person_marks_the_conversation(factory, monkeypatch):
    import builtin_tools
    await update_bot(factory, agentic_enabled=True, source_order="tools,documents,database,web",
                     retrieval_enabled=False, db_query_enabled=False, web_search_enabled=False,
                     combine_sources=False)
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Someone will follow up."))
    handover = builtin_tools.for_bot(SimpleNamespace(builtin_tools=["request_human"]))[0]
    record = agent.CallRecord(handover, "request_human", {"reason": "Billing dispute"}, "ran",
                              text="A member of staff has been asked to follow up.")
    fake_agent(monkeypatch, agent.AgentResult(calls=[record], context_block="<context>x</context>"))

    await post(factory, message="can I talk to a person about my bill?")

    async with factory() as session:
        conv = (await session.execute(select(ChatConversation))).scalars().one()
        call = (await session.execute(select(ChatToolCall))).scalars().one()
    assert conv.handover_reason == "Billing dispute"
    assert conv.handover_requested_at is not None
    assert call.connector_tool_id is None
