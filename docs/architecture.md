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

### Pages with no text layer

A scanned PDF or a photographed page carries no text for markitdown to read.
When the install configures the `vision` role, `kb/vision.py` notices: a PDF
whose extracted text averages under 50 characters a page is treated as a scan.
Each page, up to 40, is rendered at 144 dpi and sent to the vision model, which
transcribes it into Markdown. The transcription is chunked like any other
document, so a table on the page stays a table.

An uploaded PNG, JPG or WebP image is always sent to the vision model. Without
one, an image or a scan is refused with a message naming the setting to change,
instead of the old "no text to index". The role has no stand-in: ingestion
belongs to no bot, and a chat model may not read images.

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

**Serving one.** Ollama has no rerank endpoint, so a reranker needs a server
that speaks `POST /v1/rerank`. The lightest is llama.cpp: download a Windows or
Linux build from its releases and a GGUF of `bge-reranker-v2-m3` (the Q8_0 file
is 606 MB), then run

```
llama-server -m bge-reranker-v2-m3-Q8_0.gguf --reranking --host 127.0.0.1 --port 8012 -ngl 99 -c 8192 -b 8192 -ub 8192 --alias bge-reranker-v2-m3
```

and set Admin Settings, Models, Reranker to `http://127.0.0.1:8012/v1` and
`bge-reranker-v2-m3`. The batch sizes matter: llama.cpp's default of 512 tokens
is smaller than a question plus a full 1,800-character passage. On the Sparks,
vLLM serves the same endpoint. On an RTX 3080 it scored a question against a
passage in under 0.15 seconds.

### Similarity floor

Without a reranker the knowledge base still has to be able to say "nothing
here answers this", and fusion cannot: in a small collection both branches rank
nearly every chunk, so an unrelated question scored exactly as a real one
(0.0328 for both on the Kedai Aina set), the knowledge base always answered
first, and the database after it was never asked.

The best cosine similarity can say it. When no passage reaches the bot's
similarity floor, retrieval returns nothing and the next source in the order
gets its turn. The default, 0.65, is where `nomic-embed-text` separated passages
that answered (0.69 to 0.83) from the best passage for questions they could not
answer (0.44 to 0.63). Another embedding model has another scale, so the floor
is re-tuned in the retrieval playground, which shows each passage's similarity.

A reranker, when one is set and answers, decides instead. Keyword-only search
has no similarity and ignores the floor.

A first message is searched in the visitor's own words even when follow-up
understanding is on: there is nothing earlier to resolve, and a small model's
rewrite of a Malay question into English dropped its similarity to the Malay
answer below both floors.

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

### The guard

A bot with "Check messages and answers for harm" switched on sends the
visitor's message to the `guard` role, at the same time as the follow-up
reading. An unsafe verdict replaces the whole answer with the bot's refusal:
no source is consulted and the answer model is never called. After a normal
answer has streamed, the exchange is checked again, and an unsafe answer is
flagged on its message for the operator. Checking before streaming would hold
every answer back.

The role takes a dedicated guard model, whose `Safety:` and `Categories:` lines
are read directly, or a general model standing in for one, which is asked for
JSON. Only unsafe blocks; Qwen3Guard's "Controversial" is recorded and let
through. Every failure lets the message through, because a guard that is down
must not take every bot with it.

| Concern | Lives in |
|---|---|
| Asking and reading the guard | `api-engine/guard.py` |
| Refusing and flagging | `api-engine/routers/chat.py` |

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
older widget simply ignores an event type it does not know. It carries the
`kind` of the source that answered as well as the titles, because a title never
says where it came from, and the widget marks each chip with it: a book for the
knowledge base, a globe for the web, a cylinder for a database.

---

## 7. Settings, and who can see them

| Setting | Scope | Who |
|---|---|---|
| Embedding base URL, key, model, dimensions | Whole install | Super admin |
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
- **Reranking inside the engine.** It is served over HTTP by the `rerank` role
  instead, so the engine carries no PyTorch.
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

## AI providers

A bot does not carry its own endpoint. It points at a row in `ai_providers`,
which holds the name, base URL and API key of one OpenAI-compatible endpoint,
scoped to a workspace exactly as `db_connections` is.

The two hardcoded choices this replaces — local Ollama, or a remote API —
assumed the machine never moved and there was only ever one key. An operator
who works from a laptop, an office machine and a hosted key was retyping a URL
into every bot. A row per endpoint makes that a list, and the link means
changing the laptop's address moves every bot standing on it in one edit.

