"""Vector and keyword storage, one implementation per database.

Both drivers expose the same four methods, so retrieval never knows which one
it is talking to. Only rank order leaves this module; the fused ranking upstream
never compares a cosine score with a BM25 score.
"""
import struct
from dataclasses import dataclass
from typing import Protocol

import numpy as np
from sqlalchemy import bindparam, text


@dataclass
class Hit:
    chunk_id: int
    source_id: str
    content: str
    score: float
    heading_path: str = ""


class VectorStore(Protocol):
    async def upsert(self, chunks: list[dict]) -> None: ...
    async def delete_source(self, source_id: str) -> None: ...
    async def search_vector(self, collection_ids: list[str], query_vector: list[float],
                            limit: int, embedding_model: str = None) -> list[Hit]: ...
    async def search_keyword(self, collection_ids: list[str],
                             query_text: str, limit: int) -> list[Hit]: ...


def pack(vector: list[float]) -> bytes:
    return struct.pack(f"<{len(vector)}f", *vector)


def unpack(blob: bytes) -> list[float]:
    return list(struct.unpack(f"<{len(blob) // 4}f", blob))


class SqliteVectorStore:
    """Fallback driver. Brute-force scan, fine into the tens of thousands."""

    def __init__(self, session):
        self.session = session

    async def upsert(self, chunks: list[dict]) -> None:
        if not chunks:
            return
        await self.delete_source(chunks[0]["source_id"])
        for c in chunks:
            await self.session.execute(text("""
                INSERT INTO kb_chunks
                    (collection_id, source_id, ordinal, content, char_count,
                     heading_path, embedding_model, embedding)
                VALUES (:collection_id, :source_id, :ordinal, :content, :char_count,
                        :heading_path, :embedding_model, :embedding)
            """), {**c, "embedding": pack(c["embedding"])})
        await self.session.commit()

    async def delete_source(self, source_id: str) -> None:
        await self.session.execute(
            text("DELETE FROM kb_chunks WHERE source_id = :sid"), {"sid": source_id})
        await self.session.commit()

    async def search_vector(self, collection_ids, query_vector, limit,
                            embedding_model=None) -> list[Hit]:
        if not collection_ids:
            return []
        sql = """
            SELECT id, source_id, content, heading_path, embedding FROM kb_chunks
            WHERE collection_id IN :cids AND embedding IS NOT NULL
        """
        params = {}
        if embedding_model:
            # A vector made by a different model is not comparable to this query.
            sql += " AND embedding_model = :model"
            params["model"] = embedding_model
        # IN needs an expanding bindparam or the tuple is sent as one value.
        stmt = text(sql).bindparams(bindparam("cids", expanding=True))
        rows = (await self.session.execute(stmt, {**params, "cids": list(collection_ids)})).all()
        if not rows:
            return []

        matrix = np.array([unpack(r.embedding) for r in rows], dtype=np.float32)
        query = np.array(query_vector, dtype=np.float32)
        if matrix.shape[1] != query.shape[0]:
            return []          # vectors from a model with different dimensions

        scores = matrix @ query          # vectors are stored normalised
        order = np.argsort(-scores)[:limit]
        return [Hit(rows[i].id, rows[i].source_id, rows[i].content, float(scores[i]),
                    rows[i].heading_path or "")
                for i in order]

    async def search_keyword(self, collection_ids, query_text, limit) -> list[Hit]:
        if not collection_ids or not query_text.strip():
            return []
        terms = [t for t in query_text.lower().split() if len(t) > 2]
        if not terms:
            return []

        stmt = text("""
            SELECT id, source_id, content, heading_path FROM kb_chunks
            WHERE collection_id IN :cids
        """).bindparams(bindparam("cids", expanding=True))
        rows = (await self.session.execute(stmt, {"cids": list(collection_ids)})).all()

        scored = []
        for r in rows:
            haystack = r.content.lower()
            score = sum(haystack.count(term) for term in terms)
            if score:
                scored.append(Hit(r.id, r.source_id, r.content, float(score),
                                  r.heading_path or ""))
        scored.sort(key=lambda h: h.score, reverse=True)
        return scored[:limit]


class PgVectorStore:
    """Default driver. HNSW for the vector branch, GIN tsvector for keywords."""

    def __init__(self, session):
        self.session = session

    async def upsert(self, chunks: list[dict]) -> None:
        if not chunks:
            return
        await self.delete_source(chunks[0]["source_id"])
        for c in chunks:
            await self.session.execute(text("""
                INSERT INTO kb_chunks
                    (collection_id, source_id, ordinal, content, char_count,
                     heading_path, embedding_model, embedding)
                VALUES (:collection_id, :source_id, :ordinal, :content, :char_count,
                        :heading_path, :embedding_model, CAST(:embedding AS vector))
            """), {**c, "embedding": "[" + ",".join(str(x) for x in c["embedding"]) + "]"})
        await self.session.commit()

    async def delete_source(self, source_id: str) -> None:
        await self.session.execute(
            text("DELETE FROM kb_chunks WHERE source_id = :sid"), {"sid": source_id})
        await self.session.commit()

    async def search_vector(self, collection_ids, query_vector, limit,
                            embedding_model=None) -> list[Hit]:
        if not collection_ids:
            return []
        literal = "[" + ",".join(str(x) for x in query_vector) + "]"
        model_clause = " AND embedding_model = :model" if embedding_model else ""
        params = {"q": literal, "cids": list(collection_ids), "lim": limit}
        if embedding_model:
            params["model"] = embedding_model
        rows = (await self.session.execute(text(f"""
            SELECT id, source_id, content, heading_path,
                   1 - (embedding <=> CAST(:q AS vector)) AS score
            FROM kb_chunks
            WHERE collection_id = ANY(:cids) AND embedding IS NOT NULL{model_clause}
            ORDER BY embedding <=> CAST(:q AS vector)
            LIMIT :lim
        """), params)).all()
        return [Hit(r.id, r.source_id, r.content, float(r.score), r.heading_path or "")
                for r in rows]

    async def search_keyword(self, collection_ids, query_text, limit) -> list[Hit]:
        if not collection_ids or not query_text.strip():
            return []
        rows = (await self.session.execute(text("""
            SELECT id, source_id, content, heading_path,
                   ts_rank_cd(content_tsv, plainto_tsquery('english', :q)) AS score
            FROM kb_chunks
            WHERE collection_id = ANY(:cids)
              AND content_tsv @@ plainto_tsquery('english', :q)
            ORDER BY score DESC
            LIMIT :lim
        """), {"q": query_text, "cids": list(collection_ids), "lim": limit})).all()
        return [Hit(r.id, r.source_id, r.content, float(r.score), r.heading_path or "")
                for r in rows]


def make_store(session, driver: str) -> VectorStore:
    return SqliteVectorStore(session) if driver == "sqlite" else PgVectorStore(session)
