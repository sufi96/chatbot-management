from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from pydantic import BaseModel
from typing import Optional

from database import get_db, BotProfile, System
from llm_adapter import LLMAdapter
from routers.kb import require_admin_token

router = APIRouter(prefix="/api/v1/bot", tags=["bot"])


def opacity(value) -> int:
    """A picture's opacity as a percentage. Unset is fully shown; 0 is kept."""
    return 100 if value is None else max(0, min(100, int(value)))

class ConnectionTestRequest(BaseModel):
    base_url: str
    api_key: Optional[str] = ""
    model_name: str

@router.get("/{bot_id}/config")
async def get_bot_public_config(bot_id: str, db: AsyncSession = Depends(get_db)):
    """Fetch public configuration for embedding widget."""
    stmt = select(BotProfile).where(BotProfile.id == bot_id, BotProfile.is_active.is_(True),
                                    BotProfile.deleted_at.is_(None))
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
        # Empty means the header follows the widget colour.
        "widget_header_color": bot.widget_header_color or "",
        "widget_header_text_color": bot.widget_header_text_color or "#FFFFFF",
        "widget_header_image_url": bot.widget_header_image_url or "",
        "widget_header_image_opacity": opacity(bot.widget_header_image_opacity),
        "widget_background_color": bot.widget_background_color or "#FAFAFA",
        "widget_background_image_url": bot.widget_background_image_url or "",
        "widget_background_image_opacity": opacity(bot.widget_background_image_opacity),
        "widget_position": bot.widget_position or "bottom-right",
        "launcher_icon_url": bot.launcher_icon_url or "",
        "launcher_shape": bot.launcher_shape or "circle",
        "launcher_size": bot.launcher_size or 60,
        "close_icon_url": bot.close_icon_url or "",
        "close_shape": bot.close_shape or "circle",
        "close_size": bot.close_size or 52,
        "bot_avatar_url": bot.bot_avatar_url or "",
        "avatar_shape": bot.avatar_shape or "circle",
    }

class FetchModelsRequest(BaseModel):
    base_url: str
    api_key: Optional[str] = ""

# These two carry a provider's API key and call whatever URL they are given, so
# only the portal may use them. It looks the key up and sends its admin token.
@router.post("/fetch-models", dependencies=[Depends(require_admin_token)])
async def fetch_available_models(req: FetchModelsRequest):
    """Fetch available models from Ollama or OpenAI-compatible endpoint."""
    res = await LLMAdapter.fetch_models(
        base_url=req.base_url,
        api_key=req.api_key or ""
    )
    return res

@router.post("/test-connection", dependencies=[Depends(require_admin_token)])
async def test_llm_connection(req: ConnectionTestRequest):
    """Test connection to an Ollama or custom OpenAI-compatible endpoint."""
    res = await LLMAdapter.test_connection(
        base_url=req.base_url,
        api_key=req.api_key or "",
        model_name=req.model_name
    )
    return res
