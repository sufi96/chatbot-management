# Model Roles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every job a model does besides answering resolves its endpoint in one place, each job is configurable in Admin Settings with blank meaning today's behaviour, and every answer records which model did each job.

**Architecture:** A new `api-engine/roles.py` turns a role name (`sql`, `intent`, `rerank`, `guard`) plus the bot and the install settings into an `Endpoint`. Generative roles left blank borrow the bot's provider and model; the specialist `rerank` role left blank is unavailable, so its stage will be skipped. The SQL generator moves onto it first. A `model_trace` JSON column on `chat_messages` records the model per job, and Admin Settings replaces its single SQL card with a Models card listing every role.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy async, pytest (asyncio_mode=auto); Laravel 13, PHP 8.3+, PHPUnit feature tests, Blade + Bootstrap.

**Spec:** `docs/model-stack-review.md` (sections 5 to 8) and `docs/superpowers/plans/2026-09-15-spark-readiness-roadmap.md`.

## Global Constraints

- Setting keys are exactly `{role}_model_base_url`, `{role}_model_api_key`, `{role}_model_name`.
- Roles in this plan are exactly `sql`, `intent`, `rerank`, `guard`. `sql` keeps its existing keys and values.
- Every new setting defaults to `''`, in both `AppSetting::DEFAULTS` (Laravel) and `SETTING_DEFAULTS` (engine). The two lists are kept in step by hand, as they already are.
- Blank must reproduce today's behaviour exactly. The SQL generator must resolve to the same `(base_url, api_key, model)` tuples the existing tests in `api-engine/tests/test_ai_provider.py` pin.
- Endpoints are OpenAI-compatible base URLs. No new Python or Composer dependencies.
- `intent`, `rerank` and `guard` are registered but not called by anything in this plan. Plans 2 to 4 wire them.
- Match the codebase's comment style: docstrings and comments explain *why*, in full sentences, British spelling.
- Commit messages follow the repo's style: `feat: <plain sentence>`, lowercase.
- Engine tests run from `api-engine/` with `python -m pytest`. Portal tests run from `admin-laravel/` with `php artisan test`.
- `admin-laravel/CLAUDE.md` asks for Laravel Boost to be installed before application changes, and `composer.json` does not have it. Ask the user whether to install it before starting Task 3. Do not install it unasked.

## File map

| File | Change | Responsibility |
|---|---|---|
| `api-engine/roles.py` | Create | Role names, fallback rules, `Endpoint`, `endpoint_for`, `model_trace` |
| `api-engine/tests/test_roles.py` | Create | Resolution and trace behaviour |
| `api-engine/database.py` | Modify | New setting defaults; `ChatMessage.model_trace` column |
| `api-engine/dbquery/__init__.py` | Modify | `_sql_endpoint` delegates to `roles` |
| `api-engine/reasoning.py` | Modify | `TranscriptCollector.model` |
| `api-engine/tests/test_transcript_model.py` | Create | The collector keeps the reported model |
| `api-engine/routers/chat.py` | Modify | Writes `model_trace` on the assistant message |
| `admin-laravel/database/migrations/2026_09_15_000001_add_model_trace_to_chat_messages.php` | Create | The column |
| `admin-laravel/app/Models/ChatMessage.php` | Modify | `model_trace` fillable |
| `admin-laravel/tests/Feature/TranscriptModelTraceTest.php` | Create | Transcript JSON carries the trace |
| `admin-laravel/resources/views/logs/index.blade.php` | Modify | Shows the trace beside each bot message |
| `admin-laravel/app/Models/AppSetting.php` | Modify | New defaults |
| `admin-laravel/app/Http/Controllers/AdminSettingsController.php` | Modify | `MODEL_ROLES`, keys and rules built from it |
| `admin-laravel/resources/views/admin/_model-role.blade.php` | Create | One role's three fields |
| `admin-laravel/resources/views/admin/settings.blade.php` | Modify | Models card replaces the SQL card |
| `admin-laravel/tests/Feature/ModelRoleSettingsTest.php` | Create | Defaults, screen, save |
| `docs/architecture.md` | Modify | A "Model roles" section |

---

### Task 1: Resolve every model role in one place

**Files:**
- Create: `api-engine/roles.py`
- Create: `api-engine/tests/test_roles.py`
- Modify: `api-engine/database.py:273-279` (the `sql_model_*` defaults block)
- Modify: `api-engine/dbquery/__init__.py:16` (import) and `:104-114` (`_sql_endpoint`)

