# Knowledge Base Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Diagnose bad answers, ingest real documents, show visitors where an answer came from, and survive an embedding model change.

**Architecture:** Phase 1 built the pipeline; phase 2 makes it usable. The playground calls the engine's existing `/api/v1/kb/search` and shows per-branch ranks, separating a retrieval problem from a generation one. File upload reuses the ingestion path, adding one extraction step in Python because the parsing library is Python. Citations render the `sources` event the engine already emits. Re-indexing exists because a pgvector column has a fixed width, so changing dimensions is DDL plus a re-embed.

**Tech Stack:** PHP 8.4 / Laravel 13, Python 3.12 / FastAPI / SQLAlchemy 2 async, PostgreSQL 17.5 with pgvector 0.8.0, markitdown for document extraction, Bootstrap 5.3 with the existing `console.css` tokens.

**Spec:** `docs/superpowers/specs/2026-09-09-knowledge-base-rag-design.md`

## Global Constraints

- Everything from phase 1 stays true. Read that plan's Global Constraints; they still bind.
- Uploads land on Laravel's `public` disk under `kb/sources/`. `kb_sources.file_path` holds the disk-relative path, for example `kb/sources/abc123.pdf`.
- The engine resolves an upload as `<repo>/admin-laravel/storage/app/public/<file_path>`, the same relative-path trick `config.py` already uses for the SQLite fallback.
- Upload limit 20 MB. Accepted: pdf, docx, pptx, xlsx, csv, md, txt, html.
- Admin-plane engine routes require header `X-Admin-Token`. That includes the new reindex route.
- A pgvector column has fixed width. Changing `embedding_dimensions` means: null every embedding, `ALTER TABLE kb_chunks ALTER COLUMN embedding TYPE vector(n)`, then re-embed. Never alter with rows of the old width still present.
- No emoji in UI copy or widget strings.
- PHPUnit runs on `sqlite :memory:`; production is PostgreSQL. Migrations and DDL must work on both or be driver-guarded.

---

### Task 1: Retrieval playground

**Files:**
- Modify: `admin-laravel/app/Services/EngineClient.php`
- Modify: `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`
- Create: `admin-laravel/resources/views/kb/playground.blade.php`
- Modify: `admin-laravel/routes/web.php`
- Modify: `admin-laravel/resources/views/kb/index.blade.php`
- Test: `admin-laravel/tests/Feature/RetrievalPlaygroundTest.php`

**Interfaces:**
- Consumes: engine `POST /api/v1/kb/search` (phase 1), `KbCollection`, `KbSource`.
- Produces: `EngineClient::search(array $collectionIds, string $query, string $mode, int $topK, int $candidates, float $minScore): array` returning the decoded body or `['results' => [], 'error' => string]`; named routes `kb.playground` (GET) and `kb.playground.run` (POST).

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/RetrievalPlaygroundTest.php`:

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

class RetrievalPlaygroundTest extends TestCase
{
    use RefreshDatabase;

    private System $system;

    protected function setUp(): void
    {
        parent::setUp();
        $this->system = System::create([
            'id' => 'sys_test', 'name' => 'Test Workspace', 'allowed_origins' => '*',
        ]);
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'Refunds']);
        KbSource::create([
            'id' => 'kbs_1', 'collection_id' => 'kbc_1', 'type' => 'text',
            'title' => 'Refund policy', 'body' => 'Thirty days.', 'status' => 'ready',
        ]);
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

    public function test_the_playground_lists_the_workspace_collections(): void
    {
        $this->actingAs($this->editor())
            ->get(route('kb.playground'))
            ->assertOk()
            ->assertSee('Retrieval playground')
            ->assertSee('Refunds');
    }

    public function test_running_a_query_shows_the_matching_passages(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['chunk_id' => 7, 'source_id' => 'kbs_1', 'content' => 'Thirty days.', 'score' => 0.0328],
        ]], 200)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'how long do refunds take',
                'collections' => ['kbc_1'],
                'mode' => 'hybrid',
                'top_k' => 5,
                'candidates' => 30,
                'min_score' => 0,
            ])
            ->assertOk()
            ->assertSee('Thirty days.')
            ->assertSee('Refund policy');   // the source title is resolved, not just its id
    }

    public function test_a_query_that_matches_nothing_says_so(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'unrelated question',
                'collections' => ['kbc_1'],
                'mode' => 'hybrid',
                'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertOk()
            ->assertSee('Nothing matched');
    }

    public function test_an_engine_failure_is_reported_not_swallowed(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'anything',
                'collections' => ['kbc_1'],
                'mode' => 'hybrid',
                'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertOk()
            ->assertSee('Could not reach');
    }

    public function test_a_query_is_required(): void
    {
        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'collections' => ['kbc_1'],
                'mode' => 'hybrid', 'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertSessionHasErrors('query');
    }

    public function test_a_collection_from_another_workspace_is_ignored(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs']);
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'x',
                'collections' => ['kbc_other'],
                'mode' => 'hybrid', 'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            return $request['collection_ids'] === [];
        });
    }

    public function test_a_viewer_cannot_open_the_playground(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $viewer->systems()->attach('sys_test', ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('kb.playground'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin-laravel && php artisan test --filter=RetrievalPlaygroundTest`
Expected: FAIL, `Route [kb.playground] not defined`

- [ ] **Step 3: Add the search call to the engine client**

In `admin-laravel/app/Services/EngineClient.php`, add this method inside the class:

