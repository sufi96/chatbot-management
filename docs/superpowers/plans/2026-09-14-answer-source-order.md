# Answer Source Order Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an operator set, per bot, the order in which the knowledge base, the database and the web are consulted, and consult them in exactly that order until one has an answer.

**Architecture:** A new `sources` package in the engine holds a cascade that walks the bot's configured order, calls one attempt per source, and stops at the first with content. Each attempt wraps retrieval, SQL querying or web search that already exists. The model router that previously chose a single source is deleted; the order replaces it.

**Tech Stack:** Laravel 12 / PHP 8.4 (portal, PHPUnit), FastAPI / Python 3.12 (engine, pytest + pytest-asyncio), shared PostgreSQL 17.5.

**Spec:** `docs/superpowers/specs/2026-09-14-answer-source-order-design.md`

## Global Constraints

- Source tokens are exactly `documents`, `database`, `web`. No other spelling is stored or accepted.
- The default order is `documents,database,web`.
- A stored order is never trusted. Both codebases normalise before use: unknown tokens dropped, duplicates keep their first position, missing known tokens appended in default order.
- A source that is switched off is skipped without a call and does not end the cascade.
- A database query that ran is a hit **including when it returned no rows**. A query that could not be built, was rejected, declined, or failed is a miss.
- No source failure may break a conversation. Every attempt is wrapped; an exception is a miss.
- A database answer cites the connection name only. Table names and SQL never reach a visitor.
- `chat_messages.db_sql` and `chat_messages.db_row_count` must still be written when the database answered.
- Run the engine's tests with the project virtualenv: `api-engine/.venv/Scripts/python.exe -m pytest`.

---

### Task 1: The order column, and Laravel normalises it

**Files:**
- Create: `admin-laravel/database/migrations/2026_09_14_000003_add_source_order_to_bot_profiles.php`
- Create: `admin-laravel/app/Support/SourceOrder.php`
- Modify: `admin-laravel/app/Models/BotProfile.php` (add `source_order` to `$fillable`)
- Test: `admin-laravel/tests/Feature/SourceOrderTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Support\SourceOrder::SOURCES` (array), `::DEFAULT` (string), `::normalise(?string): string`, `::toList(?string): array`. `bot_profiles.source_order` column.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/SourceOrderTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Support\SourceOrder;
use Tests\TestCase;

class SourceOrderTest extends TestCase
{
    public function test_a_well_formed_order_is_kept_as_it_is(): void
    {
        $this->assertSame('database,documents,web',
            SourceOrder::normalise('database,documents,web'));
    }

    public function test_a_missing_source_is_appended_in_default_order(): void
    {
        $this->assertSame('web,documents,database', SourceOrder::normalise('web'));
    }

    public function test_an_unknown_token_is_dropped(): void
    {
        $this->assertSame('web,documents,database',
            SourceOrder::normalise('web,telepathy'));
    }

    public function test_a_duplicate_keeps_its_first_position(): void
    {
        $this->assertSame('web,documents,database',
            SourceOrder::normalise('web,web,web'));
    }

    public function test_an_empty_order_is_the_default(): void
    {
        $this->assertSame(SourceOrder::DEFAULT, SourceOrder::normalise(''));
        $this->assertSame(SourceOrder::DEFAULT, SourceOrder::normalise(null));
    }

    public function test_spacing_and_case_do_not_matter(): void
    {
        $this->assertSame('database,web,documents',
            SourceOrder::normalise(' Database , WEB '));
    }

