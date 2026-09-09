"""Find the passages that should inform an answer.

Two branches run over the same corpus: dense vectors catch paraphrase, keywords
catch exact terms such as product codes. Their scores are not comparable, so
they are merged by rank.
"""
from dataclasses import dataclass

from database import get_settings
from kb.embedding import EmbeddingClient
from kb.fusion import fuse_rankings
from kb.store import make_store

NO_CONTEXT_INSTRUCTION = (
    "\n\nNothing in the available material answers this question. Say that the "
    "answer is not in the available material rather than guessing."
)


@dataclass
class RetrievedChunk:
    chunk_id: int
    source_id: str
    content: str
    score: float
    heading_path: str = ""


async def retrieve_for_collections(session, collection_ids, query, mode="hybrid",
                                   top_k=5, candidates=30, min_score=0.0,
                                   embedder=None) -> list[RetrievedChunk]:
    if not collection_ids or not query.strip():
        return []

    settings = await get_settings(session)
    store = make_store(session, settings["vector_driver"])

    branches: list[list[str]] = []
    by_id: dict[str, RetrievedChunk] = {}

    if mode in ("hybrid", "vector"):
        client = embedder or EmbeddingClient(
            settings["embedding_base_url"],
            settings["embedding_api_key"],
            settings["embedding_model"],
        )
        query_vector = (await client.embed([query]))[0]
        hits = await store.search_vector(collection_ids, query_vector, candidates,
                                         settings["embedding_model"])
        branches.append([str(h.chunk_id) for h in hits])
        for h in hits:
            by_id[str(h.chunk_id)] = RetrievedChunk(h.chunk_id, h.source_id, h.content,
                                                    h.score, h.heading_path)

    if mode in ("hybrid", "keyword"):
        hits = await store.search_keyword(collection_ids, query, candidates)
        branches.append([str(h.chunk_id) for h in hits])
        for h in hits:
            by_id.setdefault(str(h.chunk_id),
                             RetrievedChunk(h.chunk_id, h.source_id, h.content,
                                            h.score, h.heading_path))

    fused = fuse_rankings(branches)

    out: list[RetrievedChunk] = []
    for chunk_id, score in fused:
        if score < min_score:
            continue
        chunk = by_id[chunk_id]
        out.append(RetrievedChunk(chunk.chunk_id, chunk.source_id, chunk.content,
                                  score, chunk.heading_path))
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
