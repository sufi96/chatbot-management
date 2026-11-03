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


def for_bot(bot) -> list:
    chosen = getattr(bot, "builtin_tools", None) or []
    return [agent.Tool(id=None, name=name, description=_TOOLS[name][0], parameters=_TOOLS[name][1],
                       writes=False, connector=C4, row=None, local=_TOOLS[name][2])
            for name in NAMES if name in chosen]
