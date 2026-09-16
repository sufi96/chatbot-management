# Knowledge Base Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make knowledge base sources readable, correctable and exportable; carry an author-written description into every chunk; fetch the embedding model list; and stop a bot searching the knowledge base when someone only says hello.

**Architecture:** A deterministic gate in a new `kb/gating.py` decides whether a message is worth searching for, and the chat route reads that one boolean for retrieval, citations and the fallback instruction. A `description` column on sources joins the phase 3 breadcrumb as a second `About:` line. A new source detail page reads `kb_chunks` read-only so an operator can see exactly what was embedded, and the same page edits and exports the source.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy 2 async, httpx, pytest, pytest-asyncio. Laravel 13, PHP 8.4, Blade, PHPUnit. PostgreSQL 17.5 with pgvector 0.8.0.

**Spec:** `docs/superpowers/specs/2026-09-10-knowledge-base-operations-design.md`

## Global Constraints

- Git commits carry no AI attribution. No `Co-Authored-By`, no `Claude-Session`, no "Generated with Claude Code" trailer. Author stays `sufisuhaimi2014 <sufisuhaimi2014@gmail.com>`.
- Never commit `.env` or `admin-laravel/database/database.sqlite`.
- Baseline before this work: **104 Python tests** and **53 Laravel tests**, all passing.
- Python commands run from `api-engine/` with the virtualenv at `api-engine/.venv`. Laravel commands run from `admin-laravel/`.
- Laravel tests run on `sqlite :memory:` per `phpunit.xml`, so every migration here must work on both SQLite and PostgreSQL. Do not use `->change()`; it needs doctrine/dbal, which is not installed.
- No new Python or PHP dependencies.
- Breadcrumb literals, exact: section prefix `Section: `, description prefix `About: `, path separator ` > `.
- New default values, exact: `retrieval_min_score` `0.01`.
- Authorisation: viewing and downloading a source require the viewer role; editing requires the editor role. Use the controller's existing `authorizeViewer` and `authorizeEditor` helpers.

## File Structure

**Created**

| File | Responsibility |
|---|---|
| `api-engine/kb/gating.py` | Decide whether a message is worth searching for. Knows nothing about stores or bots. |
| `api-engine/tests/test_gating.py` | Gate tests. |
| `admin-laravel/database/migrations/2026_09_10_000006_add_description_to_kb_sources.php` | The description column. |
| `admin-laravel/database/migrations/2026_09_10_000007_raise_the_relevance_floor.php` | Move an untouched relevance floor off zero. |
| `admin-laravel/resources/views/kb/source.blade.php` | Source detail, edit form, chunk listing. |
| `admin-laravel/tests/Feature/KbSourceDetailTest.php` | Detail, edit, download and authorisation. |
| `admin-laravel/tests/Feature/EmbeddingModelListTest.php` | The fetch-models route. |

**Modified**

| File | Change |
|---|---|
| `api-engine/routers/chat.py` | One `retrieval_ran` boolean gating retrieval, citations and the fallback. |
| `api-engine/kb/chunking.py` | `description` argument; two-line header; `prepend_description` helper. |
| `api-engine/kb/indexer.py` | Passes the source description through, for chunked and Q&A sources alike. |
| `api-engine/kb/embedding.py` | `list_models`. |
| `api-engine/routers/kb.py` | `POST /embedding/models`. |
| `api-engine/database.py` | `KbSource.description`. |
| `api-engine/tests/test_chunking.py` | Description cases. |
| `api-engine/tests/test_indexer.py` | Description reaches the chunk. |
| `api-engine/tests/test_embedding.py` | `list_models` sorting and failure. |
| `admin-laravel/app/Models/KbSource.php` | `description` fillable. |
| `admin-laravel/app/Models/BotProfile.php` | Default `retrieval_min_score` for new bots. |
| `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php` | `showSource`, `updateSource`, `downloadSource`; description on create and upload; breadcrumb strip covers the About line. |
| `admin-laravel/app/Http/Controllers/AdminSettingsController.php` | `models` action. |
| `admin-laravel/app/Services/EngineClient.php` | `listEmbeddingModels`. |
| `admin-laravel/routes/web.php` | Three source routes, one settings route. |
| `admin-laravel/resources/views/kb/show.blade.php` | Add-content cards, description fields in all three modals, view and download row actions. |
| `admin-laravel/resources/views/admin/settings.blade.php` | Datalist and Fetch models button. |
| `admin-laravel/resources/views/bots/brain.blade.php` | Copy explaining the gate and the floor. |

---

### Task 1: The retrieval gate

Decide whether a message is worth searching for. No model call, no store access.

**Files:**
- Create: `api-engine/kb/gating.py`
- Test: `api-engine/tests/test_gating.py`

**Interfaces:**
- Consumes: nothing.
- Produces: `should_retrieve(message: str) -> bool` and the `SMALLTALK` frozenset.

- [ ] **Step 1: Write the failing tests**

Create `api-engine/tests/test_gating.py`:

```python
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run from `api-engine/`:

```bash
.venv/Scripts/python -m pytest tests/test_gating.py -q
```

Expected: collection error, `ModuleNotFoundError: No module named 'kb.gating'`.

- [ ] **Step 3: Write the gate**

Create `api-engine/kb/gating.py`:

```python
"""Decide whether a message is worth searching the knowledge base for.

Deterministic on purpose. Asking a model to judge intent would double the wait
before the first token, and at the sizes that run locally it classifies badly.
A greeting is the case that actually occurs, and a fixed vocabulary catches it.
"""
import re

# Deliberately small, and containing no domain words. A term that could ever
# appear in a real question does not belong here.
SMALLTALK = frozenset({
    "hi", "hiya", "hello", "hey", "yo", "sup", "greetings",
    "morning", "afternoon", "evening",
    "thanks", "thank", "ty", "cheers", "appreciate", "appreciated",
    "bye", "goodbye", "later", "night",
    "ok", "okay", "sure", "yes", "yeah", "yep", "no", "nope",
    "cool", "great", "nice", "good", "well", "got", "it",
    "you", "there", "so", "much", "very", "a", "lot", "please",
})

WORD_RE = re.compile(r"[a-z]+")


def should_retrieve(message: str) -> bool:
    """False when the message cannot be a question worth searching for."""
    text = (message or "").strip()
    if not text:
        return False

    # Punctuated as a question, so search whatever the words are. This is what
    # stops the vocabulary ever swallowing a real question.
    if "?" in text:
        return True

    words = WORD_RE.findall(text.lower())
    if not words:
        return False
    if all(word in SMALLTALK for word in words):
        return False

    # A message with nothing longer than two letters cannot carry a question.
    return any(len(word) > 2 for word in words)
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
.venv/Scripts/python -m pytest tests/test_gating.py -q
```

Expected: 24 passed.

- [ ] **Step 5: Run the whole Python suite**

```bash
.venv/Scripts/python -m pytest -q
```

Expected: 128 passed. Nothing imports `kb.gating` yet.

- [ ] **Step 6: Commit**

```bash
git add api-engine/kb/gating.py api-engine/tests/test_gating.py
git commit -m "feat: decide when a message is worth searching for"
```

---

### Task 2: Wire the gate into the chat route

One boolean drives retrieval, the citations event and the fallback instruction.

**Files:**
- Modify: `api-engine/routers/chat.py`
- Test: `api-engine/tests/test_chat_gating.py` (create)

**Interfaces:**
- Consumes: `should_retrieve` from Task 1; `augment_system_prompt`, `fit_to_budget` from `kb/retrieval.py`.
- Produces: no new public names. The route's behaviour changes only.

- [ ] **Step 1: Write the failing tests**

Create `api-engine/tests/test_chat_gating.py`. These test the decision, not the
HTTP route, because the route needs a live model:

```python
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
```

- [ ] **Step 2: Run them to verify they pass or fail as expected**

```bash
.venv/Scripts/python -m pytest tests/test_chat_gating.py -q
```

Expected: 5 passed. They pass immediately because they assert the rule, not the
route. Their job is to pin the rule so Step 3 has something to match.

- [ ] **Step 3: Change the chat route**

In `api-engine/routers/chat.py`, extend the retrieval import:

```python
from kb.gating import should_retrieve
from kb.retrieval import (augment_system_prompt, build_context_block,
                          fit_to_budget, retrieve_for_collections)