```php
    /**
     * Retrieval preview. Returns the decoded body, or an error entry the
     * playground can display: a tuning tool must never fail silently, because
     * "no results" and "the engine is down" look identical otherwise.
     */
    public static function search(array $collectionIds, string $query, string $mode,
                                  int $topK, int $candidates, float $minScore): array
    {
        try {
            $response = self::request()->post(self::base() . '/api/v1/kb/search', [
                'collection_ids' => array_values($collectionIds),
                'query' => $query,
                'mode' => $mode,
                'top_k' => $topK,
                'candidates' => $candidates,
                'min_score' => $minScore,
            ]);

            if (!$response->successful()) {
                return ['results' => [], 'error' => 'Engine returned HTTP ' . $response->status()];
            }

            return $response->json();
        } catch (\Throwable $e) {
            return ['results' => [], 'error' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }
```

- [ ] **Step 4: Add the controller methods**

In `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`, add these methods inside the class:

```php
    public function playground(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        return view('kb.playground', [
            'activeSystem' => $activeSystem,
            'collections' => KbCollection::withCount('sources')
                ->where('system_id', $activeSystem->id)->orderBy('name')->get(),
            'results' => null,
            'error' => null,
            'query' => '',
            'selected' => [],
            'settings' => ['mode' => 'hybrid', 'top_k' => 5, 'candidates' => 30, 'min_score' => 0],
        ]);
    }

    public function runPlayground(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string'],
            'mode' => ['required', 'in:hybrid,vector,keyword'],
            'top_k' => ['required', 'integer', 'min:1', 'max:20'],
            'candidates' => ['required', 'integer', 'min:5', 'max:100'],
            'min_score' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        // Whatever the form posted, only this workspace's collections are searched.
        $allowed = KbCollection::where('system_id', $activeSystem->id)
            ->whereIn('id', $request->input('collections', []))
            ->pluck('id')->all();

        $response = EngineClient::search(
            $allowed, $validated['query'], $validated['mode'],
            (int) $validated['top_k'], (int) $validated['candidates'],
            (float) $validated['min_score'],
        );

        $results = $response['results'] ?? [];
        $titles = KbSource::whereIn('id', array_column($results, 'source_id'))
            ->pluck('title', 'id');

        return view('kb.playground', [
            'activeSystem' => $activeSystem,
            'collections' => KbCollection::withCount('sources')
                ->where('system_id', $activeSystem->id)->orderBy('name')->get(),
            'results' => $results,
            'titles' => $titles,
            'error' => $response['error'] ?? null,
            'query' => $validated['query'],
            'selected' => $allowed,
            'settings' => [
                'mode' => $validated['mode'],
                'top_k' => (int) $validated['top_k'],
                'candidates' => (int) $validated['candidates'],
                'min_score' => (float) $validated['min_score'],
            ],
        ]);
    }
```

- [ ] **Step 5: Register the routes**

In `admin-laravel/routes/web.php`, add beside the other `kb.*` routes:

```php
    Route::get('/knowledge-playground', [KnowledgeBaseController::class, 'playground'])->name('kb.playground');
    Route::post('/knowledge-playground', [KnowledgeBaseController::class, 'runPlayground'])->name('kb.playground.run');
```

Order does not matter here: `/knowledge-playground` cannot be mistaken for
`/knowledge/{id}`, because the paths differ. Keep them grouped with the other
knowledge routes for readability.

- [ ] **Step 6: Write the playground view**

Create `admin-laravel/resources/views/kb/playground.blade.php`:

```blade
@extends('layouts.app')

@section('page-title', 'Retrieval playground')

@section('content')
<div style="max-width: 1100px;">

    <div class="page-head mb-4">
        <div>
            <a href="{{ route('kb.index') }}" class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
                <i class="bi bi-arrow-left"></i> Knowledge base
            </a>
            <h1>Retrieval playground</h1>
            <p>Ask what a bot would ask and see exactly which passages come back. When an answer is wrong, this tells you whether the right material was found and ignored, or never found at all.</p>
        </div>
    </div>

    <form action="{{ route('kb.playground.run') }}" method="POST">
        @csrf

        <div class="card mb-3">
            <div class="p-3">
                <label for="query" class="form-label">Question</label>
                <div class="input-group">
                    <input type="text" name="query" id="query" class="form-control"
                           value="{{ old('query', $query) }}"
                           placeholder="How long do refunds take?" required>
                    <button type="submit" class="btn btn-brand">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-5">
                <div class="card h-100">
                    <div class="card-header">Collections searched</div>
                    <div class="p-3">
                        @if($collections->isEmpty())
                            <p class="text-muted mb-0" style="font-size: 0.8125rem;">
                                No collections in this workspace yet.
                            </p>
                        @else
                            <div class="ws-list">
                                @foreach($collections as $collection)
                                    <div class="ws-row" style="grid-template-columns: auto minmax(0, 1fr) 110px;">
                                        <input class="form-check-input mt-0" type="checkbox" name="collections[]"
                                               value="{{ $collection->id }}" id="pg_{{ $collection->id }}"
                                               {{ in_array($collection->id, $selected) || empty($selected) ? 'checked' : '' }}>
                                        <label for="pg_{{ $collection->id }}" class="text-truncate mb-0" style="cursor: pointer;">
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
            </div>

            <div class="col-12 col-lg-7">
                <div class="card h-100">
                    <div class="card-header">Settings to try</div>
                    <div class="p-3">
                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label for="mode" class="form-label">Search mode</label>
                                <select name="mode" id="mode" class="form-select">
                                    <option value="hybrid" {{ $settings['mode'] === 'hybrid' ? 'selected' : '' }}>Hybrid</option>
                                    <option value="vector" {{ $settings['mode'] === 'vector' ? 'selected' : '' }}>Meaning only</option>
                                    <option value="keyword" {{ $settings['mode'] === 'keyword' ? 'selected' : '' }}>Keywords only</option>
                                </select>
                            </div>
                            <div class="col-6 col-sm-3">
                                <label for="top_k" class="form-label">Passages</label>
                                <input type="number" name="top_k" id="top_k" class="form-control font-monospace"
                                       min="1" max="20" value="{{ $settings['top_k'] }}" required>
                            </div>
                            <div class="col-6 col-sm-3">
                                <label for="candidates" class="form-label">Candidates</label>
                                <input type="number" name="candidates" id="candidates" class="form-control font-monospace"
                                       min="5" max="100" value="{{ $settings['candidates'] }}" required>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="min_score" class="form-label">Relevance floor</label>
                                <input type="number" step="0.001" name="min_score" id="min_score"
                                       class="form-control font-monospace" min="0" max="1"
                                       value="{{ $settings['min_score'] }}" required>
                                <div class="form-text">A top hit scores about 0.016, or 0.033 when both branches agree.</div>
                            </div>
                        </div>
                        <div class="form-text mt-2">
                            These are not saved. Once a combination works, set it on the bot's Brain page.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    @if($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    @if($results !== null && !$error)
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <span>Results</span>
                <span class="chip figure-mono">{{ count($results) }} passages</span>
            </div>

            @if(empty($results))
                <div class="empty">
                    <i class="bi bi-search"></i>
                    <h6>Nothing matched</h6>
                    <p>No passage cleared the relevance floor. Lower it, widen the candidates, or add material that answers this question.</p>
                </div>
            @else
                <div>
                    @foreach($results as $i => $result)
                        <div class="p-3" style="{{ !$loop->last ? 'border-bottom: 1px solid var(--border);' : '' }}">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-1.5">
                                <span class="fw-semibold" style="font-size: 0.8125rem;">
                                    [{{ $i + 1 }}] {{ $titles[$result['source_id']] ?? 'Untitled' }}
                                </span>
                                <span class="chip figure-mono">score {{ number_format($result['score'], 4) }}</span>
                            </div>
                            <p class="text-muted mb-0" style="font-size: 0.78125rem; line-height: 1.6;">
                                {{ $result['content'] }}
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

</div>
@endsection
```

- [ ] **Step 7: Link it from the knowledge base list**

In `admin-laravel/resources/views/kb/index.blade.php`, replace the page-head action block:

```blade
    @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
        <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newCollectionModal">
            <i class="bi bi-plus-lg"></i> New collection
        </button>
    @endif
```

with:

```blade
    @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('kb.playground') }}" class="btn btn-outline-secondary">
                <i class="bi bi-search"></i> Playground
            </a>
            <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newCollectionModal">
                <i class="bi bi-plus-lg"></i> New collection
            </button>
        </div>
    @endif
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `cd admin-laravel && php artisan test --filter=RetrievalPlaygroundTest`
Expected: PASS, 7 passed

- [ ] **Step 9: Commit**

```bash
git add admin-laravel/app/Services/EngineClient.php admin-laravel/app/Http/Controllers/KnowledgeBaseController.php admin-laravel/resources/views/kb admin-laravel/routes/web.php admin-laravel/tests/Feature/RetrievalPlaygroundTest.php
git commit -m "feat: add retrieval playground"
```

---

### Task 2: File upload and extraction

**Files:**
- Modify: `api-engine/requirements.txt`
- Create: `api-engine/kb/extract.py`
- Modify: `api-engine/kb/indexer.py`
- Modify: `api-engine/config.py`
- Modify: `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`
- Modify: `admin-laravel/resources/views/kb/show.blade.php`
- Test: `api-engine/tests/test_extract.py`
- Test: `admin-laravel/tests/Feature/KnowledgeBaseUploadTest.php`

**Interfaces:**
- Consumes: `index_source` and `extract_text` (phase 1), `KbSource`.
- Produces: `resolve_upload(file_path: str) -> Path`; `extract_file(path: Path) -> str`; `SUPPORTED_UPLOAD_SUFFIXES: set[str]`. `extract_text(source)` gains a `file` branch that calls `extract_file`.
- `settings.UPLOAD_ROOT` is the absolute path of `admin-laravel/storage/app/public`.

- [ ] **Step 1: Add the dependency**

Append to `api-engine/requirements.txt`:

```
markitdown[all]>=0.1.7
```

Install: `cd api-engine && .venv/Scripts/python.exe -m pip install -r requirements.txt`

Confirm: `cd api-engine && .venv/Scripts/python.exe -c "from markitdown import MarkItDown; print('markitdown ok')"`

- [ ] **Step 2: Write the failing tests**

Create `api-engine/tests/test_extract.py`:

```python
from pathlib import Path

import pytest

from kb.extract import SUPPORTED_UPLOAD_SUFFIXES, extract_file, resolve_upload


def test_supported_suffixes_cover_the_documented_types():
    for suffix in (".pdf", ".docx", ".pptx", ".xlsx", ".csv", ".md", ".txt", ".html"):
        assert suffix in SUPPORTED_UPLOAD_SUFFIXES


