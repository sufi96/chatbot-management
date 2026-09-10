# Architecture Notes

How this system is put together, with emphasis on the retrieval side: embedding,
chunking, and the augmented generation path a chat message travels.

Written 2026-09-10, describing the system as of phase 4.

---

## 1. The three processes

| Piece | Runs on | Owns |
|---|---|---|
| `admin-laravel` | PHP 8.4, port 8080 | People, workspaces, bots, knowledge base content, all settings. Every table's schema. |
| `api-engine` | Python 3.12, FastAPI, port 8000 | Chat streaming, embedding, chunking, indexing, retrieval. |
| `widget` | Plain JS in a Shadow DOM | The bubble on a customer's site. Talks only to the engine. |

They share **one PostgreSQL 17.5 database**. Laravel writes the schema through
its migrations; the engine mirrors the same tables as SQLAlchemy models and
reads and writes rows. There is no ORM sharing and no code sharing between them,
only the table contract.

The split exists because the two halves have different jobs. Document parsing,
embedding maths and streaming belong in Python. Sessions, roles, forms and
migrations belong in Laravel.

```
 browser ──► widget.js ──SSE──► api-engine ──► Ollama (chat + embeddings)
                                     │
 operator ──► admin-laravel ─────────┼──► PostgreSQL 17.5 + pgvector 0.8.0
                    └──admin token───┘
```

Laravel calls the engine over HTTP for four things only: index a source, delete
a source's chunks, test or list embedding models, and run a retrieval preview
for the playground. Those routes are guarded by a shared secret,
`ADMIN_API_TOKEN` on the engine and `ENGINE_ADMIN_TOKEN` in Laravel, which lives
only in gitignored `.env` files.

---

## 2. Data model for retrieval

| Table | Holds |
|---|---|
| `kb_collections` | A named set of content, scoped to one workspace |
| `kb_sources` | One document: pasted text, an uploaded file, or a question-answer pair |
| `kb_chunks` | The indexed passages a source was cut into, with their vectors |
| `bot_kb_collection` | Which collections a bot reads from |
| `app_settings` | Embedding and chunking configuration, one row per key |

Collections belong to a **workspace**, and bots **opt in** to them. Content is
authored once and indexed once, then reused by every bot that enables it.

`kb_chunks` carries the columns retrieval needs:

| Column | Purpose |
|---|---|
| `collection_id` | Denormalised, so a query filters without a join |
| `content` | The chunk text, including its header lines |
| `heading_path` | The section this chunk came from, for display |
| `embedding` | `vector(768)` on Postgres, a float32 blob on SQLite |
| `embedding_model` | Detects vectors left behind by a model change |
| `content_tsv` | A generated `tsvector` column, Postgres only |

The `embedding` column cannot be expressed in Laravel's schema builder, so the
migration adds it with a raw statement chosen by driver. It is deliberately not
mapped in the engine's SQLAlchemy model either; the storage drivers read and
write it with raw SQL.

---

## 3. Embedding

**Transport.** One code path, the OpenAI-compatible `POST {base_url}/embeddings`.
That serves local Ollama and any remote provider without branching. Ollama's
native `/api/embed` is deliberately not used.

**Default model.** `nomic-embed-text`, 768 dimensions, served by Ollama at
`http://localhost:11434/v1`.

**Normalisation.** Every vector is L2-normalised before storage. Cosine
similarity then reduces to a dot product, which is what makes the SQLite
fallback a single matrix multiply.

**Model selection.** The admin fetches the provider's model list through
`GET {base_url}/models` and picks from it. That response carries no flag marking
which models can embed, so ids containing `embed` are sorted first as a hint.
It is never a filter, because a provider may name an embedding model without
that word. The field stays typeable for providers that publish no list.

**Changing the model is a migration, not a setting.** A pgvector column has a
fixed width, so changing the dimension count means nulling every vector,
altering the column, and re-embedding the whole corpus. Admin Settings does all
three together and says so before it starts.

Configuration lives on the **workspace**, not the bot. Shared collections must
sit in one consistent vector space, and only super admins can see or change it.

---

## 4. Chunking

This is the part that changed most, in phase 3. The short version: **structure
decides where a chunk ends; size is only a ceiling.**

### Why not the obvious approaches

Research findings that shaped this, recorded because they contradict the
intuitive choice:

| Technique | Reported effect | Verdict |
|---|---|---|
| Semantic chunking (embed sentences, cut on similarity drop) | *Worse* than recursive splitting, about 54% against 69%, producing 43-token fragments at roughly 14x the indexing cost | Rejected |
| Late chunking | About 3% on long documents | Not worth replacing the embedding path |
| Structure-aware chunking | The largest effect measured: 87% against 13% in a clinical study | **Adopted** |
| Contextual retrieval (LLM writes a context sentence per chunk) | 5-15% | Adopted deterministically, see below |
| Cross-encoder reranking | Meaningful, but needs PyTorch and about 2 GB | Out of scope |

