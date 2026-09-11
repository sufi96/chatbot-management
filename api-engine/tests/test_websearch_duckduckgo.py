import httpx
import pytest

from websearch.duckduckgo import search

# A trimmed copy of the real result list. DuckDuckGo wraps every link in a
# redirect that carries the destination in a uddg query parameter.
SAMPLE = """
<html><body>
<div class="result results_links results_links_deep web-result">
  <div class="links_main links_deep result__body">
    <h2 class="result__title">
      <a rel="nofollow" class="result__a"
         href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.dosm.gov.my%2Fpopulation&amp;rut=abc">
        Population of Malaysia
      </a>
    </h2>
    <a class="result__snippet" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fwww.dosm.gov.my%2Fpopulation">
      The population was <b>34.1 million</b> in 2024.
    </a>
  </div>
</div>
<div class="result results_links results_links_deep web-result">
  <div class="links_main links_deep result__body">
    <h2 class="result__title">
      <a rel="nofollow" class="result__a"
         href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.org%2Fmy">Malaysia facts</a>
    </h2>
    <a class="result__snippet">Kuala Lumpur is the capital.</a>
  </div>
</div>
</body></html>
"""


def handler_returning(html, seen=None):
    def handler(request: httpx.Request) -> httpx.Response:
        if seen is not None:
            seen["url"] = str(request.url)
            seen["body"] = request.read().decode()
        return httpx.Response(200, text=html)
    return handler


@pytest.mark.asyncio
async def test_results_carry_title_url_and_text():
    results = await search("population of malaysia", 5,
                           transport=httpx.MockTransport(handler_returning(SAMPLE)))

    assert len(results) == 2
    assert results[0].title == "Population of Malaysia"
    assert results[0].url == "https://www.dosm.gov.my/population"
    assert "34.1 million" in results[0].text


@pytest.mark.asyncio
async def test_the_redirect_wrapper_is_unwrapped():
    results = await search("x", 5, transport=httpx.MockTransport(handler_returning(SAMPLE)))

    assert not any("duckduckgo.com/l/" in r.url for r in results)


@pytest.mark.asyncio
async def test_no_more_results_than_asked_for():
    results = await search("x", 1, transport=httpx.MockTransport(handler_returning(SAMPLE)))

    assert len(results) == 1


@pytest.mark.asyncio
async def test_a_country_becomes_a_region_parameter():
    seen = {}
    await search("x", 3, country="MY",
                 transport=httpx.MockTransport(handler_returning(SAMPLE, seen)))

    assert "kl=my-en" in seen["body"]


@pytest.mark.asyncio
async def test_no_country_sends_no_region():
    seen = {}
    await search("x", 3, transport=httpx.MockTransport(handler_returning(SAMPLE, seen)))

    assert "kl=" not in seen["body"]


@pytest.mark.asyncio
async def test_a_page_with_no_results_gives_an_empty_list():
    handler = handler_returning("<html><body><p>nothing</p></body></html>")
    assert await search("x", 3, transport=httpx.MockTransport(handler)) == []


@pytest.mark.asyncio
async def test_a_rate_limit_interstitial_is_a_failure_not_an_empty_result():
    """DuckDuckGo answers 202 with a redirect page when it is rate limiting.

    That is not the same as finding nothing, and reporting it as no results
    would hide the reason the bot stopped citing sources.
    """
    def handler(request):
        return httpx.Response(202, text="<html><head></head><body></body></html>")

    with pytest.raises(httpx.HTTPStatusError):
        await search("x", 3, transport=httpx.MockTransport(handler))