**Interfaces:**
- Consumes: `database.provider_endpoint(bot) -> tuple[str, str]`, `database.SETTING_DEFAULTS`
- Produces:
  - `roles.GENERATIVE: tuple[str, ...] = ("sql", "intent", "guard")`
  - `roles.SPECIALIST: tuple[str, ...] = ("rerank",)`
  - `roles.ROLES = GENERATIVE + SPECIALIST`
  - `roles.Endpoint(role: str, base_url: str = "", api_key: str = "", model: str = "", configured: bool = False)`, frozen dataclass, with property `available -> bool`
  - `roles.setting_keys(role: str) -> tuple[str, str, str]` returning `(base_url_key, api_key_key, model_name_key)`
  - `roles.endpoint_for(role: str, bot, settings: dict) -> Endpoint`, raising `ValueError` for an unknown role
  - `roles.model_trace(chat_model: str | None, used: dict[str, str]) -> str | None` (added in Task 2)

- [ ] **Step 1: Write the failing tests**

Create `api-engine/tests/test_roles.py`:

```python
"""Which model does which job, and what a job left blank falls back to.

The rule under test: blank reproduces what the system did before the role
existed. A generative job borrows the bot's own model; the reranker, which no
chat model can stand in for, becomes unavailable so its stage is skipped.
"""
import pytest

import roles
from database import SETTING_DEFAULTS


class FakeProvider:
    def __init__(self, base_url="http://localhost:11434/v1", api_key=""):
        self.base_url = base_url
        self.api_key = api_key


class FakeBot:
    def __init__(self, provider="default", model_name="qwen3.5:4b"):
        self.provider = FakeProvider() if provider == "default" else provider
        self.model_name = model_name


def test_every_role_defaults_to_blank():
    for role in roles.ROLES:
        for key in roles.setting_keys(role):
            assert SETTING_DEFAULTS[key] == "", key


def test_the_setting_keys_follow_one_pattern():
    assert roles.setting_keys("intent") == (
        "intent_model_base_url", "intent_model_api_key", "intent_model_name")


def test_a_blank_generative_role_borrows_the_bots_model():
    for role in roles.GENERATIVE:
        endpoint = roles.endpoint_for(role, FakeBot(), SETTING_DEFAULTS)

        assert endpoint.base_url == "http://localhost:11434/v1", role
        assert endpoint.model == "qwen3.5:4b", role
        assert endpoint.configured is False, role
        assert endpoint.available is True, role


def test_a_configured_role_wins_over_the_bot():
    settings = {**SETTING_DEFAULTS,
                "intent_model_base_url": "http://spark-b:8001/v1",
                "intent_model_api_key": "k",
                "intent_model_name": "qwen3.5-35b-a3b"}

    endpoint = roles.endpoint_for("intent", FakeBot(), settings)

    assert endpoint == roles.Endpoint("intent", "http://spark-b:8001/v1", "k",
                                      "qwen3.5-35b-a3b", configured=True)


def test_a_url_without_a_model_does_not_count_as_configured():
    settings = {**SETTING_DEFAULTS, "guard_model_base_url": "http://spark-b:8003/v1"}

    endpoint = roles.endpoint_for("guard", FakeBot(), settings)

    assert endpoint.configured is False
    assert endpoint.base_url == "http://localhost:11434/v1"


def test_surrounding_whitespace_is_not_an_endpoint():
    settings = {**SETTING_DEFAULTS, "sql_model_base_url": "  ", "sql_model_name": " "}

    assert roles.endpoint_for("sql", FakeBot(), settings).configured is False


def test_a_blank_reranker_is_unavailable_rather_than_borrowed():
    """A chat model does not speak the rerank protocol. Borrowing one would
    fail on every question; skipping the stage is what happened before it
    existed."""
    endpoint = roles.endpoint_for("rerank", FakeBot(), SETTING_DEFAULTS)

    assert endpoint.available is False
    assert endpoint.model == ""


def test_a_configured_reranker_is_available():
    settings = {**SETTING_DEFAULTS,
                "rerank_model_base_url": "http://localhost:8012/v1",
                "rerank_model_name": "bge-reranker-v2-m3"}

    assert roles.endpoint_for("rerank", FakeBot(), settings).available is True


def test_a_bot_with_no_provider_yields_an_unavailable_endpoint():
    endpoint = roles.endpoint_for("sql", FakeBot(provider=None), SETTING_DEFAULTS)

    assert endpoint.available is False
    assert endpoint.model == "qwen3.5:4b"


def test_missing_settings_keys_read_as_blank():
    assert roles.endpoint_for("intent", FakeBot(), {}).model == "qwen3.5:4b"


def test_an_unknown_role_is_refused():
    with pytest.raises(ValueError):
        roles.endpoint_for("imagine", FakeBot(), SETTING_DEFAULTS)
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `python -m pytest tests/test_roles.py -v`
Expected: collection error, `ModuleNotFoundError: No module named 'roles'`

- [ ] **Step 3: Add the defaults**

In `api-engine/database.py`, replace the `sql_model_*` block inside `SETTING_DEFAULTS` (lines 273-278) with:

```python
    # Blank means each bot uses its own endpoint and model. Small models are
    # markedly weaker at SQL than at conversation, so an install can point
    # query work somewhere stronger without making every chat cost more.
    "sql_model_base_url": "",
    "sql_model_api_key": "",
    "sql_model_name": "",
    # The same shape for every other job a model does. roles.py decides what
    # blank falls back to; see there.
    "intent_model_base_url": "",
    "intent_model_api_key": "",
    "intent_model_name": "",
    "rerank_model_base_url": "",
    "rerank_model_api_key": "",
    "rerank_model_name": "",
    "guard_model_base_url": "",
    "guard_model_api_key": "",
    "guard_model_name": "",
