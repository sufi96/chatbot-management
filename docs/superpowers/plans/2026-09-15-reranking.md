# Reranking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an install configures the `rerank` role, the knowledge base reranks its fused candidates with a cross-encoder and applies a per-bot floor to the reranker's score. With nothing configured it behaves exactly as today.

**Architecture:**
- **Client.** `kb/rerank.py` speaks the Cohere-shaped `POST {base}/rerank` that vLLM and llama.cpp both serve, and calibrates scores into 0 to 1.
- **Retrieval.** `retrieve_for_collections` takes an optional reranker. With one, it reranks up to 40 fused candidates and filters on `rerank_min_score` instead of the RRF floor. If the reranker fails or returns nothing, retrieval falls back to the fusion order and the RRF floor.
- **Source attempt.** The documents attempt builds the reranker from `roles.py` and reports which model reranked, for the trace.
- **Settings.** A per-bot `rerank_min_score` is set on the Brain page and can be tried in the retrieval playground.

**Tech Stack:** Python, httpx, pytest; Laravel 13, PHPUnit, Blade.

**Spec:** `docs/model-stack-review.md` sections 1 to 4, and the plan 3 outline in `docs/superpowers/plans/2026-09-15-spark-readiness-roadmap.md`.

## Global Constraints

- **Protocol:** `POST {base_url}/rerank` with body `{"model", "query", "documents", "top_n"}`. The response is `{"results": [{"index", "relevance_score"}]}`.
- **Calibration:** if every score is already within 0 to 1, keep them as they are. Otherwise pass all of them through a sigmoid, clamping inputs to ±50.
- **Candidates:** at most 40 fused candidates go to the reranker (`RERANK_CANDIDATES = 40`).
- **Floors:** with a reranker, the RRF `min_score` is not applied. Only `rerank_min_score` filters.
- **Fallback:** a reranker exception, or an empty result for a non-empty candidate list, falls back to the fusion order and the RRF floor. Retrieval must never fail because of the reranker.
- **Per-bot floor:** `bot_profiles.rerank_min_score` is a float, default `0.1`, range 0 to 1, validated as `sometimes` so existing forms and tests still submit.
- **Blank role:** a blank `rerank` role means no reranker object is ever built.
- **Test commands:** engine tests run from `api-engine/` with `.venv\Scripts\python.exe -m pytest`; portal tests run from `admin-laravel/` with `php artisan test`.

## File map

| File | Change | Responsibility |
|---|---|---|
| `api-engine/kb/rerank.py` | Create | `calibrate`, `RerankClient`, `client_for` |
| `api-engine/tests/test_rerank.py` | Create | Client behaviour |
| `api-engine/kb/retrieval.py` | Modify | Rerank stage and fallback; `RetrievedChunk.reranked` |
| `api-engine/tests/test_retrieval.py` | Modify | Rerank stage tests |
| `api-engine/sources/result.py` | Modify | `SourceResult.reranked_by` |
| `api-engine/sources/attempts.py` | Modify | Builds the reranker, passes the floor |
| `api-engine/tests/test_source_attempts.py` | Modify | Attempt tests |
| `api-engine/routers/chat.py` | Modify | `rerank` in the trace |
| `api-engine/routers/kb.py` | Modify | Playground search reranks too |
| `api-engine/database.py` | Modify | `BotProfile.rerank_min_score` |
| `admin-laravel/database/migrations/2026_09_15_000003_add_rerank_min_score_to_bot_profiles.php` | Create | Column |
| `admin-laravel/app/Models/BotProfile.php`, `BotBrainController.php`, `bots/brain.blade.php` | Modify | The per-bot floor |
| `admin-laravel/app/Services/EngineClient.php`, `KnowledgeBaseController.php`, `kb/playground.blade.php` | Modify | Playground floor and label |
| `admin-laravel/tests/Feature/BotRerankSettingsTest.php` | Create | Column, default, save, page |
| `admin-laravel/tests/Feature/RetrievalPlaygroundTest.php` | Modify | Floor sent, label shown |
| `docs/architecture.md` | Modify | Reranking section; non-goal removed |

