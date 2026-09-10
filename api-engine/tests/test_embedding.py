import json
import math

import httpx
import pytest

from kb.embedding import EmbeddingClient, normalise


def test_normalise_gives_unit_length():
    out = normalise([3.0, 4.0])
    assert abs(math.sqrt(sum(x * x for x in out)) - 1.0) < 1e-9
    assert abs(out[0] - 0.6) < 1e-9


def test_normalise_leaves_a_zero_vector_alone():
    assert normalise([0.0, 0.0]) == [0.0, 0.0]


@pytest.mark.asyncio
async def test_embed_posts_to_the_openai_shape_and_normalises():
    seen = {}

    def handler(request: httpx.Request) -> httpx.Response:
        seen["url"] = str(request.url)
        seen["auth"] = request.headers.get("authorization")
        seen["body"] = request.read().decode()
        return httpx.Response(200, json={"data": [
            {"embedding": [3.0, 4.0]},
            {"embedding": [0.0, 5.0]},
        ]})

    client = EmbeddingClient("http://fake/v1", "secret", "nomic-embed-text")
    vectors = await client.embed(["one", "two"], transport=httpx.MockTransport(handler))

    assert seen["url"] == "http://fake/v1/embeddings"
    assert seen["auth"] == "Bearer secret"
    body = json.loads(seen["body"])
    assert body["model"] == "nomic-embed-text"
    assert body["input"] == ["one", "two"]
    assert abs(vectors[0][0] - 0.6) < 1e-9
    assert vectors[1] == [0.0, 1.0]


@pytest.mark.asyncio
async def test_embed_with_no_texts_makes_no_request():
    def handler(request):
        raise AssertionError("should not have been called")

    client = EmbeddingClient("http://fake/v1", "", "m")
    assert await client.embed([], transport=httpx.MockTransport(handler)) == []


@pytest.mark.asyncio
async def test_embed_raises_on_a_bad_response():
    def handler(request):
        return httpx.Response(500, text="boom")

    client = EmbeddingClient("http://fake/v1", "", "m")
    with pytest.raises(httpx.HTTPStatusError):
        await client.embed(["x"], transport=httpx.MockTransport(handler))


@pytest.mark.asyncio
async def test_list_models_puts_embedding_models_first():
    def handler(request):
        assert request.url.path.endswith("/models")
        return httpx.Response(200, json={"data": [
            {"id": "llama3.2:1b"},
            {"id": "nomic-embed-text:latest"},
            {"id": "gemma3:1b"},
            {"id": "mxbai-embed-large"},
        ]})

    client = EmbeddingClient("http://engine/v1", "", "nomic-embed-text")
    models = await client.list_models(transport=httpx.MockTransport(handler))

    assert models[:2] == ["mxbai-embed-large", "nomic-embed-text:latest"]
    assert models[2:] == ["gemma3:1b", "llama3.2:1b"]


@pytest.mark.asyncio
async def test_list_models_sends_the_api_key_when_there_is_one():
    seen = {}

    def handler(request):
        seen["auth"] = request.headers.get("Authorization")
        return httpx.Response(200, json={"data": []})

    client = EmbeddingClient("http://engine/v1", "secret", "m")
    await client.list_models(transport=httpx.MockTransport(handler))

    assert seen["auth"] == "Bearer secret"


@pytest.mark.asyncio
async def test_list_models_ignores_rows_without_an_id():
    def handler(request):
        return httpx.Response(200, json={"data": [{"id": "a"}, {"object": "model"}]})

    client = EmbeddingClient("http://engine/v1", "", "m")
    assert await client.list_models(transport=httpx.MockTransport(handler)) == ["a"]


@pytest.mark.asyncio
async def test_list_models_raises_on_a_failed_response():
    def handler(request):
        return httpx.Response(500, text="boom")

    client = EmbeddingClient("http://engine/v1", "", "m")
    with pytest.raises(httpx.HTTPStatusError):
        await client.list_models(transport=httpx.MockTransport(handler))