def test_resolve_upload_joins_onto_the_laravel_public_disk():
    resolved = resolve_upload("kb/sources/abc.pdf")
    assert resolved.as_posix().endswith("admin-laravel/storage/app/public/kb/sources/abc.pdf")


def test_resolve_upload_refuses_to_escape_the_upload_root():
    # A stored path is not user input today, but a traversal must never resolve.
    with pytest.raises(ValueError):
        resolve_upload("../../../../etc/passwd")


def test_extract_reads_a_plain_text_file(tmp_path):
    target = tmp_path / "note.txt"
    target.write_text("Refunds within thirty days.", encoding="utf-8")

    assert "thirty days" in extract_file(target)


def test_extract_reads_a_csv(tmp_path):
    target = tmp_path / "table.csv"
    target.write_text("question,answer\nrefund window,thirty days\n", encoding="utf-8")

    out = extract_file(target)
    assert "thirty days" in out


def test_extract_rejects_an_unsupported_type(tmp_path):
    target = tmp_path / "thing.exe"
    target.write_bytes(b"MZ")

    with pytest.raises(ValueError) as excinfo:
        extract_file(target)
    assert "not supported" in str(excinfo.value).lower()


def test_extract_reports_a_missing_file(tmp_path):
    with pytest.raises(FileNotFoundError):
        extract_file(tmp_path / "gone.pdf")
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_extract.py -v`
Expected: FAIL, `ModuleNotFoundError: No module named 'kb.extract'`

- [ ] **Step 4: Point the engine at Laravel's upload directory**

In `api-engine/config.py`, add inside the `Settings` class, immediately after `SQLITE_FALLBACK_URL`:

```python
    # Laravel stores uploads on its public disk; the engine reads them from
    # disk rather than over HTTP, so it needs the same directory.
    UPLOAD_ROOT: str = os.path.abspath(os.path.join(
        os.path.dirname(__file__), "..", "admin-laravel", "storage", "app", "public"))
```

- [ ] **Step 5: Write the extractor**

Create `api-engine/kb/extract.py`:

```python
"""Turn an uploaded document into plain text.

markitdown converts PDF, Word, PowerPoint, Excel, CSV, HTML and Markdown
through one interface, which is why it is the single dependency here rather
than a different parser per format.
"""
from pathlib import Path

from markitdown import MarkItDown

from config import settings

SUPPORTED_UPLOAD_SUFFIXES = {
    ".pdf", ".docx", ".pptx", ".xlsx", ".xls",
    ".csv", ".md", ".markdown", ".txt", ".html", ".htm", ".json", ".xml",
}


def resolve_upload(file_path: str) -> Path:
    """Absolute path of an upload, confined to the upload root."""
    root = Path(settings.UPLOAD_ROOT).resolve()
    candidate = (root / file_path).resolve()
    if root not in candidate.parents and candidate != root:
        raise ValueError(f"upload path escapes the upload root: {file_path}")
    return candidate


def extract_file(path: Path) -> str:
    if not path.exists():
        raise FileNotFoundError(f"upload not found: {path}")

    suffix = path.suffix.lower()
    if suffix not in SUPPORTED_UPLOAD_SUFFIXES:
        raise ValueError(f"File type {suffix or 'unknown'} is not supported.")

    # Plain text needs no conversion, and routing it through a converter only
    # adds a way for it to fail.
    if suffix in (".txt", ".md", ".markdown"):
        return path.read_text(encoding="utf-8", errors="replace").strip()

    result = MarkItDown().convert(str(path))
    return (result.text_content or "").strip()
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_extract.py -v`
Expected: PASS, 7 passed

- [ ] **Step 7: Teach the indexer about file sources**

In `api-engine/kb/indexer.py`, add to the imports:

```python
from kb.extract import extract_file, resolve_upload
```

Replace the `extract_text` function with:

```python
def extract_text(source: KbSource) -> str:
    """The canonical text for a source, whatever its type."""
    if source.type == "qa":
        return f"Q: {source.title}\nA: {source.body or ''}"
    if source.type == "file":
        if not source.file_path:
            raise ValueError("Source is a file but has no stored path.")
        return extract_file(resolve_upload(source.file_path))
    return (source.body or "").strip()
```

- [ ] **Step 8: Write the indexer test for file sources**

Append to `api-engine/tests/test_indexer.py`:

```python
@pytest.mark.asyncio
async def test_indexing_a_file_source_reads_the_file(session, tmp_path, monkeypatch):
    upload_root = tmp_path / "public"
    (upload_root / "kb" / "sources").mkdir(parents=True)
    (upload_root / "kb" / "sources" / "policy.txt").write_text(
        "Refunds are issued within thirty days.", encoding="utf-8")

    from config import settings
    monkeypatch.setattr(settings, "UPLOAD_ROOT", str(upload_root))

    session.add(KbSource(id="src5", collection_id="col1", type="file",
                         title="policy.txt", file_path="kb/sources/policy.txt"))
    await session.commit()

    count = await index_source(session, "src5", embedder=StubEmbedder())

    assert count == 1
    source = await session.get(KbSource, "src5")
    assert source.status == "ready"

    stored = (await session.execute(
        text("SELECT content FROM kb_chunks WHERE source_id='src5'"))).scalar()
    assert "thirty days" in stored


@pytest.mark.asyncio
async def test_a_missing_upload_records_an_error(session, tmp_path, monkeypatch):
    from config import settings
    monkeypatch.setattr(settings, "UPLOAD_ROOT", str(tmp_path))

    session.add(KbSource(id="src6", collection_id="col1", type="file",
                         title="gone.pdf", file_path="kb/sources/gone.pdf"))
    await session.commit()

    count = await index_source(session, "src6", embedder=StubEmbedder())

    assert count == 0
    source = await session.get(KbSource, "src6")
    assert source.status == "error"
    assert "not found" in source.error_message.lower()
