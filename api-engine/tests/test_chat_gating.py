"""The rules the chat route applies around the gate.

The route itself needs a running model, so the two decisions it makes from the
gate are asserted here directly: whether retrieval runs, and which fallback
instruction the prompt carries.
"""
from kb.gating import should_retrieve
from kb.retrieval import NO_CONTEXT_INSTRUCTION, augment_system_prompt


def resolve(retrieval_enabled: bool, message: str, configured_fallback: str):
    """The exact logic routers/chat.py applies. Kept in step with it by test."""
    retrieval_ran = bool(retrieval_enabled) and should_retrieve(message)
    fallback = configured_fallback if retrieval_ran else "answer_anyway"
    return retrieval_ran, fallback


def test_a_greeting_does_not_run_retrieval():
    ran, _ = resolve(True, "hello", "say_unknown")
    assert ran is False


def test_a_question_runs_retrieval():
    ran, _ = resolve(True, "what is the warranty", "say_unknown")
    assert ran is True


def test_a_bot_with_retrieval_off_never_runs_it():
    ran, _ = resolve(False, "what is the warranty", "say_unknown")
    assert ran is False


def test_a_gated_message_is_not_told_the_answer_is_missing():
    _, fallback = resolve(True, "hi", "say_unknown")
    prompt = augment_system_prompt("You are helpful.", "", fallback)
    assert NO_CONTEXT_INSTRUCTION.strip() not in prompt
    assert prompt == "You are helpful."


def test_a_real_question_that_finds_nothing_is_still_told():
    _, fallback = resolve(True, "what is the warranty", "say_unknown")
    prompt = augment_system_prompt("You are helpful.", "", fallback)
    assert "not in the available material" in prompt.lower()
