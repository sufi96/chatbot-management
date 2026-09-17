"""The chat route end to end, with the database in memory and the model faked.

Each decision the route makes is tested on its own elsewhere. These pin what
only the route does: what reaches the widget, in what order, and what is
written down once the stream has ended.
"""
import json

import pytest
from fastapi import FastAPI
from httpx import ASGITransport, AsyncClient
from sqlalchemy import select
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine
from sqlalchemy.pool import StaticPool

import database
import guard
from database import AiProvider, Base, BotProfile, ChatMessage, System, get_db
from routers import chat


@pytest.fixture
async def factory(monkeypatch):
    engine = create_async_engine("sqlite+aiosqlite://", poolclass=StaticPool)
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)

    made = async_sessionmaker(engine, expire_on_commit=False)
    # The route saves the answer through the module's own factory after the
    # stream ends, outside the request's session.
    monkeypatch.setattr(database, "async_session_factory", made)

    async with made() as session:
        session.add(System(id="sys_1", name="W", allowed_origins="*"))
        session.add(AiProvider(id="aip_1", system_id="sys_1", name="Local",
                               base_url="http://llm/v1", api_key=""))
        session.add(BotProfile(id="bot_1", system_id="sys_1", name="Helper",
                               provider_id="aip_1", model_name="qwen3.5:4b",
                               is_active=True))
        await session.commit()

    yield made
    await engine.dispose()


def answering(*pieces, model="qwen3.5:4b", calls=None):
    """A fake stream_chat that streams the pieces as an endpoint would."""
    async def stream_chat(**kwargs):
        if calls is not None:
            calls.append(kwargs)
        for piece in pieces:
            yield f"data: {json.dumps({'content': piece})}\n\n"
        yield f"data: {json.dumps({'meta': {'model': model}})}\n\n"
        yield "data: [DONE]\n\n"
    return stream_chat


async def update_bot(factory, **fields):
    async with factory() as session:
        bot = await session.get(BotProfile, "bot_1")
        for name, value in fields.items():
            setattr(bot, name, value)
        await session.commit()


async def post(factory, message="tell me about your shop", history=None, bot_id="bot_1"):
    app = FastAPI()
    app.include_router(chat.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session

    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post("/api/v1/chat/stream", json={
            "bot_id": bot_id, "session_id": "s1", "message": message,
            "history": history or []})

    events = [line[6:] for line in response.text.splitlines() if line.startswith("data: ")]
    return response, events


async def rows(factory):
    """The saved messages, keyed by who sent them."""
    async with factory() as session:
        found = (await session.execute(select(ChatMessage))).scalars().all()
    return {message.sender: message for message in found}


def contents(events):
    return "".join(json.loads(e).get("content", "") for e in events if e != "[DONE]")


class FakeRequest:
    headers = {"origin": ""}


async def read_until_done(factory, message="tell me about your shop"):
    """Drive the route's own stream and stop at [DONE], as a client that closes
    the connection there does. Returns whether the answer was already saved at
    that moment, then closes the stream the way a disconnect would."""
    async with factory() as session:
        response = await chat.chat_stream(
            chat.ChatStreamRequest(bot_id="bot_1", session_id="s1", message=message, history=[]),
            FakeRequest(), session)

        async for chunk in response.body_iterator:
            if "[DONE]" in chunk:
                break

        saved_at_done = "assistant" in await rows(factory)
        await response.body_iterator.aclose()

    return saved_at_done


@pytest.mark.asyncio
async def test_the_answer_is_saved_before_the_client_is_told_it_is_done(factory, monkeypatch):
    """A client that stops reading at [DONE] closes the connection, and the
    server cancels whatever the stream still had to do. Saved after [DONE], that
    visitor's answer was never logged."""
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Hi there."))

    assert await read_until_done(factory) is True


@pytest.mark.asyncio
async def test_an_answer_is_saved_once_when_the_stream_is_read_to_the_end(factory, monkeypatch):
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Hi there."))

    await post(factory)

    async with factory() as session:
        answers = (await session.execute(
            select(ChatMessage).where(ChatMessage.sender == "assistant"))).scalars().all()
    assert len(answers) == 1


@pytest.mark.asyncio
async def test_an_answer_streams_to_the_widget_and_ends(factory, monkeypatch):
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Hi ", "there."))

    response, events = await post(factory)

    assert response.status_code == 200
    assert contents(events) == "Hi there."
    assert events[-1] == "[DONE]"


@pytest.mark.asyncio
async def test_both_sides_of_the_exchange_are_kept(factory, monkeypatch):
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Hi there."))

    await post(factory, message="tell me about your shop")
    saved = await rows(factory)

    assert saved["user"].content == "tell me about your shop"
    assert saved["assistant"].content == "Hi there."
    assert json.loads(saved["assistant"].model_trace) == {"chat": "qwen3.5:4b"}


@pytest.mark.asyncio
async def test_the_model_reads_the_visitors_own_words(factory, monkeypatch):
    calls = []
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Hi.", calls=calls))

    await post(factory, message="tell me about your shop")

    assert calls[0]["user_message"] == "tell me about your shop"
    assert calls[0]["base_url"] == "http://llm/v1"


@pytest.mark.asyncio
async def test_an_unknown_bot_is_not_found(factory, monkeypatch):
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Hi."))

    response, _ = await post(factory, bot_id="bot_missing")

    assert response.status_code == 404


@pytest.mark.asyncio
async def test_a_deleted_bot_is_not_found(factory, monkeypatch):
    from datetime import datetime

    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Hi."))
    await update_bot(factory, deleted_at=datetime.utcnow())

    response, _ = await post(factory)

    assert response.status_code == 404


@pytest.mark.asyncio
async def test_an_empty_answer_saves_no_assistant_row(factory, monkeypatch):
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering())

    await post(factory)

    assert "assistant" not in await rows(factory)


