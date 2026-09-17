"""Providers that silently drop system messages.

Some gateways discard the system role, so a bot's prompt, its retrieved
context and the SQL writer's schema never reach the model. A provider marked
that way gets its instructions inside the user message instead.
"""
import json

import httpx
import pytest

import roles
from database import SETTING_DEFAULTS
from llm_adapter import LLMAdapter, with_instructions


class Provider:
    def __init__(self, merge):
        self.base_url = "http://gateway/v1"
        self.api_key = "k"
        self.merge_system_prompt = merge


class Bot:
    def __init__(self, merge):
        self.provider = Provider(merge)
        self.model_name = "deepseek"


def capture(seen, payload):
    def handler(request: httpx.Request) -> httpx.Response:
        seen["body"] = json.loads(request.read().decode())
        return payload(request)
    return handler


def test_instructions_ride_above_the_message_only_when_asked():
    assert with_instructions("Write SQL.", "how many?", False) == "how many?"
    assert with_instructions("", "how many?", True) == "how many?"
    assert with_instructions("Write SQL.", "how many?", True) == "Write SQL.\n\n---\n\nhow many?"


@pytest.mark.asyncio
async def test_a_completion_sends_no_system_message_when_merging():
    seen = {}
    ok = lambda request: httpx.Response(200, json={"choices": [{"message": {"content": "SELECT 1"}}]})

    await LLMAdapter.complete("http://gateway/v1", "", "m", "Write SQL.", "how many?",
                              merge_system=True, transport=httpx.MockTransport(capture(seen, ok)))

    assert seen["body"]["messages"] == [{"role": "user", "content": "Write SQL.\n\n---\n\nhow many?"}]


@pytest.mark.asyncio
async def test_a_completion_keeps_the_system_message_by_default():
    seen = {}
    ok = lambda request: httpx.Response(200, json={"choices": [{"message": {"content": "SELECT 1"}}]})

    await LLMAdapter.complete("http://gateway/v1", "", "m", "Write SQL.", "how many?",
                              transport=httpx.MockTransport(capture(seen, ok)))

    assert seen["body"]["messages"][0] == {"role": "system", "content": "Write SQL."}


@pytest.mark.asyncio
async def test_a_chat_puts_the_prompt_on_the_latest_turn_and_keeps_the_history():
    seen = {}
    sse = lambda request: httpx.Response(200, content=b"data: [DONE]\n\n",
                                         headers={"content-type": "text/event-stream"})

    async for _ in LLMAdapter.stream_chat(
            base_url="http://gateway/v1", api_key="", model_name="m", system_prompt="Context: 3 bots.",
            temperature=0.7, max_tokens=256, user_message="how many bots?",
            history=[{"role": "user", "content": "hi"}, {"role": "assistant", "content": "hello"}],
            merge_system=True, transport=httpx.MockTransport(capture(seen, sse))):
        pass

    assert seen["body"]["messages"] == [
        {"role": "user", "content": "hi"},
        {"role": "assistant", "content": "hello"},
        {"role": "user", "content": "Context: 3 bots.\n\n---\n\nhow many bots?"},
    ]


def test_a_borrowed_endpoint_takes_the_flag_from_the_bots_provider():
    assert roles.endpoint_for("sql", Bot(True), SETTING_DEFAULTS).merge_system is True
    assert roles.endpoint_for("sql", Bot(False), SETTING_DEFAULTS).merge_system is False


def test_a_configured_endpoint_takes_the_flag_from_its_linked_provider():
    settings = {**SETTING_DEFAULTS, "intent_model_base_url": "http://spark/v1",
                "intent_model_name": "qwen", "intent_model_merge_system": True}

    assert roles.endpoint_for("intent", Bot(False), settings).merge_system is True