```

- [ ] **Step 4: Write the resolver**

Create `api-engine/roles.py`:

```python
"""Which model does which job.

Every job that needs a model resolves its endpoint here rather than reading
settings itself. That is what lets the same code run on a laptop where one small
model does everything, and on a server where each job has a model of its own:
moving a job is a settings change, never a code change.

A role configured in Admin Settings is used as configured. A role left blank
falls back, and how depends on the kind of job:

- A generative job (sql, intent, guard) borrows the bot's own provider and
  model. A weaker verdict from a small model beats no verdict.
- A specialist job (rerank) has no stand-in, because a chat model does not
  speak the rerank protocol. Blank makes it unavailable and its stage is
  skipped, which is exactly what the system did before the stage existed.

The portal lists the same roles in AdminSettingsController::MODEL_ROLES. Keep
the two in step.
"""
from dataclasses import dataclass

from database import provider_endpoint

GENERATIVE = ("sql", "intent", "guard")
SPECIALIST = ("rerank",)
ROLES = GENERATIVE + SPECIALIST


@dataclass(frozen=True)
class Endpoint:
    role: str
    base_url: str = ""
    api_key: str = ""
    model: str = ""
    # True only when Admin Settings named this role's endpoint. A borrowed or
    # empty endpoint is False, which is what a trace or a screen reports.
    configured: bool = False

    @property
    def available(self) -> bool:
        """Whether there is anything to call at all."""
        return bool(self.base_url and self.model)


def setting_keys(role: str) -> tuple[str, str, str]:
    return (f"{role}_model_base_url", f"{role}_model_api_key", f"{role}_model_name")


def endpoint_for(role: str, bot, settings: dict) -> Endpoint:
    if role not in ROLES:
        raise ValueError(f"Unknown model role: {role!r}")

    url_key, api_key_key, name_key = setting_keys(role)
    base_url = (settings.get(url_key) or "").strip()
    model = (settings.get(name_key) or "").strip()

    # Both halves, or neither. A URL with no model names nothing to call.
    if base_url and model:
        return Endpoint(role, base_url, settings.get(api_key_key) or "", model,
                        configured=True)

    if role in SPECIALIST:
        return Endpoint(role)

    bot_url, bot_key = provider_endpoint(bot)

    return Endpoint(role, bot_url, bot_key, bot.model_name or "")
```

- [ ] **Step 5: Run the new tests to verify they pass**

Run: `python -m pytest tests/test_roles.py -v`
Expected: 11 passed

- [ ] **Step 6: Move the SQL generator onto the resolver**

In `api-engine/dbquery/__init__.py`, replace line 16:

```python
from database import provider_endpoint
```

with:

```python
import roles
```

and replace `_sql_endpoint` (lines 104-114) with:

```python
def _sql_endpoint(bot, settings: dict) -> tuple[str, str, str]:
    """Where query work goes. Blank settings mean the bot's own model.

    roles.py owns that rule for every job; this keeps the tuple the generator
    and its tests already use.
    """
    endpoint = roles.endpoint_for("sql", bot, settings)

    return endpoint.base_url, endpoint.api_key, endpoint.model
