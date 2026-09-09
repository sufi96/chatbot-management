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
