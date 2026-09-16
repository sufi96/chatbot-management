"""Which model does which job, and what a job left blank falls back to.

The rule under test: blank reproduces what the system did before the role
existed. A generative job borrows the bot's own model; the reranker, which no
chat model can stand in for, becomes unavailable so its stage is skipped.
"""
import json

import pytest

import roles
from database import SETTING_DEFAULTS


class FakeProvider:
    def __init__(self, base_url="http://localhost:11434/v1", api_key=""):
        self.base_url = base_url
        self.api_key = api_key


class FakeBot:
    def __init__(self, provider="default", model_name="qwen3.5:4b"):
        self.provider = FakeProvider() if provider == "default" else provider
        self.model_name = model_name


def test_every_role_defaults_to_blank():
    for role in roles.ROLES:
        for key in roles.setting_keys(role):
            assert SETTING_DEFAULTS[key] == "", key


def test_the_setting_keys_follow_one_pattern():
    assert roles.setting_keys("intent") == (
        "intent_model_base_url", "intent_model_api_key", "intent_model_name")


def test_a_blank_generative_role_borrows_the_bots_model():
    for role in roles.GENERATIVE:
        endpoint = roles.endpoint_for(role, FakeBot(), SETTING_DEFAULTS)

        assert endpoint.base_url == "http://localhost:11434/v1", role
        assert endpoint.model == "qwen3.5:4b", role
        assert endpoint.configured is False, role
        assert endpoint.available is True, role


def test_a_configured_role_wins_over_the_bot():
    settings = {**SETTING_DEFAULTS,
                "intent_model_base_url": "http://spark-b:8001/v1",
                "intent_model_api_key": "k",
                "intent_model_name": "qwen3.5-35b-a3b"}

    endpoint = roles.endpoint_for("intent", FakeBot(), settings)

    assert endpoint == roles.Endpoint("intent", "http://spark-b:8001/v1", "k",
                                      "qwen3.5-35b-a3b", configured=True)


def test_a_url_without_a_model_does_not_count_as_configured():
    settings = {**SETTING_DEFAULTS, "guard_model_base_url": "http://spark-b:8003/v1"}

    endpoint = roles.endpoint_for("guard", FakeBot(), settings)

    assert endpoint.configured is False
    assert endpoint.base_url == "http://localhost:11434/v1"


def test_surrounding_whitespace_is_not_an_endpoint():
    settings = {**SETTING_DEFAULTS, "sql_model_base_url": "  ", "sql_model_name": " "}

    assert roles.endpoint_for("sql", FakeBot(), settings).configured is False


def test_a_blank_reranker_is_unavailable_rather_than_borrowed():
    """A chat model does not speak the rerank protocol. Borrowing one would
    fail on every question; skipping the stage is what happened before it
    existed."""
    endpoint = roles.endpoint_for("rerank", FakeBot(), SETTING_DEFAULTS)

    assert endpoint.available is False
    assert endpoint.model == ""


def test_a_configured_reranker_is_available():
    settings = {**SETTING_DEFAULTS,
                "rerank_model_base_url": "http://localhost:8012/v1",
                "rerank_model_name": "bge-reranker-v2-m3"}

    assert roles.endpoint_for("rerank", FakeBot(), settings).available is True


def test_a_bot_with_no_provider_yields_an_unavailable_endpoint():
    endpoint = roles.endpoint_for("sql", FakeBot(provider=None), SETTING_DEFAULTS)

    assert endpoint.available is False
    assert endpoint.model == "qwen3.5:4b"


def test_missing_settings_keys_read_as_blank():
    assert roles.endpoint_for("intent", FakeBot(), {}).model == "qwen3.5:4b"


def test_an_unknown_role_is_refused():
    with pytest.raises(ValueError):
        roles.endpoint_for("imagine", FakeBot(), SETTING_DEFAULTS)


def test_a_blank_vision_role_is_unavailable_rather_than_borrowed():
    """Ingestion belongs to no bot, and a chat model may not read images."""
    assert roles.endpoint_for("vision", None, SETTING_DEFAULTS).available is False


def test_a_configured_vision_role_is_available():
    settings = {**SETTING_DEFAULTS,
                "vision_model_base_url": "http://spark-b:8005/v1",
                "vision_model_name": "qwen3-vl-8b"}

    assert roles.endpoint_for("vision", None, settings).available is True


def test_the_trace_names_the_chat_model_and_each_job_that_ran():
    trace = roles.model_trace("qwen3.5:4b", {"sql": "qwen3-coder:30b"})

    assert json.loads(trace) == {"chat": "qwen3.5:4b", "sql": "qwen3-coder:30b"}


def test_a_job_with_no_model_is_left_out_of_the_trace():
    trace = roles.model_trace("qwen3.5:4b", {"sql": "", "intent": ""})

    assert json.loads(trace) == {"chat": "qwen3.5:4b"}


def test_the_trace_is_stable_text():
    """Sorted keys, so two identical answers store identical text."""
    assert (roles.model_trace("a", {"sql": "b", "intent": "c"})
            == '{"chat": "a", "intent": "c", "sql": "b"}')


def test_nothing_known_is_no_trace_rather_than_an_empty_object():
    assert roles.model_trace(None, {}) is None
    assert roles.model_trace("", {"sql": ""}) is None
