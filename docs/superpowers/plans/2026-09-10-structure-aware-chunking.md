# Structure-Aware Chunking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make knowledge base chunk boundaries follow a document's own structure, and give every chunk a heading breadcrumb, so retrieval stops returning word fragments and headingless tables.

**Architecture:** A new `kb/blocks.py` parses markdown into typed blocks tagged with the headings above them. A rewritten `kb/chunking.py` groups those blocks by heading path, packs them up to a size ceiling, splits any oversized block by its kind, and prepends a `Section: ...` breadcrumb. A new `heading_path` column carries the path through the stores to the playground, and a context budget stops the larger chunks overrunning a small local model.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy 2 async, pytest, pytest-asyncio. Laravel 13, PHP 8.4, PHPUnit. PostgreSQL 17.5 with pgvector 0.8.0, SQLite fallback.

**Spec:** `docs/superpowers/specs/2026-09-09-structure-aware-chunking-design.md`

## Global Constraints

- Git commits carry no AI attribution. No `Co-Authored-By`, no `Claude-Session`, no "Generated with Claude Code" trailer. Author stays `sufisuhaimi2014 <sufisuhaimi2014@gmail.com>`.
- Never commit `.env` or `admin-laravel/database/database.sqlite`.
- Both suites must stay green. Baseline is **60 Python tests** and **45 Laravel tests**, all passing.
- Python commands run from `api-engine/` with the virtualenv at `api-engine/.venv`. Laravel commands run from `admin-laravel/`.
- Laravel tests run on `sqlite :memory:` per `phpunit.xml`, so any migration written here must work on both SQLite and PostgreSQL.
- `AppSetting::DEFAULTS` in `admin-laravel/app/Models/AppSetting.php` and `SETTING_DEFAULTS` in `api-engine/database.py` must stay byte-for-byte identical in their shared keys.
- No new Python or PHP dependencies. Everything here is standard library plus what is already installed.
- Breadcrumb literals, exact: prefix `Section: `, path separator ` > `.
- New default values, exact: `chunk_size` `1800`, `chunk_overlap` `200`, `context_char_budget` `6000`.
- Validation bounds, exact: `chunk_size` 400 to 8000, `chunk_overlap` 0 to less than `chunk_size`, `context_char_budget` 1000 to 20000.

## File Structure

**Created**

| File | Responsibility |
|---|---|
| `api-engine/kb/blocks.py` | Markdown text to typed blocks, each tagged with its heading stack. Knows nothing about chunk sizes. |
| `api-engine/tests/test_blocks.py` | Block parser tests. |
| `api-engine/tests/fixtures/policy.md` | A realistic document holding the two reproduced defects. |
| `api-engine/tests/test_chunking_regressions.py` | The two defect regressions, asserted against the fixture. |
| `admin-laravel/database/migrations/2026_09_10_000004_add_heading_path_to_kb_chunks.php` | Adds the `heading_path` column. |
| `admin-laravel/database/migrations/2026_09_10_000005_update_chunking_defaults.php` | Rewrites untouched chunking settings to the new defaults. |

**Modified**

| File | Change |
|---|---|
| `api-engine/kb/chunking.py` | Rewritten: `Chunk`, `chunk_document`, packing, oversized-block splitting, word-boundary overlap. |
| `api-engine/tests/test_chunking.py` | Rewritten against `chunk_document`. |
| `api-engine/database.py` | `KbChunk.heading_path`; `SETTING_DEFAULTS` values. |
| `api-engine/kb/store.py` | `Hit.heading_path`; both drivers read and write the column. |
| `api-engine/kb/indexer.py` | Calls `chunk_document`, passes the source title, stores `heading_path`. |
| `api-engine/kb/retrieval.py` | `RetrievedChunk.heading_path`; new `fit_to_budget`. |
| `api-engine/routers/kb.py` | Search response gains `heading_path`. |
| `api-engine/routers/chat.py` | Trims retrieval to the context budget before prompt and citations. |
| `api-engine/tests/test_indexer.py` | Asserts the title and heading path reach the stored chunk. |
| `api-engine/tests/test_store.py` | Asserts `heading_path` round-trips. |
| `api-engine/tests/test_retrieval.py` | Covers `fit_to_budget`. |
| `admin-laravel/app/Models/AppSetting.php` | New defaults, new key. |
| `admin-laravel/app/Http/Controllers/AdminSettingsController.php` | New key, widened bounds. |
| `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php` | Strips the breadcrumb line from displayed passages. |
| `admin-laravel/resources/views/admin/settings.blade.php` | Context budget field, new help text. |
| `admin-laravel/resources/views/kb/playground.blade.php` | Section chip above each passage. |
| `admin-laravel/tests/Feature/AdminSettingsTest.php` | New key and bounds. |
| `admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php` | Column and default assertions. |
| `admin-laravel/tests/Feature/RetrievalPlaygroundTest.php` | Section chip rendering. |

---

### Task 1: The block parser

Parse markdown into typed blocks. Nothing in this task knows what a chunk is.

**Files:**
- Create: `api-engine/kb/blocks.py`
- Test: `api-engine/tests/test_blocks.py`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Block` — a frozen dataclass with `kind: str` (one of `"paragraph"`, `"list"`, `"table"`, `"fence"`), `text: str`, `headings: tuple[str, ...]` (outermost first, innermost last).
  - `parse_blocks(text: str) -> list[Block]`

- [ ] **Step 1: Write the failing tests**

Create `api-engine/tests/test_blocks.py`:

```python
from kb.blocks import Block, parse_blocks


def test_a_plain_paragraph_is_one_block_with_no_headings():
    blocks = parse_blocks("Refunds take thirty days.")
    assert blocks == [Block("paragraph", "Refunds take thirty days.", ())]


def test_blank_lines_separate_paragraphs():
    blocks = parse_blocks("One.\n\nTwo.")
    assert [b.text for b in blocks] == ["One.", "Two."]


def test_a_heading_is_not_a_block_but_tags_what_follows():
    blocks = parse_blocks("## Warranty\n\nTwo years.")
    assert len(blocks) == 1
    assert blocks[0].text == "Two years."
    assert blocks[0].headings == ("Warranty",)


def test_nested_headings_accumulate_outermost_first():
    blocks = parse_blocks("# Shipping\n\n## International\n\nAllow ten days.")
    assert blocks[0].headings == ("Shipping", "International")


def test_going_back_up_a_level_discards_the_deeper_heading():
    text = "# A\n\n## B\n\nunder b\n\n# C\n\nunder c"
    blocks = parse_blocks(text)
    assert blocks[0].headings == ("A", "B")
    assert blocks[1].headings == ("C",)


def test_a_heading_that_skips_a_level_still_records_what_it_has():
    blocks = parse_blocks("# A\n\n### C\n\nbody")
    assert blocks[0].headings == ("A", "C")


def test_a_table_is_one_block():
    text = "| Item | Days |\n|---|---|\n| Refund | 30 |\n| Warranty | 730 |"
    blocks = parse_blocks(text)
    assert len(blocks) == 1
    assert blocks[0].kind == "table"
    assert blocks[0].text == text


def test_a_paragraph_containing_a_pipe_is_not_a_table():
    blocks = parse_blocks("Press Ctrl | Alt to continue.")
    assert blocks[0].kind == "paragraph"


def test_pipe_lines_without_a_separator_row_are_not_a_table():
    blocks = parse_blocks("| not really\n| a table")
    assert blocks[0].kind == "paragraph"


def test_a_fenced_block_is_one_block_even_with_blank_lines_inside():
    text = "```python\nx = 1\n\ny = 2\n```"
    blocks = parse_blocks(text)
    assert len(blocks) == 1
    assert blocks[0].kind == "fence"
    assert blocks[0].text == text


def test_a_fence_containing_pipes_and_bullets_is_still_a_fence():
    text = "```\n| a | b |\n- item\n```"
    blocks = parse_blocks(text)
    assert len(blocks) == 1
    assert blocks[0].kind == "fence"


def test_an_unclosed_fence_runs_to_the_end():
    blocks = parse_blocks("```\nx = 1\ny = 2")
    assert len(blocks) == 1
    assert blocks[0].kind == "fence"