```

Replace `if bot.retrieval_enabled:` with the gated boolean:

```python
    retrieved = []
    source_titles = {}
    # A greeting is not a question. Skipping saves an embedding call and two
    # searches, and stops five irrelevant passages reaching the model.
    retrieval_ran = bool(bot.retrieval_enabled) and should_retrieve(req.message)
    if retrieval_ran:
        try:
```

Replace the prompt assembly below it:

```python
    context_block = build_context_block(retrieved, source_titles)
    fallback = bot.retrieval_fallback or "say_unknown"
    if not retrieval_ran:
        # A gated message must never be told the answer is missing from the
        # material. A greeting gets a greeting.
        fallback = "answer_anyway"
    final_prompt = augment_system_prompt(bot.system_prompt or "", context_block, fallback)
```

- [ ] **Step 4: Verify by hand against the running engine**

Start the stack if it is not running, then:

```bash
curl -s -N -m 60 -X POST http://127.0.0.1:8000/api/v1/chat/stream \
  -H "Content-Type: application/json" \
  -d '{"bot_id":"bot_demo_default","session_id":"gate-check","message":"hello","history":[]}' | head -5
```

Expected: no `{"type": "sources"...}` line at all, and a greeting in reply.
Then repeat with `"message":"how long is the warranty on pendant fittings?"` and
expect the sources line to be present.

Note: `bot_demo_default` is seeded with `model_name` `llama3.2`, which is not
installed. Set it to `llama3.2:1b` for the check and set it back afterwards:

```bash
cd admin-laravel && php artisan tinker --execute="\$b=App\Models\BotProfile::find('bot_demo_default'); \$b->model_name='llama3.2:1b'; \$b->save();"
```

- [ ] **Step 5: Run the whole Python suite**

```bash
.venv/Scripts/python -m pytest -q
```

Expected: 133 passed.

- [ ] **Step 6: Commit**

```bash
git add api-engine/routers/chat.py api-engine/tests/test_chat_gating.py
git commit -m "feat: skip the knowledge base when a message is not a question"
```

---

### Task 3: Raise the relevance floor

**Files:**
- Create: `admin-laravel/database/migrations/2026_09_10_000007_raise_the_relevance_floor.php`
- Modify: `admin-laravel/app/Models/BotProfile.php`
- Modify: `admin-laravel/resources/views/bots/brain.blade.php`
- Test: `admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php` (append)

**Interfaces:**
- Consumes: nothing.
- Produces: new bots are created with `retrieval_min_score` of `0.01`.

- [ ] **Step 1: Write the failing tests**

Append to `admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php`. That file
already has the `makeSystem` helper and imports `BotProfile`, which
`BotBrainTest.php` does not:

```php
    public function test_a_new_bot_starts_with_a_relevance_floor(): void
    {
        $system = $this->makeSystem();
        $bot = BotProfile::create([
            'id' => 'test_chat_03', 'system_id' => $system->id, 'name' => 'Bot',
        ])->fresh();

        $this->assertEqualsWithDelta(0.01, $bot->retrieval_min_score, 0.0001);
    }

    public function test_an_untouched_relevance_floor_is_raised(): void
    {
        $system = $this->makeSystem();
        BotProfile::create([
            'id' => 'test_chat_04', 'system_id' => $system->id, 'name' => 'Bot',
        ]);
        \DB::table('bot_profiles')->where('id', 'test_chat_04')
            ->update(['retrieval_min_score' => 0]);

        $migration = require database_path(
            'migrations/2026_09_10_000007_raise_the_relevance_floor.php');
        $migration->up();

        $this->assertEqualsWithDelta(
            0.01, BotProfile::find('test_chat_04')->retrieval_min_score, 0.0001);
    }

    public function test_a_chosen_relevance_floor_survives_the_migration(): void
    {
        $system = $this->makeSystem();
        BotProfile::create([
            'id' => 'test_chat_05', 'system_id' => $system->id, 'name' => 'Bot',
        ]);
        \DB::table('bot_profiles')->where('id', 'test_chat_05')
            ->update(['retrieval_min_score' => 0.05]);

        $migration = require database_path(
            'migrations/2026_09_10_000007_raise_the_relevance_floor.php');
        $migration->up();

        $this->assertEqualsWithDelta(
            0.05, BotProfile::find('test_chat_05')->retrieval_min_score, 0.0001);
    }
```

- [ ] **Step 2: Run them to verify they fail**

```bash
php artisan test --filter=KnowledgeBaseSchemaTest
```

Expected: failures on the missing migration file and on the default still being
zero.

- [ ] **Step 3: Set the default for new bots**

In `admin-laravel/app/Models/BotProfile.php`, add the attribute default beside
the other model properties. Do not try to change the column default in a
migration: `->change()` needs doctrine/dbal, which is not installed, and SQLite
cannot alter a default in place.

```php
    /**
     * Fused retrieval scores top out near 0.016, so a zero floor admits every
     * weak match. The column default stays 0 for older rows; new bots start here.
     */
    protected $attributes = [
        'retrieval_min_score' => 0.01,
    ];
```

If the class already declares `$attributes`, add the key to it rather than
adding a second declaration.

- [ ] **Step 4: Write the migration**

Create `admin-laravel/database/migrations/2026_09_10_000007_raise_the_relevance_floor.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** A floor still at exactly zero was never chosen; it is the old default. */
    public function up(): void
    {
        DB::table('bot_profiles')->where('retrieval_min_score', 0)
            ->update(['retrieval_min_score' => 0.01]);
    }

    public function down(): void
    {
        DB::table('bot_profiles')->where('retrieval_min_score', 0.01)
            ->update(['retrieval_min_score' => 0]);
    }
};
```

- [ ] **Step 5: Explain both levers on the Brain page**

In `admin-laravel/resources/views/bots/brain.blade.php`, replace the relevance
floor help text and add a line under the retrieval checkbox. Find the
`retrieval_min_score` input and set the following as the `form-text` under it:

```blade
                        <div class="form-text">Passages scoring below this are dropped. A top hit scores about 0.016, or 0.033 when both branches agree.</div>
```

Under the "search the knowledge base before answering" checkbox, add:

```blade
                        <div class="form-text">Greetings, thanks and goodbyes never trigger a search, so a hello stays a hello.</div>
```

- [ ] **Step 6: Run the Laravel suite**

```bash
php artisan test
```

Expected: 56 passed.

- [ ] **Step 7: Apply the migration**

```bash
php artisan migrate
```

Expected: `2026_09_10_000007_raise_the_relevance_floor ... DONE`.

- [ ] **Step 8: Commit**

```bash
git add admin-laravel/app/Models/BotProfile.php \
        admin-laravel/database/migrations/2026_09_10_000007_raise_the_relevance_floor.php \
        admin-laravel/resources/views/bots/brain.blade.php \
        admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php
