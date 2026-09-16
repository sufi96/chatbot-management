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


def test_an_oversized_table_splits_on_rows_and_repeats_the_header():
    rows = "\n".join(f"| Item {n} | {n * 10} |" for n in range(40))
    table = "| Item | Days |\n|---|---|\n" + rows
    chunks = chunk_document("## Terms\n\n" + table, size=400, overlap=0)

    assert len(chunks) > 1
    for chunk in chunks:
        assert "| Item | Days |" in chunk.text
        assert "|---|---|" in chunk.text
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


def test_a_description_becomes_an_about_line_under_the_section():
    chunks = chunk_document("## Warranty\n\nTwo years.", title="Policy",
                            description="Retail terms.")
    assert chunks[0].text.startswith(
        "Section: Policy > Warranty\nAbout: Retail terms.\n\n")
    assert chunks[0].text.endswith("Two years.")


def test_no_description_means_no_about_line():
    chunks = chunk_document("## Warranty\n\nTwo years.", title="Policy")
    assert "About:" not in chunks[0].text


def test_a_description_without_headings_still_gets_an_about_line():
    chunks = chunk_document("plain text", description="Retail terms.")
    assert chunks[0].text == "About: Retail terms.\n\nplain text"
    assert chunks[0].heading_path == ""


def test_a_description_is_flattened_onto_one_line():
    chunks = chunk_document("body", description="Retail\nterms.")
    assert chunks[0].text.startswith("About: Retail terms.\n\n")


def test_the_description_counts_against_the_ceiling():
    text = "## H\n\n" + "\n\n".join("x" * 100 for _ in range(8))
    for chunk in chunk_document(text, size=400, overlap=0,
                                description="d" * 120):
        assert len(chunk.text) <= 400


def test_prepend_description_attaches_an_about_line():
    from kb.chunking import prepend_description
    assert prepend_description("Q: a\nA: b", "Retail terms.") == \
        "About: Retail terms.\n\nQ: a\nA: b"


def test_prepend_description_leaves_text_alone_without_one():
    from kb.chunking import prepend_description
    assert prepend_description("Q: a\nA: b", "") == "Q: a\nA: b"