def test_a_bulleted_run_is_one_list_block():
    text = "- one\n- two\n- three"
    blocks = parse_blocks(text)
    assert len(blocks) == 1
    assert blocks[0].kind == "list"
    assert blocks[0].text == text


def test_a_numbered_run_is_a_list():
    assert parse_blocks("1. one\n2. two")[0].kind == "list"


def test_empty_input_yields_no_blocks():
    assert parse_blocks("") == []
    assert parse_blocks("   \n\n  ") == []


def test_windows_line_endings_are_normalised():
    blocks = parse_blocks("## H\r\n\r\nbody")
    assert blocks[0].headings == ("H",)
    assert blocks[0].text == "body"
```

- [ ] **Step 2: Run the tests to verify they fail**

Run from `api-engine/`:

```bash
.venv/Scripts/python -m pytest tests/test_blocks.py -v
```

Expected: collection error, `ModuleNotFoundError: No module named 'kb.blocks'`.

- [ ] **Step 3: Write the parser**

Create `api-engine/kb/blocks.py`:

```python
"""Read markdown into typed blocks, each tagged with the headings above it.

Chunk boundaries follow the document's own structure, so the first job is to
recover that structure from the text. Everything downstream works on blocks and
never on raw lines.
"""
import re
from dataclasses import dataclass

HEADING_RE = re.compile(r"^(#{1,6})\s+(.*)$")
FENCE_RE = re.compile(r"^\s*(`{3,}|~{3,})")
LIST_RE = re.compile(r"^\s*([-*+]|\d+[.)])\s+")
TABLE_SEPARATOR_RE = re.compile(r"^\|?[\s:|-]+\|?\s*$")


@dataclass(frozen=True)
class Block:
    kind: str                     # "paragraph" | "list" | "table" | "fence"
    text: str
    headings: tuple[str, ...]     # outermost first, innermost last


def parse_blocks(text: str) -> list[Block]:
    lines = (text or "").replace("\r\n", "\n").replace("\r", "\n").split("\n")
    blocks: list[Block] = []
    stack: list[str] = []          # index i holds the title at heading level i+1
    i = 0

    while i < len(lines):
        line = lines[i]
        if not line.strip():
            i += 1
            continue

        heading = HEADING_RE.match(line)
        if heading:
            level = len(heading.group(1))
            # A heading closes every deeper one and replaces its own level.
            del stack[level - 1:]
            while len(stack) < level - 1:
                stack.append("")
            stack.append(heading.group(2).strip())
            i += 1
            continue

        fence = FENCE_RE.match(line)
        if fence:
            marker = fence.group(1)
            body = [line]
            i += 1
            while i < len(lines):
                body.append(lines[i])
                closed = lines[i].strip().startswith(marker[:3])
                i += 1
                if closed:
                    break
            blocks.append(Block("fence", "\n".join(body), _path(stack)))
            continue

        # A table needs its separator row. Without it, pipes are just text.
        if line.lstrip().startswith("|") and i + 1 < len(lines) \
                and "-" in lines[i + 1] and TABLE_SEPARATOR_RE.match(lines[i + 1].strip()):
            body = []
            while i < len(lines) and lines[i].lstrip().startswith("|"):
                body.append(lines[i])
                i += 1
            blocks.append(Block("table", "\n".join(body), _path(stack)))
            continue

        kind = "list" if LIST_RE.match(line) else "paragraph"
        body = []
        while i < len(lines) and lines[i].strip():
            if HEADING_RE.match(lines[i]) or FENCE_RE.match(lines[i]):
                break
            body.append(lines[i])
            i += 1
        blocks.append(Block(kind, "\n".join(body), _path(stack)))

    return blocks


def _path(stack: list[str]) -> tuple[str, ...]:
    """The heading stack with skipped levels dropped."""
    return tuple(s for s in stack if s)
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
.venv/Scripts/python -m pytest tests/test_blocks.py -v
```

Expected: 16 passed.

- [ ] **Step 5: Run the whole Python suite**

```bash
.venv/Scripts/python -m pytest -q
```

Expected: 76 passed. Nothing else imports `kb.blocks` yet, so the existing 60 are untouched.

- [ ] **Step 6: Commit**

```bash
git add api-engine/kb/blocks.py api-engine/tests/test_blocks.py
git commit -m "feat: parse markdown into typed blocks"
```

---

### Task 2: Group blocks into chunks with a breadcrumb

Rewrite the chunker around blocks. Oversized blocks are left whole in this task; Task 3 splits them.

**Files:**
- Modify: `api-engine/kb/chunking.py` (full rewrite)
- Modify: `api-engine/tests/test_chunking.py` (full rewrite)

**Interfaces:**
- Consumes: `Block`, `parse_blocks` from Task 1.
- Produces:
  - `Chunk` — a frozen dataclass with `text: str` and `heading_path: str`.
  - `chunk_document(text: str, *, title: str = "", size: int = 1800, overlap: int = 200) -> list[Chunk]`
  - `BREADCRUMB_PREFIX = "Section: "` and `PATH_SEPARATOR = " > "`.
  - A private `_split_block(block: Block, budget: int, overlap: int) -> list[str]` that Task 3 fills in.

- [ ] **Step 1: Write the failing tests**

Replace the entire contents of `api-engine/tests/test_chunking.py`:

```python
import pytest

from kb.chunking import Chunk, chunk_document


def test_empty_text_yields_nothing():
    assert chunk_document("") == []
    assert chunk_document("   \n  ") == []


def test_short_text_is_one_chunk_with_no_breadcrumb():
    assert chunk_document("hello world") == [Chunk(text="hello world", heading_path="")]


def test_overlap_must_be_smaller_than_size():
    with pytest.raises(ValueError):
        chunk_document("anything", size=100, overlap=100)


def test_a_new_heading_starts_a_new_chunk_even_with_room_to_spare():
    text = "## One\n\nalpha\n\n## Two\n\nbeta"
    chunks = chunk_document(text, size=1800)
    assert len(chunks) == 2
    assert chunks[0].heading_path == "One"
    assert chunks[1].heading_path == "Two"


def test_paragraphs_under_one_heading_are_packed_together():
    text = "## One\n\nalpha\n\nbravo\n\ncharlie"
    chunks = chunk_document(text, size=1800)
    assert len(chunks) == 1
    assert "alpha" in chunks[0].text
    assert "charlie" in chunks[0].text


def test_the_breadcrumb_leads_the_chunk_text():
    chunks = chunk_document("## Warranty\n\nTwo years.", title="Policy")
    assert chunks[0].text.startswith("Section: Policy > Warranty\n\n")
    assert chunks[0].text.endswith("Two years.")


def test_the_heading_path_matches_the_breadcrumb():
    chunks = chunk_document("# A\n\n## B\n\nbody", title="Doc")
    assert chunks[0].heading_path == "Doc > A > B"


def test_a_heading_that_repeats_the_title_is_not_said_twice():
    chunks = chunk_document("# Policy\n\n## Warranty\n\nbody", title="Policy")
    assert chunks[0].heading_path == "Policy > Warranty"


def test_the_title_alone_is_a_breadcrumb():
    chunks = chunk_document("no headings here", title="Doc")
    assert chunks[0].heading_path == "Doc"
    assert chunks[0].text.startswith("Section: Doc\n\n")


def test_no_title_and_no_heading_means_no_breadcrumb_line():
    chunks = chunk_document("no headings here")
    assert chunks[0].heading_path == ""
    assert chunks[0].text == "no headings here"


def test_packing_breaks_when_the_size_ceiling_is_reached():
    text = "## One\n\n" + "\n\n".join("x" * 200 for _ in range(6))
    chunks = chunk_document(text, size=600, overlap=0)
    assert len(chunks) > 1
    for chunk in chunks:
        assert len(chunk.text) <= 600


def test_the_breadcrumb_counts_against_the_ceiling():
    text = "## " + "H" * 100 + "\n\n" + "\n\n".join("x" * 100 for _ in range(8))
    for chunk in chunk_document(text, size=400, overlap=0):
        assert len(chunk.text) <= 400


def test_a_table_that_fits_is_never_split():
    table = "| Item | Days |\n|---|---|\n| Refund | 30 |\n| Warranty | 730 |"
    chunks = chunk_document("## Terms\n\n" + table, size=1800)
    assert len(chunks) == 1
    assert chunks[0].text.count("| Refund | 30 |") == 1
    assert "| Warranty | 730 |" in chunks[0].text


