"""Rows on their way into a prompt.

Two jobs. Keep the block inside the budget the knowledge base already
respects, and make sure the model presents the numbers as something it just
read rather than something it remembers.
"""
from dbquery.context import build_database_context_block, fit_rows_to_budget
from dbquery.result import QueryResult


def result(rows=None, columns=None):
    return QueryResult(
        columns=columns or ["id", "status", "total"],
        rows=rows if rows is not None else [[1, "shipped", "438.00"],
                                            [2, "pending", "1299.00"]],
        row_count=len(rows) if rows is not None else 2,
        elapsed_ms=12,
    )


def test_the_block_carries_the_column_names():
    block = build_database_context_block("Shop database", result(), "2026-09-14")

    assert "status" in block
    assert "total" in block


def test_the_block_carries_every_value():
    block = build_database_context_block("Shop database", result(), "2026-09-14")

    assert "shipped" in block
    assert "1299.00" in block


def test_the_block_names_the_connection():
    block = build_database_context_block("Shop database", result(), "2026-09-14")

    assert "Shop database" in block


def test_the_block_says_the_data_is_live_and_when_it_was_read():
    # Without this the model reports a queried figure as something it
    # remembers, which reads to a visitor as a guess.
    block = build_database_context_block("Shop database", result(), "2026-09-14")

    assert "2026-09-14" in block
    assert "live" in block.lower()


def test_no_rows_is_a_real_answer_not_an_empty_block():
    # "Is there an order 88421" deserves "the query found nothing", which is
    # a different statement from "I have no information".
    block = build_database_context_block("Shop database", result(rows=[]), "2026-09-14")

    assert block
    assert "no rows" in block.lower() or "nothing" in block.lower()


def test_the_budget_drops_whole_rows():
    wide = [[i, "x" * 100, "y"] for i in range(20)]
    trimmed = fit_rows_to_budget(result(rows=wide), 400)

    assert len(trimmed.rows) < 20
    assert all(len(row) == 3 for row in trimmed.rows)


def test_the_first_row_is_always_kept():
    # One long row beats no rows, matching fit_to_budget in kb/retrieval.py.
    huge = [["x" * 5000, "y", "z"]]
    trimmed = fit_rows_to_budget(result(rows=huge), 100)

    assert len(trimmed.rows) == 1


def test_trimming_records_how_many_rows_the_query_actually_found():
    wide = [[i, "x" * 100, "y"] for i in range(20)]
    trimmed = fit_rows_to_budget(result(rows=wide), 400)

    # The model must not be told six when the answer to "how many" is twenty.
    assert trimmed.row_count == 20


def test_trimming_keeps_the_columns():
    trimmed = fit_rows_to_budget(result(), 10_000)

    assert trimmed.columns == ["id", "status", "total"]


def test_an_empty_result_survives_trimming():
    trimmed = fit_rows_to_budget(result(rows=[]), 1000)

    assert trimmed.rows == []
    assert trimmed.row_count == 0


def test_the_block_says_when_it_shows_fewer_rows_than_were_found():
    partial = QueryResult(columns=["id"], rows=[[1]], row_count=97, elapsed_ms=5)
    block = build_database_context_block("Shop database", partial, "2026-09-14")

    assert "97" in block


def test_the_block_says_which_question_the_rows_answer():
    """A bare COUNT(*) of 3 means nothing on its own. Given only the rows, a 4B
    model answered "I do not have that information" to a question its own query
    had answered correctly. Given the question too, it said "3 orders"."""
    count = QueryResult(columns=["COUNT(*)"], rows=[[3]], row_count=1, elapsed_ms=1)

    block = build_database_context_block("Shop database", count, "2026-09-15",
                                         question="How many orders has Aina Rahman placed?")

    assert "How many orders has Aina Rahman placed?" in block


def test_the_question_comes_before_the_rows():
    block = build_database_context_block("Shop database", result(), "2026-09-15",
                                         question="Which orders are pending?")

    assert block.index("Which orders are pending?") < block.index("shipped")


def test_an_empty_result_still_says_what_was_asked():
    block = build_database_context_block("Shop database", result(rows=[]), "2026-09-15",
                                         question="Is there an order KA-9999?")

    assert "Is there an order KA-9999?" in block


def test_no_statement_ever_reaches_the_block():
    """The answer model can repeat what it is given, and a visitor must not
    learn the table names, let alone the statement."""
    block = build_database_context_block("Shop database", result(), "2026-09-15",
                                         question="Which orders are pending?")

    assert "select" not in block.lower()
