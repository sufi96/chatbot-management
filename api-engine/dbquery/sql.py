"""Turning a model's statement into one that is safe to run, or refusing it.

A model writes these and a model can be talked into writing anything, so this
assumes nothing about good intent. It is defence in depth beside the
allowlist and the read-only account, never a substitute for either. Laravel
runs the same checks again before it executes.
"""
import re
from dataclasses import dataclass

# Matched as whole tokens. A column called created_at contains "create" and
# must not be mistaken for a CREATE statement.
FORBIDDEN = (
    "insert", "update", "delete", "drop", "alter", "create", "truncate",
    "grant", "revoke", "merge", "exec", "execute", "call", "into",
    "attach", "pragma", "copy",
)

# A string literal can contain anything, including a double dash, so literals
# are taken out of play before comments are looked for.
_LITERAL = re.compile(r"'(?:[^']|'')*'")
_LINE_COMMENT = re.compile(r"--[^\n]*")
_BLOCK_COMMENT = re.compile(r"/\*.*?\*/", re.DOTALL)
_IDENTIFIER = r'(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z_][\w$]*(?:\.[A-Za-z_][\w$]*)*)'
_SOURCE = re.compile(r"\b(?:from|join)\s+(" + _IDENTIFIER + r")", re.IGNORECASE)
_CTE_NAME = re.compile(r"\b(?:with|,)\s+(" + _IDENTIFIER + r")\s+as\s*\(", re.IGNORECASE)


@dataclass
class Validation:
    ok: bool
    sql: str = ""
    complaint: str = ""


def strip_comments(sql: str) -> str:
    """Comments out, string literals preserved.

    This happens before anything else. A second statement hidden in a comment
    must not survive into a later check that only sees one semicolon.
    """
    literals: list[str] = []

    def stash(match: re.Match) -> str:
        literals.append(match.group(0))
        return f"\x00{len(literals) - 1}\x00"

    masked = _LITERAL.sub(stash, sql)
    masked = _BLOCK_COMMENT.sub(" ", masked)
    masked = _LINE_COMMENT.sub(" ", masked)

    return re.sub(r"\x00(\d+)\x00", lambda m: literals[int(m.group(1))], masked)


def _unquote(identifier: str) -> str:
    return identifier.strip().strip('"').strip("`").strip("[]").lower()


def referenced_tables(sql: str) -> set[str]:
    """Every identifier sitting after FROM or JOIN, subqueries included."""
    return {_unquote(match) for match in _SOURCE.findall(strip_comments(sql))}


def _cte_names(sql: str) -> set[str]:
    return {_unquote(match) for match in _CTE_NAME.findall(sql)}


def _has_row_limit(sql: str, driver: str) -> bool:
    lowered = sql.lower()
    if driver == "sqlsrv":
        return re.search(r"^\s*select\s+top\s+\d+", lowered) is not None
    return re.search(r"\blimit\s+\d+", lowered) is not None


def _apply_row_limit(sql: str, driver: str, max_rows: int) -> str:
    if _has_row_limit(sql, driver):
        return sql

    if driver == "sqlsrv":
        return re.sub(r"^(\s*select)\s", rf"\1 TOP {max_rows} ", sql, count=1,
                      flags=re.IGNORECASE)

    return f"{sql.rstrip()} LIMIT {max_rows}"


def validate(sql: str, allowed: set[str], driver: str, max_rows: int) -> Validation:
    cleaned = strip_comments(sql or "").strip()
    cleaned = re.sub(r";\s*$", "", cleaned).strip()

    if not cleaned:
        return Validation(False, complaint="The statement was empty.")

    if ";" in cleaned:
        return Validation(False, complaint="Only one statement may be sent. Remove the semicolon.")

    lowered = cleaned.lower()
    if not re.match(r"^(select|with)\b", lowered):
        return Validation(False, complaint="The statement must begin with SELECT or WITH.")

    if lowered.startswith("with") and not re.search(r"\bselect\b", lowered):
        return Validation(False, complaint="A WITH clause must end in a SELECT.")

    for verb in FORBIDDEN:
        if re.search(rf"\b{verb}\b", lowered):
            return Validation(False, complaint=(
                f"{verb.upper()} is not allowed. Only reading is permitted."))

    permitted = {name.lower() for name in allowed} | _cte_names(cleaned)
    for table in referenced_tables(cleaned):
        if table not in permitted:
            return Validation(False, complaint=(
                f"The table {table} is not one this bot may read. "
                f"Allowed tables: {', '.join(sorted(allowed))}."))

    return Validation(True, sql=_apply_row_limit(cleaned, driver, max_rows))