def test_a_fence_that_fits_is_never_split():
    fence = "```python\nx = 1\n\ny = 2\n```"
    chunks = chunk_document("## Code\n\n" + fence, size=1800)
    assert len(chunks) == 1
    assert chunks[0].text.count("```") == 2


def test_no_empty_chunks():
    chunks = chunk_document("## A\n\n\n\n## B\n\nbody", size=200)
    assert all(c.text.strip() for c in chunks)
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
.venv/Scripts/python -m pytest tests/test_chunking.py -v
```

Expected: `ImportError: cannot import name 'Chunk' from 'kb.chunking'`.

- [ ] **Step 3: Rewrite the chunker**

Replace the entire contents of `api-engine/kb/chunking.py`:

```python
"""Group blocks into retrieval-sized chunks.

Structure decides where a chunk ends; size only stops one growing without limit.
Every chunk carries the document title and the headings above it, so a passage
still says where it came from once it is on its own.
"""
from dataclasses import dataclass

from kb.blocks import Block, parse_blocks

BREADCRUMB_PREFIX = "Section: "
PATH_SEPARATOR = " > "

# A deep heading path must never squeeze the body down to nothing.
MIN_BODY_BUDGET = 200


@dataclass(frozen=True)
class Chunk:
    text: str            # breadcrumb line, blank line, then the body
    heading_path: str    # "Policy > Warranty", or "" when there is none


def chunk_document(text: str, *, title: str = "", size: int = 1800,
                   overlap: int = 200) -> list[Chunk]:
    if overlap >= size:
        raise ValueError("overlap must be smaller than size")
    if not text or not text.strip():
        return []

    out: list[Chunk] = []
    for headings, blocks in _group_by_heading(parse_blocks(text)):
        path = _path_text(headings, title)
        breadcrumb = f"{BREADCRUMB_PREFIX}{path}\n\n" if path else ""
        budget = max(MIN_BODY_BUDGET, size - len(breadcrumb))
        for body in _pack(blocks, budget, overlap):
            out.append(Chunk(text=breadcrumb + body, heading_path=path))

    return [c for c in out if c.text.strip()]


def _path_text(headings: tuple[str, ...], title: str) -> str:
    parts: list[str] = []
    for candidate in (title, *headings):
        candidate = (candidate or "").strip()
        # A document whose top heading repeats its title must not say so twice.
        if candidate and (not parts or parts[-1] != candidate):
            parts.append(candidate)
    return PATH_SEPARATOR.join(parts)


def _group_by_heading(blocks: list[Block]):
    """Runs of consecutive blocks that share a heading path."""
    run: list[Block] = []
    current: tuple[str, ...] | None = None
    for block in blocks:
        if current is not None and block.headings != current:
            yield current, run
            run = []
        current = block.headings
        run.append(block)
    if run and current is not None:
        yield current, run


def _pack(blocks: list[Block], budget: int, overlap: int) -> list[str]:
    """Fill up to budget, then break. Blocks are joined by a blank line."""
    bodies: list[str] = []
    buffer = ""
    for block in blocks:
        for piece in _split_block(block, budget, overlap):
            candidate = f"{buffer}\n\n{piece}" if buffer else piece
            if buffer and len(candidate) > budget:
                bodies.append(buffer)
                buffer = piece
            else:
                buffer = candidate
    if buffer.strip():
        bodies.append(buffer)
    return bodies


def _split_block(block: Block, budget: int, overlap: int) -> list[str]:
    """Break one oversized block. Filled in by the oversized-block task."""
    return [block.text]
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
.venv/Scripts/python -m pytest tests/test_chunking.py -v
```

Expected: 15 passed.

`test_packing_breaks_when_the_size_ceiling_is_reached` and
`test_the_breadcrumb_counts_against_the_ceiling` both pass here because every
individual block is smaller than the budget, so the packer alone enforces the
ceiling. Task 3 is what keeps them passing once a single block is too big.

- [ ] **Step 5: Run the whole Python suite**

```bash
.venv/Scripts/python -m pytest -q
```

Expected: failures in `tests/test_indexer.py`, because `index_source` still calls the removed `chunk_text`. That is expected and Task 5 fixes it. Note the failure count so you can confirm it does not grow.

- [ ] **Step 6: Commit**

```bash
git add api-engine/kb/chunking.py api-engine/tests/test_chunking.py
git commit -m "feat: group blocks into chunks with a heading breadcrumb"
```

---

### Task 3: Split oversized blocks

Tables split on rows and repeat their header. Fences close and reopen. Prose falls back to the recursive splitter, with overlap snapped to a word boundary.

**Files:**
- Modify: `api-engine/kb/chunking.py`
- Modify: `api-engine/tests/test_chunking.py` (append)

**Interfaces:**
- Consumes: `Block` from Task 1, `_split_block` from Task 2.
- Produces: `_split_block` fully implemented. No public signature changes.

- [ ] **Step 1: Write the failing tests**

Append to `api-engine/tests/test_chunking.py`:

```python
def test_an_oversized_table_splits_on_rows_and_repeats_the_header():
    rows = "\n".join(f"| Item {n} | {n * 10} |" for n in range(40))
    table = "| Item | Days |\n|---|---|\n" + rows
    chunks = chunk_document("## Terms\n\n" + table, size=400, overlap=0)

    assert len(chunks) > 1
    for chunk in chunks:
        assert "| Item | Days |" in chunk.text
        assert "|---|---|" in chunk.text
    # No row is lost and none is duplicated.
    emitted = sum(chunk.text.count("| Item 7 |") for chunk in chunks)
    assert emitted == 1


def test_a_table_row_wider_than_the_budget_is_emitted_whole():
    wide = "| " + "x" * 500 + " | y |"
    table = "| A | B |\n|---|---|\n" + wide
    chunks = chunk_document(table, size=300, overlap=0)
    assert any("x" * 500 in chunk.text for chunk in chunks)


def test_an_oversized_fence_is_closed_and_reopened():
    body = "\n".join(f"line_{n} = {n}" for n in range(60))
    chunks = chunk_document("```python\n" + body + "\n```", size=400, overlap=0)

    assert len(chunks) > 1
    for chunk in chunks:
        assert chunk.text.count("```") == 2
        assert "```python" in chunk.text


def test_overlap_never_opens_a_chunk_on_a_partial_word():
    text = "shipping is available to most countries " * 60
    chunks = chunk_document(text, size=400, overlap=100)

    assert len(chunks) > 1
    for chunk in chunks[1:]:
        first_word = chunk.text.split()[0]
        assert f" {first_word} " in f" {text} "


def test_overlap_repeats_material_from_the_previous_chunk():
    text = "alpha bravo charlie delta echo foxtrot golf hotel " * 30
    chunks = chunk_document(text, size=400, overlap=100)
    assert len(chunks) > 1
    assert any(word in chunks[0].text for word in chunks[1].text.split()[:3])


def test_a_single_token_longer_than_size_is_still_emitted():
    chunks = chunk_document("z" * 1000, size=300, overlap=50)
    assert "".join(c.text for c in chunks).count("z") >= 1000


def test_prose_with_no_headings_never_exceeds_the_ceiling():
    text = ("word " * 4000).strip()
    for chunk in chunk_document(text, size=300, overlap=50):
        assert len(chunk.text) <= 300
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
.venv/Scripts/python -m pytest tests/test_chunking.py -v
```

Expected: the seven new tests fail. `_split_block` returns the block whole, so tables and fences come back in one piece and prose exceeds the ceiling.

- [ ] **Step 3: Implement the splitters**

In `api-engine/kb/chunking.py`, add `import re` at the top, add the separator list beside the other constants, and replace `_split_block` with the following:

```python
PROSE_SEPARATORS = ["\n\n", "\n", ". ", " "]
```

```python
def _split_block(block: Block, budget: int, overlap: int) -> list[str]:
    if len(block.text) <= budget:
        return [block.text]
    if block.kind == "table":
        return _split_table(block.text, budget)
    if block.kind == "fence":
        return _split_fence(block.text, budget)
    return _split_prose(block.text, budget, overlap)


