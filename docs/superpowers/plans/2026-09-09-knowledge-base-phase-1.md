# Knowledge Base Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bots answer from workspace-owned content, retrieved by hybrid search, with retrieval and generation tunable from the admin.

**Architecture:** Laravel owns the content and the screens; the FastAPI engine owns parsing, chunking, embedding and retrieval. They share one PostgreSQL database. Retrieval runs immediately before the existing model call: the question is embedded, a vector branch and a keyword branch each return candidates, Reciprocal Rank Fusion merges them by rank, and the survivors become a numbered context block on the system prompt. A `VectorStore` protocol keeps pgvector and SQLite interchangeable.

**Tech Stack:** PHP 8.4 / Laravel 13, Python 3.12 / FastAPI / SQLAlchemy 2 async, PostgreSQL 17.5 with pgvector 0.8.0, Ollama for embeddings via the OpenAI-compatible path, Bootstrap 5.3 with the existing `console.css` token layer.

**Spec:** `docs/superpowers/specs/2026-09-09-knowledge-base-rag-design.md`

## Global Constraints

- Embedding transport is `POST {embedding_base_url}/embeddings`, OpenAI-compatible. Default base URL `http://localhost:11434/v1`, default model `nomic-embed-text`, 768 dimensions.
- RRF constant is `k = 60`. Fused scores are small: a top hit in one branch scores `1/61 ≈ 0.0164`.
- Default chunk size 900 characters, overlap 150. A `qa` source is never split.
- Bot ids, collection ids, source ids are `string(36)`. Chunk ids are `bigint`.
- The engine's SQLAlchemy models and Laravel's migrations describe the same tables twice. Change both together, and check `information_schema` after.
- PHPUnit runs on `sqlite :memory:` (see `phpunit.xml`); production runs on PostgreSQL. Every migration must work on both.
- The embedding column is **not** mapped in SQLAlchemy. Each driver reads and writes it with raw SQL, because its type differs per database.
- Admin-plane engine routes require header `X-Admin-Token` matching env `ADMIN_API_TOKEN`. The widget's own routes stay public.
- No emoji in UI copy. Follow the existing `console.css` tokens; no new colour literals in views.
- Never commit `.env` or `database/database.sqlite`.

---

### Task 1: Python test harness and the chunker

**Files:**
- Modify: `api-engine/requirements.txt`
- Create: `api-engine/pytest.ini`
- Create: `api-engine/kb/__init__.py`
- Create: `api-engine/kb/chunking.py`
- Test: `api-engine/tests/test_chunking.py`

**Interfaces:**
- Consumes: nothing.
- Produces: `chunk_text(text: str, size: int = 900, overlap: int = 150) -> list[str]`

- [ ] **Step 1: Add the test and numeric dependencies**

Append to `api-engine/requirements.txt`:

```
numpy>=2.0.0
pytest>=8.0.0
pytest-asyncio>=0.23.0
```

Install: `cd api-engine && .venv/Scripts/python.exe -m pip install -r requirements.txt`

- [ ] **Step 2: Configure pytest**

Create `api-engine/pytest.ini`:

```ini
[pytest]
testpaths = tests
asyncio_mode = auto
filterwarnings =
    ignore::DeprecationWarning
```

- [ ] **Step 3: Write the failing tests**

Create `api-engine/tests/test_chunking.py`:

```python
from kb.chunking import chunk_text


def test_short_text_is_one_chunk():
    assert chunk_text("hello world", size=900, overlap=150) == ["hello world"]


def test_empty_text_yields_nothing():
    assert chunk_text("", size=900, overlap=150) == []
    assert chunk_text("   \n  ", size=900, overlap=150) == []


def test_splits_on_paragraph_before_hard_cutting():
    first = "a" * 500
    second = "b" * 500
    chunks = chunk_text(f"{first}\n\n{second}", size=600, overlap=0)
    assert chunks == [first, second]


def test_overlap_carries_tail_of_previous_chunk():
    text = "x" * 1000
    chunks = chunk_text(text, size=400, overlap=100)
    assert len(chunks) > 1
    # each chunk after the first starts with the last 100 chars of the one before
    assert chunks[1][:100] == chunks[0][-100:]


def test_no_chunk_exceeds_size():
    text = ("word " * 4000).strip()
    for chunk in chunk_text(text, size=300, overlap=50):
        assert len(chunk) <= 300


def test_no_empty_chunks():
    text = "para one\n\n\n\n\npara two"
    assert all(c.strip() for c in chunk_text(text, size=100, overlap=10))


def test_headings_are_preferred_boundaries():
    text = "## One\nalpha\n\n## Two\nbeta"
    chunks = chunk_text(text, size=20, overlap=0)
    assert chunks[0].startswith("## One")
    assert any(c.startswith("## Two") for c in chunks)
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_chunking.py -v`
Expected: FAIL, `ModuleNotFoundError: No module named 'kb'`

- [ ] **Step 5: Implement the chunker**

Create `api-engine/kb/__init__.py` as an empty file.

Create `api-engine/kb/chunking.py`:

```python
"""Split text into retrieval-sized pieces.

Prefers structural boundaries, falling back to progressively weaker ones and
finally to a hard character cut, so a chunk breaks where meaning breaks rather
than mid-word wherever possible.
"""

SEPARATORS = ["\n## ", "\n\n", "\n", ". ", " "]


def chunk_text(text: str, size: int = 900, overlap: int = 150) -> list[str]:
    if not text or not text.strip():
        return []
    if overlap >= size:
        raise ValueError("overlap must be smaller than size")

    pieces = _split(text.strip(), size)

    # Re-join adjacent pieces up to the size budget, then add the overlap tail.
    chunks: list[str] = []
    buffer = ""
    for piece in pieces:
        candidate = piece if not buffer else f"{buffer}{piece}"
        if len(candidate) <= size:
            buffer = candidate
            continue
        if buffer.strip():
            chunks.append(buffer.strip())
        buffer = (chunks[-1][-overlap:] + piece) if (chunks and overlap) else piece
        while len(buffer) > size:
            chunks.append(buffer[:size].strip())
            buffer = buffer[size - overlap:] if overlap else buffer[size:]
    if buffer.strip():
        chunks.append(buffer.strip())

    return [c for c in chunks if c.strip()]


def _split(text: str, size: int) -> list[str]:
    """Break text into fragments no larger than size, on the best boundary found."""
    if len(text) <= size:
        return [text]

    for sep in SEPARATORS:
        if sep not in text:
            continue
        parts = text.split(sep)
        out: list[str] = []
        for i, part in enumerate(parts):
            fragment = part if i == 0 else sep + part
            out.extend(_split(fragment, size) if len(fragment) > size else [fragment])
        return out

    return [text[i:i + size] for i in range(0, len(text), size)]
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_chunking.py -v`
Expected: PASS, 7 passed

- [ ] **Step 7: Commit**

```bash
git add api-engine/requirements.txt api-engine/pytest.ini api-engine/kb api-engine/tests
git commit -m "feat: add chunker and python test harness"
```

---

### Task 2: Reciprocal Rank Fusion

**Files:**
- Create: `api-engine/kb/fusion.py`
- Test: `api-engine/tests/test_fusion.py`

**Interfaces:**
- Consumes: nothing.
- Produces: `fuse_rankings(branches: list[list[str]], k: int = 60) -> list[tuple[str, float]]` returning `(id, score)` sorted by score descending.

- [ ] **Step 1: Write the failing tests**

Create `api-engine/tests/test_fusion.py`:

```python
from kb.fusion import fuse_rankings


def test_single_branch_preserves_order():
    result = fuse_rankings([["a", "b", "c"]])
    assert [item for item, _ in result] == ["a", "b", "c"]


def test_top_of_one_branch_scores_one_over_sixty_one():
    result = fuse_rankings([["a"]])
    assert abs(result[0][1] - 1 / 61) < 1e-9


def test_appearing_in_both_branches_outranks_either_alone():
    # "b" is second in both; "a" is first in one and absent from the other
    result = fuse_rankings([["a", "b"], ["c", "b"]])
    ranked = [item for item, _ in result]
    assert ranked[0] == "b"


def test_empty_branches_yield_nothing():
    assert fuse_rankings([]) == []
    assert fuse_rankings([[], []]) == []


def test_ignores_an_empty_branch():
    result = fuse_rankings([["a", "b"], []])
    assert [item for item, _ in result] == ["a", "b"]


def test_scores_descend():
    result = fuse_rankings([["a", "b", "c"], ["b", "a"]])
    scores = [score for _, score in result]
    assert scores == sorted(scores, reverse=True)
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_fusion.py -v`
Expected: FAIL, `ModuleNotFoundError: No module named 'kb.fusion'`

- [ ] **Step 3: Implement fusion**

Create `api-engine/kb/fusion.py`:

```python
"""Reciprocal Rank Fusion.

Merges rankings by position rather than score. A vector similarity and a BM25
score are not comparable numbers, so averaging them is meaningless; their ranks
always are.
"""


def fuse_rankings(branches: list[list[str]], k: int = 60) -> list[tuple[str, float]]:
    scores: dict[str, float] = {}
    for branch in branches:
        for rank, item_id in enumerate(branch, start=1):
            scores[item_id] = scores.get(item_id, 0.0) + 1.0 / (k + rank)

    return sorted(scores.items(), key=lambda pair: pair[1], reverse=True)
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_fusion.py -v`
Expected: PASS, 6 passed

- [ ] **Step 5: Commit**

```bash
git add api-engine/kb/fusion.py api-engine/tests/test_fusion.py
git commit -m "feat: add reciprocal rank fusion"
```

---

### Task 3: Embedding client

**Files:**
- Create: `api-engine/kb/embedding.py`
- Test: `api-engine/tests/test_embedding.py`

**Interfaces:**
- Consumes: nothing.
- Produces: `EmbeddingClient(base_url: str, api_key: str, model: str)` with `async embed(texts: list[str]) -> list[list[float]]` returning unit-normalised vectors, and `normalise(vector: list[float]) -> list[float]`.

- [ ] **Step 1: Write the failing tests**

Create `api-engine/tests/test_embedding.py`:

```python
import math

import httpx
import pytest

from kb.embedding import EmbeddingClient, normalise


def test_normalise_gives_unit_length():
    out = normalise([3.0, 4.0])
    assert abs(math.sqrt(sum(x * x for x in out)) - 1.0) < 1e-9
    assert abs(out[0] - 0.6) < 1e-9


def test_normalise_leaves_a_zero_vector_alone():
    assert normalise([0.0, 0.0]) == [0.0, 0.0]


@pytest.mark.asyncio
async def test_embed_posts_to_the_openai_shape_and_normalises():
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["auth"] = request.headers.get("authorization")
        seen["body"] = request.read().decode()
        return httpx.Response(200, json={"data": [
            {"embedding": [3.0, 4.0]},
            {"embedding": [0.0, 5.0]},
        ]})

    client = EmbeddingClient("http://fake/v1", "secret", "nomic-embed-text")
    vectors = await client.embed(["one", "two"], transport=httpx.MockTransport(handler))

    assert seen["url"] == "http://fake/v1/embeddings"
    assert seen["auth"] == "Bearer secret"
    assert '"model": "nomic-embed-text"' in seen["body"]
    assert abs(vectors[0][0] - 0.6) < 1e-9
    assert vectors[1] == [0.0, 1.0]


@pytest.mark.asyncio
async def test_embed_with_no_texts_makes_no_request():
    def handler(request):
        raise AssertionError("should not have been called")

    client = EmbeddingClient("http://fake/v1", "", "m")
    assert await client.embed([], transport=httpx.MockTransport(handler)) == []


@pytest.mark.asyncio
async def test_embed_raises_on_a_bad_response():
    def handler(request):
        return httpx.Response(500, text="boom")

    client = EmbeddingClient("http://fake/v1", "", "m")
    with pytest.raises(httpx.HTTPStatusError):
        await client.embed(["x"], transport=httpx.MockTransport(handler))
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_embedding.py -v`
Expected: FAIL, `ModuleNotFoundError: No module named 'kb.embedding'`

- [ ] **Step 3: Implement the client**

Create `api-engine/kb/embedding.py`:

```python
"""Embedding over the OpenAI-compatible path.

One transport serves Ollama and any remote provider, which is why this speaks
/v1/embeddings rather than Ollama's native /api/embed.

Vectors are stored normalised so cosine similarity reduces to a dot product.
"""
import math

import httpx

TIMEOUT = httpx.Timeout(120.0, connect=10.0)


def normalise(vector: list[float]) -> list[float]:
    length = math.sqrt(sum(x * x for x in vector))
    if length == 0.0:
        return list(vector)
    return [x / length for x in vector]


class EmbeddingClient:
    def __init__(self, base_url: str, api_key: str, model: str):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key or ""
        self.model = model

    async def embed(self, texts: list[str], transport=None) -> list[list[float]]:
        if not texts:
            return []

        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"

        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            response = await client.post(
                f"{self.base_url}/embeddings",
                headers=headers,
                json={"model": self.model, "input": texts},
            )
            response.raise_for_status()
            payload = response.json()

        return [normalise(row["embedding"]) for row in payload["data"]]
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_embedding.py -v`
Expected: PASS, 5 passed

- [ ] **Step 5: Commit**

```bash
git add api-engine/kb/embedding.py api-engine/tests/test_embedding.py
git commit -m "feat: add embedding client"
```

---

### Task 4: Laravel schema and models