Chunks should be decided by meaning, but the reliable way to find meaning is to
read the structure the author already wrote, not to ask an embedding model to
guess where the topic changed.

### The pipeline

**Stage one, parse into blocks** (`kb/blocks.py`). Walk the text line by line,
emitting typed blocks, each tagged with the heading stack in force when it
started:

| Kind | Recognised by |
|---|---|
| heading | one to six hashes; updates the stack, never becomes a block |
| fence | three or more backticks or tildes, to the matching close |
| table | two or more pipe-leading lines whose second is a dash separator |
| list | a bullet or numbered marker |
| paragraph | everything else, ending at a blank line |

Fences are checked before tables and lists, so a code sample containing pipes is
never re-interpreted. The table rule requires the separator row specifically, so
a paragraph containing a pipe is not mistaken for one.

**Stage two, pack blocks into chunks** (`kb/chunking.py`). Accumulate greedily,
and break when either the heading path changes or the next block would exceed
the size ceiling. The first condition is what makes the boundary structural: a
new section always starts a new chunk, even when the previous one had room.

**Stage three, split anything oversized.** By kind:

- A **table** splits on row boundaries, and every part repeats the header row
  and its separator, so each part reads on its own.
- A **fence** splits on lines, closed and reopened with the same marker.
- **Everything else** falls back to recursive splitting on a blank line, then a
  newline, then a sentence end, then a space, then a hard cut.

**Overlap** applies only inside that last case. Between structural blocks there
is none, because the boundary already means something and carrying a tail across
it would just duplicate text. Where it does apply, the tail is snapped *forward*
to the next whitespace so a chunk can never open on a word fragment. Before this
existed, a shipping paragraph produced a chunk beginning `g is available to most
countries`.

### The header lines

Every chunk carries up to two lines before its body, and both are embedded and
keyword-indexed:

```
Section: Customer Policy > Warranty
About: Returns, warranty and shipping terms for retail customers

| Product line | Warranty | Covers |
| ...
```

`Section:` is the document title plus the heading path. `About:` is the
description an operator wrote for that source. Together they are the
deterministic substitute for contextual retrieval: they say where a passage sits
and what the document is, at no inference cost and with no chance of a
hallucinated summary. The published technique spends one competent LLM call per
chunk, and the largest model available locally is 1.5B parameters.

This is why a warranty table is now findable. Before it, a chunk holding the
whole table contained no occurrence of the word "warranty", so neither retrieval
branch could match a question about it.

**Defaults:** size 1800 characters, overlap 200. About 450 tokens, near the size
the literature converges on. Both are editable in Admin Settings, and both act
as a ceiling rather than a target.

**Question-answer sources bypass chunking entirely** and become one chunk.
Splitting one would return half an answer.

---

## 5. Retrieval

Two branches run over the same corpus and are merged by rank.

**Dense branch.** Embed the query with the same model, then order by cosine
distance. On Postgres that is the `<=>` operator against an HNSW index built
with `vector_cosine_ops`. On SQLite it is a brute-force numpy scan, which is
fine into the tens of thousands of chunks.

**Keyword branch.** Postgres uses a generated `tsvector` column with a GIN
index, ranked by `ts_rank_cd`. SQLite counts term occurrences in memory. Each
driver uses its own native engine.

**Fusion.** Reciprocal Rank Fusion with k = 60:

```
score(chunk) = Σ  1 / (60 + rank_in_branch)
```

Ranks, not scores. That is the whole point: a cosine similarity and a BM25-style
rank are not comparable numbers, and RRF needs no calibration between them. A
chunk that both branches rank highly beats one that either ranks alone.

Dense catches paraphrase. Keyword catches exact terms such as product codes.
Neither alone is enough.

**Both drivers sit behind one `VectorStore` protocol** with four methods, so
retrieval never knows which database it is talking to. Only rank order leaves
the module.

### The gate

Retrieval does not run on every message. Before anything is embedded,
`kb/gating.py` decides whether the message could be a question:

1. Blank: no.
2. Contains a question mark: **yes**, and this overrides everything below.
3. Every word is in a small smalltalk vocabulary: no.
4. No word longer than two letters: no.
5. Otherwise: yes.

The vocabulary covers greetings, thanks, farewells and acknowledgements, and
contains no domain words. Rule 2 is what stops it ever swallowing a real
question, so "thanks, and shipping?" still searches.

This is deliberately not a model call. An intent classifier would double the
wait before the first token and, at 1.5B parameters, classify badly. A greeting
is the case that actually occurs.

When the gate declines, nothing is embedded, nothing is searched, no citations
are emitted, and **the "answer is not in the available material" instruction is
suppressed**. Without that last part, saying hello to a bot configured to admit
when it does not know would be told its greeting is missing from the material.

---

## 6. Prompt assembly

For a message that passes the gate:

