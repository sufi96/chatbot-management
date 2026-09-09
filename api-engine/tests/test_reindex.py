import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import AppSetting, Base, KbCollection, KbSource
from kb.reindex import prepare_vector_column, sources_to_reindex


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
        await conn.execute(text("ALTER TABLE kb_chunks ADD COLUMN embedding blob"))
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        s.add(AppSetting(key="vector_driver", value="sqlite"))
        s.add(KbCollection(id="col1", system_id="sys1", name="C"))
        s.add(KbSource(id="s1", collection_id="col1", type="text",
                       title="A", body="alpha", status="ready"))
        s.add(KbSource(id="s2", collection_id="col1", type="text",
                       title="B", body="beta", status="error"))
        s.add(KbSource(id="s3", collection_id="col1", type="text",
                       title="C", body="gamma", status="pending"))
        await s.commit()
        yield s
    await engine.dispose()


@pytest.mark.asyncio
async def test_every_source_is_queued_whatever_its_status(session):
    ids = await sources_to_reindex(session)
    assert sorted(ids) == ["s1", "s2", "s3"]


@pytest.mark.asyncio
async def test_preparing_the_column_leaves_sqlite_vectors_alone(session):
    # SQLite stores a blob of any length, so there is nothing to alter and
    # nothing to clear. Proving that means showing an existing vector survives.
    from kb.store import SqliteVectorStore
    await SqliteVectorStore(session).upsert([{
        "collection_id": "col1", "source_id": "s1", "ordinal": 0,
        "content": "alpha", "char_count": 5, "heading_path": "",
        "embedding_model": "test", "embedding": [1.0, 0.0],
    }])

    await prepare_vector_column(session, 1024)

    survived = (await session.execute(text(
        "SELECT count(*) FROM kb_chunks WHERE embedding IS NOT NULL"))).scalar()
    assert survived == 1


@pytest.mark.asyncio
async def test_no_sources_means_nothing_to_queue(session):
    await session.execute(text("DELETE FROM kb_sources"))
    await session.commit()

    assert await sources_to_reindex(session) == []