    public function test_the_list_form_is_the_normalised_order(): void
    {
        $this->assertSame(['web', 'documents', 'database'], SourceOrder::toList('web'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin-laravel && php artisan test --filter=SourceOrderTest`
Expected: FAIL with `Class "App\Support\SourceOrder" not found`.

- [ ] **Step 3: Write the helper**

Create `admin-laravel/app/Support/SourceOrder.php`:

```php
<?php

namespace App\Support;

/**
 * The order a bot consults its sources in.
 *
 * Stored as one comma separated string because both this codebase and the
 * engine read it and neither needs to query into it. Never trusted to be well
 * formed: a hand written value, an older version's value and a mistyped one
 * all normalise rather than fail, so a bot can never end up with a source it
 * can never reach.
 */
class SourceOrder
{
    public const SOURCES = ['documents', 'database', 'web'];

    public const DEFAULT = 'documents,database,web';

    public static function normalise(?string $raw): string
    {
        $order = [];

        foreach (explode(',', (string) $raw) as $token) {
            $token = strtolower(trim($token));
            if (in_array($token, self::SOURCES, true) && !in_array($token, $order, true)) {
                $order[] = $token;
            }
        }

        // A token nobody listed is consulted last rather than not at all.
        foreach (self::SOURCES as $token) {
            if (!in_array($token, $order, true)) {
                $order[] = $token;
            }
        }

        return implode(',', $order);
    }

    /** The same order as a list, for a view that renders a row per source. */
    public static function toList(?string $raw): array
    {
        return explode(',', self::normalise($raw));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd admin-laravel && php artisan test --filter=SourceOrderTest`
Expected: PASS, 7 tests.

- [ ] **Step 5: Write the migration**

Create `admin-laravel/database/migrations/2026_09_14_000003_add_source_order_to_bot_profiles.php`:

```php
<?php

use App\Support\SourceOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which source a bot consults first. Existing bots get the order that
     * matches what they did before: their own documents, then live data,
     * then the open web.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('source_order', 64)->default(SourceOrder::DEFAULT);
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('source_order');
        });
    }
};
```

- [ ] **Step 6: Add the column to the model**

In `admin-laravel/app/Models/BotProfile.php`, add `'source_order',` to `$fillable` immediately after `'db_query_timeout',`.

- [ ] **Step 7: Add a test that the column defaults**

Append to `admin-laravel/tests/Feature/SourceOrderTest.php`, and add `use App\Models\BotProfile;`, `use App\Models\System;` and `use Illuminate\Foundation\Testing\RefreshDatabase;` at the top, with `use RefreshDatabase;` inside the class:

```php
    public function test_a_new_bot_consults_its_documents_first(): void
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $bot = BotProfile::create([
            'id' => 'bot_order', 'system_id' => 'sys_test', 'name' => 'Orderly',
        ]);

        $this->assertSame(SourceOrder::DEFAULT, $bot->fresh()->source_order);
    }
```

- [ ] **Step 8: Run the migration and the tests**

Run: `cd admin-laravel && php artisan migrate && php artisan test --filter=SourceOrderTest`
Expected: migration runs, 8 tests PASS.

- [ ] **Step 9: Commit**

```bash
git add admin-laravel/app/Support/SourceOrder.php admin-laravel/database/migrations/2026_09_14_000003_add_source_order_to_bot_profiles.php admin-laravel/app/Models/BotProfile.php admin-laravel/tests/Feature/SourceOrderTest.php
git commit -m "feat: a bot records which source it consults first"
```

---

### Task 2: The engine reads and normalises the order

**Files:**
- Create: `api-engine/sources/__init__.py`
- Create: `api-engine/sources/order.py`
- Modify: `api-engine/database.py` (add `source_order` to `BotProfile`)
- Test: `api-engine/tests/test_source_order.py`

**Interfaces:**
- Consumes: `bot_profiles.source_order` from Task 1.
- Produces: `sources.order.SOURCES` (tuple), `sources.order.DEFAULT` (str), `sources.order.normalise(raw: str | None) -> list[str]`.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_source_order.py`:

```python
"""The order a bot consults its sources in, as the engine reads it.

Normalised on read as well as on save, because the column is also written by
migrations and by hand.
"""
from sources.order import DEFAULT, SOURCES, normalise


def test_a_well_formed_order_is_kept_as_it_is():
    assert normalise("database,documents,web") == ["database", "documents", "web"]


def test_a_missing_source_is_appended_in_default_order():
    assert normalise("web") == ["web", "documents", "database"]


def test_an_unknown_token_is_dropped():
    assert normalise("web,telepathy") == ["web", "documents", "database"]


def test_a_duplicate_keeps_its_first_position():
    assert normalise("web,web,web") == ["web", "documents", "database"]


def test_an_empty_order_is_the_default():
    assert normalise("") == list(SOURCES)
    assert normalise(None) == list(SOURCES)


def test_spacing_and_case_do_not_matter():
    assert normalise(" Database , WEB ") == ["database", "web", "documents"]


def test_the_default_string_matches_the_default_order():
    assert normalise(DEFAULT) == list(SOURCES)
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest tests/test_source_order.py -q`
Expected: FAIL with `ModuleNotFoundError: No module named 'sources'`.

- [ ] **Step 3: Write the module**

Create `api-engine/sources/__init__.py` as an empty file for now.

Create `api-engine/sources/order.py`:

```python
"""The order a bot consults its sources in.

The same rule as the portal's SourceOrder helper, and deliberately so: a value
either side writes is one the other can read. Normalising rather than rejecting
means a token added in a later version is consulted last on an older engine
instead of breaking it.
"""

SOURCES = ("documents", "database", "web")

DEFAULT = ",".join(SOURCES)


def normalise(raw: str | None) -> list[str]:
    order: list[str] = []

    for token in (raw or "").split(","):
        token = token.strip().lower()
        if token in SOURCES and token not in order:
            order.append(token)

    # A token nobody listed is consulted last rather than not at all.
    for token in SOURCES:
        if token not in order:
            order.append(token)

    return order
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest tests/test_source_order.py -q`
Expected: PASS, 7 tests.

- [ ] **Step 5: Add the column to the engine model**

In `api-engine/database.py`, in `class BotProfile`, immediately after the `db_query_timeout` column, add:

```python
    # Which source answers first. Read through sources.order.normalise, never
    # raw, because a hand written value must not make a source unreachable.
    source_order = Column(String(64), default="documents,database,web")
```

- [ ] **Step 6: Run the whole engine suite**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest -q`
Expected: PASS, all existing tests plus the 7 new ones.

- [ ] **Step 7: Commit**

```bash
git add api-engine/sources/ api-engine/database.py api-engine/tests/test_source_order.py
git commit -m "feat: the engine reads a bot's source order"
```

---

### Task 3: The database step declines a question it cannot answer

**Files:**
- Modify: `api-engine/dbquery/result.py` (add `DbAnswer`)
- Modify: `api-engine/dbquery/__init__.py` (`GENERATION_PROMPT`, and `route_and_query` becomes `answer`)
- Test: `api-engine/tests/test_dbquery_run.py`

**Interfaces:**
- Consumes: `sources.order` from Task 2 (not directly; this task is independent of it).
- Produces: `dbquery.result.DbAnswer(ran: bool, context_block: str, sql: str, row_count: int, connection_name: str)` and `dbquery.answer(session, bot, message, settings, complete=None, portal_call=None, load_schema=None) -> DbAnswer`.

`dbquery.Outcome` and `dbquery.route_and_query` no longer exist after this task.

- [ ] **Step 1: Adapt the existing test harness**

`api-engine/tests/test_dbquery_run.py` already has the stubs this task needs: `FakeBot`, `answers(*replies)`, `portal_returning(result, complaint="")`, `schema_loader(...)`, `ROWS`, `SETTINGS` and a `run(bot=None, replies=("database",), portal=None, tables=None)` helper.

That harness is built around the router: `replies` begins with the routing verdict, and `run` calls `route_and_query`. Change it so `replies` holds only generation replies and it calls the new function:

```python
async def run(bot=None, replies=("SELECT id FROM orders",), portal=None, tables=None):
    return await dbquery.answer(
        None, bot or FakeBot(), "how many orders?", SETTINGS,
        complete=answers(*replies),
        portal_call=portal or portal_returning(ROWS),
        load_schema=schema_loader(tables=tables))
```

Match the existing `run` body for any argument it passes that is not shown here.

Then delete the five tests whose only subject was a verdict, because nothing routes any more:

- `test_a_database_verdict_queries_and_returns_a_block` — rewrite as `test_a_question_the_tables_can_answer_is_queried`, asserting `result.ran is True` and a non-empty `context_block`
- `test_a_documents_verdict_does_not_query` — delete
- `test_a_web_verdict_does_not_query` — delete
- `test_a_none_verdict_answers_from_nothing` — delete
- `test_an_unparseable_verdict_falls_through_to_documents` — delete
- `test_an_empty_router_answer_falls_through_to_documents` — delete

Keep every other test in the file, changing only `outcome.verdict == "database"` to `result.ran is True` and `outcome.verdict == "documents"` to `result.ran is False`.

- [ ] **Step 2: Write the failing tests**

Append to `api-engine/tests/test_dbquery_run.py`, using the harness above:

```python
EMPTY = QueryResult(columns=["id"], rows=[], row_count=0, elapsed_ms=2)


def portal_that_must_not_be_called():
    async def portal(**kwargs):
        raise AssertionError("a declined question must not reach the portal")
    return portal


@pytest.mark.asyncio
async def test_a_question_no_table_can_answer_is_declined():
    """Ordering the database first would otherwise make every policy question
    generate SQL, find no rows, and stop before reaching the documents.
    """
    result = await run(replies=("NO_QUERY",), portal=portal_that_must_not_be_called())

    assert result.ran is False
    assert result.sql == ""


@pytest.mark.asyncio
async def test_a_decline_is_not_argued_with():
    """A rejected statement is retried once. A decline is not a rejection, and
    asking again would spend a second call to be told the same thing.
    """
    calls = []

    async def counting(**kwargs):
        calls.append(kwargs)
        return "NO_QUERY"

    result = await dbquery.answer(
        None, FakeBot(), "what is your refund policy?", SETTINGS,
        complete=counting, portal_call=portal_that_must_not_be_called(),
        load_schema=schema_loader())