1. Collect the bot's opted-in collections.
2. Retrieve, fuse, and drop anything below the bot's relevance floor.
3. Keep the top `k` passages.
4. Trim to the context character budget, keeping the highest-ranked and always
   keeping at least one.
5. Build a numbered context block naming each source.
6. Append it to the bot's system prompt.

The budget trim happens **before** the citation list is built, not inside the
prompt builder. That is what stops the widget showing a source the model never
received.

The assembled prompt reads:

```
<the bot's own system prompt>

Use the following context to answer. Cite the sources you use as [1], [2].

[1] Customer Policy
Section: Customer Policy > Warranty
About: Returns, warranty and shipping terms for retail customers

| Product line | Warranty | Covers |
...
```

When retrieval ran and found nothing, a bot set to `say_unknown` gets an
instruction to say so rather than guess. A bot set to `answer_anyway` does not.

Generation then streams over Server-Sent Events. A `sources` event goes out
first, so the widget can name its citations before the first token arrives; an
older widget simply ignores an event type it does not know.

---

## 7. Settings, and who can see them

| Setting | Scope | Who |
|---|---|---|
| Embedding base URL, key, model, dimensions | Whole install | Super admin |
| Vector driver | Whole install | Super admin |
| Chunk size, overlap, context budget | Whole install | Super admin |
| Collections a bot reads | Per bot | Editor |
| Search mode, top k, candidates, relevance floor, fallback | Per bot | Editor |
| Temperature, top p, thinking level, penalties | Per bot | Editor |
| System prompt | Per bot | Editor |

The division is deliberate. An ordinary operator should put content in and tune
answers; they should not have to know what an embedding dimension is. Everything
about how text becomes vectors is locked to super admins.

The system prompt lives on the bot rather than in a collection, because it is
per-bot persona and cannot be shared.

---

## 8. Operating surfaces

**Retrieval playground.** Ask what a bot would ask and see the exact passages
that come back, with scores and section chips. This is the screen that separates
"the right material was never found" from "it was found and ignored".

**Source detail.** Every passage a source produced, with its section path and
character count, showing the exact text that was embedded, header lines
included. This is where you look when a document is not being found.

**Rebuild the index.** Re-embeds every source in every workspace. Needed after a
model change, a dimension change, or a chunking change.

---

## 9. Degradation and failure

**Retrieval failure never breaks a chat.** The whole retrieval block is wrapped;
on any error the bot answers without context and the reason is logged.

**The SQLite fallback is loud.** If Postgres is unreachable the engine falls back
to SQLite, prints a banner, and reports `database` and `degraded` on `/health`.
A silent fallback would look like a healthy service returning bad answers.

**A vector from the wrong model is ignored.** Searches filter on
`embedding_model`, so a half-finished re-index cannot mix vector spaces.

**Uploads are confined.** An upload path is resolved against the upload root and
rejected if it escapes, even though stored paths are not user input today.

---

## 10. Deliberate non-goals

- **Website crawling.** Reserved in the schema as a source type; not built.
- **Cross-encoder reranking.** Needs PyTorch for a gain fusion largely captures
  at this corpus size.
- **Agentic retrieval**, where the model decides when to search. Tool calling is
  unreliable at these model sizes. Retrieval is on or off per bot, then gated.
- **LLM-written chunk context.** Blocked by local model size. The header lines
  are the substitute, not a first step toward it.
- **Source versioning.** An edit overwrites and re-indexes.
- **Chunk-level editing.** Chunks are derived. Change the source and re-index.

---

## 11. Where the code lives

| Concern | File |
|---|---|
| Markdown to typed blocks | `api-engine/kb/blocks.py` |
| Blocks to chunks, headers, overlap | `api-engine/kb/chunking.py` |
| Embedding transport, model list | `api-engine/kb/embedding.py` |
| File text extraction | `api-engine/kb/extract.py` |
| Source to stored chunks | `api-engine/kb/indexer.py` |
| Vector and keyword storage, both drivers | `api-engine/kb/store.py` |
| Reciprocal rank fusion | `api-engine/kb/fusion.py` |
| Two-branch search, budget, prompt block | `api-engine/kb/retrieval.py` |
| Should we search at all | `api-engine/kb/gating.py` |
| Re-index after a model change | `api-engine/kb/reindex.py` |
| Admin plane, token guarded | `api-engine/routers/kb.py` |
| Chat streaming and prompt assembly | `api-engine/routers/chat.py` |
| Knowledge base screens | `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php` |
| Per-bot retrieval and generation settings | `admin-laravel/app/Http/Controllers/BotBrainController.php` |
| Install-wide settings | `admin-laravel/app/Http/Controllers/AdminSettingsController.php` |
| Calls into the engine | `admin-laravel/app/Services/EngineClient.php` |

Design documents for each phase are under `docs/superpowers/specs/`, and the
implementation plans that built them under `docs/superpowers/plans/`.
