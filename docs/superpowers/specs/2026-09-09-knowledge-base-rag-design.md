# Knowledge Base and Retrieval Augmented Generation

Design document. Written 2026-09-09, revised the same day after PostgreSQL 17.5
with pgvector 0.8.0 was made available on the development machine.

## 1. Problem

Bot profiles today carry a system prompt and nothing else. A bot cannot answer
from a customer's own material, and there is no way for an administrator to
correct a bad answer other than by rewriting the prompt.

This adds a knowledge base: administrators put their own content into the
system, and bots answer from it. When answers are wrong, administrators tune
retrieval and generation without touching code.

## 2. Goals

- Workspace-level collections of content that bots opt into.
- Three source types: pasted text, uploaded files, question and answer pairs.
- Hybrid retrieval (vector plus keyword) fused by rank, wired into the existing
  chat stream.
- Per-bot tuning of retrieval and generation, editable by system admins and
  editors.
- Embedding and vector-store configuration hidden from ordinary users and
  reachable only by super admins.
- A vector store abstraction so SQLite works today and pgvector can replace it
  without rewriting ingestion or retrieval.

## 3. Non-goals

- Website crawling. Explicitly deferred. The schema reserves a `website` source
  type so it slots in later without a migration rewrite.
- Cross-encoder reranking. It needs PyTorch, roughly two gigabytes, for a gain
  that fusion already largely captures at this corpus size.
- Contextual retrieval (an LLM-written context sentence per chunk). It needs one
  competent LLM call per chunk at index time; the largest local model here is
  1.5B parameters, which would be slow and produce weak context.
- Agentic retrieval, where the model decides when to search. Tool calling is
  unreliable at this model size. Retrieval is always on for bots that enable it.

## 4. Decisions already taken

| Decision | Choice | Why |
|---|---|---|
| Collection scope | Workspace, bots opt in | Author content once, reuse across bots, index once |
| Vector store | pgvector 0.8.0 on PostgreSQL 17.5 | Installed and verified on this machine; the `<=>` cosine operator was exercised directly |
| Fallback store | SQLite blobs plus numpy | Kept behind the same interface so the project still runs where Postgres is absent |
| Keyword search | Postgres `tsvector` with GIN; FTS5 `bm25()` on SQLite | Each driver uses its own native full-text engine |
| Fusion | Reciprocal Rank Fusion, k = 60 | Ranks not scores, so the two branches need no score calibration |
| Embedding transport | OpenAI-compatible `POST /v1/embeddings` | One code path serves Ollama and remote providers; verified against local Ollama |
| Default embedding model | `nomic-embed-text`, 768 dimensions | Already pulled locally; verified returning 768 floats |
| Embedding config lives on | The workspace, not the bot | Shared collections must have one consistent vector space |
| System prompt lives on | The bot's Brain page | It is per-bot persona and cannot sit in a shared collection |

## 5. Data model

All tables are created by Laravel migrations. The engine reads and writes them
through its own SQLAlchemy models, mirroring the schema as it does today.

### 5.1 `kb_collections`

| Column | Type | Notes |
|---|---|---|
| id | string(36) PK | |
| system_id | string(36) FK to `systems` | cascade on delete |
| name | string(255) | |
| description | text nullable | |
| created_at / updated_at | timestamps | |

### 5.2 `kb_sources`

One ingested item.

| Column | Type | Notes |
|---|---|---|
| id | string(36) PK | |
| collection_id | string(36) FK to `kb_collections` | cascade on delete |
| type | string(20) | `text`, `file`, `qa`; `website` reserved |
| title | string(500) | for `qa` this holds the question |
| body | longtext nullable | extracted text; for `qa` this holds the answer |
| file_path | string(500) nullable | relative to the public disk |
| file_mime | string(100) nullable | |
| file_size | unsigned int nullable | bytes |
| status | string(20) | `pending`, `processing`, `ready`, `error` |
| error_message | text nullable | shown in the admin when indexing fails |
| chunk_count | unsigned int default 0 | |
| indexed_at | timestamp nullable | |
| created_at / updated_at | timestamps | |

### 5.3 `kb_chunks`

