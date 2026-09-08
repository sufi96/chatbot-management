import json
import uuid
from typing import List, Dict, Optional
from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.responses import StreamingResponse
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from pydantic import BaseModel

from database import get_db, BotProfile, System, ChatConversation, ChatMessage
from llm_adapter import LLMAdapter

router = APIRouter(prefix="/api/v1/chat", tags=["chat"])

class ChatStreamRequest(BaseModel):
    bot_id: str
    session_id: str
    message: str
    history: Optional[List[Dict[str, str]]] = []

@router.post("/stream")
async def chat_stream(
    req: ChatStreamRequest, 
    request: Request,
    db: AsyncSession = Depends(get_db)
):
    # Retrieve bot profile and associated system
    stmt = select(BotProfile).where(BotProfile.id == req.bot_id, BotProfile.is_active == 1)
    result = await db.execute(stmt)
    bot = result.scalars().first()

    if not bot:
        raise HTTPException(status_code=404, detail="Bot profile not found or inactive")

    # Fetch parent system to check origin domain whitelist
    stmt_sys = select(System).where(System.id == bot.system_id)
    sys_result = await db.execute(stmt_sys)
    system = sys_result.scalars().first()

    origin_header = request.headers.get("origin", "")
    if system and system.allowed_origins and system.allowed_origins.strip() != "*":
        allowed = [o.strip().rstrip("/") for o in system.allowed_origins.split(",") if o.strip()]
        expanded_allowed = set(allowed)
        for o in allowed:
            if "localhost" in o:
                expanded_allowed.add(o.replace("localhost", "127.0.0.1"))
            elif "127.0.0.1" in o:
                expanded_allowed.add(o.replace("127.0.0.1", "localhost"))

        normalized_origin = origin_header.rstrip("/")
        if normalized_origin and normalized_origin not in expanded_allowed:
            raise HTTPException(status_code=403, detail=f"Domain {origin_header} is not allowed to embed this bot.")

    # Find or create conversation session
    conv_stmt = select(ChatConversation).where(
        ChatConversation.bot_id == bot.id,
        ChatConversation.session_id == req.session_id
    )
    conv_res = await db.execute(conv_stmt)
    conversation = conv_res.scalars().first()

    if not conversation:
        conversation = ChatConversation(
            id=str(uuid.uuid4()),
            bot_id=bot.id,
            session_id=req.session_id,
            origin=origin_header
        )
        db.add(conversation)
        await db.flush()

    # Log user message
    user_msg = ChatMessage(
        id=str(uuid.uuid4()),
        conversation_id=conversation.id,
        sender="user",
        content=req.message
    )
    db.add(user_msg)
    await db.commit()

    conv_id = conversation.id

    async def sse_event_stream():
        collected_response = []
        try:
            async for chunk in LLMAdapter.stream_chat(
                base_url=bot.base_url,
                api_key=bot.api_key or "",
                model_name=bot.model_name,
                system_prompt=bot.system_prompt or "",
                temperature=bot.temperature or 0.7,
                max_tokens=bot.max_tokens or 1024,
                history=req.history,
                user_message=req.message
            ):
                # Extract text to save to DB
                if chunk.startswith("data: "):
                    raw = chunk[6:].strip()
                    if raw != "[DONE]":
                        try:
                            parsed = json.loads(raw)
                            if "content" in parsed:
                                collected_response.append(parsed["content"])
                        except Exception:
                            pass
                yield chunk

        finally:
            # Persist assistant response after stream completes
            full_text = "".join(collected_response).strip()
            if full_text:
                try:
                    # Use a new session since streaming happens outside original request context
                    from database import async_session_factory
                    async with async_session_factory() as post_session:
                        bot_msg = ChatMessage(
                            id=str(uuid.uuid4()),
                            conversation_id=conv_id,
                            sender="assistant",
                            content=full_text
                        )
                        post_session.add(bot_msg)
                        await post_session.commit()
                except Exception as log_err:
                    print(f"[Chat Log Error] Could not save assistant response: {log_err}")

    return StreamingResponse(
        sse_event_stream(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no"  # Disables proxy buffering for Nginx/FrankenPHP
        }
    )