**Files:**
- Create: `admin-laravel/database/migrations/2026_09_10_000001_create_knowledge_base_tables.php`
- Create: `admin-laravel/database/migrations/2026_09_10_000002_add_brain_settings_to_bot_profiles.php`
- Create: `admin-laravel/app/Models/KbCollection.php`
- Create: `admin-laravel/app/Models/KbSource.php`
- Create: `admin-laravel/app/Models/AppSetting.php`
- Modify: `admin-laravel/app/Models/BotProfile.php`
- Modify: `admin-laravel/app/Models/System.php`
- Test: `admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: tables `kb_collections`, `kb_sources`, `kb_chunks`, `bot_kb_collection`, `app_settings`; models `KbCollection` (`system`, `sources`, `bots`), `KbSource` (`collection`), `AppSetting` (`get(string $key, $default = null)`, `put(string $key, $value)`); `BotProfile::collections()`; `System::kbCollections()`.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\BotProfile;
use App\Models\KbCollection;
use App\Models\KbSource;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeSystem(): System
    {
        return System::create([
            'id' => 'sys_test',
            'name' => 'Test Workspace',
            'allowed_origins' => '*',
        ]);
    }

    public function test_a_collection_belongs_to_a_workspace(): void
    {
        $system = $this->makeSystem();
        $collection = KbCollection::create([
            'id' => 'kbc_1',
            'system_id' => $system->id,
            'name' => 'Refund policy',
        ]);

        $this->assertSame($system->id, $collection->system->id);
        $this->assertCount(1, $system->fresh()->kbCollections);
    }

    public function test_a_source_belongs_to_a_collection_and_defaults_to_pending(): void
    {
        $system = $this->makeSystem();
        KbCollection::create(['id' => 'kbc_1', 'system_id' => $system->id, 'name' => 'C']);

        $source = KbSource::create([
            'id' => 'kbs_1',
            'collection_id' => 'kbc_1',
            'type' => 'text',
            'title' => 'Policy',
            'body' => 'Refunds within 30 days.',
        ]);

        $this->assertSame('pending', $source->status);
        $this->assertSame(0, $source->chunk_count);
        $this->assertSame('kbc_1', $source->collection->id);
    }

    public function test_a_bot_reads_from_many_collections(): void
    {
        $system = $this->makeSystem();
        KbCollection::create(['id' => 'kbc_1', 'system_id' => $system->id, 'name' => 'One']);
        KbCollection::create(['id' => 'kbc_2', 'system_id' => $system->id, 'name' => 'Two']);

        $bot = BotProfile::create([
            'id' => 'test_chat_01',
            'system_id' => $system->id,
            'name' => 'Bot',
        ]);
        $bot->collections()->sync(['kbc_1', 'kbc_2']);

        $this->assertCount(2, $bot->fresh()->collections);
    }

    public function test_bot_brain_settings_have_defaults(): void
    {
        $system = $this->makeSystem();
        $bot = BotProfile::create([
            'id' => 'test_chat_02',
            'system_id' => $system->id,
            'name' => 'Bot',
        ]);

        $this->assertFalse($bot->retrieval_enabled);
        $this->assertSame('hybrid', $bot->retrieval_mode);
        $this->assertSame(5, $bot->retrieval_top_k);
        $this->assertSame(30, $bot->retrieval_candidates);
        $this->assertSame('say_unknown', $bot->retrieval_fallback);
        $this->assertSame('off', $bot->thinking_level);
        $this->assertEqualsWithDelta(1.0, $bot->top_p, 0.0001);
    }

    public function test_app_settings_read_write_and_default(): void
    {
        $this->assertSame('fallback', AppSetting::get('nothing_here', 'fallback'));

        AppSetting::put('embedding_model', 'nomic-embed-text');
        $this->assertSame('nomic-embed-text', AppSetting::get('embedding_model'));

        AppSetting::put('embedding_model', 'other-model');
        $this->assertSame('other-model', AppSetting::get('embedding_model'));
        $this->assertDatabaseCount('app_settings', 1);
    }

    public function test_deleting_a_collection_removes_its_sources(): void
    {
        $system = $this->makeSystem();
        KbCollection::create(['id' => 'kbc_1', 'system_id' => $system->id, 'name' => 'C']);
        KbSource::create([
            'id' => 'kbs_1', 'collection_id' => 'kbc_1',
            'type' => 'text', 'title' => 'T', 'body' => 'B',
        ]);

        KbCollection::find('kbc_1')->delete();

        $this->assertDatabaseCount('kb_sources', 0);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin-laravel && php artisan test --filter=KnowledgeBaseSchemaTest`
Expected: FAIL, `Class "App\Models\KbCollection" not found`

- [ ] **Step 3: Write the knowledge base migration**

Create `admin-laravel/database/migrations/2026_09_10_000001_create_knowledge_base_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_collections', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('system_id', 36);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
        });

        Schema::create('kb_sources', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('collection_id', 36);
            $table->string('type', 20)->default('text');   // text, file, qa; website reserved
            $table->string('title', 500);
            $table->longText('body')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->foreign('collection_id')->references('id')->on('kb_collections')->cascadeOnDelete();
        });

        Schema::create('kb_chunks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('collection_id', 36)->index();
            $table->string('source_id', 36);
            $table->unsignedInteger('ordinal');
            $table->text('content');
            $table->unsignedInteger('char_count')->default(0);
            $table->string('embedding_model', 120)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('source_id')->references('id')->on('kb_sources')->cascadeOnDelete();
            $table->index(['collection_id', 'source_id']);
        });

        // The embedding column has no Blueprint equivalent, and its type differs
        // per database, so each driver gets its own raw statement.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            DB::statement('ALTER TABLE kb_chunks ADD COLUMN embedding vector(768)');
        } else {
            DB::statement('ALTER TABLE kb_chunks ADD COLUMN embedding blob');
        }

        Schema::create('bot_kb_collection', function (Blueprint $table) {
            $table->id();
            $table->string('bot_id', 36);
            $table->string('collection_id', 36);

            $table->foreign('bot_id')->references('id')->on('bot_profiles')->cascadeOnDelete();
            $table->foreign('collection_id')->references('id')->on('kb_collections')->cascadeOnDelete();
            $table->unique(['bot_id', 'collection_id']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('bot_kb_collection');
        Schema::dropIfExists('kb_chunks');
        Schema::dropIfExists('kb_sources');
        Schema::dropIfExists('kb_collections');
    }
};
```

- [ ] **Step 4: Write the bot settings migration**

Create `admin-laravel/database/migrations/2026_09_10_000002_add_brain_settings_to_bot_profiles.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('retrieval_enabled')->default(false);
            $table->string('retrieval_mode', 20)->default('hybrid');   // hybrid, vector, keyword
            $table->unsignedSmallInteger('retrieval_top_k')->default(5);
            $table->unsignedSmallInteger('retrieval_candidates')->default(30);
            $table->float('retrieval_min_score')->default(0);
            $table->string('retrieval_fallback', 20)->default('say_unknown'); // or answer_anyway

            $table->float('top_p')->default(1);
            $table->unsignedSmallInteger('top_k_sampling')->nullable();
            $table->float('presence_penalty')->default(0);
            $table->float('frequency_penalty')->default(0);
            $table->string('thinking_level', 10)->default('off');       // off, low, medium, high
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'retrieval_enabled', 'retrieval_mode', 'retrieval_top_k',
                'retrieval_candidates', 'retrieval_min_score', 'retrieval_fallback',
                'top_p', 'top_k_sampling', 'presence_penalty',
                'frequency_penalty', 'thinking_level',
            ]);
        });
    }
};
```

- [ ] **Step 5: Write the models**

Create `admin-laravel/app/Models/KbCollection.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KbCollection extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'system_id', 'name', 'description'];

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class, 'system_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(KbSource::class, 'collection_id');
    }

    public function bots(): BelongsToMany
    {
        return $this->belongsToMany(BotProfile::class, 'bot_kb_collection', 'collection_id', 'bot_id');
    }
}
```

Create `admin-laravel/app/Models/KbSource.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KbSource extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'collection_id', 'type', 'title', 'body',
        'file_path', 'file_mime', 'file_size',
        'status', 'error_message', 'chunk_count', 'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_count' => 'integer',
            'file_size' => 'integer',
            'indexed_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(KbCollection::class, 'collection_id');
    }
}
```

Create `admin-laravel/app/Models/AppSetting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    /** Defaults live here so a fresh install needs no seeding. */
    public const DEFAULTS = [
        'embedding_base_url' => 'http://localhost:11434/v1',
        'embedding_api_key' => '',
        'embedding_model' => 'nomic-embed-text',
        'embedding_dimensions' => '768',
        'vector_driver' => 'pgvector',
        'chunk_size' => '900',
        'chunk_overlap' => '150',
    ];

    public static function get(string $key, $default = null)
    {
        $row = Cache::remember("app_setting:{$key}", 300, function () use ($key) {
            return static::query()->find($key)?->value;
        });

        if ($row !== null) {
            return $row;
        }

        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget("app_setting:{$key}");
    }
}
```

- [ ] **Step 6: Wire the relations onto the existing models**

In `admin-laravel/app/Models/System.php`, add this method inside the class:

```php
    public function kbCollections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(KbCollection::class, 'system_id');
    }
```

In `admin-laravel/app/Models/BotProfile.php`, add this method inside the class:

```php
    public function collections(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(KbCollection::class, 'bot_kb_collection', 'bot_id', 'collection_id');
    }
```

In the same file, add these entries to the `$fillable` array:

```php
        'retrieval_enabled',
        'retrieval_mode',
        'retrieval_top_k',
        'retrieval_candidates',
        'retrieval_min_score',
        'retrieval_fallback',
        'top_p',
        'top_k_sampling',
        'presence_penalty',
        'frequency_penalty',
        'thinking_level',
```

And these to the array returned by `casts()`:

```php
            'retrieval_enabled' => 'boolean',
            'retrieval_top_k' => 'integer',
            'retrieval_candidates' => 'integer',
            'retrieval_min_score' => 'float',
            'top_p' => 'float',
            'top_k_sampling' => 'integer',
            'presence_penalty' => 'float',
            'frequency_penalty' => 'float',
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `cd admin-laravel && php artisan test --filter=KnowledgeBaseSchemaTest`
Expected: PASS, 6 passed

- [ ] **Step 8: Run the migration against the real database**

Run: `cd admin-laravel && php artisan migrate`
Expected: both migrations report DONE

Verify the vector column exists:

```bash
cd api-engine && .venv/Scripts/python.exe -c "
import asyncio, asyncpg
async def m():
    c = await asyncpg.connect(user='postgres', password='postgres', host='127.0.0.1', port=5432, database='chatbot_hub')
    print(await c.fetchval(\"select format_type(atttypid, atttypmod) from pg_attribute where attrelid='kb_chunks'::regclass and attname='embedding'\"))
    await c.close()
asyncio.run(m())"
```

Expected: `vector(768)`

- [ ] **Step 9: Commit**

```bash
git add admin-laravel/database/migrations admin-laravel/app/Models admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php
git commit -m "feat: add knowledge base schema and models"
```

---

### Task 5: Engine models for the new tables

**Files:**
- Modify: `api-engine/database.py`
- Test: `api-engine/tests/test_kb_models.py`

**Interfaces:**
- Consumes: the tables from Task 4.
- Produces: SQLAlchemy models `KbCollection`, `KbSource`, `KbChunk`, `AppSetting`, and `async get_settings(session) -> dict[str, str]` merging stored values over defaults.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_kb_models.py`:

```python
import pytest
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

import database
from database import AppSetting, Base, KbChunk, KbCollection, KbSource, get_settings


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        yield s
    await engine.dispose()


@pytest.mark.asyncio
async def test_settings_fall_back_to_defaults(session):
    settings = await get_settings(session)
    assert settings["embedding_model"] == "nomic-embed-text"
    assert settings["embedding_dimensions"] == "768"
    assert settings["chunk_size"] == "900"


@pytest.mark.asyncio
async def test_stored_settings_override_defaults(session):
    session.add(AppSetting(key="embedding_model", value="custom-model"))
    await session.commit()

    settings = await get_settings(session)
    assert settings["embedding_model"] == "custom-model"
    assert settings["chunk_size"] == "900"  # untouched default survives


@pytest.mark.asyncio
async def test_chunk_model_has_no_embedding_attribute(session):
    # The embedding column is driver-specific, so the drivers own it in raw SQL.
    assert not hasattr(KbChunk, "embedding")
    assert hasattr(KbChunk, "embedding_model")


@pytest.mark.asyncio
async def test_source_belongs_to_a_collection(session):
    session.add(KbCollection(id="c1", system_id="s1", name="C"))
    session.add(KbSource(id="s1", collection_id="c1", type="text", title="T", body="B"))
    await session.commit()

    fetched = await session.get(KbSource, "s1")
    assert fetched.collection_id == "c1"
    assert fetched.status == "pending"
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_kb_models.py -v`
Expected: FAIL, `ImportError: cannot import name 'KbCollection' from 'database'`

- [ ] **Step 3: Add the models**

In `api-engine/database.py`, add these classes after the existing `ChatMessage` class:

```python
class KbCollection(Base):
    __tablename__ = "kb_collections"

    id = Column(String(36), primary_key=True)
    system_id = Column(String(36), ForeignKey("systems.id", ondelete="CASCADE"), nullable=False)
    name = Column(String(255), nullable=False)
    description = Column(Text, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)


class KbSource(Base):
    __tablename__ = "kb_sources"

    id = Column(String(36), primary_key=True)
    collection_id = Column(String(36), ForeignKey("kb_collections.id", ondelete="CASCADE"), nullable=False)
    type = Column(String(20), default="text")
    title = Column(String(500), nullable=False)
    body = Column(Text, nullable=True)
    file_path = Column(String(500), nullable=True)
    file_mime = Column(String(100), nullable=True)
    file_size = Column(Integer, nullable=True)
    status = Column(String(20), default="pending")
    error_message = Column(Text, nullable=True)
    chunk_count = Column(Integer, default=0)
    indexed_at = Column(DateTime, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)


class KbChunk(Base):
    """The embedding column is deliberately not mapped.

    It is vector(768) on PostgreSQL and a blob on SQLite, so each vector store
    driver reads and writes it with raw SQL instead.
    """
    __tablename__ = "kb_chunks"

    id = Column(Integer, primary_key=True, autoincrement=True)
    collection_id = Column(String(36), nullable=False, index=True)
    source_id = Column(String(36), ForeignKey("kb_sources.id", ondelete="CASCADE"), nullable=False)
    ordinal = Column(Integer, nullable=False)
    content = Column(Text, nullable=False)
    char_count = Column(Integer, default=0)
    embedding_model = Column(String(120), nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)


class AppSetting(Base):
    __tablename__ = "app_settings"

    key = Column(String(120), primary_key=True)
    value = Column(Text, nullable=True)
    created_at = Column(DateTime, default=datetime.utcnow)
    updated_at = Column(DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)


# Kept in step with AppSetting::DEFAULTS on the Laravel side.
SETTING_DEFAULTS = {
    "embedding_base_url": "http://localhost:11434/v1",
    "embedding_api_key": "",
    "embedding_model": "nomic-embed-text",
    "embedding_dimensions": "768",
    "vector_driver": "pgvector",
    "chunk_size": "900",
    "chunk_overlap": "150",
}


async def get_settings(session) -> dict:
    """Stored settings layered over the defaults, read fresh each time.

    Laravel writes these; reading them from the shared table is what stops the
    two halves of the system drifting apart.
    """
    result = await session.execute(select(AppSetting))
    stored = {row.key: row.value for row in result.scalars().all() if row.value is not None}
    return {**SETTING_DEFAULTS, **stored}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_kb_models.py -v`
Expected: PASS, 4 passed

- [ ] **Step 5: Check the engine models match the real schema**

```bash
cd api-engine && .venv/Scripts/python.exe -c "
import asyncio, asyncpg, database
async def m():
    c = await asyncpg.connect(user='postgres', password='postgres', host='127.0.0.1', port=5432, database='chatbot_hub')
    for model in (database.KbCollection, database.KbSource, database.KbChunk, database.AppSetting):
        pg = {r['column_name'] for r in await c.fetch(
            \"select column_name from information_schema.columns where table_name=\$1\", model.__tablename__)}
        mine = {col.name for col in model.__table__.columns}
        missing = mine - pg
        print(model.__tablename__, 'OK' if not missing else f'MISSING IN DB: {missing}')
    await c.close()
asyncio.run(m())"
```

Expected: every table prints `OK`

- [ ] **Step 6: Commit**

```bash
git add api-engine/database.py api-engine/tests/test_kb_models.py
git commit -m "feat: add engine models for knowledge base tables"
```

---

### Task 6: Vector store drivers

**Files:**
- Create: `api-engine/kb/store.py`
- Modify: `api-engine/requirements.txt`
- Test: `api-engine/tests/test_store.py`

**Interfaces:**
- Consumes: `KbChunk` from Task 5.
- Produces: `Hit` dataclass with fields `chunk_id: int, source_id: str, content: str, score: float`; `VectorStore` protocol; `PgVectorStore(session)` and `SqliteVectorStore(session)`, both with `async upsert(chunks: list[dict]) -> None`, `async delete_source(source_id: str) -> None`, `async search_vector(collection_ids, query_vector, limit, embedding_model=None) -> list[Hit]`, `async search_keyword(collection_ids, query_text, limit) -> list[Hit]`; `make_store(session, driver: str) -> VectorStore`.
- `search_vector` with an `embedding_model` considers only chunks embedded by that model. Vectors left behind by a model change are skipped rather than compared, which would return nonsense.
- Each dict passed to `upsert` has keys `collection_id, source_id, ordinal, content, char_count, embedding_model, embedding` where `embedding` is `list[float]`.

- [ ] **Step 1: Add the pgvector dependency**

Append to `api-engine/requirements.txt`:

```
pgvector>=0.3.0
```

Install: `cd api-engine && .venv/Scripts/python.exe -m pip install -r requirements.txt`

- [ ] **Step 2: Write the failing tests**

Create `api-engine/tests/test_store.py`:

```python
import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import Base
from kb.store import Hit, SqliteVectorStore, make_store


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
        await conn.execute(text("ALTER TABLE kb_chunks ADD COLUMN embedding blob"))
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        yield s
    await engine.dispose()


def chunk(ordinal, content, embedding, source="src1", collection="col1"):
    return {
        "collection_id": collection,
        "source_id": source,
        "ordinal": ordinal,
        "content": content,
        "char_count": len(content),
        "embedding_model": "test-model",
        "embedding": embedding,
    }


@pytest.mark.asyncio
async def test_upsert_then_vector_search_ranks_by_similarity(session):
    store = SqliteVectorStore(session)
    await store.upsert([
        chunk(0, "refunds within thirty days", [1.0, 0.0]),
        chunk(1, "office opening hours", [0.0, 1.0]),
    ])

    hits = await store.search_vector(["col1"], [1.0, 0.0], limit=2)

    assert len(hits) == 2
    assert isinstance(hits[0], Hit)
    assert hits[0].content == "refunds within thirty days"
    assert hits[0].score > hits[1].score


@pytest.mark.asyncio
async def test_keyword_search_finds_the_matching_chunk(session):
    store = SqliteVectorStore(session)
    await store.upsert([
        chunk(0, "refunds within thirty days", [1.0, 0.0]),
        chunk(1, "office opening hours", [0.0, 1.0]),
    ])

    hits = await store.search_keyword(["col1"], "refunds", limit=5)

    assert [h.content for h in hits] == ["refunds within thirty days"]


@pytest.mark.asyncio
async def test_search_is_scoped_to_the_given_collections(session):
    store = SqliteVectorStore(session)
    await store.upsert([chunk(0, "alpha", [1.0, 0.0], collection="col1")])
    await store.upsert([chunk(0, "beta", [1.0, 0.0], source="src2", collection="col2")])

    hits = await store.search_vector(["col2"], [1.0, 0.0], limit=5)

    assert [h.content for h in hits] == ["beta"]


@pytest.mark.asyncio
async def test_upsert_replaces_a_sources_previous_chunks(session):
    store = SqliteVectorStore(session)
    await store.upsert([chunk(0, "old text", [1.0, 0.0])])
    await store.upsert([chunk(0, "new text", [1.0, 0.0])])

    hits = await store.search_vector(["col1"], [1.0, 0.0], limit=5)

    assert [h.content for h in hits] == ["new text"]


@pytest.mark.asyncio
async def test_delete_source_removes_its_chunks(session):
    store = SqliteVectorStore(session)
    await store.upsert([chunk(0, "alpha", [1.0, 0.0])])

    await store.delete_source("src1")

    assert await store.search_vector(["col1"], [1.0, 0.0], limit=5) == []


@pytest.mark.asyncio
async def test_searching_no_collections_returns_nothing(session):
    store = SqliteVectorStore(session)
    await store.upsert([chunk(0, "alpha", [1.0, 0.0])])

    assert await store.search_vector([], [1.0, 0.0], limit=5) == []
    assert await store.search_keyword([], "alpha", limit=5) == []


@pytest.mark.asyncio
async def test_vector_search_skips_chunks_from_another_embedding_model(session):
    store = SqliteVectorStore(session)
    await store.upsert([chunk(0, "alpha", [1.0, 0.0])])

    assert await store.search_vector(["col1"], [1.0, 0.0], 5, "test-model")
    assert await store.search_vector(["col1"], [1.0, 0.0], 5, "a-different-model") == []


@pytest.mark.asyncio
async def test_make_store_selects_the_driver(session):
    assert type(make_store(session, "sqlite")).__name__ == "SqliteVectorStore"
    assert type(make_store(session, "pgvector")).__name__ == "PgVectorStore"
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_store.py -v`
Expected: FAIL, `ModuleNotFoundError: No module named 'kb.store'`

- [ ] **Step 4: Implement the drivers**

Create `api-engine/kb/store.py`:

```python
"""Vector and keyword storage, one implementation per database.

Both drivers expose the same four methods, so retrieval never knows which one
it is talking to. Only rank order leaves this module; the fused ranking upstream
never compares a cosine score with a BM25 score.
"""
import struct
from dataclasses import dataclass
from typing import Protocol

import numpy as np
from sqlalchemy import text


@dataclass
class Hit:
    chunk_id: int
    source_id: str
    content: str
    score: float


class VectorStore(Protocol):
    async def upsert(self, chunks: list[dict]) -> None: ...
    async def delete_source(self, source_id: str) -> None: ...
    async def search_vector(self, collection_ids: list[str], query_vector: list[float],
                            limit: int, embedding_model: str = None) -> list[Hit]: ...
    async def search_keyword(self, collection_ids: list[str],
                             query_text: str, limit: int) -> list[Hit]: ...


def pack(vector: list[float]) -> bytes:
    return struct.pack(f"<{len(vector)}f", *vector)


def unpack(blob: bytes) -> list[float]:
    return list(struct.unpack(f"<{len(blob) // 4}f", blob))


class SqliteVectorStore:
    """Fallback driver. Brute-force scan, fine into the tens of thousands."""

    def __init__(self, session):
        self.session = session

    async def upsert(self, chunks: list[dict]) -> None:
        if not chunks:
            return
        await self.delete_source(chunks[0]["source_id"])
        for c in chunks:
            await self.session.execute(text("""
                INSERT INTO kb_chunks
                    (collection_id, source_id, ordinal, content, char_count, embedding_model, embedding)
                VALUES (:collection_id, :source_id, :ordinal, :content, :char_count, :embedding_model, :embedding)
            """), {**c, "embedding": pack(c["embedding"])})
        await self.session.commit()

    async def delete_source(self, source_id: str) -> None:
        await self.session.execute(
            text("DELETE FROM kb_chunks WHERE source_id = :sid"), {"sid": source_id})
        await self.session.commit()

    async def search_vector(self, collection_ids, query_vector, limit,
                            embedding_model=None) -> list[Hit]:
        if not collection_ids:
            return []
        sql = """
            SELECT id, source_id, content, embedding FROM kb_chunks
            WHERE collection_id IN :cids AND embedding IS NOT NULL
        """
        params = {"cids": tuple(collection_ids)}
        if embedding_model:
            # A vector made by a different model is not comparable to this query.
            sql += " AND embedding_model = :model"
            params["model"] = embedding_model
        rows = (await self.session.execute(
            text(sql).bindparams(**params))).all()
        if not rows:
            return []

        matrix = np.array([unpack(r.embedding) for r in rows], dtype=np.float32)
        query = np.array(query_vector, dtype=np.float32)
        if matrix.shape[1] != query.shape[0]:
            return []          # vectors from a different embedding model

        scores = matrix @ query          # vectors are stored normalised
        order = np.argsort(-scores)[:limit]
        return [Hit(rows[i].id, rows[i].source_id, rows[i].content, float(scores[i]))
                for i in order]

    async def search_keyword(self, collection_ids, query_text, limit) -> list[Hit]:
        if not collection_ids or not query_text.strip():
            return []
        terms = [t for t in query_text.lower().split() if len(t) > 2]
        if not terms:
            return []

        rows = (await self.session.execute(text("""
            SELECT id, source_id, content FROM kb_chunks WHERE collection_id IN :cids
        """).bindparams(cids=tuple(collection_ids)))).all()

        scored = []
        for r in rows:
            haystack = r.content.lower()
            score = sum(haystack.count(term) for term in terms)
            if score:
                scored.append(Hit(r.id, r.source_id, r.content, float(score)))
        scored.sort(key=lambda h: h.score, reverse=True)
        return scored[:limit]


class PgVectorStore:
    """Default driver. HNSW for the vector branch, GIN tsvector for keywords."""

    def __init__(self, session):
        self.session = session

    async def upsert(self, chunks: list[dict]) -> None:
        if not chunks:
            return
        await self.delete_source(chunks[0]["source_id"])
        for c in chunks:
            await self.session.execute(text("""
                INSERT INTO kb_chunks
                    (collection_id, source_id, ordinal, content, char_count, embedding_model, embedding)
                VALUES (:collection_id, :source_id, :ordinal, :content, :char_count,
                        :embedding_model, CAST(:embedding AS vector))
            """), {**c, "embedding": "[" + ",".join(str(x) for x in c["embedding"]) + "]"})
        await self.session.commit()

    async def delete_source(self, source_id: str) -> None:
        await self.session.execute(
            text("DELETE FROM kb_chunks WHERE source_id = :sid"), {"sid": source_id})
        await self.session.commit()

    async def search_vector(self, collection_ids, query_vector, limit,
                            embedding_model=None) -> list[Hit]:
        if not collection_ids:
            return []
        literal = "[" + ",".join(str(x) for x in query_vector) + "]"
        model_clause = " AND embedding_model = :model" if embedding_model else ""
        params = {"q": literal, "cids": list(collection_ids), "lim": limit}
        if embedding_model:
            params["model"] = embedding_model
        rows = (await self.session.execute(text(f"""
            SELECT id, source_id, content,
                   1 - (embedding <=> CAST(:q AS vector)) AS score
            FROM kb_chunks
            WHERE collection_id = ANY(:cids) AND embedding IS NOT NULL{model_clause}
            ORDER BY embedding <=> CAST(:q AS vector)
            LIMIT :lim
        """), params)).all()
        return [Hit(r.id, r.source_id, r.content, float(r.score)) for r in rows]

    async def search_keyword(self, collection_ids, query_text, limit) -> list[Hit]:
        if not collection_ids or not query_text.strip():
            return []
        rows = (await self.session.execute(text("""
            SELECT id, source_id, content,
                   ts_rank_cd(content_tsv, plainto_tsquery('english', :q)) AS score
            FROM kb_chunks
            WHERE collection_id = ANY(:cids)
              AND content_tsv @@ plainto_tsquery('english', :q)
            ORDER BY score DESC
            LIMIT :lim
        """), {"q": query_text, "cids": list(collection_ids), "lim": limit})).all()
        return [Hit(r.id, r.source_id, r.content, float(r.score)) for r in rows]


def make_store(session, driver: str) -> VectorStore:
    return SqliteVectorStore(session) if driver == "sqlite" else PgVectorStore(session)
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_store.py -v`
Expected: PASS, 8 passed

- [ ] **Step 6: Add the PostgreSQL indexes the driver needs**

Create `admin-laravel/database/migrations/2026_09_10_000003_add_kb_chunk_indexes.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;   // SQLite scans in memory; it needs no index
        }

        DB::statement("ALTER TABLE kb_chunks ADD COLUMN content_tsv tsvector
                       GENERATED ALWAYS AS (to_tsvector('english', content)) STORED");
        DB::statement('CREATE INDEX kb_chunks_tsv_idx ON kb_chunks USING GIN (content_tsv)');
        DB::statement('CREATE INDEX kb_chunks_vec_idx ON kb_chunks
                       USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS kb_chunks_vec_idx');
        DB::statement('DROP INDEX IF EXISTS kb_chunks_tsv_idx');
        DB::statement('ALTER TABLE kb_chunks DROP COLUMN IF EXISTS content_tsv');
    }
};
```

Run: `cd admin-laravel && php artisan migrate`
Expected: DONE

- [ ] **Step 7: Prove the PostgreSQL driver works against the real database**

Create `api-engine/tests/test_store_pgvector.py`:

```python
import os

import pytest
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from kb.store import PgVectorStore

DSN = os.getenv("TEST_PG_DSN",
                "postgresql+asyncpg://postgres:postgres@127.0.0.1:5432/chatbot_hub")


@pytest.fixture
async def pg_session():
    engine = create_async_engine(DSN)
    try:
        async with engine.begin() as conn:
            await conn.run_sync(lambda _: None)
    except Exception:
        pytest.skip("PostgreSQL not reachable")
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        yield s
    await engine.dispose()


@pytest.mark.asyncio
async def test_pgvector_round_trip(pg_session):
    store = PgVectorStore(pg_session)
    dims = 768
    a = [1.0] + [0.0] * (dims - 1)
    b = [0.0, 1.0] + [0.0] * (dims - 2)

    await store.upsert([
        {"collection_id": "pgtest_col", "source_id": "pgtest_src", "ordinal": 0,
         "content": "refunds within thirty days", "char_count": 26,
         "embedding_model": "test", "embedding": a},
        {"collection_id": "pgtest_col", "source_id": "pgtest_src", "ordinal": 1,
         "content": "office opening hours", "char_count": 20,
         "embedding_model": "test", "embedding": b},
    ])

    try:
        vector_hits = await store.search_vector(["pgtest_col"], a, limit=2)
        assert vector_hits[0].content == "refunds within thirty days"
        assert vector_hits[0].score > vector_hits[1].score

        keyword_hits = await store.search_keyword(["pgtest_col"], "refunds", limit=5)
        assert [h.content for h in keyword_hits] == ["refunds within thirty days"]
    finally:
        await store.delete_source("pgtest_src")
```

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_store_pgvector.py -v`
Expected: PASS, 1 passed

- [ ] **Step 8: Commit**

```bash
git add api-engine/kb/store.py api-engine/tests/test_store.py api-engine/tests/test_store_pgvector.py api-engine/requirements.txt admin-laravel/database/migrations
git commit -m "feat: add pgvector and sqlite vector store drivers"
```

---

### Task 7: Indexing pipeline and admin endpoints

**Files:**
- Create: `api-engine/kb/indexer.py`
- Create: `api-engine/routers/kb.py`
- Modify: `api-engine/main.py`
- Modify: `admin-laravel/.env.example`
- Test: `api-engine/tests/test_indexer.py`

**Interfaces:**
- Consumes: `chunk_text` (Task 1), `EmbeddingClient` (Task 3), `KbSource`/`get_settings` (Task 5), `make_store` (Task 6).
- Produces: `async index_source(session, source_id: str, embedder=None) -> int` returning the chunk count and setting the source status; router `kb.router` mounted at `/api/v1/kb` with `POST /sources/{id}/index`, `DELETE /sources/{id}/chunks` and `POST /embedding/test`; `require_admin_token(x_admin_token: str = Header(None))` dependency. `POST /search` is added in Task 8.
- `extract_text(source) -> str` renders a `qa` source as `Q: {title}\nA: {body}`.

- [ ] **Step 1: Write the failing tests**

Create `api-engine/tests/test_indexer.py`:

```python
import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import AppSetting, Base, KbCollection, KbSource
from kb.indexer import extract_text, index_source


class StubEmbedder:
    """Deterministic vectors: no Ollama needed in tests."""

    def __init__(self):
        self.calls = []

    async def embed(self, texts, transport=None):
        self.calls.append(texts)
        return [[float(len(t) % 7), 1.0] for t in texts]


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
        await conn.execute(text("ALTER TABLE kb_chunks ADD COLUMN embedding blob"))
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        s.add(AppSetting(key="vector_driver", value="sqlite"))
        s.add(KbCollection(id="col1", system_id="sys1", name="C"))
        await s.commit()
        yield s
    await engine.dispose()


def test_extract_text_renders_a_qa_pair():
    source = KbSource(id="s", collection_id="c", type="qa",
                      title="How do I get a refund?", body="Within 30 days.")
    assert extract_text(source) == "Q: How do I get a refund?\nA: Within 30 days."


def test_extract_text_passes_plain_text_through():
    source = KbSource(id="s", collection_id="c", type="text", title="T", body="Body here.")
    assert extract_text(source) == "Body here."


@pytest.mark.asyncio
async def test_indexing_a_text_source_creates_chunks_and_marks_it_ready(session):
    session.add(KbSource(id="src1", collection_id="col1", type="text",
                         title="Policy", body="Refunds within thirty days. " * 80))
    await session.commit()

    count = await index_source(session, "src1", embedder=StubEmbedder())

    assert count > 1
    source = await session.get(KbSource, "src1")
    assert source.status == "ready"
    assert source.chunk_count == count
    assert source.indexed_at is not None

    rows = (await session.execute(text("SELECT count(*) FROM kb_chunks WHERE source_id='src1'"))).scalar()
    assert rows == count


@pytest.mark.asyncio
async def test_a_qa_source_is_never_split(session):
    session.add(KbSource(id="src2", collection_id="col1", type="qa",
                         title="Q" * 400, body="A" * 900))
    await session.commit()

    count = await index_source(session, "src2", embedder=StubEmbedder())

    assert count == 1


@pytest.mark.asyncio
async def test_reindexing_replaces_the_previous_chunks(session):
    session.add(KbSource(id="src3", collection_id="col1", type="text",
                         title="T", body="first body"))
    await session.commit()
    await index_source(session, "src3", embedder=StubEmbedder())

    source = await session.get(KbSource, "src3")
    source.body = "second body"
    await session.commit()
    await index_source(session, "src3", embedder=StubEmbedder())

    rows = (await session.execute(
        text("SELECT content FROM kb_chunks WHERE source_id='src3'"))).scalars().all()
    assert rows == ["second body"]


@pytest.mark.asyncio
async def test_an_empty_body_records_an_error(session):
    session.add(KbSource(id="src4", collection_id="col1", type="text", title="T", body="   "))
    await session.commit()

    count = await index_source(session, "src4", embedder=StubEmbedder())

    assert count == 0
    source = await session.get(KbSource, "src4")
    assert source.status == "error"
    assert "no text" in source.error_message.lower()
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_indexer.py -v`
Expected: FAIL, `ModuleNotFoundError: No module named 'kb.indexer'`

- [ ] **Step 3: Implement the indexer**

Create `api-engine/kb/indexer.py`:

```python
"""Turn a source into searchable chunks.

Laravel owns the source record and any uploaded file; this owns everything from
extraction onward, because the parsing libraries are Python.
"""
from datetime import datetime

from database import KbSource, get_settings
from kb.chunking import chunk_text
from kb.embedding import EmbeddingClient
from kb.store import make_store


def extract_text(source: KbSource) -> str:
    """The canonical text for a source, whatever its type."""
    if source.type == "qa":
        return f"Q: {source.title}\nA: {source.body or ''}"
    return (source.body or "").strip()


async def index_source(session, source_id: str, embedder=None) -> int:
    source = await session.get(KbSource, source_id)
    if source is None:
        raise ValueError(f"unknown source {source_id}")

    settings = await get_settings(session)

    source.status = "processing"
    source.error_message = None
    await session.commit()

    try:
        body = extract_text(source)
        if not body.strip():
            raise ValueError("Source has no text to index.")

        # A question and answer pair is one idea; splitting it would return half
        # an answer.
        if source.type == "qa":
            pieces = [body]
        else:
            pieces = chunk_text(body,
                                size=int(settings["chunk_size"]),
                                overlap=int(settings["chunk_overlap"]))
        if not pieces:
            raise ValueError("Source produced no text to index.")

        client = embedder or EmbeddingClient(
            settings["embedding_base_url"],
            settings["embedding_api_key"],
            settings["embedding_model"],
        )
        vectors = await client.embed(pieces)

        store = make_store(session, settings["vector_driver"])
        await store.upsert([
            {
                "collection_id": source.collection_id,
                "source_id": source.id,
                "ordinal": i,
                "content": piece,
                "char_count": len(piece),
                "embedding_model": settings["embedding_model"],
                "embedding": vector,
            }
            for i, (piece, vector) in enumerate(zip(pieces, vectors))
        ])

        source.status = "ready"
        source.chunk_count = len(pieces)
        source.indexed_at = datetime.utcnow()
        source.error_message = None
        await session.commit()
        return len(pieces)

    except Exception as exc:
        source.status = "error"
        source.error_message = str(exc)[:1000]
        source.chunk_count = 0
        await session.commit()
        return 0
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_indexer.py -v`
Expected: PASS, 6 passed

- [ ] **Step 5: Add the admin router**

Create `api-engine/routers/kb.py`:

```python
"""Admin-plane routes, called by Laravel and nobody else.

The engine listens on a port anything on the host can reach, so every route
here requires a shared token. Without it, re-index and search would let a
passer-by read a workspace's content.
"""
import os

from fastapi import APIRouter, BackgroundTasks, Depends, Header, HTTPException
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

from database import async_session_factory, get_db, get_settings
from kb.embedding import EmbeddingClient
from kb.indexer import index_source

router = APIRouter(prefix="/api/v1/kb", tags=["knowledge-base"])


async def require_admin_token(x_admin_token: str = Header(None)) -> None:
    expected = os.getenv("ADMIN_API_TOKEN", "")
    if not expected:
        raise HTTPException(status_code=503,
                            detail="ADMIN_API_TOKEN is not set on the engine.")
    if x_admin_token != expected:
        raise HTTPException(status_code=401, detail="Invalid admin token.")


async def _index_in_background(source_id: str) -> None:
    async with async_session_factory() as session:
        await index_source(session, source_id)


@router.post("/sources/{source_id}/index", dependencies=[Depends(require_admin_token)])
async def start_indexing(source_id: str, background: BackgroundTasks):
    background.add_task(_index_in_background, source_id)
    return {"status": "accepted", "source_id": source_id}


@router.delete("/sources/{source_id}/chunks", dependencies=[Depends(require_admin_token)])
async def delete_chunks(source_id: str, db: AsyncSession = Depends(get_db)):
    settings = await get_settings(db)
    from kb.store import make_store
    await make_store(db, settings["vector_driver"]).delete_source(source_id)
    return {"status": "deleted", "source_id": source_id}


class EmbeddingTestRequest(BaseModel):
    base_url: str
    api_key: str = ""
    model: str


@router.post("/embedding/test", dependencies=[Depends(require_admin_token)])
async def test_embedding(req: EmbeddingTestRequest):
    client = EmbeddingClient(req.base_url, req.api_key, req.model)
    try:
        vectors = await client.embed(["connection test"])
    except Exception as exc:
        return {"ok": False, "message": str(exc)[:300]}
    return {"ok": True, "dimensions": len(vectors[0]),
            "message": f"Answered with {len(vectors[0])} dimensions."}


```

The `/search` endpoint arrives in Task 8, once `kb.retrieval` exists. The
spec's `/api/v1/kb/reindex` belongs to phase 2, alongside the dimension-change
tooling it serves.

- [ ] **Step 6: Mount the router and set the token**

In `api-engine/main.py`, change the router import line to:

```python
from routers import bot, chat, kb
```

and add after `app.include_router(chat.router)`:

```python
app.include_router(kb.router)
```

Append to `admin-laravel/.env.example`:

```
# Shared secret for the engine's admin-plane routes. Generate one per install
# and set the same value as ADMIN_API_TOKEN in the engine's environment.
ENGINE_ADMIN_TOKEN=
ENGINE_BASE_URL=http://localhost:8000
```

Add the same token to your local `admin-laravel/.env`, and export
`ADMIN_API_TOKEN` with that value before starting the engine.

- [ ] **Step 7: Verify the token guard**

Start the engine, then run:

```bash
curl -s -o /dev/null -w "no token  -> %{http_code}\n" -X POST http://127.0.0.1:8000/api/v1/kb/embedding/test \
  -H "Content-Type: application/json" -d '{"base_url":"http://localhost:11434/v1","model":"nomic-embed-text"}'
curl -s -w "\nwith token -> %{http_code}\n" -X POST http://127.0.0.1:8000/api/v1/kb/embedding/test \
  -H "Content-Type: application/json" -H "X-Admin-Token: $ADMIN_API_TOKEN" \
  -d '{"base_url":"http://localhost:11434/v1","model":"nomic-embed-text"}'
```

Expected: the first prints 401, the second prints 200 with `"dimensions": 768`

- [ ] **Step 8: Commit**

```bash
git add api-engine/kb/indexer.py api-engine/routers/kb.py api-engine/main.py api-engine/tests/test_indexer.py admin-laravel/.env.example
git commit -m "feat: add indexing pipeline and admin endpoints"
```

---

### Task 8: Retrieval in the chat stream

**Files:**
- Create: `api-engine/kb/retrieval.py`
- Modify: `api-engine/routers/chat.py`
- Modify: `api-engine/routers/kb.py`
- Modify: `api-engine/llm_adapter.py`
- Modify: `api-engine/database.py`
- Test: `api-engine/tests/test_retrieval.py`

**Interfaces:**
- Consumes: `fuse_rankings` (Task 2), `EmbeddingClient` (Task 3), `get_settings` (Task 5), `make_store` (Task 6).
- Produces: `RetrievedChunk` dataclass with `chunk_id: int, source_id: str, content: str, score: float`; `async retrieve_for_collections(session, collection_ids, query, mode="hybrid", top_k=5, candidates=30, min_score=0.0, embedder=None) -> list[RetrievedChunk]`; `build_context_block(chunks: list[RetrievedChunk], titles: dict[str, str]) -> str`; `augment_system_prompt(prompt: str, context: str, fallback: str) -> str`.
- `LLMAdapter.stream_chat` gains keyword arguments `top_p: float = 1.0`, `top_k_sampling: int | None = None`, `presence_penalty: float = 0.0`, `frequency_penalty: float = 0.0`, `thinking_level: str = "off"`.

- [ ] **Step 1: Write the failing tests**

Create `api-engine/tests/test_retrieval.py`:

```python
import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import AppSetting, Base
from kb.retrieval import (RetrievedChunk, augment_system_prompt,
                          build_context_block, retrieve_for_collections)
from kb.store import SqliteVectorStore


class StubEmbedder:
    async def embed(self, texts, transport=None):
        # "refund" queries land near the refund vector, everything else does not
        return [[1.0, 0.0] if "refund" in texts[0].lower() else [0.0, 1.0]]


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
        await conn.execute(text("ALTER TABLE kb_chunks ADD COLUMN embedding blob"))
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        s.add(AppSetting(key="vector_driver", value="sqlite"))
        s.add(AppSetting(key="embedding_model", value="test"))
        await s.commit()
        store = SqliteVectorStore(s)
        await store.upsert([
            {"collection_id": "col1", "source_id": "src1", "ordinal": 0,
             "content": "Refunds are issued within thirty days.", "char_count": 38,
             "embedding_model": "test", "embedding": [1.0, 0.0]},
        ])
        await store.upsert([
            {"collection_id": "col1", "source_id": "src2", "ordinal": 0,
             "content": "The office opens at nine.", "char_count": 25,
             "embedding_model": "test", "embedding": [0.0, 1.0]},
        ])
        yield s
    await engine.dispose()


def test_context_block_numbers_sources():
    chunks = [RetrievedChunk(1, "src1", "Refunds in thirty days.", 0.03)]
    block = build_context_block(chunks, {"src1": "Refund policy"})
    assert "[1] Refund policy" in block
    assert "Refunds in thirty days." in block


def test_context_block_is_empty_without_chunks():
    assert build_context_block([], {}) == ""


def test_augment_appends_context_to_the_prompt():
    out = augment_system_prompt("You are helpful.", "[1] A\nbody", "say_unknown")
    assert out.startswith("You are helpful.")
    assert "[1] A" in out


def test_augment_with_no_context_and_say_unknown_adds_the_instruction():
    out = augment_system_prompt("You are helpful.", "", "say_unknown")
    assert "not in the available material" in out.lower()


def test_augment_with_no_context_and_answer_anyway_leaves_the_prompt_alone():
    assert augment_system_prompt("You are helpful.", "", "answer_anyway") == "You are helpful."


@pytest.mark.asyncio
async def test_retrieval_finds_the_relevant_chunk(session):
    results = await retrieve_for_collections(
        session, ["col1"], "how do refunds work", top_k=1, embedder=StubEmbedder())

    assert len(results) == 1
    assert "Refunds" in results[0].content


@pytest.mark.asyncio
async def test_no_collections_returns_nothing(session):
    assert await retrieve_for_collections(
        session, [], "refund", embedder=StubEmbedder()) == []


@pytest.mark.asyncio
async def test_min_score_filters_everything_out(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", min_score=99.0, embedder=StubEmbedder())
    assert results == []


@pytest.mark.asyncio
async def test_keyword_mode_skips_the_embedder(session):
    class Exploding:
        async def embed(self, texts, transport=None):
            raise AssertionError("keyword mode must not embed")

    results = await retrieve_for_collections(
        session, ["col1"], "refunds", mode="keyword", embedder=Exploding())
    assert any("Refunds" in r.content for r in results)
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_retrieval.py -v`
Expected: FAIL, `ModuleNotFoundError: No module named 'kb.retrieval'`

- [ ] **Step 3: Implement retrieval**

Create `api-engine/kb/retrieval.py`:

```python
"""Find the passages that should inform an answer.

Two branches run over the same corpus: dense vectors catch paraphrase, keywords
catch exact terms such as product codes. Their scores are not comparable, so
they are merged by rank.
"""
from dataclasses import dataclass

from database import get_settings
from kb.embedding import EmbeddingClient
from kb.fusion import fuse_rankings
from kb.store import make_store

NO_CONTEXT_INSTRUCTION = (
    "\n\nNothing in the available material answers this question. Say that the "
    "answer is not in the available material rather than guessing."
)


@dataclass
class RetrievedChunk:
    chunk_id: int
    source_id: str
    content: str
    score: float


async def retrieve_for_collections(session, collection_ids, query, mode="hybrid",
                                   top_k=5, candidates=30, min_score=0.0,
                                   embedder=None) -> list[RetrievedChunk]:
    if not collection_ids or not query.strip():
        return []

    settings = await get_settings(session)
    store = make_store(session, settings["vector_driver"])

    branches: list[list[str]] = []
    by_id: dict[str, RetrievedChunk] = {}

    if mode in ("hybrid", "vector"):
        client = embedder or EmbeddingClient(
            settings["embedding_base_url"],
            settings["embedding_api_key"],
            settings["embedding_model"],
        )
        query_vector = (await client.embed([query]))[0]
        hits = await store.search_vector(collection_ids, query_vector, candidates,
                                         settings["embedding_model"])
        branches.append([str(h.chunk_id) for h in hits])
        for h in hits:
            by_id[str(h.chunk_id)] = RetrievedChunk(h.chunk_id, h.source_id, h.content, h.score)

    if mode in ("hybrid", "keyword"):
        hits = await store.search_keyword(collection_ids, query, candidates)
        branches.append([str(h.chunk_id) for h in hits])
        for h in hits:
            by_id.setdefault(str(h.chunk_id),
                             RetrievedChunk(h.chunk_id, h.source_id, h.content, h.score))

    fused = fuse_rankings(branches)

    out: list[RetrievedChunk] = []
    for chunk_id, score in fused:
        if score < min_score:
            continue
        chunk = by_id[chunk_id]
        out.append(RetrievedChunk(chunk.chunk_id, chunk.source_id, chunk.content, score))
        if len(out) >= top_k:
            break
    return out


def build_context_block(chunks: list[RetrievedChunk], titles: dict[str, str]) -> str:
    if not chunks:
        return ""
    parts = ["Use the following context to answer. Cite the sources you use as [1], [2].", ""]
    for n, chunk in enumerate(chunks, start=1):
        parts.append(f"[{n}] {titles.get(chunk.source_id, 'Untitled')}")
        parts.append(chunk.content)
        parts.append("")
    return "\n".join(parts).strip()


def augment_system_prompt(prompt: str, context: str, fallback: str) -> str:
    base = (prompt or "").strip()
    if context:
        return f"{base}\n\n{context}".strip()
    if fallback == "say_unknown":
        return f"{base}{NO_CONTEXT_INSTRUCTION}".strip()
    return base
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_retrieval.py -v`
Expected: PASS, 9 passed

- [ ] **Step 5: Pass the generation settings through the adapter**

In `api-engine/llm_adapter.py`, change the `stream_chat` signature to add these keyword parameters after `max_tokens: int`:

```python
        top_p: float = 1.0,
        top_k_sampling: int = None,
        presence_penalty: float = 0.0,
        frequency_penalty: float = 0.0,
        thinking_level: str = "off",
```

Then, immediately after the `payload` dictionary is built (the block containing `"temperature"` and `"max_tokens"`), add:

```python
        # Only send what the caller actually set. Endpoints differ in what they
        # accept, and an unexpected key is rejected outright by some of them.
        if top_p is not None and float(top_p) != 1.0:
            payload["top_p"] = float(top_p)
        if top_k_sampling:
            payload["top_k"] = int(top_k_sampling)
        if presence_penalty:
            payload["presence_penalty"] = float(presence_penalty)
        if frequency_penalty:
            payload["frequency_penalty"] = float(frequency_penalty)
        if thinking_level and thinking_level != "off":
            # Understood by OpenAI-compatible reasoning models; harmlessly
            # ignored by endpoints that do not implement it.
            payload["reasoning_effort"] = thinking_level
```

- [ ] **Step 6: Add the engine models the route will need**

In `api-engine/database.py`, add after the `KbChunk` class:

```python
class BotKbCollection(Base):
    __tablename__ = "bot_kb_collection"

    id = Column(Integer, primary_key=True, autoincrement=True)
    bot_id = Column(String(36), ForeignKey("bot_profiles.id", ondelete="CASCADE"), nullable=False)
    collection_id = Column(String(36), ForeignKey("kb_collections.id", ondelete="CASCADE"), nullable=False)
```

Add the retrieval and generation columns to the engine's `BotProfile` model in
the same file, after `avatar_shape`:

```python
    retrieval_enabled = Column(Boolean, default=False)
    retrieval_mode = Column(String(20), default="hybrid")
    retrieval_top_k = Column(Integer, default=5)
    retrieval_candidates = Column(Integer, default=30)
    retrieval_min_score = Column(Float, default=0.0)
    retrieval_fallback = Column(String(20), default="say_unknown")
    top_p = Column(Float, default=1.0)
    top_k_sampling = Column(Integer, nullable=True)
    presence_penalty = Column(Float, default=0.0)
    frequency_penalty = Column(Float, default=0.0)
    thinking_level = Column(String(10), default="off")
```

- [ ] **Step 7: Wire retrieval into the chat route**

In `api-engine/routers/chat.py`, add to the imports at the top:

```python
from sqlalchemy import select as sa_select

from database import BotKbCollection, KbCollection, KbSource
from kb.retrieval import (augment_system_prompt, build_context_block,
                          retrieve_for_collections)
```

Replace the `async def sse_event_stream():` line and the `try:` block opening with the following, keeping the rest of the function body unchanged:

```python
    # Retrieval runs before the model call. A failure here must never break a
    # chat, so it degrades to answering without context.
    retrieved = []
    source_titles = {}
    if bot.retrieval_enabled:
        try:
            rows = await db.execute(sa_select(KbCollection.id).join(
                BotKbCollection, BotKbCollection.collection_id == KbCollection.id
            ).where(BotKbCollection.bot_id == bot.id))
            collection_ids = [r for r in rows.scalars().all()]

            retrieved = await retrieve_for_collections(
                db, collection_ids, req.message,
                mode=bot.retrieval_mode or "hybrid",
                top_k=bot.retrieval_top_k or 5,
                candidates=bot.retrieval_candidates or 30,
                min_score=bot.retrieval_min_score or 0.0,
            )
            if retrieved:
                title_rows = await db.execute(sa_select(KbSource.id, KbSource.title).where(
                    KbSource.id.in_([r.source_id for r in retrieved])))
                source_titles = {row[0]: row[1] for row in title_rows.all()}
        except Exception as retrieval_error:
            print(f"[Retrieval] Skipped, answering without context: {retrieval_error}")
            retrieved = []

    context_block = build_context_block(retrieved, source_titles)
    final_prompt = augment_system_prompt(
        bot.system_prompt or "", context_block, bot.retrieval_fallback or "say_unknown")

    async def sse_event_stream():
        collected_response = []
        if retrieved:
            payload = {"type": "sources", "sources": [
                {"n": i + 1, "title": source_titles.get(r.source_id, "Untitled"),
                 "source_id": r.source_id}
                for i, r in enumerate(retrieved)
            ]}
            yield f"data: {json.dumps(payload)}\n\n"
        try:
```

Then, in the `LLMAdapter.stream_chat(...)` call inside that function, replace the `system_prompt=bot.system_prompt or "",` line with:

```python
                system_prompt=final_prompt,
```

and add these arguments after `max_tokens=bot.max_tokens or 1024,`:

```python
                top_p=bot.top_p if bot.top_p is not None else 1.0,
                top_k_sampling=bot.top_k_sampling,
                presence_penalty=bot.presence_penalty or 0.0,
                frequency_penalty=bot.frequency_penalty or 0.0,
                thinking_level=bot.thinking_level or "off",
```

- [ ] **Step 8: Add the search endpoint, now that retrieval exists**

In `api-engine/routers/kb.py`, add to the imports:

```python
from kb.retrieval import retrieve_for_collections
```

and append to the file:

```python
class SearchRequest(BaseModel):
    collection_ids: list[str]
    query: str
    mode: str = "hybrid"
    top_k: int = 5
    candidates: int = 30
    min_score: float = 0.0


@router.post("/search", dependencies=[Depends(require_admin_token)])
async def search(req: SearchRequest, db: AsyncSession = Depends(get_db)):
    results = await retrieve_for_collections(
        db, req.collection_ids, req.query,
        mode=req.mode, top_k=req.top_k,
        candidates=req.candidates, min_score=req.min_score,
    )
    return {"results": [
        {"chunk_id": r.chunk_id, "source_id": r.source_id,
         "content": r.content, "score": r.score}
        for r in results
    ]}
```

- [ ] **Step 9: Run the whole Python suite**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest -v`
Expected: PASS, all tests green

- [ ] **Step 10: Commit**

```bash
git add api-engine/kb/retrieval.py api-engine/tests/test_retrieval.py api-engine/routers/chat.py api-engine/routers/kb.py api-engine/llm_adapter.py api-engine/database.py
git commit -m "feat: retrieve context before answering"
```

---

### Task 9: Knowledge base screens

**Files:**
- Create: `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`
- Create: `admin-laravel/app/Services/EngineClient.php`
- Create: `admin-laravel/resources/views/kb/index.blade.php`
- Create: `admin-laravel/resources/views/kb/show.blade.php`
- Modify: `admin-laravel/routes/web.php`
- Modify: `admin-laravel/resources/views/layouts/app.blade.php`
- Test: `admin-laravel/tests/Feature/KnowledgeBaseControllerTest.php`

**Interfaces:**
- Consumes: `KbCollection`, `KbSource` (Task 4); the engine's `/api/v1/kb/sources/{id}/index` (Task 7).
- Produces: named routes `kb.index`, `kb.store`, `kb.show`, `kb.destroy`, `kb.sources.store`, `kb.sources.reindex`, `kb.sources.destroy`; `EngineClient::indexSource(string $sourceId): bool` and `EngineClient::deleteChunks(string $sourceId): bool`.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/KnowledgeBaseControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\KbCollection;
use App\Models\KbSource;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeBaseControllerTest extends TestCase
{
    use RefreshDatabase;

    private System $system;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['status' => 'accepted'], 200)]);

        $this->system = System::create([
            'id' => 'sys_test', 'name' => 'Test Workspace', 'allowed_origins' => '*',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => 'Tester', 'email' => $role . '@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach($this->system->id, ['role' => $role]);

        return $user;
    }

    public function test_an_editor_sees_the_collection_list(): void
    {
        $this->actingAs($this->userWithRole('editor'))
            ->get(route('kb.index'))
            ->assertOk()
            ->assertSee('Knowledge base');
    }

    public function test_an_editor_can_create_a_collection(): void
    {
        $this->actingAs($this->userWithRole('editor'))
            ->post(route('kb.store'), ['name' => 'Refund policy'])
            ->assertRedirect();

        $this->assertDatabaseHas('kb_collections', [
            'name' => 'Refund policy', 'system_id' => 'sys_test',
        ]);
    }

    public function test_a_viewer_cannot_create_a_collection(): void
    {
        $this->actingAs($this->userWithRole('viewer'))
            ->post(route('kb.store'), ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertDatabaseCount('kb_collections', 0);
    }

    public function test_adding_a_text_source_queues_indexing(): void
    {
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'C']);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('kb.sources.store', 'kbc_1'), [
                'type' => 'text',
                'title' => 'Policy',
                'body' => 'Refunds within thirty days.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('kb_sources', ['title' => 'Policy', 'type' => 'text']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/index'));
    }

    public function test_a_qa_source_requires_both_halves(): void
    {
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'C']);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('kb.sources.store', 'kbc_1'), ['type' => 'qa', 'title' => 'Question only'])
            ->assertSessionHasErrors('body');
    }

    public function test_a_collection_from_another_workspace_is_refused(): void
    {
        $other = System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_other', 'system_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('kb.show', 'kbc_other'))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin-laravel && php artisan test --filter=KnowledgeBaseControllerTest`
Expected: FAIL, `Route [kb.index] not defined`

- [ ] **Step 3: Write the engine client**

Create `admin-laravel/app/Services/EngineClient.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls the FastAPI engine's admin plane.
 *
 * Every call carries the shared token; the engine rejects anything without it.
 * Failures are logged and reported as false rather than thrown, because a
 * queued re-index should not take an admin page down with it.
 */