    assert result.ran is False
    assert len(calls) == 1


@pytest.mark.asyncio
async def test_a_decline_is_recognised_through_a_markdown_fence():
    result = await run(replies=("```\nNO_QUERY\n```",),
                       portal=portal_that_must_not_be_called())

    assert result.ran is False


@pytest.mark.asyncio
async def test_a_query_that_ran_with_no_rows_is_still_an_answer():
    """No projek in Selangor is a fact about the operator's data. Treating it
    as a miss would replace it with a stranger's guess from the web.
    """
    result = await run(replies=("SELECT id FROM orders",),
                       portal=portal_returning(EMPTY))

    assert result.ran is True
    assert result.row_count == 0


@pytest.mark.asyncio
async def test_a_portal_failure_is_not_an_answer():
    result = await run(replies=("SELECT id FROM orders",),
                       portal=portal_returning(None, "the portal is down"))

    assert result.ran is False
```

`EMPTY` sits beside the existing `ROWS` constant. If `portal_returning` does not already accept `None` as its result, widen it so it returns `(None, complaint)` in that case.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest tests/test_dbquery_run.py -q`
Expected: FAIL with `AttributeError: module 'dbquery' has no attribute 'answer'`.

- [ ] **Step 4: Add the DbAnswer dataclass**

Append to `api-engine/dbquery/result.py`:

```python
@dataclass
class DbAnswer:
    """What one attempt at the database produced.

    `ran` is the whole distinction the cascade turns on. A query that ran is an
    answer about the operator's data whether it found rows or not. A query that
    was never built, was rejected, was declined or failed is not an answer about
    anything, and the next source gets its turn.
    """
    ran: bool = False
    context_block: str = ""
    sql: str = ""
    row_count: int = 0
    connection_name: str = ""
```

Ensure `from dataclasses import dataclass` is imported at the top of that file.

- [ ] **Step 5: Teach the generation prompt to decline**

In `api-engine/dbquery/__init__.py`, replace the `GENERATION_PROMPT` constant with:

```python
GENERATION_PROMPT = """You write one read-only SQL query for a {driver} database.

{schema}

Rules:
- Reply with the SQL statement and nothing else. No explanation, no markdown fence.
- Only SELECT. Never INSERT, UPDATE, DELETE, or anything that changes data.
- Only the tables listed above may be named.
- Prefer the columns whose descriptions match what was asked for.
- If no table listed above can answer the question, reply exactly NO_QUERY and nothing else."""

DECLINED = "NO_QUERY"
```

- [ ] **Step 6: Replace route_and_query with answer**

In `api-engine/dbquery/__init__.py`: delete the `Outcome` dataclass, add `from dbquery.result import DbAnswer` to the imports, and replace `route_and_query` with:

```python
async def answer(session, bot, message: str, settings: dict, complete=None,
                 portal_call=None, load_schema=None) -> DbAnswer:
    """One attempt at the database, and whether it produced an answer.

    It no longer decides whether it should be asked. The bot's source order
    decides that, and this reports back only whether it managed to answer, so
    the cascade knows whether to move on.
    """
    complete = complete or LLMAdapter.complete
    portal_call = portal_call or portal_module.run_query
    loader = load_schema or load_bot_schema

    if not bot.db_query_enabled:
        return DbAnswer()

    connection_id, connection_name, driver, tables = await loader(session, bot.id)
    if not tables:
        return DbAnswer()

    base_url, api_key, model = _sql_endpoint(bot, settings)

    from config import settings as engine_settings

    generation_prompt = GENERATION_PROMPT.format(
        driver=DIALECTS.get(driver, "SQL"), schema=build_schema_block(tables))
    allowed = allowed_names(tables)
    max_rows = int(bot.db_max_rows or 50)

    statement = ""
    complaint = ""
    for attempt in range(2):
        prompt = generation_prompt if attempt == 0 else (
            f"{generation_prompt}\n\nYour last statement was rejected: {complaint}\n"
            "Write a statement that obeys the rules.")

        raw = _unfence(await complete(
            base_url=base_url, api_key=api_key, model_name=model,
            system_prompt=prompt, user_message=message))

        # A decline is not a rejected statement, so it is not argued with.
        if raw.strip().upper() == DECLINED:
            return DbAnswer()

        checked = sql_module.validate(raw, allowed, driver, max_rows)

        if checked.ok:
            statement = checked.sql
            break

        complaint = checked.complaint
        print(f"[DbQuery] Statement rejected: {complaint}")

    if not statement:
        return DbAnswer()

    result, failure = await portal_call(
        base_url=engine_settings.PORTAL_BASE_URL,
        token=engine_settings.PORTAL_INTERNAL_TOKEN,
        connection_id=connection_id, sql=statement,
        max_rows=max_rows, timeout=int(bot.db_query_timeout or 10))

    if result is None:
        print(f"[DbQuery] Query failed, answering without it: {failure}")
        return DbAnswer()

    trimmed = row_context.fit_rows_to_budget(
        result, int(settings.get("context_char_budget", 6000)))

    return DbAnswer(
        ran=True,
        context_block=row_context.build_database_context_block(
            connection_name, trimmed, date.today().isoformat()),
        sql=statement,
        row_count=result.row_count,
        connection_name=connection_name,
    )
```

Delete the now unused `from dbquery import routing` import and the `web_enabled` local.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest tests/test_dbquery_run.py -q`
Expected: PASS. Tests elsewhere that import `Outcome` or `route_and_query` will fail; Task 6 replaces them. Note which files fail and move on.

- [ ] **Step 8: Commit**

```bash
git add api-engine/dbquery/
git commit -m "feat: the database step answers, or declines, but no longer routes"
```

---

### Task 4: A result, and one attempt per source

**Files:**
- Create: `api-engine/sources/result.py`
- Create: `api-engine/sources/attempts.py`
- Test: `api-engine/tests/test_source_attempts.py`

**Interfaces:**
- Consumes: `dbquery.answer` and `DbAnswer` from Task 3.
- Produces: `sources.result.SourceResult(kind, context_block, citations, sql, row_count, has_content)`; `sources.attempts.documents(session, bot, message, settings, collection_ids) -> SourceResult`, `sources.attempts.database(session, bot, message, settings) -> SourceResult`, `sources.attempts.web(bot, message, settings) -> SourceResult`.

- [ ] **Step 1: Write the result module**

Create `api-engine/sources/result.py`:

```python
"""What one source produced, in the one shape the chat route reads.

Kept free of project imports so dbquery and websearch can be translated into it
without either importing the cascade that calls them.
"""
from dataclasses import dataclass, field


@dataclass
class SourceResult:
    kind: str
    context_block: str = ""
    citations: list = field(default_factory=list)
    # The database fills these for the transcript. An operator auditing a wrong
    # answer needs the statement, not a guess at it.
    sql: str = ""
    row_count: int = 0
    # Set by the attempt that built this, never derived from the block being
    # non-empty: a query that ran and found nothing has an empty block and is
    # still an answer.
    has_content: bool = False
```

- [ ] **Step 2: Write the failing tests**

Create `api-engine/tests/test_source_attempts.py`:

```python
"""One attempt per source, each reporting whether it answered.

Every attempt is driven through injected callables, so none of this needs a
model, a database or an HTTP request.
"""
import pytest

from dbquery.result import DbAnswer
from sources import attempts
from sources.result import SourceResult


class FakeBot:
    id = "bot_1"
    retrieval_enabled = True
    retrieval_mode = "hybrid"
    retrieval_top_k = 5
    retrieval_candidates = 30
    retrieval_min_score = 0.0
    db_query_enabled = True
    db_max_rows = 50
    db_query_timeout = 10
    web_search_enabled = True
    web_search_max_results = 3
    web_search_country = None


class FakeChunk:
    def __init__(self, source_id, text):
        self.source_id = source_id
        self.text = text


SETTINGS = {"context_char_budget": 6000, "web_search_provider": "duckduckgo"}


@pytest.mark.asyncio
async def test_documents_with_passages_is_an_answer():
    async def retrieve(**kwargs):
        return [FakeChunk("src_1", "Remote work is allowed on Fridays.")]

