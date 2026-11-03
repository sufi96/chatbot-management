"""C⁴ as an MCP server, so other AI tools (Copilot, Claude, a staff agent) can
use a workspace's bots. Mounted at /mcp in main.py.

Every call carries a workspace's MCP key as a bearer token; only its SHA-256 is
stored. A key reaches its own workspace's bots and never the console's. ask_bot
runs the ordinary chat route in-process, so the shield, guard, leak watch,
sources and logging all apply exactly as for a visitor.
"""
import hashlib
import json
import uuid
from datetime import datetime

import httpx
from fastmcp import FastMCP
from fastmcp.exceptions import ToolError
from fastmcp.server.dependencies import get_http_headers
from sqlalchemy import select

import database

server = FastMCP(
    "C⁴",
    instructions="Ask this organisation's chatbots. Call list_bots first, then ask_bot with a bot id.",
)

# The engine's FastAPI app, set by main.py, for ask_bot to call the chat route.
APP = None


def bearer() -> str | None:
    auth = get_http_headers(include={"authorization"}).get("authorization", "")
    scheme, _, token = auth.partition(" ")
    return token.strip() if scheme.lower() == "bearer" and token.strip() else None


async def _workspace(session) -> str:
    """The key's workspace, or a refusal. Marks the key as used."""
    from database import McpKey

    token = bearer()
    if not token:
        raise ToolError("Send a C⁴ MCP key as a bearer token.")
    key = (await session.execute(select(McpKey).where(
        McpKey.token_hash == hashlib.sha256(token.encode()).hexdigest()))).scalars().first()
    if key is None:
        raise ToolError("That MCP key is not valid.")
    key.last_used_at = datetime.utcnow()
    await session.commit()
    return key.system_id


async def _bot(session, system_id: str, bot_id: str):
    from database import BotProfile

    bot = (await session.execute(select(BotProfile).where(
        BotProfile.id == bot_id, BotProfile.system_id == system_id, BotProfile.is_active.is_(True),
        BotProfile.deleted_at.is_(None)))).scalars().first()
    if bot is None or bot.is_platform:
        raise ToolError("No such bot in this workspace.")
    return bot


@server.tool(annotations={"readOnlyHint": True})
async def list_bots() -> list[dict]:
    """The chatbots this key may ask: id, name and what each is for."""
    from database import BotProfile

    async with database.async_session_factory() as session:
        system_id = await _workspace(session)
        bots = (await session.execute(select(BotProfile).where(
            BotProfile.system_id == system_id, BotProfile.is_active.is_(True),
            BotProfile.deleted_at.is_(None)).order_by(BotProfile.name))).scalars().all()
    return [{"id": b.id, "name": b.name, "title": b.widget_title} for b in bots if not b.is_platform]


@server.tool(annotations={"readOnlyHint": True})
async def search_knowledge(bot_id: str, query: str) -> dict:
    """Passages from a bot's knowledge base that match the query, with their document titles."""
    from database import BotKbCollection, get_settings
    from sources import attempts

    async with database.async_session_factory() as session:
        bot = await _bot(session, await _workspace(session), bot_id)
        ids = list((await session.execute(select(BotKbCollection.collection_id).where(
            BotKbCollection.bot_id == bot.id))).scalars().all())
        if not ids:
            return {"passages": "", "sources": []}
        found = await attempts.documents(session, bot, query, await get_settings(session), ids)
    return {"passages": found.context_block, "sources": [c["title"] for c in found.citations]}


@server.tool
async def ask_bot(bot_id: str, question: str, session_id: str | None = None) -> dict:
    """Ask a bot a question, as a visitor would. Pass session_id back to continue the conversation."""
    async with database.async_session_factory() as session:
        await _bot(session, await _workspace(session), bot_id)

    session_id = session_id if (session_id or "").startswith("mcp-") else f"mcp-{uuid.uuid4()}"
    answer, sources, pending = [], [], None
    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=APP), base_url="http://c4", timeout=120) as client:
        response = await client.post("/api/v1/chat/stream", json={
            "bot_id": bot_id, "session_id": session_id, "message": question[:4000], "history": []})
    if response.status_code != 200:
        raise ToolError(f"The bot could not answer (HTTP {response.status_code}).")

    for line in response.text.splitlines():
        if not line.startswith("data: ") or line == "data: [DONE]":
            continue
        try:
            event = json.loads(line[6:])
        except ValueError:
            continue
        if event.get("content"):
            answer.append(event["content"])
        elif event.get("type") == "sources":
            sources = [s.get("title") for s in event.get("sources") or []]
        elif event.get("type") == "confirm":
            pending = event.get("summary")
        elif event.get("type") == "retract":
            answer = [event.get("content") or ""]

    result = {"answer": "".join(answer), "sources": sources, "session_id": session_id}
    if pending:
        # A change waits on a person pressing Confirm in the widget; MCP cannot.
        result["action_waiting"] = pending
    return result