class EngineClient
{
    private static function base(): string
    {
        return rtrim(env('ENGINE_BASE_URL', 'http://localhost:8000'), '/');
    }

    private static function request()
    {
        return Http::timeout(15)
            ->withHeaders(['X-Admin-Token' => env('ENGINE_ADMIN_TOKEN', '')]);
    }

    public static function indexSource(string $sourceId): bool
    {
        try {
            return self::request()
                ->post(self::base() . "/api/v1/kb/sources/{$sourceId}/index")
                ->successful();
        } catch (\Throwable $e) {
            Log::warning("Engine index call failed for {$sourceId}: " . $e->getMessage());
            return false;
        }
    }

    public static function deleteChunks(string $sourceId): bool
    {
        try {
            return self::request()
                ->delete(self::base() . "/api/v1/kb/sources/{$sourceId}/chunks")
                ->successful();
        } catch (\Throwable $e) {
            Log::warning("Engine delete call failed for {$sourceId}: " . $e->getMessage());
            return false;
        }
    }

    public static function testEmbedding(string $baseUrl, string $apiKey, string $model): array
    {
        try {
            $response = self::request()->post(self::base() . '/api/v1/kb/embedding/test', [
                'base_url' => $baseUrl, 'api_key' => $apiKey, 'model' => $model,
            ]);

            return $response->successful()
                ? $response->json()
                : ['ok' => false, 'message' => 'Engine returned HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }
}
```

- [ ] **Step 4: Write the controller**

Create `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\KbCollection;
use App\Models\KbSource;
use App\Services\EngineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        if (!$activeSystem) {
            return redirect()->route('systems.index')
                ->with('error', 'Select a workspace first.');
        }

        return view('kb.index', [
            'activeSystem' => $activeSystem,
            'collections' => KbCollection::withCount('sources')
                ->where('system_id', $activeSystem->id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        KbCollection::create([
            'id' => 'kbc_' . Str::random(12),
            'system_id' => $activeSystem->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return redirect()->route('kb.index')->with('success', 'Collection created.');
    }

    public function show(Request $request, string $id)
    {
        $collection = KbCollection::with('sources')->findOrFail($id);
        $this->authorizeViewer($request, $collection->system_id);

        return view('kb.show', ['collection' => $collection]);
    }

    public function destroy(Request $request, string $id)
    {
        $collection = KbCollection::findOrFail($id);
        $this->authorizeEditor($request, $collection->system_id);

        foreach ($collection->sources as $source) {
            EngineClient::deleteChunks($source->id);
        }
        $collection->delete();

        return redirect()->route('kb.index')->with('success', 'Collection deleted.');
    }

    public function storeSource(Request $request, string $collectionId)
    {
        $collection = KbCollection::findOrFail($collectionId);
        $this->authorizeEditor($request, $collection->system_id);

        $validated = $request->validate([
            'type' => ['required', 'in:text,qa'],
            'title' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string'],
        ], [
            'body.required' => 'A question needs an answer.',
        ]);

        $source = KbSource::create([
            'id' => 'kbs_' . Str::random(12),
            'collection_id' => $collection->id,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => 'pending',
        ]);

        EngineClient::indexSource($source->id);

        return redirect()->route('kb.show', $collection->id)
            ->with('success', 'Added. Indexing runs in the background.');
    }

    public function reindexSource(Request $request, string $sourceId)
    {
        $source = KbSource::with('collection')->findOrFail($sourceId);
        $this->authorizeEditor($request, $source->collection->system_id);

        $source->update(['status' => 'pending', 'error_message' => null]);
        EngineClient::indexSource($source->id);

        return back()->with('success', 'Re-indexing started.');
    }

    public function destroySource(Request $request, string $sourceId)
    {
        $source = KbSource::with('collection')->findOrFail($sourceId);
        $this->authorizeEditor($request, $source->collection->system_id);

        EngineClient::deleteChunks($source->id);
        $collectionId = $source->collection_id;
        $source->delete();

        return redirect()->route('kb.show', $collectionId)->with('success', 'Source removed.');
    }

    private function authorizeEditor(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }

    private function authorizeViewer(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'viewer'), 403);
    }
}
```

- [ ] **Step 5: Register the routes**

In `admin-laravel/routes/web.php`, inside the `Route::middleware(['auth', 'system.access'])->group(...)` block, add before the closing of that group:

```php
    // Knowledge base
    Route::get('/knowledge', [KnowledgeBaseController::class, 'index'])->name('kb.index');
    Route::post('/knowledge', [KnowledgeBaseController::class, 'store'])->name('kb.store');
    Route::get('/knowledge/{id}', [KnowledgeBaseController::class, 'show'])->name('kb.show');
    Route::delete('/knowledge/{id}', [KnowledgeBaseController::class, 'destroy'])->name('kb.destroy');
    Route::post('/knowledge/{id}/sources', [KnowledgeBaseController::class, 'storeSource'])->name('kb.sources.store');
    Route::post('/knowledge/sources/{sourceId}/reindex', [KnowledgeBaseController::class, 'reindexSource'])->name('kb.sources.reindex');
    Route::delete('/knowledge/sources/{sourceId}', [KnowledgeBaseController::class, 'destroySource'])->name('kb.sources.destroy');
```

Add to the imports at the top of the same file:

```php
use App\Http\Controllers\KnowledgeBaseController;
```

- [ ] **Step 6: Write the collection list view**

Create `admin-laravel/resources/views/kb/index.blade.php`:

```blade
@extends('layouts.app')

@section('page-title', 'Knowledge base')

@section('content')

<div class="page-head mb-4">
    <div>
        <h1>Knowledge base</h1>
        <p>Content the bots in {{ $activeSystem->name }} can answer from. A collection is a group of related material; each bot chooses which collections it reads.</p>
    </div>

    @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
        <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newCollectionModal">
            <i class="bi bi-plus-lg"></i> New collection
        </button>
    @endif
</div>

<div class="card">
    @if($collections->isEmpty())
        <div class="empty">
            <i class="bi bi-journal-text"></i>
            <h6>No collections yet</h6>
            <p>Create one, add your policies or product notes, and point a bot at it.</p>
            @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newCollectionModal">New collection</button>
            @endif
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 260px;">Collection</th>
                        <th style="width: 100px;">Sources</th>
                        <th class="text-end" style="width: 160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($collections as $collection)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $collection->name }}</div>
                                <div class="text-muted" style="font-size: 0.75rem;">
                                    {{ $collection->description ?: 'No description.' }}
                                </div>
                            </td>
                            <td><span class="figure-mono">{{ $collection->sources_count }}</span></td>
                            <td class="text-end">
                                <div class="d-flex align-items-center justify-content-end gap-1.5">
                                    <a href="{{ route('kb.show', $collection->id) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                    @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                                        <form action="{{ route('kb.destroy', $collection->id) }}" method="POST"
                                              onsubmit="return confirm('Delete {{ $collection->name }} and everything in it?');"
                                              class="d-inline m-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete collection">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
    <div class="modal fade" id="newCollectionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">New collection</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('kb.store') }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="kbc_name" class="form-label">Name <span style="color: var(--danger);">*</span></label>
                            <input type="text" id="kbc_name" name="name" class="form-control"
                                   placeholder="Refund policy, Product notes" required>
                        </div>
                        <div class="mb-0">
                            <label for="kbc_desc" class="form-label">Description</label>
                            <textarea id="kbc_desc" name="description" class="form-control" rows="2"
                                      placeholder="What this collection covers."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Create collection</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

@endsection
```

- [ ] **Step 7: Write the collection detail view**

Create `admin-laravel/resources/views/kb/show.blade.php`:

```blade
@extends('layouts.app')

@section('page-title', 'Knowledge base')

@section('content')
@php $canEdit = auth()->user()->canManageSystem($collection->system_id, 'editor'); @endphp

<div class="page-head mb-4">
    <div>
        <a href="{{ route('kb.index') }}" class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
            <i class="bi bi-arrow-left"></i> Knowledge base
        </a>
        <h1>{{ $collection->name }}</h1>
        <p>{{ $collection->description ?: 'Everything a bot reading this collection can draw on.' }}</p>
    </div>

    @if($canEdit)
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addQaModal">
                <i class="bi bi-patch-question"></i> Add Q and A
            </button>
            <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#addTextModal">
                <i class="bi bi-plus-lg"></i> Add text
            </button>
        </div>
    @endif
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <span>Sources</span>
        <span class="chip figure-mono">{{ $collection->sources->count() }} total</span>
    </div>

    @if($collection->sources->isEmpty())
        <div class="empty">
            <i class="bi bi-file-text"></i>
            <h6>Nothing in this collection yet</h6>
            <p>Paste a policy, or add a question with the answer you want given.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 280px;">Source</th>
                        <th style="width: 90px;">Type</th>
                        <th style="width: 130px;">Status</th>
                        <th style="width: 90px;">Chunks</th>
                        <th class="text-end" style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($collection->sources as $source)
                        <tr>
                            <td>
                                <div class="fw-semibold text-truncate" style="max-width: 420px;">{{ $source->title }}</div>
                                @if($source->status === 'error')
                                    <div style="color: var(--danger); font-size: 0.75rem;">{{ $source->error_message }}</div>
                                @else
                                    <div class="text-muted text-truncate" style="max-width: 420px; font-size: 0.75rem;">
                                        {{ \Illuminate\Support\Str::limit($source->body, 90) }}
                                    </div>
                                @endif
                            </td>
                            <td><span class="chip">{{ $source->type === 'qa' ? 'Q and A' : 'Text' }}</span></td>
                            <td>
                                @if($source->status === 'ready')
                                    <span class="d-inline-flex align-items-center gap-1.5" style="color: var(--ok);">
                                        <span class="state-dot is-live"></span> Indexed
                                    </span>
                                @elseif($source->status === 'error')
                                    <span style="color: var(--danger);">Failed</span>
                                @else
                                    <span class="text-muted">{{ ucfirst($source->status) }}</span>
                                @endif
                            </td>
                            <td><span class="figure-mono">{{ $source->chunk_count }}</span></td>
                            <td class="text-end">
                                @if($canEdit)
                                    <div class="d-flex align-items-center justify-content-end gap-1.5">
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
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if($canEdit)
    <div class="modal fade" id="addTextModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">Add text</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('kb.sources.store', $collection->id) }}" method="POST">
                    @csrf
                    <input type="hidden" name="type" value="text">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="text_title" class="form-label">Title <span style="color: var(--danger);">*</span></label>
                            <input type="text" id="text_title" name="title" class="form-control"
                                   placeholder="Refund policy" required>
                            <div class="form-text">Shown to the bot as the source name when it cites this.</div>
                        </div>
                        <div class="mb-0">
                            <label for="text_body" class="form-label">Content <span style="color: var(--danger);">*</span></label>
                            <textarea id="text_body" name="body" class="form-control" rows="10" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Add and index</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addQaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h6 class="modal-title mb-0">Add a question and answer</h6>
                        <span class="text-muted" style="font-size: 0.75rem;">Kept whole, so the answer is returned exactly as written.</span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('kb.sources.store', $collection->id) }}" method="POST">
                    @csrf
                    <input type="hidden" name="type" value="qa">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="qa_title" class="form-label">Question <span style="color: var(--danger);">*</span></label>
                            <input type="text" id="qa_title" name="title" class="form-control"
                                   placeholder="How long do refunds take?" required>
                        </div>
                        <div class="mb-0">
                            <label for="qa_body" class="form-label">Answer <span style="color: var(--danger);">*</span></label>
                            <textarea id="qa_body" name="body" class="form-control" rows="6" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Add and index</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

