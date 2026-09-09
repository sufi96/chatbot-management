import pytest
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import AppSetting, Base, KbChunk, KbCollection, KbSource, get_settings


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        yield s
    await engine.dispose()


@pytest.mark.asyncio
async def test_settings_fall_back_to_defaults(session):
    settings = await get_settings(session)
    assert settings["embedding_model"] == "nomic-embed-text"
    assert settings["embedding_dimensions"] == "768"
    assert settings["chunk_size"] == "1800"


@pytest.mark.asyncio
async def test_stored_settings_override_defaults(session):
    session.add(AppSetting(key="embedding_model", value="custom-model"))
    await session.commit()

    settings = await get_settings(session)
    assert settings["embedding_model"] == "custom-model"
    assert settings["chunk_size"] == "1800"  # untouched default survives


@pytest.mark.asyncio
async def test_chunk_model_has_no_embedding_attribute(session):
    # The embedding column is driver-specific, so the drivers own it in raw SQL.
    assert not hasattr(KbChunk, "embedding")
    assert hasattr(KbChunk, "embedding_model")


@pytest.mark.asyncio
async def test_source_belongs_to_a_collection(session):
    session.add(KbCollection(id="c1", system_id="s1", name="C"))
    session.add(KbSource(id="s1", collection_id="c1", type="text", title="T", body="B"))
    await session.commit()

    fetched = await session.get(KbSource, "s1")
    assert fetched.collection_id == "c1"
    assert fetched.status == "pending"