    async def titles(session, chunks):
        return {"src_1": "Hybrid Work Policy"}

    result = await attempts.documents(
        None, FakeBot(), "can I work from home?", SETTINGS, ["col_1"],
        retrieve=retrieve, load_titles=titles)

    assert result.has_content is True
    assert result.kind == "documents"
    assert result.citations == [
        {"n": 1, "title": "Hybrid Work Policy", "source_id": "src_1"}]


@pytest.mark.asyncio
async def test_documents_with_no_passages_is_not_an_answer():
    async def retrieve(**kwargs):
        return []

    async def titles(session, chunks):
        raise AssertionError("nothing retrieved means no titles to look up")

    result = await attempts.documents(
        None, FakeBot(), "anything", SETTINGS, ["col_1"],
        retrieve=retrieve, load_titles=titles)

    assert result.has_content is False


@pytest.mark.asyncio
async def test_a_database_query_that_ran_is_an_answer_and_cites_the_connection():
    async def ask(session, bot, message, settings):
        return DbAnswer(ran=True, context_block="rows here", sql="SELECT 1",
                        row_count=4, connection_name="Shop database")

    result = await attempts.database(None, FakeBot(), "how many?", SETTINGS, ask=ask)

    assert result.has_content is True
    assert result.sql == "SELECT 1"
    assert result.row_count == 4
    # The connection's name and nothing else. A visitor must not learn the
    # table names, let alone the statement.
    assert result.citations == [{"n": 1, "title": "Shop database"}]


@pytest.mark.asyncio
async def test_a_database_query_that_did_not_run_is_not_an_answer():
    async def ask(session, bot, message, settings):
        return DbAnswer(ran=False)

    result = await attempts.database(None, FakeBot(), "how many?", SETTINGS, ask=ask)

    assert result.has_content is False


@pytest.mark.asyncio
async def test_web_results_are_an_answer_and_carry_their_links():
    class FakeResult:
        def __init__(self, title, url):
            self.title = title
            self.url = url
            self.snippet = "..."

    async def search(**kwargs):
        return [FakeResult("Weather today", "https://example.test/weather")]

    result = await attempts.web(FakeBot(), "weather?", SETTINGS, search=search)

    assert result.has_content is True
    assert result.citations == [
        {"n": 1, "title": "Weather today", "url": "https://example.test/weather"}]


@pytest.mark.asyncio
async def test_no_web_results_is_not_an_answer():
    async def search(**kwargs):
        return []

    result = await attempts.web(FakeBot(), "weather?", SETTINGS, search=search)

    assert result.has_content is False
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest tests/test_source_attempts.py -q`
Expected: FAIL with `ImportError: cannot import name 'attempts' from 'sources'`.

- [ ] **Step 4: Write the attempts**

Create `api-engine/sources/attempts.py`:

```python
"""One attempt per source.

Each wraps work that already existed and answers one question the cascade asks:
did you produce something worth answering from. The dependencies are injected so
the branching can be tested without a model, a database or an HTTP request, the
way should_retrieve already is.
"""
from sqlalchemy import select

import dbquery
import websearch
from kb.retrieval import build_context_block, fit_to_budget, retrieve_for_collections
from sources.result import SourceResult
from websearch.context import build_web_context_block, fit_results_to_budget


def key_for(settings: dict) -> str:
    """The API key belonging to whichever provider is configured.

    DuckDuckGo has no key setting, so this returns an empty string for it,
    which is exactly what the adapter expects.
    """
    provider = settings.get("web_search_provider", "duckduckgo")

    return settings.get(f"web_search_{provider}_key", "")


async def _titles_for(session, chunks) -> dict:
    from database import KbSource

    rows = await session.execute(
        select(KbSource.id, KbSource.title).where(
            KbSource.id.in_([chunk.source_id for chunk in chunks])))

    return {row[0]: row[1] for row in rows.all()}


async def documents(session, bot, message: str, settings: dict, collection_ids,
                    retrieve=None, load_titles=None) -> SourceResult:
    retrieve = retrieve or retrieve_for_collections
    load_titles = load_titles or _titles_for

    chunks = await retrieve(
        session=session, collection_ids=collection_ids, query=message,
        mode=bot.retrieval_mode or "hybrid",
        top_k=bot.retrieval_top_k or 5,
        candidates=bot.retrieval_candidates or 30,
        min_score=bot.retrieval_min_score or 0.0)

    # Bigger chunks mean a bigger prompt. Trim before the titles are looked up
    # so the citations match what the model actually saw.
    chunks = fit_to_budget(chunks, int(settings["context_char_budget"]))

    if not chunks:
        return SourceResult(kind="documents")

    titles = await load_titles(session, chunks)

    return SourceResult(
        kind="documents",
        context_block=build_context_block(chunks, titles),
        citations=[{"n": i + 1, "title": titles.get(chunk.source_id, "Untitled"),
                    "source_id": chunk.source_id}
                   for i, chunk in enumerate(chunks)],
        has_content=True)


async def database(session, bot, message: str, settings: dict, ask=None) -> SourceResult:
    ask = ask or dbquery.answer

    found = await ask(session, bot, message, settings)

    if not found.ran:
        return SourceResult(kind="database")

    return SourceResult(
        kind="database",
        context_block=found.context_block,
        # The connection's name and nothing else. A visitor on a public site
        # must not learn the table names, let alone the statement.
        citations=[{"n": 1, "title": found.connection_name}],
        sql=found.sql,
        row_count=found.row_count,
        has_content=True)


async def web(bot, message: str, settings: dict, search=None) -> SourceResult:
    search = search or websearch.search

