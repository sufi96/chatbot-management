# Knowledge Base Operations

Design document for phase 4. Written 2026-09-10 from operator feedback after
phase 3 shipped. It builds on `2026-09-09-knowledge-base-rag-design.md` and
`2026-09-09-structure-aware-chunking-design.md`; both still hold except where
this one supersedes them.

## 1. Problem

Five things came out of using the knowledge base for real.

**A source is a dead end.** Once content is in a collection there is no way to
read it back, correct it, or get the original file out again. The only row
actions are re-index and delete, so fixing a typo means deleting the source and
uploading it a second time.

**The ways in are hard to see.** Adding content hangs off three equally weighted
buttons in the page header. Nothing says what "Q and A" means before you click
it, and nothing draws the eye when the collection is empty.

**An upload arrives with no context.** A file lands under whatever name it had
on disk. Nobody, human or model, is told what it is for. Phase 3 established
that the words wrapped around a chunk are what make it findable, and an upload
currently contributes none.

**The embedding model is typed from memory.** A misspelling is only caught by
the test button, and there is no way to see what the configured provider
actually offers.

**Retrieval runs on "hello".** A bot with the knowledge base enabled embeds the
message and runs two searches before answering a greeting. It pays for the work,
and worse, it hands the model five irrelevant passages and an instruction to
answer only from them.

## 2. Goals

- Read, correct and export any source without deleting it.
- See how a source was actually chunked, from the same screen.
- Make the three ways to add content self-explanatory.
- Carry an author-written description into every chunk of a source.
- Choose the embedding model from a list fetched from the provider.
- Skip retrieval entirely for messages that cannot be questions, with no extra
  model call and no added latency.

## 3. Non-goals

- **Model-judged intent.** The obvious answer to "hello" is to ask the model
  what the user wants before answering. Rejected: the largest model installed
  here is 1.5B parameters, which classifies unreliably, and the extra call
  doubles the time before the first word appears. A deterministic gate catches
  the case that actually occurs.
- **Editing extracted file text.** An uploaded file's text comes from
  extraction. Making it editable creates a copy that silently diverges from the
  file people can still download.
- **Versioning sources.** An edit overwrites. History is out of scope.
- **Chunk-level editing.** Chunks are derived. The way to change one is to
  change the source and re-index.
- **Website crawling.** Still deferred.

## 4. Decisions taken

| Decision | Choice | Why |
|---|---|---|
| Description scope | Every source type, not just uploads | A pasted policy needs the context as much as a PDF does |
| Description destination | A second breadcrumb line, embedded and keyword-indexed | Phase 3 showed the wrapper text is what makes a chunk findable |
| Where the description is stored | On the source, not copied per chunk | One row to edit; the chunk text is rebuilt on re-index |
| File body editing | Not editable | Would diverge from the downloadable original |
| Chunk listing | Laravel reads `kb_chunks` directly, read-only | Same database, no HTTP round trip; writes stay with the engine |
| Download format | Original file for uploads, Markdown for text and Q&A | Give back what was put in |
| Model list transport | `GET {base_url}/models`, OpenAI-compatible | Verified against local Ollama, returns all six installed models |
| Model field control | Text input backed by a datalist | Listed after fetching, still typeable for providers that expose no list |
| Retrieval gate | Deterministic word test, no model call | Zero latency, predictable, no chance of a wrong classification |
| Gate and fallback | A gated message never gets the "not in the available material" instruction | A greeting must get a greeting, not a refusal |
| Relevance floor default | 0.0 becomes 0.01 | Fused scores top out near 0.016, so a zero floor admits every weak match |

## 5. The retrieval gate

New module, `api-engine/kb/gating.py`.

```python
def should_retrieve(message: str) -> bool:
    """False when the message cannot be a question worth searching for."""
```

The rules, in order:

1. Blank or whitespace-only: do not retrieve.
2. Contains a question mark: retrieve. This wins over everything below, so
   "thanks, what about the warranty?" still searches.
3. Otherwise tokenise on non-letters and lowercase.
4. Every token is in the smalltalk vocabulary: do not retrieve.
5. No token longer than two letters: do not retrieve.
6. Anything else: retrieve.

The smalltalk vocabulary is a fixed set covering greetings (`hi`, `hello`,
`hey`, `morning`, `afternoon`, `evening`, `greetings`), thanks (`thanks`,
`thank`, `ty`, `cheers`, `appreciated`), farewells (`bye`, `goodbye`, `later`,
`night`), acknowledgements (`ok`, `okay`, `sure`, `yes`, `no`, `yeah`, `nope`,
`cool`, `great`, `nice`, `got`, `it`), and the filler that binds them (`you`,
`there`, `so`, `much`, `very`, `good`, `well`, `a`, `lot`).

