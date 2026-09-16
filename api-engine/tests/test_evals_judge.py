"""Scoring an answer against its reference with a judge model."""
import pytest

from evals import judge
from evals.cases import Case


def replying(text, calls=None):
    async def complete(**kwargs):
        if calls is not None:
            calls.append(kwargs)
        return text
    return complete


@pytest.mark.parametrize("raw, score", [
    ('{"score": 4, "reason": "Close."}', 4),
    ('```json\n{"score": 5, "reason": ""}\n```', 5),
    ('{"score": 4.0}', 4),
])
def test_a_usable_score_is_read(raw, score):
    assert judge.parse(raw).score == score


@pytest.mark.parametrize("raw", [
    '{"score": 0}', '{"score": 6}', '{"score": "4"}', '{"score": true}',
    '{"score": 3.5}', "Four out of five.",
])
def test_an_unusable_score_is_refused(raw):
    assert judge.parse(raw) is None


def test_the_reason_is_kept():
    assert judge.parse('{"score": 2, "reason": "Wrong period."}').reason == "Wrong period."


def test_the_grading_input_holds_the_question_the_reference_and_the_answer():
    text = judge.grading_input(Case(id="c", message="Warranty?", reference="Two years."), "One year.")

    assert "Warranty?" in text
    assert "Two years." in text
    assert "One year." in text


@pytest.mark.asyncio
async def test_a_case_without_a_reference_is_not_judged():
    calls = []

    judgement = await judge.judge(Case(id="c", message="hi"), "Hello!", "http://x/v1", "", "m",
                                  complete=replying("{}", calls))

    assert calls == []
    assert judgement.score is None


@pytest.mark.asyncio
async def test_the_judge_is_asked_through_the_named_endpoint_in_json_mode():
    calls = []

    judgement = await judge.judge(
        Case(id="c", message="Warranty?", reference="Two years."), "Two years.",
        "http://laptop:8000/v1", "k", "qwen3.5-4b",
        complete=replying('{"score": 5, "reason": "Same."}', calls))

    assert calls[0]["base_url"] == "http://laptop:8000/v1"
    assert calls[0]["api_key"] == "k"
    assert calls[0]["model_name"] == "qwen3.5-4b"
    assert calls[0]["system_prompt"] == judge.PROMPT
    assert calls[0]["response_format"] == {"type": "json_object"}
    assert judgement.score == 5


@pytest.mark.asyncio
async def test_a_judge_that_gives_nothing_usable_is_recorded_as_such():
    judgement = await judge.judge(Case(id="c", message="q", reference="r"), "a",
                                  "http://x/v1", "", "m", complete=replying(""))

    assert judgement.score is None
    assert "no usable score" in judgement.reason
