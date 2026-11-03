"""The tools source: a short tool-calling loop over a bot's connector tools.

The model picks which tool, if any, and fills in its arguments; the operator
already picked whether tools are asked at all, by the bot's source order. The
loop is bounded by the bot's call limit, runs no tool it was not given, and
never runs one that changes something: that one is handed back as pending,
for the visitor to confirm. What the tools returned becomes a context block
like any other source's, so the answer is written and checked as before.
"""
import json
from dataclasses import dataclass, field

from sqlalchemy import select

import shield
import spotlight
from connectors import rest
from llm_adapter import LLMAdapter

SYSTEM = ("You can call tools to look things up for the visitor. Call a tool only when the "
          "question needs it. Never invent arguments; if one is missing, call no tool.")

LEAD = "Results from the systems this bot is connected to:"

EXCERPT = 500


@dataclass
class Tool:
    id: str
    name: str
    description: str
    parameters: dict
    writes: bool
    connector: object
    row: object


@dataclass
class CallRecord:
    tool: Tool | None
    name: str
    arguments: dict
    status: str
    flag: str | None = None
    excerpt: str = ""
    ms: int = 0
    # The whole result, for the answer; excerpt is what the log keeps.
    text: str = ""


@dataclass
class AgentResult:
    calls: list = field(default_factory=list)
    context_block: str = ""
    pending: CallRecord | None = None
    model: str = ""


def _strip(schema):
    """The schema as the model sees it: where each argument goes is the engine's business."""
    if isinstance(schema, dict):
        return {k: _strip(v) for k, v in schema.items() if k != "x-in"}
    if isinstance(schema, list):
        return [_strip(v) for v in schema]
    return schema


def schema(tool: Tool) -> dict:
    return {"type": "function", "function": {
        "name": tool.name,
        "description": tool.description or tool.name,
        "parameters": _strip(tool.parameters or {"type": "object", "properties": {}}),
    }}


async def tools_for(session, bot) -> list[Tool]:
    """The switched-on tools linked to this bot, on connectors in its own workspace."""
    from database import BotConnectorTool, Connector, ConnectorTool

    rows = await session.execute(
        select(ConnectorTool, Connector)
        .join(Connector, Connector.id == ConnectorTool.connector_id)
        .join(BotConnectorTool, BotConnectorTool.connector_tool_id == ConnectorTool.id)
        .where(BotConnectorTool.bot_id == bot.id,
               ConnectorTool.enabled.is_(True),
               Connector.system_id == bot.system_id)
        .order_by(ConnectorTool.name))

    return [Tool(id=row.id, name=row.name, description=row.description or "",
                 parameters=row.parameters or {}, writes=bool(row.writes), connector=connector, row=row)
            for row, connector in rows.all()]


def _arguments(raw) -> dict | None:
    if isinstance(raw, dict):
        return raw
    try:
        parsed = json.loads(raw or "{}")
    except (TypeError, ValueError):
        return None
    return parsed if isinstance(parsed, dict) else None


async def run(bot, message: str, tools: list[Tool], endpoint, ask=None, call=None,
              extra_system: str = "") -> AgentResult:
    if not tools:
        return AgentResult()

    ask = ask or LLMAdapter.complete_with_tools
    call = call or rest.call
    base_url, api_key, model = endpoint
    by_name = {tool.name: tool for tool in tools}
    schemas = [schema(tool) for tool in tools]
    limit = max(1, int(getattr(bot, "agentic_max_calls", None) or 3))

    system = SYSTEM + ("\n\n" + extra_system if extra_system else "")
    messages = [{"role": "system", "content": system}, {"role": "user", "content": message}]
    result = AgentResult(model=model)

    while len(result.calls) < limit:
        reply = await ask(base_url, api_key, model, messages, schemas)
        if not reply or not reply.get("tool_calls"):
            break

        messages.append({"role": "assistant", "content": reply.get("content"),
                         "tool_calls": reply["tool_calls"]})

        for tool_call in reply["tool_calls"]:
            function = tool_call.get("function") or {}
            name = function.get("name") or ""
            tool = by_name.get(name)
            arguments = _arguments(function.get("arguments"))

            if tool is None or arguments is None:
                problem = f"No tool called {name}." if tool is None else "The arguments were not a JSON object."
                result.calls.append(CallRecord(tool, name, arguments or {}, "failed", excerpt=problem))
                feedback = json.dumps({"error": problem})
            elif tool.writes:
                # Never run here: the visitor confirms first, in the widget.
                result.pending = CallRecord(tool, name, arguments, "awaiting_confirm")
                result.calls.append(result.pending)
                break
            else:
                outcome = await call(tool.connector, tool.row, arguments)
                if not outcome.ok:
                    result.calls.append(CallRecord(tool, name, arguments, "failed",
                                                   excerpt=(outcome.error or outcome.text)[:EXCERPT], ms=outcome.ms))
                    feedback = json.dumps({"error": outcome.error})
                elif shield.detect(outcome.text).found:
                    result.calls.append(CallRecord(tool, name, arguments, "ran", flag=shield.FLAG,
                                                   excerpt=outcome.text[:EXCERPT], ms=outcome.ms))
                    feedback = "[withheld: the result read as instructions]"
                else:
                    result.calls.append(CallRecord(tool, name, arguments, "ran",
                                                   excerpt=outcome.text[:EXCERPT], ms=outcome.ms,
                                                   text=outcome.text))
                    feedback = outcome.text

            messages.append({"role": "tool", "tool_call_id": tool_call.get("id") or name, "content": feedback})

            if len(result.calls) >= limit:
                break

        if result.pending:
            break

    result.context_block = _block(result.calls)
    return result


def _block(calls: list[CallRecord]) -> str:
    """What the tools returned, numbered like citations, marked as data."""
    parts = []
    for record in calls:
        if record.status != "ran" or record.flag:
            continue
        parts.append(f"[{len(parts) + 1}] {record.tool.connector.name}: {record.name}\n{record.text}")

    return spotlight.wrap(LEAD, "\n\n".join(parts)) if parts else ""