git commit -m "feat: give retrieval a relevance floor by default"
```

---

### Task 4: The source description

A description on every source, carried into every chunk as a second header line.

**Files:**
- Create: `admin-laravel/database/migrations/2026_09_10_000006_add_description_to_kb_sources.php`
- Modify: `api-engine/kb/chunking.py`
- Modify: `api-engine/kb/indexer.py`
- Modify: `api-engine/database.py`
- Modify: `api-engine/tests/test_chunking.py`
- Modify: `api-engine/tests/test_indexer.py`
- Modify: `admin-laravel/app/Models/KbSource.php`
- Modify: `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`
- Modify: `admin-laravel/resources/views/kb/show.blade.php`

**Interfaces:**
- Consumes: `Chunk`, `chunk_document` from phase 3.
- Produces:
  - `chunk_document(text, *, title="", description="", size=1800, overlap=200)`
  - `prepend_description(body: str, description: str) -> str`
  - `ABOUT_PREFIX = "About: "`
  - `KbSource.description` on both the Laravel model and the engine model.

- [ ] **Step 1: Write the failing Python tests**

Append to `api-engine/tests/test_chunking.py`:

```python
def test_a_description_becomes_an_about_line_under_the_section():
    chunks = chunk_document("## Warranty\n\nTwo years.", title="Policy",
                            description="Retail terms.")
    assert chunks[0].text.startswith(
        "Section: Policy > Warranty\nAbout: Retail terms.\n\n")
    assert chunks[0].text.endswith("Two years.")


def test_no_description_means_no_about_line():
    chunks = chunk_document("## Warranty\n\nTwo years.", title="Policy")
    assert "About:" not in chunks[0].text


def test_a_description_without_headings_still_gets_an_about_line():
    chunks = chunk_document("plain text", description="Retail terms.")
    assert chunks[0].text == "About: Retail terms.\n\nplain text"
    assert chunks[0].heading_path == ""


def test_a_description_is_flattened_onto_one_line():
    chunks = chunk_document("body", description="Retail\nterms.")
    assert chunks[0].text.startswith("About: Retail terms.\n\n")


def test_the_description_counts_against_the_ceiling():
    text = "## H\n\n" + "\n\n".join("x" * 100 for _ in range(8))
    for chunk in chunk_document(text, size=400, overlap=0,
                                description="d" * 120):
        assert len(chunk.text) <= 400


def test_prepend_description_attaches_an_about_line():
    from kb.chunking import prepend_description
    assert prepend_description("Q: a\nA: b", "Retail terms.") == \
        "About: Retail terms.\n\nQ: a\nA: b"


def test_prepend_description_leaves_text_alone_without_one():
    from kb.chunking import prepend_description
    assert prepend_description("Q: a\nA: b", "") == "Q: a\nA: b"
```

- [ ] **Step 2: Run them to verify they fail**

```bash
.venv/Scripts/python -m pytest tests/test_chunking.py -q
```

Expected: seven failures. `chunk_document` rejects the `description` keyword and
`prepend_description` does not exist.

- [ ] **Step 3: Add the header block to the chunker**

In `api-engine/kb/chunking.py`, add the prefix beside the existing one:

```python
BREADCRUMB_PREFIX = "Section: "
ABOUT_PREFIX = "About: "
PATH_SEPARATOR = " > "
```

Change the signature and the header construction inside `chunk_document`:

```python
def chunk_document(text: str, *, title: str = "", description: str = "",
                   size: int = 1800, overlap: int = 200) -> list[Chunk]:
    if overlap >= size:
        raise ValueError("overlap must be smaller than size")
    if not text or not text.strip():
        return []

    out: list[Chunk] = []
    for headings, blocks in _group_by_heading(parse_blocks(text)):
        path = _path_text(headings, title)
        header = _header(path, description)
        budget = max(MIN_BODY_BUDGET, size - len(header))
        for body in _pack(blocks, budget, overlap):
            out.append(Chunk(text=header + body, heading_path=path))

    return [c for c in out if c.text.strip()]
```

Add the two helpers after `_path_text`:

```python
def _header(path: str, description: str) -> str:
    """The lines every chunk of this source carries, blank-line terminated."""
    lines: list[str] = []
    if path:
        lines.append(f"{BREADCRUMB_PREFIX}{path}")
    summary = " ".join((description or "").split())
    if summary:
        lines.append(f"{ABOUT_PREFIX}{summary}")
    return "\n".join(lines) + "\n\n" if lines else ""


def prepend_description(body: str, description: str) -> str:
    """Attach an About line to text that bypasses chunking, such as a Q&A pair."""
    return _header("", description) + body
```

- [ ] **Step 4: Run the chunking tests**

```bash
.venv/Scripts/python -m pytest tests/test_chunking.py -q
```

Expected: 29 passed.

- [ ] **Step 5: Write the failing indexer test**

Append to `api-engine/tests/test_indexer.py`:

```python
@pytest.mark.asyncio
async def test_a_description_reaches_every_chunk(session):
    session.add(KbSource(id="s11", collection_id="col1", type="text",
                         title="Customer Policy",
                         description="Retail terms for lamps.",
                         body="## Warranty\n\nTwo years on desk lamps.",
                         status="pending"))
    await session.commit()

    await index_source(session, "s11", embedder=StubEmbedder())

    content = (await session.execute(text(
        "SELECT content FROM kb_chunks WHERE source_id = 's11'"))).scalar()
    assert content.startswith(
        "Section: Customer Policy > Warranty\nAbout: Retail terms for lamps.\n\n")


@pytest.mark.asyncio
async def test_a_qa_source_carries_its_description_and_stays_one_chunk(session):
    session.add(KbSource(id="s12", collection_id="col1", type="qa",
                         title="Do you refund shipping?",
                         description="Retail terms for lamps.",
                         body="No.", status="pending"))
    await session.commit()

    count = await index_source(session, "s12", embedder=StubEmbedder())
    assert count == 1

    content = (await session.execute(text(
        "SELECT content FROM kb_chunks WHERE source_id = 's12'"))).scalar()
    assert content == ("About: Retail terms for lamps.\n\n"
                       "Q: Do you refund shipping?\nA: No.")
```

- [ ] **Step 6: Add the column to the engine model and pass it through**

In `api-engine/database.py`, inside `class KbSource`, add the column after
`title`:

```python
    description = Column(Text, nullable=True)
```

In `api-engine/kb/indexer.py`, change the import and the two branches:

```python
from kb.chunking import Chunk, chunk_document, prepend_description
```

```python
        # A question and answer pair is one idea; splitting it would return half
        # an answer, and it has no headings to carry.
        if source.type == "qa":
            chunks = [Chunk(text=prepend_description(body, source.description or ""),
                            heading_path="")]
        else:
            chunks = chunk_document(body,
                                    title=source.title or "",
                                    description=source.description or "",
                                    size=int(settings["chunk_size"]),
                                    overlap=int(settings["chunk_overlap"]))
