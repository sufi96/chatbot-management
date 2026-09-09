from kb.chunking import chunk_text


def test_short_text_is_one_chunk():
    assert chunk_text("hello world", size=900, overlap=150) == ["hello world"]


def test_empty_text_yields_nothing():
    assert chunk_text("", size=900, overlap=150) == []
    assert chunk_text("   \n  ", size=900, overlap=150) == []


def test_splits_on_paragraph_before_hard_cutting():
    first = "a" * 500
    second = "b" * 500
    chunks = chunk_text(f"{first}\n\n{second}", size=600, overlap=0)
    assert chunks == [first, second]


def test_overlap_carries_tail_of_previous_chunk():
    text = "x" * 1000
    chunks = chunk_text(text, size=400, overlap=100)
    assert len(chunks) > 1
    assert chunks[1][:100] == chunks[0][-100:]


def test_no_chunk_exceeds_size():
    text = ("word " * 4000).strip()
    for chunk in chunk_text(text, size=300, overlap=50):
        assert len(chunk) <= 300


def test_no_empty_chunks():
    text = "para one\n\n\n\n\npara two"
    assert all(c.strip() for c in chunk_text(text, size=100, overlap=10))


def test_headings_are_preferred_boundaries():
    text = "## One\nalpha\n\n## Two\nbeta"
    chunks = chunk_text(text, size=20, overlap=0)
    assert chunks[0].startswith("## One")
    assert any(c.startswith("## Two") for c in chunks)
