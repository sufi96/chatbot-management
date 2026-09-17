"""Understanding a visitor's message before any source is consulted.

Two questions, answered by one call to the intent role. Does answering need
facts at all, or is this small talk? And what should the sources search for,
given that "and the warranty?" means nothing without the message before it?

What this does not decide is which source answers. That stays the operator's
order: a model choosing the source was tried and removed because nobody could
configure or explain it (docs/model-stack-review.md section 5). A verdict here
only says whether to look, and what for.

Every failure reads as "facts, searched with the visitor's own words", which is
exactly what the chat route did before this module existed.
"""
import json
import re
from dataclasses import dataclass

import roles
from kb.gating import should_retrieve
from llm_adapter import LLMAdapter

INTENTS = ("facts", "chat")

HISTORY_TURNS = 6
TURN_CHARS = 400
QUERY_CHARS = 500

PROMPT = """You read the latest message sent to a customer service assistant, with the conversation before it, and decide two things.

intent: "facts" when answering needs information about the business, its products, services, policies, orders or records. "chat" when it is a greeting, thanks, small talk, or a reaction that needs no information.

query: the latest message rewritten as one question that makes sense with no conversation before it. Replace words such as "it", "that one" and "the second" with what they refer to. Keep the visitor's language. Keep product names, codes and numbers exactly as written. When the message already stands on its own, repeat it unchanged.

Reply with one JSON object and nothing else, for example:
{"intent": "facts", "query": "What is the warranty on the X200 air fryer?"}"""

# First brace to last brace. A small model wraps its object in a fence or a
# sentence however firmly it is told not to.
_OBJECT = re.compile(r"\{.*\}", re.DOTALL)


@dataclass(frozen=True)
class Understanding:
    intent: str
    query: str
    # Whether a model produced this. False is the fallback, which every caller
    # treats exactly like the behaviour before intent existed.
    ran: bool = False
    model: str = ""


@dataclass(frozen=True)
class Decision:
    # Whether the sources are consulted at all. The route still checks that
    # one is switched on.
    is_question: bool
    # What the sources are asked. The answer model reads the visitor's words.
    query: str
    # The model's verdict, or None when no model was asked.
    intent: str | None = None
    model: str = ""


def earlier_turns(message: str, history: list[dict]) -> list[dict]:
    """The turns before this message, without the copy of it the widget appends."""
    turns = [turn for turn in (history or [])
             if (turn.get("role") or turn.get("sender")) in ("user", "assistant")
             and (turn.get("content") or "").strip()]

    # The widget sends the message being asked as the last turn of its history
    # as well. Reading it twice would make it its own context.
    if (turns and (turns[-1].get("role") or turns[-1].get("sender")) == "user"
            and turns[-1]["content"].strip() == message.strip()):
        turns = turns[:-1]

    return turns


def build_input(message: str, history: list[dict]) -> str:
    """The conversation as the intent model reads it: recent turns, then the message."""
    turns = earlier_turns(message, history)

    lines = []
    for turn in turns[-HISTORY_TURNS:]:
        role = turn.get("role") or turn.get("sender") or ""
        content = (turn.get("content") or "").strip()
        if role not in ("user", "assistant") or not content:
            continue
        speaker = "visitor" if role == "user" else "assistant"
        lines.append(f"{speaker}: {content[:TURN_CHARS]}")

    earlier = "\n".join(lines) if lines else "(none)"

    return f"Conversation so far:\n{earlier}\n\nLatest message:\n{message.strip()}"


def parse(raw: str, message: str) -> Understanding | None:
    """The model's reply as an Understanding, or None when it is not usable."""
    found = _OBJECT.search(raw or "")
    if not found:
        return None

    try:
        data = json.loads(found.group(0))
    except ValueError:
        return None

    if not isinstance(data, dict):
        return None

    verdict = str(data.get("intent") or "").strip().lower()
    if verdict not in INTENTS:
        verdict = "facts"

    query = str(data.get("query") or "").strip()[:QUERY_CHARS] or message.strip()

    # A question mark is the visitor saying it is a question. A small model's
    # "chat" does not overrule that: a wasted search costs less than an
    # invented answer.
    if verdict == "chat" and "?" in message:
        verdict = "facts"

    return Understanding(intent=verdict, query=query, ran=True)


async def understand(bot, message: str, history: list[dict], settings: dict,
                     complete=None) -> Understanding:
    fallback = Understanding(intent="facts", query=message)

    endpoint = roles.endpoint_for("intent", bot, settings)
    if not endpoint.available:
        return fallback

    complete = complete or LLMAdapter.complete

    raw = await complete(
        base_url=endpoint.base_url, api_key=endpoint.api_key,
        model_name=endpoint.model, system_prompt=PROMPT,
        user_message=build_input(message, history),
        max_tokens=200, response_format={"type": "json_object"},
        merge_system=endpoint.merge_system)

    parsed = parse(raw, message)
    if parsed is None:
        print(f"[Intent] Unusable reply, searching with the message as sent: {(raw or '')[:120]!r}")
        return fallback

    # A first message has nothing to resolve, so a rewrite can only lose
    # something. A small model translated a Malay question into English, and a
    # knowledge base that answered it in Malay stopped finding the answer.
    query = parsed.query if earlier_turns(message, history) else message

    return Understanding(parsed.intent, query, ran=True, model=endpoint.model)


async def decide(bot, message: str, history: list[dict], settings: dict,
                 enabled: dict, understand_fn=None) -> Decision:
    """Whether to search, and for what.

    The word rules run first and stay the fast path: a greeting never costs a
    model call. Only a message they would search, on a bot with the switch on
    and a source to search, is read by the intent model.
    """
    is_question = should_retrieve(message)

    if not (is_question and bot.intent_enabled and any(enabled.values())):
        return Decision(is_question=is_question, query=message)

    understanding = await (understand_fn or understand)(bot, message, history, settings)

    if not understanding.ran:
        return Decision(is_question=True, query=message)

    return Decision(is_question=understanding.intent == "facts",
                    query=understanding.query, intent=understanding.intent,
                    model=understanding.model)
