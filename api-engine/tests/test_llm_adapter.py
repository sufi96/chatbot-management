import json

import httpx
import pytest

from llm_adapter import LLMAdapter


def delta(**fields):
    return {"choices": [{"delta": fields}]}


def sse_body(*chunks):
    body = "".join("data: " + json.dumps(c) + "\n\n" for c in chunks)
    return (body + "data: [DONE]\n\n").encode()


async def run_stream(handler, **overrides):
    """Drive stream_chat against a faked endpoint, returning parsed payloads."""
    kwargs = dict(
        base_url="http://fake/v1",
        api_key="secret",
        model_name="qwen3",
        system_prompt="be nice",
        temperature=0.7,
        max_tokens=512,
        history=[],
        user_message="hello",
        thinking_level="off",
    )
    kwargs.update(overrides)

    payloads = []
    async for raw in LLMAdapter.stream_chat(
        transport=httpx.MockTransport(handler), **kwargs
    ):
        data = raw[6:].strip()
        if data and data != "[DONE]":
            payloads.append(json.loads(data))
    return payloads


def capture(*chunks):
    """A handler that records the request body and replays the given chunks."""
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["body"] = json.loads(request.read())
        return httpx.Response(200, content=sse_body(*chunks))

    return seen, handler


@pytest.mark.asyncio
async def test_thinking_off_turns_the_qwen_template_switch_off():
    seen, handler = capture(delta(content="Hi"))
    await run_stream(handler, thinking_level="off")

    assert seen["body"]["chat_template_kwargs"] == {"enable_thinking": False}
    assert "reasoning_effort" not in seen["body"]


@pytest.mark.asyncio
async def test_a_thinking_level_turns_the_switch_on_and_names_the_effort():
    seen, handler = capture(delta(content="Hi"))
    await run_stream(handler, thinking_level="medium")

    assert seen["body"]["chat_template_kwargs"] == {"enable_thinking": True}
    assert seen["body"]["reasoning_effort"] == "medium"


@pytest.mark.asyncio
async def test_separate_reasoning_field_is_streamed_as_its_own_event():
    _, handler = capture(
        delta(reasoning_content="weighing"), delta(content="Hello")
    )
    payloads = await run_stream(handler, thinking_level="high")

    assert {"reasoning": "weighing"} in payloads
    assert {"content": "Hello"} in payloads


@pytest.mark.asyncio
async def test_inline_think_tags_never_reach_the_answer():
    _, handler = capture(
        delta(content="<think>pon"), delta(content="der</think>Hi!")
    )
    payloads = await run_stream(handler, thinking_level="low")

    answer = "".join(p["content"] for p in payloads if "content" in p)
    thought = "".join(p["reasoning"] for p in payloads if "reasoning" in p)
    assert answer == "Hi!"
    assert thought == "ponder"


@pytest.mark.asyncio
async def test_thinking_off_emits_no_reasoning_at_all():
    _, handler = capture(
        delta(reasoning_content="hidden"),
        delta(content="<think>also hidden</think>Just this"),
    )
    payloads = await run_stream(handler, thinking_level="off")

    assert not any("reasoning" in p for p in payloads)
    assert "".join(p["content"] for p in payloads if "content" in p) == "Just this"


@pytest.mark.asyncio
async def test_an_endpoint_that_rejects_the_template_switch_is_asked_again_without_it():
    bodies = []

    def handler(request: httpx.Request) -> httpx.Response:
        bodies.append(json.loads(request.read()))
        if len(bodies) == 1:
            return httpx.Response(400, text="Unrecognized request argument supplied: chat_template_kwargs")
        return httpx.Response(200, content=sse_body(delta(content="Hello")))

    payloads = await run_stream(handler, thinking_level="off")

    assert "chat_template_kwargs" not in bodies[1]
    assert {"content": "Hello"} in payloads
    assert not any("error" in p for p in payloads)


@pytest.mark.asyncio
async def test_an_unrelated_rejection_is_reported_rather_than_retried():
    calls = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(1)
        return httpx.Response(400, text="model not found")

    payloads = await run_stream(handler, thinking_level="off")

    assert len(calls) == 1
    assert any("error" in p for p in payloads)


@pytest.mark.asyncio
async def test_a_prefilled_think_block_ends_up_out_of_the_answer():
    """The exact shape a Qwen3 template produces: no opening tag, a late close."""
    _, handler = capture(
        delta(content="Thinking Process: 1. Analyze "),
        delta(content="the request.</thi"),
        delta(content="nk>Hello! I'm Sarjan Amir."),
    )
    payloads = await run_stream(handler, thinking_level="medium")

    kinds = [k for p in payloads for k in p]
    assert "reclassify" in kinds

    before = payloads[:kinds.index("reclassify")]
    after = payloads[kinds.index("reclassify") + 1:]
    assert "".join(p["content"] for p in before if "content" in p) == (
        "Thinking Process: 1. Analyze the request.")
    assert "".join(p["content"] for p in after if "content" in p) == "Hello! I'm Sarjan Amir."


@pytest.mark.asyncio
async def test_usage_is_requested_on_the_stream():
    seen, handler = capture(delta(content="Hi"))
    await run_stream(handler)

    assert seen["body"]["stream_options"] == {"include_usage": True}


@pytest.mark.asyncio
async def test_reported_usage_becomes_a_meta_event():
    _, handler = capture(
        {"model": "qwen3.5-4b", "choices": [{"delta": {"content": "Hi"}}]},
        {"model": "qwen3.5-4b", "choices": [],
         "usage": {"prompt_tokens": 118, "completion_tokens": 12, "total_tokens": 130}},
    )
    payloads = await run_stream(handler)

    meta = [p["meta"] for p in payloads if "meta" in p]
    assert len(meta) == 1
    assert meta[0]["model"] == "qwen3.5-4b"
    assert meta[0]["tokens_in"] == 118
    assert meta[0]["tokens_out"] == 12


@pytest.mark.asyncio
async def test_meta_still_names_the_model_when_usage_is_missing():
    _, handler = capture(delta(content="Hi"))
    payloads = await run_stream(handler, model_name="llama3.2")

    meta = [p["meta"] for p in payloads if "meta" in p][0]
    assert meta["model"] == "llama3.2"
    assert "tokens_in" not in meta
    assert "tokens_out" not in meta


@pytest.mark.asyncio
async def test_meta_arrives_after_the_answer():
    _, handler = capture(delta(content="Hi"))
    payloads = await run_stream(handler)

    kinds = [k for p in payloads for k in p]
    assert kinds.index("meta") > kinds.index("content")


@pytest.mark.asyncio
async def test_an_endpoint_rejecting_stream_options_is_asked_again_without_it():
    bodies = []

    def handler(request: httpx.Request) -> httpx.Response:
        bodies.append(json.loads(request.read()))
        if len(bodies) == 1:
            return httpx.Response(400, text="Unrecognized request argument supplied: stream_options")
        return httpx.Response(200, content=sse_body(delta(content="Hello")))

    payloads = await run_stream(handler)

    assert "stream_options" not in bodies[1]
    assert {"content": "Hello"} in payloads
    assert not any("error" in p for p in payloads)
