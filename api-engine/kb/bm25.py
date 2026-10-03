"""Okapi BM25 over the knowledge base, inside the engine.

Postgres full-text search is what the keyword branch used before, and it is not
BM25: ts_rank_cd weighs how close the terms sit, knows nothing of how rare a
term is across the collection, and stems with English rules, so a Malay word is
cut wrongly or not at all. BM25 is the ranking every search engine is measured
against: a rare term such as a product code outweighs a common one, and a long
chunk does not win merely by being long.

The index is built from kb_chunks the first time a set of collections is
searched, then kept in memory. A cheap fingerprint of those collections (row
count, highest id, total characters) is read on every search, so an upload, an
edit or a delete rebuilds it on the next question rather than serving stale
rankings. At the sizes this system runs (tens of thousands of chunks), a build
takes well under a second and a search a few milliseconds.

Words are split on Unicode word boundaries and lowercased, with a light plural
rule for English and no other stemming. That treats Malay and English alike,
and leaves codes such as X200 whole.
"""
import math
import re
import unicodedata
from collections import Counter, OrderedDict
from dataclasses import dataclass

from sqlalchemy import bindparam, text

from kb.store import Hit

K1 = 1.2
B = 0.75
CACHE_SIZE = 16

_WORD = re.compile(r"\w+", re.UNICODE)

# Function words in the two languages the system was built for. Small on
# purpose: a word that could ever be the point of a question does not belong.
STOPWORDS = frozenset("""
a an and are as at be by do does for from has have how i in is it its me my of on or our
so that the their them there these they this to was we were what when where which who why
will with you your can could would should may please tell about
ada adakah akan apa bagaimana bila boleh dan dari dengan di ini itu ke kami kamu
kenapa mana pada saya sila tak tidak untuk yang
""".split())


def stem(word: str) -> str:
    """A plural made singular, for alphabetic English words only."""
    if not word.isalpha() or len(word) <= 3:
        return word
    if word.endswith("ies") and len(word) > 4:
        return word[:-3] + "y"
    if word.endswith("s") and not word.endswith("ss"):
        return word[:-1]
    return word


def tokens(content: str) -> list[str]:
    folded = unicodedata.normalize("NFKC", content or "").lower()
    return [stem(word) for word in _WORD.findall(folded)
            if (len(word) > 1 or word.isdigit()) and word not in STOPWORDS]


@dataclass
class _Doc:
    chunk_id: int
    source_id: str
    content: str
    heading_path: str
    length: int


class Index:
    def __init__(self, rows):
        self.docs: list[_Doc] = []
        self.postings: dict[str, list[tuple[int, int]]] = {}

        for row in rows:
            words = tokens(row.content)
            position = len(self.docs)
            self.docs.append(_Doc(row.id, row.source_id, row.content,
                                  row.heading_path or "", len(words)))
            for term, count in Counter(words).items():
                self.postings.setdefault(term, []).append((position, count))

        total = sum(doc.length for doc in self.docs)
        self.average = (total / len(self.docs)) if self.docs else 0.0

    def idf(self, term: str) -> float:
        n = len(self.docs)
        df = len(self.postings.get(term, ()))
        return math.log(1.0 + (n - df + 0.5) / (df + 0.5))

    def search(self, query: str, limit: int) -> list[Hit]:
        if not self.docs:
            return []

        scores: dict[int, float] = {}
        for term in set(tokens(query)):
            postings = self.postings.get(term)
            if not postings:
                continue
            weight = self.idf(term)
            for position, tf in postings:
                length = self.docs[position].length
                norm = K1 * (1 - B + B * (length / self.average if self.average else 1.0))
                scores[position] = scores.get(position, 0.0) + weight * tf * (K1 + 1) / (tf + norm)

        best = sorted(scores.items(), key=lambda pair: pair[1], reverse=True)[:limit]
        return [Hit(self.docs[p].chunk_id, self.docs[p].source_id, self.docs[p].content,
                    float(score), self.docs[p].heading_path)
                for p, score in best]


_cache: "OrderedDict[tuple, tuple[tuple, Index]]" = OrderedDict()


def clear() -> None:
    _cache.clear()


async def _fingerprint(session, collection_ids) -> tuple:
    stmt = text("""
        SELECT COUNT(*) AS n, MAX(id) AS top, SUM(char_count) AS chars
        FROM kb_chunks WHERE collection_id IN :cids
    """).bindparams(bindparam("cids", expanding=True))
    row = (await session.execute(stmt, {"cids": list(collection_ids)})).one()
    return (int(row.n or 0), int(row.top or 0), int(row.chars or 0))


async def _build(session, collection_ids) -> Index:
    stmt = text("""
        SELECT id, source_id, content, heading_path FROM kb_chunks
        WHERE collection_id IN :cids ORDER BY id
    """).bindparams(bindparam("cids", expanding=True))
    rows = (await session.execute(stmt, {"cids": list(collection_ids)})).all()
    return Index(rows)


async def index_for(session, collection_ids) -> Index:
    key = tuple(sorted(set(collection_ids)))
    fingerprint = await _fingerprint(session, key)

    cached = _cache.get(key)
    if cached and cached[0] == fingerprint:
        _cache.move_to_end(key)
        return cached[1]

    index = await _build(session, key)
    _cache[key] = (fingerprint, index)
    _cache.move_to_end(key)
    while len(_cache) > CACHE_SIZE:
        _cache.popitem(last=False)
    return index


async def search(session, collection_ids, query: str, limit: int) -> list[Hit]:
    if not collection_ids or not (query or "").strip():
        return []
    index = await index_for(session, collection_ids)
    return index.search(query, limit)
