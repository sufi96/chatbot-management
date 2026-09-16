"""Find the passages that should inform an answer.

Two branches run over the same corpus: dense vectors catch paraphrase, keywords
catch exact terms such as product codes. Their scores are not comparable, so
they are merged by rank.
"""
from dataclasses import dataclass

from database import get_settings
from kb.embedding import client_for
from kb.fusion import fuse_rankings
from kb.store import make_store

NO_CONTEXT_INSTRUCTION = (
    "\n\nNothing in the available material answers this question. Say that the "
    "answer is not in the available material rather than guessing."
)

# How many fused candidates a reranker reads. It scores each against the
# question, so this is the knob between latency and a passage fusion ranked low.
RERANK_CANDIDATES = 40


@dataclass
class RetrievedChunk:
    chunk_id: int
    source_id: str
    content: str
    score: float
    heading_path: str = ""
    # True when score is a reranker's, between 0 and 1, rather than a fusion rank.
    reranked: bool = False
    # The passage's cosine similarity to the question, or None when only the
    # keyword branch found it.
    similarity: float | None = None


async def retrieve_for_collections(session, collection_ids, query, mode="hybrid",
                                   top_k=5, candidates=30, min_score=0.0,
                                   embedder=None, reranker=None,
                                   rerank_min_score=0.0,
                                   min_similarity=0.0) -> list[RetrievedChunk]:
    if not collection_ids or not query.strip():
        return []

    settings = await get_settings(session)
    store = make_store(session)

    branches: list[list[str]] = []
    by_id: dict[str, RetrievedChunk] = {}
    best_similarity = None

    if mode in ("hybrid", "vector"):
        client = embedder or client_for(settings)
        query_vector = (await client.embed([query]))[0]
        hits = await store.search_vector(collection_ids, query_vector, candidates,
                                         settings["embedding_model"])
        branches.append([str(h.chunk_id) for h in hits])
        best_similarity = max((h.score for h in hits), default=None)
        for h in hits:
            by_id[str(h.chunk_id)] = RetrievedChunk(h.chunk_id, h.source_id, h.content,
                                                    h.score, h.heading_path,
                                                    similarity=h.score)

    if mode in ("hybrid", "keyword"):
        hits = await store.search_keyword(collection_ids, query, candidates)
        branches.append([str(h.chunk_id) for h in hits])
        for h in hits:
            by_id.setdefault(str(h.chunk_id),
                             RetrievedChunk(h.chunk_id, h.source_id, h.content,
                                            h.score, h.heading_path))

    fused = fuse_rankings(branches)

    if reranker is not None and fused:
        reranked = await _rerank(reranker, query, fused, by_id, top_k, rerank_min_score)
        if reranked is not None:
            return reranked

    # Fusion orders passages but cannot say that none of them answers: in a
    # small collection both branches rank nearly every chunk, so an unrelated
    # question scored exactly as a real one, the knowledge base always answered
    # first, and the sources after it were never asked. The best raw similarity
    # can say it. A reranker, when one ran, is the better judge and has already
    # returned above; keyword search has no similarity to hold to a floor.
    if min_similarity > 0 and mode in ("hybrid", "vector"):
        if best_similarity is None or best_similarity < min_similarity:
            return []

    out: list[RetrievedChunk] = []
    for chunk_id, score in fused:
        if score < min_score:
            continue
        chunk = by_id[chunk_id]
        out.append(RetrievedChunk(chunk.chunk_id, chunk.source_id, chunk.content,
                                  score, chunk.heading_path, similarity=chunk.similarity))
        if len(out) >= top_k:
            break
    return out


async def _rerank(reranker, query, fused, by_id, top_k, floor) -> list[RetrievedChunk] | None:
    """The fused candidates in the reranker's order, or None when it failed.

    None sends retrieval back to the fusion order and its own floor, which is
    exactly what happened before a reranker was configured. A reranker that
    returns nothing for a non-empty list has failed too: silence is not a
    verdict that every passage is irrelevant.
    """
    candidates = [by_id[chunk_id] for chunk_id, _ in fused[:RERANK_CANDIDATES]]

    try:
        ranked = await reranker.rerank(query, [chunk.content for chunk in candidates])
    except Exception as error:
        print(f"[Rerank] Failed, keeping the fusion order: {error}")
        return None

    if not ranked:
        print("[Rerank] Returned no scores, keeping the fusion order.")
        return None

    out: list[RetrievedChunk] = []
    for index, score in ranked:
        # Best first, so the first passage under the floor ends the list.
        if score < floor:
            break
        chunk = candidates[index]
        out.append(RetrievedChunk(chunk.chunk_id, chunk.source_id, chunk.content,
                                  score, chunk.heading_path, reranked=True,
                                  similarity=chunk.similarity))
        if len(out) >= top_k:
            break

    return out


def fit_to_budget(chunks: list[RetrievedChunk], budget: int) -> list[RetrievedChunk]:
    """The highest-ranked chunks that fit inside a character budget.

    The first is always kept: one long passage beats no passage at all. Trimming
    here rather than inside the context block is what keeps the prompt and the
    citations shown to the visitor in agreement.
    """
    kept: list[RetrievedChunk] = []
    used = 0
    for chunk in chunks:
        if kept and used + len(chunk.content) > budget:
            break
        kept.append(chunk)
        used += len(chunk.content)
    return kept


def build_context_block(chunks: list[RetrievedChunk], titles: dict[str, str]) -> str:
    if not chunks:
        return ""
    parts = ["Use the following context to answer. Cite the sources you use as [1], [2].", ""]
    for n, chunk in enumerate(chunks, start=1):
        parts.append(f"[{n}] {titles.get(chunk.source_id, 'Untitled')}")
        parts.append(chunk.content)
        parts.append("")
    return "\n".join(parts).strip()


def augment_system_prompt(prompt: str, context: str, fallback: str) -> str:
    base = (prompt or "").strip()
    if context:
        return f"{base}\n\n{context}".strip()
    if fallback == "say_unknown":
        return f"{base}{NO_CONTEXT_INSTRUCTION}".strip()
    return base