@endsection
```

- [ ] **Step 8: Add the sidebar entry**

In `admin-laravel/resources/views/layouts/app.blade.php`, add after the Bot profiles link inside the Operate group:

```blade
            <a href="{{ route('kb.index') }}" class="sidebar-link {{ request()->routeIs('kb.*') ? 'active' : '' }}">
                <i class="bi bi-journal-text"></i>
                <span>Knowledge base</span>
            </a>
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `cd admin-laravel && php artisan test --filter=KnowledgeBaseControllerTest`
Expected: PASS, 6 passed

- [ ] **Step 10: Commit**

```bash
git add admin-laravel/app/Http/Controllers/KnowledgeBaseController.php admin-laravel/app/Services/EngineClient.php admin-laravel/resources/views/kb admin-laravel/routes/web.php admin-laravel/resources/views/layouts/app.blade.php admin-laravel/tests/Feature/KnowledgeBaseControllerTest.php
git commit -m "feat: add knowledge base screens"
```

---

### Task 10: Bot Brain page

**Files:**
- Create: `admin-laravel/app/Http/Controllers/BotBrainController.php`
- Create: `admin-laravel/resources/views/bots/brain.blade.php`
- Modify: `admin-laravel/routes/web.php`
- Modify: `admin-laravel/resources/views/bots/form.blade.php`
- Test: `admin-laravel/tests/Feature/BotBrainTest.php`

