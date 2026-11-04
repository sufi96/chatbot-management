"""Attachments on a chat message: linked to it, read into the answer, never across sessions."""
from datetime import datetime, timedelta

from fastapi import FastAPI
from httpx import ASGITransport, AsyncClient
from sqlalchemy import select

from database import ChatConversation, ChatFile, ChatMessage, get_db
from llm_adapter import LLMAdapter
from routers import chat
from tests.test_chat_route import answering, factory, update_bot  # noqa: F401  (fixture)

ON = {"in_enabled": True}


async def a_file(factory, file_id, session_id="s1", text="The warranty lasts 24 months."):
    async with factory() as session:
        conv = (await session.execute(select(ChatConversation).where(
            ChatConversation.session_id == session_id))).scalars().first()
        if conv is None:
            conv = ChatConversation(id=f"conv_{session_id}", bot_id="bot_1", session_id=session_id)
            session.add(conv)
        session.add(ChatFile(id=file_id, conversation_id=conv.id, bot_id="bot_1", direction="in",
                             name=f"{file_id}.txt", mime="text/plain", ext="txt", size=10,
                             storage_key=f"bot_1/{file_id}.txt", sha256="0" * 64, scan_status="not_scanned",
                             text=text, token=file_id * 4, created_at=datetime.utcnow(),
                             expires_at=datetime.utcnow() + timedelta(days=30)))
        await session.commit()


async def send(factory, message, attachments, session_id="s1"):
    app = FastAPI()
    app.include_router(chat.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        return await client.post("/api/v1/chat/stream", json={
            "bot_id": "bot_1", "session_id": session_id, "message": message, "attachments": attachments})


async def linked(factory, file_id):
    async with factory() as session:
        return (await session.get(ChatFile, file_id)).message_id


async def test_an_attached_file_is_read_into_the_answer(factory, monkeypatch):
    await update_bot(factory, file_settings=ON)
    await a_file(factory, "f1")
    calls = []
    monkeypatch.setattr(LLMAdapter, "stream_chat", answering("24 months.", calls=calls))

    response = await send(factory, "How long is the warranty?", ["f1"])

    assert response.status_code == 200
    assert "The warranty lasts 24 months." in calls[0]["system_prompt"]
    assert await linked(factory, "f1")


async def test_another_sessions_file_is_ignored(factory, monkeypatch):
    await update_bot(factory, file_settings=ON)
    await a_file(factory, "theirs", session_id="s9", text="Secret salary list")
    calls = []
    monkeypatch.setattr(LLMAdapter, "stream_chat", answering("ok", calls=calls))

    await send(factory, "What does the file say?", ["theirs"])

    assert "Secret salary list" not in calls[0]["system_prompt"]
    assert await linked(factory, "theirs") is None


async def test_a_message_with_only_a_file_is_answered(factory, monkeypatch):
    await update_bot(factory, file_settings=ON)
    await a_file(factory, "f1")
    calls = []
    monkeypatch.setattr(LLMAdapter, "stream_chat", answering("It is about a warranty.", calls=calls))

    response = await send(factory, "", ["f1"])

    assert response.status_code == 200
    assert calls[0]["user_message"] == "Please read the attached file."
    async with factory() as session:
        sent = (await session.execute(select(ChatMessage).where(ChatMessage.sender == "user"))).scalars().one()
    assert sent.content == "Please read the attached file."


async def test_more_files_than_the_bot_allows_is_refused(factory, monkeypatch):
    await update_bot(factory, file_settings={**ON, "per_message": 1})
    await a_file(factory, "f1")
    await a_file(factory, "f2")
    monkeypatch.setattr(LLMAdapter, "stream_chat", answering("ok"))

    assert (await send(factory, "read these", ["f1", "f2"])).status_code == 400


async def test_a_bot_with_files_off_never_reads_them(factory, monkeypatch):
    await a_file(factory, "f1")
    calls = []
    monkeypatch.setattr(LLMAdapter, "stream_chat", answering("ok", calls=calls))

    await send(factory, "What does it say?", ["f1"])

    assert "warranty" not in calls[0]["system_prompt"]