def _split_table(table: str, budget: int) -> list[str]:
    """Split on rows. Every part repeats the header so it reads on its own."""
    rows = table.split("\n")
    if len(rows) < 3:
        return [table]

    header = rows[:2]
    parts: list[str] = []
    buffer: list[str] = []
    for row in rows[2:]:
        candidate = "\n".join([*header, *buffer, row])
        if buffer and len(candidate) > budget:
            parts.append("\n".join([*header, *buffer]))
            buffer = [row]
        else:
            buffer.append(row)
    if buffer:
        parts.append("\n".join([*header, *buffer]))
    return parts


def _split_fence(fence: str, budget: int) -> list[str]:
    """Split on lines, closing and reopening with the same marker."""
    lines = fence.split("\n")
    opener = lines[0]
    marker = opener.strip()[:3]
    body = lines[1:]
    if body and body[-1].strip().startswith(marker):
        body = body[:-1]

    parts: list[str] = []
    buffer: list[str] = []
    for line in body:
        candidate = "\n".join([opener, *buffer, line, marker])
        if buffer and len(candidate) > budget:
            parts.append("\n".join([opener, *buffer, marker]))
            buffer = [line]
        else:
            buffer.append(line)
    if buffer:
        parts.append("\n".join([opener, *buffer, marker]))
    return parts or [fence]


def _split_prose(text: str, budget: int, overlap: int) -> list[str]:
    parts: list[str] = []
    buffer = ""
    for piece in _fragments(text, budget):
        candidate = buffer + piece
        if buffer and len(candidate) > budget:
            parts.append(buffer.strip())
            tail = _overlap_tail(parts[-1], overlap)
            if len(tail) + len(piece) > budget:
                tail = ""          # the overlap would not leave room for the text
            buffer = f"{tail} {piece.lstrip()}" if tail else piece
        else:
            buffer = candidate
    if buffer.strip():
        parts.append(buffer.strip())
    return [p for p in parts if p]


def _fragments(text: str, size: int) -> list[str]:
    """Break text on the strongest boundary that keeps every piece under size."""
    if len(text) <= size:
        return [text]
    for sep in PROSE_SEPARATORS:
        if sep not in text:
            continue
        out: list[str] = []
        for i, part in enumerate(text.split(sep)):
            fragment = part if i == 0 else sep + part
            out.extend(_fragments(fragment, size) if len(fragment) > size else [fragment])
        return out
    return [text[i:i + size] for i in range(0, len(text), size)]


def _overlap_tail(text: str, overlap: int) -> str:
    """The last `overlap` characters, advanced past any partial leading word."""
    if overlap <= 0 or not text:
        return ""
    tail = text[-overlap:]
    match = re.search(r"\s", tail)
    if match is None:
        return tail        # one unbroken token: a clean boundary does not exist
    return tail[match.end():]
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
.venv/Scripts/python -m pytest tests/test_chunking.py -v
```

Expected: 22 passed.

- [ ] **Step 5: Commit**

```bash
git add api-engine/kb/chunking.py api-engine/tests/test_chunking.py
git commit -m "feat: split oversized tables, fences and prose on real boundaries"
```

---

### Task 4: Regression tests for the two reproduced defects

Lock in the two failures that motivated the work, against a realistic document.

**Files:**
- Create: `api-engine/tests/fixtures/policy.md`
- Create: `api-engine/tests/test_chunking_regressions.py`

**Interfaces:**
- Consumes: `chunk_document` from Task 2 and its splitters from Task 3.
- Produces: nothing other tasks depend on.

- [ ] **Step 1: Create the fixture**

Create `api-engine/tests/fixtures/policy.md`:

```markdown
# Customer Policy

## Shipping

Shipping is available to most countries. Orders placed before two in the
afternoon leave the warehouse the same working day, and everything else leaves
on the next one. Tracking numbers are issued when the parcel is scanned by the
carrier, which is usually within a few hours of collection. Delivery estimates
shown at checkout are working days and exclude public holidays in the
destination country. Remote addresses, offshore islands and forwarding
addresses can add several days beyond the estimate, and we cannot expedite a
parcel once it has been handed to the carrier. If a parcel has not moved for
seven working days, contact support and we will open an enquiry with the
carrier on your behalf.

## Warranty

| Product line | Warranty | Covers |
|---|---|---|
| Desk lamps | 24 months | Electrical faults, switch failure |
| Floor lamps | 24 months | Electrical faults, switch failure |
| Pendant fittings | 36 months | Electrical faults, finish defects |
| Bulbs | 6 months | Premature failure only |

## Refunds

Returns are accepted within thirty days of delivery.
```

- [ ] **Step 2: Write the failing tests**

Create `api-engine/tests/test_chunking_regressions.py`:

```python
"""The two defects that motivated structure-aware chunking.

Both were reproduced against the character-first splitter. They are pinned here
so a future change to the boundaries cannot bring them back.
"""
import re
from pathlib import Path

import pytest

from kb.chunking import BREADCRUMB_PREFIX, chunk_document

FIXTURE = Path(__file__).parent / "fixtures" / "policy.md"


@pytest.fixture
def document() -> str:
    return FIXTURE.read_text(encoding="utf-8")


def _body(chunk) -> str:
    """The chunk without its breadcrumb line."""
    if chunk.text.startswith(BREADCRUMB_PREFIX):
        return chunk.text.split("\n\n", 1)[1]
    return chunk.text


def test_no_chunk_opens_on_a_word_fragment(document):
    # The old splitter produced "g is available to most countries" by slicing
    # the overlap tail through the middle of "shipping".
    words = set(re.findall(r"[A-Za-z]+", document))
    chunks = chunk_document(document, title="Customer Policy", size=500, overlap=120)

    assert len(chunks) > 1
    for chunk in chunks:
        first = re.findall(r"[A-Za-z]+", _body(chunk))
        assert first, f"chunk has no words: {chunk.text!r}"
        assert first[0] in words, f"chunk opens on a fragment: {first[0]!r}"


def test_the_warranty_table_keeps_its_heading(document):
    # The old splitter emitted the whole table with no mention of "warranty",
    # so neither retrieval branch could match a question about it.
    chunks = chunk_document(document, title="Customer Policy", size=900, overlap=150)
    table_chunks = [c for c in chunks if "Pendant fittings" in c.text]

    assert table_chunks
    for chunk in table_chunks:
        assert "Warranty" in chunk.heading_path
        assert "Warranty" in chunk.text


def test_every_chunk_names_the_document(document):
    for chunk in chunk_document(document, title="Customer Policy", size=500, overlap=120):
        assert chunk.heading_path.startswith("Customer Policy")


def test_the_three_sections_do_not_bleed_into_each_other(document):
    chunks = chunk_document(document, title="Customer Policy", size=1800, overlap=200)
    paths = [c.heading_path for c in chunks]

    assert any(p.endswith("Shipping") for p in paths)
    assert any(p.endswith("Warranty") for p in paths)
    assert any(p.endswith("Refunds") for p in paths)
    for chunk in chunks:
        if chunk.heading_path.endswith("Refunds"):
            assert "Pendant fittings" not in chunk.text