**Interfaces:**
- Consumes: `BotProfile::collections()` (Task 4); `KbCollection` (Task 4).
- Produces: named routes `bots.brain` (GET) and `bots.brain.update` (PUT).
- The system prompt field moves out of `bots/form.blade.php` and into `bots/brain.blade.php`. `BotProfileController::update` no longer validates `system_prompt`.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/BotBrainTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\KbCollection;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotBrainTest extends TestCase
{
    use RefreshDatabase;

    private System $system;
    private BotProfile $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->system = System::create([
            'id' => 'sys_test', 'name' => 'Test Workspace', 'allowed_origins' => '*',
        ]);
        $this->bot = BotProfile::create([
            'id' => 'test_chat_01', 'system_id' => 'sys_test', 'name' => 'Bot',
            'system_prompt' => 'Original prompt.',
        ]);
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'One']);
        KbCollection::create(['id' => 'kbc_2', 'system_id' => 'sys_test', 'name' => 'Two']);
    }

    private function editor(): User
    {
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    public function test_the_brain_page_shows_the_prompt_and_the_collections(): void
    {
        $this->actingAs($this->editor())
            ->get(route('bots.brain', $this->bot->id))
            ->assertOk()
            ->assertSee('Original prompt.')
            ->assertSee('One')
            ->assertSee('Two');
    }

    public function test_saving_updates_the_prompt_and_the_settings(): void
    {
        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $this->bot->id), [
                'system_prompt' => 'New prompt.',
                'retrieval_enabled' => '1',
                'retrieval_mode' => 'vector',
                'retrieval_top_k' => 8,
                'retrieval_candidates' => 40,
                'retrieval_min_score' => 0.02,
                'retrieval_fallback' => 'answer_anyway',
                'top_p' => 0.9,
                'presence_penalty' => 0.1,
                'frequency_penalty' => 0.2,
                'thinking_level' => 'medium',
                'collections' => ['kbc_1'],
            ])
            ->assertRedirect();

        $bot = $this->bot->fresh();
        $this->assertSame('New prompt.', $bot->system_prompt);
        $this->assertTrue($bot->retrieval_enabled);
        $this->assertSame('vector', $bot->retrieval_mode);
        $this->assertSame(8, $bot->retrieval_top_k);
        $this->assertSame('answer_anyway', $bot->retrieval_fallback);
        $this->assertSame('medium', $bot->thinking_level);
        $this->assertEqualsWithDelta(0.9, $bot->top_p, 0.0001);
        $this->assertSame(['kbc_1'], $bot->collections->pluck('id')->all());
    }

    public function test_unchecking_every_collection_detaches_them_all(): void
    {
        $this->bot->collections()->sync(['kbc_1', 'kbc_2']);

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $this->bot->id), [
                'system_prompt' => 'p',
                'retrieval_mode' => 'hybrid',
                'retrieval_top_k' => 5,
                'retrieval_candidates' => 30,
                'retrieval_min_score' => 0,
                'retrieval_fallback' => 'say_unknown',
                'top_p' => 1,
                'presence_penalty' => 0,
                'frequency_penalty' => 0,
                'thinking_level' => 'off',
            ])
            ->assertRedirect();

        $this->assertCount(0, $this->bot->fresh()->collections);
    }

    public function test_a_viewer_cannot_open_the_brain_page(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $viewer->systems()->attach('sys_test', ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->get(route('bots.brain', $this->bot->id))
            ->assertForbidden();
    }

    public function test_a_collection_from_another_workspace_cannot_be_attached(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs']);

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $this->bot->id), [
                'system_prompt' => 'p',
                'retrieval_mode' => 'hybrid',
                'retrieval_top_k' => 5,
                'retrieval_candidates' => 30,
                'retrieval_min_score' => 0,
                'retrieval_fallback' => 'say_unknown',
                'top_p' => 1,
                'presence_penalty' => 0,
                'frequency_penalty' => 0,
                'thinking_level' => 'off',
                'collections' => ['kbc_other'],
            ]);

        $this->assertCount(0, $this->bot->fresh()->collections);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin-laravel && php artisan test --filter=BotBrainTest`
Expected: FAIL, `Route [bots.brain] not defined`

- [ ] **Step 3: Write the controller**

Create `admin-laravel/app/Http/Controllers/BotBrainController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\KbCollection;
use Illuminate\Http\Request;