```

- [ ] **Step 7: Run the whole Python suite**

```bash
.venv/Scripts/python -m pytest -q
```

Expected: 142 passed.

- [ ] **Step 8: Write the Laravel migration**

Create `admin-laravel/database/migrations/2026_09_10_000006_add_description_to_kb_sources.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_sources', function (Blueprint $table) {
            // What this document is for, in the author's words. It rides along
            // with every chunk, so it is retrieval input, not just a label.
            $table->text('description')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('kb_sources', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
```

- [ ] **Step 9: Write the failing Laravel test**

Append to `admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php`:

```php
    public function test_a_source_carries_a_description(): void
    {
        $this->assertTrue(Schema::hasColumn('kb_sources', 'description'));

        $system = $this->makeSystem();
        KbCollection::create(['id' => 'kbc_9', 'system_id' => $system->id, 'name' => 'C']);
        $source = KbSource::create([
            'id' => 'kbs_9', 'collection_id' => 'kbc_9', 'type' => 'text',
            'title' => 'Policy', 'description' => 'Retail terms.', 'body' => 'B',
        ]);

        $this->assertSame('Retail terms.', $source->fresh()->description);
    }
```

- [ ] **Step 10: Make the column fillable and accept it on create**

In `admin-laravel/app/Models/KbSource.php`, add `description` to `$fillable`:

```php
    protected $fillable = [
        'id', 'collection_id', 'type', 'title', 'description', 'body',
        'file_path', 'file_mime', 'file_size',
        'status', 'error_message', 'chunk_count', 'indexed_at',
    ];
```

In `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`, in
`storeSource`, add the rule and the field:

```php
        $validated = $request->validate([
            'type' => ['required', 'in:text,qa'],
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string'],
        ], [
            'body.required' => 'A question needs an answer.',
        ]);

        $source = KbSource::create([
            'id' => 'kbs_' . Str::random(12),
            'collection_id' => $collection->id,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'body' => $validated['body'],
            'status' => 'pending',
        ]);
```

In `uploadSource`, add the rule and both fields, letting the operator override
the file name as the title:

```php
        $validated = $request->validate([
            'file' => [
                'required', 'file', 'max:20480',   // kilobytes, so 20 MB
                'mimes:pdf,docx,pptx,xlsx,xls,csv,md,txt,html,htm',
            ],
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'file.mimes' => 'That file type is not supported. Use PDF, Word, PowerPoint, Excel, CSV, Markdown, HTML or plain text.',
            'file.max' => 'Files must be 20 MB or smaller.',
        ]);

        $upload = $validated['file'];
        $path = $upload->store('kb/sources', 'public');

        $source = KbSource::create([
            'id' => 'kbs_' . Str::random(12),
            'collection_id' => $collection->id,
            'type' => 'file',
            // Absent, blank, or whitespace all fall back to the file name.
            'title' => trim($validated['title'] ?? '') ?: $upload->getClientOriginalName(),
            'description' => $validated['description'] ?? null,
            'file_path' => $path,
            'file_mime' => $upload->getClientMimeType(),
            'file_size' => $upload->getSize(),
            'status' => 'pending',
        ]);
```

- [ ] **Step 11: Add the description field to all three modals**

In `admin-laravel/resources/views/kb/show.blade.php`, add the same block to the
body of `#addTextModal`, `#addQaModal` and `#addFileModal`. Use a distinct id
per modal so the labels stay bound. For the text modal:

```blade
                        <div class="mb-3">
                            <label for="text_description" class="form-label">What is this about?</label>
                            <input type="text" id="text_description" name="description" class="form-control"
                                   maxlength="1000" placeholder="Returns, warranty and shipping terms for retail customers">
                            <div class="form-text">One line. It travels with every passage, so the bot knows what document an answer came from.</div>
                        </div>
```

For the Q&A modal use `qa_description`, and for the file modal use
`file_description`. The file modal also gains a title field, defaulting to
empty and falling back to the file name:

```blade
                        <div class="mb-3">
                            <label for="file_title" class="form-label">Title</label>
                            <input type="text" id="file_title" name="title" class="form-control"
                                   maxlength="500" placeholder="Leave blank to use the file name">
                        </div>
```

- [ ] **Step 12: Handle the About line in the playground strip**

In `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`, in
`runPlayground`, a chunk with a description but no section starts with
`About: `, which the current condition misses. Replace the condition:

```php
        $results = array_map(function (array $result): array {
            $result['heading_path'] = $result['heading_path'] ?? '';
            $break = strpos($result['content'], "\n\n");
            $hasHeader = str_starts_with($result['content'], 'Section: ')
                || str_starts_with($result['content'], 'About: ');
            if ($hasHeader && $break !== false) {
                $result['content'] = ltrim(substr($result['content'], $break + 2));
            }

            return $result;
        }, $response['results'] ?? []);
```

- [ ] **Step 13: Run both suites**

```bash
php artisan test
```

Expected: 57 passed.

```bash
cd ../api-engine && .venv/Scripts/python -m pytest -q
```

Expected: 142 passed.

- [ ] **Step 14: Apply the migration**

```bash
cd ../admin-laravel && php artisan migrate
```

Expected: `2026_09_10_000006_add_description_to_kb_sources ... DONE`.

- [ ] **Step 15: Commit**

```bash
git add api-engine/kb/chunking.py api-engine/kb/indexer.py api-engine/database.py \
        api-engine/tests/test_chunking.py api-engine/tests/test_indexer.py \
        admin-laravel/app/Models/KbSource.php \
        admin-laravel/app/Http/Controllers/KnowledgeBaseController.php \
        admin-laravel/resources/views/kb/show.blade.php \
        admin-laravel/database/migrations/2026_09_10_000006_add_description_to_kb_sources.php \
        admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php
git commit -m "feat: carry a source description into every chunk"
```

---

### Task 5: Source detail, edit and download

**Files:**
- Create: `admin-laravel/resources/views/kb/source.blade.php`
- Create: `admin-laravel/tests/Feature/KbSourceDetailTest.php`
- Modify: `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`
- Modify: `admin-laravel/routes/web.php`
- Modify: `admin-laravel/resources/views/kb/show.blade.php`

**Interfaces:**
- Consumes: `KbSource.description` from Task 4.
- Produces: routes `kb.sources.show`, `kb.sources.update`, `kb.sources.download`.

- [ ] **Step 1: Write the failing tests**

Create `admin-laravel/tests/Feature/KbSourceDetailTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\KbCollection;
use App\Models\KbSource;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KbSourceDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['status' => 'accepted'], 200)]);

        System::create(['id' => 'sys_test', 'name' => 'Test Workspace', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'Refunds']);
        KbSource::create([
            'id' => 'kbs_text', 'collection_id' => 'kbc_1', 'type' => 'text',
            'title' => 'Refund policy', 'description' => 'Retail terms.',
            'body' => 'Thirty days.', 'status' => 'ready', 'chunk_count' => 1,
        ]);
        DB::table('kb_chunks')->insert([
            'collection_id' => 'kbc_1', 'source_id' => 'kbs_text', 'ordinal' => 0,
            'content' => "Section: Refund policy\nAbout: Retail terms.\n\nThirty days.",
            'char_count' => 58, 'heading_path' => 'Refund policy',
        ]);
    }

    private function user(string $role, string $email): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => ucfirst($role), 'password' => bcrypt('password'), 'global_role' => 'user'],
        );
        $user->systems()->syncWithoutDetaching(['sys_test' => ['role' => $role]]);

        return $user;
    }

    public function test_the_detail_page_shows_the_source_and_its_chunks(): void
    {
        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.sources.show', 'kbs_text'))
            ->assertOk()
            ->assertSee('Refund policy')
            ->assertSee('Retail terms.')
            ->assertSee('Thirty days.');
    }

    public function test_a_viewer_can_read_a_source_but_cannot_save_it(): void
    {
        $viewer = $this->user('viewer', 'viewer@test.com');

        $this->actingAs($viewer)
            ->get(route('kb.sources.show', 'kbs_text'))
            ->assertOk();

        $this->actingAs($viewer)
            ->put(route('kb.sources.update', 'kbs_text'),
                ['title' => 'Changed', 'body' => 'Changed'])
            ->assertForbidden();
    }

    public function test_editing_a_text_source_saves_and_queues_a_reindex(): void
    {
        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->put(route('kb.sources.update', 'kbs_text'), [
                'title' => 'Refund policy v2',
                'description' => 'Updated retail terms.',
                'body' => 'Forty five days.',
            ])
            ->assertRedirect(route('kb.sources.show', 'kbs_text'));

        $source = KbSource::find('kbs_text');
        $this->assertSame('Refund policy v2', $source->title);
        $this->assertSame('Updated retail terms.', $source->description);
        $this->assertSame('Forty five days.', $source->body);
        $this->assertSame('pending', $source->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/index'));
    }

    public function test_a_file_source_has_no_editable_body(): void
    {
        KbSource::create([
            'id' => 'kbs_file', 'collection_id' => 'kbc_1', 'type' => 'file',
            'title' => 'terms.pdf', 'file_path' => 'kb/sources/terms.pdf',
            'file_mime' => 'application/pdf', 'file_size' => 2048, 'status' => 'ready',
        ]);

        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.sources.show', 'kbs_file'))
            ->assertOk()
            ->assertSee('terms.pdf')
            ->assertDontSee('name="body"', false);
    }

    public function test_editing_a_file_source_needs_no_body(): void
    {
        KbSource::create([
            'id' => 'kbs_file2', 'collection_id' => 'kbc_1', 'type' => 'file',
            'title' => 'terms.pdf', 'file_path' => 'kb/sources/terms.pdf',
            'file_mime' => 'application/pdf', 'file_size' => 2048, 'status' => 'ready',
        ]);

        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->put(route('kb.sources.update', 'kbs_file2'), [
                'title' => 'terms.pdf', 'description' => 'Supplier contract terms.',
            ])
            ->assertRedirect();

        $this->assertSame('Supplier contract terms.',
            KbSource::find('kbs_file2')->description);
    }

    public function test_downloading_a_text_source_returns_markdown(): void
    {
        $response = $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.sources.download', 'kbs_text'))
            ->assertOk();

        $body = $response->streamedContent() ?: $response->getContent();
        $this->assertStringContainsString('# Refund policy', $body);
        $this->assertStringContainsString('Retail terms.', $body);
        $this->assertStringContainsString('Thirty days.', $body);
    }

    public function test_downloading_a_file_source_returns_the_stored_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('kb/sources/terms.pdf', '%PDF-1.4 fake');
        KbSource::create([
            'id' => 'kbs_file3', 'collection_id' => 'kbc_1', 'type' => 'file',
            'title' => 'terms.pdf', 'file_path' => 'kb/sources/terms.pdf',
            'file_mime' => 'application/pdf', 'file_size' => 13, 'status' => 'ready',
        ]);

        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.sources.download', 'kbs_file3'))
            ->assertOk()
            ->assertDownload('terms.pdf');
    }

    public function test_a_source_in_another_workspace_is_refused(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs']);
        KbSource::create([
            'id' => 'kbs_other', 'collection_id' => 'kbc_other', 'type' => 'text',
            'title' => 'Secret', 'body' => 'x', 'status' => 'ready',
        ]);

        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.sources.show', 'kbs_other'))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

```bash
php artisan test --filter=KbSourceDetailTest
```

Expected: every test errors with `Route [kb.sources.show] not defined.`

- [ ] **Step 3: Add the routes**

In `admin-laravel/routes/web.php`, beside the existing source routes:

```php
    Route::get('/knowledge/sources/{sourceId}', [KnowledgeBaseController::class, 'showSource'])->name('kb.sources.show');
    Route::put('/knowledge/sources/{sourceId}', [KnowledgeBaseController::class, 'updateSource'])->name('kb.sources.update');
    Route::get('/knowledge/sources/{sourceId}/download', [KnowledgeBaseController::class, 'downloadSource'])->name('kb.sources.download');
```

Place them before the existing `POST /knowledge/sources/{sourceId}/reindex`
line so the whole source group reads together. Route order does not matter here
because the methods and suffixes differ.

- [ ] **Step 4: Add the three controller actions**

In `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`, add the
`DB` facade to the imports:

```php
use Illuminate\Support\Facades\DB;
```

Add the actions after `destroySource`:

```php
    public function showSource(Request $request, string $sourceId)
    {
        $source = KbSource::with('collection')->findOrFail($sourceId);
        $this->authorizeViewer($request, $source->collection->system_id);

        // Read-only. The engine remains the only writer of this table; a
        // listing does not justify an HTTP hop to fetch it.
        $chunks = DB::table('kb_chunks')
            ->where('source_id', $source->id)
            ->orderBy('ordinal')
            ->get(['id', 'ordinal', 'heading_path', 'char_count', 'content']);

        return view('kb.source', [
            'source' => $source,
            'chunks' => $chunks,
            'canEdit' => $request->user()->canManageSystem(
                $source->collection->system_id, 'editor'),
        ]);
    }

    public function updateSource(Request $request, string $sourceId)
    {
        $source = KbSource::with('collection')->findOrFail($sourceId);
        $this->authorizeEditor($request, $source->collection->system_id);

        $rules = [
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
        // An uploaded file's text comes from extraction. Editing it here would
        // create a copy that silently diverges from the downloadable original.
        if ($source->type !== 'file') {
            $rules['body'] = ['required', 'string'];
        }
        $validated = $request->validate($rules);

        $source->title = $validated['title'];
        $source->description = $validated['description'] ?? null;
        if ($source->type !== 'file') {
            $source->body = $validated['body'];
        }
        $source->status = 'pending';
        $source->error_message = null;
        $source->save();

        EngineClient::indexSource($source->id);

        return redirect()->route('kb.sources.show', $source->id)
            ->with('success', 'Saved. Re-indexing runs in the background.');
    }

    public function downloadSource(Request $request, string $sourceId)
    {
        $source = KbSource::with('collection')->findOrFail($sourceId);
        $this->authorizeViewer($request, $source->collection->system_id);

        if ($source->type === 'file') {
            abort_unless($source->file_path
                && Storage::disk('public')->exists($source->file_path), 404);

            return Storage::disk('public')->download($source->file_path, $source->title);
        }

        $markdown = "# {$source->title}\n\n";
        if ($source->description) {
            $markdown .= "{$source->description}\n\n";
        }
        $markdown .= (string) $source->body . "\n";

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'
                . Str::slug($source->title) . '.md"',
        ]);
    }
