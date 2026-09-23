"""Consulting a bot's sources in the order its operator set.

There is no router here on purpose. A model that picks the source is right most
of the time and unpredictable the rest, and an operator cannot configure it,
predict it, or explain an answer that came from the wrong place. An order can be
held in a person's head. See
docs/superpowers/specs/2026-09-14-answer-source-order-design.md section 4.
"""
import asyncio
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


def answer_kind(found: SourceResult | None, searched: bool, refused: bool) -> str:
    """Where an answer came from, in one word, for the analytics page.

    A source's own kind when one answered. "none" when the sources were asked
    and had nothing, which is a gap in what the bot was given. "model" when no
    source was asked at all, and "refused" when the guard answered instead.
    """
    if refused:
        return "refused"
    if found is not None:
        return found.kind
    return "none" if searched else "model"


def build_attempts(session, bot, message: str, settings: dict, collection_ids) -> dict:
    return {
        "documents": partial(attempt_module.documents, session, bot, message,
                             settings, collection_ids),
        "database": partial(attempt_module.database, session, bot, message, settings),
        "web": partial(attempt_module.web, bot, message, settings),
    }


# The sources a bot set to combine asks together. The web stays a fallback:
# it is the slowest and the least the operator's own, so it is asked only
# when neither of these had anything.
COMBINED = ("documents", "database")


async def _try(name, attempts: dict) -> SourceResult | None:
    """One source's result, or nothing when it failed or had nothing."""
    attempt = attempts.get(name)
    if attempt is None:
        return None

    try:
        result = await attempt()
    except Exception as error:
        print(f"[Sources] {name} failed, trying the next: {error}")
        return None

    return result if (result and result.has_content) else None


def merge(results: list[SourceResult]) -> SourceResult:
    """Several sources' material as one, numbered as one list.

    Each source numbers its own citations from 1, so they are renumbered here
    and the database block gets the [n] heading its own block never needed.
    Each citation keeps its kind, so the widget can mark every chip.
    """
    if len(results) == 1:
        return results[0]

    blocks, citations = [], []
    merged = SourceResult(kind="combined", has_content=True)

    for result in results:
        first = len(citations) + 1
        for citation in result.citations:
            citations.append({**citation, "n": len(citations) + 1, "kind": result.kind})

        block = result.context_block
        if result.kind == "database" and result.citations:
            block = f"[{first}] {result.citations[0]['title']}\n{block}"
        blocks.append(block)

        merged.sql = merged.sql or result.sql
        merged.row_count = merged.row_count or result.row_count
        merged.reranked_by = merged.reranked_by or result.reranked_by

    merged.context_block = "\n\n".join(blocks)
    merged.citations = citations
    return merged


async def resolve(order: list[str], enabled: dict, attempts: dict,
                  combine: bool = False) -> SourceResult | None:
    """The first source in the order with something to say, or nothing.

    A source that fails is a source that did not answer. The next one in the
    operator's order gets its turn, so the order is honoured in failure as well
    as in success and no source failure can break a conversation.

    With combine, the knowledge base and the database are asked at once and
    everything either found is answered from, so a question needing a policy
    and a record gets both. The order still decides which comes first in the
    prompt. The rest of the order is walked only when both had nothing.
    """
    if combine:
        together = [name for name in order if name in COMBINED and enabled.get(name)]
        found = [result for result in await asyncio.gather(
            *(_try(name, attempts) for name in together)) if result]
        if found:
            return merge(found)
        order = [name for name in order if name not in together]

    for name in order:
        if not enabled.get(name):
            continue

        result = await _try(name, attempts)
        if result:
            return result

    return None