class BotBrainController extends Controller
{
    public function edit(Request $request, string $id)
    {
        $bot = BotProfile::with('collections')->findOrFail($id);
        abort_unless($request->user()->canManageSystem($bot->system_id, 'editor'), 403);

        return view('bots.brain', [
            'bot' => $bot,
            'collections' => KbCollection::withCount('sources')
                ->where('system_id', $bot->system_id)
                ->orderBy('name')
                ->get(),
            'attached' => $bot->collections->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);
        abort_unless($request->user()->canManageSystem($bot->system_id, 'editor'), 403);

        $validated = $request->validate([
            'system_prompt' => ['nullable', 'string'],
            'retrieval_mode' => ['required', 'in:hybrid,vector,keyword'],
            'retrieval_top_k' => ['required', 'integer', 'min:1', 'max:20'],
            'retrieval_candidates' => ['required', 'integer', 'min:5', 'max:100'],
            'retrieval_min_score' => ['required', 'numeric', 'min:0', 'max:1'],
            'retrieval_fallback' => ['required', 'in:say_unknown,answer_anyway'],
            'top_p' => ['required', 'numeric', 'min:0', 'max:1'],
            'top_k_sampling' => ['nullable', 'integer', 'min:1', 'max:200'],
            'presence_penalty' => ['required', 'numeric', 'min:-2', 'max:2'],
            'frequency_penalty' => ['required', 'numeric', 'min:-2', 'max:2'],
            'thinking_level' => ['required', 'in:off,low,medium,high'],
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string'],
        ]);

        $validated['retrieval_enabled'] = $request->boolean('retrieval_enabled');
        $bot->update($validated);

        // Only collections from this bot's own workspace may be attached, whatever
        // the form posted.
        $allowed = KbCollection::where('system_id', $bot->system_id)
            ->whereIn('id', $request->input('collections', []))
            ->pluck('id')
            ->all();
        $bot->collections()->sync($allowed);

        return redirect()->route('bots.brain', $bot->id)->with('success', 'Brain settings saved.');
    }
}
```

- [ ] **Step 4: Register the routes**

In `admin-laravel/routes/web.php`, add inside the authenticated group, next to the other bot routes:

```php
    Route::get('/bots/{id}/brain', [BotBrainController::class, 'edit'])->name('bots.brain');
    Route::put('/bots/{id}/brain', [BotBrainController::class, 'update'])->name('bots.brain.update');
```

Add to the imports:

```php
use App\Http\Controllers\BotBrainController;
```

- [ ] **Step 5: Write the Brain view**

Create `admin-laravel/resources/views/bots/brain.blade.php`:

```blade
@extends('layouts.app')

@section('page-title', 'Brain')

@section('content')
<div style="max-width: 1000px;">

    <div class="page-head mb-4">
        <div>
            <a href="{{ route('bots.edit', $bot->id) }}" class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
                <i class="bi bi-arrow-left"></i> {{ $bot->name }}
            </a>
            <h1>Brain</h1>
            <p>What this bot knows and how it decides what to say.</p>
        </div>
    </div>

    <form action="{{ route('bots.brain.update', $bot->id) }}" method="POST" id="brainForm">
        @csrf
        @method('PUT')

        <div class="card mb-3">
            <div class="card-header">System prompt</div>
            <div class="p-3">
                <label for="system_prompt" class="visually-hidden">System prompt</label>
                <textarea name="system_prompt" id="system_prompt" rows="6" class="form-control font-monospace">{{ old('system_prompt', $bot->system_prompt) }}</textarea>
                <div class="form-text">Sent ahead of every conversation. Retrieved material is appended to it automatically.</div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Knowledge</div>
            <div class="p-3">
                <div class="form-check form-switch d-flex align-items-center gap-2 mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" name="retrieval_enabled" value="1"
                           id="retrieval_enabled" {{ old('retrieval_enabled', $bot->retrieval_enabled) ? 'checked' : '' }}>
                    <label class="form-check-label" for="retrieval_enabled">
                        Search the knowledge base before answering
                    </label>
                </div>

                @if($collections->isEmpty())
                    <p class="text-muted mb-0" style="font-size: 0.8125rem;">
                        This workspace has no collections yet.
                        <a href="{{ route('kb.index') }}">Create one</a> before switching retrieval on.
                    </p>
                @else
                    <div class="text-muted mb-2" style="font-size: 0.75rem;">Collections this bot reads</div>
                    <div class="ws-list">
                        @foreach($collections as $collection)
                            <div class="ws-row" style="grid-template-columns: auto minmax(0, 1fr) 90px;">
                                <input class="form-check-input mt-0" type="checkbox" name="collections[]"
                                       value="{{ $collection->id }}" id="col_{{ $collection->id }}"
                                       {{ in_array($collection->id, old('collections', $attached)) ? 'checked' : '' }}>
                                <label for="col_{{ $collection->id }}" class="text-truncate mb-0" style="cursor: pointer;">
                                    {{ $collection->name }}
                                </label>
                                <span class="figure-mono text-muted text-end" style="font-size: 0.75rem;">
                                    {{ $collection->sources_count }} sources
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Retrieval</div>
            <div class="p-3">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label for="retrieval_mode" class="form-label">Search mode</label>
                        <select name="retrieval_mode" id="retrieval_mode" class="form-select">
                            <option value="hybrid" {{ old('retrieval_mode', $bot->retrieval_mode) === 'hybrid' ? 'selected' : '' }}>Hybrid, meaning and keywords</option>
                            <option value="vector" {{ old('retrieval_mode', $bot->retrieval_mode) === 'vector' ? 'selected' : '' }}>Meaning only</option>
                            <option value="keyword" {{ old('retrieval_mode', $bot->retrieval_mode) === 'keyword' ? 'selected' : '' }}>Keywords only</option>
                        </select>
                        <div class="form-text">Hybrid suits most content. Keywords only helps when exact codes matter.</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="retrieval_fallback" class="form-label">When nothing relevant is found</label>
                        <select name="retrieval_fallback" id="retrieval_fallback" class="form-select">
                            <option value="say_unknown" {{ old('retrieval_fallback', $bot->retrieval_fallback) === 'say_unknown' ? 'selected' : '' }}>Say the answer is not available</option>
                            <option value="answer_anyway" {{ old('retrieval_fallback', $bot->retrieval_fallback) === 'answer_anyway' ? 'selected' : '' }}>Answer from general knowledge</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-6 col-lg-4">
                        <label for="retrieval_top_k" class="form-label">Passages used</label>
                        <input type="number" name="retrieval_top_k" id="retrieval_top_k" class="form-control font-monospace"
                               min="1" max="20" value="{{ old('retrieval_top_k', $bot->retrieval_top_k) }}" required>
                        <div class="form-text">More context, slower answers.</div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <label for="retrieval_candidates" class="form-label">Candidates per branch</label>
                        <input type="number" name="retrieval_candidates" id="retrieval_candidates" class="form-control font-monospace"
                               min="5" max="100" value="{{ old('retrieval_candidates', $bot->retrieval_candidates) }}" required>
                        <div class="form-text">Depth searched before merging.</div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <label for="retrieval_min_score" class="form-label">Relevance floor</label>
                        <input type="number" step="0.001" name="retrieval_min_score" id="retrieval_min_score"
                               class="form-control font-monospace" min="0" max="1"
                               value="{{ old('retrieval_min_score', $bot->retrieval_min_score) }}" required>
                        <div class="form-text">Fused scores are small: a top hit scores about 0.016, and 0.033 if both branches agree. Leave at 0 to keep everything.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Generation</div>
            <div class="p-3">
                <div class="row g-3">
                    <div class="col-6 col-lg-3">
                        <label for="top_p" class="form-label">Top p</label>
                        <input type="number" step="0.05" min="0" max="1" name="top_p" id="top_p"
                               class="form-control font-monospace" value="{{ old('top_p', $bot->top_p) }}" required>
                        <div class="form-text">Narrows word choice.</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label for="top_k_sampling" class="form-label">Top k</label>
                        <input type="number" min="1" max="200" name="top_k_sampling" id="top_k_sampling"
                               class="form-control font-monospace" value="{{ old('top_k_sampling', $bot->top_k_sampling) }}"
                               placeholder="unset">
                        <div class="form-text">Blank leaves it to the model.</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label for="presence_penalty" class="form-label">Presence penalty</label>
                        <input type="number" step="0.1" min="-2" max="2" name="presence_penalty" id="presence_penalty"
                               class="form-control font-monospace" value="{{ old('presence_penalty', $bot->presence_penalty) }}" required>
                        <div class="form-text">Pushes toward new topics.</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label for="frequency_penalty" class="form-label">Frequency penalty</label>
                        <input type="number" step="0.1" min="-2" max="2" name="frequency_penalty" id="frequency_penalty"
                               class="form-control font-monospace" value="{{ old('frequency_penalty', $bot->frequency_penalty) }}" required>
                        <div class="form-text">Discourages repetition.</div>
                    </div>
                </div>

