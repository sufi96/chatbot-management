# Follow-up Questions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A bot can read each question with the conversation before it, so the sources search for what the visitor meant ("and the warranty?" becomes "What is the warranty on the X200 air fryer?"), and small talk the word rules let through is answered without searching.

**Architecture:** A new `api-engine/intent.py` asks the `intent` role (from plan 1's `roles.py`) for one JSON object, `{"intent": "facts" | "chat", "query": "..."}`, and turns it into a `Decision` the chat route acts on. The word-rule gate stays the fast path and runs first. The intent step never chooses a source; the operator's order still does. Every failure means "facts, searched with the visitor's own words", which is today's behaviour. A per-bot switch, off by default, turns it on, and the verdict and rewrite are stored on the visitor's message.

**Tech Stack:** Python 3.11+, FastAPI, httpx, pytest; Laravel 13, PHPUnit, Blade.

**Spec:** `docs/model-stack-review.md` section 5, and the plan 2 outline in `docs/superpowers/plans/2026-09-15-spark-readiness-roadmap.md`.

## Global Constraints

- Verdicts are exactly `facts` and `chat`. Anything else reads as `facts`.
- A message containing `?` is never `chat`.
- The intent step runs only when all three hold: the word rules would search the message, the bot's `intent_enabled` is true, and at least one source is switched on.
- The rewritten query goes to the sources only. The answer model still receives the visitor's own message and history, unchanged.
- The intent call asks for `response_format: {"type": "json_object"}`, at temperature 0, with thinking off. It reads at most the last 6 history turns, each cut to 400 characters. The query is cut to 500 characters.
- The widget sends the current message as the last `history` turn. The intent input must not repeat it.
- `bot_profiles.intent_enabled` defaults to false. Existing bots behave exactly as before.
- Engine tests run from `api-engine/` with `.venv\Scripts\python.exe -m pytest`. Portal tests run from `admin-laravel/` with `php artisan test`.
- Commit messages follow the repo style, `feat: <plain sentence>`.

## File map

| File | Change | Responsibility |
|---|---|---|
| `api-engine/llm_adapter.py` | Modify | `complete()` can ask for JSON, and drops it when refused |
| `api-engine/tests/test_llm_complete.py` | Modify | JSON mode tests |
| `api-engine/intent.py` | Create | Prompt, input, parsing, `understand`, `decide` |
| `api-engine/tests/test_intent.py` | Create | All intent behaviour, with fakes |
| `admin-laravel/database/migrations/2026_09_15_000002_add_intent_to_bots_and_messages.php` | Create | Three columns |
| `admin-laravel/app/Models/BotProfile.php` | Modify | `intent_enabled` fillable and cast |
| `admin-laravel/app/Models/ChatMessage.php` | Modify | `intent`, `intent_query` fillable |
| `admin-laravel/app/Http/Controllers/BotBrainController.php` | Modify | Saves the switch |
| `admin-laravel/resources/views/bots/brain.blade.php` | Modify | The switch, in the source order card |
| `admin-laravel/resources/views/logs/index.blade.php` | Modify | What a question was searched as |
| `admin-laravel/tests/Feature/BotIntentSettingsTest.php` | Create | Columns, default, save, page, transcript |
| `api-engine/database.py` | Modify | Engine columns |
| `api-engine/routers/chat.py` | Modify | Uses `intent.decide` |
| `docs/architecture.md` | Modify | A follow-up questions section |

---

### Task 1: One-shot calls can ask for JSON

**Files:**
- Modify: `api-engine/llm_adapter.py` (`complete`)
- Modify: `api-engine/tests/test_llm_complete.py`

**Interfaces:**
- Produces: `LLMAdapter.complete(base_url, api_key, model_name, system_prompt, user_message, temperature=0.0, max_tokens=512, response_format: dict | None = None, transport=None) -> str`

- [ ] **Step 1: Write the failing tests.** Append to `api-engine/tests/test_llm_complete.py`:

```python
@pytest.mark.asyncio
async def test_json_can_be_asked_for():
    seen = {}
    await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u", response_format={"type": "json_object"},
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert json.loads(seen["body"])["response_format"] == {"type": "json_object"}


@pytest.mark.asyncio
async def test_json_is_not_asked_for_unless_wanted():
    seen = {}
    await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert "response_format" not in json.loads(seen["body"])


@pytest.mark.asyncio
async def test_an_endpoint_that_refuses_json_mode_is_asked_again_without_it():
    """JSON mode is a nicety. The parser copes with prose around the object,
    so losing the whole verdict to an endpoint that lacks it would be worse."""
    seen = []

    def handler(request: httpx.Request) -> httpx.Response:
        body = json.loads(request.read().decode())
        seen.append(body)
        if "response_format" in body:
            return httpx.Response(400, json={
                "error": {"message": "response_format is not supported"}})
        return httpx.Response(200, json=ANSWER)

    answer = await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u", response_format={"type": "json_object"},
        transport=httpx.MockTransport(handler))

    assert answer == "database"
    assert len(seen) == 2
    assert "response_format" not in seen[1]
```

- [ ] **Step 2: Run them to verify they fail.** Run `.venv\Scripts\python.exe -m pytest tests/test_llm_complete.py -q`. Expected: 3 failures, `unexpected keyword argument 'response_format'`.

- [ ] **Step 3: Implement.** In `LLMAdapter.complete`:
  - Add the parameter `response_format: dict | None = None` between `max_tokens` and `transport`.
  - Change the docstring's first line to `"""A whole answer in one call, for the SQL generator and the intent reader.`.
  - After the `payload` literal, add:

```python
        # Asked for only by a caller that parses JSON. An endpoint without the
        # mode is handled on the retry below, like the thinking switch.
        if response_format:
            payload["response_format"] = response_format
```

  Replace the retry check inside the loop:

```python
                    if (response.status_code == 400
                            and "chat_template_kwargs" in payload
                            and "chat_template_kwargs" in response.text):
                        payload.pop("chat_template_kwargs")
                        continue
```

  with:

```python
                    refused = [key for key in ("chat_template_kwargs", "response_format")
                               if key in payload and key in response.text]
                    if response.status_code == 400 and refused:
                        for key in refused:
                            payload.pop(key)
                        continue
```

- [ ] **Step 4: Run the engine suite.** Run `.venv\Scripts\python.exe -m pytest -q`. Expected: all pass, including `test_an_endpoint_that_refuses_the_thinking_switch_is_asked_again`.

- [ ] **Step 5: Commit.** `git add api-engine/llm_adapter.py api-engine/tests/test_llm_complete.py` then `git commit -m "feat: a one-shot call can ask for json and survives an endpoint that cannot"`

---

### Task 2: Reading a message before the sources

**Files:**
- Create: `api-engine/intent.py`
- Create: `api-engine/tests/test_intent.py`

**Interfaces:**
- Consumes: `roles.endpoint_for`, `kb.gating.should_retrieve`, `LLMAdapter.complete(..., response_format=...)`
- Produces:
  - `intent.INTENTS = ("facts", "chat")`, `HISTORY_TURNS = 6`, `TURN_CHARS = 400`, `QUERY_CHARS = 500`, `PROMPT: str`
  - `intent.Understanding(intent: str, query: str, ran: bool = False, model: str = "")`, frozen
  - `intent.build_input(message: str, history: list[dict]) -> str`
  - `intent.parse(raw: str, message: str) -> Understanding | None`
  - `async intent.understand(bot, message, history, settings, complete=None) -> Understanding`
  - `intent.Decision(is_question: bool, query: str, intent: str | None = None, model: str = "")`, frozen
  - `async intent.decide(bot, message, history, settings, enabled: dict, understand_fn=None) -> Decision`

- [ ] **Step 1: Write the failing tests.** Create `api-engine/tests/test_intent.py` with the content in the Appendix, section A.

- [ ] **Step 2: Run them to verify they fail.** Run `.venv\Scripts\python.exe -m pytest tests/test_intent.py -q`. Expected: collection error, `No module named 'intent'`.

- [ ] **Step 3: Implement.** Create `api-engine/intent.py` with the content in the Appendix, section B.

- [ ] **Step 4: Run the engine suite.** Run `.venv\Scripts\python.exe -m pytest -q`. Expected: all pass.

- [ ] **Step 5: Commit.** `git add api-engine/intent.py api-engine/tests/test_intent.py` then `git commit -m "feat: a question is read with the conversation before it"`

---

### Task 3: The switch, and a record of what was understood

**Files:**
- Create: `admin-laravel/tests/Feature/BotIntentSettingsTest.php` (Appendix, section C)
- Create: `admin-laravel/database/migrations/2026_09_15_000002_add_intent_to_bots_and_messages.php` (Appendix, section D)
- Modify: `admin-laravel/app/Models/BotProfile.php`, `ChatMessage.php`, `BotBrainController.php`, `bots/brain.blade.php`, `logs/index.blade.php`
- Modify: `api-engine/database.py`

**Interfaces:**
- Produces: `bot_profiles.intent_enabled` (boolean, default false), `chat_messages.intent` (string 20, nullable), and `chat_messages.intent_query` (text, nullable), all mapped on both sides.

- [ ] **Step 1: Write the failing test.** Create `BotIntentSettingsTest.php` from Appendix C.
- [ ] **Step 2: Run it to verify it fails.** Run `php artisan test --filter=BotIntentSettingsTest`. Expected: failures on the missing columns.
- [ ] **Step 3: Add the migration** from Appendix D.
- [ ] **Step 4: Map the columns in the portal.**
  - `BotProfile`: add `'intent_enabled',` to `$fillable` after `'source_order',`, and add `'intent_enabled' => 'boolean',` to `casts()` after `'db_query_enabled' => 'boolean',`.
  - `ChatMessage`: add `'intent', 'intent_query',` to `$fillable` after `'model_trace',`, with the comment `// What the intent step decided, on the visitor's own row.`
  - `BotBrainController::update`: after `$validated['db_query_enabled'] = ...` add `$validated['intent_enabled'] = $request->boolean('intent_enabled');`.
- [ ] **Step 5: Add the switch.** In `bots/brain.blade.php`, inside the Answer source order card, insert the markup from Appendix E immediately before the comment `{{-- This governs the end of the cascade`.
- [ ] **Step 6: Show it in the logs.** In `logs/index.blade.php`, replace `wrapper.appendChild(bubble);` followed by `wrapper.appendChild(meta);` with the script from Appendix F.
- [ ] **Step 7: Map the columns in the engine.** In `api-engine/database.py`:
  - In `BotProfile`, after `source_order`, add:

```python
    # Whether each question is read with the conversation before it. See intent.py.
    intent_enabled = Column(Boolean, default=False)
```

  - In `ChatMessage`, after `model_trace`, add:

```python
    # On the visitor's row: what the intent step decided, and what the sources
    # searched for when that differed from the words sent.
    intent = Column(String(20), nullable=True)
    intent_query = Column(Text, nullable=True)
```

- [ ] **Step 8: Run both suites.** Expected: all pass.
- [ ] **Step 9: Commit** with `feat: a bot can be told to understand follow-up questions`.

---

### Task 4: The chat route acts on the decision

**Files:**
- Modify: `api-engine/routers/chat.py`
- Modify: `docs/architecture.md`
- Modify: `docs/superpowers/plans/2026-09-15-spark-readiness-roadmap.md` (status column)

- [ ] **Step 1: Wire the decision.** In `routers/chat.py`:
  - Replace `from kb.gating import should_retrieve` with `import intent`.
  - Replace the block from the comment `# A greeting is not a question.` through `enabled = sources.enabled_for(bot)` with:

```python
    engine_settings = await get_settings(db)
    enabled = sources.enabled_for(bot)

    # Whether to search, and for what. A greeting is settled by word rules and
    # costs nothing. A bot told to understand follow-ups also has the intent
    # model rewrite the question, so the sources search what the visitor meant.
    decision = await intent.decide(bot, req.message, req.history or [],
                                   engine_settings, enabled)
    message_is_a_question = decision.is_question

    if decision.intent:
        # Kept on the visitor's own row, beside the words it was read from.
        user_msg.intent = decision.intent
        user_msg.intent_query = decision.query if decision.query != req.message else None
        await db.commit()
```

  - In `sources.build_attempts(db, bot, req.message, ...)`, pass `decision.query` in place of `req.message`.
  - In the `model_trace=` argument, replace `{"sql": sql_model} if (found and found.sql) else {}` with `{"intent": decision.model, **({"sql": sql_model} if (found and found.sql) else {})}`.

- [ ] **Step 2: Check it imports, and run the suite.** Run `.venv\Scripts\python.exe -c "import routers.chat"`, then the full engine suite. Expected: all pass.
- [ ] **Step 3: Document it.** In `docs/architecture.md`, insert the section in Appendix G immediately before `## 6. Prompt assembly`. In the roadmap, set plan 1's status to `Done` and plan 2's to `Done`.
- [ ] **Step 4: Commit** with `feat: the sources search what a follow-up question meant`.

---

## Appendix

### A. `api-engine/tests/test_intent.py`

```python
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
```

### B. `api-engine/intent.py`

```python
"""Understanding a visitor's message before any source is consulted.

Two questions, answered by one call to the intent role. Does answering need
facts at all, or is this small talk? And what should the sources search for,
given that "and the warranty?" means nothing without the message before it?

What this does not decide is which source answers. That stays the operator's
order: a model choosing the source was tried and removed because nobody could
configure or explain it (docs/model-stack-review.md section 5). A verdict here
only says whether to look, and what for.

Every failure reads as "facts, searched with the visitor's own words", which is
exactly what the chat route did before this module existed.
"""
import json
import re
from dataclasses import dataclass

import roles
from kb.gating import should_retrieve
from llm_adapter import LLMAdapter

INTENTS = ("facts", "chat")

HISTORY_TURNS = 6
TURN_CHARS = 400
QUERY_CHARS = 500

PROMPT = """You read the latest message sent to a customer service assistant, with the conversation before it, and decide two things.

intent: "facts" when answering needs information about the business, its products, services, policies, orders or records. "chat" when it is a greeting, thanks, small talk, or a reaction that needs no information.

query: the latest message rewritten as one question that makes sense with no conversation before it. Replace words such as "it", "that one" and "the second" with what they refer to. Keep the visitor's language. Keep product names, codes and numbers exactly as written. When the message already stands on its own, repeat it unchanged.

Reply with one JSON object and nothing else, for example:
{"intent": "facts", "query": "What is the warranty on the X200 air fryer?"}"""

# First brace to last brace. A small model wraps its object in a fence or a
# sentence however firmly it is told not to.
_OBJECT = re.compile(r"\{.*\}", re.DOTALL)


@dataclass(frozen=True)
class Understanding:
    intent: str
    query: str
    # Whether a model produced this. False is the fallback, which every caller
    # treats exactly like the behaviour before intent existed.
    ran: bool = False
    model: str = ""


@dataclass(frozen=True)
class Decision:
    # Whether the sources are consulted at all. The route still checks that
    # one is switched on.
    is_question: bool
    # What the sources are asked. The answer model reads the visitor's words.
    query: str
    # The model's verdict, or None when no model was asked.
    intent: str | None = None
    model: str = ""


def build_input(message: str, history: list[dict]) -> str:
    """The conversation as the intent model reads it: recent turns, then the message."""
    turns = list(history or [])

    # The widget sends the message being asked as the last turn of its history
    # as well. Reading it twice would make it its own context.
    if (turns and (turns[-1].get("role") or turns[-1].get("sender")) == "user"
            and (turns[-1].get("content") or "").strip() == message.strip()):
        turns = turns[:-1]

    lines = []
    for turn in turns[-HISTORY_TURNS:]:
        role = turn.get("role") or turn.get("sender") or ""
        content = (turn.get("content") or "").strip()
        if role not in ("user", "assistant") or not content:
            continue
        speaker = "visitor" if role == "user" else "assistant"
        lines.append(f"{speaker}: {content[:TURN_CHARS]}")

    earlier = "\n".join(lines) if lines else "(none)"

    return f"Conversation so far:\n{earlier}\n\nLatest message:\n{message.strip()}"


def parse(raw: str, message: str) -> Understanding | None:
    """The model's reply as an Understanding, or None when it is not usable."""
    found = _OBJECT.search(raw or "")
    if not found:
        return None

    try:
        data = json.loads(found.group(0))
    except ValueError:
        return None

    if not isinstance(data, dict):
        return None

    verdict = str(data.get("intent") or "").strip().lower()
    if verdict not in INTENTS:
        verdict = "facts"

    query = str(data.get("query") or "").strip()[:QUERY_CHARS] or message.strip()

    # A question mark is the visitor saying it is a question. A small model's
    # "chat" does not overrule that: a wasted search costs less than an
    # invented answer.
    if verdict == "chat" and "?" in message:
        verdict = "facts"

    return Understanding(intent=verdict, query=query, ran=True)


async def understand(bot, message: str, history: list[dict], settings: dict,
                     complete=None) -> Understanding:
    fallback = Understanding(intent="facts", query=message)

    endpoint = roles.endpoint_for("intent", bot, settings)
    if not endpoint.available:
        return fallback

    complete = complete or LLMAdapter.complete

    raw = await complete(
        base_url=endpoint.base_url, api_key=endpoint.api_key,
        model_name=endpoint.model, system_prompt=PROMPT,
        user_message=build_input(message, history),
        max_tokens=200, response_format={"type": "json_object"})

    parsed = parse(raw, message)
    if parsed is None:
        print(f"[Intent] Unusable reply, searching with the message as sent: {(raw or '')[:120]!r}")
        return fallback

    return Understanding(parsed.intent, parsed.query, ran=True, model=endpoint.model)


async def decide(bot, message: str, history: list[dict], settings: dict,
                 enabled: dict, understand_fn=None) -> Decision:
    """Whether to search, and for what.

    The word rules run first and stay the fast path: a greeting never costs a
    model call. Only a message they would search, on a bot with the switch on
    and a source to search, is read by the intent model.
    """
    is_question = should_retrieve(message)

    if not (is_question and bot.intent_enabled and any(enabled.values())):
        return Decision(is_question=is_question, query=message)

    understanding = await (understand_fn or understand)(bot, message, history, settings)

    if not understanding.ran:
        return Decision(is_question=True, query=message)

    return Decision(is_question=understanding.intent == "facts",
                    query=understanding.query, intent=understanding.intent,
                    model=understanding.model)
```

### C. `admin-laravel/tests/Feature/BotIntentSettingsTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use App\Models\User;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BotIntentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    private function editor(): User
    {
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    private function bot(array $overrides = []): BotProfile
    {
        return BotProfile::create(array_merge([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper',
        ], $overrides));
    }

    private function brainPayload(array $overrides = []): array
    {
        return array_merge([
            'system_prompt' => 'Be helpful.',
            'retrieval_mode' => 'hybrid',
            'retrieval_top_k' => 5,
            'retrieval_candidates' => 30,
            'retrieval_min_score' => 0.02,
            'retrieval_fallback' => 'say_unknown',
            'web_search_max_results' => 3,
            'web_search_country' => null,
            'top_p' => 1.0,
            'top_k_sampling' => null,
            'presence_penalty' => 0,
            'frequency_penalty' => 0,
            'thinking_level' => 'off',
            'db_max_rows' => 50,
            'db_query_timeout' => 10,
            'source_order' => SourceOrder::DEFAULT,
        ], $overrides);
    }

    public function test_the_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'intent_enabled'));
        $this->assertTrue(Schema::hasColumn('chat_messages', 'intent'));
        $this->assertTrue(Schema::hasColumn('chat_messages', 'intent_query'));
    }

    public function test_it_is_off_by_default(): void
    {
        // It costs a model call on every question. A bot that answers well
        // today must not get slower because of an upgrade.
        $this->assertFalse($this->bot()->fresh()->intent_enabled);
    }

    public function test_the_brain_page_offers_the_switch(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->get(route('bots.brain', $bot->id))
            ->assertOk()
            ->assertSee('Understand follow-up questions')
            ->assertSee('name="intent_enabled"', false);
    }

    public function test_an_editor_switches_it_on(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload(['intent_enabled' => '1']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($bot->fresh()->intent_enabled);
    }

    public function test_an_unticked_box_switches_it_off(): void
    {
        $bot = $this->bot(['intent_enabled' => true]);

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload())
            ->assertRedirect();

        $this->assertFalse($bot->fresh()->intent_enabled);
    }

    public function test_the_transcript_shows_what_a_question_was_searched_as(): void
    {
        $user = $this->editor();
        $this->bot();
        ChatConversation::create([
            'id' => 'conv_1', 'bot_id' => 'bot_1', 'session_id' => 's1', 'origin' => '',
        ]);

        ChatMessage::create([
            'id' => 'msg_1', 'conversation_id' => 'conv_1', 'sender' => 'user',
            'content' => 'and the warranty?',
            'intent' => 'facts',
            'intent_query' => 'What is the warranty on the X200 air fryer?',
        ]);

        $this->actingAs($user)
            ->get(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertJsonPath('messages.0.intent', 'facts')
            ->assertJsonPath('messages.0.intent_query', 'What is the warranty on the X200 air fryer?');
    }
}
```

### D. `admin-laravel/database/migrations/2026_09_15_000002_add_intent_to_bots_and_messages.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a bot reads each question with the conversation before it, and
     * what that reading decided.
     *
     * Off by default. It costs a model call on every question, and a bot that
     * answers well today must not get slower because of an upgrade.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('intent_enabled')->default(false);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            // On the visitor's row: "facts" or "chat", null when no model read it.
            $table->string('intent', 20)->nullable();
            // What the sources searched for, when it differed from the words sent.
            $table->text('intent_query')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['intent', 'intent_query']);
        });

        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('intent_enabled');
        });
    }
};
```

### E. The switch, for `bots/brain.blade.php`

```blade
                        {{-- With the order rather than inside one source: it changes
                             what every source is asked. --}}
                        <div class="mt-3 pt-3" style="border-top: 1px solid var(--border);">
                            <div class="form-check form-switch d-flex align-items-center gap-2 mb-1">
                                <input class="form-check-input" type="checkbox" role="switch" name="intent_enabled" value="1"
                                       id="intent_enabled" {{ old('intent_enabled', $bot->intent_enabled) ? 'checked' : '' }}>
                                <label class="form-check-label" for="intent_enabled">Understand follow-up questions</label>
                            </div>
                            <div class="form-text">
                                Reads each question with the conversation before it, so "and the warranty?"
                                is searched as the whole question, and small talk is answered without searching.
                                It adds one model call to every question. Which model is set in admin settings.
                            </div>
                        </div>

