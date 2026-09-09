# Structure-Aware Chunking

Design document for phase 3 of the knowledge base. Written 2026-09-09, after a
second research pass on retrieval chunking. It builds on
`2026-09-09-knowledge-base-rag-design.md`; everything in that document still
holds except where this one supersedes it.

## 1. Problem

Retrieval quality is set at index time. A chunk that starts mid-sentence, or
that has been separated from the heading that gives it meaning, cannot be
rescued by a better query, a better fusion rule, or a larger `top_k`.

The current splitter in `api-engine/kb/chunking.py` cuts on character count
first and structure second. Two defects were reproduced against it directly.

**Overlap cuts words in half.** The overlap tail is taken with a blind slice:

```python
buffer = (chunks[-1][-overlap:] + piece) if (chunks and overlap) else piece
```

Splitting a shipping policy produced a chunk beginning `g is available to most
countries` — the tail landed inside the word "shipping". The fragment embeds
poorly and reads badly when it reaches the model as context.

**Chunks lose their heading.** A document with a `## Warranty` section produced
a chunk containing the entire warranty table and no mention of the word
"warranty" anywhere in it. A query for "warranty period" has nothing to match on
the dense side and nothing to match on the keyword side.

The default size compounds both. 900 characters is roughly 225 tokens, under
half the size the retrieval literature converges on, so documents are cut into
more pieces than they need to be and every cut is a chance to sever context.

## 2. What the research says

Summarised from the second research pass. These findings decided the shape of
the design, so they are recorded here rather than left in a commit message.