```

- [ ] **Step 3: Run the tests**

```bash
.venv/Scripts/python -m pytest tests/test_chunking_regressions.py -v
```

Expected: 4 passed. These pass against the implementation from Tasks 1 to 3, which is the point: they are the acceptance criteria for that work. If any fails, the defect is still present and Task 3 is not done.

- [ ] **Step 4: Confirm they would have caught the old behaviour**

Run this one-off check from `api-engine/` to see the old splitter fail the same assertion:

```bash
.venv/Scripts/python -c "import pathlib; from kb.chunking import _overlap_tail; t=pathlib.Path('tests/fixtures/policy.md').read_text(encoding='utf-8'); old=t[:520]; print('blind slice ->', repr(old[-120:][:40])); print('snapped     ->', repr(_overlap_tail(old, 120)[:40]))"
```

Expected: the blind slice opens mid-word; the snapped tail opens on a whole word. This is a sanity check, not a test. Nothing is committed from it.

- [ ] **Step 5: Commit**

```bash
git add api-engine/tests/fixtures/policy.md api-engine/tests/test_chunking_regressions.py
git commit -m "test: pin the word-fragment and lost-heading regressions"
```

---

### Task 5: Carry the heading path into storage

Add the column, teach both drivers about it, and switch the indexer onto `chunk_document`.

**Files:**
- Create: `admin-laravel/database/migrations/2026_09_10_000004_add_heading_path_to_kb_chunks.php`
- Modify: `api-engine/database.py` (`KbChunk`, around line 130)
- Modify: `api-engine/kb/store.py` (`Hit`, both drivers)
- Modify: `api-engine/kb/indexer.py` (`index_source`)
- Modify: `api-engine/tests/test_indexer.py`
- Modify: `api-engine/tests/test_store.py`
- Modify: `admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php`

**Interfaces:**
- Consumes: `Chunk`, `chunk_document` from Task 2.
- Produces:
  - `KbChunk.heading_path` — `Column(String(500), nullable=True)`.
  - `Hit(chunk_id, source_id, content, score, heading_path="")` — the new field is last and defaulted, so existing positional construction still works.
  - `store.upsert` accepts `"heading_path"` in each chunk dict.

- [ ] **Step 1: Write the failing tests**

Append to `api-engine/tests/test_store.py`:

```python
@pytest.mark.asyncio
async def test_heading_path_round_trips_through_the_sqlite_store(session):
    store = SqliteVectorStore(session)
    await store.upsert([{
        "collection_id": "col1", "source_id": "s1", "ordinal": 0,
        "content": "Section: Policy > Warranty\n\nTwo years on desk lamps.",
        "char_count": 50, "heading_path": "Policy > Warranty",
        "embedding_model": "test", "embedding": [1.0, 0.0],
    }])

    hits = await store.search_vector(["col1"], [1.0, 0.0], 5, "test")
    assert hits[0].heading_path == "Policy > Warranty"

    hits = await store.search_keyword(["col1"], "warranty", 5)
    assert hits[0].heading_path == "Policy > Warranty"
```

Append to `api-engine/tests/test_indexer.py`:

```python
@pytest.mark.asyncio
async def test_indexing_records_the_heading_path_and_the_title(session):
    session.add(KbSource(id="s9", collection_id="col1", type="text",
                         title="Customer Policy",
                         body="## Warranty\n\nTwo years on desk lamps.",
                         status="pending"))
    await session.commit()

    count = await index_source(session, "s9", embedder=StubEmbedder())
    assert count == 1

    row = (await session.execute(text(
        "SELECT content, heading_path FROM kb_chunks WHERE source_id = 's9'"))).one()
    assert row.heading_path == "Customer Policy > Warranty"
    assert row.content.startswith("Section: Customer Policy > Warranty\n\n")


@pytest.mark.asyncio
async def test_a_qa_source_is_one_chunk_with_no_heading_path(session):
    session.add(KbSource(id="s10", collection_id="col1", type="qa",
                         title="How do I get a refund?", body="Within 30 days.",
                         status="pending"))
    await session.commit()

    count = await index_source(session, "s10", embedder=StubEmbedder())
    assert count == 1

    row = (await session.execute(text(
        "SELECT content, heading_path FROM kb_chunks WHERE source_id = 's10'"))).one()
    assert row.content == "Q: How do I get a refund?\nA: Within 30 days."
    assert (row.heading_path or "") == ""
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
.venv/Scripts/python -m pytest tests/test_store.py tests/test_indexer.py -v
```

Expected: `OperationalError: table kb_chunks has no column named heading_path`, plus the pre-existing `chunk_text` import failure from Task 2.

- [ ] **Step 3: Add the column to the engine model**

In `api-engine/database.py`, inside `class KbChunk`, add the column after `char_count`:

```python
    char_count = Column(Integer, default=0)
    heading_path = Column(String(500), nullable=True)
    embedding_model = Column(String(120), nullable=True)
```

- [ ] **Step 4: Teach both stores about the column**

In `api-engine/kb/store.py`, extend `Hit`:

```python
@dataclass
class Hit:
    chunk_id: int
    source_id: str
    content: str
    score: float
    heading_path: str = ""
```

In `SqliteVectorStore.upsert`, add the column to the insert:

```python
            await self.session.execute(text("""
                INSERT INTO kb_chunks
                    (collection_id, source_id, ordinal, content, char_count,
                     heading_path, embedding_model, embedding)
                VALUES (:collection_id, :source_id, :ordinal, :content, :char_count,
                        :heading_path, :embedding_model, :embedding)
            """), {**c, "embedding": pack(c["embedding"])})
```

In `SqliteVectorStore.search_vector`, select it and pass it through:

```python
        sql = """
            SELECT id, source_id, content, heading_path, embedding FROM kb_chunks
            WHERE collection_id IN :cids AND embedding IS NOT NULL
        """
```

```python
        return [Hit(rows[i].id, rows[i].source_id, rows[i].content, float(scores[i]),
                    rows[i].heading_path or "")
                for i in order]
```

In `SqliteVectorStore.search_keyword`:

```python
        stmt = text("""
            SELECT id, source_id, content, heading_path FROM kb_chunks
            WHERE collection_id IN :cids
        """).bindparams(bindparam("cids", expanding=True))
```

```python
            if score:
                scored.append(Hit(r.id, r.source_id, r.content, float(score),
                                  r.heading_path or ""))
```

In `PgVectorStore.upsert`:

```python
            await self.session.execute(text("""
                INSERT INTO kb_chunks
                    (collection_id, source_id, ordinal, content, char_count,
                     heading_path, embedding_model, embedding)
                VALUES (:collection_id, :source_id, :ordinal, :content, :char_count,
                        :heading_path, :embedding_model, CAST(:embedding AS vector))
            """), {**c, "embedding": "[" + ",".join(str(x) for x in c["embedding"]) + "]"})
```

In `PgVectorStore.search_vector`:

```python
        rows = (await self.session.execute(text(f"""
            SELECT id, source_id, content, heading_path,
                   1 - (embedding <=> CAST(:q AS vector)) AS score
            FROM kb_chunks
            WHERE collection_id = ANY(:cids) AND embedding IS NOT NULL{model_clause}
            ORDER BY embedding <=> CAST(:q AS vector)
            LIMIT :lim
        """), params)).all()
        return [Hit(r.id, r.source_id, r.content, float(r.score), r.heading_path or "")
                for r in rows]
```

In `PgVectorStore.search_keyword`:

```python
        rows = (await self.session.execute(text("""
            SELECT id, source_id, content, heading_path,
                   ts_rank_cd(content_tsv, plainto_tsquery('english', :q)) AS score
            FROM kb_chunks
            WHERE collection_id = ANY(:cids)
              AND content_tsv @@ plainto_tsquery('english', :q)
            ORDER BY score DESC
            LIMIT :lim
        """), {"q": query_text, "cids": list(collection_ids), "lim": limit})).all()
        return [Hit(r.id, r.source_id, r.content, float(r.score), r.heading_path or "")
                for r in rows]
```

- [ ] **Step 5: Switch the indexer onto `chunk_document`**

In `api-engine/kb/indexer.py`, change the import:

```python
from kb.chunking import Chunk, chunk_document
```

Replace the chunking and upsert section of `index_source`:

```python
        # A question and answer pair is one idea; splitting it would return half
        # an answer, and it has no headings to carry.
        if source.type == "qa":
            chunks = [Chunk(text=body, heading_path="")]
        else:
            chunks = chunk_document(body,
                                    title=source.title or "",
                                    size=int(settings["chunk_size"]),
                                    overlap=int(settings["chunk_overlap"]))
        if not chunks:
            raise ValueError("Source produced no text to index.")

        client = embedder or EmbeddingClient(
            settings["embedding_base_url"],
            settings["embedding_api_key"],
            settings["embedding_model"],
        )
        vectors = await client.embed([c.text for c in chunks])

        store = make_store(session, settings["vector_driver"])
        await store.upsert([
            {
                "collection_id": source.collection_id,
                "source_id": source.id,
                "ordinal": i,
                "content": chunk.text,
                "char_count": len(chunk.text),
                "heading_path": chunk.heading_path,
                "embedding_model": settings["embedding_model"],
                "embedding": vector,
            }
            for i, (chunk, vector) in enumerate(zip(chunks, vectors))
        ])

        source.status = "ready"
        source.chunk_count = len(chunks)
