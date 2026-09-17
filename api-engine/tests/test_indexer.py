import pytest
from sqlalchemy import text
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine

from database import Base, KbCollection, KbSource
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
    # The title leads every chunk as a breadcrumb, so the body follows it.
    assert rows == ["Section: T\n\nsecond body"]


@pytest.mark.asyncio
async def test_an_empty_body_records_an_error(session):
    session.add(KbSource(id="src4", collection_id="col1", type="text", title="T", body="   "))
    await session.commit()

    count = await index_source(session, "src4", embedder=StubEmbedder())

    assert count == 0
    source = await session.get(KbSource, "src4")
    assert source.status == "error"
    assert "no text" in source.error_message.lower()


@pytest.mark.asyncio
async def test_indexing_a_file_source_reads_the_file(session, tmp_path, monkeypatch):
    upload_root = tmp_path / "public"
    (upload_root / "kb" / "sources").mkdir(parents=True)
    (upload_root / "kb" / "sources" / "policy.txt").write_text(
        "Refunds are issued within thirty days.", encoding="utf-8")

    from config import settings
    monkeypatch.setattr(settings, "UPLOAD_ROOT", str(upload_root))

    session.add(KbSource(id="src5", collection_id="col1", type="file",
                         title="policy.txt", file_path="kb/sources/policy.txt"))
    await session.commit()

    count = await index_source(session, "src5", embedder=StubEmbedder())

    assert count == 1
    source = await session.get(KbSource, "src5")
    assert source.status == "ready"

    stored = (await session.execute(
        text("SELECT content FROM kb_chunks WHERE source_id='src5'"))).scalar()
    assert "thirty days" in stored


@pytest.mark.asyncio
async def test_a_missing_upload_records_an_error(session, tmp_path, monkeypatch):
    from config import settings
    monkeypatch.setattr(settings, "UPLOAD_ROOT", str(tmp_path))

    session.add(KbSource(id="src6", collection_id="col1", type="file",
                         title="gone.pdf", file_path="kb/sources/gone.pdf"))
    await session.commit()

    count = await index_source(session, "src6", embedder=StubEmbedder())

    assert count == 0
    source = await session.get(KbSource, "src6")
    assert source.status == "error"
    assert "not found" in source.error_message.lower()


@pytest.mark.asyncio
async def test_indexing_records_the_heading_path_and_the_title(session):
    session.add(KbSource(id="s9", collection_id="col1", type="text",
                         title="Customer Policy",
                         body="## Warranty\n\nTwo years on desk lamps.",
                         status="pending"))
    await session.commit()

    count = await index_source(session, "s9", embedder=StubEmbedder())
    assert count == 1

    row = (await session.execute(text(
        "SELECT content, heading_path FROM kb_chunks WHERE source_id = 's9'"))).one()
    assert row.heading_path == "Customer Policy > Warranty"
    assert row.content.startswith("Section: Customer Policy > Warranty\n\n")


@pytest.mark.asyncio
async def test_a_qa_source_is_one_chunk_with_no_heading_path(session):
    session.add(KbSource(id="s10", collection_id="col1", type="qa",
                         title="How do I get a refund?", body="Within 30 days.",
                         status="pending"))
    await session.commit()

    count = await index_source(session, "s10", embedder=StubEmbedder())
    assert count == 1

    row = (await session.execute(text(
        "SELECT content, heading_path FROM kb_chunks WHERE source_id = 's10'"))).one()
    assert row.content == "Q: How do I get a refund?\nA: Within 30 days."
    assert (row.heading_path or "") == ""


@pytest.mark.asyncio
async def test_a_description_reaches_every_chunk(session):
    session.add(KbSource(id="s11", collection_id="col1", type="text",
                         title="Customer Policy",
                         description="Retail terms for lamps.",
                         body="## Warranty\n\nTwo years on desk lamps.",
                         status="pending"))
    await session.commit()

    await index_source(session, "s11", embedder=StubEmbedder())

    content = (await session.execute(text(
        "SELECT content FROM kb_chunks WHERE source_id = 's11'"))).scalar()
    assert content.startswith(
        "Section: Customer Policy > Warranty\nAbout: Retail terms for lamps.\n\n")


@pytest.mark.asyncio
async def test_a_qa_source_carries_its_description_and_stays_one_chunk(session):
    session.add(KbSource(id="s12", collection_id="col1", type="qa",
                         title="Do you refund shipping?",
                         description="Retail terms for lamps.",
                         body="No.", status="pending"))
    await session.commit()

    count = await index_source(session, "s12", embedder=StubEmbedder())
    assert count == 1

    content = (await session.execute(text(
        "SELECT content FROM kb_chunks WHERE source_id = 's12'"))).scalar()
    assert content == ("About: Retail terms for lamps.\n\n"
                       "Q: Do you refund shipping?\nA: No.")


class FakeVision:
    model = "fake-vl"

    def __init__(self, reply="## Warranty\n\nTwo years on desk lamps."):
        self.reply = reply

    async def transcribe(self, png, transport=None):
        return self.reply


def scanned_upload(tmp_path, monkeypatch, name="scan.pdf"):
    from PIL import Image
    from config import settings

    upload_root = tmp_path / "public"
    (upload_root / "kb" / "sources").mkdir(parents=True)
    target = upload_root / "kb" / "sources" / name
    image = Image.new("RGB", (300, 400), "white")
    image.save(target, "PDF" if name.endswith(".pdf") else "PNG")
    monkeypatch.setattr(settings, "UPLOAD_ROOT", str(upload_root))
    return f"kb/sources/{name}"


