"""Scoring retrieved passages against the question, over the rerank protocol.

An embedding model reads a question and a passage separately, so it matches
topic. A reranker reads the two together and says how well this passage
answers this question, which is the number a relevance floor actually needs.

vLLM and llama.cpp both serve the Cohere-shaped POST {base}/rerank. vLLM
returns scores already between 0 and 1; a llama.cpp build may return the
model's raw logits instead. Scores outside that range go through a sigmoid, so
one floor means the same thing on either server.
"""
import math

import httpx

import roles

TIMEOUT = httpx.Timeout(30.0, connect=5.0)

# Beyond this a sigmoid is 0 or 1 to every digit that matters, and math.exp
# would overflow long before a real model produced such a logit.
_LOGIT_LIMIT = 50.0


def calibrate(scores: list[float]) -> list[float]:
    """Scores between 0 and 1, whichever server produced them.

    All or nothing: one raw logit among probabilities means the whole list is
    raw, and squashing only some would compare unlike numbers.
    """
    if all(0.0 <= score <= 1.0 for score in scores):
        return list(scores)

    return [1.0 / (1.0 + math.exp(-max(-_LOGIT_LIMIT, min(_LOGIT_LIMIT, score))))
            for score in scores]


class RerankClient:
    def __init__(self, base_url: str, api_key: str, model: str):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key or ""
        self.model = model

    async def rerank(self, query: str, documents: list[str],
                     transport=None) -> list[tuple[int, float]]:
        """(index into documents, score) pairs, best first."""
        if not documents:
            return []

        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"

        async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
            response = await client.post(
                f"{self.base_url}/rerank",
                headers=headers,
                json={"model": self.model, "query": query,
                      "documents": documents, "top_n": len(documents)},
            )
            response.raise_for_status()
            payload = response.json()

        rows = [(int(row["index"]), float(row["relevance_score"]))
                for row in payload.get("results", [])]
        rows = [(index, score) for index, score in rows if 0 <= index < len(documents)]

        scores = calibrate([score for _, score in rows])
        ranked = [(index, score) for (index, _), score in zip(rows, scores)]

        return sorted(ranked, key=lambda pair: pair[1], reverse=True)


def client_for(settings: dict) -> RerankClient | None:
    """A client for the install's reranker, or None when none is configured.

    The rerank role has no stand-in, so it never needs a bot to fall back on.
    """
    endpoint = roles.endpoint_for("rerank", None, settings)
    if not endpoint.available:
        return None

    return RerankClient(endpoint.base_url, endpoint.api_key, endpoint.model)
