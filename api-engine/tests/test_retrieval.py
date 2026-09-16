import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import AppSetting, Base
from kb.retrieval import (RetrievedChunk, augment_system_prompt,
                          build_context_block, retrieve_for_collections)
from kb.store import SqliteVectorStore


class StubEmbedder:
    async def embed(self, texts, transport=None):
        # "refund" queries land near the refund vector, everything else does not
        return [[1.0, 0.0] if "refund" in texts[0].lower() else [0.0, 1.0]]


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
        await conn.execute(text("ALTER TABLE kb_chunks ADD COLUMN embedding blob"))
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        s.add(AppSetting(key="embedding_model", value="test"))
        await s.commit()
        store = SqliteVectorStore(s)
        await store.upsert([
            {"collection_id": "col1", "source_id": "src1", "ordinal": 0,
             "content": "Refunds are issued within thirty days.", "char_count": 38,
             "heading_path": "", "embedding_model": "test", "embedding": [1.0, 0.0]},
        ])
        await store.upsert([
            {"collection_id": "col1", "source_id": "src2", "ordinal": 0,
             "content": "The office opens at nine.", "char_count": 25,
             "heading_path": "", "embedding_model": "test", "embedding": [0.0, 1.0]},
        ])
        yield s
    await engine.dispose()


def test_context_block_numbers_sources():
    chunks = [RetrievedChunk(1, "src1", "Refunds in thirty days.", 0.03)]
    block = build_context_block(chunks, {"src1": "Refund policy"})
    assert "[1] Refund policy" in block
    assert "Refunds in thirty days." in block


def test_context_block_is_empty_without_chunks():
    assert build_context_block([], {}) == ""


def test_augment_appends_context_to_the_prompt():
    out = augment_system_prompt("You are helpful.", "[1] A\nbody", "say_unknown")
    assert out.startswith("You are helpful.")
    assert "[1] A" in out


def test_augment_with_no_context_and_say_unknown_adds_the_instruction():
    out = augment_system_prompt("You are helpful.", "", "say_unknown")
    assert "not in the available material" in out.lower()


def test_augment_with_no_context_and_answer_anyway_leaves_the_prompt_alone():
    assert augment_system_prompt("You are helpful.", "", "answer_anyway") == "You are helpful."


@pytest.mark.asyncio
async def test_retrieval_finds_the_relevant_chunk(session):
    results = await retrieve_for_collections(
        session, ["col1"], "how do refunds work", top_k=1, embedder=StubEmbedder())

    assert len(results) == 1
    assert "Refunds" in results[0].content


@pytest.mark.asyncio
async def test_no_collections_returns_nothing(session):
    assert await retrieve_for_collections(
        session, [], "refund", embedder=StubEmbedder()) == []