```

- [ ] **Step 9: Run the indexer tests**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_indexer.py -v`
Expected: PASS, 8 passed

- [ ] **Step 10: Write the Laravel upload test**

Create `admin-laravel/tests/Feature/KnowledgeBaseUploadTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\KbCollection;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeBaseUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['*' => Http::response(['status' => 'accepted'], 200)]);

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'C']);
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

    public function test_uploading_a_file_stores_it_and_queues_indexing(): void
    {
        $file = UploadedFile::fake()->createWithContent('policy.txt', 'Refunds in thirty days.');

        $this->actingAs($this->editor())
            ->post(route('kb.sources.upload', 'kbc_1'), ['file' => $file])
            ->assertRedirect();

        $source = \App\Models\KbSource::first();
        $this->assertSame('file', $source->type);
        $this->assertSame('policy.txt', $source->title);
        $this->assertNotNull($source->file_path);
        Storage::disk('public')->assertExists($source->file_path);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/index'));
    }

    public function test_an_unsupported_type_is_refused(): void
    {
        $file = UploadedFile::fake()->create('nasty.exe', 10);

        $this->actingAs($this->editor())
            ->post(route('kb.sources.upload', 'kbc_1'), ['file' => $file])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('kb_sources', 0);
    }

    public function test_a_file_over_the_limit_is_refused(): void
    {
        $file = UploadedFile::fake()->create('huge.pdf', 25000); // 25 MB, limit is 20

        $this->actingAs($this->editor())
            ->post(route('kb.sources.upload', 'kbc_1'), ['file' => $file])
            ->assertSessionHasErrors('file');
    }

    public function test_a_viewer_cannot_upload(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $viewer->systems()->attach('sys_test', ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->post(route('kb.sources.upload', 'kbc_1'),
                ['file' => UploadedFile::fake()->createWithContent('a.txt', 'x')])
            ->assertForbidden();
    }

    public function test_deleting_a_file_source_removes_the_stored_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('policy.txt', 'Refunds.');
        $this->actingAs($this->editor())
            ->post(route('kb.sources.upload', 'kbc_1'), ['file' => $file]);

        $source = \App\Models\KbSource::first();
        $path = $source->file_path;

        $this->actingAs($this->editor())
            ->delete(route('kb.sources.destroy', $source->id))
            ->assertRedirect();

        Storage::disk('public')->assertMissing($path);
    }
}
```

- [ ] **Step 11: Run the test to verify it fails**

Run: `cd admin-laravel && php artisan test --filter=KnowledgeBaseUploadTest`
Expected: FAIL, `Route [kb.sources.upload] not defined`

- [ ] **Step 12: Add the upload handler**

In `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`, add to the imports:

```php
use Illuminate\Support\Facades\Storage;
```

Add this method inside the class:

```php
    public function uploadSource(Request $request, string $collectionId)
    {
        $collection = KbCollection::findOrFail($collectionId);
        $this->authorizeEditor($request, $collection->system_id);

        $validated = $request->validate([
            'file' => [
                'required', 'file', 'max:20480',   // kilobytes, so 20 MB
                'mimes:pdf,docx,pptx,xlsx,xls,csv,md,txt,html,htm',
            ],
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
            'title' => $upload->getClientOriginalName(),
            'file_path' => $path,
            'file_mime' => $upload->getClientMimeType(),
            'file_size' => $upload->getSize(),
            'status' => 'pending',
        ]);

        EngineClient::indexSource($source->id);

        return redirect()->route('kb.show', $collection->id)
            ->with('success', 'Uploaded. Extraction and indexing run in the background.');
    }
```

Then, in `destroySource`, delete the stored file too. Replace:

```php
        EngineClient::deleteChunks($source->id);
        $collectionId = $source->collection_id;
        $source->delete();
```

with:

```php
        EngineClient::deleteChunks($source->id);
        // An orphaned upload would sit on disk forever otherwise.
        if ($source->file_path) {
            Storage::disk('public')->delete($source->file_path);
        }
        $collectionId = $source->collection_id;
        $source->delete();
```

- [ ] **Step 13: Register the route**

In `admin-laravel/routes/web.php`, beside the other `kb.sources.*` routes:

```php
    Route::post('/knowledge/{id}/upload', [KnowledgeBaseController::class, 'uploadSource'])->name('kb.sources.upload');
```

- [ ] **Step 14: Add the upload control to the collection screen**

In `admin-laravel/resources/views/kb/show.blade.php`, in the page-head action block, add before the "Add text" button:

```blade
            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addFileModal">
                <i class="bi bi-upload"></i> Upload file
            </button>
```

And add this modal alongside the others, inside the `@if($canEdit)` block:

```blade
    <div class="modal fade" id="addFileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h6 class="modal-title mb-0">Upload a file</h6>
                        <span class="text-muted" style="font-size: 0.75rem;">The text is extracted, then indexed.</span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('kb.sources.upload', $collection->id) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-body">
                        <label for="kb_file" class="form-label">File <span style="color: var(--danger);">*</span></label>
                        <input type="file" name="file" id="kb_file" class="form-control"
                               accept=".pdf,.docx,.pptx,.xlsx,.xls,.csv,.md,.txt,.html,.htm" required>
                        <div class="form-text">
                            PDF, Word, PowerPoint, Excel, CSV, Markdown, HTML or plain text. Up to 20 MB.
                            Scanned pages with no text layer extract nothing.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Upload and index</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
```

