"""Embedding over the OpenAI-compatible path.

One transport serves Ollama and any remote provider, which is why this speaks
/v1/embeddings rather than Ollama's native /api/embed.

Vectors are stored normalised so cosine similarity reduces to a dot product.
"""
import math

import httpx

TIMEOUT = httpx.Timeout(120.0, connect=10.0)


def normalise(vector: list[float]) -> list[float]:
    length = math.sqrt(sum(x * x for x in vector))
    if length == 0.0:
        return list(vector)
    return [x / length for x in vector]


class EmbeddingClient:
    def __init__(self, base_url: str, api_key: str, model: str):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key or ""
        self.model = model

    async def embed(self, texts: list[str], transport=None) -> list[list[float]]:
        if not texts:
            return []

        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"

        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            response = await client.post(
                f"{self.base_url}/embeddings",
                headers=headers,
                json={"model": self.model, "input": texts},
            )
            response.raise_for_status()
            payload = response.json()

        return [normalise(row["embedding"]) for row in payload["data"]]

    async def list_models(self, transport=None) -> list[str]:
        """Model ids the provider offers, embedding-looking ones first.

        The OpenAI-compatible response carries no flag marking which models can
        embed, so the ordering is a hint. It must never be a filter: a provider
        may name an embedding model without the word in it.
        """
        headers = {}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"

        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            response = await client.get(f"{self.base_url}/models", headers=headers)
            response.raise_for_status()
            payload = response.json()

        ids = [row["id"] for row in payload.get("data", []) if row.get("id")]
        return sorted(ids, key=lambda name: (0 if "embed" in name.lower() else 1,
                                             name.lower()))