```

- [ ] **Step 6: Add the column to the test fixtures**

Every engine test fixture builds `kb_chunks` from `Base.metadata`, so the new
column appears automatically. No fixture edit is needed. Confirm by running:

```bash
.venv/Scripts/python -m pytest tests/test_store.py tests/test_indexer.py -v
```

Expected: all pass.

- [ ] **Step 7: Run the whole Python suite**

```bash
.venv/Scripts/python -m pytest -q
```

Expected: 99 passed.

- [ ] **Step 8: Write the Laravel migration**

Create `admin-laravel/database/migrations/2026_09_10_000004_add_heading_path_to_kb_chunks.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_chunks', function (Blueprint $table) {
            // Nullable because chunks indexed before structure-aware chunking
            // have no path until they are re-indexed.
            $table->string('heading_path', 500)->nullable()->after('char_count');
        });
    }

    public function down(): void
    {
        Schema::table('kb_chunks', function (Blueprint $table) {
            $table->dropColumn('heading_path');
        });
    }
};
```

- [ ] **Step 9: Write the failing Laravel test**

Append to `admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php`:

```php
    public function test_chunks_carry_a_heading_path(): void
    {
        $this->assertTrue(Schema::hasColumn('kb_chunks', 'heading_path'));
    }
```

If `Illuminate\Support\Facades\Schema` is not already imported in that file, add
`use Illuminate\Support\Facades\Schema;` beside the existing imports.

- [ ] **Step 10: Run the Laravel suite**

```bash
php artisan test
```

Expected: 46 passed.

- [ ] **Step 11: Apply the migration to the development database**

```bash
php artisan migrate
```

Expected: `2026_09_10_000004_add_heading_path_to_kb_chunks ... DONE`.

- [ ] **Step 12: Commit**

```bash
git add api-engine/database.py api-engine/kb/store.py api-engine/kb/indexer.py \
        api-engine/tests/test_store.py api-engine/tests/test_indexer.py \
        admin-laravel/database/migrations/2026_09_10_000004_add_heading_path_to_kb_chunks.php \
        admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php
git commit -m "feat: store the heading path with every chunk"
```

---

### Task 6: Show the section in retrieval

Carry `heading_path` out through retrieval and the API, and render it in the playground.

**Files:**
- Modify: `api-engine/kb/retrieval.py` (`RetrievedChunk`, both branches)
- Modify: `api-engine/routers/kb.py:84-88`
- Modify: `api-engine/tests/test_retrieval.py`
- Modify: `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php:206`
- Modify: `admin-laravel/resources/views/kb/playground.blade.php:124-135`
- Modify: `admin-laravel/tests/Feature/RetrievalPlaygroundTest.php`

**Interfaces:**
- Consumes: `Hit.heading_path` from Task 5.
- Produces:
  - `RetrievedChunk(chunk_id, source_id, content, score, heading_path="")`.
  - Search response items gain `"heading_path"`.
  - Playground result arrays gain `heading_path`, and `content` arrives with the breadcrumb line already removed.

- [ ] **Step 1: Write the failing Python test**

Append to `api-engine/tests/test_retrieval.py`:

```python
def test_retrieved_chunks_default_to_an_empty_heading_path():
    from kb.retrieval import RetrievedChunk
    chunk = RetrievedChunk(1, "s1", "body", 0.5)
    assert chunk.heading_path == ""


def test_a_retrieved_chunk_keeps_the_heading_path_it_was_given():
    from kb.retrieval import RetrievedChunk
    chunk = RetrievedChunk(1, "s1", "body", 0.5, "Policy > Warranty")
    assert chunk.heading_path == "Policy > Warranty"
```

- [ ] **Step 2: Run it to verify it fails**

```bash
.venv/Scripts/python -m pytest tests/test_retrieval.py -v
```

Expected: `TypeError: RetrievedChunk.__init__() takes 5 positional arguments but 6 were given`.

- [ ] **Step 3: Carry the path through retrieval**

In `api-engine/kb/retrieval.py`, extend the dataclass:

```python
@dataclass
class RetrievedChunk:
    chunk_id: int
    source_id: str
    content: str
    score: float
    heading_path: str = ""
```

In the vector branch of `retrieve_for_collections`:

```python
        for h in hits:
            by_id[str(h.chunk_id)] = RetrievedChunk(h.chunk_id, h.source_id, h.content,
                                                    h.score, h.heading_path)
```

In the keyword branch:

```python
        for h in hits:
            by_id.setdefault(str(h.chunk_id),
                             RetrievedChunk(h.chunk_id, h.source_id, h.content,
                                            h.score, h.heading_path))
```

And in the fused output loop, preserve it:

```python
        out.append(RetrievedChunk(chunk.chunk_id, chunk.source_id, chunk.content,
                                  score, chunk.heading_path))
```

- [ ] **Step 4: Add the field to the search response**

In `api-engine/routers/kb.py`, in the `search` route:

```python
    return {"results": [
        {"chunk_id": r.chunk_id, "source_id": r.source_id,
         "content": r.content, "score": r.score,
         "heading_path": r.heading_path}
        for r in results
    ]}
```

- [ ] **Step 5: Run the Python suite**

```bash
.venv/Scripts/python -m pytest -q
```

Expected: 101 passed.

- [ ] **Step 6: Write the failing Laravel test**

In `admin-laravel/tests/Feature/RetrievalPlaygroundTest.php`, replace
`test_running_a_query_shows_the_matching_passages` and add two tests after it:

```php
    public function test_running_a_query_shows_the_matching_passages(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['chunk_id' => 7, 'source_id' => 'kbs_1', 'content' => 'Thirty days.',
             'score' => 0.0328, 'heading_path' => ''],
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

    public function test_a_passage_shows_its_section_and_hides_the_breadcrumb_line(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['chunk_id' => 7, 'source_id' => 'kbs_1',
             'content' => "Section: Refund policy > Warranty\n\nTwo years on desk lamps.",
             'score' => 0.0328, 'heading_path' => 'Refund policy > Warranty'],
        ]], 200)]);

        $response = $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'warranty period',
                'collections' => ['kbc_1'],
                'mode' => 'hybrid', 'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertOk()
            ->assertSee('Refund policy &gt; Warranty', false)
            ->assertSee('Two years on desk lamps.');

        $response->assertDontSee('Section:');
    }

    public function test_a_passage_with_no_section_renders_without_a_chip(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['chunk_id' => 8, 'source_id' => 'kbs_1', 'content' => 'Plain passage.',
             'score' => 0.01, 'heading_path' => ''],
        ]], 200)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'anything',
                'collections' => ['kbc_1'],
                'mode' => 'hybrid', 'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertOk()
            ->assertSee('Plain passage.')
            ->assertDontSee('Section:');
    }
```

- [ ] **Step 7: Run it to verify it fails**

```bash
php artisan test --filter=RetrievalPlaygroundTest
```

Expected: `test_a_passage_shows_its_section_and_hides_the_breadcrumb_line` fails, because the view does not render the chip and the breadcrumb line is still in the body.

- [ ] **Step 8: Strip the breadcrumb in the controller**

In `admin-laravel/app/Http/Controllers/KnowledgeBaseController.php`, in
`runPlayground`, replace the line `$results = $response['results'] ?? [];` with:

```php
        // The breadcrumb is part of the indexed text, so it comes back inside
        // the passage. Show it once, as a chip, not twice.
        $results = array_map(function (array $result): array {
            $result['heading_path'] = $result['heading_path'] ?? '';
            $break = strpos($result['content'], "\n\n");
            if ($result['heading_path'] !== ''
                && str_starts_with($result['content'], 'Section: ')
                && $break !== false) {
                $result['content'] = ltrim(substr($result['content'], $break + 2));
            }

            return $result;
        }, $response['results'] ?? []);
```

- [ ] **Step 9: Render the chip**

In `admin-laravel/resources/views/kb/playground.blade.php`, inside the
`@foreach($results as $i => $result)` loop, insert the chip between the header
row and the passage paragraph:

```blade
                            @if(!empty($result['heading_path']))
                                <div class="mb-1">
                                    <span class="chip">{{ $result['heading_path'] }}</span>
                                </div>
                            @endif
                            <p class="text-muted mb-0" style="font-size: 0.78125rem; line-height: 1.6;">
                                {{ $result['content'] }}
                            </p>