    results = await search(
        provider=settings["web_search_provider"], query=message,
        count=int(bot.web_search_max_results or 3),
        country=bot.web_search_country, api_key=key_for(settings))

    results = fit_results_to_budget(results, int(settings["context_char_budget"]))

    if not results:
        return SourceResult(kind="web")

    return SourceResult(
        kind="web",
        context_block=build_web_context_block(results),
        citations=[{"n": i + 1, "title": item.title, "url": item.url}
                   for i, item in enumerate(results)],
        has_content=True)
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest tests/test_source_attempts.py -q`
Expected: PASS, 6 tests.

If `retrieve_for_collections` rejects being called with keyword arguments, adjust the call in `documents` to positional form matching its signature at `kb/retrieval.py:29`, and adjust the fake in the test to match.

- [ ] **Step 6: Commit**

```bash
git add api-engine/sources/ api-engine/tests/test_source_attempts.py
git commit -m "feat: one attempt per source, each saying whether it answered"
```

---

### Task 5: The cascade

**Files:**
- Modify: `api-engine/sources/__init__.py`
- Test: `api-engine/tests/test_source_cascade.py`

**Interfaces:**
- Consumes: `sources.order.normalise`, `sources.result.SourceResult` from Tasks 2 and 4.
- Produces: `sources.resolve(order: list[str], enabled: dict[str, bool], attempts: dict[str, Callable]) -> SourceResult | None`, `sources.enabled_for(bot) -> dict[str, bool]`, `sources.fallback_for(bot, message_is_a_question: bool, enabled: dict) -> str`, `sources.build_attempts(session, bot, message, settings, collection_ids) -> dict[str, Callable]`.

- [ ] **Step 1: Write the failing tests**

Create `api-engine/tests/test_source_cascade.py`:

```python
"""Walking a bot's source order until one of them answers.

Pure orchestration: the attempts are callables the test supplies, so none of
this needs a model, a database or an HTTP request.
"""
import pytest

import sources
from sources.result import SourceResult


def hit(kind):
    async def attempt():
        return SourceResult(kind=kind, context_block=f"{kind} block", has_content=True)
    return attempt


def miss(kind, log=None):
    async def attempt():
        if log is not None:
            log.append(kind)
        return SourceResult(kind=kind)
    return attempt


def never(kind):
    async def attempt():
        raise AssertionError(f"{kind} should not have been consulted")
    return attempt


ALL_ON = {"documents": True, "database": True, "web": True}


@pytest.mark.asyncio
async def test_the_first_source_with_content_answers():
    result = await sources.resolve(
        ["documents", "database", "web"], ALL_ON,
        {"documents": hit("documents"), "database": never("database"),
         "web": never("web")})

    assert result.kind == "documents"


@pytest.mark.asyncio
async def test_the_configured_order_is_followed_not_the_default():
    result = await sources.resolve(
        ["web", "database", "documents"], ALL_ON,
        {"documents": never("documents"), "database": never("database"),
         "web": hit("web")})

    assert result.kind == "web"


@pytest.mark.asyncio
@pytest.mark.parametrize("order", [
    ["documents", "database", "web"],
    ["documents", "web", "database"],
    ["database", "documents", "web"],
    ["database", "web", "documents"],
    ["web", "documents", "database"],
    ["web", "database", "documents"],
])
async def test_whichever_source_is_first_is_the_one_consulted(order):
    """All six permutations, because "the order is followed" is the whole
    feature and one example of it proves only that one example works.
    """
    first = order[0]
    attempts = {name: (hit(name) if name == first else never(name)) for name in order}

    result = await sources.resolve(order, ALL_ON, attempts)

    assert result.kind == first


@pytest.mark.asyncio
async def test_a_miss_moves_on_to_the_next_source_in_order():
    log = []

    result = await sources.resolve(
        ["documents", "database", "web"], ALL_ON,
        {"documents": miss("documents", log), "database": hit("database"),
         "web": never("web")})

    assert log == ["documents"]
    assert result.kind == "database"


@pytest.mark.asyncio
async def test_a_source_switched_off_is_skipped_without_being_called():
    result = await sources.resolve(
        ["documents", "database", "web"],
        {"documents": False, "database": True, "web": True},
        {"documents": never("documents"), "database": hit("database"),
         "web": never("web")})

    assert result.kind == "database"


@pytest.mark.asyncio
async def test_a_source_that_raises_is_a_miss_and_the_cascade_continues():
    async def explode():
        raise RuntimeError("the database is on fire")

    result = await sources.resolve(
        ["database", "web"], ALL_ON,
        {"database": explode, "web": hit("web")})

    assert result.kind == "web"


@pytest.mark.asyncio
async def test_every_source_missing_answers_with_nothing():
    result = await sources.resolve(
        ["documents", "database", "web"], ALL_ON,
        {"documents": miss("documents"), "database": miss("database"),
         "web": miss("web")})

    assert result is None


@pytest.mark.asyncio
async def test_every_source_switched_off_answers_with_nothing():
    result = await sources.resolve(
        ["documents", "database", "web"],
        {"documents": False, "database": False, "web": False},
        {"documents": never("documents"), "database": never("database"),
         "web": never("web")})

    assert result is None


def test_which_switch_governs_which_source():
    class Bot:
        retrieval_enabled = True
        db_query_enabled = False
        web_search_enabled = True

    assert sources.enabled_for(Bot()) == {
        "documents": True, "database": False, "web": True}


class FallbackBot:
    retrieval_fallback = "say_unknown"


def test_a_question_that_found_nothing_is_told_so():
    assert sources.fallback_for(FallbackBot(), True, {"documents": True}) == "say_unknown"


def test_a_greeting_is_never_told_the_answer_is_missing():
    # A gated message must never be told the answer is missing from the
    # material. A greeting gets a greeting.
    assert sources.fallback_for(FallbackBot(), False, {"documents": True}) == "answer_anyway"


def test_a_bot_with_no_source_at_all_is_never_told_the_answer_is_missing():
    # There was no material to be missing from.
    assert sources.fallback_for(
        FallbackBot(), True,
        {"documents": False, "database": False, "web": False}) == "answer_anyway"
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest tests/test_source_cascade.py -q`
Expected: FAIL with `AttributeError: module 'sources' has no attribute 'resolve'`.

- [ ] **Step 3: Write the cascade**

Replace the contents of `api-engine/sources/__init__.py` with:

```python
"""Consulting a bot's sources in the order its operator set.

There is no router here on purpose. A model that picks the source is right most
of the time and unpredictable the rest, and an operator cannot configure it,
predict it, or explain an answer that came from the wrong place. An order can be
held in a person's head. See
docs/superpowers/specs/2026-09-14-answer-source-order-design.md section 4.
"""
from functools import partial

from sources import attempts as attempt_module
from sources.order import normalise  # noqa: F401  re-exported for the chat route
from sources.result import SourceResult


def enabled_for(bot) -> dict[str, bool]:
    """Which sources this bot has switched on at all.

    A connection that is missing or has no readable tables is not checked here.
    The database attempt discovers that and reports a miss, which is the same
    outcome by a cheaper route.
    """
    return {
        "documents": bool(bot.retrieval_enabled),
        "database": bool(bot.db_query_enabled),
        "web": bool(bot.web_search_enabled),
    }


def fallback_for(bot, message_is_a_question: bool, enabled: dict) -> str:
    """What the prompt should tell the model to do when nothing was found.

    A gated message must never be told the answer is missing from the material,
    and neither must a bot that was never given material to look in. Both get a
    plain answer instead.
    """
    if not message_is_a_question or not any(enabled.values()):
        return "answer_anyway"

