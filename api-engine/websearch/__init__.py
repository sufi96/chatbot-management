"""Web search, as one call that never fails loudly.

A search sits between a visitor's message and the first word of their answer.
Every way it can go wrong, an unknown provider, a rejected key, a rate limit, a
slow reply, ends the same way here: nothing is returned and the answer is built
without it. That is the same trade the knowledge base already makes.
"""
from websearch import brave, duckduckgo, tavily
from websearch.result import SearchResult

PROVIDERS = {
    "duckduckgo": duckduckgo.search,
    "tavily": tavily.search,
    "brave": brave.search,
}

__all__ = ["PROVIDERS", "SearchResult", "search"]


async def search(provider, query, count, country=None, api_key="", transport=None):
    adapter = PROVIDERS.get((provider or "").strip().lower())
    if adapter is None:
        print(f"[WebSearch] Unknown provider {provider!r}. Search skipped.")
        return []

    try:
        return await adapter(query, count, country, api_key, transport)
    except Exception as error:
        print(f"[WebSearch] {provider} search failed, answering without it: {error}")
        return []
