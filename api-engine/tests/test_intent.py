"""Reading a message before any source is consulted.

Every way this can go wrong ends as "facts, searched with the visitor's own
words", which is what the chat route did before it existed.
"""
import pytest

import intent
from database import SETTING_DEFAULTS


class FakeProvider:
    base_url = "http://localhost:11434/v1"
    api_key = ""


class FakeBot:
    def __init__(self, intent_enabled=True):
        self.provider = FakeProvider()
        self.model_name = "qwen3.5:4b"
        self.intent_enabled = intent_enabled


ALL_ON = {"documents": True, "database": True, "web": True}
ALL_OFF = {"documents": False, "database": False, "web": False}

HISTORY = [
    {"role": "user", "content": "How much is the X200 air fryer?"},
    {"role": "assistant", "content": "The X200 is RM 399."},
]

REWRITE = "What is the warranty on the X200 air fryer?"


def replying(text, calls=None):
    async def complete(**kwargs):
        if calls is not None:
            calls.append(kwargs)
        return text
    return complete


def understood(verdict, query, model="qwen3.5:4b", ran=True):
    async def fake(bot, message, history, settings):
        return intent.Understanding(verdict, query, ran=ran, model=model)
    return fake


def must_not_understand():
    async def fake(*args, **kwargs):
        raise AssertionError("the intent model must not be asked")
    return fake


# Parsing the reply

def test_a_follow_up_is_rewritten_into_a_standalone_question():
    understanding = intent.parse(f'{{"intent": "facts", "query": "{REWRITE}"}}',
                                 "and the warranty?")

    assert understanding.intent == "facts"
    assert understanding.query == REWRITE
    assert understanding.ran is True


def test_a_reply_wrapped_in_a_fence_still_parses():
    understanding = intent.parse('```json\n{"intent": "chat", "query": "you are great"}\n```',
                                 "you are great")

    assert understanding.intent == "chat"


def test_prose_with_no_object_is_unusable():
    assert intent.parse("The visitor wants the warranty.", "and the warranty?") is None


def test_broken_json_is_unusable():
    assert intent.parse('{"intent": "facts", "query": ', "and the warranty?") is None


def test_an_unknown_verdict_reads_as_facts():
    assert intent.parse('{"intent": "image", "query": "draw a cat"}', "draw a cat").intent == "facts"


def test_a_blank_query_falls_back_to_the_message():
    assert intent.parse('{"intent": "facts", "query": "  "}', "opening hours").query == "opening hours"


def test_a_question_mark_is_never_downgraded_to_chat():
    """A wasted search costs less than an invented answer."""
    assert intent.parse('{"intent": "chat", "query": "you there?"}', "you there?").intent == "facts"


def test_an_overlong_query_is_cut():
    understanding = intent.parse('{"intent": "facts", "query": "' + "a" * 900 + '"}', "x")

    assert len(understanding.query) == intent.QUERY_CHARS


# What the model reads

def test_the_input_carries_the_recent_conversation_then_the_message():
    text = intent.build_input("and the warranty?", HISTORY)

    assert "visitor: How much is the X200 air fryer?" in text
    assert "assistant: The X200 is RM 399." in text
    assert text.rstrip().endswith("and the warranty?")


def test_the_message_is_not_repeated_as_its_own_history():
    """The widget sends the current message as the last history turn too."""
    history = HISTORY + [{"role": "user", "content": "and the warranty?"}]

    assert intent.build_input("and the warranty?", history).count("and the warranty?") == 1


def test_only_the_last_turns_are_read():
    history = [{"role": "user", "content": f"turn {n}"} for n in range(20)]

    text = intent.build_input("next one", history)

    assert "turn 19" in text
    assert "turn 13" not in text


def test_a_long_turn_is_cut():
    history = [{"role": "assistant", "content": "b" * 1000}]

    assert "b" * (intent.TURN_CHARS + 1) not in intent.build_input("next one", history)


def test_no_history_says_so():
    assert "(none)" in intent.build_input("opening hours", [])


# Asking the model

@pytest.mark.asyncio
async def test_the_intent_role_is_asked_for_json_at_temperature_zero():
    calls = []
    await intent.understand(FakeBot(), "and the warranty?", HISTORY, SETTING_DEFAULTS,
                            complete=replying('{"intent": "facts", "query": "q"}', calls))

    assert calls[0]["response_format"] == {"type": "json_object"}
    assert calls[0]["model_name"] == "qwen3.5:4b"
    assert calls[0]["system_prompt"] == intent.PROMPT


@pytest.mark.asyncio
async def test_a_configured_intent_model_is_used_and_named():
    settings = {**SETTING_DEFAULTS,
                "intent_model_base_url": "http://spark-b:8001/v1",
                "intent_model_name": "qwen3.5-35b-a3b"}
    calls = []

    understanding = await intent.understand(
        FakeBot(), "and the warranty?", HISTORY, settings,
        complete=replying('{"intent": "facts", "query": "q"}', calls))

    assert calls[0]["base_url"] == "http://spark-b:8001/v1"
    assert understanding.model == "qwen3.5-35b-a3b"