def verdicts(*results, calls=None):
    """A fake guard.check returning each verdict in turn, then safe."""
    queue = list(results)

    async def check(bot, text, settings, complete=None):
        if calls is not None:
            calls.append(text)
        return queue.pop(0) if queue else guard.Verdict(ran=True, model="guard-m")
    return check


def model_must_not_answer():
    async def stream_chat(**kwargs):
        raise AssertionError("a refused message must not reach the answer model")
        yield  # pragma: no cover
    return stream_chat


@pytest.mark.asyncio
async def test_a_bot_without_the_guard_never_asks_it(factory, monkeypatch):
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Hi."))

    async def exploding(*args, **kwargs):
        raise AssertionError("the guard is switched off")
    monkeypatch.setattr(guard, "check", exploding)

    response, events = await post(factory)

    assert contents(events) == "Hi."


@pytest.mark.asyncio
async def test_an_unsafe_message_is_refused_before_the_model(factory, monkeypatch):
    await update_bot(factory, guard_enabled=True)
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", model_must_not_answer())
    monkeypatch.setattr(guard, "check", verdicts(
        guard.Verdict(safe=False, category="Violent", ran=True, model="guard-m")))

    _, events = await post(factory, message="something harmful")
    saved = await rows(factory)

    assert contents(events) == guard.DEFAULT_REFUSAL
    assert events[-1] == "[DONE]"
    assert saved["user"].guard_flag == "Violent"
    assert saved["assistant"].content == guard.DEFAULT_REFUSAL
    assert json.loads(saved["assistant"].model_trace) == {"guard": "guard-m"}


@pytest.mark.asyncio
async def test_a_refusal_is_said_in_the_bots_own_words(factory, monkeypatch):
    await update_bot(factory, guard_enabled=True, guard_refusal="Maaf, tidak boleh.")
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", model_must_not_answer())
    monkeypatch.setattr(guard, "check", verdicts(guard.Verdict(safe=False, ran=True)))

    _, events = await post(factory, message="something harmful")

    assert contents(events) == "Maaf, tidak boleh."


@pytest.mark.asyncio
async def test_a_flag_with_no_category_still_says_unsafe(factory, monkeypatch):
    await update_bot(factory, guard_enabled=True)
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", model_must_not_answer())
    monkeypatch.setattr(guard, "check", verdicts(guard.Verdict(safe=False, ran=True)))

    await post(factory, message="something harmful")

    assert (await rows(factory))["user"].guard_flag == "unsafe"


@pytest.mark.asyncio
async def test_a_safe_message_is_answered_and_the_exchange_is_checked(factory, monkeypatch):
    await update_bot(factory, guard_enabled=True)
    calls = []
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Two year warranty."))
    monkeypatch.setattr(guard, "check", verdicts(calls=calls))

    _, events = await post(factory, message="what is the warranty")
    saved = await rows(factory)

    assert contents(events) == "Two year warranty."
    assert calls == ["what is the warranty",
                     "Visitor: what is the warranty\n\nAssistant: Two year warranty."]
    assert saved["assistant"].guard_flag is None
    assert json.loads(saved["assistant"].model_trace)["guard"] == "guard-m"


@pytest.mark.asyncio
async def test_an_unsafe_answer_is_flagged_not_retracted(factory, monkeypatch):
    await update_bot(factory, guard_enabled=True)
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Something it should not say."))
    monkeypatch.setattr(guard, "check", verdicts(
        guard.Verdict(ran=True, model="guard-m"),
        guard.Verdict(safe=False, category="Self-Harm", ran=True, model="guard-m")))

    _, events = await post(factory, message="an innocent question")
    saved = await rows(factory)

    assert contents(events) == "Something it should not say."
    assert saved["assistant"].guard_flag == "Self-Harm"
    assert saved["user"].guard_flag is None


@pytest.mark.asyncio
async def test_a_guard_that_fails_open_lets_the_answer_through(factory, monkeypatch):
    await update_bot(factory, guard_enabled=True)
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Hi."))
    monkeypatch.setattr(guard, "check", verdicts(guard.Verdict(), guard.Verdict()))

    _, events = await post(factory)
    saved = await rows(factory)

    assert contents(events) == "Hi."
    assert "guard" not in json.loads(saved["assistant"].model_trace)
