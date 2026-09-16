"""Embedding over the OpenAI-compatible path.

One transport serves Ollama and any remote provider, which is why this speaks
/v1/embeddings rather than Ollama's native /api/embed.

Vectors are stored normalised so cosine similarity reduces to a dot product.

The configured number of dimensions is asked for, not assumed. A model such as
Qwen3-Embedding returns 4096 numbers unless asked for fewer, and a pgvector
column holds exactly one width, so a vector of any other length is refused here
with a message that says what to change, rather than failing inside an insert.
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
    def __init__(self, base_url: str, api_key: str, model: str, dimensions: int | None = None):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key or ""
        self.model = model
        self.dimensions = dimensions

    async def embed(self, texts: list[str], transport=None) -> list[list[float]]:
        if not texts:
            return []

        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"

        body = {"model": self.model, "input": texts}
        if self.dimensions:
            body["dimensions"] = self.dimensions

        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            response = await client.post(f"{self.base_url}/embeddings", headers=headers, json=body)

            # vLLM refuses dimensions for a model that cannot shorten its
            # vectors. Asked again without it, the model's own length still
            # has to match the setting, which the check below enforces.
            if (response.status_code == 400 and "dimensions" in body
                    and "dimensions" in response.text):
                body.pop("dimensions")
                response = await client.post(f"{self.base_url}/embeddings",
                                             headers=headers, json=body)

            response.raise_for_status()
            payload = response.json()

        vectors = [normalise(row["embedding"]) for row in payload["data"]]

        if self.dimensions:
            for vector in vectors:
                if len(vector) != self.dimensions:
                    raise ValueError(
                        f"The embedding model {self.model} returned {len(vector)} dimensions, "
                        f"but the install is set to {self.dimensions}. Set Admin Settings, "
                        f"Embedding, Dimensions to {len(vector)}, or choose a model that can "
                        "shorten its vectors, then rebuild the index.")

        return vectors

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


def client_for(settings: dict) -> EmbeddingClient:
    """The install's embedding client, as indexing and retrieval must share it.

    Both have to ask for the same size, or a question's vector and the stored
    passages' vectors stop being comparable. A blank or unusable size asks for
    none and takes whatever the model returns.
    """
    try:
        dimensions = int(settings.get("embedding_dimensions") or 0)
    except (TypeError, ValueError):
        dimensions = 0

    return EmbeddingClient(settings["embedding_base_url"], settings["embedding_api_key"],
                           settings["embedding_model"], dimensions=dimensions or None)
