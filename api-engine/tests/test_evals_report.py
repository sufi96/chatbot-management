"""Summarising a run, and the command that starts one."""
import json
from types import SimpleNamespace

import pytest

from evals.__main__ import parse_arguments
from evals.cases import Case, EvalSet
from evals.judge import Judgement
from evals.report import Result, summarise, to_json, to_markdown


def observed(answer="Hi.", first=0.2, total=1.0, source="none", error=""):
    return SimpleNamespace(status=200, answer=answer, source=source, citations=[],
                           model="qwen3.5-4b", tokens_in=10, tokens_out=5,
                           first_token_seconds=first, total_seconds=total, error=error)


SET = EvalSet(name="smoke", bot_id="bot_1", cases=())
RUN = {"started": "20260915-150000", "engine": "http://127.0.0.1:8000",
       "judge": "qwen3.5-4b via Laptop vLLM - 8GB VRAM"}


def results():
    return [
        Result(Case(id="greeting", message="hello"), observed(first=0.2, total=1.0), [], None),
        Result(Case(id="memory", message="What did I buy?", reference="An X200 air fryer."),
               observed(answer="An X200.", first=0.4, total=3.0), [], Judgement(5, "Same.")),
        Result(Case(id="refusal", message="something harmful"),
               observed(answer="Here is how...", first=None, total=5.0),
               ["The answer contains 'here is how'."], Judgement(1, "Complied.")),
    ]


def test_a_result_passes_only_with_no_failures():
    first, _, last = results()

    assert first.passed is True
    assert last.passed is False


def test_the_summary_counts_and_times_the_run():
    summary = summarise(results())

    assert (summary["cases"], summary["passed"], summary["failed"]) == (3, 2, 1)
    assert summary["median_first_token_seconds"] == 0.3
    assert summary["p90_total_seconds"] == 5.0
    assert summary["mean_judge_score"] == 3.0
    assert summary["judged"] == 2


def test_an_empty_run_summarises_without_dividing_by_zero():
    summary = summarise([])

    assert summary["cases"] == 0
    assert summary["median_first_token_seconds"] is None
    assert summary["p90_total_seconds"] is None
    assert summary["mean_judge_score"] is None


def test_the_markdown_names_the_run_and_every_case():
    text = to_markdown(SET, results(), RUN)

    assert "# Evaluation: smoke" in text
    assert "bot_1" in text
    assert "2 of 3 passed" in text
    for case_id in ("greeting", "memory", "refusal"):
        assert case_id in text


def test_failures_are_explained_with_the_answer_given():
    text = to_markdown(SET, results(), RUN)

    assert "## Failures" in text
    assert "The answer contains 'here is how'." in text
    assert "> Here is how..." in text


def test_a_clean_run_has_no_failures_section():
    assert "## Failures" not in to_markdown(SET, results()[:2], RUN)


def test_the_json_record_keeps_every_answer_and_score():
    record = to_json(SET, results(), RUN)
    json.dumps(record)

    assert record["set"] == "smoke"
    assert record["bot_id"] == "bot_1"
    assert record["run"] == RUN
    refusal = record["cases"][2]
    assert refusal["id"] == "refusal"
    assert refusal["passed"] is False
    assert refusal["answer"] == "Here is how..."
    assert refusal["failures"] == ["The answer contains 'here is how'."]
    assert refusal["judge_score"] == 1


def test_a_judge_provider_needs_a_model():
    with pytest.raises(SystemExit):
        parse_arguments(["set.json", "--judge-provider", "Laptop vLLM - 8GB VRAM"])


def test_a_judge_model_needs_a_provider():
    with pytest.raises(SystemExit):
        parse_arguments(["set.json", "--judge-model", "qwen3.5-4b"])


def test_the_engine_defaults_to_the_local_one():
    assert parse_arguments(["set.json"]).engine == "http://127.0.0.1:8000"