```

- [ ] **Step 10: Run the Laravel suite**

```bash
php artisan test
```

Expected: 48 passed.

- [ ] **Step 11: Commit**

```bash
git add api-engine/kb/retrieval.py api-engine/routers/kb.py api-engine/tests/test_retrieval.py \
        admin-laravel/app/Http/Controllers/KnowledgeBaseController.php \
        admin-laravel/resources/views/kb/playground.blade.php \
        admin-laravel/tests/Feature/RetrievalPlaygroundTest.php
git commit -m "feat: show the section a passage came from"
```

---

### Task 7: New defaults and the context budget

Raise the chunk size, add the budget, widen the bounds, and trim retrieval before the prompt.

**Files:**
- Create: `admin-laravel/database/migrations/2026_09_10_000005_update_chunking_defaults.php`
- Modify: `api-engine/database.py` (`SETTING_DEFAULTS`, line 166)
- Modify: `api-engine/kb/retrieval.py` (add `fit_to_budget`)
- Modify: `api-engine/routers/chat.py:88-113`
- Modify: `api-engine/tests/test_retrieval.py`
- Modify: `admin-laravel/app/Models/AppSetting.php`
- Modify: `admin-laravel/app/Http/Controllers/AdminSettingsController.php`
- Modify: `admin-laravel/resources/views/admin/settings.blade.php:91-108`
- Modify: `admin-laravel/tests/Feature/AdminSettingsTest.php`

**Interfaces:**
- Consumes: `RetrievedChunk` from Task 6.
- Produces: `fit_to_budget(chunks: list[RetrievedChunk], budget: int) -> list[RetrievedChunk]`.

- [ ] **Step 1: Write the failing Python test**

Append to `api-engine/tests/test_retrieval.py`:

```python
def test_fit_to_budget_keeps_the_highest_ranked_chunks_that_fit():
    from kb.retrieval import RetrievedChunk, fit_to_budget
    chunks = [RetrievedChunk(n, "s", "x" * 100, 1.0 - n / 10) for n in range(5)]

    kept = fit_to_budget(chunks, 250)

    assert [c.chunk_id for c in kept] == [0, 1]


def test_fit_to_budget_always_keeps_the_first_chunk():
    from kb.retrieval import RetrievedChunk, fit_to_budget
    chunks = [RetrievedChunk(1, "s", "x" * 5000, 0.9)]

    assert len(fit_to_budget(chunks, 100)) == 1


def test_fit_to_budget_passes_everything_through_when_it_all_fits():
    from kb.retrieval import RetrievedChunk, fit_to_budget
    chunks = [RetrievedChunk(n, "s", "x" * 100, 0.5) for n in range(3)]

    assert len(fit_to_budget(chunks, 6000)) == 3


def test_fit_to_budget_handles_an_empty_list():
    from kb.retrieval import fit_to_budget
    assert fit_to_budget([], 6000) == []
```

- [ ] **Step 2: Run it to verify it fails**

```bash
.venv/Scripts/python -m pytest tests/test_retrieval.py -v
```

Expected: `ImportError: cannot import name 'fit_to_budget' from 'kb.retrieval'`.

- [ ] **Step 3: Add `fit_to_budget`**

In `api-engine/kb/retrieval.py`, add after `retrieve_for_collections`:

```python
def fit_to_budget(chunks: list[RetrievedChunk], budget: int) -> list[RetrievedChunk]:
    """The highest-ranked chunks that fit inside a character budget.

    The first is always kept: one long passage beats no passage at all. Trimming
    here rather than inside the context block is what keeps the prompt and the
    citations shown to the visitor in agreement.
    """
    kept: list[RetrievedChunk] = []
    used = 0
    for chunk in chunks:
        if kept and used + len(chunk.content) > budget:
            break
        kept.append(chunk)
        used += len(chunk.content)
    return kept
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
.venv/Scripts/python -m pytest tests/test_retrieval.py -v
```

Expected: all pass.

- [ ] **Step 5: Change the engine defaults**

In `api-engine/database.py`:

```python
SETTING_DEFAULTS = {
    "embedding_base_url": "http://localhost:11434/v1",
    "embedding_api_key": "",
    "embedding_model": "nomic-embed-text",
    "embedding_dimensions": "768",
    "vector_driver": "pgvector",
    "chunk_size": "1800",
    "chunk_overlap": "200",
    "context_char_budget": "6000",
}
```

- [ ] **Step 6: Trim retrieval in the chat route**

In `api-engine/routers/chat.py`, extend the import:

```python
from database import (get_db, get_settings, BotProfile, System, ChatConversation,
                      ChatMessage, BotKbCollection, KbSource)
from kb.retrieval import (augment_system_prompt, build_context_block,
                          fit_to_budget, retrieve_for_collections)
```

Inside the `if bot.retrieval_enabled:` block, after `retrieved = await retrieve_for_collections(...)` and before the source-title lookup, add:

```python
            # Bigger chunks mean a bigger prompt. Trim before the titles are
            # looked up so the citations match what the model actually saw.
            engine_settings = await get_settings(db)
            retrieved = fit_to_budget(
                retrieved, int(engine_settings["context_char_budget"]))
```

- [ ] **Step 7: Run the whole Python suite**

```bash
.venv/Scripts/python -m pytest -q
```

Expected: 105 passed.

- [ ] **Step 8: Write the failing Laravel tests**

In `admin-laravel/tests/Feature/AdminSettingsTest.php`, update the `payload`
helper defaults and add the new cases. The helper's defaults become:

```php
            'chunk_size' => 1800,
            'chunk_overlap' => 200,
            'context_char_budget' => 6000,
```

Update the existing assertion that saved `chunk_size` is `'800'` to use a value
inside the new bounds if it is not already, then add:

```php
    public function test_chunk_size_accepts_the_widened_bounds(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['chunk_size' => 400]))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['chunk_size' => 8000]))
            ->assertSessionHasNoErrors();
    }

    public function test_chunk_size_rejects_values_outside_the_bounds(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['chunk_size' => 399]))
            ->assertSessionHasErrors('chunk_size');

        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['chunk_size' => 8001]))
            ->assertSessionHasErrors('chunk_size');
    }

    public function test_the_context_budget_persists_and_validates_its_range(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['context_char_budget' => 9000]))
            ->assertSessionHasNoErrors();
        $this->assertSame('9000', AppSetting::get('context_char_budget'));

        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.update'), $this->payload(['context_char_budget' => 999]))
            ->assertSessionHasErrors('context_char_budget');
    }

    public function test_an_untouched_chunk_size_is_migrated_to_the_new_default(): void
    {
        AppSetting::put('chunk_size', '900');
        AppSetting::put('chunk_overlap', '150');

        // The migration class is anonymous, so load the file and call it directly.
        $migration = require database_path(
            'migrations/2026_09_10_000005_update_chunking_defaults.php');
        $migration->up();

        $this->assertSame('1800', AppSetting::get('chunk_size'));
        $this->assertSame('200', AppSetting::get('chunk_overlap'));
    }

    public function test_a_chosen_chunk_size_survives_the_migration(): void
    {
        AppSetting::put('chunk_size', '1200');

        $migration = require database_path(
            'migrations/2026_09_10_000005_update_chunking_defaults.php');
        $migration->up();

        $this->assertSame('1200', AppSetting::get('chunk_size'));
    }
```

The existing test that saves `chunk_size` as `800` needs no change: 800 is inside
the widened bounds.

In `admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php`, change the default
assertion:

```php
        $this->assertSame('1800', AppSetting::get('chunk_size'));
```

- [ ] **Step 9: Run them to verify they fail**

```bash
php artisan test --filter=AdminSettingsTest
```

Expected: the budget tests fail on a missing validation rule, and the migration
tests fail because the file does not exist.

- [ ] **Step 10: Change the Laravel defaults**

In `admin-laravel/app/Models/AppSetting.php`:

```php
    public const DEFAULTS = [
        'embedding_base_url' => 'http://localhost:11434/v1',
        'embedding_api_key' => '',
        'embedding_model' => 'nomic-embed-text',
        'embedding_dimensions' => '768',
        'vector_driver' => 'pgvector',
        'chunk_size' => '1800',
        'chunk_overlap' => '200',
        'context_char_budget' => '6000',
    ];
