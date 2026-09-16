import json

import httpx
import pytest

from websearch.tavily import search

PAYLOAD = {"results": [
    {"title": "Population of Malaysia", "url": "https://www.dosm.gov.my/population",
     "content": "The population was 34.1 million in 2024."},
    {"title": "Malaysia facts", "url": "https://example.org/my",
     "content": "Kuala Lumpur is the capital."},
]}


def capture(status=200, payload=None):
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["auth"] = request.headers.get("authorization")
        seen["body"] = json.loads(request.read())
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
async def test_the_key_travels_as_a_bearer_token():
    seen, handler = capture()
    await search("x", 3, api_key="secret", transport=httpx.MockTransport(handler))

    assert seen["auth"] == "Bearer secret"


@pytest.mark.asyncio
async def test_the_query_and_result_count_are_sent():
    seen, handler = capture()
    await search("warranty length", 4, transport=httpx.MockTransport(handler))

    assert seen["body"]["query"] == "warranty length"
    assert seen["body"]["max_results"] == 4


@pytest.mark.asyncio
async def test_a_country_is_sent_and_its_absence_is_not():
    with_country, handler = capture()
    await search("x", 3, country="MY", transport=httpx.MockTransport(handler))
    assert with_country["body"]["country"] == "malaysia"

    without, handler = capture()
    await search("x", 3, transport=httpx.MockTransport(handler))
    assert "country" not in without["body"]


@pytest.mark.asyncio
async def test_a_rejected_request_raises_for_the_registry_to_catch():
    _, handler = capture(status=401, payload={"detail": "bad key"})

    with pytest.raises(httpx.HTTPStatusError):
        await search("x", 3, api_key="wrong", transport=httpx.MockTransport(handler))