```

- [ ] **Step 5: Build the detail view**

Create `admin-laravel/resources/views/kb/source.blade.php`:

```blade
@extends('layouts.app')

@section('title', $source->title)

@section('content')
<div style="max-width: 1100px;">

    <div class="page-head mb-4">
        <div class="min-w-0">
            <a href="{{ route('kb.show', $source->collection_id) }}"
               class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
                <i class="bi bi-arrow-left"></i> {{ $source->collection->name }}
            </a>
            <h1 class="truncate-1">{{ $source->title }}</h1>
            <p>
                <span class="chip">
                    @if($source->type === 'qa') Q and A
                    @elseif($source->type === 'file') File
                    @else Text @endif
                </span>
                <span class="chip figure-mono">{{ $source->chunk_count }} passages</span>
                @if($source->status === 'ready')
                    <span class="chip" style="color: var(--ok);">Indexed</span>
                @elseif($source->status === 'error')
                    <span class="chip" style="color: var(--danger);">Failed</span>
                @else
                    <span class="chip">{{ ucfirst($source->status) }}</span>
                @endif
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('kb.sources.download', $source->id) }}" class="btn btn-outline-secondary">
                <i class="bi bi-download"></i> Download
            </a>
            @if($canEdit)
                <form action="{{ route('kb.sources.reindex', $source->id) }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-repeat"></i> Re-index
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if($source->status === 'error' && $source->error_message)
        <div class="alert alert-danger">{{ $source->error_message }}</div>
    @endif

    <form action="{{ route('kb.sources.update', $source->id) }}" method="POST" class="mb-4">
        @csrf
        @method('PUT')
        <div class="card">
            <div class="card-header">Source</div>
            <div class="p-3">
                <div class="mb-3">
                    <label for="title" class="form-label">
                        {{ $source->type === 'qa' ? 'Question' : 'Title' }}
                    </label>
                    <input type="text" name="title" id="title" class="form-control"
                           maxlength="500" value="{{ old('title', $source->title) }}"
                           {{ $canEdit ? '' : 'disabled' }} required>
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">What is this about?</label>
                    <input type="text" name="description" id="description" class="form-control"
                           maxlength="1000" value="{{ old('description', $source->description) }}"
                           placeholder="Returns, warranty and shipping terms for retail customers"
                           {{ $canEdit ? '' : 'disabled' }}>
                    <div class="form-text">One line. It travels with every passage below.</div>
                </div>

                @if($source->type === 'file')
                    <div class="metrics">
                        <div><span class="text-muted">File</span><div class="figure-mono">{{ $source->title }}</div></div>
                        <div><span class="text-muted">Type</span><div class="figure-mono">{{ $source->file_mime }}</div></div>
                        <div><span class="text-muted">Size</span><div class="figure-mono">{{ number_format(($source->file_size ?? 0) / 1024) }} KB</div></div>
                    </div>
                    <div class="form-text mt-2">
                        The text comes from the file itself. Download it, change it, and upload it again to replace the wording.
                    </div>
                @else
                    <div class="mb-0">
                        <label for="body" class="form-label">
                            {{ $source->type === 'qa' ? 'Answer' : 'Content' }}
                        </label>
                        <textarea name="body" id="body" class="form-control" rows="14"
                                  {{ $canEdit ? '' : 'disabled' }} required>{{ old('body', $source->body) }}</textarea>
                    </div>
                @endif
            </div>
        </div>

        @if($canEdit)
            <div class="form-actions">
                <span class="text-muted d-none d-sm-inline" style="font-size: 0.75rem;">Saving re-indexes this source.</span>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <button type="submit" class="btn btn-brand">Save and re-index</button>
                </div>
            </div>
        @endif
    </form>

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span>Passages</span>
            <span class="chip figure-mono">{{ count($chunks) }}</span>
        </div>

        @if(count($chunks) === 0)
            <div class="empty">
                <i class="bi bi-layers"></i>
                <h6>Nothing indexed yet</h6>
                <p>This source has produced no passages. If its status is failed, the message above says why.</p>
            </div>
        @else
            <div>
                @foreach($chunks as $chunk)
                    <div class="p-3" style="{{ !$loop->last ? 'border-bottom: 1px solid var(--border);' : '' }}">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-1.5">
                            <span class="fw-semibold figure-mono" style="font-size: 0.8125rem;">#{{ $chunk->ordinal }}</span>
                            <span class="chip figure-mono">{{ $chunk->char_count }} chars</span>
                        </div>
                        @if(!empty($chunk->heading_path))
                            <div class="mb-1"><span class="chip">{{ $chunk->heading_path }}</span></div>
                        @endif
                        <pre class="text-muted mb-0" style="font-size: 0.78125rem; line-height: 1.6; white-space: pre-wrap; word-break: break-word;">{{ $chunk->content }}</pre>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</div>