| Technique | Reported effect | Verdict |
|---|---|---|
| Semantic chunking (embed sentences, cut on similarity drop) | Benchmarked *worse* than recursive splitting, roughly 54% against 69%, producing fragments as small as 43 tokens, at about 14x the indexing cost | Rejected |
| Late chunking (embed the whole document, pool per chunk) | About 3% on long documents | Rejected for now |
| Structure-aware chunking (cut on the document's own logical boundaries) | The largest single effect measured: 87% against 13% in a clinical-document study | **Adopted** |
| Contextual retrieval (an LLM writes a context sentence per chunk) | 5-15% | Adopted in deterministic form only |
| Cross-encoder reranking | Meaningful, but needs PyTorch | Still out of scope |

The headline is that the goal was right and the mechanism was wrong. Chunks
should be decided by meaning rather than by a character count, but the reliable
way to find meaning is to read the structure the author already wrote, not to
ask an embedding model to guess where the topic changed.

Contextual retrieval is the one technique adopted with a substitution. The
published method spends one competent LLM call per chunk at index time. The
largest model available locally is 1.5B parameters, which would be slow and
would write weak context. The deterministic substitute is a **heading
breadcrumb**: the document title and the enclosing heading path, prepended to
every chunk. It captures most of what the written sentence captures, namely
where this passage sits in the document, at no inference cost and with no chance
of hallucination.

## 3. Goals

- Chunk boundaries follow the document's structure: headings, tables, fenced
  code, lists, paragraphs.
- Never split a markdown table that fits within the size ceiling, and repeat the
  header row on every part of one that does not.
- Every chunk carries its document title and heading path, in the text that is
  embedded, in the text that is keyword-indexed, and in the text sent to the
  model.
- Overlap, where it still applies, snaps to a word boundary.
- Size and overlap remain admin-editable, but become a ceiling rather than a
  target.
- Text with no structure at all still chunks correctly, degrading to today's
  behaviour with the word-boundary fix.

## 4. Non-goals

- **Semantic chunking.** Benchmarked worse than what is already here, at 14x the
  cost. Not deferred, rejected.
- **Late chunking.** `nomic-embed-text` has the 8192-token context to support
  it, so it stays technically open, but a 3% gain does not justify replacing the
  embedding call path.
- **LLM-written chunk context.** Blocked by local model size, as above. The
  breadcrumb is the substitute, not a first step toward it.
- **Cross-encoder reranking.** Unchanged from the phase 1 spec.
- **Website crawling.** Still deferred, still reserved in the schema.
- **Per-collection chunking profiles.** One global profile until there is
  evidence that two collections in the same install need different ones.
  Structure-driven boundaries remove most of the reason to want them.

## 5. Decisions taken

| Decision | Choice | Why |
|---|---|---|
| Boundary authority | Structure first, size as a ceiling | The one technique the research supports strongly |
| Chunk context | Document title plus heading path, prepended | Deterministic stand-in for contextual retrieval |
| Breadcrumb storage | Inside `content`, mirrored in a `heading_path` column | The embedding, the keyword index and the model prompt all need it in the text; the column exists so the UI can show it without parsing the body |
| Overlap between structural blocks | None | The boundary is meaningful, so carrying a tail across it only duplicates text |
| Overlap within an oversized block | Kept, snapped forward to a word boundary | Long unbroken prose still needs it |
| Default size | 1800 characters, up from 900 | About 450 tokens, near the size the literature converges on |
| Default overlap | 200 characters, up from 150 | Proportionate to the new size |
| Minimum chunk size | None | A short section is a legitimately short chunk, and the breadcrumb keeps it retrievable |
| Existing stored settings | Rewritten only when still equal to the old default | An administrator who chose 900 deliberately keeps 900 |
| Context assembly | Capped by a character budget | Larger chunks would otherwise overrun a small local model's context window |

## 6. The chunker

`api-engine/kb/chunking.py` is rewritten. The module gains a return type and
loses `chunk_text`.

```python
@dataclass(frozen=True)
class Chunk:
    text: str           # breadcrumb line, blank line, then the body
    heading_path: str   # "Refund policy > Warranty", or "" when there is none


def chunk_document(text: str, *, title: str = "", size: int = 1800,
                   overlap: int = 200) -> list[Chunk]:
```

`chunk_text` has one caller, `index_source`, so no compatibility shim is kept.

### 6.1 Stage one: parse into blocks

Parsing lives in a new module, `api-engine/kb/blocks.py`, so that reading
structure out of markdown and deciding chunk boundaries stay separable and
separately testable.

Walk the text line by line, emitting typed blocks. Each block records its kind,
its text, and the heading stack in force when it started.

| Kind | Recognised by | Ends at |
|---|---|---|
| heading | a line matching one to six hashes followed by whitespace | the same line |
| fence | a line of three or more backticks or tildes | the matching closing fence, or end of text |
| table | two or more consecutive lines starting with a pipe, the second consisting only of pipes, dashes, colons and spaces | the first line not starting with a pipe |
| list | a line matching an optional indent, then a dash, asterisk, plus, or a number with a dot or bracket, then whitespace | a blank line, or a line that is none of the above |
| paragraph | anything else | a blank line, or the start of another block kind |

A heading does not become a block of its own. It updates the heading stack:
level *n* replaces the entry at depth *n* and discards everything deeper. The
stack, joined with the title, is the heading path for every block that follows
until the next heading.

Fences are checked before tables and lists, so a code sample containing pipes or
bullets is never re-interpreted. The table rule requires the separator row
specifically, so a paragraph that happens to contain a pipe character is not
mistaken for one.

### 6.2 Stage two: pack blocks into chunks

Accumulate blocks greedily. Start a new chunk when either condition holds:

1. the next block's heading path differs from the current chunk's, or
2. adding the next block would push the chunk past `size`.

Blocks are joined with a blank line. Condition 1 is what makes the boundary
structural: a new section always starts a new chunk, even when the previous one
had room to spare.

### 6.3 Stage three: oversized blocks

A single block larger than `size` is split, by kind.

**Table.** Split on row boundaries. Every part after the first is prefixed with
the original header row and its separator row, so each part is a valid table
that can be read on its own. A single row wider than `size` is emitted whole
rather than mangled.

**Fence.** Split on line boundaries. Every part is closed and the next reopened
with the same fence marker and info string.

**Everything else.** Recursive split on a blank line, then a newline, then a
sentence end, then a space, then a hard character cut as the last resort. This
is today's `_split`, unchanged, and it is the only place overlap still applies.

### 6.4 Word-boundary overlap

```python
def _overlap_tail(text: str, overlap: int) -> str:
    """The last `overlap` characters, advanced past any partial leading word."""
    if overlap <= 0 or not text:
        return ""
    tail = text[-overlap:]
    match = re.search(r"\s", tail)
    if match is None:
        return tail      # one unbroken token: a clean boundary does not exist
    return tail[match.end():]
```

Snapping forward rather than backward guarantees the tail never opens on a word
fragment. It can return an empty string when the only whitespace is the final
character, which simply means no overlap at that boundary.

### 6.5 The breadcrumb

The heading path is the title and the heading stack joined by a space, an angle
bracket and a space, omitting empty parts. A part equal to the one before it is
dropped, so a document whose top heading repeats its title does not say the name
twice. It is rendered into the chunk text as
a first line followed by a blank line:

```
Section: Refund policy > International orders > Warranty

Returns are accepted within thirty days of delivery...
```

`Section:` is a plain word rather than a markdown heading or a bracketed marker,
because the assembled context already uses square-bracketed numbers for
citations and the two must not be confused.

When there is neither a title nor any heading, no line is added and
`heading_path` is the empty string. That is the degradation path for pasted text
and for PDFs where markitdown detected no headings.

### 6.6 What does not change

Question and answer sources still bypass chunking entirely and become one chunk
each, as decided in phase 1. Splitting them would return half an answer.

## 7. Data model

One migration, `add_heading_path_to_kb_chunks`.

| Column | Type | Notes |
|---|---|---|
| heading_path | string(500) nullable | added to `kb_chunks` after `char_count` |

Invariant: when `heading_path` is non-empty, `content` begins with the word
`Section:`, that path, and a blank line. The column is not the source of truth
for retrieval, `content` is. It exists so the playground and the citation list
can show the section without parsing the body.

The engine's `KbChunk` model gains the column. `VectorStore.upsert` accepts it,
`Hit` carries it, and both drivers select it. `RetrievedChunk` carries it
through to the API response.

No re-embed is forced by the migration itself; existing rows simply hold null.
Getting the benefit requires a re-index, which the phase 2 tooling already does.

### 7.1 Settings

`AppSetting::DEFAULTS` in Laravel and `SETTING_DEFAULTS` in the engine both
change and must stay identical:

| Key | Old | New |
|---|---|---|
| chunk_size | 900 | 1800 |
| chunk_overlap | 150 | 200 |
| context_char_budget | absent | 6000 |

The migration rewrites a stored `chunk_size` row only if its value is still
exactly `900`, and a stored `chunk_overlap` row only if it is still exactly
`150`. Any other value was chosen by an administrator and is left alone.

Validation bounds in `AdminSettingsController` widen to match: `chunk_size`
between 400 and 8000, `chunk_overlap` between 0 and less than `chunk_size`,
`context_char_budget` between 1000 and 20000.

## 8. Context budget

Raising the chunk size raises the size of the assembled prompt. Five chunks at
1800 characters is 9000 characters, roughly 2250 tokens, which is enough to
crowd out the conversation on the 1B-parameter models this install runs.

`kb/retrieval.py` gains one function:

```python
def fit_to_budget(chunks: list[RetrievedChunk], budget: int) -> list[RetrievedChunk]:
```

Chunks are kept in fused-rank order until the next one would exceed `budget`,
then the rest are dropped. The highest-ranked chunk is always kept, even when it
alone exceeds the budget, because returning nothing is worse than returning one
long passage. Ranking already put the best passages first, so the cut falls on
the least useful material.

The trim lives here rather than inside `build_context_block` because the chat
route feeds the same list to two places: the prompt and the `sources` event the
widget renders as citations. Trimming once, before both, is what stops the
widget citing a passage the model never saw.

This is not a retrieval change. `top_k` still decides how many passages are
retrieved and shown in the playground; the budget only decides how many reach
the model and the visitor.

## 9. Admin surfaces

**Admin Settings**, super admin only, unchanged in scope. The chunking fieldset
gains the context budget field and new help text explaining that size is a
ceiling: structure decides where a chunk ends, and size only stops a section
growing without limit.

**Retrieval playground.** Each result gains a section chip above the passage,
rendered from `heading_path`, and the breadcrumb line is stripped from the
displayed body so it is not shown twice.

**Widget citations.** Unchanged. The source title is what a visitor needs; the
heading path is an operator's diagnostic.

## 10. Engine API

No new routes. Results from the search endpoint gain a `heading_path` field.
Existing consumers that ignore unknown fields are unaffected.

## 11. Testing

### 11.1 Chunker unit tests

Rewrite `api-engine/tests/test_chunking.py` against `chunk_document`.

- A heading starts a new chunk even when the previous chunk has room to spare.
- Nested headings render as a full path, with deeper levels discarded correctly
  when the level goes back up.
- The document title leads the path when given, and is omitted when not.
- Every chunk's text begins with its breadcrumb line when a path exists.
- A markdown table that fits is never split.
- An oversized table splits on rows, and every part after the first repeats the
  header and separator rows.
- A fenced block that fits is never split; one that does not is closed and
  reopened with the same marker.
- A paragraph containing a pipe character is not treated as a table.
- Overlap never opens a chunk on a partial word.
- Text with no headings still chunks, and no chunk exceeds the ceiling.
- A single token longer than `size` is emitted rather than dropped, and does not
  loop.
- An overlap greater than or equal to `size` still raises `ValueError`.
- Empty and whitespace-only input returns an empty list.

### 11.2 Regression tests for the two reproduced defects

A fixture document under `api-engine/tests/fixtures/` containing a shipping
paragraph long enough to force a split, and a warranty section holding a table.
Two assertions:

- No chunk begins with a word fragment. Concretely, the first token of every
  chunk body appears as a whole word in the source.
- The chunk containing the warranty table has `Warranty` in its `heading_path`.

Both fail against the current implementation, which is the point of writing them
first.

### 11.3 Integration

- `index_source` stores `heading_path` on every chunk it writes, and passes the
  source title through as the breadcrumb root.
- A question and answer source still produces exactly one chunk, with an empty
  heading path.
- Both vector stores round-trip `heading_path` through `upsert` and through both
  search methods.
- `fit_to_budget` stops at the budget, keeps the highest-ranked chunks, and
  always keeps the first one even when it alone exceeds the budget.

### 11.4 Laravel

- The migration adds the column and existing chunk rows survive it.
- A stored `chunk_size` of 900 is rewritten to 1800; a stored 1200 is not.
- The settings form accepts 400 and 8000 and rejects 399 and 8001.
- The context budget validates its range and persists.
- The playground renders the section chip when a heading path comes back, and
  omits it when it does not.

Both suites must stay green. The baseline before this work starts is 60 Python
tests and 45 Laravel tests, all passing.

## 12. Risks

**markitdown heading fidelity.** A scanned or badly authored PDF may yield no
headings at all, leaving every chunk with only the document title as its
breadcrumb. That is still better than nothing, and it is the same degradation
path as pasted text. Accepted.

**Table detection misfiring.** Line-based detection could still misread an
ASCII diagram inside an indented block. Requiring the dash separator row makes
this unlikely, and a misread costs a slightly odd boundary rather than lost
content. Accepted.

**Stale chunks after deployment.** Existing chunks keep their old boundaries and
have no breadcrumb until someone re-indexes. Mitigated by the phase 2 re-index
tooling and by a notice on the Admin Settings page after the upgrade.

**Larger chunks dilute the vector.** An 1800-character chunk covering two
paragraphs embeds to a less specific point than a 900-character one. This is the
trade the research supports, and the keyword branch plus the breadcrumb both
push back against it. If retrieval quality drops in the playground after
re-indexing, the lever is the size setting, which is exactly why it stays
editable.

**Breadcrumb repetition in the keyword index.** Every chunk in a section now
contains that section's heading, so a keyword query on the heading term matches
all of them. This raises recall and flattens precision within a section. Fusion
with the dense branch is what breaks the resulting ties.

## 13. Phasing

**3a.** Rewrite the chunker. Regression tests first, then the block parser, the
packer, the oversized-block handlers, and word-boundary overlap. Engine only, no
schema change, nothing visible in the UI yet.

**3b.** The `heading_path` column, the model and store plumbing, `index_source`
passing the title, the search response field, and the playground chip.

**3c.** New defaults, the settings migration with its equal-to-old-default
guard, the widened validation bounds, and the context budget.

**3d.** Re-index the existing corpus, verify in the playground that a warranty
query returns the warranty section, and capture screenshots of the changed
pages.