| Column | Type | Notes |
|---|---|---|
| id | big increments PK | |
| collection_id | string(36) indexed | denormalised so a query filters without a join |
| source_id | string(36) FK to `kb_sources` | cascade on delete |
| ordinal | unsigned int | position within the source |
| content | text | the chunk text |
| char_count | unsigned int | |
| embedding | driver-specific | `vector(n)` on Postgres, float32 blob on SQLite |
| embedding_model | string(120) nullable | detects vectors left behind by a model change |
| created_at | timestamp | |

Index on `(collection_id, source_id)`.

The embedding column cannot be expressed in Laravel's schema builder, so the
migration adds it with a raw statement chosen by driver: `ADD COLUMN embedding
vector(768)` on Postgres, `ADD COLUMN embedding blob` on SQLite. A pgvector
column has a fixed width, so changing the configured dimension count means
altering the column and re-embedding. Admin settings performs both together and
says so before it starts.

### 5.4 `kb_chunks_fts`

Driver-owned, not a Laravel migration.

The Postgres driver adds a generated column and indexes it:

```sql
ALTER TABLE kb_chunks ADD COLUMN content_tsv tsvector
  GENERATED ALWAYS AS (to_tsvector('english', content)) STORED;
CREATE INDEX kb_chunks_tsv_idx ON kb_chunks USING GIN (content_tsv);
CREATE INDEX kb_chunks_vec_idx ON kb_chunks
  USING hnsw (embedding vector_cosine_ops);
```

The SQLite driver instead creates:

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS kb_chunks_fts
USING fts5(content, chunk_id UNINDEXED, collection_id UNINDEXED);
```

Neither is visible outside the driver.

### 5.5 `bot_kb_collection`

Pivot. `bot_id`, `collection_id`, both foreign keys, cascade on delete, unique
together.

### 5.6 New columns on `bot_profiles`

Retrieval:

| Column | Type | Default |
|---|---|---|
| retrieval_enabled | boolean | false |
| retrieval_mode | string(20) | `hybrid` (`vector`, `keyword`) |
| retrieval_top_k | unsigned smallint | 5 |
| retrieval_candidates | unsigned smallint | 30 |
| retrieval_min_score | float | 0.0 |
| retrieval_fallback | string(20) | `say_unknown` (`answer_anyway`) |

Generation:

| Column | Type | Default |
|---|---|---|
| top_p | float | 1.0 |
| top_k_sampling | unsigned smallint nullable | null |
| presence_penalty | float | 0.0 |
| frequency_penalty | float | 0.0 |
| thinking_level | string(10) | `off` (`low`, `medium`, `high`) |

`temperature` and `max_tokens` already exist and stay where they are.

### 5.7 `app_settings`

Key-value, one row per key, read through a cached accessor.

| Key | Default |
|---|---|
| embedding_base_url | `http://localhost:11434/v1` |
| embedding_api_key | empty |
| embedding_model | `nomic-embed-text` |
| embedding_dimensions | 768 |
| vector_driver | `pgvector` (`sqlite`) |
| chunk_size | 900 |
| chunk_overlap | 150 |

Laravel writes these; the engine reads them straight from the shared database at
the start of each indexing run and each admin-plane request, the same way it
already reads `bot_profiles`. There is no separate configuration file and no
value is passed over the wire, so the two halves cannot drift apart.

## 6. Vector store interface

```python
class VectorStore(Protocol):
    async def upsert(self, chunks: list[Chunk]) -> None: ...
    async def delete_source(self, source_id: str) -> None: ...
    async def search_vector(self, collection_ids: list[str],
                            query_vector: list[float], limit: int
                            ) -> list[Hit]: ...
    async def search_keyword(self, collection_ids: list[str],
                             query_text: str, limit: int) -> list[Hit]: ...
```

`Hit` is `(chunk_id, source_id, content, score, rank)`. Fusion consumes only
`rank`, so the two branches never need comparable scores.

`PgVectorStore` is the default. The vector branch is
`ORDER BY embedding <=> $1 LIMIT $2`, served by the HNSW index; the keyword
branch ranks `content_tsv @@ plainto_tsquery($1)` with `ts_rank_cd`. Both run in
the database, so no vectors cross the wire.