                <div class="mt-3" style="max-width: 320px;">
                    <label for="thinking_level" class="form-label">Thinking level</label>
                    <select name="thinking_level" id="thinking_level" class="form-select">
                        <option value="off" {{ old('thinking_level', $bot->thinking_level) === 'off' ? 'selected' : '' }}>Off</option>
                        <option value="low" {{ old('thinking_level', $bot->thinking_level) === 'low' ? 'selected' : '' }}>Low</option>
                        <option value="medium" {{ old('thinking_level', $bot->thinking_level) === 'medium' ? 'selected' : '' }}>Medium</option>
                        <option value="high" {{ old('thinking_level', $bot->thinking_level) === 'high' ? 'selected' : '' }}>High</option>
                    </select>
                    <div class="form-text">
                        Only reaches models that accept a reasoning effort setting. Endpoints that do not support it ignore this.
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <span class="text-muted d-none d-sm-inline" style="font-size: 0.75rem;">
                Saving applies to every site running this bot.
            </span>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <a href="{{ route('bots.edit', $bot->id) }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-brand">Save brain settings</button>
            </div>
        </div>
    </form>

</div>
@endsection
```

- [ ] **Step 6: Move the system prompt off the edit form**

In `admin-laravel/resources/views/bots/form.blade.php`, delete the entire card whose header is `System prompt` (from the `{{-- System prompt --}}` comment to the closing `</div>` of that card).

In its place, add:

```blade
                {{-- Prompt and knowledge live on the Brain page --}}
                @if($isEdit)
                    <div class="card mb-3">
                        <div class="card-header">Brain</div>
                        <div class="p-3 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                            <p class="text-muted mb-0" style="font-size: 0.8125rem;">
                                The system prompt, the collections this bot reads, and its retrieval and generation
                                settings are on their own page.
                            </p>
                            <a href="{{ route('bots.brain', $bot->id) }}" class="btn btn-outline-primary flex-shrink-0">
                                <i class="bi bi-diagram-2"></i> Open Brain
                            </a>
                        </div>
                    </div>
                @endif
```

In `admin-laravel/app/Http/Controllers/BotProfileController.php`, remove the line `'system_prompt' => ['nullable', 'string'],` from the `$request->validate([...])` array inside `update()` only. Leave it in `store()`, which still sets a starting prompt.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd admin-laravel && php artisan test --filter=BotBrainTest`
Expected: PASS, 5 passed

Run the whole suite: `cd admin-laravel && php artisan test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add admin-laravel/app/Http/Controllers admin-laravel/resources/views/bots admin-laravel/routes/web.php admin-laravel/tests/Feature/BotBrainTest.php
git commit -m "feat: add bot brain page"
```

---

### Task 11: Admin settings

**Files:**
- Create: `admin-laravel/app/Http/Controllers/AdminSettingsController.php`
- Create: `admin-laravel/resources/views/admin/settings.blade.php`
- Modify: `admin-laravel/routes/web.php`
- Modify: `admin-laravel/resources/views/layouts/app.blade.php`
- Test: `admin-laravel/tests/Feature/AdminSettingsTest.php`

**Interfaces:**
- Consumes: `AppSetting` (Task 4); `EngineClient::testEmbedding` (Task 9).
- Produces: named routes `admin.settings` (GET), `admin.settings.update` (PUT), `admin.settings.test` (POST).

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/AdminSettingsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root', 'email' => 'root@test.com',
            'password' => bcrypt('password'), 'global_role' => 'super_admin',
        ]);
    }

    private function systemAdmin(): User
    {
        $system = System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach($system->id, ['role' => 'system_admin']);

        return $user;
    }

    public function test_a_super_admin_sees_the_settings(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Embedding')
            ->assertSee('nomic-embed-text');
    }

    public function test_a_system_admin_is_refused(): void
    {
        $this->actingAs($this->systemAdmin())
            ->get(route('admin.settings'))
            ->assertForbidden();
    }

    public function test_saving_stores_the_settings(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), [
                'embedding_base_url' => 'http://localhost:11434/v1',
                'embedding_api_key' => '',
                'embedding_model' => 'mxbai-embed-large',
                'embedding_dimensions' => 1024,
                'vector_driver' => 'pgvector',
                'chunk_size' => 800,
                'chunk_overlap' => 100,
            ])
            ->assertRedirect();

        $this->assertSame('mxbai-embed-large', AppSetting::get('embedding_model'));
        $this->assertSame('800', AppSetting::get('chunk_size'));
    }

    public function test_overlap_must_be_smaller_than_chunk_size(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), [
                'embedding_base_url' => 'http://localhost:11434/v1',
                'embedding_api_key' => '',
                'embedding_model' => 'nomic-embed-text',
                'embedding_dimensions' => 768,
                'vector_driver' => 'pgvector',
                'chunk_size' => 500,
                'chunk_overlap' => 500,
            ])
            ->assertSessionHasErrors('chunk_overlap');
    }

    public function test_the_test_button_reports_the_engine_answer(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'dimensions' => 768,
                                           'message' => 'Answered with 768 dimensions.'], 200)]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.test'), [
                'embedding_base_url' => 'http://localhost:11434/v1',
                'embedding_api_key' => '',
                'embedding_model' => 'nomic-embed-text',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'dimensions' => 768]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin-laravel && php artisan test --filter=AdminSettingsTest`
Expected: FAIL, `Route [admin.settings] not defined`

- [ ] **Step 3: Write the controller**

Create `admin-laravel/app/Http/Controllers/AdminSettingsController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\EngineClient;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    private const KEYS = [
        'embedding_base_url', 'embedding_api_key', 'embedding_model',
        'embedding_dimensions', 'vector_driver', 'chunk_size', 'chunk_overlap',
    ];

    public function edit()
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $settings[$key] = AppSetting::get($key);
        }

        return view('admin.settings', ['settings' => $settings]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'embedding_base_url' => ['required', 'string', 'max:500'],
            'embedding_api_key' => ['nullable', 'string', 'max:500'],
            'embedding_model' => ['required', 'string', 'max:120'],
            'embedding_dimensions' => ['required', 'integer', 'min:64', 'max:4096'],
            'vector_driver' => ['required', 'in:pgvector,sqlite'],
            'chunk_size' => ['required', 'integer', 'min:200', 'max:4000'],
            'chunk_overlap' => ['required', 'integer', 'min:0', 'lt:chunk_size'],
        ], [
            'chunk_overlap.lt' => 'Overlap must be smaller than the chunk size.',
        ]);

        foreach (self::KEYS as $key) {
            AppSetting::put($key, $validated[$key] ?? '');
        }

        return redirect()->route('admin.settings')->with('success', 'Settings saved.');
    }

    public function test(Request $request)
    {
        $validated = $request->validate([
            'embedding_base_url' => ['required', 'string'],
            'embedding_api_key' => ['nullable', 'string'],
            'embedding_model' => ['required', 'string'],
        ]);

        return response()->json(EngineClient::testEmbedding(
            $validated['embedding_base_url'],
            $validated['embedding_api_key'] ?? '',
            $validated['embedding_model'],
        ));
    }
}
```

- [ ] **Step 4: Register the routes**

In `admin-laravel/routes/web.php`, inside the existing `Route::middleware('super_admin')->group(...)` block, add:

```php
        Route::get('/admin/settings', [AdminSettingsController::class, 'edit'])->name('admin.settings');
        Route::put('/admin/settings', [AdminSettingsController::class, 'update'])->name('admin.settings.update');
        Route::post('/admin/settings/test', [AdminSettingsController::class, 'test'])->name('admin.settings.test');
```

Add to the imports:

```php
use App\Http\Controllers\AdminSettingsController;
```

- [ ] **Step 5: Write the settings view**

Create `admin-laravel/resources/views/admin/settings.blade.php`:

```blade
@extends('layouts.app')

@section('page-title', 'Admin settings')

@section('content')
<div style="max-width: 860px;">

    <div class="page-head mb-4">
        <div>
            <h1>Admin settings</h1>
            <p>How content is turned into vectors and where those vectors live. These apply to every workspace, so only super admins can change them.</p>
        </div>
    </div>

    <form action="{{ route('admin.settings.update') }}" method="POST">
        @csrf
        @method('PUT')

        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <span>Embedding</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="testEmbedding()">
                    <i class="bi bi-plug"></i> Test connection
                </button>
            </div>
            <div class="p-3">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label for="embedding_base_url" class="form-label">Base URL</label>
                        <input type="text" name="embedding_base_url" id="embedding_base_url"
                               class="form-control font-monospace"
                               value="{{ old('embedding_base_url', $settings['embedding_base_url']) }}" required>
                        <div class="form-text">OpenAI-compatible. Local Ollama serves this at /v1.</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="embedding_api_key" class="form-label">API key</label>
                        <input type="password" name="embedding_api_key" id="embedding_api_key"
                               class="form-control font-monospace"
                               value="{{ old('embedding_api_key', $settings['embedding_api_key']) }}"
                               placeholder="Not needed for local Ollama">
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-sm-8">
                        <label for="embedding_model" class="form-label">Model</label>
                        <input type="text" name="embedding_model" id="embedding_model"
                               class="form-control font-monospace"
                               value="{{ old('embedding_model', $settings['embedding_model']) }}" required>
                    </div>
                    <div class="col-12 col-sm-4">
                        <label for="embedding_dimensions" class="form-label">Dimensions</label>
                        <input type="number" name="embedding_dimensions" id="embedding_dimensions"
                               class="form-control font-monospace" min="64" max="4096"
                               value="{{ old('embedding_dimensions', $settings['embedding_dimensions']) }}" required>
                    </div>
                </div>

                <div id="embeddingTestResult" class="mt-2" style="font-size: 0.8125rem;"></div>

                <div class="alert alert-warning mt-3 mb-0">
                    Changing the model or the dimensions invalidates every vector already stored.
                    Existing collections keep working on keyword search alone until they are indexed again.
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Vector store</div>
            <div class="p-3">
                <div style="max-width: 340px;">
                    <label for="vector_driver" class="form-label">Driver</label>
                    <select name="vector_driver" id="vector_driver" class="form-select">
                        <option value="pgvector" {{ old('vector_driver', $settings['vector_driver']) === 'pgvector' ? 'selected' : '' }}>PostgreSQL with pgvector</option>
                        <option value="sqlite" {{ old('vector_driver', $settings['vector_driver']) === 'sqlite' ? 'selected' : '' }}>SQLite fallback</option>
                    </select>
                    <div class="form-text">
                        pgvector searches with an index inside the database. The SQLite fallback scans every
                        vector in memory, which is fine for small collections and slows as they grow.
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Chunking</div>
            <div class="p-3">
                <div class="row g-3">
                    <div class="col-6">
                        <label for="chunk_size" class="form-label">Chunk size</label>
                        <input type="number" name="chunk_size" id="chunk_size" class="form-control font-monospace"
                               min="200" max="4000" value="{{ old('chunk_size', $settings['chunk_size']) }}" required>
                        <div class="form-text">Characters per passage. Larger means more context and fewer, blunter matches.</div>
                    </div>
                    <div class="col-6">
                        <label for="chunk_overlap" class="form-label">Overlap</label>
                        <input type="number" name="chunk_overlap" id="chunk_overlap" class="form-control font-monospace"
                               min="0" value="{{ old('chunk_overlap', $settings['chunk_overlap']) }}" required>
                        <div class="form-text">Characters repeated between neighbours, so a sentence split across two passages is still findable.</div>
                    </div>
                </div>
                <div class="form-text mt-2">Applies to sources indexed from now on. Existing chunks keep the size they were made with.</div>
            </div>
        </div>

        <div class="form-actions">
            <span class="text-muted d-none d-sm-inline" style="font-size: 0.75rem;">These settings apply to every workspace.</span>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <button type="submit" class="btn btn-brand">Save settings</button>
            </div>
        </div>
    </form>

</div>
@endsection

@push('scripts')
<script>
    function testEmbedding() {
        var out = document.getElementById('embeddingTestResult');
        out.className = 'mt-2 text-muted';
        out.textContent = 'Testing...';

        fetch('{{ route('admin.settings.test') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                embedding_base_url: document.getElementById('embedding_base_url').value,
                embedding_api_key: document.getElementById('embedding_api_key').value,
                embedding_model: document.getElementById('embedding_model').value
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            out.className = 'mt-2 ' + (data.ok ? 'text-success' : 'text-danger');
            out.textContent = data.message || (data.ok ? 'Connected.' : 'Failed.');
            if (data.ok && data.dimensions) {
                document.getElementById('embedding_dimensions').value = data.dimensions;
            }
        })
        .catch(function () {
            out.className = 'mt-2 text-danger';
            out.textContent = 'Could not reach the admin portal.';
        });
    }
</script>
@endpush
```

- [ ] **Step 6: Add the sidebar entry**

In `admin-laravel/resources/views/layouts/app.blade.php`, add inside the Administer group, after the Users and roles link:

```blade
            @if(auth()->user()->isSuperAdmin())
                <a href="{{ route('admin.settings') }}" class="sidebar-link {{ request()->routeIs('admin.settings') ? 'active' : '' }}">
                    <i class="bi bi-sliders2"></i>
                    <span>Admin settings</span>
                </a>
            @endif
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd admin-laravel && php artisan test --filter=AdminSettingsTest`
Expected: PASS, 5 passed

Run the whole suite: `cd admin-laravel && php artisan test`
Expected: PASS

- [ ] **Step 8: End-to-end check against the running stack**

Start both services. Then:

1. Open Admin settings, press Test connection. Expect "Answered with 768 dimensions."
2. Open Knowledge base, create a collection named "Refund policy".
3. Add a text source titled "Refunds" with body "Refunds are issued within thirty days of purchase, to the original payment method."
4. Reload after a moment. Expect status Indexed with a chunk count of 1 or more.
5. Open a bot's Brain page, switch retrieval on, tick "Refund policy", save.
6. Open that bot's editor, press Open test widget, and ask "how long do refunds take?".

Expected: the answer mentions thirty days, and the browser network tab shows a `sources` event before the token stream.

Verify the chunk landed in PostgreSQL:

```bash
cd api-engine && .venv/Scripts/python.exe -c "
import asyncio, asyncpg
async def m():
    c = await asyncpg.connect(user='postgres', password='postgres', host='127.0.0.1', port=5432, database='chatbot_hub')
    print('chunks:', await c.fetchval('select count(*) from kb_chunks'))
    print('with vectors:', await c.fetchval('select count(*) from kb_chunks where embedding is not null'))
    await c.close()
asyncio.run(m())"
```

Expected: both counts above zero and equal.

- [ ] **Step 9: Commit**

```bash
git add admin-laravel/app/Http/Controllers/AdminSettingsController.php admin-laravel/resources/views/admin admin-laravel/routes/web.php admin-laravel/resources/views/layouts/app.blade.php admin-laravel/tests/Feature/AdminSettingsTest.php
git commit -m "feat: add admin settings for embedding and vector store"
```

---

## Done when

- `cd api-engine && .venv/Scripts/python.exe -m pytest` passes.
- `cd admin-laravel && php artisan test` passes.
- A bot with a collection attached answers from that collection, and emits a `sources` event naming what it used.
- Turning retrieval off returns the bot to its previous behaviour exactly.
- Admin settings is reachable by a super admin and refused to everyone else.