```

- [ ] **Step 7: Run the whole engine suite**

Run: `python -m pytest`
Expected: all pass. In particular `tests/test_ai_provider.py::test_query_work_falls_back_to_the_bots_provider`, `::test_a_configured_sql_endpoint_still_wins_over_the_bots_provider` and `::test_a_bot_with_no_provider_yields_a_blank_endpoint_rather_than_raising` still pass, which proves blank behaviour did not change.

- [ ] **Step 8: Commit**

```bash
git add api-engine/roles.py api-engine/tests/test_roles.py api-engine/database.py api-engine/dbquery/__init__.py
git commit -m "feat: every job a model does resolves its endpoint in one place"
```

---

### Task 2: Record which model did each job

**Files:**
- Modify: `api-engine/roles.py` (append `model_trace`)
- Modify: `api-engine/tests/test_roles.py` (append trace tests)
- Modify: `api-engine/reasoning.py:126-171` (`TranscriptCollector`)
- Create: `api-engine/tests/test_transcript_model.py`
- Modify: `api-engine/database.py:119-135` (`ChatMessage`)
- Modify: `api-engine/routers/chat.py`
- Create: `admin-laravel/database/migrations/2026_09_15_000001_add_model_trace_to_chat_messages.php`
- Modify: `admin-laravel/app/Models/ChatMessage.php:16-27`
- Create: `admin-laravel/tests/Feature/TranscriptModelTraceTest.php`
- Modify: `admin-laravel/resources/views/logs/index.blade.php:251-254`

**Interfaces:**
- Consumes: `roles.endpoint_for` from Task 1
- Produces:
  - `roles.model_trace(chat_model: str | None, used: dict[str, str]) -> str | None`: JSON object text with sorted keys, e.g. `{"chat": "qwen3.5:4b", "sql": "qwen3-coder:30b"}`, or `None` when nothing is known
  - `TranscriptCollector.model -> str | None`
  - Column `chat_messages.model_trace` (text, nullable), fillable on the Laravel model, present in `logs.transcript` JSON. Plans 2 to 4 add `intent`, `rerank` and `guard` entries to `used`.

- [ ] **Step 1: Write the failing engine tests**

Add `import json` to the top of `api-engine/tests/test_roles.py`, beside `import pytest`, then append:

```python
def test_the_trace_names_the_chat_model_and_each_job_that_ran():
    trace = roles.model_trace("qwen3.5:4b", {"sql": "qwen3-coder:30b"})

    assert json.loads(trace) == {"chat": "qwen3.5:4b", "sql": "qwen3-coder:30b"}


def test_a_job_with_no_model_is_left_out_of_the_trace():
    trace = roles.model_trace("qwen3.5:4b", {"sql": "", "intent": ""})

    assert json.loads(trace) == {"chat": "qwen3.5:4b"}


def test_the_trace_is_stable_text():
    """Sorted keys, so two identical answers store identical text."""
    assert (roles.model_trace("a", {"sql": "b", "intent": "c"})
            == '{"chat": "a", "intent": "c", "sql": "b"}')


def test_nothing_known_is_no_trace_rather_than_an_empty_object():
    assert roles.model_trace(None, {}) is None
    assert roles.model_trace("", {"sql": ""}) is None
```

Create `api-engine/tests/test_transcript_model.py`:

```python
"""The collector keeps the model the endpoint said it used.

A bot configured for one model name can be served by another, an alias or a
quantised build, and the endpoint's own answer is the one worth recording.
"""
from reasoning import TranscriptCollector


def test_the_collector_keeps_the_model_the_endpoint_reported():
    collector = TranscriptCollector()
    collector.observe({"content": "Hello"})
    collector.observe({"meta": {"model": "qwen3.5:4b", "tokens_in": 10, "tokens_out": 2}})

    assert collector.model == "qwen3.5:4b"
    assert collector.tokens == 12


def test_the_model_is_none_when_no_meta_arrived():
    assert TranscriptCollector().model is None


def test_a_meta_event_without_a_model_does_not_erase_one():
    collector = TranscriptCollector()
    collector.observe({"meta": {"model": "qwen3.5:4b"}})
    collector.observe({"meta": {"tokens_in": 1}})

    assert collector.model == "qwen3.5:4b"
```

- [ ] **Step 2: Run them to verify they fail**

Run: `python -m pytest tests/test_roles.py tests/test_transcript_model.py -v`
Expected: FAIL with `AttributeError: module 'roles' has no attribute 'model_trace'` and `AttributeError: 'TranscriptCollector' object has no attribute 'model'`

- [ ] **Step 3: Implement the trace**

Add `import json` beside the existing imports at the top of `api-engine/roles.py`, then append:

```python
def model_trace(chat_model: str | None, used: dict[str, str]) -> str | None:
    """JSON naming the model behind each job that produced one answer.

    Once jobs run on different machines, "which model said that" stops having a
    single answer, and an operator auditing a wrong one needs all of them. Only
    jobs that ran are named. Nothing known is None, so the column stays empty
    rather than holding "{}".
    """
    trace = {role: model for role, model in used.items() if model}
    if chat_model:
        trace["chat"] = chat_model

    return json.dumps(trace, sort_keys=True) if trace else None
