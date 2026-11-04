"""Tools C⁴ carries itself, for an operator to switch on per bot.

No connector, no outside call: each runs here and answers at once. They are
Tools like any connector's, so the loop, the log and the trace treat them the
same; only `local` tells the loop to run them here.
"""
from datetime import datetime
from types import SimpleNamespace
from zoneinfo import ZoneInfo

import agent
from connectors.rest import CallResult

# ponytail: one fixed zone for every bot; a per-workspace zone when a
# workspace outside Malaysia needs one.
ZONE = ZoneInfo("Asia/Kuala_Lumpur")

NAMES = ("current_datetime", "request_human")

C4 = SimpleNamespace(name="C⁴")

HANDOVER_TEXT = "A member of staff has been asked to follow up."


async def _now(arguments: dict) -> CallResult:
    now = datetime.now(ZONE)
    return CallResult(ok=True, text=f"{now.isoformat(timespec='seconds')} ({now:%A, %d %B %Y}, Malaysia time)")


async def _handover(arguments: dict) -> CallResult:
    # The chat route marks the conversation when it logs this call; see
    # routers/chat.py. Nothing leaves the engine.
    return CallResult(ok=True, text=HANDOVER_TEXT)


_TOOLS = {
    "current_datetime": ("Today's date and the current time in Malaysia.",
                         {"type": "object", "properties": {}}, _now),
    "request_human": ("Ask a member of staff to follow up with this visitor. Use when they ask for a "
                      "person, or when you cannot help after two tries.",
                      {"type": "object", "properties": {"reason": {
                          "type": "string", "description": "One line on what the visitor needs."}},
                       "required": ["reason"]}, _handover),
}


MAKE_FILE = "make_file"

MAKE_FILE_WHEN = {
    "on_request": "Only when the visitor asks for a file, such as \"send me this as Excel\".",
    "own_initiative": ("When the visitor asks for a file, or when a file would serve them better than a "
                       "chat reply, such as a long table or a document they will keep."),
}


async def _make_file(arguments: dict) -> CallResult:
    # Nothing is made here: the chat route turns the reply itself into the file
    # once it is written, so the model writes the content where it writes best.
    fmt = str(arguments.get("format") or "").lower()
    title = str(arguments.get("title") or "answer")[:120]
    return CallResult(ok=True, text=f"A {fmt} file titled \"{title}\" will be attached to your reply. "
                                    "Write the file's full content as your reply, in Markdown "
                                    "(use a table for rows and columns).")


def make_file_tool(bot):
    """make_file for a bot with files out and Agentic on, else None."""
    from files import settings as file_settings

    rules = file_settings.for_bot(bot)
    if not (rules.out_enabled and rules.out_formats and getattr(bot, "agentic_enabled", False)):
        return None
    return agent.Tool(
        id=None, name=MAKE_FILE, writes=False, connector=C4, row=None, local=_make_file,
        description="Hand your reply to the visitor as a downloadable file. " + MAKE_FILE_WHEN[rules.out_mode],
        parameters={"type": "object", "properties": {
            "format": {"type": "string", "enum": list(rules.out_formats)},
            "title": {"type": "string", "description": "A short title for the file."}},
            "required": ["format", "title"]})


def for_bot(bot) -> list:
    chosen = getattr(bot, "builtin_tools", None) or []
    tools = [agent.Tool(id=None, name=name, description=_TOOLS[name][0], parameters=_TOOLS[name][1],
                        writes=False, connector=C4, row=None, local=_TOOLS[name][2])
             for name in NAMES if name in chosen]
    maker = make_file_tool(bot)
    return tools + [maker] if maker else tools
