import httpx
import pytest

import websearch


def ok_html():
    body = ('<div class="result"><a class="result__a" '
            'href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2F">T</a>'
            '<a class="result__snippet">S</a></div>')
    return httpx.MockTransport(lambda request: httpx.Response(200, text=body))


@pytest.mark.asyncio
async def test_the_named_provider_is_the_one_called():
    results = await websearch.search("duckduckgo", "x", 3, transport=ok_html())

    assert len(results) == 1
    assert results[0].url == "https://example.com/"


@pytest.mark.asyncio
async def test_every_provider_name_is_registered():
    assert set(websearch.PROVIDERS) == {"duckduckgo", "tavily", "brave"}


@pytest.mark.asyncio
async def test_an_unknown_provider_disables_search_rather_than_raising():
    assert await websearch.search("altavista", "x", 3, transport=ok_html()) == []


@pytest.mark.asyncio
async def test_a_provider_name_is_read_loosely():
    results = await websearch.search("  DuckDuckGo ", "x", 3, transport=ok_html())

    assert len(results) == 1


@pytest.mark.asyncio
async def test_a_failing_provider_returns_nothing_rather_than_raising():
    broken = httpx.MockTransport(lambda request: httpx.Response(503, text="busy"))

    assert await websearch.search("duckduckgo", "x", 3, transport=broken) == []


@pytest.mark.asyncio
async def test_a_timeout_returns_nothing_rather_than_raising():
    def timeout(request):
        raise httpx.ConnectTimeout("too slow")

    assert await websearch.search("duckduckgo", "x", 3,
                                  transport=httpx.MockTransport(timeout)) == []
