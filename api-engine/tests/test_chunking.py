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
    chunks = chunk_document("## A\n\n\n\n## B\n\nbody", size=200, overlap=20)
    assert all(c.text.strip() for c in chunks)
