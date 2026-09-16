"""Rows rendered for a prompt, inside the budget the rest of the system uses."""
from dbquery.result import QueryResult

LIVE_NOTICE = (
    "The rows below are live data, read from the operator's own database at "
    "the moment this question was asked, on {date}, from the connection "
    'named "{name}". Answer from these rows only. Do not invent a row that '
    "is not here, and do not present a figure as remembered when it was read."
)


def _render_row(row: list) -> str:
    return " | ".join("" if value is None else str(value) for value in row)


def fit_rows_to_budget(result: QueryResult, budget: int) -> QueryResult:
    """As many whole rows as fit, keeping the true match count.

    The first row is kept whatever its size, matching fit_to_budget in
    kb/retrieval.py: one long row beats none at all.
    """
    kept: list[list] = []
    used = 0

    for row in result.rows:
        rendered = len(_render_row(row))
        if kept and used + rendered > budget:
            break
        kept.append(row)
        used += rendered

    return QueryResult(columns=result.columns, rows=kept,
                       row_count=result.row_count, elapsed_ms=result.elapsed_ms)


def build_database_context_block(connection_name: str, result: QueryResult,
                                 queried_on: str, question: str = "") -> str:
    parts = [LIVE_NOTICE.format(date=queried_on, name=connection_name)]

    # Rows alone do not say what they mean. Given a bare COUNT(*) of 3, a small
    # model told its visitor it had no such information; told the question the
    # rows were read for, it said "3 orders". The question, never the statement:
    # the answer model can repeat what it is given, and a visitor must not learn
    # the table names.
    if question.strip():
        parts.append(f"They were read to answer this question: {question.strip()}")

    parts.append("")

    if not result.rows:
        parts.append("The query ran and matched no rows at all.")
        return "\n".join(parts)

    parts.append(" | ".join(result.columns))
    parts.append("-|-".join("-" for _ in result.columns))
    parts.extend(_render_row(row) for row in result.rows)

    if result.row_count > len(result.rows):
        parts.append("")
        parts.append(
            f"The query matched {result.row_count} rows in total. "
            f"The first {len(result.rows)} are shown above.")

    return "\n".join(parts)
