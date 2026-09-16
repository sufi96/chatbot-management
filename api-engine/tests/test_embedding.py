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


def vectors_of(length, count=1):
    return {"data": [{"embedding": [1.0] + [0.0] * (length - 1)} for _ in range(count)]}


@pytest.mark.asyncio
async def test_the_configured_size_is_asked_for():
    """Qwen3-Embedding returns 4096 numbers unless asked for fewer, and a
    pgvector column holds one width only."""
    seen = {}

    def handler(request):
        seen["body"] = json.loads(request.read().decode())
        return httpx.Response(200, json=vectors_of(1024))

    client = EmbeddingClient("http://fake/v1", "", "qwen3-embedding-4b", dimensions=1024)
    await client.embed(["one"], transport=httpx.MockTransport(handler))

    assert seen["body"]["dimensions"] == 1024


@pytest.mark.asyncio
async def test_no_size_is_asked_for_when_none_is_configured():
    seen = {}

    def handler(request):
        seen["body"] = json.loads(request.read().decode())
        return httpx.Response(200, json=vectors_of(768))

    await EmbeddingClient("http://fake/v1", "", "m").embed(
        ["one"], transport=httpx.MockTransport(handler))

    assert "dimensions" not in seen["body"]


@pytest.mark.asyncio
async def test_an_endpoint_that_refuses_a_size_is_asked_again_without_it():
    """vLLM refuses dimensions for a model that cannot shorten its vectors. The
    vector it returns then still has to match, which the next check enforces."""
    bodies = []

    def handler(request):
        bodies.append(json.loads(request.read().decode()))
        if "dimensions" in bodies[-1]:
            return httpx.Response(400, text="Model does not support matryoshka representation; "
                                            "dimensions is not supported")
        return httpx.Response(200, json=vectors_of(768))

    client = EmbeddingClient("http://fake/v1", "", "m", dimensions=768)
    vectors = await client.embed(["one"], transport=httpx.MockTransport(handler))

    assert len(bodies) == 2
    assert "dimensions" not in bodies[1]
    assert len(vectors[0]) == 768


@pytest.mark.asyncio
async def test_a_vector_of_the_wrong_size_is_refused_with_both_numbers():
    def handler(request):
        return httpx.Response(200, json=vectors_of(4096))

    client = EmbeddingClient("http://fake/v1", "", "qwen3-embedding-8b", dimensions=1024)

    with pytest.raises(ValueError) as raised:
        await client.embed(["one"], transport=httpx.MockTransport(handler))

    assert "4096" in str(raised.value)
    assert "1024" in str(raised.value)


def test_a_client_is_built_from_the_install_settings():
    from kb.embedding import client_for

    client = client_for({"embedding_base_url": "http://spark-b:8002/v1/",
                         "embedding_api_key": "k",
                         "embedding_model": "qwen3-embedding-4b",
                         "embedding_dimensions": "1024"})

    assert client.base_url == "http://spark-b:8002/v1"
    assert client.api_key == "k"
    assert client.model == "qwen3-embedding-4b"
    assert client.dimensions == 1024


@pytest.mark.parametrize("value", ["", "0", None, "not a number"])
def test_a_blank_or_unusable_size_asks_for_none(value):
    from kb.embedding import client_for

    client = client_for({"embedding_base_url": "http://x/v1", "embedding_api_key": "",
                         "embedding_model": "m", "embedding_dimensions": value})

    assert client.dimensions is None


@pytest.mark.asyncio
async def test_list_models_raises_on_a_failed_response():
    def handler(request):
        return httpx.Response(500, text="boom")

    client = EmbeddingClient("http://engine/v1", "", "m")
    with pytest.raises(httpx.HTTPStatusError):
        await client.list_models(transport=httpx.MockTransport(handler))