---

### Task 1: A rerank client

**Interfaces produced:**
- `kb.rerank.calibrate(scores: list[float]) -> list[float]`
- `kb.rerank.RerankClient(base_url, api_key, model)`, with `.model` and `async .rerank(query: str, documents: list[str], transport=None) -> list[tuple[int, float]]`, best first
- `kb.rerank.client_for(settings: dict) -> RerankClient | None`

- [ ] **Step 1:** Create `api-engine/tests/test_rerank.py` from Appendix A.
- [ ] **Step 2:** Run `.venv\Scripts\python.exe -m pytest tests/test_rerank.py -q`. Expected: `No module named 'kb.rerank'`.
- [ ] **Step 3:** Create `api-engine/kb/rerank.py` from Appendix B.
- [ ] **Step 4:** Run the full engine suite. Expected: all pass.
- [ ] **Step 5:** Commit with `feat: a client for the rerank protocol that means one thing on any server`.

### Task 2: Retrieval reranks when it has a reranker

**Interfaces produced:**
- `RetrievedChunk(..., heading_path="", reranked: bool = False)`
- `retrieve_for_collections(..., embedder=None, reranker=None, rerank_min_score=0.0)`
- `kb.retrieval.RERANK_CANDIDATES = 40`

- [ ] **Step 1:** Append the tests in Appendix C to `api-engine/tests/test_retrieval.py`.
- [ ] **Step 2:** Run `.venv\Scripts\python.exe -m pytest tests/test_retrieval.py -q`. Expected: failures on the unexpected `reranker` keyword.
- [ ] **Step 3:** Apply Appendix D to `api-engine/kb/retrieval.py`.
- [ ] **Step 4:** Run the full engine suite. Expected: all pass.
- [ ] **Step 5:** Commit with `feat: retrieval reranks its candidates and falls back when the reranker cannot`.

### Task 3: The documents attempt builds the reranker, and the trace names it

**Interfaces produced:**
- `SourceResult.reranked_by: str = ""`
- `attempts.documents(..., retrieve=None, load_titles=None, make_reranker=None)`

- [ ] **Step 1:** Apply the test changes in Appendix E to `api-engine/tests/test_source_attempts.py`.
- [ ] **Step 2:** Run that file. Expected: the new tests fail.
- [ ] **Step 3:** Apply Appendix F to `sources/result.py`, `sources/attempts.py` and `routers/chat.py`.
- [ ] **Step 4:** Run `.venv\Scripts\python.exe -c "import routers.chat"` and then the full engine suite. Expected: all pass.
- [ ] **Step 5:** Commit with `feat: the knowledge base answers from reranked passages when a reranker is set`.

### Task 4: A per-bot reranker floor, and the playground

- [ ] **Step 1:** Create `admin-laravel/tests/Feature/BotRerankSettingsTest.php` from Appendix G, and append the two tests in Appendix H to `RetrievalPlaygroundTest.php`.
- [ ] **Step 2:** Run `php artisan test --filter="BotRerankSettingsTest|RetrievalPlaygroundTest"`. Expected: the new tests fail.
- [ ] **Step 3:** Apply Appendix I: the migration, the model, the controllers, the views, EngineClient, the engine column and the engine search route.
- [ ] **Step 4:** Run both suites. Expected: all pass.
- [ ] **Step 5:** Apply Appendix J to `docs/architecture.md`, and set plan 3's roadmap status to `Done`.
- [ ] **Step 6:** Commit with `feat: each bot sets how relevant a reranked passage must be`.

---

## Appendix

### A. `api-engine/tests/test_rerank.py`

