"""Files out: the Download-as button, the make_file tool, and download links."""
import io
import json
from datetime import datetime, timedelta
from types import SimpleNamespace

import openpyxl
import pytest
from fastapi import FastAPI
from httpx import ASGITransport, AsyncClient
from sqlalchemy import select

import agent
import builtin_tools
from database import ChatConversation, ChatFile, ChatMessage, get_db
from files import storage
from routers import chat
from routers import files as files_router
from tests.test_chat_route import answering, factory, post, update_bot  # noqa: F401  (fixture)

OUT = {"out_enabled": True, "out_formats": ["xlsx", "csv"]}
TABLE = "| Item | Qty |\n|---|---|\n| Apple | 3 |"


@pytest.fixture(autouse=True)
def local_store(tmp_path, monkeypatch):
    store = storage.LocalStorage(tmp_path / "files")
    monkeypatch.setattr(storage, "_current", store)
    return store


def app_for(factory):
    app = FastAPI()
    app.include_router(files_router.router)
    app.include_router(chat.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session
    return app


async def call(factory, method, url, **kwargs):
    async with AsyncClient(transport=ASGITransport(app=app_for(factory)), base_url="http://test") as client:
        return await client.request(method, url, **kwargs)


async def an_answer(factory, session_id="s1", content=TABLE):
    async with factory() as session:
        session.add(ChatConversation(id=f"conv_{session_id}", bot_id="bot_1", session_id=session_id))
        session.add(ChatMessage(id=f"msg_{session_id}", conversation_id=f"conv_{session_id}",
                                sender="assistant", content=content))
        await session.commit()
    return f"msg_{session_id}"


def export(message_id, fmt="xlsx", session_id="s1"):
    return {"bot_id": "bot_1", "session_id": session_id, "message_id": message_id, "format": fmt}


async def test_an_answer_downloads_as_excel(factory):
    await update_bot(factory, file_settings=OUT)
    message_id = await an_answer(factory)

    made = await call(factory, "POST", "/api/v1/chat/files/export", json=export(message_id))
    assert made.status_code == 200
    got = await call(factory, "GET", made.json()["url"])

    assert got.status_code == 200 and "attachment" in got.headers["content-disposition"]
    assert openpyxl.load_workbook(io.BytesIO(got.content)).active["B2"].value == 3
    async with factory() as session:
        row = (await session.execute(select(ChatFile))).scalars().one()
    assert row.direction == "out" and row.message_id == message_id


async def test_another_sessions_answer_cannot_be_exported(factory):
    await update_bot(factory, file_settings=OUT)
    message_id = await an_answer(factory, session_id="s9")

    assert (await call(factory, "POST", "/api/v1/chat/files/export", json=export(message_id))).status_code == 404


async def test_a_format_the_bot_does_not_offer_is_refused(factory):
    await update_bot(factory, file_settings=OUT)
    message_id = await an_answer(factory)

    assert (await call(factory, "POST", "/api/v1/chat/files/export",
                       json=export(message_id, "pdf"))).status_code == 400


async def test_a_bot_with_files_out_off_exports_nothing(factory):
    message_id = await an_answer(factory)

    assert (await call(factory, "POST", "/api/v1/chat/files/export", json=export(message_id))).status_code == 409


async def test_a_download_link_needs_its_session_and_token_and_ends(factory):
    await update_bot(factory, file_settings=OUT)
    made = (await call(factory, "POST", "/api/v1/chat/files/export", json=export(await an_answer(factory)))).json()
    file_id = made["id"]

    assert (await call(factory, "GET", made["url"].replace("session_id=s1", "session_id=s9"))).status_code == 404
    assert (await call(factory, "GET", f"/api/v1/chat/files/{file_id}/download?session_id=s1&token=nope")).status_code == 404

    async with factory() as session:
        row = await session.get(ChatFile, file_id)
        row.expires_at = datetime.utcnow() - timedelta(minutes=1)
        await session.commit()
    assert (await call(factory, "GET", made["url"])).status_code == 410


def test_make_file_is_offered_only_with_files_out_and_agentic():
    def names(**fields):
        return [t.name for t in builtin_tools.for_bot(SimpleNamespace(builtin_tools=[], **fields))]

    assert "make_file" not in names(agentic_enabled=True, file_settings=None)
    assert "make_file" not in names(agentic_enabled=False, file_settings=OUT)
    [tool] = [t for t in builtin_tools.for_bot(SimpleNamespace(builtin_tools=[], agentic_enabled=True,
                                                                 file_settings=OUT)) if t.name == "make_file"]
    assert tool.parameters["properties"]["format"]["enum"] == ["csv", "xlsx"]
    assert "asks" in tool.description


async def test_a_make_file_call_hands_the_answer_back_as_a_file(factory, monkeypatch):
    await update_bot(factory, agentic_enabled=True, source_order="tools,documents,database,web",
                     retrieval_enabled=False, db_query_enabled=False, web_search_enabled=False,
                     combine_sources=False, file_settings=OUT)
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering(TABLE))

    async def run(bot, message, tools, endpoint, **kwargs):
        tool = next(t for t in tools if t.name == "make_file")
        arguments = {"format": "xlsx", "title": "Stock"}
        outcome = await tool.local(arguments)
        record = agent.CallRecord(tool, "make_file", arguments, "ran", text=outcome.text)
        return agent.AgentResult(calls=[record], model="m", context_block=agent._block([record]))

    async def no_tools(session, bot):
        return []

    monkeypatch.setattr(agent, "tools_for", no_tools)
    monkeypatch.setattr(agent, "run", run)

    _, events = await post(factory, message="can I have the stock as an Excel file?")

    parsed = [json.loads(e) for e in events if e != "[DONE]"]
    [made] = [p for p in parsed if p.get("type") == "file"]
    [answer] = [p for p in parsed if p.get("type") == "answer"]
    assert made["name"] == "stock.xlsx" and answer["formats"] == ["csv", "xlsx"]
    async with factory() as session:
        row = (await session.execute(select(ChatFile))).scalars().one()
        saved = (await session.execute(select(ChatMessage).where(ChatMessage.sender == "assistant"))).scalars().one()
    assert row.message_id == saved.id == answer["id"]


async def test_a_bot_without_files_out_sends_no_answer_event(factory, monkeypatch):
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("hello"))

    _, events = await post(factory)

    assert not any(json.loads(e).get("type") == "answer" for e in events if e != "[DONE]")