    return bot.retrieval_fallback or "say_unknown"


def build_attempts(session, bot, message: str, settings: dict, collection_ids) -> dict:
    return {
        "documents": partial(attempt_module.documents, session, bot, message,
                             settings, collection_ids),
        "database": partial(attempt_module.database, session, bot, message, settings),
        "web": partial(attempt_module.web, bot, message, settings),
    }


async def resolve(order: list[str], enabled: dict, attempts: dict) -> SourceResult | None:
    """The first source in the order with something to say, or nothing.

    A source that fails is a source that did not answer. The next one in the
    operator's order gets its turn, so the order is honoured in failure as well
    as in success and no source failure can break a conversation.
    """
    for name in order:
        if not enabled.get(name):
            continue

        attempt = attempts.get(name)
        if attempt is None:
            continue

        try:
            result = await attempt()
        except Exception as error:
            print(f"[Sources] {name} failed, trying the next: {error}")
            continue

        if result and result.has_content:
            return result

    return None
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest tests/test_source_cascade.py -q`
Expected: PASS, 17 collected (the parametrised order test counts as six).

- [ ] **Step 5: Commit**

```bash
git add api-engine/sources/__init__.py api-engine/tests/test_source_cascade.py
git commit -m "feat: sources are consulted in the order the operator set"
```

---

### Task 6: The chat route calls the cascade, and the router is deleted

**Files:**
- Modify: `api-engine/routers/chat.py`
- Delete: `api-engine/dbquery/routing.py`, `api-engine/tests/test_dbquery_routing.py`
- Delete: `api-engine/websearch/gating.py`, `api-engine/tests/test_websearch_gating.py`
- Modify: `api-engine/tests/test_chat_database.py`, `api-engine/tests/test_chat_web_search.py`

**Interfaces:**
- Consumes: `sources.resolve`, `sources.enabled_for`, `sources.build_attempts`, `sources.normalise` from Task 5.
- Produces: nothing further.

- [ ] **Step 1: Replace the orchestration in the chat route**

In `api-engine/routers/chat.py`:

Delete the module-level helpers `key_for`, `context_for` and `sources_payload_for` — `key_for` now lives in `sources/attempts.py` and the other two are replaced by `SourceResult`.

Replace these imports:

```python
import dbquery
from kb.gating import should_retrieve
from kb.retrieval import (augment_system_prompt, build_context_block,
                          fit_to_budget, retrieve_for_collections)
from llm_adapter import LLMAdapter
import websearch
from websearch.context import build_web_context_block, fit_results_to_budget
from websearch.gating import web_search_runs
```

with:

```python
import sources
from kb.gating import should_retrieve
from kb.retrieval import augment_system_prompt
from llm_adapter import LLMAdapter
```

Then replace everything from the comment `# Retrieval runs before the model call.` down to and including the `final_prompt = ...` line with:

```python
    # A greeting is not a question. Skipping saves an embedding call, a
    # statement and a search, and stops five irrelevant passages reaching the
    # model.
    message_is_a_question = should_retrieve(req.message)

    engine_settings = await get_settings(db)
    enabled = sources.enabled_for(bot)

    # The sources are consulted in the operator's order until one of them has
    # something. Nothing here decides which source suits the question; that was
    # a router, and an order is what an operator can actually configure.
    found = None
    if message_is_a_question and any(enabled.values()):
        rows = await db.execute(
            select(BotKbCollection.collection_id).where(BotKbCollection.bot_id == bot.id))
        collection_ids = list(rows.scalars().all())

        found = await sources.resolve(
            sources.normalise(bot.source_order),
            enabled,
            sources.build_attempts(db, bot, req.message, engine_settings, collection_ids))

    context_block = found.context_block if found else ""
    fallback = sources.fallback_for(bot, message_is_a_question, enabled)

    final_prompt = augment_system_prompt(bot.system_prompt or "", context_block, fallback)
```

In `sse_event_stream`, replace the `sources_payload` block with:

```python
        # Sent before the tokens so the widget can name its sources. An older
        # widget ignores an event type it does not know. A web source carries a
        # url the widget turns into a link; a document and a database answer do
        # not, and the widget draws those as plain text already.
        if found and found.citations:
            yield f"data: {json.dumps({'type': 'sources', 'sources': found.citations})}\n\n"
```

And in the `finally` block, replace the two database columns with:

```python
                            # An operator auditing a wrong answer needs the
                            # statement, not a guess at it.
                            db_sql=(found.sql or None) if found else None,
                            db_row_count=found.row_count if (found and found.sql) else None,
```

Remove the now unused imports of `KbCollection` and `KbSource` from the `database` import list if nothing else in the file uses them.

- [ ] **Step 2: Delete the router and the web gate**

```bash
cd api-engine
rm dbquery/routing.py tests/test_dbquery_routing.py
rm websearch/gating.py tests/test_websearch_gating.py
```

- [ ] **Step 3: Run the suite and see what still refers to them**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest -q`
Expected: FAIL in `tests/test_chat_database.py` and `tests/test_chat_web_search.py`, which assert on `Outcome`, `context_for`, `sources_payload_for` or `web_search_runs`.

- [ ] **Step 4: Rewrite those two test files against the cascade**

Both files test `context_for` and `sources_payload_for`, which no longer exist. Replace `tests/test_chat_database.py` entirely with the file below, which keeps every property it asserted and states it as an ordering property instead of a verdict one.

```python
"""What the chat route does with whichever source answered.

The branching these covered used to live in context_for and
sources_payload_for. It lives in the SourceResult now, so these assert on the
result the cascade hands back.
"""
import pytest

import sources
from sources.result import SourceResult

ALL_ON = {"documents": True, "database": True, "web": True}


def hit(kind, **fields):
    async def attempt():
        return SourceResult(kind=kind, has_content=True, **fields)
    return attempt


def miss(kind):
    async def attempt():
        return SourceResult(kind=kind)
    return attempt


def never(kind):
    async def attempt():
        raise AssertionError(f"{kind} should not have been consulted")
    return attempt


@pytest.mark.asyncio
async def test_a_database_answer_supplies_its_rows_as_the_context():
    found = await sources.resolve(
        ["database", "documents", "web"], ALL_ON,
        {"database": hit("database", context_block="| id |\n| 4 |"),
         "documents": never("documents"), "web": never("web")})