`SqliteVectorStore` is the fallback. It loads the candidate collections' vectors,
scores them with a single numpy dot product over L2-normalised vectors, and takes
the top slice. Normalising at write time makes cosine similarity a dot product.

Selection happens once at startup from `app_settings.vector_driver`.

## 7. Ingestion

Responsibility splits by capability. Laravel owns the upload, the stored file
and the source record. The engine parses, chunks and embeds, because the parsing
library is Python.

1. Admin creates a source in Laravel. Status is `pending`.
2. Laravel calls `POST /api/v1/kb/sources/{id}/index` on the engine and returns
   immediately.
3. The engine marks it `processing` and runs the rest as a FastAPI background
   task.
4. **Extract.** `text` uses the body as-is. `qa` renders as
   `Q: {title}\nA: {body}`. `file` passes the stored file through markitdown,
   which converts PDF, DOCX, PPTX, XLSX, CSV, HTML and Markdown to Markdown text.
5. **Chunk.** Recursive split on descending separators: `\n## `, `\n\n`, `\n`,
   `. `, then hard character cut. Target `chunk_size` characters with
   `chunk_overlap` carried between neighbours. A `qa` source is never split; one
   pair is one chunk, so an answer is returned whole.
6. **Embed.** Batch the chunks, `POST {embedding_base_url}/embeddings` with the
   configured model. Normalise each vector to unit length.
7. **Store.** Delete any existing chunks for the source, then upsert. Set
   `status = ready`, `chunk_count`, `indexed_at`.
8. On failure, set `status = error` and write `error_message`. The admin shows it
   on the source row with a Retry button.

The admin polls the source list while any row is `pending` or `processing`.

## 8. Retrieval and prompt assembly

In `routers/chat.py`, immediately before `LLMAdapter.stream_chat`, and only when
`bot.retrieval_enabled` and the bot has at least one collection:

1. Embed the user message with the same model used at index time.
2. Vector branch: `search_vector(collection_ids, q, retrieval_candidates)`.
3. Keyword branch: `search_keyword(collection_ids, message, retrieval_candidates)`.
   Skipped in `vector` mode; the vector branch is skipped in `keyword` mode.
4. Fuse:

   ```
   score(chunk) = Σ over branches  1 / (60 + rank_in_branch)
   ```

5. Drop anything below `retrieval_min_score`, keep the top `retrieval_top_k`.

   Fused scores are small by construction: a chunk ranked first in one branch
   scores `1/61`, about `0.0164`, and one ranked first in both scores about
   `0.0328`. The default floor of `0.0` keeps everything, and the Brain page
   states this range beside the field so the number is tunable rather than
   mysterious.
6. Build the context block:

   ```
   Use the following context to answer. Cite the sources you use as [1], [2].

   [1] {source title}
   {chunk content}

   [2] {source title}
   {chunk content}
   ```

7. Append it to the system prompt. When nothing survives step 5 and
   `retrieval_fallback` is `say_unknown`, append an instruction to say the answer
   is not in the available material rather than guess.
8. The engine emits one extra SSE event before the token stream:

   ```
   data: {"type":"sources","sources":[{"n":1,"title":"Refund policy","source_id":"..."}]}
   ```

   The existing token events are unchanged, so an older widget ignores it safely.

Retrieval failure never breaks a chat. Any exception is logged, retrieval is
skipped, and the model answers without context.