```python
"""The rerank protocol, and scores that mean one thing on any server."""
import json
import math

import httpx
import pytest

from database import SETTING_DEFAULTS
from kb.rerank import RerankClient, calibrate, client_for


def test_probabilities_are_left_alone():
    assert calibrate([0.9, 0.1, 0.0, 1.0]) == [0.9, 0.1, 0.0, 1.0]


def test_raw_logits_are_squashed_into_probabilities():
    scores = calibrate([4.0, -3.0])

    assert abs(scores[0] - 1 / (1 + math.exp(-4.0))) < 1e-9
    assert abs(scores[1] - 1 / (1 + math.exp(3.0))) < 1e-9


def test_one_score_out_of_range_squashes_them_all():
    """Mixing a raw logit with a probability would compare unlike numbers."""
    scores = calibrate([0.5, 7.0])

    assert scores[0] == pytest.approx(1 / (1 + math.exp(-0.5)))


def test_an_extreme_logit_does_not_overflow():
    scores = calibrate([-10000.0, 10000.0])

    assert scores[0] == pytest.approx(0.0, abs=1e-12)
    assert scores[1] == pytest.approx(1.0)


def test_no_scores_is_no_scores():
    assert calibrate([]) == []


@pytest.mark.asyncio
async def test_rerank_posts_the_cohere_shape():
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["auth"] = request.headers.get("authorization")
        seen["body"] = json.loads(request.read().decode())
        return httpx.Response(200, json={"results": [
            {"index": 0, "relevance_score": 0.2},
            {"index": 1, "relevance_score": 0.9},
        ]})

    client = RerankClient("http://spark-b:8003/v1", "secret", "bge-reranker-v2-m3")
    ranked = await client.rerank("warranty?", ["office hours", "two year warranty"],
                                 transport=httpx.MockTransport(handler))

    assert seen["url"] == "http://spark-b:8003/v1/rerank"
    assert seen["auth"] == "Bearer secret"
    assert seen["body"] == {"model": "bge-reranker-v2-m3", "query": "warranty?",
                            "documents": ["office hours", "two year warranty"], "top_n": 2}
    assert ranked == [(1, 0.9), (0, 0.2)]


@pytest.mark.asyncio
async def test_no_documents_makes_no_request():
    def handler(request):
        raise AssertionError("nothing to rerank")

    client = RerankClient("http://x/v1", "", "m")

    assert await client.rerank("q", [], transport=httpx.MockTransport(handler)) == []


@pytest.mark.asyncio
async def test_an_index_outside_the_documents_is_ignored():
    def handler(request):
        return httpx.Response(200, json={"results": [
            {"index": 5, "relevance_score": 0.99},
            {"index": 0, "relevance_score": 0.4},
        ]})

    client = RerankClient("http://x/v1", "", "m")

    assert await client.rerank("q", ["only one"], transport=httpx.MockTransport(handler)) == [(0, 0.4)]


@pytest.mark.asyncio
async def test_a_failed_response_raises():
    def handler(request):
        return httpx.Response(500, text="boom")

    client = RerankClient("http://x/v1", "", "m")

    with pytest.raises(httpx.HTTPStatusError):
        await client.rerank("q", ["a"], transport=httpx.MockTransport(handler))


def test_a_blank_role_builds_no_client():
    assert client_for(SETTING_DEFAULTS) is None


def test_a_configured_role_builds_a_client_for_it():
    client = client_for({**SETTING_DEFAULTS,
                         "rerank_model_base_url": "http://localhost:8012/v1/",
                         "rerank_model_api_key": "k",
                         "rerank_model_name": "bge-reranker-v2-m3"})

    assert client.model == "bge-reranker-v2-m3"
    assert client.base_url == "http://localhost:8012/v1"
    assert client.api_key == "k"
```

### B. `api-engine/kb/rerank.py`

