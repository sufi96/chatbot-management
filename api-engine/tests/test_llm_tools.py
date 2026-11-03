"""One non-streamed completion that may ask for tools."""
import json

import httpx

from llm_adapter import LLMAdapter

TOOLS = [{"type": "function", "function": {"name": "getApplication", "description": "",
                                           "parameters": {"type": "object", "properties": {}}}}]
MESSAGES = [{"role": "user", "content": "status of A1?"}]


def ask(transport):
    return LLMAdapter.complete_with_tools("http://llm.test/v1", "k", "m", MESSAGES, TOOLS, transport=transport)


async def test_the_reply_message_is_returned_with_its_tool_calls():
    seen = []
    message = {"role": "assistant", "content": None, "tool_calls": [
        {"id": "c1", "type": "function", "function": {"name": "getApplication", "arguments": "{}"}}]}

    def handler(request):
        seen.append(json.loads(request.content))
        return httpx.Response(200, json={"choices": [{"message": message}]})

    reply = await ask(httpx.MockTransport(handler))

    assert reply["tool_calls"][0]["function"]["name"] == "getApplication"
    assert seen[0]["tools"] == TOOLS
    assert seen[0]["tool_choice"] == "auto"
    assert seen[0]["stream"] is False
    assert seen[0]["messages"] == MESSAGES


async def test_a_provider_without_tools_gives_none():
    def handler(request):
        return httpx.Response(400, text='{"error": "unknown field: tools"}')

    assert await ask(httpx.MockTransport(handler)) is None


async def test_the_thinking_switch_is_dropped_when_refused():
    bodies = []

    def handler(request):
        body = json.loads(request.content)
        bodies.append(body)
        if "chat_template_kwargs" in body:
            return httpx.Response(400, text="unrecognized: chat_template_kwargs")
        return httpx.Response(200, json={"choices": [{"message": {"role": "assistant", "content": "hi"}}]})

    reply = await ask(httpx.MockTransport(handler))

    assert reply["content"] == "hi"
    assert len(bodies) == 2


async def test_an_unreachable_provider_gives_none():
    def handler(request):
        raise httpx.ConnectError("down")

    assert await ask(httpx.MockTransport(handler)) is None
