"""Deleting chat files whose bot's retention period has passed.

Each file carries its own expires_at, set from the bot's retention when it was
made, so the sweep needs no bot lookups. Run hourly from the engine's lifespan.
"""
import asyncio
from datetime import datetime

from sqlalchemy import delete, select

from database import ChatFile

BATCH = 500


async def sweep(session, store, now: datetime) -> int:
    rows = (await session.execute(
        select(ChatFile.id, ChatFile.storage_key).where(ChatFile.expires_at < now).limit(BATCH))).all()
    for _, key in rows:
        try:
            await store.delete(key)
        except Exception as error:
            print(f"[Files] Could not delete {key}: {error}")
    if rows:
        await session.execute(delete(ChatFile).where(ChatFile.id.in_([r[0] for r in rows])))
        await session.commit()
    return len(rows)


async def run_forever(every_seconds: int = 3600) -> None:
    import database
    from files import storage

    while True:
        try:
            async with database.async_session_factory() as session:
                removed = await sweep(session, storage.current(), datetime.utcnow())
            if removed:
                print(f"[Files] Retention removed {removed} file(s).")
        except Exception as error:
            print(f"[Files] Retention sweep failed: {error}")
        await asyncio.sleep(every_seconds)
