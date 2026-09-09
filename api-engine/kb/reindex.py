"""Rebuild every vector after the embedding model changes.

A pgvector column has a fixed width, so a dimension change is DDL, not just a
re-embed: the old vectors must go before the column can be altered.
"""
from sqlalchemy import select, text

from database import KbSource, get_settings


async def sources_to_reindex(session) -> list[str]:
    """Every source, whatever its current status.

    A source that failed last time may well succeed now, and one still pending
    has nothing to lose by being queued again.
    """
    result = await session.execute(select(KbSource.id))
    return list(result.scalars().all())


async def prepare_vector_column(session, dimensions: int) -> None:
    settings = await get_settings(session)
    if settings["vector_driver"] != "pgvector":
        return   # SQLite stores a blob of any length

    # Vectors of the old width cannot survive the alter, and they are about to
    # be replaced anyway.
    await session.execute(text("UPDATE kb_chunks SET embedding = NULL"))
    await session.commit()

    await session.execute(text(
        f"ALTER TABLE kb_chunks ALTER COLUMN embedding TYPE vector({int(dimensions)})"))
    await session.commit()
