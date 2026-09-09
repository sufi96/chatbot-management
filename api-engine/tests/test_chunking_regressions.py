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
