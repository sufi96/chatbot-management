import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import AppSetting, Base, KbCollection, KbSource
from kb.indexer import extract_text, index_source


class StubEmbedder:
    """Deterministic vectors: no Ollama needed in tests."""

    def __init__(self):
        self.calls = []

    async def embed(self, texts, transport=None):
        self.calls.append(texts)
        return [[float(len(t) % 7), 1.0] for t in texts]


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
        await s.commit()
        yield s
    await engine.dispose()


def test_extract_text_renders_a_qa_pair():
    source = KbSource(id="s", collection_id="c", type="qa",
                      title="How do I get a refund?", body="Within 30 days.")
    assert extract_text(source) == "Q: How do I get a refund?\nA: Within 30 days."


def test_extract_text_passes_plain_text_through():
    source = KbSource(id="s", collection_id="c", type="text", title="T", body="Body here.")
    assert extract_text(source) == "Body here."


@pytest.mark.asyncio
async def test_indexing_a_text_source_creates_chunks_and_marks_it_ready(session):
    session.add(KbSource(id="src1", collection_id="col1", type="text",
                         title="Policy", body="Refunds within thirty days. " * 80))
    await session.commit()

    count = await index_source(session, "src1", embedder=StubEmbedder())

    assert count > 1
    source = await session.get(KbSource, "src1")
    assert source.status == "ready"
    assert source.chunk_count == count
    assert source.indexed_at is not None

    rows = (await session.execute(
        text("SELECT count(*) FROM kb_chunks WHERE source_id='src1'"))).scalar()
    assert rows == count


@pytest.mark.asyncio
async def test_a_qa_source_is_never_split(session):
    session.add(KbSource(id="src2", collection_id="col1", type="qa",
                         title="Q" * 400, body="A" * 900))
    await session.commit()

    count = await index_source(session, "src2", embedder=StubEmbedder())

    assert count == 1


@pytest.mark.asyncio
async def test_reindexing_replaces_the_previous_chunks(session):
    session.add(KbSource(id="src3", collection_id="col1", type="text",
                         title="T", body="first body"))
    await session.commit()
    await index_source(session, "src3", embedder=StubEmbedder())

    source = await session.get(KbSource, "src3")
    source.body = "second body"
    await session.commit()
    await index_source(session, "src3", embedder=StubEmbedder())

    rows = (await session.execute(
        text("SELECT content FROM kb_chunks WHERE source_id='src3'"))).scalars().all()
    assert rows == ["second body"]


@pytest.mark.asyncio
async def test_an_empty_body_records_an_error(session):
    session.add(KbSource(id="src4", collection_id="col1", type="text", title="T", body="   "))
    await session.commit()

    count = await index_source(session, "src4", embedder=StubEmbedder())

    assert count == 0
    source = await session.get(KbSource, "src4")
    assert source.status == "error"
    assert "no text" in source.error_message.lower()
