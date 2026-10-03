"""Find the passages that should inform an answer.

Two branches run over the same corpus: dense vectors catch paraphrase, keywords
catch exact terms such as product codes. Their scores are not comparable, so
they are merged by rank.
"""
from dataclasses import dataclass

from sqlalchemy import bindparam, text

import shield
import spotlight
from database import get_settings
from kb import bm25
from kb.chunking import ABOUT_PREFIX, BREADCRUMB_PREFIX, CONTEXT_PREFIX
from kb.embedding import client_for
from kb.fusion import fuse_rankings
from kb.store import make_store

HEADER_PREFIXES = (BREADCRUMB_PREFIX, ABOUT_PREFIX, CONTEXT_PREFIX)

KB_LEAD = "Use the following context to answer. Cite the sources you use as [1], [2]."

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
                                   min_similarity=0.0, keyword_weight=1.0,
                                   expansion=None, neighbours=0,
                                   query_vector=None) -> list[RetrievedChunk]:
    """The passages to answer from, best first.

    Every phrasing is searched by meaning and by keyword: the question itself,
    and the rephrasings query expansion wrote. A hypothetical answer passage
    (HyDE) is searched by meaning only. All of it is merged by rank, then
    screened for injected instructions, reranked when a reranker is set, cut to
    top_k, and widened with neighbouring passages when the bot asks for them.

    query_vector is the question's embedding when the caller already has it,
    as the answer cache does, so it is not computed twice.
    """
    if not collection_ids or not query.strip():
        return []

    settings = await get_settings(session)
    store = make_store(session)
    keyword_engine = (settings.get("keyword_engine") or "bm25").strip().lower()

    extra = list(getattr(expansion, "queries", None) or [])
    passage = (getattr(expansion, "passage", "") or "").strip()
    phrasings = [query, *extra]

    branches: list[list[str]] = []
    weights: list[float] = []
    by_id: dict[str, RetrievedChunk] = {}
    best_similarity = None

    if mode in ("hybrid", "vector"):
        client = embedder or client_for(settings)
        wanted = ([] if query_vector is not None else [query]) + extra + ([passage] if passage else [])
        vectors = await client.embed(wanted) if wanted else []
        if query_vector is not None:
            vectors = [query_vector, *vectors]

        for index, vector in enumerate(vectors):
            hypothetical = bool(passage) and index == len(vectors) - 1
            hits = await store.search_vector(collection_ids, vector, candidates,
                                             settings["embedding_model"])
            branches.append([str(h.chunk_id) for h in hits])
            weights.append(1.0)
            for h in hits:
                key = str(h.chunk_id)
                # A made-up passage's similarity says how alike two answers
                # are, not how well a passage answers the question, so it
                # never counts towards the similarity floor.
                similarity = None if hypothetical else h.score
                known = by_id.get(key)
                if known is None:
                    by_id[key] = RetrievedChunk(h.chunk_id, h.source_id, h.content, h.score,
                                                h.heading_path, similarity=similarity)
                elif similarity is not None and (known.similarity is None or similarity > known.similarity):
                    known.similarity = similarity
                if similarity is not None and (best_similarity is None or similarity > best_similarity):
                    best_similarity = similarity

    if mode in ("hybrid", "keyword"):
        for phrasing in phrasings:
            if keyword_engine == "bm25":
                hits = await bm25.search(session, collection_ids, phrasing, candidates)
            else:
                hits = await store.search_keyword(collection_ids, phrasing, candidates)
            branches.append([str(h.chunk_id) for h in hits])
            weights.append(float(keyword_weight if keyword_weight is not None else 1.0))
            for h in hits:
                by_id.setdefault(str(h.chunk_id),
                                 RetrievedChunk(h.chunk_id, h.source_id, h.content,
                                                h.score, h.heading_path))

    fused = fuse_rankings(branches, weights=weights)

    # A passage that addresses the model is dropped before anything ranks it
    # higher or hands it on. See shield.py.
    fused, _ = shield.screen_passages(fused, lambda pair: by_id[pair[0]].content, settings)

    if reranker is not None and fused:
        reranked = await _rerank(reranker, query, fused, by_id, top_k, rerank_min_score)
        if reranked is not None:
            return await with_neighbours(session, reranked, neighbours)

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
    return await with_neighbours(session, out, neighbours)


