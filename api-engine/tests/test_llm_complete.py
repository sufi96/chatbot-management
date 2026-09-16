"""One-shot completion, for the router and the SQL generator.

Neither wants a stream and neither wants creativity, which is why the
temperature defaults to zero.
"""
import json

import httpx
import pytest

from llm_adapter import LLMAdapter


def responder(payload, capture=None, status=200):
    def handler(request: httpx.Request) -> httpx.Response:
        if capture is not None:
            capture["url"] = str(request.url)
            capture["headers"] = dict(request.headers)
            capture["body"] = request.read().decode()
        return httpx.Response(status, json=payload)
    return handler


ANSWER = {"choices": [{"message": {"content": "database"}}]}


@pytest.mark.asyncio
async def test_the_content_comes_back():
    answer = await LLMAdapter.complete(
        "http://localhost:11434/v1", "", "llama3.2", "route this", "hello",
        transport=httpx.MockTransport(responder(ANSWER)))

    assert answer == "database"


@pytest.mark.asyncio
async def test_the_request_does_not_ask_for_a_stream():
    seen = {}
    await LLMAdapter.complete(
        "http://localhost:11434/v1", "", "llama3.2", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert '"stream": false' in seen["body"] or '"stream":false' in seen["body"]


@pytest.mark.asyncio
async def test_the_endpoint_is_normalised_like_the_streaming_one():
    seen = {}
    await LLMAdapter.complete(
        "http://localhost:11434/v1", "", "llama3.2", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert seen["url"].endswith("/v1/chat/completions")


@pytest.mark.asyncio
async def test_a_key_is_sent_as_a_bearer_token():
    seen = {}
    await LLMAdapter.complete(
        "http://x/v1", "sk-abc", "m", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert seen["headers"]["authorization"] == "Bearer sk-abc"


@pytest.mark.asyncio
async def test_no_key_sends_no_authorization_header():
    seen = {}
    await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert "authorization" not in seen["headers"]


@pytest.mark.asyncio
async def test_the_temperature_defaults_to_zero():
    seen = {}
    await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert '"temperature": 0' in seen["body"] or '"temperature":0' in seen["body"]


@pytest.mark.asyncio
async def test_an_http_error_returns_empty_rather_than_raising():
    # Every caller treats empty as "fall through", so a failure here must
    # never escape into a visitor's conversation.
    answer = await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u",
        transport=httpx.MockTransport(responder({"error": "nope"}, status=500)))

    assert answer == ""


@pytest.mark.asyncio
async def test_a_malformed_body_returns_empty():
    def handler(request):
        return httpx.Response(200, text="not json")

    answer = await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u", transport=httpx.MockTransport(handler))

    assert answer == ""


@pytest.mark.asyncio
async def test_a_missing_choices_key_returns_empty():
    answer = await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u",
        transport=httpx.MockTransport(responder({"id": "x"})))

    assert answer == ""


@pytest.mark.asyncio
async def test_thinking_is_switched_off_for_one_shot_calls():
    """A generated statement is one line. Reasoning aloud spends the whole
    token budget before the model reaches it, which reads as no statement at
    all and loses the database its turn.
    """
    seen = {}
    await LLMAdapter.complete(
        "http://localhost:11434/v1", "", "qwen3.5-4b", "route this", "hello",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    body = json.loads(seen["body"])
    assert body["chat_template_kwargs"] == {"enable_thinking": False}


@pytest.mark.asyncio
async def test_an_endpoint_that_refuses_the_thinking_switch_is_asked_again():
    """A strict endpoint rejects a body key it does not know. Losing the
    statement over a nicety would put the bot back to never querying its
    database.
    """
    seen = []

    def handler(request: httpx.Request) -> httpx.Response:
        body = json.loads(request.read().decode())
        seen.append(body)
        if "chat_template_kwargs" in body:
            return httpx.Response(400, json={
                "error": {"message": "unrecognised key chat_template_kwargs"}})
        return httpx.Response(200, json=ANSWER)

    answer = await LLMAdapter.complete(
        "http://localhost:11434/v1", "", "qwen3.5-4b", "route this", "hello",
        transport=httpx.MockTransport(handler))

    assert answer == "database"
    assert len(seen) == 2
    assert "chat_template_kwargs" not in seen[1]


@pytest.mark.asyncio
async def test_json_can_be_asked_for():
    seen = {}
    await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u", response_format={"type": "json_object"},
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert json.loads(seen["body"])["response_format"] == {"type": "json_object"}


@pytest.mark.asyncio
async def test_json_is_not_asked_for_unless_wanted():
    seen = {}
    await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert "response_format" not in json.loads(seen["body"])


@pytest.mark.asyncio
async def test_an_endpoint_that_refuses_json_mode_is_asked_again_without_it():
    """JSON mode is a nicety. The parser copes with prose around the object,
    so losing the whole verdict to an endpoint that lacks it would be worse."""
    seen = []

    def handler(request: httpx.Request) -> httpx.Response:
        body = json.loads(request.read().decode())
        seen.append(body)
        if "response_format" in body:
            return httpx.Response(400, json={
                "error": {"message": "response_format is not supported"}})
        return httpx.Response(200, json=ANSWER)

    answer = await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u", response_format={"type": "json_object"},
        transport=httpx.MockTransport(handler))

    assert answer == "database"
    assert len(seen) == 2
    assert "response_format" not in seen[1]
