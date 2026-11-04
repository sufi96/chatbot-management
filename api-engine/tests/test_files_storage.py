"""File storage (local disk, or S3/MinIO by setting) and the retention sweep."""
from datetime import datetime, timedelta

import pytest
from sqlalchemy import select
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine
from sqlalchemy.pool import StaticPool

from database import Base, ChatConversation, ChatFile
from files import retention, storage


async def test_local_storage_round_trip(tmp_path):
    store = storage.LocalStorage(tmp_path)

    await store.put("bot_1/abc.pdf", b"%PDF-1.4 hello")
    assert await store.get("bot_1/abc.pdf") == b"%PDF-1.4 hello"

    await store.delete("bot_1/abc.pdf")
    with pytest.raises(FileNotFoundError):
        await store.get("bot_1/abc.pdf")


async def test_a_key_that_climbs_out_of_the_root_is_refused(tmp_path):
    store = storage.LocalStorage(tmp_path / "files")

    with pytest.raises(ValueError):
        await store.put("../escape.txt", b"x")


async def test_deleting_a_missing_file_is_not_an_error(tmp_path):
    await storage.LocalStorage(tmp_path).delete("gone.txt")


class FakeS3:
    def __init__(self):
        self.objects = {}

    def put_object(self, Bucket, Key, Body):
        self.objects[(Bucket, Key)] = Body

    def get_object(self, Bucket, Key):
        if (Bucket, Key) not in self.objects:
            raise KeyError(Key)

        class Body:
            def __init__(self, data):
                self.data = data

            def read(self):
                return self.data

        return {"Body": Body(self.objects[(Bucket, Key)])}

    def delete_object(self, Bucket, Key):
        self.objects.pop((Bucket, Key), None)


async def test_s3_storage_uses_its_bucket():
    client = FakeS3()
    store = storage.S3Storage(client, "c4-files")

    await store.put("k.txt", b"hi")
    assert client.objects == {("c4-files", "k.txt"): b"hi"}
    assert await store.get("k.txt") == b"hi"
    await store.delete("k.txt")
    with pytest.raises(FileNotFoundError):
        await store.get("k.txt")


@pytest.fixture
async def session():
    engine = create_async_engine("sqlite+aiosqlite://", poolclass=StaticPool)
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    factory = async_sessionmaker(engine, expire_on_commit=False)
    async with factory() as s:
        s.add(ChatConversation(id="c1", bot_id="b1", session_id="s1"))
        await s.commit()
        yield s
    await engine.dispose()


def row(id, expires_at):
    return ChatFile(id=id, conversation_id="c1", bot_id="b1", direction="in", name=f"{id}.txt",
                    mime="text/plain", ext="txt", size=2, storage_key=f"b1/{id}.txt", sha256="0" * 64,
                    scan_status="not_scanned", token=id * 8, expires_at=expires_at)


async def test_the_sweep_deletes_expired_files_and_keeps_the_rest(session, tmp_path):
    store = storage.LocalStorage(tmp_path)
    now = datetime(2026, 11, 5, 12, 0)
    session.add_all([row("old", now - timedelta(minutes=1)), row("new", now + timedelta(days=3))])
    await session.commit()
    await store.put("b1/old.txt", b"x")
    await store.put("b1/new.txt", b"y")

    removed = await retention.sweep(session, store, now)

    assert removed == 1
    assert [r.id for r in (await session.execute(select(ChatFile))).scalars()] == ["new"]
    assert await store.get("b1/new.txt") == b"y"
    with pytest.raises(FileNotFoundError):
        await store.get("b1/old.txt")


async def test_the_sweep_survives_bytes_already_gone(session, tmp_path):
    now = datetime(2026, 11, 5)
    session.add(row("lost", now - timedelta(days=1)))
    await session.commit()

    assert await retention.sweep(session, storage.LocalStorage(tmp_path), now) == 1
