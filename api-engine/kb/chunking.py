"""Group blocks into retrieval-sized chunks.

Structure decides where a chunk ends; size only stops one growing without limit.
Every chunk carries the document title and the headings above it, so a passage
still says where it came from once it is on its own.
"""
import re
from dataclasses import dataclass

from kb.blocks import Block, parse_blocks

BREADCRUMB_PREFIX = "Section: "
PATH_SEPARATOR = " > "

PROSE_SEPARATORS = ["\n\n", "\n", ". ", " "]

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
