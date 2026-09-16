import httpx
import pytest

from websearch.brave import search

PAYLOAD = {"web": {"results": [
    {"title": "Population of Malaysia", "url": "https://www.dosm.gov.my/population",
     "description": "The population was 34.1 million in 2024."},
    {"title": "Malaysia facts", "url": "https://example.org/my",
     "description": "Kuala Lumpur is the capital."},
]}}


def capture(status=200, payload=None):
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["token"] = request.headers.get("x-subscription-token")
        return httpx.Response(status, json=payload if payload is not None else PAYLOAD)

    return seen, handler


@pytest.mark.asyncio
async def test_results_are_mapped_onto_the_common_shape():
    _, handler = capture()
    results = await search("population of malaysia", 2, api_key="secret",
                           transport=httpx.MockTransport(handler))

    assert len(results) == 2
    assert results[0].title == "Population of Malaysia"
    assert results[0].url == "https://www.dosm.gov.my/population"
    assert "34.1 million" in results[0].text


@pytest.mark.asyncio
async def test_the_key_travels_in_the_subscription_header():
    seen, handler = capture()
    await search("x", 3, api_key="secret", transport=httpx.MockTransport(handler))

    assert seen["token"] == "secret"


@pytest.mark.asyncio
async def test_the_query_and_count_are_sent():
    seen, handler = capture()
    await search("warranty length", 4, transport=httpx.MockTransport(handler))

    assert "q=warranty+length" in seen["url"] or "q=warranty%20length" in seen["url"]
    assert "count=4" in seen["url"]


@pytest.mark.asyncio
async def test_a_country_is_sent_and_its_absence_is_not():
    with_country, handler = capture()
    await search("x", 3, country="MY", transport=httpx.MockTransport(handler))
    assert "country=MY" in with_country["url"]

    without, handler = capture()
    await search("x", 3, transport=httpx.MockTransport(handler))
    assert "country=" not in without["url"]


@pytest.mark.asyncio
async def test_an_empty_response_gives_an_empty_list():
    _, handler = capture(payload={})
    assert await search("x", 3, transport=httpx.MockTransport(handler)) == []
