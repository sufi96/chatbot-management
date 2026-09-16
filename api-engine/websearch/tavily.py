"""Tavily, which returns page text rather than snippets.

It is built for feeding models, so there is no second step to fetch and strip
the pages behind the results.
"""
import httpx

from websearch.result import SearchResult

ENDPOINT = "https://api.tavily.com/search"
TIMEOUT = httpx.Timeout(connect=4.0, read=6.0, write=4.0, pool=4.0)

# Tavily names countries rather than taking a code. Only the places this is
# actually pointed at need to be here; anything unlisted is sent without a bias,
# which is better than sending a code the API will reject.
COUNTRIES = {
    "MY": "malaysia", "SG": "singapore", "ID": "indonesia", "TH": "thailand",
    "PH": "philippines", "VN": "vietnam", "BN": "brunei", "IN": "india",
    "AU": "australia", "GB": "united kingdom", "US": "united states",
}


async def search(query, count, country=None, api_key="", transport=None):
    body = {"query": query, "max_results": int(count)}

    named = COUNTRIES.get((country or "").upper())
    if named:
        body["country"] = named

    headers = {"Content-Type": "application/json"}
    if api_key:
        headers["Authorization"] = f"Bearer {api_key}"

    async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
        response = await client.post(ENDPOINT, json=body, headers=headers)
        response.raise_for_status()
        data = response.json()

    return [
        SearchResult(
            title=item.get("title", ""),
            url=item.get("url", ""),
            text=item.get("content", ""),
        )
        for item in data.get("results", [])[:count]
    ]