**Linked, not copied.** `bot_profiles.provider_id` is a foreign key. The
alternative — filling the bot's own fields from a preset — leaves each bot
holding a stale copy the moment the endpoint changes, which is the problem the
feature exists to solve.

**The key is plaintext**, as `bot_profiles.api_key` was. The engine reads this
table directly through SQLAlchemy, so a Laravel `encrypted` cast would put the
key beyond the process that needs it. This is the same trade `db_connections`
does not make, because there Laravel itself runs the query and can decrypt.

**Deleting is refused while a bot points at one.** The refusal names the bots,
because cutting the endpoint out from under a live bot is discovered by a
visitor, not by the operator.

**Managed from inside the bot form**, not on a page of its own: the picker sits
where the endpoint is chosen, and add, edit and delete answer JSON into a modal
so a half-filled bot form survives adding an endpoint.

| Concern | Lives in |
|---|---|
| The provider list, and its CRUD | `admin-laravel/app/Http/Controllers/AiProviderController.php` |
| The picker and its modal | `admin-laravel/resources/views/bots/form.blade.php` |
| Resolving a bot's endpoint | `api-engine/database.py`, `provider_endpoint()` |

---

## Database connections

Laravel holds the connection to a customer's database, not the engine. Every
driver this needs is already loaded on the PHP side: `pdo_mysql`, `pdo_pgsql`,
`pdo_sqlite` and `pdo_sqlsrv`. Putting it in the engine would mean adding an
async MySQL driver and an ODBC stack for SQL Server, for the same four
databases.

The cost is that stage two reverses the call direction for the first time: the
engine will call the portal mid-chat. That is why the new direction gets its own
shared secret rather than reusing `ENGINE_ADMIN_TOKEN`, and why the query route
will re-validate everything the engine already validated.

Discovery merges rather than replaces. A description is written by somebody who
understands the business and is the expensive part of the feature, so a run
updates shape and never touches a description or an enable flag. A table the run
no longer sees is marked absent, never deleted.

| Concern | Lives in |
|---|---|
| Connection config per driver | `admin-laravel/app/Services/Schema/ProbeConnection.php` |
| Reading tables, columns and keys | `admin-laravel/app/Services/Schema/SchemaIntrospector.php` |
| Information schema rows to value objects | `admin-laravel/app/Services/Schema/SchemaMapper.php` |
| Merging a run without losing an annotation | `admin-laravel/app/Services/Schema/SchemaSync.php` |
| Connections and credentials | `admin-laravel/app/Http/Controllers/DbConnectionController.php` |
| The schema editor | `admin-laravel/app/Http/Controllers/DbSchemaController.php` |

### The query path

The engine calls the portal at `POST /internal/db/query`, carrying
`PORTAL_INTERNAL_TOKEN`. That is a different secret from the
`ENGINE_ADMIN_TOKEN` Laravel sends the other way, because compromising one
direction should not hand over the other. The route lives in its own routes
file, outside the web group, so it has no session and no CSRF to be excluded
from.

The portal re-validates everything the engine already validated. The
read-only rules and the allowlist both run twice, in `dbquery/sql.py` and
again in `SqlGuard`. The duplication is deliberate: nothing reaches a
customer's database on the strength of a check that happened in another
process.

No model decides which source answers. Each bot carries an order, and the
sources are consulted in it until one has something; the first with content
answers and the rest are never called. A model router was tried and removed:
it was right most of the time and unpredictable the rest, and an operator
could neither configure it nor explain an answer that came from the wrong
place. See `superpowers/specs/2026-09-14-answer-source-order-design.md`.

Failure is an ordering question too. A statement rejected twice, a question
no table can answer, a portal that is down and a query that times out all
report that the database did not answer, and the next source in the
operator's order gets its turn. No source failure ends a conversation.

| Concern | Lives in |
|---|---|
| The order sources are consulted in | `api-engine/sources/__init__.py` |
| One attempt per source | `api-engine/sources/attempts.py` |
| The schema as the model is told it | `api-engine/dbquery/schema.py` |
| Statement validation and row limits | `api-engine/dbquery/sql.py` |
| Rows into a prompt, inside the budget | `api-engine/dbquery/context.py` |
| Asking the portal to run it | `api-engine/dbquery/portal.py` |
| Orchestration and fall-through | `api-engine/dbquery/__init__.py` |
| Running it, and refusing it again | `admin-laravel/app/Services/Schema/DbQueryRunner.php` |
| Tuning what an annotation is worth | `admin-laravel/app/Http/Controllers/DbPlaygroundController.php` |

Design documents for each phase are under `docs/superpowers/specs/`, and the
implementation plans that built them under `docs/superpowers/plans/`.