```python
"""Scoring retrieved passages against the question, over the rerank protocol.

An embedding model reads a question and a passage separately, so it matches
topic. A reranker reads the two together and says how well this passage
answers this question, which is the number a relevance floor actually needs.

vLLM and llama.cpp both serve the Cohere-shaped POST {base}/rerank. vLLM
returns scores already between 0 and 1; a llama.cpp build may return the
model's raw logits instead. Scores outside that range go through a sigmoid, so
one floor means the same thing on either server.
"""
import math

import httpx

import roles

TIMEOUT = httpx.Timeout(30.0, connect=5.0)

# Beyond this a sigmoid is 0 or 1 to every digit that matters, and math.exp
# would overflow long before a real model produced such a logit.
_LOGIT_LIMIT = 50.0


def calibrate(scores: list[float]) -> list[float]:
    """Scores between 0 and 1, whichever server produced them.

    All or nothing: one raw logit among probabilities means the whole list is
    raw, and squashing only some would compare unlike numbers.
    """
    if all(0.0 <= score <= 1.0 for score in scores):
        return list(scores)

    return [1.0 / (1.0 + math.exp(-max(-_LOGIT_LIMIT, min(_LOGIT_LIMIT, score))))
            for score in scores]


class RerankClient:
    def __init__(self, base_url: str, api_key: str, model: str):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key or ""
        self.model = model

    async def rerank(self, query: str, documents: list[str],
                     transport=None) -> list[tuple[int, float]]:
        """(index into documents, score) pairs, best first."""
        if not documents:
            return []

        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"

        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            response = await client.post(
                f"{self.base_url}/rerank",
                headers=headers,
                json={"model": self.model, "query": query,
                      "documents": documents, "top_n": len(documents)},
            )
            response.raise_for_status()
            payload = response.json()

        rows = [(int(row["index"]), float(row["relevance_score"]))
                for row in payload.get("results", [])]
        rows = [(index, score) for index, score in rows if 0 <= index < len(documents)]

        scores = calibrate([score for _, score in rows])
        ranked = [(index, score) for (index, _), score in zip(rows, scores)]

        return sorted(ranked, key=lambda pair: pair[1], reverse=True)


def client_for(settings: dict) -> RerankClient | None:
    """A client for the install's reranker, or None when none is configured.

    The rerank role has no stand-in, so it never needs a bot to fall back on.
    """
    endpoint = roles.endpoint_for("rerank", None, settings)
    if not endpoint.available:
        return None

    return RerankClient(endpoint.base_url, endpoint.api_key, endpoint.model)
```

### C. Tests appended to `api-engine/tests/test_retrieval.py`

