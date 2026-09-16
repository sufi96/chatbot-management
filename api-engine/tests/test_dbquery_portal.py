"""Asking the portal to run a statement.

This is the call that reverses the architecture's one-way direction, so it
carries its own secret and it never raises.
"""
import httpx
import pytest

from dbquery.portal import run_query

OK_BODY = {
    "ok": True,
    "columns": ["id", "status"],
    "rows": [[1, "shipped"], [2, "pending"]],
    "row_count": 2,
    "elapsed_ms": 9,
    "message": "",
}


def responder(body, capture=None, status=200):
    def handler(request: httpx.Request) -> httpx.Response:
        if capture is not None:
            capture["url"] = str(request.url)
            capture["headers"] = dict(request.headers)
            capture["body"] = request.read().decode()
        return httpx.Response(status, json=body)
    return handler


@pytest.mark.asyncio
async def test_a_successful_query_becomes_a_result():
    result, complaint = await run_query(
        "http://localhost:8080", "tok", "dbc_1", "SELECT 1", 50, 10,
        transport=httpx.MockTransport(responder(OK_BODY)))

    assert complaint == ""
    assert result.columns == ["id", "status"]
    assert result.row_count == 2
    assert result.rows[0] == [1, "shipped"]


@pytest.mark.asyncio
async def test_the_token_travels_in_its_own_header():
    seen = {}
    await run_query("http://localhost:8080", "tok", "dbc_1", "SELECT 1", 50, 10,
                    transport=httpx.MockTransport(responder(OK_BODY, seen)))

    assert seen["headers"]["x-portal-token"] == "tok"


@pytest.mark.asyncio
async def test_the_request_goes_to_the_internal_route():
    seen = {}
    await run_query("http://localhost:8080/", "tok", "dbc_1", "SELECT 1", 50, 10,
                    transport=httpx.MockTransport(responder(OK_BODY, seen)))

    assert seen["url"] == "http://localhost:8080/internal/db/query"


@pytest.mark.asyncio
async def test_the_body_carries_every_limit():
    seen = {}
    await run_query("http://localhost:8080", "tok", "dbc_1", "SELECT 1", 25, 7,
                    transport=httpx.MockTransport(responder(OK_BODY, seen)))

    assert '"max_rows": 25' in seen["body"] or '"max_rows":25' in seen["body"]
    assert '"timeout": 7' in seen["body"] or '"timeout":7' in seen["body"]


@pytest.mark.asyncio
async def test_a_refusal_comes_back_as_a_complaint():
    body = {"ok": False, "columns": [], "rows": [], "row_count": 0,
            "elapsed_ms": 0, "message": "DELETE is not allowed."}

    result, complaint = await run_query(
        "http://x", "tok", "dbc_1", "DELETE FROM t", 50, 10,
        transport=httpx.MockTransport(responder(body)))

    assert result is None
    assert "DELETE" in complaint


@pytest.mark.asyncio
async def test_an_http_error_is_a_complaint_not_an_exception():
    result, complaint = await run_query(
        "http://x", "tok", "dbc_1", "SELECT 1", 50, 10,
        transport=httpx.MockTransport(responder({"error": "x"}, status=500)))

    assert result is None
    assert complaint


@pytest.mark.asyncio
async def test_an_unreachable_portal_is_a_complaint_not_an_exception():
    def handler(request):
        raise httpx.ConnectError("refused")

    result, complaint = await run_query(
        "http://x", "tok", "dbc_1", "SELECT 1", 50, 10,
        transport=httpx.MockTransport(handler))

    assert result is None
    assert complaint


@pytest.mark.asyncio
async def test_a_malformed_body_is_a_complaint():
    def handler(request):
        return httpx.Response(200, text="not json")

    result, complaint = await run_query(
        "http://x", "tok", "dbc_1", "SELECT 1", 50, 10,
        transport=httpx.MockTransport(handler))

    assert result is None
    assert complaint


@pytest.mark.asyncio
async def test_an_empty_result_set_is_success_not_failure():
    # "No order 88421" is an answer. It is not the query failing.
    body = {"ok": True, "columns": ["id"], "rows": [], "row_count": 0,
            "elapsed_ms": 3, "message": ""}

    result, complaint = await run_query(
        "http://x", "tok", "dbc_1", "SELECT 1", 50, 10,
        transport=httpx.MockTransport(responder(body)))

    assert complaint == ""
    assert result is not None
    assert result.rows == []
