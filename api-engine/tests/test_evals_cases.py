"""Loading an evaluation set, and checking an answer against its case."""
import json
from types import SimpleNamespace

import pytest

from evals.cases import Case, Expect, load_set, parse_set
from evals.checks import failures

MINIMAL = {"name": "smoke", "bot_id": "bot_1", "cases": [{"id": "hello", "message": "hello"}]}


def with_case(**fields):
    case = {"id": "c1", "message": "What is the warranty?"}
    case.update(fields)
    return {"name": "smoke", "bot_id": "bot_1", "cases": [case]}


# Loading

def test_a_minimal_set_loads():
    loaded = parse_set(MINIMAL)

    assert loaded.name == "smoke"
    assert loaded.bot_id == "bot_1"
    assert loaded.cases[0] == Case(id="hello", message="hello")


def test_expectations_history_and_reference_are_read():
    loaded = parse_set(with_case(
        history=[{"role": "user", "content": "I bought an X200."},
                 {"role": "assistant", "content": "Great choice."}],
        expect={"source": "documents", "cites": ["Warranty"], "must_include_any": ["two years"],
                "must_not_include": ["RM"], "max_first_token_seconds": 5, "max_total_seconds": 30},
        reference="Two years."))
    case = loaded.cases[0]

    assert case.history[0] == {"role": "user", "content": "I bought an X200."}
    assert case.expect == Expect(source="documents", cites=("Warranty",),
                                 must_include_any=("two years",), must_not_include=("RM",),
                                 max_first_token_seconds=5.0, max_total_seconds=30.0)
    assert case.reference == "Two years."


@pytest.mark.parametrize("data, complaint", [
    ({"bot_id": "b", "cases": [{"id": "a", "message": "m"}]}, "needs a name"),
    ({"name": "n", "cases": [{"id": "a", "message": "m"}]}, "needs a bot_id"),
    ({"name": "n", "bot_id": "b", "cases": []}, "at least one case"),
    ({"name": "n", "bot_id": "b", "cases": [{"message": "m"}]}, "needs an id"),
    ({"name": "n", "bot_id": "b", "cases": [{"id": "a"}]}, "needs a message"),
    ({"name": "n", "bot_id": "b", "cases": [{"id": "a", "message": "m"},
                                            {"id": "a", "message": "m"}]}, "appears twice"),
    (with_case(expect={"source": "telepathy"}), "unknown source"),
    (with_case(expect={"cites": "Warranty"}), "list of strings"),
    (with_case(expect={"max_total_seconds": 0}), "positive number"),
    (with_case(history=[{"role": "system", "content": "x"}]), "role of user or assistant"),
])
def test_a_mistake_in_the_set_is_named(data, complaint):
    """A typo found after a twenty-minute run is a wasted run."""
    with pytest.raises(ValueError) as raised:
        parse_set(data)

    assert complaint in str(raised.value)


def test_a_file_that_is_not_json_says_so(tmp_path):
    path = tmp_path / "broken.json"
    path.write_text("{ nope", encoding="utf-8")

    with pytest.raises(ValueError) as raised:
        load_set(path)

    assert "not valid JSON" in str(raised.value)


def test_a_set_loads_from_a_file(tmp_path):
    path = tmp_path / "set.json"
    path.write_text(json.dumps(MINIMAL), encoding="utf-8")

    assert load_set(path).name == "smoke"


def test_the_shipped_sets_are_valid():
    from pathlib import Path

    shipped = list((Path(__file__).parent.parent / "evals" / "sets").glob("*.json"))

    assert shipped
    for path in shipped:
        assert load_set(path).cases, path.name


# Checking

def observed(**fields):
    base = dict(answer="The warranty is two years.", source="documents",
                citations=[{"n": 1, "title": "Warranty Policy"}],
                first_token_seconds=0.4, total_seconds=2.0, error="")
    base.update(fields)
    return SimpleNamespace(**base)


def case(**expect):
    return Case(id="c1", message="What is the warranty?", expect=Expect(**expect))


def test_an_answer_meeting_every_expectation_passes():
    assert failures(case(source="documents", cites=("warranty",),
                         must_include_any=("two years", "2 years"), must_not_include=("RM",),
                         max_first_token_seconds=1, max_total_seconds=5), observed()) == []


def test_a_case_with_no_expectations_passes():
    assert failures(case(), observed()) == []


def test_the_wrong_source_fails():
    assert failures(case(source="web"), observed()) == ["Expected the answer from web, got documents."]


def test_no_source_is_an_expectation_of_its_own():
    assert failures(case(source="none"), observed(source="none", citations=[])) == []


def test_a_missing_citation_fails():
    assert failures(case(cites=("Shipping",)), observed()) == ["No citation named 'Shipping'."]


def test_phrases_match_whatever_their_case():
    assert failures(case(must_include_any=("TWO YEARS",)), observed()) == []


def test_an_answer_with_none_of_the_phrases_fails():
    assert failures(case(must_include_any=("three years",)), observed()) == [
        "The answer contains none of: 'three years'."]


def test_a_forbidden_phrase_fails():
    assert failures(case(must_not_include=("warranty",)), observed()) == [
        "The answer contains 'warranty'."]


def test_a_slow_first_token_fails():
    assert failures(case(max_first_token_seconds=0.2), observed()) == [
        "First token took 0.40s, over 0.2s."]


def test_no_answer_text_fails_a_first_token_limit():
    assert failures(case(max_first_token_seconds=5),
                    observed(first_token_seconds=None, answer="")) == ["No answer text arrived."]


def test_a_slow_answer_fails():
    assert failures(case(max_total_seconds=1.5), observed()) == [
        "The answer took 2.00s, over 1.5s."]


def test_an_engine_error_fails():
    assert failures(case(), observed(error="Cannot connect")) == [
        "The engine reported an error: Cannot connect"]
