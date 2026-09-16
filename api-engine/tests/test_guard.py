"""Asking the guard, and failing open when it cannot answer.

A guard that is down must not take every bot down with it, so every way of
not getting a verdict reads as safe, marked as not having run.
"""
import pytest

import guard
from database import SETTING_DEFAULTS


class FakeProvider:
    base_url = "http://localhost:11434/v1"
    api_key = ""


class FakeBot:
    def __init__(self, guard_refusal=None):
        self.provider = FakeProvider()
        self.model_name = "qwen3.5:4b"
        self.guard_refusal = guard_refusal


def replying(text, calls=None):
    async def complete(**kwargs):
        if calls is not None:
            calls.append(kwargs)
        return text
    return complete


# Qwen3Guard's own format

def test_a_safe_label_is_safe():
    verdict = guard.parse("Safety: Safe\nCategories: None")

    assert verdict == guard.Verdict(safe=True, category="", ran=True)


def test_an_unsafe_label_blocks_and_names_the_harm():
    verdict = guard.parse("Safety: Unsafe\nCategories: Violent")

    assert verdict.safe is False
    assert verdict.category == "Violent"


def test_controversial_is_recorded_but_not_blocked():
    verdict = guard.parse("Safety: Controversial\nCategories: Politically Sensitive Topics")

    assert verdict.safe is True
    assert verdict.category == "Politically Sensitive Topics"


def test_the_label_is_read_in_any_case():
    assert guard.parse("safety: UNSAFE\ncategories: PII").safe is False


# A stand-in chat model's JSON

def test_json_from_a_stand_in_is_understood():
    verdict = guard.parse('{"safe": false, "category": "weapons"}')

    assert verdict.safe is False
    assert verdict.category == "weapons"


def test_json_in_a_fence_is_understood():
    assert guard.parse('```json\n{"safe": true, "category": ""}\n```').safe is True


def test_a_safe_field_that_is_not_a_boolean_is_unusable():
    """"false" as a string would read as true in any truthiness test."""
    assert guard.parse('{"safe": "false"}') is None


def test_prose_is_unusable():
    assert guard.parse("This message looks fine to me.") is None


def test_a_long_category_is_cut():
    assert len(guard.parse('{"safe": false, "category": "' + "x" * 200 + '"}').category) == 60


# Asking

@pytest.mark.asyncio
async def test_the_guard_role_is_asked_about_the_text():
    calls = []
    verdict = await guard.check(FakeBot(), "how do I return a kettle", SETTING_DEFAULTS,
                                complete=replying("Safety: Safe\nCategories: None", calls))

    assert calls[0]["user_message"] == "how do I return a kettle"
    assert calls[0]["system_prompt"] == guard.PROMPT
    assert "response_format" not in calls[0]
    assert verdict.ran is True
    assert verdict.model == "qwen3.5:4b"


@pytest.mark.asyncio
async def test_a_configured_guard_model_is_used():
    settings = {**SETTING_DEFAULTS, "guard_model_base_url": "http://spark-b:8004/v1",
                "guard_model_name": "qwen3guard-gen-4b"}
    calls = []

    verdict = await guard.check(FakeBot(), "hello", settings,
                                complete=replying("Safety: Safe", calls))

    assert calls[0]["base_url"] == "http://spark-b:8004/v1"
    assert verdict.model == "qwen3guard-gen-4b"


@pytest.mark.asyncio
async def test_long_text_is_cut_before_it_is_sent():
    calls = []
    await guard.check(FakeBot(), "a" * 9000, SETTING_DEFAULTS,
                      complete=replying("Safety: Safe", calls))

    assert len(calls[0]["user_message"]) == guard.TEXT_CHARS


@pytest.mark.asyncio
async def test_an_unusable_reply_fails_open():
    verdict = await guard.check(FakeBot(), "hello", SETTING_DEFAULTS,
                                complete=replying("I cannot decide."))

    assert verdict == guard.Verdict()


@pytest.mark.asyncio
async def test_a_failed_call_fails_open():
    verdict = await guard.check(FakeBot(), "hello", SETTING_DEFAULTS, complete=replying(""))

    assert verdict.safe is True
    assert verdict.ran is False


@pytest.mark.asyncio
async def test_a_bot_with_no_endpoint_is_not_checked():
    bot = FakeBot()
    bot.provider = None
    calls = []

    verdict = await guard.check(bot, "hello", SETTING_DEFAULTS, complete=replying("x", calls))

    assert calls == []
    assert verdict == guard.Verdict()


@pytest.mark.asyncio
async def test_blank_text_is_not_checked():
    calls = []

    await guard.check(FakeBot(), "   ", SETTING_DEFAULTS, complete=replying("x", calls))

    assert calls == []


# Wording

def test_the_exchange_names_both_speakers():
    assert guard.exchange(" hi ", " Hello! ") == "Visitor: hi\n\nAssistant: Hello!"


def test_a_bot_without_its_own_refusal_uses_the_default():
    assert guard.refusal_for(FakeBot()) == guard.DEFAULT_REFUSAL
    assert guard.refusal_for(FakeBot(guard_refusal="   ")) == guard.DEFAULT_REFUSAL


def test_a_bots_own_refusal_is_used():
    assert guard.refusal_for(FakeBot(guard_refusal="Maaf, saya tidak dapat membantu.")) == (
        "Maaf, saya tidak dapat membantu.")