@endsection
```

- [ ] **Step 6: Add the row actions**

In `admin-laravel/resources/views/kb/show.blade.php`, make the title a link.
Replace the title div:

```blade
                                <div class="fw-semibold text-truncate" style="max-width: 420px;">
                                    <a href="{{ route('kb.sources.show', $source->id) }}">{{ $source->title }}</a>
                                </div>
```

Then put view and download in front of the existing actions, and show them to
viewers as well as editors. Replace the whole `<td class="text-end">` block:

```blade
                            <td class="text-end">
                                <div class="d-flex align-items-center justify-content-end gap-1.5">
                                    <a href="{{ route('kb.sources.show', $source->id) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="{{ route('kb.sources.download', $source->id) }}"
                                       class="btn btn-sm btn-outline-secondary" title="Download">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    @if($canEdit)
                                        <form action="{{ route('kb.sources.reindex', $source->id) }}" method="POST" class="d-inline m-0">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Index again">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                        </form>
                                        <form action="{{ route('kb.sources.destroy', $source->id) }}" method="POST"
                                              onsubmit="return confirm('Remove this source?');" class="d-inline m-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
```

- [ ] **Step 7: Run the Laravel suite**

```bash
php artisan test
```

Expected: 65 passed.

- [ ] **Step 8: Commit**

```bash
git add admin-laravel/routes/web.php \
        admin-laravel/app/Http/Controllers/KnowledgeBaseController.php \
        admin-laravel/resources/views/kb/source.blade.php \
        admin-laravel/resources/views/kb/show.blade.php \
        admin-laravel/tests/Feature/KbSourceDetailTest.php
git commit -m "feat: read, correct and export a knowledge base source"
```

---

### Task 6: The add-content cards

**Files:**
- Modify: `admin-laravel/resources/views/kb/show.blade.php`
- Test: `admin-laravel/tests/Feature/KbSourceDetailTest.php` (append)

**Interfaces:**
- Consumes: the three existing modals, unchanged in behaviour.
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Write the failing test**

Append to `admin-laravel/tests/Feature/KbSourceDetailTest.php`:

```php
    public function test_the_collection_page_explains_the_three_ways_to_add_content(): void
    {
        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.show', 'kbc_1'))
            ->assertOk()
            ->assertSee('Policies, guides, anything you can copy in')
            ->assertSee('PDF, Word, PowerPoint, Excel, CSV or Markdown')
            ->assertSee('One question with its exact answer, kept whole');
    }

    public function test_a_viewer_is_not_offered_the_add_cards(): void
    {
        $this->actingAs($this->user('viewer', 'viewer@test.com'))
            ->get(route('kb.show', 'kbc_1'))
            ->assertOk()
            ->assertDontSee('Policies, guides, anything you can copy in');
    }
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test --filter=explains_the_three_ways
```

Expected: fails, the strings are not on the page.

- [ ] **Step 3: Replace the header buttons with cards**

In `admin-laravel/resources/views/kb/show.blade.php`, delete the three
`data-bs-toggle="modal"` buttons from the page header, leaving the header with
the collection name and its description only.

Then insert this panel directly above the source table, inside the
`@if($canEdit)` guard:

```blade
    @if($canEdit)
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-4">
                <button type="button" class="card w-100 h-100 text-start p-3"
                        data-bs-toggle="modal" data-bs-target="#addTextModal">
                    <i class="bi bi-file-text d-block mb-2" style="font-size: 1.25rem; color: var(--accent);"></i>
                    <div class="fw-semibold mb-1">Paste text</div>
                    <div class="text-muted" style="font-size: 0.78125rem;">Policies, guides, anything you can copy in</div>
                </button>
            </div>
            <div class="col-12 col-md-4">
                <button type="button" class="card w-100 h-100 text-start p-3"
                        data-bs-toggle="modal" data-bs-target="#addFileModal">
                    <i class="bi bi-file-earmark-arrow-up d-block mb-2" style="font-size: 1.25rem; color: var(--accent);"></i>
                    <div class="fw-semibold mb-1">Upload a file</div>
                    <div class="text-muted" style="font-size: 0.78125rem;">PDF, Word, PowerPoint, Excel, CSV or Markdown</div>
                </button>
            </div>
            <div class="col-12 col-md-4">
                <button type="button" class="card w-100 h-100 text-start p-3"
                        data-bs-toggle="modal" data-bs-target="#addQaModal">
                    <i class="bi bi-chat-square-quote d-block mb-2" style="font-size: 1.25rem; color: var(--accent);"></i>
                    <div class="fw-semibold mb-1">Question and answer</div>
                    <div class="text-muted" style="font-size: 0.78125rem;">One question with its exact answer, kept whole</div>
                </button>
            </div>
        </div>
    @endif
```

- [ ] **Step 4: Give the cards a hover state**

In `admin-laravel/public/css/console.css`, append:

```css
/* Add-content cards: a card that is also a button needs to say so. */
button.card {
  cursor: pointer;
  transition: border-color 0.12s ease, transform 0.12s ease;
}
button.card:hover {
  border-color: var(--accent);
  transform: translateY(-1px);
}
button.card:active {
  transform: translateY(0);
}
```

`button` elements do not inherit the card background in every browser, so set it
explicitly in that rule if the cards render transparent:
`background: var(--surface);`.

- [ ] **Step 5: Run the Laravel suite**

```bash
php artisan test
```

Expected: 67 passed.

- [ ] **Step 6: Commit**

```bash
git add admin-laravel/resources/views/kb/show.blade.php \
        admin-laravel/public/css/console.css \
        admin-laravel/tests/Feature/KbSourceDetailTest.php
