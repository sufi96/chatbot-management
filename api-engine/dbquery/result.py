"""What came back from a query.

Its own module so context.py and portal.py can both import it without a
circular import once the package's __init__ imports them.
"""
from dataclasses import dataclass, field


@dataclass
class QueryResult:
    columns: list[str] = field(default_factory=list)
    rows: list[list] = field(default_factory=list)
    # How many rows the query matched, which is not always how many are in
    # rows: the budget may have dropped some. "How many orders" is answered
    # from this, never from len(rows).
    row_count: int = 0
    elapsed_ms: int = 0


@dataclass
class DbAnswer:
    """What one attempt at the database produced.

    `ran` is the whole distinction the cascade turns on. A query that ran is an
    answer about the operator's data whether it found rows or not. A query that
    was never built, was rejected, was declined or failed is not an answer about
    anything, and the next source gets its turn.
    """
    ran: bool = False
    context_block: str = ""
    sql: str = ""
    row_count: int = 0
    connection_name: str = ""
