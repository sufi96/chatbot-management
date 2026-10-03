"""One sentence of context written into every chunk while indexing.

A chunk cut from the middle of a document often cannot say what it is about:
"The fee is waived for members" names neither the fee nor the membership. The
Section and About lines already carry the document's title, headings and the
operator's description, which costs nothing. Contextual retrieval goes one step
further and has a model read the document and write, for each chunk, a sentence
placing it, which is then embedded and keyword-indexed with the chunk.

It costs one model call per chunk at indexing time and nothing when answering,
so it is off by default (Admin Settings, Chunking and search) and a re-index is
needed after switching it on. The context role does the writing; left blank it
borrows the main model of a bot that reads the collection, as vision does.

A chunk whose call fails keeps its header lines alone, exactly as with the
setting off. Indexing never fails for want of context.
"""
import asyncio

import roles
from llm_adapter import LLMAdapter

DOCUMENT_CHARS = 8000
CHUNK_CHARS = 2400
CONTEXT_CHARS = 300
CONCURRENCY = 4

PROMPT = (
    "You place a passage within the document it was cut from, to help a search engine "
    "find it. Reply with one sentence, in the document's language, saying what the "
    "passage is about and how it fits in the document: name the product, policy, "
    "section or subject it belongs to. Reply with the sentence only."
)


def enabled(settings: dict) -> bool:
    return (settings.get("contextual_chunks") or "off").strip().lower() == "on"


def build_input(document: str, chunk: str) -> str:
    excerpt = (document or "")[:DOCUMENT_CHARS]
    more = "\n[The document continues.]" if len(document or "") > DOCUMENT_CHARS else ""
    return (f"<document>\n{excerpt}{more}\n</document>\n\n"
            f"<passage>\n{(chunk or '')[:CHUNK_CHARS]}\n</passage>")


def clean(raw: str) -> str:
    line = " ".join((raw or "").split())
    return line[:CONTEXT_CHARS]


async def contexts_for(document: str, chunks: list[str], settings: dict, bot,
                       complete=None) -> list[str]:
    """A context sentence per chunk, empty where none could be written."""
    if not enabled(settings) or not chunks:
        return ["" for _ in chunks]

    endpoint = roles.endpoint_for("context", bot, settings)
    if not endpoint.available:
        print("[Context] Contextual chunks are on but no model is available; indexing without.")
        return ["" for _ in chunks]

    complete = complete or LLMAdapter.complete
    gate = asyncio.Semaphore(CONCURRENCY)

    async def one(chunk: str) -> str:
        async with gate:
            raw = await complete(
                base_url=endpoint.base_url, api_key=endpoint.api_key, model_name=endpoint.model,
                system_prompt=PROMPT, user_message=build_input(document, chunk),
                temperature=0.0, max_tokens=120, merge_system=endpoint.merge_system)
            return clean(raw)

    return list(await asyncio.gather(*(one(chunk) for chunk in chunks)))
