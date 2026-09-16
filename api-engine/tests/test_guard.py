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

    assert verdict == guard.Verdict(safe=True, category="", ran=True, labelled=True)


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


# Rules: which harms, which topics, and what borderline means

def rules(**overrides):
    return {**SETTING_DEFAULTS, **overrides}


def test_every_category_is_guarded_by_default():
    assert set(SETTING_DEFAULTS["guard_categories"].split(",")) == set(guard.CATEGORIES)
    assert SETTING_DEFAULTS["guard_borderline"] == "allow"
    assert SETTING_DEFAULTS["guard_topics"] == ""


@pytest.mark.asyncio
async def test_the_prompt_names_only_the_categories_switched_on():
    calls = []
    await guard.check(FakeBot(), "hello", rules(guard_categories="violence,personal_data"),
                      complete=replying('{"safe": true, "category": ""}', calls))

    prompt = calls[0]["system_prompt"]
    assert "violence" in prompt and "personal_data" in prompt
    assert "sexual" not in prompt


@pytest.mark.asyncio
async def test_platform_and_bot_topics_are_both_in_the_prompt():
    bot = FakeBot()
    bot.guard_topics = "medical diagnosis"
    calls = []

    await guard.check(bot, "hello", rules(guard_topics="competitor pricing\n\n"),
                      complete=replying('{"safe": true, "category": ""}', calls))

    assert "- competitor pricing" in calls[0]["system_prompt"]
    assert "- medical diagnosis" in calls[0]["system_prompt"]


@pytest.mark.asyncio
async def test_nothing_to_guard_makes_no_call():
    calls = []
    verdict = await guard.check(FakeBot(), "hello", rules(guard_categories=""),
                                complete=replying("x", calls))

    assert calls == []
    assert verdict == guard.Verdict()


@pytest.mark.asyncio
async def test_a_dedicated_guard_flagging_a_category_switched_off_lets_it_through():
    verdict = await guard.check(
        FakeBot(), "who should I vote for", rules(guard_categories="violence"),
        complete=replying("Safety: Unsafe\nCategories: Politically Sensitive Topics"))

    assert verdict.safe is True
    assert verdict.ran is True


@pytest.mark.asyncio
async def test_a_dedicated_guard_flagging_a_category_switched_on_blocks():
    verdict = await guard.check(
        FakeBot(), "x", rules(guard_categories="violence,personal_data"),
        complete=replying("Safety: Unsafe\nCategories: PII"))

    assert verdict.safe is False
    assert verdict.category == "PII"


@pytest.mark.asyncio
async def test_an_unknown_category_still_blocks():
    """A harm the guard names that we do not recognise is not ours to wave through."""
    verdict = await guard.check(
        FakeBot(), "x", rules(guard_categories="violence"),
        complete=replying('{"safe": false, "category": "something new"}'))

    assert verdict.safe is False


@pytest.mark.asyncio
async def test_a_stand_in_naming_a_category_switched_off_lets_it_through():
    verdict = await guard.check(
        FakeBot(), "x", rules(guard_categories="violence,topic"),
        complete=replying('{"safe": false, "category": "copyright"}'))

    assert verdict.safe is True


@pytest.mark.asyncio
async def test_borderline_is_let_through_by_default():
    verdict = await guard.check(
        FakeBot(), "x", SETTING_DEFAULTS,
        complete=replying("Safety: Controversial\nCategories: Politically Sensitive Topics"))

    assert verdict.safe is True
    assert verdict.category == "Politically Sensitive Topics"


@pytest.mark.asyncio
async def test_borderline_can_be_blocked():
    verdict = await guard.check(
        FakeBot(), "x", rules(guard_borderline="block"),
        complete=replying("Safety: Controversial\nCategories: Politically Sensitive Topics"))

    assert verdict.safe is False


@pytest.mark.asyncio
async def test_blocking_borderline_asks_a_stand_in_to_lean_cautious():
    calls = []
    await guard.check(FakeBot(), "x", rules(guard_borderline="block"),
                      complete=replying('{"safe": true, "category": ""}', calls))

    assert "unsure" in calls[0]["system_prompt"]


@pytest.mark.asyncio
async def test_topics_behind_a_dedicated_guard_are_checked_by_the_bots_main_model():
    """Qwen3Guard knows only its own categories, so it cannot judge a topic."""
    settings = rules(guard_topics="competitor pricing",
                     guard_model_base_url="http://spark-b:8004/v1",
                     guard_model_name="qwen3guard-gen-4b")
    replies = iter(["Safety: Safe\nCategories: None",
                    '{"safe": false, "category": "topic"}'])
    calls = []

    async def complete(**kwargs):
        calls.append(kwargs)
        return next(replies)

    verdict = await guard.check(FakeBot(), "how cheap is BrandX", settings, complete=complete)

    assert len(calls) == 2
    assert calls[1]["base_url"] == "http://localhost:11434/v1"
    assert calls[1]["model_name"] == "qwen3.5:4b"
    assert "competitor pricing" in calls[1]["system_prompt"]
    assert verdict.safe is False
    assert verdict.category == "topic"


@pytest.mark.asyncio
async def test_a_stand_in_already_judged_topics_so_no_second_call():
    calls = []
    await guard.check(FakeBot(), "x", rules(guard_topics="competitor pricing"),
                      complete=replying('{"safe": true, "category": ""}', calls))

    assert len(calls) == 1
