"""Brave, which runs its own index rather than reselling another.

It returns descriptions rather than page text, so answers built on it are built
on snippets.
"""
import httpx

from websearch.result import SearchResult

ENDPOINT = "https://api.search.brave.com/res/v1/web/search"
TIMEOUT = httpx.Timeout(connect=4.0, read=6.0, write=4.0, pool=4.0)


async def search(query, count, country=None, api_key="", transport=None):
    params = {"q": query, "count": int(count)}
    if country:
        params["country"] = country.upper()

    headers = {"Accept": "application/json"}
    if api_key:
        headers["X-Subscription-Token"] = api_key

    async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
        response = await client.get(ENDPOINT, params=params, headers=headers)
        response.raise_for_status()
        data = response.json()

    items = (data.get("web") or {}).get("results") or []
    return [
        SearchResult(
            title=item.get("title", ""),
            url=item.get("url", ""),
            text=item.get("description", ""),
        )
        for item in items[:count]
    ]
