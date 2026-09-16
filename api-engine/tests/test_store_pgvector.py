"""Proves the PostgreSQL driver against a real database.

Skips cleanly when PostgreSQL is not reachable, so the suite stays green on a
machine that only has SQLite.
"""
import os

import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from kb.store import PgVectorStore

DSN = os.getenv("TEST_PG_DSN",
                "postgresql+asyncpg://postgres:postgres@127.0.0.1:5432/chatbot_hub")


@pytest.fixture
async def pg_session():
    engine = create_async_engine(DSN)
    try:
        async with engine.begin() as conn:
            await conn.run_sync(lambda _: None)
    except Exception:
        pytest.skip("PostgreSQL not reachable")

    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        # Real parent rows: PostgreSQL enforces the foreign keys that SQLite
        # leaves unchecked by default.
        await s.execute(text("""
            INSERT INTO systems (id, name, allowed_origins, created_at, updated_at)
            VALUES ('pgtest_sys', 'pgvector test', '*', now(), now())
            ON CONFLICT (id) DO NOTHING
        """))
        await s.execute(text("""
            INSERT INTO kb_collections (id, system_id, name, created_at, updated_at)
            VALUES ('pgtest_col', 'pgtest_sys', 'pgvector test', now(), now())
            ON CONFLICT (id) DO NOTHING
        """))
        await s.execute(text("""
            INSERT INTO kb_sources (id, collection_id, type, title, body, status, created_at, updated_at)
            VALUES ('pgtest_src', 'pgtest_col', 'text', 'T', 'B', 'pending', now(), now())
            ON CONFLICT (id) DO NOTHING
        """))
        await s.commit()

        try:
            yield s
        finally:
            # systems cascades down to collections, sources and chunks
            await s.execute(text("DELETE FROM systems WHERE id = 'pgtest_sys'"))
            await s.commit()

    await engine.dispose()


@pytest.mark.asyncio
async def test_pgvector_round_trip(pg_session):
    store = PgVectorStore(pg_session)
    dims = 768
    a = [1.0] + [0.0] * (dims - 1)
    b = [0.0, 1.0] + [0.0] * (dims - 2)

    await store.upsert([
        {"collection_id": "pgtest_col", "source_id": "pgtest_src", "ordinal": 0,
         "content": "refunds within thirty days", "char_count": 26, "heading_path": "",
         "embedding_model": "test", "embedding": a},
        {"collection_id": "pgtest_col", "source_id": "pgtest_src", "ordinal": 1,
         "content": "office opening hours", "char_count": 20, "heading_path": "",
         "embedding_model": "test", "embedding": b},
    ])

    vector_hits = await store.search_vector(["pgtest_col"], a, limit=2)
    assert vector_hits[0].content == "refunds within thirty days"
    assert vector_hits[0].score > vector_hits[1].score

    keyword_hits = await store.search_keyword(["pgtest_col"], "refunds", limit=5)
    assert [h.content for h in keyword_hits] == ["refunds within thirty days"]

    filtered = await store.search_vector(["pgtest_col"], a, 5, "a-different-model")
    assert filtered == []


@pytest.mark.asyncio
async def test_pgvector_delete_removes_chunks(pg_session):
    store = PgVectorStore(pg_session)
    a = [1.0] + [0.0] * 767

    await store.upsert([
        {"collection_id": "pgtest_col", "source_id": "pgtest_src", "ordinal": 0,
         "content": "alpha", "char_count": 5, "heading_path": "",
         "embedding_model": "test", "embedding": a},
    ])
    assert await store.search_vector(["pgtest_col"], a, limit=5)

    await store.delete_source("pgtest_src")
    assert await store.search_vector(["pgtest_col"], a, limit=5) == []
