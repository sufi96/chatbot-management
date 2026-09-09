import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import Base
from kb.store import Hit, SqliteVectorStore, make_store


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
        await conn.execute(text("ALTER TABLE kb_chunks ADD COLUMN embedding blob"))
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        yield s
    await engine.dispose()


def chunk(ordinal, content, embedding, source="src1", collection="col1"):
    return {
        "collection_id": collection,
        "source_id": source,
        "ordinal": ordinal,
        "content": content,
        "char_count": len(content),
        "heading_path": "",
        "embedding_model": "test-model",
        "embedding": embedding,
    }


@pytest.mark.asyncio
async def test_upsert_then_vector_search_ranks_by_similarity(session):
    store = SqliteVectorStore(session)
    await store.upsert([
        chunk(0, "refunds within thirty days", [1.0, 0.0]),
        chunk(1, "office opening hours", [0.0, 1.0]),
    ])

    hits = await store.search_vector(["col1"], [1.0, 0.0], limit=2)

    assert len(hits) == 2
    assert isinstance(hits[0], Hit)
    assert hits[0].content == "refunds within thirty days"
    assert hits[0].score > hits[1].score


@pytest.mark.asyncio
async def test_keyword_search_finds_the_matching_chunk(session):
    store = SqliteVectorStore(session)
    await store.upsert([
        chunk(0, "refunds within thirty days", [1.0, 0.0]),
        chunk(1, "office opening hours", [0.0, 1.0]),
    ])

    hits = await store.search_keyword(["col1"], "refunds", limit=5)

    assert [h.content for h in hits] == ["refunds within thirty days"]


@pytest.mark.asyncio
async def test_search_is_scoped_to_the_given_collections(session):
    store = SqliteVectorStore(session)
    await store.upsert([chunk(0, "alpha", [1.0, 0.0], collection="col1")])
    await store.upsert([chunk(0, "beta", [1.0, 0.0], source="src2", collection="col2")])

    hits = await store.search_vector(["col2"], [1.0, 0.0], limit=5)

    assert [h.content for h in hits] == ["beta"]


@pytest.mark.asyncio
async def test_upsert_replaces_a_sources_previous_chunks(session):
    store = SqliteVectorStore(session)
    await store.upsert([chunk(0, "old text", [1.0, 0.0])])
    await store.upsert([chunk(0, "new text", [1.0, 0.0])])

    hits = await store.search_vector(["col1"], [1.0, 0.0], limit=5)

    assert [h.content for h in hits] == ["new text"]


@pytest.mark.asyncio
async def test_delete_source_removes_its_chunks(session):
    store = SqliteVectorStore(session)
    await store.upsert([chunk(0, "alpha", [1.0, 0.0])])

    await store.delete_source("src1")

    assert await store.search_vector(["col1"], [1.0, 0.0], limit=5) == []


@pytest.mark.asyncio
async def test_searching_no_collections_returns_nothing(session):
    store = SqliteVectorStore(session)
    await store.upsert([chunk(0, "alpha", [1.0, 0.0])])

    assert await store.search_vector([], [1.0, 0.0], limit=5) == []
    assert await store.search_keyword([], "alpha", limit=5) == []


@pytest.mark.asyncio
async def test_vector_search_skips_chunks_from_another_embedding_model(session):
    store = SqliteVectorStore(session)
    await store.upsert([chunk(0, "alpha", [1.0, 0.0])])

    assert await store.search_vector(["col1"], [1.0, 0.0], 5, "test-model")
    assert await store.search_vector(["col1"], [1.0, 0.0], 5, "a-different-model") == []


@pytest.mark.asyncio
async def test_make_store_selects_the_driver(session):
    assert type(make_store(session, "sqlite")).__name__ == "SqliteVectorStore"
    assert type(make_store(session, "pgvector")).__name__ == "PgVectorStore"


@pytest.mark.asyncio
async def test_heading_path_round_trips_through_the_sqlite_store(session):
    store = SqliteVectorStore(session)
    await store.upsert([{
        "collection_id": "col1", "source_id": "s1", "ordinal": 0,
        "content": "Section: Policy > Warranty\n\nTwo years on desk lamps.",
        "char_count": 50, "heading_path": "Policy > Warranty",
        "embedding_model": "test", "embedding": [1.0, 0.0],
    }])

    hits = await store.search_vector(["col1"], [1.0, 0.0], 5, "test")
    assert hits[0].heading_path == "Policy > Warranty"

    hits = await store.search_keyword(["col1"], "warranty", 5)
    assert hits[0].heading_path == "Policy > Warranty"
