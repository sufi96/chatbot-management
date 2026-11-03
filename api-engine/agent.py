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
import connectors
from llm_adapter import LLMAdapter

SYSTEM = ("You can call tools to look things up for the visitor. Call a tool only when the "
          "question needs it. Never invent arguments: when a tool is needed but the visitor has not "
          "given everything it needs, call ask_visitor with one short question asking for all of "
          "it, and no other tool.")

LEAD = "Results from the systems this bot is connected to:"

EXCERPT = 500

HISTORY_TURNS = 6


@dataclass
class Tool:
    id: str
    name: str
    description: str
    parameters: dict
    writes: bool
    connector: object
    row: object
    # A built-in: run here by awaiting local(arguments), not through a connector.
    local: object = None


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
    # The chat_tool_calls row once written, for the Confirm card to name.
    row_id: str = ""


@dataclass
class AgentResult:
    calls: list = field(default_factory=list)
    context_block: str = ""
    pending: CallRecord | None = None
    model: str = ""
    # The skills the model loaded, for the answer model to follow too.
    guidance: str = ""
    # One "loaded" record per skill loaded, for the log and the test chat's
    # trace. Kept apart from calls: loading is not a call to anyone's system.
    loaded: list = field(default_factory=list)
    # What the model needs from the visitor before a tool can run (ask_visitor).
    # An answer in itself: without it, a reply with no tool call was a miss and
    # the question it wrote was thrown away.
    question: str = ""
    # A call failed for the system's sake (sign-in, server error, unreachable),
    # not because the record is missing. The answer must not read it as "not found".
    system_failed: bool = False
    # A call answered 404: the system has no such record. Said plainly, or the
    # answer model fills the silence with a status it made up.
    not_found: bool = False


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
              extra_system: str = "", skills=(), history=None) -> AgentResult:
    if not tools and not skills:
        return AgentResult()

    ask = ask or LLMAdapter.complete_with_tools
    call = call or connectors.call
    base_url, api_key, model = endpoint
    by_name = {tool.name: tool for tool in tools}
    schemas = [schema(tool) for tool in tools]
    if tools:
        schemas.append(ASK_VISITOR)
    limit = max(1, int(getattr(bot, "agentic_max_calls", None) or 3))

    skills_by_name = {skill.name: skill for skill in skills}
    if skills:
        extra_system = "\n\n".join(part for part in (extra_system, skills_prompt(skills)) if part)
        schemas.append(LOAD_SKILL)

    system = SYSTEM + ("\n\n" + extra_system if extra_system else "")
    # The last few visitor and assistant turns, so "A-12345" after "what is
    # your reference number?" means something. Nothing else is let in.
    earlier = [{"role": t["role"], "content": t["content"]} for t in (history or [])
               if t.get("role") in ("user", "assistant") and isinstance(t.get("content"), str)
               and t["content"].strip()][-HISTORY_TURNS:]
    messages = [{"role": "system", "content": system}, *earlier, {"role": "user", "content": message}]
    result = AgentResult(model=model)

    # Loading a skill is not a call to anyone's system, so it does not count
    # against the limit; the rounds cap is what stops a model that only loads.
    rounds = 0
    while len(result.calls) < limit and rounds < limit + 2:
        rounds += 1
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

            if name == ASK_VISITOR["function"]["name"] and tools:
                question = str((arguments or {}).get("question") or "").strip()[:QUESTION_CHARS]
                if question:
                    result.question = question
                    result.loaded.append(CallRecord(None, name, {"question": question}, "asked"))
                    break
                feedback = json.dumps({"error": "Give the question to ask."})
            elif name == LOAD_SKILL["function"]["name"] and skills:
                skill = skills_by_name.get((arguments or {}).get("name", ""))
                if skill:
                    # Steps to follow, unlike material: screened when loaded
                    # (skills_for), so not marked "do not obey".
                    feedback = f"Instructions for the {skill.name} skill:\n{skill.body}"
                    _load(result, skill)
                else:
                    feedback = json.dumps({"error": "No skill by that name is available."})
            elif tool is None or arguments is None:
                problem = f"No tool called {name}." if tool is None else "The arguments were not a JSON object."
                result.calls.append(CallRecord(tool, name, arguments or {}, "failed", excerpt=problem))
                feedback = json.dumps({"error": problem})
            elif tool.writes:
                _load_for(result, skills, name)
                # Never run here: the visitor confirms first, in the widget.
                result.pending = CallRecord(tool, name, arguments, "awaiting_confirm")
                result.calls.append(result.pending)
                break
            else:
                outcome = await (tool.local(arguments) if tool.local else call(tool.connector, tool.row, arguments))
                if outcome.ok:
                    _load_for(result, skills, name)
                if not outcome.ok:
                    if outcome.status == 404:
                        result.not_found = True
                    else:
                        result.system_failed = True
                    result.calls.append(CallRecord(tool, name, arguments, "failed",
                                                   excerpt=(outcome.detail or outcome.error or outcome.text)[:EXCERPT], ms=outcome.ms))
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

        if result.pending or result.question:
            break

    result.context_block = _block(result.calls)
    return result


