"""Turn a source into searchable chunks.

Laravel owns the source record and any uploaded file; this owns everything from
extraction onward, because the parsing libraries are Python.
"""
from datetime import datetime

from database import KbSource, get_settings
from kb.chunking import chunk_text
from kb.embedding import EmbeddingClient
from kb.extract import extract_file, resolve_upload
from kb.store import make_store


def extract_text(source: KbSource) -> str:
    """The canonical text for a source, whatever its type."""
    if source.type == "qa":
        return f"Q: {source.title}\nA: {source.body or ''}"
    if source.type == "file":
        if not source.file_path:
            raise ValueError("Source is a file but has no stored path.")
        return extract_file(resolve_upload(source.file_path))
    return (source.body or "").strip()


async def index_source(session, source_id: str, embedder=None) -> int:
    source = await session.get(KbSource, source_id)
    if source is None:
        raise ValueError(f"unknown source {source_id}")

    settings = await get_settings(session)

    source.status = "processing"
    source.error_message = None
    await session.commit()

    try:
        body = extract_text(source)
        if not body.strip():
            raise ValueError("Source has no text to index.")

        # A question and answer pair is one idea; splitting it would return half
        # an answer.
        if source.type == "qa":
            pieces = [body]
        else:
            pieces = chunk_text(body,
                                size=int(settings["chunk_size"]),
                                overlap=int(settings["chunk_overlap"]))
        if not pieces:
            raise ValueError("Source produced no text to index.")

        client = embedder or EmbeddingClient(
            settings["embedding_base_url"],
            settings["embedding_api_key"],
            settings["embedding_model"],
        )
        vectors = await client.embed(pieces)

        store = make_store(session, settings["vector_driver"])
        await store.upsert([
            {
                "collection_id": source.collection_id,
                "source_id": source.id,
                "ordinal": i,
                "content": piece,
                "char_count": len(piece),
                "embedding_model": settings["embedding_model"],
                "embedding": vector,
            }
            for i, (piece, vector) in enumerate(zip(pieces, vectors))
        ])

        source.status = "ready"
        source.chunk_count = len(pieces)
        source.indexed_at = datetime.utcnow()
        source.error_message = None
        await session.commit()
        return len(pieces)

    except Exception as exc:
        await session.rollback()
        source = await session.get(KbSource, source_id)
        if source is not None:
            source.status = "error"
            source.error_message = str(exc)[:1000]
            source.chunk_count = 0
            await session.commit()
        return 0
