"""Consulting a bot's sources in the order its operator set.

There is no router here on purpose. A model that picks the source is right most
of the time and unpredictable the rest, and an operator cannot configure it,
predict it, or explain an answer that came from the wrong place. An order can be
held in a person's head. See
docs/superpowers/specs/2026-09-14-answer-source-order-design.md section 4.
"""
from functools import partial

from sources import attempts as attempt_module
from sources.order import normalise  # noqa: F401  re-exported for the chat route
from sources.result import SourceResult


def enabled_for(bot) -> dict[str, bool]:
    """Which sources this bot has switched on at all.

    A connection that is missing or has no readable tables is not checked here.
    The database attempt discovers that and reports a miss, which is the same
    outcome by a cheaper route.
    """
    return {
        "documents": bool(bot.retrieval_enabled),
        "database": bool(bot.db_query_enabled),
        "web": bool(bot.web_search_enabled),
    }


def fallback_for(bot, message_is_a_question: bool, enabled: dict) -> str:
    """What the prompt should tell the model to do when nothing was found.

    A gated message must never be told the answer is missing from the material,
    and neither must a bot that was never given material to look in. Both get a
    plain answer instead.
    """
    if not message_is_a_question or not any(enabled.values()):
        return "answer_anyway"

    return bot.retrieval_fallback or "say_unknown"


def build_attempts(session, bot, message: str, settings: dict, collection_ids) -> dict:
    return {
        "documents": partial(attempt_module.documents, session, bot, message,
                             settings, collection_ids),
        "database": partial(attempt_module.database, session, bot, message, settings),
        "web": partial(attempt_module.web, bot, message, settings),
    }


async def resolve(order: list[str], enabled: dict, attempts: dict) -> SourceResult | None:
    """The first source in the order with something to say, or nothing.

    A source that fails is a source that did not answer. The next one in the
    operator's order gets its turn, so the order is honoured in failure as well
    as in success and no source failure can break a conversation.
    """
    for name in order:
        if not enabled.get(name):
            continue

        attempt = attempts.get(name)
        if attempt is None:
            continue

        try:
            result = await attempt()
        except Exception as error:
            print(f"[Sources] {name} failed, trying the next: {error}")
            continue

        if result and result.has_content:
            return result

    return None
