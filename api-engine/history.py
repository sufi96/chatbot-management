"""Which earlier turns of a conversation the models read.

The widget sends its own copy of the conversation with every message. That copy
is whatever the browser says it is: anyone can post a turn marked "assistant"
in which the bot agreed to drop its rules, or a turn marked "system" that reads
as the operator's own instructions. A model cannot tell those from the real
thing.

So by default the engine reads the conversation from its own records, written
as each message arrived and each answer streamed, and ignores the browser's
copy. An install can choose the browser's copy instead (Admin Settings,
Security), which is then cleaned: only visitor and assistant turns, each cut to
a length, and only the most recent few. A caller holding the engine's admin
token, such as the evaluation runner, is trusted to send history either way,
since a test conversation has no records to read.
"""
import hmac

from sqlalchemy import select

MAX_TURNS = 10
TURN_CHARS = 4000

ROLES = ("user", "assistant")
SOURCES = ("server", "client")


def sanitise(history) -> list[dict]:
    """The browser's turns, keeping only what a visitor could honestly have sent.

    A system turn is dropped outright: no visitor writes the operator's
    instructions. Anything that is not a list of objects with text is dropped
    rather than guessed at.
    """
    turns = []
    for turn in history or []:
        if not isinstance(turn, dict):
            continue
        role = turn.get("role") or turn.get("sender")
        content = turn.get("content")
        if role not in ROLES or not isinstance(content, str) or not content.strip():
            continue
        turns.append({"role": role, "content": content[:TURN_CHARS]})

    return turns[-MAX_TURNS:]


async def stored(session, conversation_id: str, exclude_id: str | None = None) -> list[dict]:
    """The conversation as the engine recorded it, oldest first.

    The message being answered is left out: it has just been written, and the
    models read it as the question, not as history.
    """
    from database import ChatMessage

    stmt = (select(ChatMessage.id, ChatMessage.sender, ChatMessage.content)
            .where(ChatMessage.conversation_id == conversation_id,
                   ChatMessage.sender.in_(ROLES))
            .order_by(ChatMessage.created_at.desc())
            .limit(MAX_TURNS + 1))
    rows = (await session.execute(stmt)).all()

    turns = [{"role": row.sender, "content": (row.content or "")[:TURN_CHARS]}
             for row in reversed(rows) if row.id != exclude_id and (row.content or "").strip()]

    return turns[-MAX_TURNS:]


def trusted(admin_token_header: str | None, expected: str) -> bool:
    """Whether the caller holds the engine's admin token.

    Compared in constant time. No token configured means nobody is trusted.
    """
    if not expected or not admin_token_header:
        return False
    return hmac.compare_digest(admin_token_header.encode(), expected.encode())


def source_for(settings: dict) -> str:
    value = (settings.get("history_source") or "server").strip().lower()
    return value if value in SOURCES else "server"


async def for_request(session, settings: dict, conversation_id: str, exclude_id: str,
                      sent, caller_trusted: bool) -> list[dict]:
    """The history the models read for this message."""
    if caller_trusted or source_for(settings) == "client":
        return sanitise(sent)

    return await stored(session, conversation_id, exclude_id)