QUESTION_CHARS = 300

ASK_VISITOR = {"type": "function", "function": {
    "name": "ask_visitor",
    "description": "Ask the visitor for details a tool needs that they have not given yet, "
                   "such as their name, phone number or a reference number.",
    "parameters": {"type": "object", "properties": {"question": {"type": "string"}}, "required": ["question"]},
}}


LOAD_SKILL = {"type": "function", "function": {
    "name": "load_skill",
    "description": "Load a skill's step-by-step instructions before handling a request it matches.",
    "parameters": {"type": "object", "properties": {"name": {"type": "string"}}, "required": ["name"]},
}}


def _load(result: AgentResult, skill) -> None:
    """A skill's steps into the answer, once, with a line for the trace."""
    if skill.body not in result.guidance:
        result.guidance = "\n\n".join(p for p in (result.guidance, skill.body) if p)
        result.loaded.append(CallRecord(None, "load_skill", {"name": skill.name}, "loaded"))


def _load_for(result: AgentResult, skills, tool_name: str) -> None:
    """Every skill whose main operation this is, loaded because it was called.

    A small model goes straight to the operation and never calls load_skill,
    so the operator's steps for it were never followed. A skill's first tool
    is its main operation; the others are helpers (lodge-complaint searches
    for duplicates first), and a plain search must not pull in the lodging
    steps. Called only for an operation that ran or awaits Confirm: on a
    failure, a status skill's examples were copied into the answer as a
    status nobody returned.
    """
    for skill in skills:
        if (getattr(skill, "tools", None) or [])[:1] == [tool_name]:
            _load(result, skill)


def skills_prompt(skills) -> str:
    if not skills:
        return ""
    lines = [f"- {skill.name}: {skill.description}" for skill in skills]
    return ("Skills you can load with load_skill. When a request matches one, call load_skill with "
            "its name first, before any other tool, and follow its steps:\n" + "\n".join(lines))


async def skills_for(session, bot, tool_names: set, mode: str = "progressive") -> list:
    """The bot's skills in its own workspace whose tools it has, minus any that
    read as an attempt to instruct the model. Skill text is data like any other."""
    from database import BotSkill, Skill

    rows = (await session.execute(
        select(Skill).join(BotSkill, BotSkill.skill_id == Skill.id)
        .where(BotSkill.bot_id == bot.id, Skill.system_id == bot.system_id,
               Skill.mode == mode if mode == "always" else (Skill.mode != "always") | Skill.mode.is_(None))
        .order_by(Skill.name))).scalars().all()

    usable = []
    for skill in rows:
        if not set(skill.tools or []) <= set(tool_names):
            continue
        if shield.detect(f"{skill.description}\n{skill.body}").found:
            print(f"[Skills] Skipped {skill.name}: reads as instructions")
            continue
        usable.append(skill)
    return usable


def _block(calls: list[CallRecord]) -> str:
    """What the tools returned, numbered like citations, marked as data."""
    parts, extras = [], []
    for record in calls:
        if record.status != "ran" or record.flag:
            continue
        if record.tool and record.tool.local:
            # A built-in is no citation, so it takes no number.
            extras.append(f"{record.name}: {record.text}")
            continue
        parts.append(f"[{len(parts) + 1}] {record.tool.connector.name}: {record.name}\n{record.text}")

    parts += extras
    return spotlight.wrap(LEAD, "\n\n".join(parts)) if parts else ""