```python
class FakeReranker:
    """Scores the office passage above the refund one, the reverse of fusion."""
    model = "fake-reranker"

    def __init__(self, scores=None):
        self.scores = scores or {"office": 0.8, "Refunds": 0.3}
        self.seen = []

    async def rerank(self, query, documents, transport=None):
        self.seen.append(list(documents))
        ranked = []
        for index, document in enumerate(documents):
            score = next((s for word, s in self.scores.items() if word in document), 0.0)
            ranked.append((index, score))
        return sorted(ranked, key=lambda pair: pair[1], reverse=True)


class ExplodingReranker:
    model = "exploding"

    async def rerank(self, query, documents, transport=None):
        raise RuntimeError("reranker is down")


class SilentReranker:
    model = "silent"

    async def rerank(self, query, documents, transport=None):
        return []


@pytest.mark.asyncio
async def test_a_reranker_decides_the_order_and_the_score(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", top_k=2, embedder=StubEmbedder(),
        reranker=FakeReranker())

    assert "office" in results[0].content
    assert results[0].score == 0.8
    assert results[0].reranked is True


@pytest.mark.asyncio
async def test_the_reranker_reads_the_passages_themselves(session):
    reranker = FakeReranker()
    await retrieve_for_collections(session, ["col1"], "refund", top_k=2,
                                   embedder=StubEmbedder(), reranker=reranker)

    assert any("Refunds are issued" in document for document in reranker.seen[0])


@pytest.mark.asyncio
async def test_the_reranker_floor_drops_weak_passages(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", top_k=5, embedder=StubEmbedder(),
        reranker=FakeReranker(), rerank_min_score=0.5)

    assert [r.score for r in results] == [0.8]


@pytest.mark.asyncio
async def test_nothing_above_the_reranker_floor_is_a_miss(session):
    """An honest miss is what lets the next source in the order have its turn."""
    results = await retrieve_for_collections(
        session, ["col1"], "refund", embedder=StubEmbedder(),
        reranker=FakeReranker(), rerank_min_score=0.95)

    assert results == []


@pytest.mark.asyncio
async def test_the_fusion_floor_does_not_apply_once_reranked(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", embedder=StubEmbedder(),
        min_score=99.0, reranker=FakeReranker())

    assert results


@pytest.mark.asyncio
async def test_a_failing_reranker_falls_back_to_the_fusion_order(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", top_k=1, embedder=StubEmbedder(),
        reranker=ExplodingReranker())

    assert "Refunds" in results[0].content
    assert results[0].reranked is False


@pytest.mark.asyncio
async def test_a_reranker_that_returns_nothing_falls_back_too(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", top_k=1, embedder=StubEmbedder(),
        reranker=SilentReranker())

    assert "Refunds" in results[0].content


def test_a_chunk_is_not_reranked_unless_it_says_so():
    assert RetrievedChunk(1, "s1", "body", 0.5).reranked is False
```

### D. Changes to `api-engine/kb/retrieval.py`

**RetrievedChunk.** Add a last field:

```python
    heading_path: str = ""
    # True when score is a reranker's, between 0 and 1, rather than a fusion rank.
    reranked: bool = False
```

**Constant.** Below `NO_CONTEXT_INSTRUCTION`, add:

```python
# How many fused candidates a reranker reads. It scores each against the
# question, so this is the knob between latency and a passage fusion ranked low.
RERANK_CANDIDATES = 40
```

**Signature.** The `retrieve_for_collections` signature becomes:

```python
async def retrieve_for_collections(session, collection_ids, query, mode="hybrid",
                                   top_k=5, candidates=30, min_score=0.0,
                                   embedder=None, reranker=None,
                                   rerank_min_score=0.0) -> list[RetrievedChunk]:
```

**Rerank branch.** Directly after `fused = fuse_rankings(branches)`, insert:

```python
    if reranker is not None and fused:
        reranked = await _rerank(reranker, query, fused, by_id, top_k, rerank_min_score)
        if reranked is not None:
            return reranked
```

**Helper.** Add below `retrieve_for_collections`:

```python
async def _rerank(reranker, query, fused, by_id, top_k, floor) -> list[RetrievedChunk] | None:
    """The fused candidates in the reranker's order, or None when it failed.

    None sends retrieval back to the fusion order and its own floor, which is
    exactly what happened before a reranker was configured. A reranker that
    returns nothing for a non-empty list has failed too: silence is not a
    verdict that every passage is irrelevant.
    """
    candidates = [by_id[chunk_id] for chunk_id, _ in fused[:RERANK_CANDIDATES]]

    try:
        ranked = await reranker.rerank(query, [chunk.content for chunk in candidates])
    except Exception as error:
        print(f"[Rerank] Failed, keeping the fusion order: {error}")
        return None

    if not ranked:
        print("[Rerank] Returned no scores, keeping the fusion order.")
        return None

    out: list[RetrievedChunk] = []
    for index, score in ranked:
        # Best first, so the first passage under the floor ends the list.
        if score < floor:
            break
        chunk = candidates[index]
        out.append(RetrievedChunk(chunk.chunk_id, chunk.source_id, chunk.content,
                                  score, chunk.heading_path, reranked=True))
        if len(out) >= top_k:
            break

    return out
```

### E. Test changes to `api-engine/tests/test_source_attempts.py`

