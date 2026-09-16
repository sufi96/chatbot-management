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
