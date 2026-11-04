"""MCP servers as connectors, against a real FastMCP server held in memory."""
from types import SimpleNamespace

from fastmcp import FastMCP

import connectors
from connectors import mcp

server = FastMCP("Applications")


@server.tool(annotations={"readOnlyHint": True})
def application_status(reference: str) -> str:
    """The status of one application."""
    return f"{reference}: approved"


@server.tool(name="tickets.create")
def create_ticket(subject: str) -> str:
    """Open a ticket."""
    return "Ticket 41 opened"


@server.tool
def broken() -> str:
    """Always fails."""
    raise ValueError("database offline at 10.0.0.5")


def connector(**fields):
    base = dict(type="mcp", name="Apps", base_url="http://mcp.test/mcp", auth_type="none",
                auth_header=None, auth_secret=None)
    return SimpleNamespace(**{**base, **fields})


def row(name):
    return SimpleNamespace(path=name, method="mcp", parameters={})


async def test_tools_are_listed_with_their_schema_and_whether_they_write():
    found = await mcp.list_tools("http://mcp.test/mcp", "none", None, None, transport=server)

    assert found["success"] is True
    tools = {t["name"]: t for t in found["tools"]}
    assert tools["application_status"]["writes"] is False
    assert tools["tickets.create"]["writes"] is True
    assert tools["application_status"]["parameters"]["required"] == ["reference"]
    assert tools["application_status"]["description"] == "The status of one application."


async def test_a_tool_is_called_by_its_mcp_name():
    result = await mcp.call(connector(), row("tickets.create"), {"subject": "Login broken"}, transport=server)

    assert result.ok and result.text == "Ticket 41 opened"


async def test_a_failing_tool_is_a_sentence_not_an_exception():
    result = await mcp.call(connector(), row("broken"), {}, transport=server)

    assert result.ok is False
    assert result.error and "10.0.0.5" not in result.error


async def test_an_unreachable_server_is_a_sentence():
    down = connector(base_url="http://127.0.0.1:9/mcp")

    result = await mcp.call(down, row("application_status"), {"reference": "A1"})
    pinged = await mcp.ping("http://127.0.0.1:9/mcp", "none", None, None)
    listed = await mcp.list_tools("http://127.0.0.1:9/mcp", "none", None, None)

    assert result.ok is False and "127.0.0.1" not in result.error and "127.0.0.1" in result.detail
    assert pinged["success"] is False and pinged["message"]
    assert listed["success"] is False and listed["message"]


async def test_the_dispatcher_sends_mcp_connectors_to_mcp(monkeypatch):
    seen = []

    async def fake_mcp(c, r, a, **kwargs):
        seen.append("mcp")
        return mcp.CallResult(ok=True)

    async def fake_rest(c, r, a, **kwargs):
        seen.append("rest")
        return mcp.CallResult(ok=True)

    monkeypatch.setattr(connectors.mcp, "call", fake_mcp)
    monkeypatch.setattr(connectors.rest, "call", fake_rest)

    await connectors.call(connector(), row("x"), {})
    await connectors.call(connector(type="rest"), row("x"), {})
    await connectors.call(SimpleNamespace(name="old row, no type"), row("x"), {})

    assert seen == ["mcp", "rest", "rest"]


async def test_the_portal_routes_need_the_admin_token(monkeypatch):
    from fastapi import FastAPI
    from httpx import ASGITransport, AsyncClient
    from config import settings
    from routers import connectors as connectors_router

    monkeypatch.setattr(settings, "ADMIN_API_TOKEN", "portal-token")
    app = FastAPI()
    app.include_router(connectors_router.router)
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        refused = await client.post("/api/v1/connectors/mcp-tools", json={"base_url": "http://127.0.0.1:9/mcp"})
        down = await client.post("/api/v1/connectors/test", headers={"X-Admin-Token": "portal-token"},
                                 json={"type": "mcp", "base_url": "http://127.0.0.1:9/mcp"})

    assert refused.status_code == 401
    assert down.json()["success"] is False


async def test_a_fastmcp_server_passes_the_test_button():
    # FastMCP servers do not answer the protocol's ping, so reaching one must not depend on it.
    pinged = await mcp.ping("http://mcp.test/mcp", "none", None, None, transport=server)

    assert pinged["success"] is True and "3 tools" in pinged["message"]