@pytest.mark.asyncio
async def test_min_score_filters_everything_out(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", min_score=99.0, embedder=StubEmbedder())
    assert results == []


@pytest.mark.asyncio
async def test_keyword_mode_skips_the_embedder(session):
    class Exploding:
        async def embed(self, texts, transport=None):
            raise AssertionError("keyword mode must not embed")

    results = await retrieve_for_collections(
        session, ["col1"], "refunds", mode="keyword", embedder=Exploding())
    assert any("Refunds" in r.content for r in results)


@pytest.mark.asyncio
async def test_hybrid_beats_either_branch_for_a_chunk_both_agree_on(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refunds", mode="hybrid", top_k=2, embedder=StubEmbedder())

    # the refund chunk is top of both branches, so it must lead
    assert "Refunds" in results[0].content


def test_retrieved_chunks_default_to_an_empty_heading_path():
    from kb.retrieval import RetrievedChunk
    chunk = RetrievedChunk(1, "s1", "body", 0.5)
    assert chunk.heading_path == ""


def test_a_retrieved_chunk_keeps_the_heading_path_it_was_given():
    from kb.retrieval import RetrievedChunk
    chunk = RetrievedChunk(1, "s1", "body", 0.5, "Policy > Warranty")
    assert chunk.heading_path == "Policy > Warranty"


def test_fit_to_budget_keeps_the_highest_ranked_chunks_that_fit():
    from kb.retrieval import RetrievedChunk, fit_to_budget
    chunks = [RetrievedChunk(n, "s", "x" * 100, 1.0 - n / 10) for n in range(5)]

    kept = fit_to_budget(chunks, 250)

    assert [c.chunk_id for c in kept] == [0, 1]


def test_fit_to_budget_always_keeps_the_first_chunk():
    from kb.retrieval import RetrievedChunk, fit_to_budget
    chunks = [RetrievedChunk(1, "s", "x" * 5000, 0.9)]

    assert len(fit_to_budget(chunks, 100)) == 1


def test_fit_to_budget_passes_everything_through_when_it_all_fits():
    from kb.retrieval import RetrievedChunk, fit_to_budget
    chunks = [RetrievedChunk(n, "s", "x" * 100, 0.5) for n in range(3)]

    assert len(fit_to_budget(chunks, 6000)) == 3


def test_fit_to_budget_handles_an_empty_list():
    from kb.retrieval import fit_to_budget
    assert fit_to_budget([], 6000) == []


class FakeReranker:
    """Scores the office passage above the refund one, the reverse of fusion."""
    model = "fake-reranker"

    def __init__(self, scores=None):
        self.scores = scores or {"office": 0.8, "Refunds": 0.3}
        self.seen = []

    async def rerank(self, query, documents, transport=None):
        self.seen.append(list(documents))
        ranked = []
        for index, document in enumerate(documents):
            score = next((s for word, s in self.scores.items() if word in document), 0.0)
            ranked.append((index, score))
        return sorted(ranked, key=lambda pair: pair[1], reverse=True)


class ExplodingReranker:
    model = "exploding"

    async def rerank(self, query, documents, transport=None):
        raise RuntimeError("reranker is down")


class SilentReranker:
    model = "silent"

    async def rerank(self, query, documents, transport=None):
        return []


@pytest.mark.asyncio
async def test_a_reranker_decides_the_order_and_the_score(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", top_k=2, embedder=StubEmbedder(),
        reranker=FakeReranker())

    assert "office" in results[0].content
    assert results[0].score == 0.8
    assert results[0].reranked is True


@pytest.mark.asyncio
async def test_the_reranker_reads_the_passages_themselves(session):
    reranker = FakeReranker()
    await retrieve_for_collections(session, ["col1"], "refund", top_k=2,
                                   embedder=StubEmbedder(), reranker=reranker)

    assert any("Refunds are issued" in document for document in reranker.seen[0])


@pytest.mark.asyncio
async def test_the_reranker_floor_drops_weak_passages(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", top_k=5, embedder=StubEmbedder(),
        reranker=FakeReranker(), rerank_min_score=0.5)

    assert [r.score for r in results] == [0.8]


@pytest.mark.asyncio
async def test_nothing_above_the_reranker_floor_is_a_miss(session):
    """An honest miss is what lets the next source in the order have its turn."""
    results = await retrieve_for_collections(
        session, ["col1"], "refund", embedder=StubEmbedder(),
        reranker=FakeReranker(), rerank_min_score=0.95)

    assert results == []


@pytest.mark.asyncio
async def test_the_fusion_floor_does_not_apply_once_reranked(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", embedder=StubEmbedder(),
        min_score=99.0, reranker=FakeReranker())

    assert results


@pytest.mark.asyncio
async def test_a_failing_reranker_falls_back_to_the_fusion_order(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", top_k=1, embedder=StubEmbedder(),
        reranker=ExplodingReranker())

    assert "Refunds" in results[0].content
    assert results[0].reranked is False


@pytest.mark.asyncio
async def test_a_reranker_that_returns_nothing_falls_back_too(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", top_k=1, embedder=StubEmbedder(),
        reranker=SilentReranker())

    assert "Refunds" in results[0].content


def test_a_chunk_is_not_reranked_unless_it_says_so():
    assert RetrievedChunk(1, "s1", "body", 0.5).reranked is False


class SlantedEmbedder:
    """A query at cosine 0.6 to the refund passage and 0.8 to the office one."""

    async def embed(self, texts, transport=None):
        return [[0.6, 0.8]]


@pytest.mark.asyncio
async def test_a_question_no_passage_is_similar_to_is_a_miss(session):
    """Fusion ranks every passage in a small collection, so its score cannot
    say "nothing here answers this". On a ten-chunk knowledge base an
    unrelated question scored exactly as a real one, the knowledge base always
    answered first, and the database behind it was never asked."""
    results = await retrieve_for_collections(
        session, ["col1"], "anything", embedder=SlantedEmbedder(), min_similarity=0.9)

    assert results == []


@pytest.mark.asyncio
async def test_a_question_similar_enough_to_one_passage_still_finds_them(session):
    results = await retrieve_for_collections(
        session, ["col1"], "anything", top_k=2, embedder=SlantedEmbedder(), min_similarity=0.75)

    assert len(results) == 2


@pytest.mark.asyncio
async def test_a_similarity_floor_of_zero_is_off(session):
    results = await retrieve_for_collections(
        session, ["col1"], "anything", embedder=SlantedEmbedder(), min_similarity=0.0)

    assert results


@pytest.mark.asyncio
async def test_keyword_search_has_no_similarity_to_hold_to_a_floor(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refunds", mode="keyword", min_similarity=0.99,
        embedder=SlantedEmbedder())

    assert results


@pytest.mark.asyncio
async def test_a_reranker_judges_instead_of_the_similarity_floor(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", embedder=SlantedEmbedder(),
        reranker=FakeReranker(), min_similarity=0.99)

    assert results
    assert results[0].reranked is True


@pytest.mark.asyncio
async def test_a_failed_reranker_falls_back_to_the_similarity_floor(session):
    results = await retrieve_for_collections(
        session, ["col1"], "refund", embedder=SlantedEmbedder(),
        reranker=ExplodingReranker(), min_similarity=0.9)

    assert results == []


@pytest.mark.asyncio
async def test_each_passage_carries_its_similarity(session):
    """What an operator tunes the floor by, in the playground."""
    results = await retrieve_for_collections(
        session, ["col1"], "anything", top_k=2, embedder=SlantedEmbedder())

    by_content = {("office" if "office" in r.content else "refund"): r.similarity for r in results}
    assert by_content["office"] == pytest.approx(0.8)
    assert by_content["refund"] == pytest.approx(0.6)


def test_a_chunk_found_only_by_keyword_has_no_similarity():
    assert RetrievedChunk(1, "s1", "body", 0.5).similarity is None