Finally, in the sources table, the "Type" cell currently renders text or Q and A only. Replace:

```blade
                            <td><span class="chip">{{ $source->type === 'qa' ? 'Q and A' : 'Text' }}</span></td>
```

with:

```blade
                            <td>
                                <span class="chip">
                                    @if($source->type === 'qa') Q and A
                                    @elseif($source->type === 'file') File
                                    @else Text @endif
                                </span>
                            </td>
```

And the preview cell shows `$source->body`, which is null for a file. Replace:

```blade
                                    <div class="text-muted text-truncate" style="max-width: 420px; font-size: 0.75rem;">
                                        {{ \Illuminate\Support\Str::limit($source->body, 90) }}
                                    </div>
```

with:

```blade
                                    <div class="text-muted text-truncate" style="max-width: 420px; font-size: 0.75rem;">
                                        @if($source->type === 'file')
                                            {{ $source->file_mime }}, {{ number_format(($source->file_size ?? 0) / 1024) }} KB
                                        @else
                                            {{ \Illuminate\Support\Str::limit($source->body, 90) }}
                                        @endif
                                    </div>
```

- [ ] **Step 15: Run the tests to verify they pass**

Run: `cd admin-laravel && php artisan test --filter=KnowledgeBaseUploadTest`
Expected: PASS, 5 passed

Run the whole suite: `cd admin-laravel && php artisan test`
Expected: PASS

- [ ] **Step 16: Commit**

```bash
git add api-engine/requirements.txt api-engine/kb/extract.py api-engine/kb/indexer.py api-engine/config.py api-engine/tests/test_extract.py api-engine/tests/test_indexer.py admin-laravel/app/Http/Controllers/KnowledgeBaseController.php admin-laravel/resources/views/kb/show.blade.php admin-laravel/routes/web.php admin-laravel/tests/Feature/KnowledgeBaseUploadTest.php
git commit -m "feat: ingest uploaded documents"
```

---

### Task 3: Citations in the widget

**Files:**
- Modify: `widget/widget.js`

**Interfaces:**
- Consumes: the `{"type":"sources","sources":[{"n","title","source_id"}]}` SSE event the engine emits (phase 1).
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Add the citation styles**

In `widget/widget.js`, inside the `styleSheet.textContent` template, add after the `.message-time` rule:

```css
        .message-sources {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
        }

        .source-chip {
            font-size: 10.5px;
            line-height: 1.4;
            padding: 2px 7px;
            border-radius: 999px;
            border: 1px solid #E4E4E7;
            background: #FAFAFA;
            color: #52525B;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sources-label {
            font-size: 10.5px;
            color: #A1A1AA;
            margin-right: 2px;
            align-self: center;
        }
```

- [ ] **Step 2: Render the citations under the answer**

In `widget/widget.js`, add this function immediately after `appendMessage`:

```javascript
    /**
     * Lists the material an answer drew on, under the bubble it belongs to.
     * The visitor sees titles, never chunk contents or ids.
     */
    function attachSources(bubble, sources) {
        if (!bubble || !sources || !sources.length) return;

        var wrapper = bubble.parentNode;
        if (!wrapper || wrapper.querySelector(".message-sources")) return;

        var row = document.createElement("div");
        row.className = "message-sources";

        var label = document.createElement("span");
        label.className = "sources-label";
        label.textContent = "Based on";
        row.appendChild(label);

        for (var i = 0; i < sources.length; i++) {
            var chip = document.createElement("span");
            chip.className = "source-chip";
            chip.textContent = sources[i].title || "Untitled";
            chip.title = sources[i].title || "Untitled";
            row.appendChild(chip);
        }

        // Above the timestamp, which is always the last child.
        wrapper.insertBefore(row, wrapper.lastChild);
    }
```

- [ ] **Step 3: Handle the sources event in the stream loop**

In `widget/widget.js`, in the SSE parsing block, change:

```javascript
                                var parsed = JSON.parse(dataStr);
                                if (parsed.error) {
```

to:

```javascript
                                var parsed = JSON.parse(dataStr);
                                if (parsed.type === "sources") {
                                    pendingSources = parsed.sources || [];
                                } else if (parsed.error) {
```

Declare the variable next to the other per-request state. Change:

```javascript
        var partialText = "";
        var firstChunk = true;
```

to:

```javascript
        var partialText = "";
        var firstChunk = true;
        var pendingSources = [];
```

Then, where the stream ends, attach them. Change:

```javascript
                            if (dataStr === "[DONE]") {
                                stopThinking();
                                isStreaming = false;
                                if (partialText) {
                                    messageHistory.push({ role: "assistant", content: partialText });
                                }
                                return;
                            }
```

to:

```javascript
                            if (dataStr === "[DONE]") {
                                stopThinking();
                                isStreaming = false;
                                if (partialText) {
                                    messageHistory.push({ role: "assistant", content: partialText });
                                    attachSources(botBubble, pendingSources);
                                }
                                return;
                            }
```

- [ ] **Step 4: Remove the last emoji from the widget**

In `widget/widget.js`, change:

```javascript
                                    partialText += "\n⚠️ " + parsed.error;
```

to:

```javascript
                                    partialText += "\n" + parsed.error;
```

- [ ] **Step 5: Check the file still parses**

Run: `node --check widget/widget.js`
Expected: no output

