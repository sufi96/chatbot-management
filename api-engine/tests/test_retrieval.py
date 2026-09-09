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
        s.add(AppSetting(key="vector_driver", value="sqlite"))
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
