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
            marker = fence.group(1)[:3]
            body = [line]
            i += 1
            while i < len(lines):
                body.append(lines[i])
                closed = lines[i].strip().startswith(marker)
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