## 9. Engine API additions

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/v1/kb/sources/{id}/index` | Start or restart indexing |
| DELETE | `/api/v1/kb/sources/{id}/chunks` | Drop a source's chunks |
| POST | `/api/v1/kb/search` | Playground: collections, query and settings in, ranked chunks with per-branch ranks out |
| POST | `/api/v1/kb/embedding/test` | Admin settings: prove the endpoint answers, return the dimension count |
| POST | `/api/v1/kb/reindex` | Re-embed every chunk after a model change |

These are admin-plane endpoints called by Laravel, not by the widget. The
widget's own endpoints stay public and CORS-checked as they are today.

The admin plane must not be. The engine listens on port 8000, which is reachable
by anything that can reach the host, so these five routes require a shared
secret: Laravel sends `X-Admin-Token`, the engine compares it against
`ADMIN_API_TOKEN` from its environment, and rejects a mismatch with 401. Without
this, anyone able to reach the port could re-index a workspace or read its
content through the search endpoint. The token is generated during setup and
added to both `.env` files; the engine refuses to start the admin routes if it
is unset, rather than defaulting to something guessable.

## 10. Admin surfaces and roles

**Knowledge base** (sidebar, editor and above). Collections for the current
workspace. Inside a collection: the source list with type, status, chunk count
and errors, plus Add text, Add file and Add Q&A.

**Admin settings** (sidebar, super admin only). Embedding provider, base URL,
API key, model and dimensions, with a Test button that calls the engine.
Vector driver with its current status. Chunking defaults. Changing the model or
dimensions invalidates every vector, so the screen states that plainly and
offers Re-index everything.

**Bot profile, Brain** (editor and above). System prompt, moved off the edit
screen. Collection checkboxes. Retrieval settings. Generation settings.

Route authorisation mirrors the existing `canManageSystem` checks. Admin
settings additionally requires `isSuperAdmin`.

## 11. Dependencies

Python, added to `api-engine/requirements.txt`:

- `numpy` for scoring
- `markitdown` for file extraction
- `pytest` and `pytest-asyncio` for tests
- `pgvector` for the SQLAlchemy `Vector` column type; `asyncpg` is already present

No new PHP packages.

## 12. Testing

Unit, pure functions, no network:

- Chunker: respects separators, honours overlap, never emits an empty chunk,
  leaves a `qa` source whole.
- RRF: known per-branch ranks produce a known fused order; a chunk in both
  branches outranks one in either alone.
- Cosine on normalised vectors matches a numpy reference.

Integration, embedding stubbed by a fixture returning deterministic vectors so
the suite needs no Ollama:

- Ingest a fixture document, then query it, and assert the expected chunk ranks
  first.
- A query matching nothing returns nothing and triggers the fallback branch.
- Deleting a source removes its chunks from both the vector and keyword indexes.

Laravel feature tests:

- Collection and source CRUD under each role; a viewer is refused writes.
- Admin settings are refused to a system admin and allowed to a super admin.
- The Brain page persists retrieval and generation settings.

## 13. Risks

**Embedding model changes silently invalidate vectors.** Mitigated by storing
`embedding_model` per chunk, warning on the settings screen, and providing
re-index. A chunk whose model no longer matches the configured one is excluded
from the vector branch until re-indexed, so it degrades to keyword-only rather
than returning nonsense.

**Brute-force vector scan.** Fine into the tens of thousands of chunks, and the
interface exists so pgvector can replace it. Worth revisiting past roughly
100,000 chunks in one workspace.

**Thinking level is not universal.** It maps to `reasoning_effort` on
OpenAI-compatible endpoints and is dropped for models that do not accept it. The
field will say so rather than implying it always works.

**Postgres is stricter than SQLite about types.** Moving the database exposed
this immediately: the engine modelled `is_active` as an integer and compared it
to `1`, which SQLite accepted and Postgres rejected outright. That one is fixed,
and every other engine column was checked against `information_schema` and
matches. The lesson stands for the new tables: the engine's SQLAlchemy models and
Laravel's migrations describe the same tables twice, so they must be reviewed
together whenever either changes.

**Small models may ignore context.** A 1B model given five chunks may still
answer from memory. The playground in phase 2 exists to show whether retrieval
found the right material, separating a retrieval problem from a generation one.

## 14. Phasing

**Phase 1.** Migrations. Collections and sources CRUD for text and Q&A.
Chunking, embedding, indexing. **pgvector driver**, with the SQLite driver
written against the same interface as a fallback. Hybrid retrieval in the chat
stream. Bot Brain page with the system prompt moved onto it, collection opt-in,
retrieval settings and generation settings. Admin settings with embedding
configuration, the driver switch, and the test button.

**Phase 2.** File upload through markitdown. Retrieval playground. Source
citations rendered in the widget. Re-index tooling for a dimension change.

**Phase 3.** Website crawling, if it is still wanted.
