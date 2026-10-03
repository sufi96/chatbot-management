"""Admin-plane routes, called by Laravel and nobody else.

The engine listens on a port anything on the host can reach, so every route
here requires a shared token. Without it, re-index and search would let a
passer-by read a workspace's content.
"""
from fastapi import APIRouter, BackgroundTasks, Depends, Header, HTTPException
from pydantic import BaseModel
from sqlalchemy.ext.asyncio import AsyncSession

import httpx

import answer_cache
import database
import websearch
from config import settings
from database import get_db, get_settings
from kb.embedding import EmbeddingClient
from kb.indexer import index_source
from kb.reindex import prepare_vector_column, sources_to_reindex
from kb import rerank
from kb.retrieval import retrieve_for_collections
from kb.store import make_store

router = APIRouter(prefix="/api/v1/kb", tags=["knowledge-base"])


async def require_admin_token(x_admin_token: str = Header(None)) -> None:
    expected = settings.ADMIN_API_TOKEN
    if not expected:
        raise HTTPException(status_code=503,
                            detail="ADMIN_API_TOKEN is not set on the engine.")
    if x_admin_token != expected:
        raise HTTPException(status_code=401, detail="Invalid admin token.")


async def _index_in_background(source_id: str) -> None:
    async with database.async_session_factory() as session:
        await index_source(session, source_id)


@router.post("/sources/{source_id}/index", dependencies=[Depends(require_admin_token)])
async def start_indexing(source_id: str, background: BackgroundTasks):
    background.add_task(_index_in_background, source_id)
    return {"status": "accepted", "source_id": source_id}


@router.delete("/sources/{source_id}/chunks", dependencies=[Depends(require_admin_token)])
async def delete_chunks(source_id: str, db: AsyncSession = Depends(get_db)):
    source = await db.get(database.KbSource, source_id)
    await make_store(db).delete_source(source_id)
    # An answer cached from this source would outlive it otherwise.
    if source is not None:
        await answer_cache.forget_collection(db, source.collection_id)
    return {"status": "deleted", "source_id": source_id}


class EmbeddingTestRequest(BaseModel):
    base_url: str
    api_key: str = ""
    model: str


@router.post("/embedding/test", dependencies=[Depends(require_admin_token)])
async def test_embedding(req: EmbeddingTestRequest):
    client = EmbeddingClient(req.base_url, req.api_key, req.model)
    try:
        vectors = await client.embed(["connection test"])
    except Exception as exc:
        return {"ok": False, "message": str(exc)[:300]}
    return {"ok": True, "dimensions": len(vectors[0]),
            "message": f"Answered with {len(vectors[0])} dimensions."}


class EmbeddingModelsRequest(BaseModel):
    base_url: str
    api_key: str = ""


@router.post("/embedding/models", dependencies=[Depends(require_admin_token)])
async def list_embedding_models(req: EmbeddingModelsRequest):
    client = EmbeddingClient(req.base_url, req.api_key, "")
    try:
        models = await client.list_models()
    except Exception as exc:
        return {"ok": False, "models": [], "message": str(exc)[:300]}
    return {"ok": True, "models": models,
            "message": f"{len(models)} models available."}


class SearchRequest(BaseModel):
    """Retrieval preview, used by the tuning tools rather than by a chat."""
    collection_ids: list[str]
    query: str
    mode: str = "hybrid"
    top_k: int = 5
    candidates: int = 30
    min_score: float = 0.0
    # Only read when the install has a reranker, as it is in a chat.
    rerank_min_score: float = 0.0
    # Read when it has not, as it is in a chat.
    min_similarity: float = 0.0
    # The bot settings of the same names; 1 and 0 are what a chat did before
    # either existed.
    keyword_weight: float = 1.0
    neighbours: int = 0


@router.post("/search", dependencies=[Depends(require_admin_token)])
async def search(req: SearchRequest, db: AsyncSession = Depends(get_db)):
    # The playground reranks exactly when a chat would, so a floor tuned here
    # holds in a conversation.
    results = await retrieve_for_collections(
        db, req.collection_ids, req.query,
        mode=req.mode, top_k=req.top_k,
        candidates=req.candidates, min_score=req.min_score,
        reranker=rerank.client_for(await get_settings(db)),
        rerank_min_score=req.rerank_min_score,
        min_similarity=req.min_similarity,
        keyword_weight=req.keyword_weight,
        neighbours=max(0, min(2, req.neighbours)),
    )
    return {"reranked": any(r.reranked for r in results), "results": [
        {"chunk_id": r.chunk_id, "source_id": r.source_id,
         "content": r.content, "score": r.score,
         "heading_path": r.heading_path,
         # What the similarity floor is tuned by.
         "similarity": r.similarity}
        for r in results
    ]}


class WebSearchTestRequest(BaseModel):
    provider: str
    api_key: str = ""


@router.post("/websearch/test", dependencies=[Depends(require_admin_token)])
async def test_web_search(req: WebSearchTestRequest):
    """One real search with a key, reporting the provider's own complaint.

    websearch.search swallows every failure so a chat never breaks; a key test
    needs the opposite, so the provider's adapter is called directly.
    """
    adapter = websearch.PROVIDERS.get((req.provider or "").strip().lower())
    if adapter is None:
        return {"ok": False, "message": f"Unknown provider {req.provider!r}."}
    try:
        results = await adapter("opening hours", 3, None, req.api_key, None)
    except httpx.HTTPStatusError as exc:
        code = exc.response.status_code
        reason = {401: "the key was rejected", 403: "the key is not allowed to search",
                  429: "the plan's rate limit or quota is used up"}.get(code, "the provider refused")
        return {"ok": False, "message": f"HTTP {code}: {reason}."}
    except Exception as exc:
        return {"ok": False, "message": f"Could not reach the provider: {str(exc)[:200]}"}
    return {"ok": True, "message": f"It works: {len(results)} results for a test search."}


class ReindexRequest(BaseModel):
    dimensions: int = 768


async def _reindex_in_background(source_ids: list[str]) -> None:
    async with database.async_session_factory() as session:
        for source_id in source_ids:
            await index_source(session, source_id)


@router.post("/reindex", dependencies=[Depends(require_admin_token)])
async def reindex_everything(req: ReindexRequest, background: BackgroundTasks,
                             db: AsyncSession = Depends(get_db)):
    await prepare_vector_column(db, req.dimensions)
    source_ids = await sources_to_reindex(db)
    background.add_task(_reindex_in_background, source_ids)
    return {"status": "accepted", "sources": len(source_ids)}
