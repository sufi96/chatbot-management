"""One attempt per source.

Each wraps work that already existed and answers one question the cascade asks:
did you produce something worth answering from. The dependencies are injected so
the branching can be tested without a model, a database or an HTTP request, the
way should_retrieve already is.
"""
from sqlalchemy import select

import dbquery
import websearch
from kb import rerank
from kb.retrieval import build_context_block, fit_to_budget, retrieve_for_collections
from sources.result import SourceResult
from websearch.context import build_web_context_block, fit_results_to_budget


def key_for(settings: dict) -> str:
    """The API key belonging to whichever provider is configured.

    DuckDuckGo has no key setting, so this returns an empty string for it,
    which is exactly what the adapter expects.
    """
    provider = settings.get("web_search_provider", "duckduckgo")

    return settings.get(f"web_search_{provider}_key", "")


async def _titles_for(session, chunks) -> dict:
    from database import KbSource

    rows = await session.execute(
        select(KbSource.id, KbSource.title).where(
            KbSource.id.in_([chunk.source_id for chunk in chunks])))

    return {row[0]: row[1] for row in rows.all()}


async def documents(session, bot, message: str, settings: dict, collection_ids,
                    retrieve=None, load_titles=None, make_reranker=None) -> SourceResult:
    retrieve = retrieve or retrieve_for_collections
    load_titles = load_titles or _titles_for

    # Built only when the install configured one. Blank means fusion order and
    # the RRF floor, exactly as before the rerank role existed.
    reranker = (make_reranker or rerank.client_for)(settings)

    chunks = await retrieve(
        session=session, collection_ids=collection_ids, query=message,
        mode=bot.retrieval_mode or "hybrid",
        top_k=bot.retrieval_top_k or 5,
        candidates=bot.retrieval_candidates or 30,
        min_score=bot.retrieval_min_score or 0.0,
        reranker=reranker,
        rerank_min_score=getattr(bot, "rerank_min_score", None) or 0.0,
        min_similarity=getattr(bot, "retrieval_min_similarity", None) or 0.0)

    # Bigger chunks mean a bigger prompt. Trim before the titles are looked up
    # so the citations match what the model actually saw.
    chunks = fit_to_budget(chunks, int(settings["context_char_budget"]))

    if not chunks:
        return SourceResult(kind="documents")

    titles = await load_titles(session, chunks)

    return SourceResult(
        kind="documents",
        context_block=build_context_block(chunks, titles),
        citations=[{"n": i + 1, "title": titles.get(chunk.source_id, "Untitled"),
                    "source_id": chunk.source_id}
                   for i, chunk in enumerate(chunks)],
        reranked_by=reranker.model if (reranker and getattr(chunks[0], "reranked", False)) else "",
        has_content=True)


async def database(session, bot, message: str, settings: dict, ask=None) -> SourceResult:
    ask = ask or dbquery.answer

    found = await ask(session, bot, message, settings)

    if not found.ran:
        return SourceResult(kind="database")

    return SourceResult(
        kind="database",
        context_block=found.context_block,
        # The connection's name and nothing else. A visitor on a public site
        # must not learn the table names, let alone the statement.
        citations=[{"n": 1, "title": found.connection_name}],
        sql=found.sql,
        row_count=found.row_count,
        has_content=True)


async def web(bot, message: str, settings: dict, search=None) -> SourceResult:
    search = search or websearch.search

    results = await search(
        provider=settings["web_search_provider"], query=message,
        count=int(bot.web_search_max_results or 3),
        country=bot.web_search_country, api_key=key_for(settings))

    results = fit_results_to_budget(results, int(settings["context_char_budget"]))

    if not results:
        return SourceResult(kind="web")

    return SourceResult(
        kind="web",
        context_block=build_web_context_block(results),
        citations=[{"n": i + 1, "title": item.title, "url": item.url}
                   for i, item in enumerate(results)],
        has_content=True)
