"""The vector store follows the database the engine is actually connected to.

There used to be a vector_driver setting, defaulting to pgvector. On SQLite,
which is what a development install and the engine's own fallback run on, it
sent PostgreSQL operators to SQLite and every search failed with a syntax error
the chat route swallowed, so a bot answered as though its knowledge base were
empty. The setting is gone; the session decides.
"""
import pytest
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from kb.store import PgVectorStore, SqliteVectorStore, make_store


@pytest.mark.asyncio
async def test_a_sqlite_database_gets_the_sqlite_store():
    engine = create_async_engine("sqlite+aiosqlite:///:memory:")
    async with async_sessionmaker(engine)() as session:
        assert isinstance(make_store(session), SqliteVectorStore)
    await engine.dispose()


def test_a_postgres_database_gets_the_pgvector_store():
    engine = create_async_engine("postgresql+asyncpg://user:pass@127.0.0.1:1/none")
    session = async_sessionmaker(engine)()

    assert isinstance(make_store(session), PgVectorStore)
