"""Asking Laravel to run a statement.

This is the one call that goes engine to portal. Every other call in this
system goes the other way, which is why it carries its own secret rather than
reusing the one Laravel sends here.

Nothing raises. A refusal, an unreachable portal and a malformed reply all
come back as a complaint the caller turns into a fall-through.
"""
import httpx

from dbquery.result import QueryResult


async def run_query(base_url: str, token: str, connection_id: str, sql: str,
                    max_rows: int, timeout: int,
                    transport=None) -> tuple[QueryResult | None, str]:
    endpoint = f"{base_url.rstrip('/')}/internal/db/query"
    payload = {
        "connection_id": connection_id,
        "sql": sql,
        "max_rows": max_rows,
        "timeout": timeout,
    }

    # Room for the portal's own timeout to fire and report properly, rather
    # than this one cutting it off and losing the reason.
    budget = timeout + 5

    try:
        async with httpx.AsyncClient(timeout=budget, transport=transport) as client:
            response = await client.post(
                endpoint, json=payload,
                headers={"X-Portal-Token": token, "Accept": "application/json"})
            response.raise_for_status()
            body = response.json()
    except Exception as error:
        return None, f"Could not reach the portal: {error}"

    if not body.get("ok"):
        return None, body.get("message") or "The portal refused the query."

    return QueryResult(
        columns=body.get("columns") or [],
        rows=body.get("rows") or [],
        row_count=int(body.get("row_count") or 0),
        elapsed_ms=int(body.get("elapsed_ms") or 0),
    ), ""