```

### F. The logs line, for `logs/index.blade.php`

```js
                    wrapper.appendChild(bubble);

                    // What the intent step made of a visitor's message. Without
                    // it, an answer about the wrong product reads as the bot's
                    // mistake rather than the question's.
                    if (isUser && (msg.intent === 'chat' || msg.intent_query)) {
                        var understood = document.createElement('span');
                        understood.className = 'text-muted mt-1';
                        understood.style.fontSize = '0.6875rem';
                        understood.style.maxWidth = '78%';
                        understood.textContent = msg.intent === 'chat'
                            ? 'Read as small talk, so no source was searched'
                            : 'Searched as: ' + msg.intent_query;
                        wrapper.appendChild(understood);
                    }

                    wrapper.appendChild(meta);
```

### G. `docs/architecture.md` section

```markdown
### Follow-up questions

A bot with "Understand follow-up questions" switched on takes one more step,
after the gate and only for a message the gate would search. The `intent` role
reads the message with the last six turns and returns `facts` or `chat`, along
with the message rewritten as a question that stands on its own. `facts` sends
the rewrite, not the visitor's words, to every source. `chat` consults none.
The answer model still reads the visitor's own words.

It never chooses a source; the order does. A message with a question mark is
never `chat`. Every failure, from an unreachable endpoint to a reply that is not
JSON, searches with the message as sent, which is what happened before the step
existed. The verdict and the rewrite are stored on the visitor's message and
shown on the logs screen.

| Concern | Lives in |
|---|---|
| Reading, parsing and deciding | `api-engine/intent.py` |
| Acting on the decision | `api-engine/routers/chat.py` |

---

```