Rule 5 is what catches a typo or a stray token that is not in the vocabulary.
Rule 2 is what stops the vocabulary ever swallowing a real question.

### 5.1 Wiring

In `routers/chat.py` the condition becomes one boolean that the rest of the
route reads:

```python
retrieval_ran = bot.retrieval_enabled and should_retrieve(req.message)
```

When it is false, nothing is embedded, nothing is searched, no `sources` event
is emitted, and the fallback passed to `augment_system_prompt` is forced to
`answer_anyway` regardless of what the bot has configured:

```python
fallback = bot.retrieval_fallback or "say_unknown"
if not retrieval_ran:
    fallback = "answer_anyway"     # a greeting gets a greeting, not a refusal
```

Without that override, saying "hi" to a bot set to `say_unknown` would answer
that the greeting is not in the available material.

### 5.2 Relevance floor

`bot_profiles.retrieval_min_score` moves from a default of `0` to `0.01`. A
migration rewrites existing rows only where the value is still exactly `0`,
matching the rule used for the chunking defaults in phase 3. It stays editable
per bot on the Brain page.

## 6. The description

### 6.1 Data model

One migration, `add_description_to_kb_sources`.

| Column | Type | Notes |
|---|---|---|
| description | text nullable | added to `kb_sources` after `title` |

Added to `KbSource::$fillable` in Laravel and to the engine's `KbSource` model.

### 6.2 In the chunk

`chunk_document` gains a `description` keyword argument. The header block a
chunk carries becomes up to two lines:

```
Section: Customer Policy > Warranty
About: Returns, warranty and shipping terms for retail customers.

| Product line | Warranty | Covers |
```

The `About:` line is omitted when the source has no description. When there is
neither a section path nor a description, no header is written at all, which is
the existing degradation path for unstructured pasted text.

The whole header counts against the size ceiling, exactly as the section line
does today. The `heading_path` column keeps meaning the section path alone; the
description is not copied into it.

A question and answer source still bypasses chunking and becomes one chunk. Its
description is prepended the same way, because a Q&A pair benefits from knowing
which document it belongs to.

### 6.3 Where it is written

The description is editable wherever a source is created or edited: the add-text
modal, the upload modal, the Q&A modal, and the new source detail page. It is
optional everywhere. Changing it re-indexes the source, because the text that
gets embedded has changed.

## 7. Source detail, edit and download

### 7.1 Routes

| Method | Path | Name |
|---|---|---|
| GET | `/knowledge/sources/{sourceId}` | `kb.sources.show` |
| PUT | `/knowledge/sources/{sourceId}` | `kb.sources.update` |
| GET | `/knowledge/sources/{sourceId}/download` | `kb.sources.download` |

All three authorise against the source's collection's workspace, the same way
the existing re-index and delete routes do. Viewing and downloading are open to
viewers; updating requires an editor.

### 7.2 The page

Header: title, type chip, status, chunk count, last indexed time, and buttons
for download, re-index and delete.

An edit form:

| Type | Editable |
|---|---|
| text | title, description, body |
| qa | title (the question), description, body (the answer) |
| file | title, description |

For a file the extracted text is not shown as an editable field. The stored file
name, MIME type and size are shown instead, next to the download button.

Below the form, the chunks this source produced, in order: ordinal, section
path, character count, and the chunk text. This is the screen that answers "why
did the bot not find this", because it shows exactly what was embedded,
breadcrumb lines included.

Laravel reads those rows straight from `kb_chunks` with the query builder. The
engine remains the only writer. This is a deliberate exception to the phase 1
split, taken because a read-only listing does not justify an HTTP hop, and it is
noted here so it does not look like drift.

### 7.3 Download

For a file source, the stored upload is streamed back under the source title.
For text and Q&A, a Markdown file is generated:

```markdown
# <title>

<description>

<body>
```

named from a slug of the title with a `.md` extension. Nothing is written to
disk to serve it.

### 7.4 Row actions

The source table gains a view action and a download action, so each row offers
view, download, re-index, delete. The title itself links to the detail page.

## 8. Adding content

The three header buttons are replaced by a three-card panel above the source
table, shown whether or not the collection has content. Each card is a button
that opens the modal it already opened, with an icon, a name, and one line
saying when to use it:

| Card | Line |
|---|---|
| Paste text | Policies, guides, anything you can copy in |
| Upload a file | PDF, Word, PowerPoint, Excel, CSV or Markdown |
| Question and answer | One question with its exact answer, kept whole |

All three modals gain the description field. The upload modal's title defaults
to the file name, and stays editable before submitting.

## 9. The embedding model list

### 9.1 Engine

`EmbeddingClient` gains a method:

```python
async def list_models(self, transport=None) -> list[str]:
```

It issues `GET {base_url}/models`, reads `data[].id`, and returns the ids sorted
so that any containing `embed` come first, the rest alphabetically after. The
OpenAI-compatible response carries no flag marking which models can embed, so
the sort is a hint and not a filter: a provider naming an embedding model
without that substring must still be reachable.

New route beside the existing test route, under the same admin token:

```
POST /api/v1/kb/embedding/models   {base_url, api_key}  ->  {ok, models[]}
```

A failure returns `{"ok": false, "message": ...}` rather than an error status,
matching how `embedding/test` already behaves.

### 9.2 Admin

`EngineClient::listEmbeddingModels($baseUrl, $apiKey)` mirrors
`testEmbedding`. A controller action posts to it and returns JSON.

On the settings page the model field becomes a text input bound to a datalist,
next to a Fetch models button. Fetching fills the datalist and reports how many
were found. The input keeps its current value and stays typeable throughout, so
a provider that exposes no list, or is unreachable, never locks an administrator
out of the field.

## 10. Testing

### 10.1 Gate

- A greeting, a thanks, a farewell and a bare acknowledgement each skip.
- A greeting with a real question attached retrieves, because of the question
  mark rule.
- A one-word question retrieves.
- Blank and whitespace-only skip.
- A message of only two-letter tokens skips.
- A message mixing smalltalk and a content word retrieves.

### 10.2 Chat wiring

- A gated message triggers no embedding call. Proven with an embedder that
  raises if called.
- A gated message emits no `sources` event.
- A gated message does not receive the "not in the available material"
  instruction, even when the bot is set to `say_unknown`.
- An ungated message behaves exactly as before.

### 10.3 Description

- The description becomes an `About:` line after the section line.
- A source with no description gets no `About:` line.
- The description counts against the size ceiling.
- A Q&A source carries its description and is still one chunk.
- Indexing passes the source description through.

### 10.4 Source detail

- The page shows the source and its chunks with section paths.
- A viewer can open it; a viewer cannot save an edit.
- Editing text updates the row and queues a re-index.
- A file source shows no editable body.
- Downloading a text source returns Markdown containing title, description and
  body.
- Downloading a file source returns the stored file.
- A source in another workspace is not reachable.

### 10.5 Model list

- The engine sorts models containing `embed` first.
- An unreachable provider returns `ok: false` with a message, not a crash.
- The admin route requires a super admin.
- The settings page renders the datalist and the fetch button.

### 10.6 Migrations

- The description column exists and existing sources survive.
- A `retrieval_min_score` still at `0` becomes `0.01`; one set to `0.05` is left
  alone.

Baseline before this work: 104 Python tests, 53 Laravel tests, all passing.

## 11. Risks

**The gate refuses a real question.** A terse message with no question mark, all
of whose words happen to sit in the smalltalk vocabulary, would be skipped. The
vocabulary is deliberately small and contains no domain words, and rule 2 covers
anything punctuated as a question. If it happens, the fix is to shrink the
vocabulary, not to add cleverness.

**The relevance floor hides a correct answer.** Raising it from zero to 0.01
could drop a weak but correct match on a thin corpus. It stays per-bot and
editable, and the playground shows the scores, so the diagnosis is one screen
away.

**A description makes chunks worse.** A vague or wrong description now rides
along with every chunk of that source and pulls its vectors toward the wrong
region. This is the same risk the heading breadcrumb carries and the same
mitigation applies: it is visible on the source detail page, and re-indexing
after an edit is one click.

**Laravel reading `kb_chunks`.** A schema change made by the engine could break
the listing. The column set is small and stable, and the migration that owns the
table is Laravel's own, so the two cannot drift far.

## 12. Phasing

**4a.** The retrieval gate, its chat wiring, and the relevance floor default.

**4b.** The description column, its place in the breadcrumb, and the three
modals that write it.

**4c.** The source detail page with view, edit and download, plus the new row
actions.

**4d.** The three-card add-content panel.

**4e.** The embedding model list, engine route through to the settings field.
