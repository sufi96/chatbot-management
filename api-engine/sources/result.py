"""What one source produced, in the one shape the chat route reads.

Kept free of project imports so dbquery and websearch can be translated into it
without either importing the cascade that calls them.
"""
from dataclasses import dataclass, field


@dataclass
class SourceResult:
    kind: str
    context_block: str = ""
    citations: list = field(default_factory=list)
    # The database fills these for the transcript. An operator auditing a wrong
    # answer needs the statement, not a guess at it.
    sql: str = ""
    row_count: int = 0
    # The reranker model whose scores chose these passages, for the trace.
    # Empty when fusion order was used, including after a reranker failed.
    reranked_by: str = ""
    # Set by the attempt that built this, never derived from the block being
    # non-empty: a query that ran and found nothing has an empty block and is
    # still an answer.
    has_content: bool = False