git commit -m "feat: say what each way of adding content is for"
```

---

### Task 7: The embedding model list

**Files:**
- Modify: `api-engine/kb/embedding.py`
- Modify: `api-engine/routers/kb.py`
- Modify: `api-engine/tests/test_embedding.py`
- Modify: `admin-laravel/app/Services/EngineClient.php`
- Modify: `admin-laravel/app/Http/Controllers/AdminSettingsController.php`
- Modify: `admin-laravel/routes/web.php`
- Modify: `admin-laravel/resources/views/admin/settings.blade.php`
- Create: `admin-laravel/tests/Feature/EmbeddingModelListTest.php`

**Interfaces:**
- Consumes: `EmbeddingClient` from phase 1.
- Produces:
  - `EmbeddingClient.list_models(transport=None) -> list[str]`
  - `POST /api/v1/kb/embedding/models` returning `{ok, models, message}`
  - `EngineClient::listEmbeddingModels(string $baseUrl, string $apiKey): array`
  - Route `admin.settings.models`.

- [ ] **Step 1: Write the failing Python tests**

Append to `api-engine/tests/test_embedding.py`:

```python
@pytest.mark.asyncio
async def test_list_models_puts_embedding_models_first():
    def handler(request):
        assert request.url.path.endswith("/models")
        return httpx.Response(200, json={"data": [
            {"id": "llama3.2:1b"},
            {"id": "nomic-embed-text:latest"},
            {"id": "gemma3:1b"},
            {"id": "mxbai-embed-large"},
        ]})

    client = EmbeddingClient("http://engine/v1", "", "nomic-embed-text")
    models = await client.list_models(transport=httpx.MockTransport(handler))

    assert models[:2] == ["mxbai-embed-large", "nomic-embed-text:latest"]
    assert models[2:] == ["gemma3:1b", "llama3.2:1b"]


@pytest.mark.asyncio
async def test_list_models_sends_the_api_key_when_there_is_one():
    seen = {}

    def handler(request):
        seen["auth"] = request.headers.get("Authorization")
        return httpx.Response(200, json={"data": []})

    client = EmbeddingClient("http://engine/v1", "secret", "m")
    await client.list_models(transport=httpx.MockTransport(handler))

    assert seen["auth"] == "Bearer secret"


@pytest.mark.asyncio
async def test_list_models_ignores_rows_without_an_id():
    def handler(request):
        return httpx.Response(200, json={"data": [{"id": "a"}, {"object": "model"}]})

    client = EmbeddingClient("http://engine/v1", "", "m")
    assert await client.list_models(transport=httpx.MockTransport(handler)) == ["a"]


@pytest.mark.asyncio
async def test_list_models_raises_on_a_failed_response():
    def handler(request):
        return httpx.Response(500, text="boom")

    client = EmbeddingClient("http://engine/v1", "", "m")
    with pytest.raises(httpx.HTTPStatusError):
        await client.list_models(transport=httpx.MockTransport(handler))
```

If `httpx` and `pytest` are not already imported at the top of that file, add
them.

- [ ] **Step 2: Run them to verify they fail**

```bash
.venv/Scripts/python -m pytest tests/test_embedding.py -q
```

Expected: `AttributeError: 'EmbeddingClient' object has no attribute 'list_models'`.

- [ ] **Step 3: Add `list_models`**

In `api-engine/kb/embedding.py`, add the method to `EmbeddingClient`:

```python
    async def list_models(self, transport=None) -> list[str]:
        """Model ids the provider offers, embedding-looking ones first.

        The OpenAI-compatible response carries no flag marking which models can
        embed, so the ordering is a hint. It must never be a filter: a provider
        may name an embedding model without the word in it.
        """
        headers = {}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"

        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            response = await client.get(f"{self.base_url}/models", headers=headers)
            response.raise_for_status()
            payload = response.json()

        ids = [row["id"] for row in payload.get("data", []) if row.get("id")]
        return sorted(ids, key=lambda name: (0 if "embed" in name.lower() else 1,
                                             name.lower()))
```

- [ ] **Step 4: Add the engine route**

In `api-engine/routers/kb.py`, after the `embedding/test` route:

```python
class EmbeddingModelsRequest(BaseModel):
    base_url: str
    api_key: str = ""


@router.post("/embedding/models", dependencies=[Depends(require_admin_token)])
async def list_embedding_models(req: EmbeddingModelsRequest):
    client = EmbeddingClient(req.base_url, req.api_key, "")
    try:
        models = await client.list_models()
    except Exception as exc:
        return {"ok": False, "models": [], "message": str(exc)[:300]}
    return {"ok": True, "models": models,
            "message": f"{len(models)} models available."}
