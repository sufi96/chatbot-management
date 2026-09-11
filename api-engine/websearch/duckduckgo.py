"""DuckDuckGo, through the HTML endpoint.

There is no official API. This posts to the same endpoint a browser would and
reads the result list out of the markup, which means a redesign on their side
breaks it. The saved fixture in the tests is what turns that into a failing
test rather than a production surprise.

Expect rate limiting under real traffic. That is why the provider is a setting.
"""
import urllib.parse

import httpx
from bs4 import BeautifulSoup

from websearch.result import SearchResult

ENDPOINT = "https://html.duckduckgo.com/html/"
TIMEOUT = httpx.Timeout(connect=4.0, read=6.0, write=4.0, pool=4.0)

# The endpoint returns an empty page to clients that do not look like browsers.
USER_AGENT = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
              "(KHTML, like Gecko) Chrome/124.0 Safari/537.36")


def region_for(country):
    """DuckDuckGo wants a region such as my-en, not a country code."""
    return f"{country.lower()}-en" if country else ""


def unwrap(href: str) -> str:
    """Pull the real destination out of DuckDuckGo's redirect wrapper."""
    if href.startswith("//"):
        href = "https:" + href
    query = urllib.parse.urlparse(href).query
    target = urllib.parse.parse_qs(query).get("uddg")
    return target[0] if target else href


def parse(html: str, count: int) -> list[SearchResult]:
    soup = BeautifulSoup(html, "html.parser")
    results = []

    for node in soup.select("div.result"):
        link = node.select_one("a.result__a")
        if not link:
            continue

        snippet = node.select_one(".result__snippet")
        results.append(SearchResult(
            title=link.get_text(" ", strip=True),
            url=unwrap(link.get("href", "")),
            text=snippet.get_text(" ", strip=True) if snippet else "",
        ))

        if len(results) >= count:
            break

    return results


async def search(query, count, country=None, api_key="", transport=None):
    form = {"q": query}
    region = region_for(country)
    if region:
        form["kl"] = region

    async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
        response = await client.post(ENDPOINT, data=form,
                                     headers={"User-Agent": USER_AGENT})
        response.raise_for_status()

        # Rate limiting arrives as 202 carrying a redirect page rather than as
        # an error status, so raise_for_status sails past it. Saying nothing
        # was found would hide the real reason the bot stopped citing sources.
        if response.status_code != 200:
            raise httpx.HTTPStatusError(
                f"DuckDuckGo answered {response.status_code}, which means rate limited",
                request=response.request, response=response)

        html = response.text

    return parse(html, count)
