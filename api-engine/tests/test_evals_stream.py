"""Asking the engine as the widget does, over a faked transport and a faked clock."""
import json

import httpx
import pytest

from evals.cases import Case
from evals.stream import ask


def sse(*payloads):
    return "".join(f"data: {json.dumps(p)}\n\n" for p in payloads) + "data: [DONE]\n\n"


def ticking(*times):
    """A clock that returns each time in turn, then keeps returning the last."""
    queue = list(times)

    def clock():
        return queue.pop(0) if len(queue) > 1 else queue[0]
    return clock


@pytest.mark.asyncio
async def test_the_request_is_what_the_widget_sends():
    seen = {}

    def handler(request):
        seen["url"] = str(request.url)
        seen["body"] = json.loads(request.read().decode())
        return httpx.Response(200, text=sse({"content": "Hi."}))

    case = Case(id="c1", message="and the warranty?",
                history=({"role": "user", "content": "X200 price?"},
                         {"role": "assistant", "content": "RM 399."}))
    await ask("http://engine:8000/", "bot_1", case, "eval-s-1", transport=httpx.MockTransport(handler))

    assert seen["url"] == "http://engine:8000/api/v1/chat/stream"
    assert seen["body"] == {
        "bot_id": "bot_1", "session_id": "eval-s-1", "message": "and the warranty?",
        "history": [{"role": "user", "content": "X200 price?"},
                    {"role": "assistant", "content": "RM 399."},
                    {"role": "user", "content": "and the warranty?"}]}


@pytest.mark.asyncio
async def test_the_answer_sources_model_and_timing_are_observed():
    body = sse({"type": "sources", "kind": "documents",
                "sources": [{"n": 1, "title": "Warranty Policy"}]},
               {"content": "Two "}, {"content": "years."},
               {"meta": {"model": "qwen3.5-4b", "tokens_in": 40, "tokens_out": 3}})

    observed = await ask("http://engine", "bot_1", Case(id="c1", message="warranty?"), "s",
                         transport=httpx.MockTransport(lambda r: httpx.Response(200, text=body)),
                         clock=ticking(10.0, 10.4, 12.0))

    assert observed.status == 200
    assert observed.answer == "Two years."
    assert observed.source == "documents"
    assert observed.citations == [{"n": 1, "title": "Warranty Policy"}]
    assert observed.model == "qwen3.5-4b"
    assert (observed.tokens_in, observed.tokens_out) == (40, 3)
    assert observed.first_token_seconds == pytest.approx(0.4)
    assert observed.total_seconds == pytest.approx(2.0)
    assert observed.error == ""


@pytest.mark.asyncio
async def test_no_sources_event_means_no_source():
    observed = await ask("http://engine", "bot_1", Case(id="c1", message="hello"), "s",
                         transport=httpx.MockTransport(
                             lambda r: httpx.Response(200, text=sse({"content": "Hi!"}))))

    assert observed.source == "none"
    assert observed.citations == []


@pytest.mark.asyncio
async def test_thinking_is_kept_out_of_the_answer():
    body = sse({"reasoning": "the visitor greets"}, {"content": "Hello!"})

    observed = await ask("http://engine", "bot_1", Case(id="c1", message="hi"), "s",
                         transport=httpx.MockTransport(lambda r: httpx.Response(200, text=body)))

    assert observed.answer == "Hello!"


@pytest.mark.asyncio
async def test_an_error_event_is_recorded():
    body = sse({"error": "Cannot connect to LLM server"})

    observed = await ask("http://engine", "bot_1", Case(id="c1", message="hi"), "s",
                         transport=httpx.MockTransport(lambda r: httpx.Response(200, text=body)))

    assert observed.error == "Cannot connect to LLM server"
    assert observed.first_token_seconds is None


@pytest.mark.asyncio
async def test_a_refused_request_is_recorded_with_its_status():
    observed = await ask("http://engine", "bot_missing", Case(id="c1", message="hi"), "s",
                         transport=httpx.MockTransport(
                             lambda r: httpx.Response(404, json={"detail": "Bot profile not found"})))

    assert observed.status == 404
    assert observed.error.startswith("HTTP 404")


@pytest.mark.asyncio
async def test_an_unreachable_engine_is_recorded_not_raised():
    def handler(request):
        raise httpx.ConnectError("refused")

    observed = await ask("http://engine", "bot_1", Case(id="c1", message="hi"), "s",
                         transport=httpx.MockTransport(handler))

    assert observed.error.startswith("ConnectError")