**FakeChunk.** Replace `FakeChunk` with:

```python
class FakeChunk:
    def __init__(self, source_id, content, reranked=False):
        self.source_id = source_id
        self.content = content
        self.reranked = reranked
```

**FakeBot.** Add `rerank_min_score = 0.25` to `FakeBot`.

**New tests.** Append:

```python
class FakeReranker:
    model = "bge-reranker-v2-m3"


@pytest.mark.asyncio
async def test_a_configured_reranker_is_handed_to_retrieval_with_the_bots_floor():
    seen = {}

    async def retrieve(**kwargs):
        seen.update(kwargs)
        return [FakeChunk("src_1", "Two year warranty.", reranked=True)]

    async def titles(session, chunks):
        return {"src_1": "Warranty"}

    result = await attempts.documents(
        None, FakeBot(), "warranty?", SETTINGS, ["col_1"],
        retrieve=retrieve, load_titles=titles,
        make_reranker=lambda settings: FakeReranker())

    assert isinstance(seen["reranker"], FakeReranker)
    assert seen["rerank_min_score"] == 0.25
    assert result.reranked_by == "bge-reranker-v2-m3"


@pytest.mark.asyncio
async def test_no_reranker_configured_means_none_is_passed():
    seen = {}

    async def retrieve(**kwargs):
        seen.update(kwargs)
        return [FakeChunk("src_1", "Two year warranty.")]

    async def titles(session, chunks):
        return {"src_1": "Warranty"}

    result = await attempts.documents(
        None, FakeBot(), "warranty?", SETTINGS, ["col_1"],
        retrieve=retrieve, load_titles=titles, make_reranker=lambda settings: None)

    assert seen["reranker"] is None
    assert result.reranked_by == ""


@pytest.mark.asyncio
async def test_passages_that_fell_back_to_fusion_do_not_name_the_reranker():
    async def retrieve(**kwargs):
        return [FakeChunk("src_1", "Two year warranty.", reranked=False)]

    async def titles(session, chunks):
        return {"src_1": "Warranty"}

    result = await attempts.documents(
        None, FakeBot(), "warranty?", SETTINGS, ["col_1"],
        retrieve=retrieve, load_titles=titles,
        make_reranker=lambda settings: FakeReranker())

    assert result.reranked_by == ""
```

### F. Engine changes for Task 3

**`sources/result.py`.** After `row_count`, add:

```python
    # The reranker model whose scores chose these passages, for the trace.
    # Empty when fusion order was used, including after a reranker failed.
    reranked_by: str = ""
```

**`sources/attempts.py`, import.** Add `from kb import rerank` after `import websearch`.

**`sources/attempts.py`, `documents()`.**

- The signature becomes:

  ```python
  async def documents(session, bot, message: str, settings: dict, collection_ids,
                      retrieve=None, load_titles=None, make_reranker=None) -> SourceResult:
  ```

- After `load_titles = load_titles or _titles_for`, add:

  ```python
      # Built only when the install configured one. Blank means fusion order and
      # the RRF floor, exactly as before the rerank role existed.
      reranker = (make_reranker or rerank.client_for)(settings)
  ```

- Add these two keyword arguments to the `retrieve(...)` call:

  ```python
          reranker=reranker,
          rerank_min_score=getattr(bot, "rerank_min_score", None) or 0.0,
  ```

- In the returned `SourceResult`, add:

  ```python
          reranked_by=reranker.model if (reranker and getattr(chunks[0], "reranked", False)) else "",
  ```

**`routers/chat.py`.**

- After the `sql_model = ...` line, add:

  ```python
      # The model behind each job that ran, for the trace written after the stream.
      used_models = {"intent": decision.model}
      if found and found.sql:
          used_models["sql"] = sql_model
      if found and found.reranked_by:
          used_models["rerank"] = found.reranked_by
  ```

