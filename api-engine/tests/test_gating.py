import pytest

from kb.gating import should_retrieve


@pytest.mark.parametrize("message", [
    "hi",
    "Hello",
    "hey there",
    "good morning",
    "thanks",
    "thank you",
    "thanks so much",
    "ok",
    "okay, got it",
    "bye",
    "goodbye",
    "cool",
    "yes",
    "no",
])
def test_smalltalk_does_not_search(message):
    assert should_retrieve(message) is False


@pytest.mark.parametrize("message", [
    "what is the refund window",
    "warranty",
    "how long do pendant fittings last",
    "refund",
    "tell me about shipping to Ireland",
])
def test_a_real_message_searches(message):
    assert should_retrieve(message) is True


def test_a_greeting_with_a_question_attached_still_searches():
    assert should_retrieve("hi, what is the warranty?") is True
    assert should_retrieve("thanks! and shipping?") is True


def test_a_question_mark_always_wins():
    # Every word here is smalltalk, but it is punctuated as a question.
    assert should_retrieve("ok?") is True


def test_blank_input_does_not_search():
    assert should_retrieve("") is False
    assert should_retrieve("   \n ") is False
    assert should_retrieve(None) is False


def test_a_message_of_only_short_tokens_does_not_search():
    # "hm" and "eh" are not in the vocabulary, but neither can be a question.
    assert should_retrieve("hm eh") is False


def test_punctuation_and_case_do_not_matter():
    assert should_retrieve("HELLO!!!") is False
    assert should_retrieve("Warranty.") is True


def test_emoji_only_does_not_search():
    assert should_retrieve("👋") is False