def _body(content: str) -> str:
    """A chunk without the Section and About lines every chunk of a source repeats."""
    head, separator, rest = content.partition("\n\n")
    lines = head.splitlines()
    if separator and lines and all(line.startswith(HEADER_PREFIXES) for line in lines):
        return rest
    return content


async def with_neighbours(session, chunks: list[RetrievedChunk], neighbours: int) -> list[RetrievedChunk]:
    """Each passage with the passages either side of it, from the same section.

    A chunk is cut to suit search, not reading: the sentence that answers the
    question can sit in it while the condition that qualifies it sits in the
    next. Searching small and handing the model a little more fixes that, the
    pattern known as small-to-big or parent-document retrieval.

    Only neighbours under the same heading are added, so a passage never runs
    on into an unrelated section. A neighbour already handed over with a
    better-ranked passage is not repeated, and a hit that is itself such a
    neighbour is dropped, since its text is already there.
    """
    if not neighbours or neighbours <= 0 or not chunks:
        return chunks

    ids = [chunk.chunk_id for chunk in chunks]
    stmt = text("SELECT id, source_id, ordinal, heading_path FROM kb_chunks WHERE id IN :ids") \
        .bindparams(bindparam("ids", expanding=True))
    placed = {row.id: row for row in (await session.execute(stmt, {"ids": ids})).all()}

    wanted: dict[str, tuple[int, int]] = {}
    for chunk in chunks:
        row = placed.get(chunk.chunk_id)
        if row is None:
            continue
        low, high = wanted.get(row.source_id, (row.ordinal, row.ordinal))
        wanted[row.source_id] = (min(low, row.ordinal - neighbours), max(high, row.ordinal + neighbours))

    around: dict[tuple[str, int], object] = {}
    for source_id, (low, high) in wanted.items():
        rows = (await session.execute(text("""
            SELECT id, source_id, ordinal, content, heading_path FROM kb_chunks
            WHERE source_id = :sid AND ordinal BETWEEN :low AND :high
        """), {"sid": source_id, "low": low, "high": high})).all()
        for row in rows:
            around[(row.source_id, row.ordinal)] = row

    used: set[int] = set()
    out: list[RetrievedChunk] = []
    for chunk in chunks:
        row = placed.get(chunk.chunk_id)
        if row is None:
            out.append(chunk)
            continue
        if row.id in used:
            continue

        section = row.heading_path or ""
        before, after = [], []
        for step in range(1, neighbours + 1):
            prior = around.get((row.source_id, row.ordinal - step))
            if prior is None or (prior.heading_path or "") != section or prior.id in used:
                break
            before.insert(0, prior)
        for step in range(1, neighbours + 1):
            later = around.get((row.source_id, row.ordinal + step))
            if later is None or (later.heading_path or "") != section or later.id in used:
                break
            after.append(later)

        used.add(row.id)
        used.update(n.id for n in before + after)

        if not before and not after:
            out.append(chunk)
            continue

        content = "\n\n".join([*(_body(n.content) for n in before), chunk.content,
                               *(_body(n.content) for n in after)])
        out.append(RetrievedChunk(chunk.chunk_id, chunk.source_id, content, chunk.score,
                                  chunk.heading_path, reranked=chunk.reranked,
                                  similarity=chunk.similarity))

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
    parts = []
    for n, chunk in enumerate(chunks, start=1):
        parts.append(f"[{n}] {titles.get(chunk.source_id, 'Untitled')}")
        parts.append(chunk.content)
        parts.append("")
    return spotlight.wrap(KB_LEAD, "\n".join(parts))


def augment_system_prompt(prompt: str, context: str, fallback: str) -> str:
    base = (prompt or "").strip()
    if context:
        return f"{base}\n\n{context}".strip()
    if fallback == "say_unknown":
        return f"{base}{NO_CONTEXT_INSTRUCTION}".strip()
    return base