    assert found.context_block == "| id |\n| 4 |"


@pytest.mark.asyncio
async def test_a_documents_answer_supplies_the_knowledge_base_block():
    found = await sources.resolve(
        ["documents", "database", "web"], ALL_ON,
        {"documents": hit("documents", context_block="From the handbook."),
         "database": never("database"), "web": never("web")})

    assert found.context_block == "From the handbook."


@pytest.mark.asyncio
async def test_nothing_retrieved_falls_through_to_the_next_source():
    found = await sources.resolve(
        ["documents", "web", "database"], ALL_ON,
        {"documents": miss("documents"),
         "web": hit("web", context_block="From the web."),
         "database": never("database")})

    assert found.kind == "web"


@pytest.mark.asyncio
async def test_no_source_answering_supplies_no_context_at_all():
    found = await sources.resolve(
        ["documents", "database", "web"], ALL_ON,
        {"documents": miss("documents"), "database": miss("database"),
         "web": miss("web")})

    assert found is None


@pytest.mark.asyncio
async def test_a_database_answer_cites_the_connection_by_name():
    found = await sources.resolve(
        ["database"], ALL_ON,
        {"database": hit("database", citations=[{"n": 1, "title": "Shop database"}])})

    assert found.citations == [{"n": 1, "title": "Shop database"}]


@pytest.mark.asyncio
async def test_a_database_citation_carries_no_url_and_no_sql():
    """A visitor on a public site must not learn the table names, let alone
    the statement, however the answer was produced.
    """
    found = await sources.resolve(
        ["database"], ALL_ON,
        {"database": hit("database", sql="SELECT id FROM orders", row_count=1,
                         citations=[{"n": 1, "title": "Shop database"}])})

    assert list(found.citations[0].keys()) == ["n", "title"]


@pytest.mark.asyncio
async def test_the_statement_reaches_the_transcript_but_not_the_citation():
    """An operator auditing a wrong answer needs the statement. chat.py writes
    these two onto the message row, and only the database attempt sets them.
    """
    found = await sources.resolve(
        ["database"], ALL_ON,
        {"database": hit("database", sql="SELECT id FROM orders", row_count=7,
                         citations=[{"n": 1, "title": "Shop database"}])})

    assert found.sql == "SELECT id FROM orders"
    assert found.row_count == 7


@pytest.mark.asyncio
async def test_a_documents_answer_leaves_the_transcript_columns_empty():
    found = await sources.resolve(
        ["documents"], ALL_ON, {"documents": hit("documents", context_block="x")})

    assert found.sql == ""
    assert found.row_count == 0
```

In `tests/test_chat_web_search.py`:

- `test_duckduckgo_needs_no_key` and `test_the_key_of_the_chosen_provider_is_the_one_used` — keep, changing the import from `routers.chat` to `sources.attempts`, which is where `key_for` now lives.
- `test_a_greeting_never_reaches_the_web` — keep. It asserts `should_retrieve` is false for a greeting, which is unchanged.
- `test_a_question_with_no_knowledge_base_hits_reaches_the_web`, `test_a_question_the_knowledge_base_answered_does_not`, `test_web_results_become_the_context_when_the_knowledge_base_was_empty` and `test_a_knowledge_base_block_is_never_replaced_by_web_results` — delete. All four assert the old rule that the web is a fallback after the knowledge base, which is now one particular order rather than a property of the web. `test_a_miss_moves_on_to_the_next_source_in_order` and `test_the_first_source_with_content_answers` in Task 5 cover it as ordering.

- [ ] **Step 5: Run the whole suite**

Run: `cd api-engine && ./.venv/Scripts/python.exe -m pytest -q`
Expected: PASS, everything green.

- [ ] **Step 6: Commit**

```bash
git add -A api-engine/
git commit -m "feat: the chat route walks the source order, and the router is gone"
```

---

### Task 7: The order control on the Brain page

**Files:**
- Modify: `admin-laravel/app/Http/Controllers/BotBrainController.php`
- Modify: `admin-laravel/resources/views/bots/brain.blade.php`
- Modify: `admin-laravel/public/css/console.css`
- Test: `admin-laravel/tests/Feature/BotBrainSourceOrderTest.php`

**Interfaces:**
- Consumes: `App\Support\SourceOrder` from Task 1.
- Produces: nothing further.

- [ ] **Step 1: Write the failing tests**

Create `admin-laravel/tests/Feature/BotBrainSourceOrderTest.php`. Model its `setUp`, its user helper and its bot creation on `admin-laravel/tests/Feature/DbSchemaControllerTest.php`, then:

```php
    public function test_the_brain_page_shows_the_bots_order(): void
    {
        $bot = $this->bot(['source_order' => 'database,documents,web']);

        $html = $this->actingAs($this->userWithRole('editor'))
            ->get(route('bots.brain', $bot->id))
            ->assertOk()
            ->assertSee('Answer source order')
            ->getContent();

        // Laboured by hand rather than by token, because the operator reads
        // names and the column stores tokens.
        $this->assertLessThan(
            strpos($html, 'Knowledge base'),
            strpos($html, 'Database'));
    }

    public function test_a_source_that_is_switched_off_says_so_in_the_order(): void
    {
        // An operator who puts the database first and sees nothing change
        // needs to be told why, next to the row they just moved.
        $bot = $this->bot(['db_query_enabled' => false]);

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('bots.brain', $bot->id))
            ->assertOk()
            ->assertSee('switched off');
    }

    public function test_an_editor_changes_the_order(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('bots.brain.update', $bot->id),
                $this->brainPayload(['source_order' => 'web,database,documents']))
            ->assertRedirect();

        $this->assertSame('web,database,documents', $bot->fresh()->source_order);
    }

    public function test_a_malformed_order_is_normalised_rather_than_refused(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('bots.brain.update', $bot->id),
                $this->brainPayload(['source_order' => 'web,web,telepathy']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('web,documents,database', $bot->fresh()->source_order);
    }

    public function test_an_absent_order_falls_back_to_the_default(): void
    {
        $bot = $this->bot(['source_order' => 'web,documents,database']);

        $payload = $this->brainPayload();
        unset($payload['source_order']);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('bots.brain.update', $bot->id), $payload)
            ->assertRedirect();

        $this->assertSame(SourceOrder::DEFAULT, $bot->fresh()->source_order);
    }
```

Write `brainPayload(array $overrides = [])` as a private helper returning every field `BotBrainController::update` requires, merged with `$overrides`. Read the controller's `validate` call for the full list; every `required` rule needs a value or the test fails on validation rather than on the behaviour being tested.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd admin-laravel && php artisan test --filter=BotBrainSourceOrderTest`
Expected: FAIL — the page does not contain "Answer source order".

- [ ] **Step 3: Accept and normalise the order in the controller**

