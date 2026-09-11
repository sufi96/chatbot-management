import json
import uuid
from typing import List, Dict, Optional
from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.responses import StreamingResponse
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from pydantic import BaseModel

from database import (get_db, get_settings, BotProfile, System, ChatConversation,
                      ChatMessage, BotKbCollection, KbSource)
from kb.gating import should_retrieve
from kb.retrieval import (augment_system_prompt, build_context_block,
                          fit_to_budget, retrieve_for_collections)
from llm_adapter import LLMAdapter
import websearch
from websearch.context import build_web_context_block, fit_results_to_budget
from websearch.gating import web_search_runs
from reasoning import TranscriptCollector


def key_for(settings: dict) -> str:
    """The API key belonging to whichever provider is configured.

    DuckDuckGo has no key setting, so this returns an empty string for it,
    which is exactly what the adapter expects.
    """
    provider = settings.get("web_search_provider", "duckduckgo")
    return settings.get(f"web_search_{provider}_key", "")

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
    stmt = select(BotProfile).where(BotProfile.id == req.bot_id, BotProfile.is_active.is_(True))
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

    # Retrieval runs before the model call. A failure here must never break a
    # chat, so it degrades to answering without context.
    retrieved = []
    source_titles = {}
    # A greeting is not a question. Skipping saves an embedding call and two
    # searches, and stops five irrelevant passages reaching the model.
    # Needed on its own, because a bot with retrieval off still has to know
    # whether the message was a question before the web is consulted.
    message_is_a_question = should_retrieve(req.message)
    retrieval_ran = bool(bot.retrieval_enabled) and message_is_a_question

    engine_settings = await get_settings(db)

    if retrieval_ran:
        try:
            rows = await db.execute(
                select(BotKbCollection.collection_id).where(BotKbCollection.bot_id == bot.id))
            collection_ids = list(rows.scalars().all())

            retrieved = await retrieve_for_collections(
                db, collection_ids, req.message,
                mode=bot.retrieval_mode or "hybrid",
                top_k=bot.retrieval_top_k or 5,
                candidates=bot.retrieval_candidates or 30,
                min_score=bot.retrieval_min_score or 0.0,
            )
            # Bigger chunks mean a bigger prompt. Trim before the titles are
            # looked up so the citations match what the model actually saw.
            retrieved = fit_to_budget(
                retrieved, int(engine_settings["context_char_budget"]))

            if retrieved:
                title_rows = await db.execute(
                    select(KbSource.id, KbSource.title).where(
                        KbSource.id.in_([r.source_id for r in retrieved])))
                source_titles = {row[0]: row[1] for row in title_rows.all()}
        except Exception as retrieval_error:
            print(f"[Retrieval] Skipped, answering without context: {retrieval_error}")
            retrieved = []

    # The web is the fallback, so it is consulted only once the bot's own
    # material has had its turn and come back with nothing.
    web_results = []
    if web_search_runs(bot.web_search_enabled, message_is_a_question, len(retrieved)):
        web_results = await websearch.search(
            provider=engine_settings["web_search_provider"],
            query=req.message,
            count=int(bot.web_search_max_results or 3),
            country=bot.web_search_country,
            api_key=key_for(engine_settings),
        )
        web_results = fit_results_to_budget(
            web_results, int(engine_settings["context_char_budget"]))

    context_block = build_context_block(retrieved, source_titles)
    if not context_block:
        context_block = build_web_context_block(web_results)

    fallback = bot.retrieval_fallback or "say_unknown"
    if not retrieval_ran:
        # A gated message must never be told the answer is missing from the
        # material. A greeting gets a greeting.
        fallback = "answer_anyway"
    final_prompt = augment_system_prompt(bot.system_prompt or "", context_block, fallback)

    async def sse_event_stream():
        transcript = TranscriptCollector()
        if retrieved:
            # Sent before the tokens so the widget can name its sources. An
            # older widget ignores an event type it does not know.
            sources_payload = {"type": "sources", "sources": [
                {"n": i + 1, "title": source_titles.get(r.source_id, "Untitled"),
                 "source_id": r.source_id}
                for i, r in enumerate(retrieved)
            ]}
            yield f"data: {json.dumps(sources_payload)}\n\n"
        elif web_results:
            # Web sources carry a url, which the widget turns into a link the
            # visitor can follow and check for themselves.
            sources_payload = {"type": "sources", "sources": [
                {"n": i + 1, "title": item.title, "url": item.url}
                for i, item in enumerate(web_results)
            ]}
            yield f"data: {json.dumps(sources_payload)}\n\n"
        try:
            async for chunk in LLMAdapter.stream_chat(
                base_url=bot.base_url,
                api_key=bot.api_key or "",
                model_name=bot.model_name,
                system_prompt=final_prompt,
                temperature=bot.temperature or 0.7,
                max_tokens=bot.max_tokens or 1024,
                history=req.history,
                user_message=req.message,
                top_p=bot.top_p if bot.top_p is not None else 1.0,
                top_k_sampling=bot.top_k_sampling,
                presence_penalty=bot.presence_penalty or 0.0,
                frequency_penalty=bot.frequency_penalty or 0.0,
                thinking_level=bot.thinking_level or "off"
            ):
                # Extract text to save to DB
                if chunk.startswith("data: "):
                    raw = chunk[6:].strip()
                    if raw != "[DONE]":
                        try:
                            transcript.observe(json.loads(raw))
                        except Exception:
                            pass
                yield chunk

        finally:
            # Persist assistant response after stream completes
            full_text = transcript.answer
            if full_text:
                try:
                    # Use a new session since streaming happens outside original request context
                    from database import async_session_factory
                    async with async_session_factory() as post_session:
                        bot_msg = ChatMessage(
                            id=str(uuid.uuid4()),
                            conversation_id=conv_id,
                            sender="assistant",
                            content=full_text,
                            reasoning=transcript.thinking,
                            tokens_used=transcript.tokens
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