```

- [ ] **Step 11: Widen the bounds and add the key**

In `admin-laravel/app/Http/Controllers/AdminSettingsController.php`:

```php
    private const KEYS = [
        'embedding_base_url', 'embedding_api_key', 'embedding_model',
        'embedding_dimensions', 'vector_driver', 'chunk_size', 'chunk_overlap',
        'context_char_budget',
    ];
```

```php
            'chunk_size' => ['required', 'integer', 'min:400', 'max:8000'],
            'chunk_overlap' => ['required', 'integer', 'min:0', 'lt:chunk_size'],
            'context_char_budget' => ['required', 'integer', 'min:1000', 'max:20000'],
```

- [ ] **Step 12: Write the settings migration**

Create `admin-laravel/database/migrations/2026_09_10_000005_update_chunking_defaults.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Old default => new default. A value equal to the old one was never chosen. */
    private const MOVES = [
        'chunk_size' => ['900', '1800'],
        'chunk_overlap' => ['150', '200'],
    ];

    public function up(): void
    {
        foreach (self::MOVES as $key => [$old, $new]) {
            DB::table('app_settings')->where('key', $key)->where('value', $old)
                ->update(['value' => $new]);
            Cache::forget("app_setting:{$key}");
        }
    }

    public function down(): void
    {
        foreach (self::MOVES as $key => [$old, $new]) {
            DB::table('app_settings')->where('key', $key)->where('value', $new)
                ->update(['value' => $old]);
            Cache::forget("app_setting:{$key}");
        }
    }
};
```

- [ ] **Step 13: Add the budget field to the settings form**

In `admin-laravel/resources/views/admin/settings.blade.php`, replace the
chunking card's inner `<div class="p-3">` block with:

```blade
            <div class="p-3">
                <div class="row g-3">
                    <div class="col-6">
                        <label for="chunk_size" class="form-label">Chunk size</label>
                        <input type="number" name="chunk_size" id="chunk_size" class="form-control font-monospace"
                               min="400" max="8000" value="{{ old('chunk_size', $settings['chunk_size']) }}" required>
                        <div class="form-text">A ceiling, not a target. Headings, tables and code blocks decide where a passage ends; this only stops one growing without limit.</div>
                    </div>
                    <div class="col-6">
                        <label for="chunk_overlap" class="form-label">Overlap</label>
                        <input type="number" name="chunk_overlap" id="chunk_overlap" class="form-control font-monospace"
                               min="0" value="{{ old('chunk_overlap', $settings['chunk_overlap']) }}" required>
                        <div class="form-text">Characters repeated when one long passage has to be cut. Sections never overlap, because the boundary between them already means something.</div>
                    </div>
                    <div class="col-6">
                        <label for="context_char_budget" class="form-label">Context budget</label>
                        <input type="number" name="context_char_budget" id="context_char_budget" class="form-control font-monospace"
                               min="1000" max="20000" value="{{ old('context_char_budget', $settings['context_char_budget']) }}" required>
                        <div class="form-text">Characters of retrieved material sent to the model. Lower it if answers wander on a small model.</div>
                    </div>
                </div>
                <div class="form-text mt-2">Chunking applies to sources indexed from now on. Existing chunks keep the boundaries they were made with until you rebuild the index.</div>
            </div>
```

- [ ] **Step 14: Run both suites**

```bash
php artisan test
```

Expected: 53 passed.

```bash
cd ../api-engine && .venv/Scripts/python -m pytest -q
```

Expected: 105 passed.

- [ ] **Step 15: Apply the migration**

```bash
cd ../admin-laravel && php artisan migrate
```

Expected: `2026_09_10_000005_update_chunking_defaults ... DONE`.

- [ ] **Step 16: Commit**

```bash
git add api-engine/database.py api-engine/kb/retrieval.py api-engine/routers/chat.py \
        api-engine/tests/test_retrieval.py \
        admin-laravel/app/Models/AppSetting.php \
        admin-laravel/app/Http/Controllers/AdminSettingsController.php \
        admin-laravel/resources/views/admin/settings.blade.php \
        admin-laravel/database/migrations/2026_09_10_000005_update_chunking_defaults.php \
        admin-laravel/tests/Feature/AdminSettingsTest.php \
        admin-laravel/tests/Feature/KnowledgeBaseSchemaTest.php
git commit -m "feat: raise the chunk ceiling and budget the context"
```

---

### Task 8: Re-index and verify end to end

Rebuild the existing corpus and confirm the defects are gone in the running system.

**Files:**
- No source changes. This task produces evidence.

**Interfaces:**
- Consumes: everything from Tasks 1 to 7.
- Produces: screenshots and a verification note.

- [ ] **Step 1: Start the stack**

From the repository root:

```bash
./start-dev.bat
```

Wait for the Laravel admin on `http://localhost:8080` and the engine on
`http://localhost:8001` to answer. Confirm the engine reports the right
database:

```bash
curl -s http://localhost:8001/health
```

Expected: `"database"` is `postgresql` and `"degraded"` is `false`. If it says
`sqlite`, stop and fix the connection before continuing; a re-index against the
wrong database wastes the run.

- [ ] **Step 2: Add a source that exercises the new boundaries**

In the admin, open a workspace, go to Knowledge Base, open a collection, and add
a text source titled `Customer Policy` with the body from
`api-engine/tests/fixtures/policy.md`. Wait for its status to reach `ready`.

- [ ] **Step 3: Rebuild the whole index**

Admin Settings, then the "Rebuild the index" button. Confirm the flash message
names a source count. Watch the knowledge base list until every source is
`ready` again.

- [ ] **Step 4: Verify the warranty defect is fixed**

Open the retrieval playground. Select the collection. Query `warranty period for
pendant fittings`, mode `hybrid`, top k 5.

Expected: the top passage is the warranty table, its section chip reads
`Customer Policy > Warranty`, and the passage body starts with the table rather
than with `Section:`.

- [ ] **Step 5: Verify the word-fragment defect is fixed**

Query `international shipping delays`. Read the returned passages.

Expected: no passage begins mid-word. Every passage opens on a whole word.

- [ ] **Step 6: Verify the widget still answers with citations**

Open a bot that has this collection enabled, use its embed preview, and ask
`how long is the warranty on pendant fittings`.

Expected: the answer names 36 months, and the source chips under it match the
passages the playground returned. No chip refers to a passage the model did not
receive.

- [ ] **Step 7: Capture screenshots**

Capture the retrieval playground with the section chips visible, and the Admin
Settings chunking card with the new fields. Save them under
`docs/superpowers/screenshots/`.

- [ ] **Step 8: Run both suites one last time**

```bash
cd api-engine && .venv/Scripts/python -m pytest -q
cd ../admin-laravel && php artisan test
```

Expected: 105 Python passed, 53 Laravel passed.

- [ ] **Step 9: Commit the screenshots**

```bash
git add docs/superpowers/screenshots
git commit -m "docs: screenshots for structure-aware chunking"
```

- [ ] **Step 10: Finish the branch**

**REQUIRED SUB-SKILL:** Use superpowers:finishing-a-development-branch. The base
branch is `main`.

---

## Notes for the implementer

**Why the chunker keeps size at all.** Structure decides boundaries, but a
document can have one heading and forty paragraphs under it. Size is what stops
that becoming a single 40,000-character chunk. Read every size comparison in
`_pack` and `_split_block` as a ceiling.

**Why the breadcrumb is inside `content` and also in its own column.** The
embedding, the keyword index and the model prompt all need it in the text, so it
lives in `content`. The column exists so the playground can show it as a chip
without parsing the body. If the two ever disagree, `content` wins; it is what
retrieval actually matched against.

**Why there is no minimum chunk size.** A section with one sentence in it is a
real section. Merging it into its neighbour would put two topics in one vector.
The breadcrumb is what keeps a short chunk findable.

**The engine falls back to SQLite silently if Postgres is unreachable.** It
prints a banner and `/health` reports it. Check `/health` before trusting any
end-to-end result in Task 8.
