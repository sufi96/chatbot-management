"""Settings that link a platform provider rather than copying its endpoint.

Admin Settings stores `<job>_provider_id`. Everything downstream (roles.py,
the embedding client) still reads `<job>_base_url` and `<job>_api_key`, so
get_settings expands the link. These tests pin that expansion, including the
two ways it can be empty.
"""
import pytest
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

import roles
from database import SETTING_DEFAULTS, AiProvider, AppSetting, Base, get_settings


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        yield s
    await engine.dispose()


def spark() -> AiProvider:
    return AiProvider(id="aip_spark", system_id=None, name="Spark",
                      base_url="http://spark:8000/v1", api_key="k")


def test_every_job_has_a_blank_provider_link_by_default():
    assert SETTING_DEFAULTS["embedding_provider_id"] == ""
    for role in roles.ROLES:
        assert SETTING_DEFAULTS[f"{role}_model_provider_id"] == "", role


async def test_a_linked_role_reads_its_endpoint_from_the_provider(session):
    session.add(spark())
    session.add(AppSetting(key="intent_model_provider_id", value="aip_spark"))
    session.add(AppSetting(key="intent_model_name", value="qwen3.5:4b"))
    await session.commit()

    settings = await get_settings(session)

    assert settings["intent_model_base_url"] == "http://spark:8000/v1"
    assert settings["intent_model_api_key"] == "k"
    endpoint = roles.endpoint_for("intent", None, settings)
    assert endpoint.configured is True


async def test_linked_embedding_reads_its_endpoint_from_the_provider(session):
    session.add(spark())
    session.add(AppSetting(key="embedding_provider_id", value="aip_spark"))
    await session.commit()

    settings = await get_settings(session)

    assert settings["embedding_base_url"] == "http://spark:8000/v1"
    assert settings["embedding_api_key"] == "k"


async def test_a_link_to_a_deleted_provider_is_unavailable_not_defaulted(session):
    """Quietly falling back to localhost would hide that the link is broken."""
    session.add(AppSetting(key="embedding_provider_id", value="aip_gone"))
    session.add(AppSetting(key="rerank_model_provider_id", value="aip_gone"))
    session.add(AppSetting(key="rerank_model_name", value="bge-reranker-v2-m3"))
    await session.commit()

    settings = await get_settings(session)

    assert settings["embedding_base_url"] == ""
    assert settings["rerank_model_base_url"] == ""
    assert roles.endpoint_for("rerank", None, settings).available is False


async def test_a_blank_embedding_link_keeps_the_local_default(session):
    settings = await get_settings(session)

    assert settings["embedding_base_url"] == "http://localhost:11434/v1"
    assert settings["embedding_api_key"] == ""


async def test_a_platform_provider_has_no_workspace(session):
    session.add(spark())
    await session.commit()

    assert (await session.get(AiProvider, "aip_spark")).system_id is None