@pytest.mark.asyncio
async def test_a_scanned_pdf_is_indexed_from_its_transcription(session, tmp_path, monkeypatch):
    path = scanned_upload(tmp_path, monkeypatch)
    session.add(KbSource(id="scan1", collection_id="col1", type="file",
                         title="Warranty card", file_path=path))
    await session.commit()

    count = await index_source(session, "scan1", embedder=StubEmbedder(),
                               make_vision=lambda settings: FakeVision())

    assert count == 1
    content = (await session.execute(
        text("SELECT content FROM kb_chunks WHERE source_id='scan1'"))).scalar()
    assert "Two years on desk lamps." in content
    assert content.startswith("Section: Warranty card > Warranty")


@pytest.mark.asyncio
async def test_a_scan_with_no_vision_model_explains_itself(session, tmp_path, monkeypatch):
    path = scanned_upload(tmp_path, monkeypatch)
    session.add(KbSource(id="scan2", collection_id="col1", type="file",
                         title="Warranty card", file_path=path))
    await session.commit()

    count = await index_source(session, "scan2", embedder=StubEmbedder(),
                               make_vision=lambda settings: None)

    assert count == 0
    source = await session.get(KbSource, "scan2")
    assert source.status == "error"
    assert "vision model" in source.error_message


@pytest.mark.asyncio
async def test_an_uploaded_image_is_indexed(session, tmp_path, monkeypatch):
    path = scanned_upload(tmp_path, monkeypatch, name="menu.png")
    session.add(KbSource(id="img1", collection_id="col1", type="file",
                         title="Menu", file_path=path))
    await session.commit()

    count = await index_source(session, "img1", embedder=StubEmbedder(),
                               make_vision=lambda settings: FakeVision("Nasi lemak RM 8."))

    assert count == 1


@pytest.mark.asyncio
async def test_a_scan_is_read_by_the_main_model_of_a_bot_using_the_collection(
        session, tmp_path, monkeypatch):
    from database import AiProvider, BotKbCollection, BotProfile
    from kb import vision

    seen = []

    async def transcribe(self, png, transport=None):
        seen.append((self.base_url, self.model, self.borrowed_from))
        return "## Warranty\n\nTwo years."

    monkeypatch.setattr(vision.VisionClient, "transcribe", transcribe)

    session.add(AiProvider(id="aip_laptop", system_id="sys1", name="Laptop",
                           base_url="http://laptop:11434/v1", api_key=""))
    session.add(BotProfile(id="bot_off", system_id="sys1", name="Old", provider_id="aip_laptop",
                           model_name="old-model", is_active=False))
    session.add(BotProfile(id="bot_on", system_id="sys1", name="Helpdesk", provider_id="aip_laptop",
                           model_name="qwen3-vl:8b", is_active=True))
    session.add(BotKbCollection(bot_id="bot_off", collection_id="col1"))
    session.add(BotKbCollection(bot_id="bot_on", collection_id="col1"))
    path = scanned_upload(tmp_path, monkeypatch)
    session.add(KbSource(id="scan3", collection_id="col1", type="file",
                         title="Warranty card", file_path=path))
    await session.commit()

    count = await index_source(session, "scan3", embedder=StubEmbedder())

    assert count == 1
    assert seen == [("http://laptop:11434/v1", "qwen3-vl:8b", "Helpdesk")]


@pytest.mark.asyncio
async def test_a_deleted_bot_does_not_read_a_scan(session, tmp_path, monkeypatch):
    from datetime import datetime

    from database import AiProvider, BotKbCollection, BotProfile
    from kb import vision

    seen = []

    async def transcribe(self, png, transport=None):
        seen.append((self.model, self.borrowed_from))
        return "## Warranty\n\nTwo years."

    monkeypatch.setattr(vision.VisionClient, "transcribe", transcribe)

    session.add(AiProvider(id="aip_laptop", system_id="sys1", name="Laptop",
                           base_url="http://laptop:11434/v1", api_key=""))
    session.add(BotProfile(id="bot_gone", system_id="sys1", name="Gone", provider_id="aip_laptop",
                           model_name="gone-model", is_active=True, deleted_at=datetime.utcnow(),
                           created_at=datetime(2020, 1, 1)))
    session.add(BotProfile(id="bot_here", system_id="sys1", name="Helpdesk", provider_id="aip_laptop",
                           model_name="qwen3-vl:8b", is_active=True))
    session.add(BotKbCollection(bot_id="bot_gone", collection_id="col1"))
    session.add(BotKbCollection(bot_id="bot_here", collection_id="col1"))
    path = scanned_upload(tmp_path, monkeypatch)
    session.add(KbSource(id="scan5", collection_id="col1", type="file",
                         title="Warranty card", file_path=path))
    await session.commit()

    await index_source(session, "scan5", embedder=StubEmbedder())

    assert seen == [("qwen3-vl:8b", "Helpdesk")]


@pytest.mark.asyncio
async def test_a_scan_in_a_collection_no_bot_reads_explains_itself(session, tmp_path, monkeypatch):
    path = scanned_upload(tmp_path, monkeypatch)
    session.add(KbSource(id="scan4", collection_id="col1", type="file",
                         title="Warranty card", file_path=path))
    await session.commit()

    await index_source(session, "scan4", embedder=StubEmbedder())

    source = await session.get(KbSource, "scan4")
    assert source.status == "error"
    assert "vision model" in source.error_message