- Replace the `model_trace=roles.model_trace(...)` argument with:

  ```python
                              model_trace=roles.model_trace(
                                  transcript.model or bot.model_name, used_models),
  ```

### G. `admin-laravel/tests/Feature/BotRerankSettingsTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BotRerankSettingsTest extends TestCase
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

    private function bot(): BotProfile
    {
        return BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper']);
    }

    private function brainPayload(array $overrides = []): array
    {
        return array_merge([
            'system_prompt' => 'Be helpful.', 'retrieval_mode' => 'hybrid',
            'retrieval_top_k' => 5, 'retrieval_candidates' => 30,
            'retrieval_min_score' => 0.02, 'retrieval_fallback' => 'say_unknown',
            'web_search_max_results' => 3, 'web_search_country' => null,
            'top_p' => 1.0, 'top_k_sampling' => null, 'presence_penalty' => 0,
            'frequency_penalty' => 0, 'thinking_level' => 'off',
            'db_max_rows' => 50, 'db_query_timeout' => 10,
            'source_order' => SourceOrder::DEFAULT,
        ], $overrides);
    }

    public function test_the_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'rerank_min_score'));
    }

    public function test_the_floor_starts_at_a_tenth(): void
    {
        $this->assertSame(0.1, $this->bot()->fresh()->rerank_min_score);
    }

    public function test_the_brain_page_offers_it(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->get(route('bots.brain', $bot->id))
            ->assertOk()
            ->assertSee('Reranker floor')
            ->assertSee('name="rerank_min_score"', false);
    }

    public function test_an_editor_sets_it(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload(['rerank_min_score' => 0.4]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0.4, $bot->fresh()->rerank_min_score);
    }

    public function test_a_floor_above_one_is_refused(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload(['rerank_min_score' => 1.5]))
            ->assertSessionHasErrors('rerank_min_score');
    }
}
```

### H. Tests appended to `RetrievalPlaygroundTest.php`

```php
    public function test_the_reranker_floor_is_sent_to_the_engine(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'warranty', 'collections' => ['kbc_1'],
                'mode' => 'hybrid', 'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
                'rerank_min_score' => 0.35,
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['rerank_min_score'] === 0.35);
    }

    public function test_reranked_scores_are_labelled_as_the_rerankers(): void
    {
        Http::fake(['*' => Http::response([
            'reranked' => true,
            'results' => [['chunk_id' => 7, 'source_id' => 'kbs_1', 'content' => 'Thirty days.',
                           'score' => 0.91, 'heading_path' => '']],
        ], 200)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'refunds', 'collections' => ['kbc_1'],
                'mode' => 'hybrid', 'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertOk()
            ->assertSee('reranker 0.9100');
    }
```

### I. Portal and engine changes for Task 4

**Migration.** Create `admin-laravel/database/migrations/2026_09_15_000003_add_rerank_min_score_to_bot_profiles.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How relevant a passage must be once a reranker has scored it. Unlike the
     * fusion floor this is a real relevance measure, between 0 and 1, and it
     * is only read when the install has a reranker configured.
     *
     * 0.1 is deliberately lenient: rerankers such as bge-reranker-v2-m3 put
     * an unrelated passage far below it, and a floor set too high turns real
     * answers into misses that hand the question to the web.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->float('rerank_min_score')->default(0.1);
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('rerank_min_score');
        });
    }
};
```

**`BotProfile`.**
- Add `'rerank_min_score',` to `$fillable` after `'retrieval_min_score',`.
- Add `'rerank_min_score' => 'float',` to `casts()` after `'retrieval_min_score' => 'float',`.
- Add `'rerank_min_score' => 0.1,` to `$attributes`.

**`BotBrainController::update`.** Add the rule `'rerank_min_score' => ['sometimes', 'numeric', 'min:0', 'max:1'],` after the `retrieval_min_score` rule.

**`bots/brain.blade.php`.** After the Relevance floor `col-12` block, add:

```blade
                                        <div class="col-12">
                                            <label for="rerank_min_score" class="form-label">Reranker floor</label>
                                            <input type="number" step="0.01" name="rerank_min_score" id="rerank_min_score"
                                                   class="form-control font-monospace" min="0" max="1"
                                                   value="{{ old('rerank_min_score', $bot->rerank_min_score) }}">
                                            <div class="form-text">Used instead of the relevance floor when a reranker is set in admin settings. A reranker scores how well a passage answers the question, from 0 to 1. Try values in the retrieval playground before raising this.</div>
                                        </div>
