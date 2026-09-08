from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from pydantic import BaseModel
from typing import Optional

from database import get_db, BotProfile, System
from llm_adapter import LLMAdapter

router = APIRouter(prefix="/api/v1/bot", tags=["bot"])

class ConnectionTestRequest(BaseModel):
    base_url: str
    api_key: Optional[str] = ""
    model_name: str

@router.get("/{bot_id}/config")
async def get_bot_public_config(bot_id: str, db: AsyncSession = Depends(get_db)):
    """Fetch public configuration for embedding widget."""
    stmt = select(BotProfile).where(BotProfile.id == bot_id, BotProfile.is_active == 1)
    result = await db.execute(stmt)
    bot = result.scalars().first()

    if not bot:
        raise HTTPException(status_code=404, detail="Active bot profile not found")

    return {
        "id": bot.id,
        "name": bot.name,
        "widget_title": bot.widget_title or bot.name,
        "widget_greeting": bot.widget_greeting or "Hello! How can I help you today?",
        "widget_primary_color": bot.widget_primary_color or "#4F46E5",
        "widget_position": bot.widget_position or "bottom-right",
        "launcher_icon_url": bot.launcher_icon_url or "",
        "launcher_shape": bot.launcher_shape or "circle",
        "bot_avatar_url": bot.bot_avatar_url or "",
        "avatar_shape": bot.avatar_shape or "circle",
    }

class FetchModelsRequest(BaseModel):
    base_url: str
    api_key: Optional[str] = ""

@router.post("/fetch-models")
async def fetch_available_models(req: FetchModelsRequest):
    """Fetch available models from Ollama or OpenAI-compatible endpoint."""
    res = await LLMAdapter.fetch_models(
        base_url=req.base_url,
        api_key=req.api_key or ""
    )
    return res

@router.post("/test-connection")
async def test_llm_connection(req: ConnectionTestRequest):
    """Test connection to an Ollama or custom OpenAI-compatible endpoint."""
    res = await LLMAdapter.test_connection(
        base_url=req.base_url,
        api_key=req.api_key or "",
        model_name=req.model_name
    )
    return res
