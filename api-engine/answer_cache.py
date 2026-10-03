"""Answering a question already answered, without asking the model again.

A shop's visitors ask the same dozen questions in a hundred wordings. With the
cache on (Behaviour, Answer cache), an answer drawn from the knowledge base is
kept with its question's embedding, and a later question whose embedding is
close enough gets the same answer and citations back at once: no retrieval, no
model, no wait.

What is never cached, because reusing it could be wrong:

- An answer that used the database or the web. Records and pages change; the
  knowledge base changes only when someone re-indexes, which empties the cache.
- A follow-up that was not rewritten into a standalone question. "and the
  warranty?" means something different in every conversation, so a question is
  only cached, or looked up, when it is a conversation's first or the intent
  model has rewritten it to stand on its own.
- An answer the guard or the grounding check flagged, or that came back empty.

Kept per bot, for the bot's own time to live, and dropped whenever a collection
it reads is re-indexed and whenever its behaviour is saved (Laravel does the
latter). The closeness needed is the bot's own setting: 0.95 and above is the
same question reworded; much lower starts answering a different question.
"""
import json
import uuid
from dataclasses import dataclass
from datetime import datetime, timedelta

import numpy as np
from sqlalchemy import delete, select, update

MAX_ROWS_PER_BOT = 500
CACHEABLE_KINDS = ("documents",)


@dataclass
class Hit:
    id: str
    answer: str
    citations: list
    source_kind: str
    similarity: float


def enabled(bot) -> bool:
    return bool(getattr(bot, "cache_enabled", False))


def threshold(bot) -> float:
    value = getattr(bot, "cache_min_similarity", None)
    return float(value) if value else 0.95


async def lookup(session, bot, vector: list[float], embedding_model: str,
                 now: datetime | None = None) -> Hit | None:
    from database import AnswerCache

    now = now or datetime.utcnow()
    rows = (await session.execute(
        select(AnswerCache).where(AnswerCache.bot_id == bot.id,
                                  AnswerCache.embedding_model == embedding_model))).scalars().all()
    rows = [row for row in rows if row.expires_at is None or row.expires_at > now]
    if not rows:
        return None

    query = np.asarray(vector, dtype=np.float32)
    best, best_score = None, -1.0
    for row in rows:
        try:
            stored = np.asarray(json.loads(row.embedding), dtype=np.float32)
        except (ValueError, TypeError):
            continue
        if stored.shape != query.shape:
            continue
        # Both are stored normalised, so the dot product is the cosine.
        score = float(stored @ query)
        if score > best_score:
            best, best_score = row, score

    if best is None or best_score < threshold(bot):
        return None

    await session.execute(update(AnswerCache).where(AnswerCache.id == best.id)
                          .values(hits=(best.hits or 0) + 1, last_hit_at=now))
    await session.commit()

    try:
        citations = json.loads(best.citations) if best.citations else []
    except ValueError:
        citations = []

    return Hit(best.id, best.answer, citations, best.source_kind or "documents", best_score)


async def store(session, bot, question: str, vector: list[float], embedding_model: str,
                answer: str, citations: list, source_kind: str,
                now: datetime | None = None) -> None:
    from database import AnswerCache

    if source_kind not in CACHEABLE_KINDS or not answer.strip():
        return

    now = now or datetime.utcnow()
    hours = int(getattr(bot, "cache_ttl_hours", None) or 24)
    session.add(AnswerCache(
        id=str(uuid.uuid4()), bot_id=bot.id, question=question[:2000],
        embedding_model=embedding_model, embedding=json.dumps([round(float(x), 6) for x in vector]),
        answer=answer, citations=json.dumps(citations or []), source_kind=source_kind,
        hits=0, created_at=now, expires_at=now + timedelta(hours=hours)))
    await session.commit()

    # Oldest first out, once a bot holds more than it should.
    ids = (await session.execute(
        select(AnswerCache.id).where(AnswerCache.bot_id == bot.id)
        .order_by(AnswerCache.created_at.desc()).offset(MAX_ROWS_PER_BOT))).scalars().all()
    if ids:
        await session.execute(delete(AnswerCache).where(AnswerCache.id.in_(ids)))
        await session.commit()


async def forget_collection(session, collection_id: str) -> None:
    """Drop every cached answer of every bot that reads this collection."""
    from database import AnswerCache, BotKbCollection

    bots = select(BotKbCollection.bot_id).where(BotKbCollection.collection_id == collection_id)
    try:
        await session.execute(delete(AnswerCache).where(AnswerCache.bot_id.in_(bots)))
        await session.commit()
    except Exception as error:
        # Before the migration has run there is no table; indexing goes on.
        await session.rollback()
        print(f"[Cache] Could not clear cached answers: {error}")


def standalone(decision, earlier_turns: list) -> bool:
    """Whether the question can be looked up outside its conversation."""
    return not earlier_turns or bool(getattr(decision, "intent", None))