```

- [ ] **Step 5: Run the Python suite**

```bash
.venv/Scripts/python -m pytest -q
```

Expected: 146 passed.

- [ ] **Step 6: Write the failing Laravel test**

Create `admin-laravel/tests/Feature/EmbeddingModelListTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingModelListTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'root@test.com'],
            ['name' => 'Root', 'password' => bcrypt('password'),
             'global_role' => 'super_admin'],
        );
    }

    public function test_a_super_admin_can_fetch_the_model_list(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => true, 'models' => ['nomic-embed-text', 'llama3.2:1b'],
            'message' => '2 models available.',
        ], 200)]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.settings.models'), [
                'embedding_base_url' => 'http://localhost:11434/v1',
                'embedding_api_key' => '',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'models' => ['nomic-embed-text', 'llama3.2:1b']]);
    }

    public function test_an_unreachable_provider_is_reported_not_thrown(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.settings.models'), [
                'embedding_base_url' => 'http://localhost:11434/v1',
                'embedding_api_key' => '',
            ])
            ->assertOk()
            ->assertJson(['ok' => false]);
    }

    public function test_a_system_admin_cannot_fetch_the_model_list(): void
    {
        $system = System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach($system->id, ['role' => 'system_admin']);

        $this->actingAs($user)
            ->postJson(route('admin.settings.models'), [
                'embedding_base_url' => 'http://localhost:11434/v1',
            ])
            ->assertForbidden();
    }

    public function test_the_settings_page_offers_a_fetch_button(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Fetch models')
            ->assertSee('embedding_model_options');
    }
}
```

- [ ] **Step 7: Run it to verify it fails**

```bash
php artisan test --filter=EmbeddingModelListTest
```

Expected: `Route [admin.settings.models] not defined.`

- [ ] **Step 8: Add the engine client method**

In `admin-laravel/app/Services/EngineClient.php`, after `testEmbedding`:

```php
    public static function listEmbeddingModels(string $baseUrl, string $apiKey): array
    {
        try {
            $response = self::request()->post(self::base() . '/api/v1/kb/embedding/models', [
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
            ]);

            return $response->successful()
                ? $response->json()
                : ['ok' => false, 'models' => [],
                   'message' => 'Engine returned HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'models' => [],
                    'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }
```

- [ ] **Step 9: Add the controller action and the route**

In `admin-laravel/app/Http/Controllers/AdminSettingsController.php`, after
`test`:

```php
    public function models(Request $request)
    {
        $validated = $request->validate([
            'embedding_base_url' => ['required', 'string'],
            'embedding_api_key' => ['nullable', 'string'],
        ]);

        return response()->json(EngineClient::listEmbeddingModels(
            $validated['embedding_base_url'],
            $validated['embedding_api_key'] ?? '',
        ));
    }
```

In `admin-laravel/routes/web.php`, beside the other admin settings routes:

```php
        Route::post('/admin/settings/models', [AdminSettingsController::class, 'models'])->name('admin.settings.models');
```

- [ ] **Step 10: Make the model field a list**

In `admin-laravel/resources/views/admin/settings.blade.php`, replace the Model
field block with an input bound to a datalist and a fetch button:

```blade
                    <div class="col-12 col-sm-8">
                        <label for="embedding_model" class="form-label">Model</label>
                        <div class="input-group">
                            <input type="text" name="embedding_model" id="embedding_model"
                                   class="form-control font-monospace" list="embedding_model_options"
                                   value="{{ old('embedding_model', $settings['embedding_model']) }}" required>
                            <button type="button" class="btn btn-outline-secondary" onclick="fetchEmbeddingModels()">
                                <i class="bi bi-arrow-clockwise"></i> Fetch models
                            </button>
                        </div>
                        <datalist id="embedding_model_options"></datalist>
                        <div class="form-text" id="embeddingModelsResult">
                            Fetch the list from the provider, or type a name if it does not publish one.
                        </div>
                    </div>
```

Keep the Dimensions field in the column beside it. Adjust its wrapping column
class so the two still share a row.

Add the fetch function to the pushed script block, next to `testEmbedding`:

```javascript
    function fetchEmbeddingModels() {
        var out = document.getElementById('embeddingModelsResult');
        var list = document.getElementById('embedding_model_options');
        out.className = 'form-text';
        out.textContent = 'Fetching...';

        fetch('{{ route('admin.settings.models') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                embedding_base_url: document.getElementById('embedding_base_url').value,
                embedding_api_key: document.getElementById('embedding_api_key').value
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            list.innerHTML = '';
            (data.models || []).forEach(function (name) {
                var option = document.createElement('option');
                option.value = name;
                list.appendChild(option);
            });
            out.className = 'form-text ' + (data.ok ? 'text-success' : 'text-danger');
            out.textContent = data.ok
                ? (data.models || []).length + ' found. Click the field to choose one.'
                : (data.message || 'Could not list models.');
        })
        .catch(function () {
            out.className = 'form-text text-danger';
            out.textContent = 'Could not reach the admin portal.';
        });
    }
```

- [ ] **Step 11: Run both suites**

```bash
php artisan test
```

Expected: 71 passed.

```bash
cd ../api-engine && .venv/Scripts/python -m pytest -q
```

Expected: 146 passed.

- [ ] **Step 12: Commit**

```bash
git add api-engine/kb/embedding.py api-engine/routers/kb.py api-engine/tests/test_embedding.py \
        admin-laravel/app/Services/EngineClient.php \
        admin-laravel/app/Http/Controllers/AdminSettingsController.php \
        admin-laravel/routes/web.php \
        admin-laravel/resources/views/admin/settings.blade.php \
        admin-laravel/tests/Feature/EmbeddingModelListTest.php
git commit -m "feat: fetch the embedding model list from the provider"
```

---

### Task 8: Verify end to end and capture screenshots

**Files:**
- No source changes. This task produces evidence.

**Interfaces:**
- Consumes: everything from Tasks 1 to 7.
- Produces: screenshots under `docs/superpowers/screenshots/`.

- [ ] **Step 1: Start the stack**

`start-dev.bat` has been unreliable at spawning the servers. Start them directly
and confirm both answer:

```bash
curl -s http://127.0.0.1:8000/health
curl -s -o /dev/null -w "laravel:%{http_code}\n" http://127.0.0.1:8080
```

Expected: `"database":"postgresql"`, and a 302 from Laravel. If the engine
reports `sqlite`, stop and fix the connection before continuing.

- [ ] **Step 2: Give an existing source a description**

Open the Customer Policy source added in phase 3, set its description to
`Returns, warranty and shipping terms for retail customers`, and save. Confirm
the status returns to Indexed.

- [ ] **Step 3: Check the description reached the chunks**

```bash
cd admin-laravel && php artisan tinker --execute="
foreach (DB::table('kb_chunks')->where('source_id', function (\$q) {
    \$q->select('id')->from('kb_sources')->where('title', 'Customer Policy');
})->orderBy('ordinal')->get(['ordinal','content']) as \$c) {
  echo \$c->ordinal.': '.str_replace(chr(10), ' / ', substr(\$c->content, 0, 100)).PHP_EOL;
}"
```

Expected: each chunk begins `Section: Customer Policy > ...` then
`About: Returns, warranty and shipping terms for retail customers`.

- [ ] **Step 4: Verify the gate against the running engine**

Point the demo bot at an installed model, ask it two things, then set it back:

```bash
php artisan tinker --execute="\$b=App\Models\BotProfile::find('bot_demo_default'); \$b->model_name='llama3.2:1b'; \$b->save();"
```

```bash
curl -s -N -m 60 -X POST http://127.0.0.1:8000/api/v1/chat/stream \
  -H "Content-Type: application/json" \
  -d '{"bot_id":"bot_demo_default","session_id":"gate-a","message":"hello","history":[]}' | head -4
```

Expected: no `sources` event, and a greeting.

```bash
curl -s -N -m 60 -X POST http://127.0.0.1:8000/api/v1/chat/stream \
  -H "Content-Type: application/json" \
  -d '{"bot_id":"bot_demo_default","session_id":"gate-b","message":"how long is the warranty on pendant fittings?","history":[]}' | head -4
```

Expected: a `sources` event, then an answer naming 36 months.

```bash
php artisan tinker --execute="\$b=App\Models\BotProfile::find('bot_demo_default'); \$b->model_name='llama3.2'; \$b->save();"
```

- [ ] **Step 5: Fetch the model list in the admin**

Open Admin Settings, click Fetch models, and confirm the field offers the six
installed models with the embedding one first.

- [ ] **Step 6: Capture screenshots**

Capture, into `docs/superpowers/screenshots/`, replacing the phase 3 files where
the page has changed:

- the collection page with the three add-content cards and the new row actions
- the source detail page showing the description and the indexed passages
- Admin Settings with the model list fetched

Playwright over the system Chrome works here; the bundled browser build does
not match the installed Playwright version:

```python
browser = p.chromium.launch(headless=True, channel="chrome")
```

- [ ] **Step 7: Run both suites one last time**

```bash
cd api-engine && .venv/Scripts/python -m pytest -q
cd ../admin-laravel && php artisan test
```

Expected: 146 Python passed, 71 Laravel passed.

- [ ] **Step 8: Commit the screenshots**

```bash
git add docs/superpowers/screenshots
git commit -m "docs: screenshots for knowledge base operations"
```

- [ ] **Step 9: Finish the branch**

**REQUIRED SUB-SKILL:** Use superpowers:finishing-a-development-branch. The base
branch is `main`.

---

## Notes for the implementer

**Why the gate is a fixed word list and not a model.** A model call before every
answer doubles the time before the first token appears, and the largest model
installed on this machine is 1.5B parameters, which classifies badly. The
vocabulary is small on purpose and contains no domain words. The question-mark
rule sits above it so a real question can never be swallowed.

**Why a gated message forces `answer_anyway`.** Without it, a bot configured to
say when the answer is missing would respond to "hi" by announcing that the
greeting is not in the available material. This is the single easiest thing to
get wrong in Task 2.

**Why the description is stored once and rebuilt into chunks.** The chunk text
is derived. Editing the description and re-indexing is the whole update path,
which is why saving a source always queues a re-index.

**Why Laravel reads `kb_chunks` directly.** Phase 1 gave chunk writes to the
engine. A read-only listing does not justify an HTTP hop, and the migration that
owns the table is Laravel's own. Writes stay with the engine.

**Test counts are a guide, not a contract.** The numbers in each step come from
counting the tests written in this plan. If your total differs by one or two,
confirm the tests you added are present and passing rather than chasing the
number.