@pytest.mark.asyncio
async def test_a_failed_call_falls_back_to_the_message_as_sent():
    understanding = await intent.understand(FakeBot(), "and the warranty?", HISTORY,
                                            SETTING_DEFAULTS, complete=replying(""))

    assert understanding == intent.Understanding(intent="facts", query="and the warranty?")


@pytest.mark.asyncio
async def test_a_first_message_is_searched_in_the_visitors_own_words():
    """With no conversation before it there is nothing to resolve, so a rewrite
    can only lose something. A 4B model turned "Boleh saya bayar secara
    ansuran?" into "Can I pay in installments?": similarity to the Malay answer
    fell from 0.733 to 0.486 and the reranker's score from 0.889 to 0.031, and
    a question the knowledge base answered went unanswered."""
    understanding = await intent.understand(
        FakeBot(), "Boleh saya bayar secara ansuran?", [], SETTING_DEFAULTS,
        complete=replying('{"intent": "facts", "query": "Can I pay in installments?"}'))

    assert understanding.intent == "facts"
    assert understanding.query == "Boleh saya bayar secara ansuran?"


@pytest.mark.asyncio
async def test_a_first_message_the_widget_repeats_as_history_is_still_a_first_message():
    understanding = await intent.understand(
        FakeBot(), "Boleh saya bayar secara ansuran?",
        [{"role": "user", "content": "Boleh saya bayar secara ansuran?"}], SETTING_DEFAULTS,
        complete=replying('{"intent": "facts", "query": "Can I pay in installments?"}'))

    assert understanding.query == "Boleh saya bayar secara ansuran?"


@pytest.mark.asyncio
async def test_a_follow_up_is_still_searched_as_its_rewrite():
    understanding = await intent.understand(
        FakeBot(), "and the warranty?", HISTORY, SETTING_DEFAULTS,
        complete=replying(f'{{"intent": "facts", "query": "{REWRITE}"}}'))

    assert understanding.query == REWRITE


@pytest.mark.asyncio
async def test_a_bot_with_no_endpoint_is_not_asked():
    bot = FakeBot()
    bot.provider = None
    calls = []

    understanding = await intent.understand(bot, "and the warranty?", HISTORY,
                                            SETTING_DEFAULTS, complete=replying("{}", calls))

    assert calls == []
    assert understanding.ran is False


# Deciding

@pytest.mark.asyncio
async def test_a_greeting_never_reaches_the_model():
    decision = await intent.decide(FakeBot(), "hello", [], SETTING_DEFAULTS, ALL_ON,
                                   understand_fn=must_not_understand())

    assert decision.is_question is False
    assert decision.intent is None


@pytest.mark.asyncio
async def test_a_bot_with_the_switch_off_behaves_as_before():
    decision = await intent.decide(FakeBot(intent_enabled=False), "and the warranty?",
                                   HISTORY, SETTING_DEFAULTS, ALL_ON,
                                   understand_fn=must_not_understand())

    assert decision == intent.Decision(is_question=True, query="and the warranty?")


@pytest.mark.asyncio
async def test_a_bot_with_no_source_switched_on_is_not_read():
    decision = await intent.decide(FakeBot(), "and the warranty?", HISTORY,
                                   SETTING_DEFAULTS, ALL_OFF,
                                   understand_fn=must_not_understand())

    assert decision.intent is None


@pytest.mark.asyncio
async def test_a_follow_up_is_searched_as_the_rewritten_question():
    decision = await intent.decide(FakeBot(), "and the warranty?", HISTORY,
                                   SETTING_DEFAULTS, ALL_ON,
                                   understand_fn=understood("facts", REWRITE))

    assert decision.is_question is True
    assert decision.query == REWRITE
    assert decision.intent == "facts"
    assert decision.model == "qwen3.5:4b"


@pytest.mark.asyncio
async def test_small_talk_the_word_rules_let_through_skips_the_sources():
    decision = await intent.decide(FakeBot(), "you guys are awesome", [],
                                   SETTING_DEFAULTS, ALL_ON,
                                   understand_fn=understood("chat", "you guys are awesome"))

    assert decision.is_question is False
    assert decision.intent == "chat"


@pytest.mark.asyncio
async def test_an_unusable_reading_searches_with_the_message_as_sent():
    decision = await intent.decide(
        FakeBot(), "and the warranty?", HISTORY, SETTING_DEFAULTS, ALL_ON,
        understand_fn=understood("facts", "and the warranty?", model="", ran=False))

    assert decision == intent.Decision(is_question=True, query="and the warranty?")