Confirm no emoji remain:

Run: `grep -c "⚠️\|🤖\|✅\|❌" widget/widget.js`
Expected: `0`

- [ ] **Step 6: Verify in the browser**

Start both services. Open a bot with retrieval enabled and a collection attached, open its editor, press Open test widget, and ask a question the material answers.

Expected: the answer streams in, and a row reading "Based on" plus the source titles appears under it. Ask something the material does not cover and no such row appears.

- [ ] **Step 7: Commit**

```bash
git add widget/widget.js
git commit -m "feat: show sources under a widget answer"
```

---

### Task 4: Re-indexing after a model change

**Files:**
- Modify: `api-engine/routers/kb.py`
- Modify: `admin-laravel/app/Services/EngineClient.php`
- Modify: `admin-laravel/app/Http/Controllers/AdminSettingsController.php`
- Modify: `admin-laravel/resources/views/admin/settings.blade.php`
- Modify: `admin-laravel/routes/web.php`
- Test: `api-engine/tests/test_reindex.py`
- Test: `admin-laravel/tests/Feature/AdminSettingsTest.php`

**Interfaces:**
- Consumes: `index_source` (phase 1), `get_settings`, `KbSource`.
- Produces: engine `POST /api/v1/kb/reindex` accepting `{"dimensions": int}` and returning `{"status": "accepted", "sources": int}`; `EngineClient::reindexAll(int $dimensions): array`; named route `admin.settings.reindex` (POST).
- `async prepare_vector_column(session, dimensions: int) -> None` widens or narrows the pgvector column, nulling existing embeddings first. A no-op on SQLite.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_reindex.py`:

```python
import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import AppSetting, Base, KbCollection, KbSource
from kb.reindex import prepare_vector_column, sources_to_reindex


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
        s.add(KbSource(id="s1", collection_id="col1", type="text",
                       title="A", body="alpha", status="ready"))
        s.add(KbSource(id="s2", collection_id="col1", type="text",
                       title="B", body="beta", status="error"))
        s.add(KbSource(id="s3", collection_id="col1", type="text",
                       title="C", body="gamma", status="pending"))
        await s.commit()
        yield s
    await engine.dispose()


@pytest.mark.asyncio
async def test_every_source_is_queued_whatever_its_status(session):
    ids = await sources_to_reindex(session)
    assert sorted(ids) == ["s1", "s2", "s3"]


@pytest.mark.asyncio
async def test_preparing_the_column_leaves_sqlite_vectors_alone(session):
    # SQLite stores a blob of any length, so there is nothing to alter and
    # nothing to clear. Proving that means showing an existing vector survives.
    from kb.store import SqliteVectorStore
    await SqliteVectorStore(session).upsert([{
        "collection_id": "col1", "source_id": "s1", "ordinal": 0,
        "content": "alpha", "char_count": 5,
        "embedding_model": "test", "embedding": [1.0, 0.0],
    }])

    await prepare_vector_column(session, 1024)

    survived = (await session.execute(text(
        "SELECT count(*) FROM kb_chunks WHERE embedding IS NOT NULL"))).scalar()
    assert survived == 1
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_reindex.py -v`
Expected: FAIL, `ModuleNotFoundError: No module named 'kb.reindex'`

- [ ] **Step 3: Write the re-index helpers**

Create `api-engine/kb/reindex.py`:

```python
"""Rebuild every vector after the embedding model changes.

A pgvector column has a fixed width, so a dimension change is DDL, not just a
re-embed: the old vectors must go before the column can be altered.
"""
from sqlalchemy import select, text

from database import KbSource, get_settings


async def sources_to_reindex(session) -> list[str]:
    """Every source, whatever its current status.

    A source that failed last time may well succeed now, and one still pending
    has nothing to lose by being queued again.
    """
    result = await session.execute(select(KbSource.id))
    return list(result.scalars().all())


async def prepare_vector_column(session, dimensions: int) -> None:
    settings = await get_settings(session)
    if settings["vector_driver"] != "pgvector":
        return   # SQLite stores a blob of any length

    # Vectors of the old width cannot survive the alter, and they are about to
    # be replaced anyway.
    await session.execute(text("UPDATE kb_chunks SET embedding = NULL"))
    await session.commit()

    await session.execute(text(
        f"ALTER TABLE kb_chunks ALTER COLUMN embedding TYPE vector({int(dimensions)})"))
    await session.commit()
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest tests/test_reindex.py -v`
Expected: PASS, 2 passed

- [ ] **Step 5: Add the engine endpoint**

In `api-engine/routers/kb.py`, add to the imports:

```python
from kb.reindex import prepare_vector_column, sources_to_reindex
```

Append to the file:

```python
class ReindexRequest(BaseModel):
    dimensions: int = 768


async def _reindex_in_background(source_ids: list[str]) -> None:
    async with database.async_session_factory() as session:
        for source_id in source_ids:
            await index_source(session, source_id)


@router.post("/reindex", dependencies=[Depends(require_admin_token)])
async def reindex_everything(req: ReindexRequest, background: BackgroundTasks,
                             db: AsyncSession = Depends(get_db)):
    await prepare_vector_column(db, req.dimensions)
    source_ids = await sources_to_reindex(db)
    background.add_task(_reindex_in_background, source_ids)
    return {"status": "accepted", "sources": len(source_ids)}