In `admin-laravel/app/Http/Controllers/BotBrainController.php`, add `use App\Support\SourceOrder;` at the top, add to the `validate` array:

```php
            'source_order' => ['nullable', 'string', 'max:64'],
```

and immediately after the three `$validated[...] = $request->boolean(...)` lines add:

```php
        // Normalised rather than refused. A hand made submission must not be
        // able to leave a bot with a source it can never reach.
        $validated['source_order'] = SourceOrder::normalise($request->input('source_order'));
```

- [ ] **Step 4: Add the control to the view**

In `admin-laravel/resources/views/bots/brain.blade.php`, immediately above the card whose header is the knowledge base block, insert:

```blade
@php
    $sourceLabels = [
        'documents' => ['Knowledge base', 'The written material attached below.', $bot->retrieval_enabled],
        'database' => ['Database', 'Live records from a connected database.', $bot->db_query_enabled],
        'web' => ['Web search', 'A public search of the open internet.', $bot->web_search_enabled],
    ];
@endphp

<div class="card mt-4">
    <div class="card-header">
        <h2 class="h6 mb-0">Answer source order</h2>
    </div>
    <div class="card-body">
        <p class="text-muted" style="font-size: 0.8rem;">
            Each question goes to these in turn, and the first one with something
            to say answers it. A source that is switched off is passed over.
        </p>

        <input type="hidden" name="source_order" id="source_order"
               value="{{ old('source_order', $bot->source_order) }}">

        <ol class="source-order list-unstyled mb-0" id="sourceOrder">
            @foreach(\App\Support\SourceOrder::toList(old('source_order', $bot->source_order)) as $token)
                @php([$label, $blurb, $on] = $sourceLabels[$token])
                <li class="source-order-row d-flex align-items-center gap-2" data-token="{{ $token }}">
                    <span class="source-order-rank figure-mono"></span>
                    <span class="flex-grow-1">
                        <span class="fw-semibold">{{ $label }}</span>
                        @unless($on)
                            <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">switched off</span>
                        @endunless
                        <br>
                        <span class="text-muted" style="font-size: 0.75rem;">{{ $blurb }}</span>
                    </span>
                    <span class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary" data-move="up"
                                aria-label="Move {{ $label }} earlier">
                            <i class="bi bi-arrow-up"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary" data-move="down"
                                aria-label="Move {{ $label }} later">
                            <i class="bi bi-arrow-down"></i>
                        </button>
                    </span>
                </li>
            @endforeach
        </ol>
    </div>
</div>
```

At the end of the file, inside the existing `@push('scripts')` block if the view has one, or in a new one:

```blade
@push('scripts')
<script>
(function () {
    var list = document.getElementById('sourceOrder');
    var field = document.getElementById('source_order');
    if (!list || !field) { return; }

    function sync() {
        var rows = Array.prototype.slice.call(list.querySelectorAll('.source-order-row'));
        field.value = rows.map(function (row) { return row.dataset.token; }).join(',');
        rows.forEach(function (row, i) {
            row.querySelector('.source-order-rank').textContent = (i + 1) + '.';
            row.querySelector('[data-move="up"]').disabled = i === 0;
            row.querySelector('[data-move="down"]').disabled = i === rows.length - 1;
        });
    }

    list.addEventListener('click', function (event) {
        var button = event.target.closest('[data-move]');
        if (!button) { return; }

        var row = button.closest('.source-order-row');
        if (button.dataset.move === 'up' && row.previousElementSibling) {
            list.insertBefore(row, row.previousElementSibling);
        } else if (button.dataset.move === 'down' && row.nextElementSibling) {
            list.insertBefore(row.nextElementSibling, row);
        }
        sync();
    });

    sync();
})();
</script>
@endpush
```

- [ ] **Step 5: Add the styles**

Append to `admin-laravel/public/css/console.css`:

```css

/* The order sources are consulted in, one row per source. */
.source-order-row {
  padding: 0.5rem 0.625rem;
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  background-color: var(--bg-elev);
}

.source-order-row + .source-order-row { margin-top: 0.375rem; }

.source-order-rank {
  min-width: 1.5em;
  font-size: 0.75rem;
  color: var(--text-faint);
}
```

- [ ] **Step 6: Correct the copy the cascade makes wrong**

Two blocks on this page describe the behaviour that has just been replaced.

In the web search block, replace "Runs only when the knowledge base returns nothing, so your own documents always win. A bot with retrieval switched off has no knowledge base, so it will search every question." with:

```
Consulted in the order set above. Where it sits after the knowledge base, it
runs only when your own documents had nothing.
```

In the database block, replace "When this is on, the bot works out for itself whether a question is best answered from live data, from the knowledge above, from the web, or from nothing at all. If it cannot decide, or anything goes wrong, it falls back to the knowledge and then the web, which is what it does today." with:

```
When this is on, the database is consulted in its turn from the order set
above. If no readable table can answer the question it is passed over, and if
a query fails the next source gets its turn.
```

- [ ] **Step 7: Run the tests**

Run: `cd admin-laravel && php artisan test --filter=BotBrainSourceOrderTest`
Expected: PASS, 5 tests.

- [ ] **Step 8: Run the whole portal suite**

Run: `cd admin-laravel && php artisan test`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add admin-laravel/
git commit -m "feat: an operator sets which source answers first"
```

---

### Task 8: The documents catch up

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/superpowers/specs/2026-09-11-database-query-design.md`

- [ ] **Step 1: Point the old decision at the new one**

At the top of `docs/superpowers/specs/2026-09-11-database-query-design.md`, after the existing opening paragraph, add:

```markdown
**Section 8 no longer holds.** The model no longer routes between sources; an
order set per bot decides, and the router is deleted. See
`2026-09-14-answer-source-order-design.md`, which says what was gained and what
was given up. Every other decision in this document stands.
```

- [ ] **Step 2: Update the pointer table**

In `docs/architecture.md`, in the table of where things live, replace the row naming the router with:

```markdown
| The order sources are consulted in | `api-engine/sources/__init__.py` |
| One attempt per source | `api-engine/sources/attempts.py` |
```

- [ ] **Step 3: Verify no document still describes the router**

Run: `grep -rn "route_and_query\|parse_verdict\|routes between sources" docs/ --include=*.md`
Expected: only the two corrected references above, in the specs, describing history rather than behaviour.

- [ ] **Step 4: Commit**

```bash
git add docs/
git commit -m "docs: the source order replaces the router"
```

---

## Manual check before calling it done

The automated tests cover the cascade. These two need a person, because they need a real model and a real database.

1. Start both services. On a bot with a database connection and readable tables, set the order to **Database, Knowledge base, Web search**. Ask it a question its tables can answer. Confirm the answer uses live data, that the transcript records the statement, and that the widget cites the connection name and not a table name.
2. On the same bot, ask a question only the knowledge base can answer, such as a policy question. Confirm the database declines rather than answering with no rows, and that the knowledge base answers. The engine log should show no rejected statement for that question.