```

In `api-engine/reasoning.py`, inside `TranscriptCollector.__init__` add after `self._tokens = 0`:

```python
        self._model = None
```

In `observe`, replace the meta branch:

```python
        meta = payload.get("meta")
        if meta:
            self._tokens = int(meta.get("tokens_in", 0) or 0) + int(meta.get("tokens_out", 0) or 0)
            return
```

with:

```python
        meta = payload.get("meta")
        if meta:
            self._tokens = int(meta.get("tokens_in", 0) or 0) + int(meta.get("tokens_out", 0) or 0)
            # The endpoint's own name for the model, which can differ from the
            # bot's setting when an alias or a quantised build answers.
            self._model = meta.get("model") or self._model
            return
```

and add after the `tokens` property:

```python
    @property
    def model(self):
        """The model the endpoint reported, or None when it reported none."""
        return self._model
```

- [ ] **Step 4: Run the engine tests to verify they pass**

Run: `python -m pytest tests/test_roles.py tests/test_transcript_model.py -v`
Expected: all passed

- [ ] **Step 5: Write the failing portal test**

Create `admin-laravel/tests/Feature/TranscriptModelTraceTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptModelTraceTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    private function conversation(): void
    {
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Bot']);
        ChatConversation::create([
            'id' => 'conv_1', 'bot_id' => 'bot_1', 'session_id' => 's1', 'origin' => '',
        ]);
    }

    public function test_an_operator_can_see_which_model_did_each_job(): void
    {
        $user = $this->editor();
        $this->conversation();

        ChatMessage::create([
            'id' => 'msg_1', 'conversation_id' => 'conv_1', 'sender' => 'assistant',
            'content' => 'One order is still pending.',
            'model_trace' => '{"chat": "qwen3.5:4b", "sql": "qwen3-coder:30b"}',
        ]);

        $this->actingAs($user)
            ->get(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertJsonPath('messages.0.model_trace', '{"chat": "qwen3.5:4b", "sql": "qwen3-coder:30b"}');
    }

    public function test_an_older_message_carries_no_trace(): void
    {
        $user = $this->editor();
        $this->conversation();

        ChatMessage::create([
            'id' => 'msg_2', 'conversation_id' => 'conv_1', 'sender' => 'assistant',
            'content' => 'Refunds are within 30 days.',
        ]);

        $this->actingAs($user)
            ->get(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertJsonPath('messages.0.model_trace', null);
    }
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `php artisan test --filter=TranscriptModelTraceTest`
Expected: `test_an_operator_can_see_which_model_did_each_job` FAILS, because `model_trace` is not fillable and the column does not exist, so the JSON path is null.

- [ ] **Step 7: Add the column on both sides**

Create `admin-laravel/database/migrations/2026_09_15_000001_add_model_trace_to_chat_messages.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which model did each job behind an answer, as JSON such as
     * {"chat": "qwen3.5:4b", "sql": "qwen3-coder:30b"}. Once jobs can run on
     * different machines, "which model said that" stops having one answer.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->text('model_trace')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('model_trace');
        });
    }
};
```

In `admin-laravel/app/Models/ChatMessage.php`, add to `$fillable` after `'db_row_count',`:

```php
        // Which model did each job, as JSON. Null on messages written before
        // the column existed, and on visitor messages.
        'model_trace',
```

In `api-engine/database.py`, inside `ChatMessage` after `db_row_count`:

```python
    # JSON naming the model behind each job, written by roles.model_trace.
    model_trace = Column(Text, nullable=True)
```

- [ ] **Step 8: Run the portal test to verify it passes**

Run: `php artisan test --filter=TranscriptModelTraceTest`
Expected: 2 passed

- [ ] **Step 9: Write the trace from the chat route**

In `api-engine/routers/chat.py`, add `import roles` after `import sources`.

After `bot_base_url, bot_api_key = provider_endpoint(bot)` add:

```python
    # Resolved once, for the trace only. The database attempt resolves the same
    # endpoint for itself; this records which model that was.
    sql_model = roles.endpoint_for("sql", bot, engine_settings).model
```

In the `ChatMessage(...)` inside the `finally` block, add after `db_row_count=...`:

```python
                            # The model per job, so a wrong answer can be
                            # traced to the machine that gave it.
                            model_trace=roles.model_trace(
                                transcript.model or bot.model_name,
                                {"sql": sql_model} if (found and found.sql) else {}),
```

- [ ] **Step 10: Show the trace in the logs screen**

In `admin-laravel/resources/views/logs/index.blade.php`, replace the line:

```js
                    meta.textContent = (isUser ? 'visitor' : data.bot_name) + '  ' + (msg.created_at || 'just now');
```

with:

```js
                    // Which model did each job, when the engine recorded it.
                    // A trace that does not parse is left out, not shown raw.
                    var models = '';
                    if (!isUser && msg.model_trace) {
                        try {
                            var trace = JSON.parse(msg.model_trace);
                            models = Object.keys(trace).sort().map(function (job) {
                                return job + ' ' + trace[job];
                            }).join(' · ');
                        } catch (e) {
                            models = '';
                        }
                    }
                    meta.textContent = (isUser ? 'visitor' : data.bot_name) + '  ' + (msg.created_at || 'just now')
                        + (models ? '  ' + models : '');
```

- [ ] **Step 11: Run both suites**

Run from `api-engine/`: `python -m pytest`
Expected: all pass

Run from `admin-laravel/`: `php artisan migrate` then `php artisan test`
Expected: all pass

- [ ] **Step 12: Check it end to end**

Start the stack with `./start-dev.ps1`, open `demo/index.html`, and ask the demo bot one question. In the portal, open Logs, then the conversation. The bot message's meta line ends with `chat <model name>`. On a bot with database querying on, a question the database answers also shows `sql <model name>`.

- [ ] **Step 13: Commit**

```bash
git add api-engine/roles.py api-engine/tests/test_roles.py api-engine/reasoning.py api-engine/tests/test_transcript_model.py api-engine/database.py api-engine/routers/chat.py admin-laravel/database/migrations/2026_09_15_000001_add_model_trace_to_chat_messages.php admin-laravel/app/Models/ChatMessage.php admin-laravel/tests/Feature/TranscriptModelTraceTest.php admin-laravel/resources/views/logs/index.blade.php
git commit -m "feat: an answer remembers which model did each job"
```

---

### Task 3: A Models card for every role in Admin Settings

**Files:**
- Modify: `admin-laravel/app/Models/AppSetting.php:29-33`
- Modify: `admin-laravel/app/Http/Controllers/AdminSettingsController.php`
- Create: `admin-laravel/resources/views/admin/_model-role.blade.php`
- Modify: `admin-laravel/resources/views/admin/settings.blade.php:220-253` (the SQL model card)
- Create: `admin-laravel/tests/Feature/ModelRoleSettingsTest.php`
- Modify: `docs/architecture.md` (new section before "## AI providers")

**Interfaces:**
- Consumes: the key pattern and role names from Task 1
- Produces: `AdminSettingsController::MODEL_ROLES` (public const array keyed by role, each with `label`, `job`, `blank`, `placeholder`), and a `$modelRoles` view variable. Plans 5 and 6 add `judge` and `vision` here and in `roles.py`.

- [ ] **Step 0: Ask about Laravel Boost**

`admin-laravel/CLAUDE.md` asks for Laravel Boost before application changes, and it is not in `composer.json`. Ask the user whether to install it (`composer require laravel/boost --dev` then `php artisan boost:install`). Continue either way once they answer.

- [ ] **Step 1: Write the failing tests**

Create `admin-laravel/tests/Feature/ModelRoleSettingsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminSettingsController;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRoleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SUFFIXES = ['base_url', 'api_key', 'name'];

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin',
        ]);
    }

    public function test_the_roles_match_the_engine(): void
    {
        // api-engine/roles.py lists the same four. A role the portal does not
        // offer can never be configured; one the engine does not know is
        // silently ignored.
        $this->assertSame(['intent', 'sql', 'rerank', 'guard'],
            array_keys(AdminSettingsController::MODEL_ROLES));
    }

    public function test_every_role_defaults_to_blank(): void
    {
        // Blank is the supported default: nothing changes until an install
        // points a job somewhere.
        foreach (array_keys(AdminSettingsController::MODEL_ROLES) as $role) {
            foreach (self::SUFFIXES as $suffix) {
                $this->assertSame('', AppSetting::DEFAULTS["{$role}_model_{$suffix}"], "{$role}_model_{$suffix}");
            }
        }
    }

    public function test_the_settings_screen_offers_every_role(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Models');

        foreach (array_keys(AdminSettingsController::MODEL_ROLES) as $role) {
            $response->assertSee("{$role}_model_base_url");
            $response->assertSee("{$role}_model_name");
        }
    }

    public function test_a_role_can_be_saved(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), array_merge(
                AppSetting::DEFAULTS,
                [
                    'rerank_model_base_url' => 'http://localhost:8012/v1',
                    'rerank_model_name' => 'bge-reranker-v2-m3',
                ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('http://localhost:8012/v1', AppSetting::get('rerank_model_base_url'));
        $this->assertSame('bge-reranker-v2-m3', AppSetting::get('rerank_model_name'));
    }

    public function test_leaving_every_role_blank_is_accepted(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), AppSetting::DEFAULTS)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_an_overlong_model_name_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), array_merge(
                AppSetting::DEFAULTS, ['guard_model_name' => str_repeat('x', 256)]))
            ->assertSessionHasErrors('guard_model_name');
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --filter=ModelRoleSettingsTest`
Expected: FAIL. `Undefined constant App\Http\Controllers\AdminSettingsController::MODEL_ROLES`

- [ ] **Step 3: Add the defaults**

In `admin-laravel/app/Models/AppSetting.php`, after `'sql_model_name' => '',` add:

```php
        // The same shape for every other job a model does. Blank borrows each
        // bot's own model, except the reranker, which has no stand-in and is
        // skipped. api-engine/roles.py holds that rule.
        'intent_model_base_url' => '',
        'intent_model_api_key' => '',
        'intent_model_name' => '',
        'rerank_model_base_url' => '',
        'rerank_model_api_key' => '',
        'rerank_model_name' => '',
        'guard_model_base_url' => '',
        'guard_model_api_key' => '',
        'guard_model_name' => '',
```

- [ ] **Step 4: Build keys and rules from one list**

In `admin-laravel/app/Http/Controllers/AdminSettingsController.php`:

Replace the `KEYS` constant with:

```php
    private const KEYS = [
        'embedding_base_url', 'embedding_api_key', 'embedding_model',
        'embedding_dimensions', 'vector_driver', 'chunk_size', 'chunk_overlap',
        'context_char_budget',
        'web_search_provider', 'web_search_tavily_key', 'web_search_brave_key',
    ];

    /**
     * Every job a model does besides answering, in the order the screen lists
     * them. api-engine/roles.py holds the same names and decides what blank
     * falls back to; keep the two in step.
     */
    public const MODEL_ROLES = [
        'intent' => [
            'label' => 'Intent',
            'job' => 'Reads each message with the recent conversation, decides whether it needs facts, and rewrites a follow-up into a question that stands on its own.',
            'blank' => "each bot's own model",
            'placeholder' => 'qwen3.5:4b',
        ],
        'sql' => [
            'label' => 'SQL',
            'job' => 'Writes the query, and decides whether any table can answer a question at all. Small models are markedly weaker at SQL than at conversation.',
            'blank' => "each bot's own model",
            'placeholder' => 'qwen3-coder:30b',
        ],
        'rerank' => [
            'label' => 'Reranker',
            'job' => 'Scores each retrieved passage against the question. Needs an endpoint that serves /v1/rerank, such as vLLM or llama.cpp. Ollama does not.',
            'blank' => 'reranking is skipped',
            'placeholder' => 'bge-reranker-v2-m3',
        ],
        'guard' => [
            'label' => 'Guard',
            'job' => 'Checks what visitors send, and what bots answer, for harmful content.',
            'blank' => "each bot's own model",
            'placeholder' => 'qwen3guard-gen:0.6b',
        ],
    ];

    /** The stored keys, each role's three fields included. */
    private static function keys(): array
    {
        $keys = self::KEYS;

        foreach (array_keys(self::MODEL_ROLES) as $role) {
            array_push($keys, "{$role}_model_base_url", "{$role}_model_api_key", "{$role}_model_name");
        }

        return $keys;
    }

    private static function modelRoleRules(): array
    {
        $rules = [];

        foreach (array_keys(self::MODEL_ROLES) as $role) {
            $rules["{$role}_model_base_url"] = ['nullable', 'string', 'max:500'];
            $rules["{$role}_model_api_key"] = ['nullable', 'string', 'max:500'];
            $rules["{$role}_model_name"] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }
```

In `edit()`, change `foreach (self::KEYS as $key)` to `foreach (self::keys() as $key)`, and add to the view data after `'settings' => $settings,`:

```php
            'modelRoles' => self::MODEL_ROLES,
```

In `update()`, delete the three `sql_model_*` rule lines, wrap the rules array in `array_merge([...], self::modelRoleRules())` so the call reads `$request->validate(array_merge([ ...existing rules... ], self::modelRoleRules()), [ ...existing messages... ])`, and change the save loop to `foreach (self::keys() as $key)`.

- [ ] **Step 5: Write the role partial**

Create `admin-laravel/resources/views/admin/_model-role.blade.php`:

```blade
{{-- One job a model does: where its endpoint is, and what blank means. --}}
<div>
    <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-1">
        <span class="fw-semibold" style="font-size: 0.875rem;">{{ $meta['label'] }}</span>
        <span class="text-muted" style="font-size: 0.75rem;">Blank: {{ $meta['blank'] }}</span>
    </div>
    <p class="text-muted mb-2" style="font-size: 0.78rem;">{{ $meta['job'] }}</p>

    <div class="mb-2">
        <label for="{{ $role }}_model_base_url" class="form-label">Base URL</label>
        <input type="text" name="{{ $role }}_model_base_url" id="{{ $role }}_model_base_url"
               class="form-control font-monospace" placeholder="http://localhost:11434/v1"
               value="{{ old($role . '_model_base_url', $settings[$role . '_model_base_url']) }}">
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <label for="{{ $role }}_model_name" class="form-label">Model</label>
            <input type="text" name="{{ $role }}_model_name" id="{{ $role }}_model_name"
                   class="form-control font-monospace" placeholder="{{ $meta['placeholder'] }}"
                   value="{{ old($role . '_model_name', $settings[$role . '_model_name']) }}">
        </div>
        <div class="col-md-6">
            <label for="{{ $role }}_model_api_key" class="form-label">API key</label>
            <input type="password" name="{{ $role }}_model_api_key" id="{{ $role }}_model_api_key"
                   class="form-control font-monospace" autocomplete="off"
                   value="{{ old($role . '_model_api_key', $settings[$role . '_model_api_key']) }}">
        </div>
    </div>
</div>
```

- [ ] **Step 6: Replace the SQL card with the Models card**

In `admin-laravel/resources/views/admin/settings.blade.php`, replace the whole `<div class="card mb-3">` block whose header is `SQL model` (lines 220-253) with:

```blade
        <div class="card mb-3">
            <div class="card-header">Models</div>
            <div class="p-3">
                <p class="text-muted" style="font-size: 0.8rem;">
                    Which model does each job besides answering. Each takes an
                    OpenAI-compatible endpoint, so a job moves from this machine
                    to a server by changing its address. A job left blank falls
                    back as described beside it.
                </p>

                @foreach ($modelRoles as $role => $meta)
                    @include('admin._model-role', ['role' => $role, 'meta' => $meta, 'settings' => $settings])
                    @unless ($loop->last)
                        <hr class="my-3">
                    @endunless
                @endforeach
            </div>
        </div>
```

- [ ] **Step 7: Run the portal suite**

Run: `php artisan test`
Expected: all pass, including the existing `SqlModelSettingsTest` (its keys, defaults and field ids are unchanged) and `AdminSettingsTest`.

- [ ] **Step 8: Check the screen**

With the stack running, open Admin Settings as a super admin. The Models card lists Intent, SQL, Reranker and Guard, each with Base URL, Model and API key, and a "Blank:" note. Fill in the Reranker, save, reload, and confirm the values stayed and the SQL fields did not change.

- [ ] **Step 9: Document the roles**

In `docs/architecture.md`, insert before `## AI providers`:

```markdown
## Model roles

Answering is the bot's job, through the AI provider it points at. Every other
job a model does is a role, resolved in `api-engine/roles.py` and configured
install-wide in Admin Settings under Models.

| Role | Does | Blank means |
|---|---|---|
| `intent` | Decides whether a message needs facts; rewrites follow-ups | The bot's own model |
| `sql` | Writes the query, or declines | The bot's own model |
| `rerank` | Scores retrieved passages against the question | The stage is skipped |
| `guard` | Checks input and output for harmful content | The bot's own model |

**Blank reproduces the system before the role existed.** A generative job
borrows the bot's model, because a weaker verdict beats none. The reranker has
no stand-in, since a chat model does not speak the rerank protocol, so its
stage is skipped.

**Both halves or neither.** A base URL with no model name is not a configured
role.

**Every answer records its models.** `chat_messages.model_trace` holds JSON
naming the model behind each job that ran, shown on the logs screen beside the
message.

| Concern | Lives in |
|---|---|
| Role names and fallback rules | `api-engine/roles.py` |
| The settings screen's list of roles | `AdminSettingsController::MODEL_ROLES` |
| Writing the trace | `api-engine/routers/chat.py` |

---

```

- [ ] **Step 10: Commit**

```bash
git add admin-laravel/app/Models/AppSetting.php admin-laravel/app/Http/Controllers/AdminSettingsController.php admin-laravel/resources/views/admin/_model-role.blade.php admin-laravel/resources/views/admin/settings.blade.php admin-laravel/tests/Feature/ModelRoleSettingsTest.php docs/architecture.md
git commit -m "feat: admin settings lists every job a model does"
```
