"""Split text into retrieval-sized pieces.

Prefers structural boundaries, falling back to progressively weaker ones and
finally to a hard character cut, so a chunk breaks where meaning breaks rather
than mid-word wherever possible.
"""

SEPARATORS = ["\n## ", "\n\n", "\n", ". ", " "]


def chunk_text(text: str, size: int = 900, overlap: int = 150) -> list[str]:
    if not text or not text.strip():
        return []
    if overlap >= size:
        raise ValueError("overlap must be smaller than size")

    pieces = _split(text.strip(), size)

    # Re-join adjacent pieces up to the size budget, then carry an overlap tail.
    chunks: list[str] = []
    buffer = ""
    for piece in pieces:
        candidate = piece if not buffer else f"{buffer}{piece}"
        if len(candidate) <= size:
            buffer = candidate
            continue
        if buffer.strip():
            chunks.append(buffer.strip())
        buffer = (chunks[-1][-overlap:] + piece) if (chunks and overlap) else piece
        while len(buffer) > size:
            chunks.append(buffer[:size].strip())
            buffer = buffer[size - overlap:] if overlap else buffer[size:]
    if buffer.strip():
        chunks.append(buffer.strip())

    return [c for c in chunks if c.strip()]


def _split(text: str, size: int) -> list[str]:
    """Break text into fragments no larger than size, on the best boundary found."""
    if len(text) <= size:
        return [text]

    for sep in SEPARATORS:
        if sep not in text:
            continue
        parts = text.split(sep)
        out: list[str] = []
        for i, part in enumerate(parts):
            fragment = part if i == 0 else sep + part
            out.extend(_split(fragment, size) if len(fragment) > size else [fragment])
        return out

    return [text[i:i + size] for i in range(0, len(text), size)]
