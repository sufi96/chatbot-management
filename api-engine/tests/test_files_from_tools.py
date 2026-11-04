"""Files a connector or MCP tool returns reach the visitor, on a bot with files out."""
import io
import json
from types import SimpleNamespace

import httpx
import pytest
from fastmcp import FastMCP
from fastmcp.utilities.types import Image as McpImage
from PIL import Image
from sqlalchemy import select

import agent
from connectors import mcp, rest
from connectors.rest import CallResult, Returned
from database import ChatFile
from files import storage
from routers import chat
from tests.test_chat_route import answering, factory, post, update_bot  # noqa: F401  (fixture)


def png() -> bytes:
    buf = io.BytesIO()
    Image.new("RGB", (6, 6), "green").save(buf, "PNG")
    return buf.getvalue()


server = FastMCP("Maps")


@server.tool
def site_plan(lot: str) -> McpImage:
    """The plan of a lot."""
    return McpImage(data=png(), format="png")


async def test_an_mcp_image_comes_back_as_a_file():
    connector = SimpleNamespace(type="mcp", base_url="http://mcp.test/mcp", auth_type="none",
                                auth_header=None, auth_secret=None)

    got = await mcp.call(connector, SimpleNamespace(path="site_plan"), {"lot": "7"}, transport=server)

    assert got.ok and len(got.files) == 1
    assert got.files[0].mime == "image/png" and got.files[0].data.startswith(b"\x89PNG")


async def test_a_rest_pdf_comes_back_as_a_file():
    def reply(request):
        return httpx.Response(200, content=b"%PDF-1.4\n%%EOF", headers={
            "content-type": "application/pdf", "content-disposition": 'attachment; filename="receipt.pdf"'})

    connector = SimpleNamespace(base_url="http://api.test", auth_type="none", auth_header=None, auth_secret=None)
    tool = SimpleNamespace(name="getReceipt", method="GET", path="/receipt", parameters={})

    got = await rest.call(connector, tool, {}, transport=httpx.MockTransport(reply))

    assert got.ok and [f.name for f in got.files] == ["receipt.pdf"]
    assert "returned a file: receipt.pdf" in got.text


@pytest.fixture(autouse=True)
def local_store(tmp_path, monkeypatch):
    monkeypatch.setattr(storage, "_current", storage.LocalStorage(tmp_path / "files"))


def a_run_returning_a_file(monkeypatch):
    connector = SimpleNamespace(name="Maps")
    tool = agent.Tool(id="t1", name="site_plan", description="", parameters={}, writes=False,
                      connector=connector, row=SimpleNamespace())

    async def run(bot, message, tools, endpoint, **kwargs):
        record = agent.CallRecord(tool, "site_plan", {"lot": "7"}, "ran", text="returned a file: plan.png",
                                  files=[Returned("plan.png", "image/png", png())])
        return agent.AgentResult(calls=[record], model="m", context_block=agent._block([record]))

    async def tools_for(session, bot):
        return [tool]

    monkeypatch.setattr(agent, "run", run)
    monkeypatch.setattr(agent, "tools_for", tools_for)


async def agentic(factory, **fields):
    await update_bot(factory, agentic_enabled=True, source_order="tools,documents,database,web",
                     retrieval_enabled=False, db_query_enabled=False, web_search_enabled=False,
                     combine_sources=False, **fields)


async def test_the_chat_hands_a_tools_file_to_the_visitor(factory, monkeypatch):
    await agentic(factory, file_settings={"out_enabled": True})
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Here is the plan of lot 7."))
    a_run_returning_a_file(monkeypatch)

    _, events = await post(factory, message="can I see the plan of lot 7?")

    files = [json.loads(e) for e in events if e != "[DONE]" and json.loads(e).get("type") == "file"]
    assert [f["name"] for f in files] == ["plan.png"]
    async with factory() as session:
        row = (await session.execute(select(ChatFile))).scalars().one()
    assert row.direction == "out" and row.mime == "image/png"


async def test_without_files_out_the_file_is_not_handed_on(factory, monkeypatch):
    await agentic(factory)
    monkeypatch.setattr(chat.LLMAdapter, "stream_chat", answering("Here is the plan."))
    a_run_returning_a_file(monkeypatch)

    _, events = await post(factory, message="can I see the plan of lot 7?")

    assert not any(json.loads(e).get("type") == "file" for e in events if e != "[DONE]")