```

**`EngineClient::search`.**
- Add a trailing parameter `?float $rerankMinScore = null`.
- Build the request body as `$body = [...]`, then add `if ($rerankMinScore !== null) { $body['rerank_min_score'] = $rerankMinScore; }` before posting `$body`.

**`KnowledgeBaseController`.**
- In `playground()`, add `'rerank_min_score' => 0.1` to `settings`.
- In `runPlayground()`, add the rule `'rerank_min_score' => ['sometimes', 'numeric', 'min:0', 'max:1'],`.
- Pass `isset($validated['rerank_min_score']) ? (float) $validated['rerank_min_score'] : null` as the new last argument to `EngineClient::search`.
- Add `'rerank_min_score' => (float) ($validated['rerank_min_score'] ?? 0.1),` to `settings`.
- Add `'reranked' => (bool) ($response['reranked'] ?? false),` to the view data.
- In `playground()`, add `'reranked' => false,` to the view data.

**`kb/playground.blade.php`.**
- Change the `min_score` column's class from `col-12 col-sm-6` to `col-6 col-sm-3`.
- After that column, add:

```blade
                            <div class="col-6 col-sm-3">
                                <label for="rerank_min_score" class="form-label">Reranker floor</label>
                                <input type="number" step="0.01" name="rerank_min_score" id="rerank_min_score"
                                       class="form-control font-monospace" min="0" max="1"
                                       value="{{ $settings['rerank_min_score'] }}">
                                <div class="form-text">Used instead when a reranker is set.</div>
                            </div>
```

- Replace the score chip with:

```blade
                                <span class="chip figure-mono">{{ $reranked ? 'reranker' : 'score' }} {{ number_format($result['score'], 4) }}</span>
```

**`api-engine/database.py`.** In `BotProfile`, after `retrieval_min_score`, add:

```python
    # Read instead of retrieval_min_score when a reranker is configured.
    rerank_min_score = Column(Float, default=0.1)
```

**`api-engine/routers/kb.py`.**
- Add `from kb import rerank`.
- Add `rerank_min_score: float = 0.0` to `SearchRequest`.
- In `search`, pass `reranker=rerank.client_for(await get_settings(db))` and `rerank_min_score=req.rerank_min_score` to `retrieve_for_collections`.
- Return `"reranked": any(r.reranked for r in results)` beside `"results"`.

### J. `docs/architecture.md`

Insert before `### The gate`:

```markdown
### Reranking

When the install configures the `rerank` role, the fused list is not the last
word. Up to 40 fused candidates go to the reranker, which reads each one
together with the question and scores how well it answers it, from 0 to 1. The
reranker's order replaces the fusion order, and the bot's reranker floor
replaces the relevance floor.

That is what makes the cascade's "did the documents answer" honest. A fusion
score is a rank sum and says nothing about relevance, so its floor could only
be tuned against noise. A reranker score is a relevance judgement.

A reranker that fails or returns nothing leaves retrieval on the fusion order
and its floor, as though none were configured. vLLM returns scores between 0
and 1 and some llama.cpp builds return raw logits; `kb/rerank.py` squashes the
latter so one floor holds on either.
```

In section 10, replace the bullet beginning `- **Cross-encoder reranking.**` with:

```markdown
- **Reranking inside the engine.** It is served over HTTP by the `rerank` role
  instead, so the engine carries no PyTorch.
```
