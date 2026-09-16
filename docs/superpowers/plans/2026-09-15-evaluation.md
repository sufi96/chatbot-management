# Evaluation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An operator can run a fixed set of questions against a running engine and get a report that says, for each question, whether the answer met its expectations, how fast it came, and optionally how a judge model scored it against a reference. Two runs on two model setups can then be compared on the same questions.

**Architecture:** A new `api-engine/evals/` package with a CLI, `python -m evals <set.json>`.
- `cases.py` loads and validates a JSON set.
- `stream.py` asks the running engine exactly as the widget does, over HTTP server-sent events, and times the first token and the whole answer.
- `checks.py` applies deterministic expectations.
- `judge.py` optionally scores an answer against a reference, using an AI provider named as it is in the portal.
- `report.py` writes Markdown to read and JSON to compare.

Reports go to `api-engine/evals/reports/`, which git ignores.

**Tech Stack:** Python, httpx, SQLAlchemy (to resolve the judge's provider), pytest.

**Spec:** `docs/model-stack-review.md` section 8, steps 1 and 6, and the plan 5 row in `docs/superpowers/plans/2026-09-15-spark-readiness-roadmap.md`.

## Measured before planning (2026-09-15, laptop vLLM, `qwen3.5-4b` AWQ, 8 GB)

| Call | Time |
|---|---|
| First intent call after the server started | 23.7 s |
| Intent call, warm | 0.5 to 0.6 s |
| Guard check, benign and harmful | 0.23 s and 0.25 s, harmful flagged `violence` |
| Chat through the engine, `bot_demo_default` | First token 0.14 s, whole answer 2.65 s, 158 tokens out |

The first call after a vLLM start is slow, so a run's first case measures warm-up rather than the pipeline. The shipped set therefore starts with a greeting, which absorbs it.

## Global Constraints

- **What `stream.py` sends.** `stream.py` sends what the widget sends: `{bot_id, session_id, message, history}`, where `history` is the case's turns followed by the message itself as a user turn.
- **Session ids.** Session ids are `eval-<set name>-<YYYYmmdd-HHMMSS>-<case id>`, cut to 100 characters, so evaluation conversations are recognisable in the logs.
- **Deterministic checks.** Checks ignore case. Expected sources are `none`, `documents`, `database` or `web`. No `sources` event means `none`.
- **Judge.**
  - The judge is used only when both `--judge-provider` and `--judge-model` are given.
  - A case without `reference` is not judged.
  - A score must be an integer from 1 to 5; anything else is recorded as "no usable score".
- **Order and exit code.** Cases run one at a time, so latency is not distorted by the run itself. The CLI exits 0 when every case passes, 1 when any fails, and 2 when the set cannot be loaded.
- **Commands.** Engine tests run from `api-engine/` with `.venv\Scripts\python.exe -m pytest`.

## File map

| File | Change | Responsibility |
|---|---|---|
| `api-engine/evals/__init__.py` | Create | Package docstring |
| `api-engine/evals/cases.py` | Create | `Expect`, `Case`, `EvalSet`, `parse_set`, `load_set` |
| `api-engine/evals/checks.py` | Create | `failures(case, observed)` |
| `api-engine/evals/stream.py` | Create | `Observed`, `ask(...)` |
| `api-engine/evals/judge.py` | Create | `Judgement`, `parse`, `grading_input`, `judge` |
| `api-engine/evals/report.py` | Create | `Result`, `summarise`, `to_json`, `to_markdown` |
| `api-engine/evals/__main__.py` | Create | CLI |
| `api-engine/evals/sets/corporate-support-smoke.json` | Create | A set for `bot_demo_default` |
| `api-engine/tests/test_evals_cases.py` | Create | Loading and checks |
| `api-engine/tests/test_evals_stream.py` | Create | Streaming and timing |
| `api-engine/tests/test_evals_judge.py` | Create | Judging |
| `api-engine/tests/test_evals_report.py` | Create | Reports and the CLI's arguments |
| `.gitignore` | Modify | `api-engine/evals/reports/` |
| `docs/evaluation.md` | Create | How to write a set and run it |

---

### Task 1: A set, and whether an answer meets it

- [ ] Create `tests/test_evals_cases.py` (Appendix A). Run it, and expect `No module named 'evals'`.
- [ ] Create `evals/__init__.py`, `evals/cases.py` and `evals/checks.py` (Appendix B). Add `api-engine/evals/reports/` to `.gitignore`.
- [ ] Run the full engine suite. Then commit with `feat: an evaluation set is loaded, checked and its answers judged against it`.

### Task 2: Asking the running engine and timing it

- [ ] Create `tests/test_evals_stream.py` (Appendix C). Run it, and expect `No module named 'evals.stream'`.
- [ ] Create `evals/stream.py` (Appendix D).
- [ ] Run the full engine suite. Then commit with `feat: an evaluation asks the engine as the widget does and times it`.

### Task 3: A judge and a report

- [ ] Create `tests/test_evals_judge.py` and `tests/test_evals_report.py` (Appendix E). Run them, and expect missing modules.
- [ ] Create `evals/judge.py`, `evals/report.py` and `evals/__main__.py` (Appendix F).
- [ ] Run the full engine suite. Then commit with `feat: an evaluation run is judged and written up`.

### Task 4: A real set, run for real

- [ ] Create `evals/sets/corporate-support-smoke.json` and `docs/evaluation.md` (Appendix G). Append this test to `tests/test_evals_cases.py`:

```python
def test_the_shipped_sets_are_valid():
    from pathlib import Path

    for path in (Path(__file__).parent.parent / "evals" / "sets").glob("*.json"):
        assert load_set(path).cases, path.name
```

- [ ] Run the full engine suite.
- [ ] With the engine running and the laptop vLLM reachable, run:

```
.venv\Scripts\python.exe -m evals evals/sets/corporate-support-smoke.json --judge-provider "Laptop vLLM - 8GB VRAM" --judge-model qwen3.5-4b
```

  Keep the printed summary for the handover. A failing case is a finding about the bot or the model; it does not block the commit.
- [ ] Set plan 5's roadmap status to `Done`. Then commit with `feat: a smoke set for the support bot, and how to run an evaluation`.

---

## Appendix

### A. `api-engine/tests/test_evals_cases.py`

```python
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
```

### B. `evals/__init__.py`, `evals/cases.py`, `evals/checks.py`

The code is as committed in Task 1. The shapes are exactly as the tests in Appendix A require:

- **Frozen dataclasses.** `Expect(source=None, cites=(), must_include_any=(), must_not_include=(), max_first_token_seconds=None, max_total_seconds=None)`, `Case(id, message, history=(), expect=Expect(), reference="")` and `EvalSet(name, bot_id, cases)`.
- **`parse_set(data)`** raises `ValueError` with the complaints named in the parametrised test.
- **`load_set(path)`** reads UTF-8 JSON and reports invalid JSON as "`<path>` is not valid JSON: …".
- **`failures(case, observed)`** returns the exact messages asserted, in this order: error, source, citations, phrases that must appear, forbidden phrases, first token, total.

### C. `api-engine/tests/test_evals_stream.py`

```python
"""Asking the engine as the widget does, over a faked transport and a faked clock."""
import json

import httpx
import pytest

from evals.cases import Case
from evals.stream import ask


def sse(*payloads):
    return "".join(f"data: {json.dumps(p)}\n\n" for p in payloads) + "data: [DONE]\n\n"


def ticking(*times):
    """A clock that returns each time in turn, then keeps returning the last."""
    queue = list(times)

    def clock():
        return queue.pop(0) if len(queue) > 1 else queue[0]
    return clock


@pytest.mark.asyncio
async def test_the_request_is_what_the_widget_sends():
    seen = {}

    def handler(request):
        seen["url"] = str(request.url)
        seen["body"] = json.loads(request.read().decode())
        return httpx.Response(200, text=sse({"content": "Hi."}))

    case = Case(id="c1", message="and the warranty?",
                history=({"role": "user", "content": "X200 price?"},
                         {"role": "assistant", "content": "RM 399."}))
    await ask("http://engine:8000/", "bot_1", case, "eval-s-1", transport=httpx.MockTransport(handler))

    assert seen["url"] == "http://engine:8000/api/v1/chat/stream"
    assert seen["body"] == {
        "bot_id": "bot_1", "session_id": "eval-s-1", "message": "and the warranty?",
        "history": [{"role": "user", "content": "X200 price?"},
                    {"role": "assistant", "content": "RM 399."},
                    {"role": "user", "content": "and the warranty?"}]}


@pytest.mark.asyncio
async def test_the_answer_sources_model_and_timing_are_observed():
    body = sse({"type": "sources", "kind": "documents",
                "sources": [{"n": 1, "title": "Warranty Policy"}]},
               {"content": "Two "}, {"content": "years."},
               {"meta": {"model": "qwen3.5-4b", "tokens_in": 40, "tokens_out": 3}})

    observed = await ask("http://engine", "bot_1", Case(id="c1", message="warranty?"), "s",
                         transport=httpx.MockTransport(lambda r: httpx.Response(200, text=body)),
                         clock=ticking(10.0, 10.4, 12.0))

    assert observed.status == 200
    assert observed.answer == "Two years."
    assert observed.source == "documents"
    assert observed.citations == [{"n": 1, "title": "Warranty Policy"}]
    assert observed.model == "qwen3.5-4b"
    assert (observed.tokens_in, observed.tokens_out) == (40, 3)
    assert observed.first_token_seconds == pytest.approx(0.4)
    assert observed.total_seconds == pytest.approx(2.0)
    assert observed.error == ""


@pytest.mark.asyncio
async def test_no_sources_event_means_no_source():
    observed = await ask("http://engine", "bot_1", Case(id="c1", message="hello"), "s",
                         transport=httpx.MockTransport(
                             lambda r: httpx.Response(200, text=sse({"content": "Hi!"}))))

    assert observed.source == "none"
    assert observed.citations == []


@pytest.mark.asyncio
async def test_thinking_is_kept_out_of_the_answer():
    body = sse({"reasoning": "the visitor greets"}, {"content": "Hello!"})

    observed = await ask("http://engine", "bot_1", Case(id="c1", message="hi"), "s",
                         transport=httpx.MockTransport(lambda r: httpx.Response(200, text=body)))

    assert observed.answer == "Hello!"


@pytest.mark.asyncio
async def test_an_error_event_is_recorded():
    body = sse({"error": "Cannot connect to LLM server"})

    observed = await ask("http://engine", "bot_1", Case(id="c1", message="hi"), "s",
                         transport=httpx.MockTransport(lambda r: httpx.Response(200, text=body)))

    assert observed.error == "Cannot connect to LLM server"
    assert observed.first_token_seconds is None


@pytest.mark.asyncio
async def test_a_refused_request_is_recorded_with_its_status():
    observed = await ask("http://engine", "bot_missing", Case(id="c1", message="hi"), "s",
                         transport=httpx.MockTransport(
                             lambda r: httpx.Response(404, json={"detail": "Bot profile not found"})))

    assert observed.status == 404
    assert observed.error.startswith("HTTP 404")


@pytest.mark.asyncio
async def test_an_unreachable_engine_is_recorded_not_raised():
    def handler(request):
        raise httpx.ConnectError("refused")

    observed = await ask("http://engine", "bot_1", Case(id="c1", message="hi"), "s",
                         transport=httpx.MockTransport(handler))

    assert observed.error.startswith("ConnectError")
```

### D. `evals/stream.py`

The code is as committed in Task 2: `Observed` is a mutable dataclass with the fields asserted in Appendix C, and `ask(engine_url, bot_id, case, session_id, transport=None, clock=time.perf_counter)` behaves exactly as those tests describe, feeding events through `reasoning.TranscriptCollector` so thinking never reaches the answer.

### E. `tests/test_evals_judge.py` and `tests/test_evals_report.py`

These are as committed in Task 3. Together they cover:

- **Judge parsing.** An integer from 1 to 5 is accepted; `4.0` is read as 4; `0`, `6`, `"4"`, `true` and prose are unusable.
- **Unjudged cases.** A case with no reference is never sent to the judge.
- **The judge call.** It uses the named endpoint and model, the prompt, and JSON mode, and a failed call yields "no usable score".
- **The summary.** It counts passed and failed cases, and computes the median first token, the nearest-rank p90 of total seconds, and the mean judge score over judged cases only.
- **Markdown.** It names the set and the bot, lists every case, and adds a failures section with reasons and the answer only when something failed.
- **JSON.** It holds every case's answer, timings, failures and judge score.
- **CLI arguments.** `--judge-provider` without `--judge-model`, or the reverse, is refused.

### F. `evals/judge.py`, `evals/report.py`, `evals/__main__.py`

These are as committed in Task 3, and behave as Appendix E describes.

### G. `evals/sets/corporate-support-smoke.json` and `docs/evaluation.md`

These are as committed in Task 4. The set targets `bot_demo_default`, which has no knowledge base, database or web search today, so every case expects source `none`. It covers:

- **Greeting.** It absorbs warm-up.
- **Capability.** The bot says what it can help with.
- **Memory.** The bot recalls a product and a name from the history.
- **Malay.** A question in Malay, checked for latency only.
- **Refusal.** A harmful request, answered without instructions.