```

- [ ] **Step 6: Add the client call**

In `admin-laravel/app/Services/EngineClient.php`, add inside the class:

```php
    public static function reindexAll(int $dimensions): array
    {
        try {
            $response = self::request()->timeout(60)
                ->post(self::base() . '/api/v1/kb/reindex', ['dimensions' => $dimensions]);

            return $response->successful()
                ? $response->json()
                : ['status' => 'failed', 'message' => 'Engine returned HTTP ' . $response->status()];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => 'Could not reach the engine: ' . $e->getMessage()];
        }
    }
```

- [ ] **Step 7: Add the controller action**

In `admin-laravel/app/Http/Controllers/AdminSettingsController.php`, add inside the class:

```php
    public function reindex(Request $request)
    {
        $dimensions = (int) AppSetting::get('embedding_dimensions');
        $result = EngineClient::reindexAll($dimensions);

        if (($result['status'] ?? '') !== 'accepted') {
            return back()->with('error', $result['message'] ?? 'Re-indexing could not be started.');
        }

        return back()->with('success',
            'Re-indexing started for ' . ($result['sources'] ?? 0) . ' sources. Watch their status in the knowledge base.');
    }
```

- [ ] **Step 8: Register the route**

In `admin-laravel/routes/web.php`, beside the other `admin.settings.*` routes:

```php
        Route::post('/admin/settings/reindex', [AdminSettingsController::class, 'reindex'])->name('admin.settings.reindex');
```

- [ ] **Step 9: Add the button**

In `admin-laravel/resources/views/admin/settings.blade.php`, replace the warning alert:

```blade
                <div class="alert alert-warning mt-3 mb-0">
                    Changing the model or the dimensions invalidates every vector already stored.
                    Existing collections keep working on keyword search alone until they are indexed again.
                </div>
```

with:

```blade
                <div class="alert alert-warning mt-3 mb-0">
                    <div class="mb-2">
                        Changing the model or the dimensions invalidates every vector already stored.
                        Existing collections keep working on keyword search alone until they are indexed again.
                    </div>
                    <div class="text-muted" style="font-size: 0.75rem;">
                        Save your changes first, then re-index. On PostgreSQL a dimension change also
                        alters the column, which clears the old vectors before rebuilding them.
                    </div>
                </div>
```

Then, after the closing `</form>` of the settings form, add a separate form so re-indexing is not a side effect of saving:

```blade
    <div class="card mt-3">
        <div class="card-header">Rebuild the index</div>
        <div class="p-3 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <p class="text-muted mb-0" style="font-size: 0.8125rem;">
                Re-embeds every source in every workspace with the settings above. Run this after
                changing the model or the dimensions. It runs in the background.
            </p>
            <form action="{{ route('admin.settings.reindex') }}" method="POST" class="flex-shrink-0 m-0"
                  onsubmit="return confirm('Re-embed every source in every workspace?');">
                @csrf
                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-repeat"></i> Re-index everything
                </button>
            </form>
        </div>
    </div>
```

- [ ] **Step 10: Add the Laravel tests**

Append these methods to `admin-laravel/tests/Feature/AdminSettingsTest.php`:

```php
    public function test_re_indexing_reports_how_many_sources_were_queued(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted', 'sources' => 4], 200)]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.reindex'))
            ->assertRedirect()
            ->assertSessionHas('success', fn ($message) => str_contains($message, '4 sources'));
    }

    public function test_a_failed_re_index_reports_the_problem(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.reindex'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_a_system_admin_cannot_trigger_a_re_index(): void
    {
        $this->actingAs($this->systemAdmin())
            ->post(route('admin.settings.reindex'))
            ->assertForbidden();
    }
```

- [ ] **Step 11: Run both suites**

Run: `cd admin-laravel && php artisan test`
Expected: PASS

Run: `cd api-engine && .venv/Scripts/python.exe -m pytest -q`
Expected: PASS

- [ ] **Step 12: Verify a real re-index end to end**

Start both services. In Admin settings, press Re-index everything. Then confirm every chunk was rebuilt:

```bash
cd api-engine && .venv/Scripts/python.exe -c "
import asyncio, asyncpg
async def m():
    c = await asyncpg.connect(user='postgres', password='postgres', host='127.0.0.1', port=5432, database='chatbot_hub')
    print('chunks       ', await c.fetchval('select count(*) from kb_chunks'))
    print('with vectors ', await c.fetchval('select count(*) from kb_chunks where embedding is not null'))
    print('sources ready', await c.fetchval(\"select count(*) from kb_sources where status='ready'\"))
    await c.close()
asyncio.run(m())"
```

Expected: chunks and with-vectors are equal and above zero, and every source reads ready.

- [ ] **Step 13: Commit**

```bash
git add api-engine/kb/reindex.py api-engine/routers/kb.py api-engine/tests/test_reindex.py admin-laravel/app/Services/EngineClient.php admin-laravel/app/Http/Controllers/AdminSettingsController.php admin-laravel/resources/views/admin/settings.blade.php admin-laravel/routes/web.php admin-laravel/tests/Feature/AdminSettingsTest.php
git commit -m "feat: rebuild the index after a model change"
```

---

## Done when

- `cd api-engine && .venv/Scripts/python.exe -m pytest` passes.
- `cd admin-laravel && php artisan test` passes.
- A PDF or Word file uploaded to a collection reaches status Indexed with a chunk count above zero.
- The playground returns the expected passage for a question the material answers, and says nothing matched for one it does not.
- A widget answer shows the titles it drew on; an answer with no retrieval shows none.
- Re-index rebuilds every vector, and the count of chunks with vectors matches the total.
