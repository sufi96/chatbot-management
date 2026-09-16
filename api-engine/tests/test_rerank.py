"""The rerank protocol, and scores that mean one thing on any server."""
import json
import math

import httpx
import pytest

from database import SETTING_DEFAULTS
from kb.rerank import RerankClient, calibrate, client_for


def test_probabilities_are_left_alone():
    assert calibrate([0.9, 0.1, 0.0, 1.0]) == [0.9, 0.1, 0.0, 1.0]


def test_raw_logits_are_squashed_into_probabilities():
    scores = calibrate([4.0, -3.0])

    assert abs(scores[0] - 1 / (1 + math.exp(-4.0))) < 1e-9
    assert abs(scores[1] - 1 / (1 + math.exp(3.0))) < 1e-9


def test_one_score_out_of_range_squashes_them_all():
    """Mixing a raw logit with a probability would compare unlike numbers."""
    scores = calibrate([0.5, 7.0])

    assert scores[0] == pytest.approx(1 / (1 + math.exp(-0.5)))


def test_an_extreme_logit_does_not_overflow():
    scores = calibrate([-10000.0, 10000.0])

    assert scores[0] == pytest.approx(0.0, abs=1e-12)
    assert scores[1] == pytest.approx(1.0)


def test_no_scores_is_no_scores():
    assert calibrate([]) == []


@pytest.mark.asyncio
async def test_rerank_posts_the_cohere_shape():
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["auth"] = request.headers.get("authorization")
        seen["body"] = json.loads(request.read().decode())
        return httpx.Response(200, json={"results": [
            {"index": 0, "relevance_score": 0.2},
            {"index": 1, "relevance_score": 0.9},
        ]})

    client = RerankClient("http://spark-b:8003/v1", "secret", "bge-reranker-v2-m3")
    ranked = await client.rerank("warranty?", ["office hours", "two year warranty"],
                                 transport=httpx.MockTransport(handler))

    assert seen["url"] == "http://spark-b:8003/v1/rerank"
    assert seen["auth"] == "Bearer secret"
    assert seen["body"] == {"model": "bge-reranker-v2-m3", "query": "warranty?",
                            "documents": ["office hours", "two year warranty"], "top_n": 2}
    assert ranked == [(1, 0.9), (0, 0.2)]


@pytest.mark.asyncio
async def test_no_documents_makes_no_request():
    def handler(request):
        raise AssertionError("nothing to rerank")

    client = RerankClient("http://x/v1", "", "m")

    assert await client.rerank("q", [], transport=httpx.MockTransport(handler)) == []


@pytest.mark.asyncio
async def test_an_index_outside_the_documents_is_ignored():
    def handler(request):
        return httpx.Response(200, json={"results": [
            {"index": 5, "relevance_score": 0.99},
            {"index": 0, "relevance_score": 0.4},
        ]})

    client = RerankClient("http://x/v1", "", "m")

    assert await client.rerank("q", ["only one"], transport=httpx.MockTransport(handler)) == [(0, 0.4)]


@pytest.mark.asyncio
async def test_a_failed_response_raises():
    def handler(request):
        return httpx.Response(500, text="boom")

    client = RerankClient("http://x/v1", "", "m")

    with pytest.raises(httpx.HTTPStatusError):
        await client.rerank("q", ["a"], transport=httpx.MockTransport(handler))


def test_a_blank_role_builds_no_client():
    assert client_for(SETTING_DEFAULTS) is None


def test_a_configured_role_builds_a_client_for_it():
    client = client_for({**SETTING_DEFAULTS,
                         "rerank_model_base_url": "http://localhost:8012/v1/",
                         "rerank_model_api_key": "k",
                         "rerank_model_name": "bge-reranker-v2-m3"})

    assert client.model == "bge-reranker-v2-m3"
    assert client.base_url == "http://localhost:8012/v1"
    assert client.api_key == "k"
