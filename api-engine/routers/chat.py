import asyncio
import json
import time
import uuid
from typing import List, Dict, Optional
from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.responses import StreamingResponse
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select
from pydantic import BaseModel

from database import (get_db, get_settings, provider_endpoint, BotProfile, System,
                      ChatConversation, ChatMessage, BotKbCollection)
import guard
import intent
import roles
import sources
from kb.retrieval import augment_system_prompt
from llm_adapter import LLMAdapter
from reasoning import TranscriptCollector


router = APIRouter(prefix="/api/v1/chat", tags=["chat"])

class ChatStreamRequest(BaseModel):
    bot_id: str
    session_id: str
    message: str
    history: Optional[List[Dict[str, str]]] = []

async def save_assistant_message(conv_id: str, **fields) -> None:
    """Write the bot's side of an exchange once its stream has ended.

    A new session, because the stream outlives the request's own. A failure is
    logged and swallowed: the visitor already has the answer, and a lost log
    row must not look like a broken reply.
    """
    try:
        from database import async_session_factory
        async with async_session_factory() as session:
            session.add(ChatMessage(id=str(uuid.uuid4()), conversation_id=conv_id,
                                    sender="assistant", **fields))
            await session.commit()
    except Exception as error:
        print(f"[Chat Log Error] Could not save assistant response: {error}")


@router.post("/stream")
async def chat_stream(
    req: ChatStreamRequest, 
    request: Request,
    db: AsyncSession = Depends(get_db)
):
    # The visitor's wait starts when the message arrives, so the times saved
    # with the answer include the intent, guard and source steps too.
    started = time.monotonic()

    def elapsed_ms() -> int:
        return int((time.monotonic() - started) * 1000)

    # Retrieve bot profile and associated system
    stmt = select(BotProfile).where(BotProfile.id == req.bot_id, BotProfile.is_active.is_(True),
                                    BotProfile.deleted_at.is_(None))
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

    engine_settings = await get_settings(db)
    enabled = sources.enabled_for(bot)

    guarding = bool(bot.guard_enabled)

    # Whether to search, and for what. A greeting is settled by word rules and
    # costs nothing. A bot told to understand follow-ups also has the intent
    # model rewrite the question, so the sources search what the visitor meant.
    # On a guarded bot the input check runs beside it rather than before it,
    # so the visitor waits for the slower of the two, not both in turn.
    reading = intent.decide(bot, req.message, req.history or [], engine_settings, enabled)
    if guarding:
        decision, verdict = await asyncio.gather(
            reading, guard.check(bot, req.message, engine_settings))
    else:
        decision, verdict = await reading, guard.Verdict()

    blocked = not verdict.safe
    # A refused message consults nothing: searching for it would only put
    # material about the harm in front of a model that is not going to answer.
    message_is_a_question = decision.is_question and not blocked

    if decision.intent or blocked:
        if decision.intent:
            # Kept on the visitor's own row, beside the words it was read from.
            user_msg.intent = decision.intent
            user_msg.intent_query = decision.query if decision.query != req.message else None
        if blocked:
            user_msg.guard_flag = verdict.category or "unsafe"
        await db.commit()

    # The sources are consulted in the operator's order until one of them has
    # something. Nothing here decides which source suits the question; that was
    # a router, and an order is what an operator can actually configure.
    found = None
    if message_is_a_question and any(enabled.values()):
        rows = await db.execute(
            select(BotKbCollection.collection_id).where(BotKbCollection.bot_id == bot.id))
        collection_ids = list(rows.scalars().all())

        found = await sources.resolve(
            sources.normalise(bot.source_order),
            enabled,
            sources.build_attempts(db, bot, decision.query, engine_settings, collection_ids))

    context_block = found.context_block if found else ""
    fallback = sources.fallback_for(bot, message_is_a_question, enabled)

    final_prompt = augment_system_prompt(bot.system_prompt or "", context_block, fallback)

    # Read once, outside the generator: the endpoint belongs to the provider
    # the bot points at, not to the bot.
    bot_base_url, bot_api_key = provider_endpoint(bot)

    # Resolved once, for the trace only. The database attempt resolves the same
    # endpoint for itself; this records which model that was.
    sql_model = roles.endpoint_for("sql", bot, engine_settings).model

    # The model behind each job that ran, for the trace written after the stream.
    used_models = {"intent": decision.model}
    if found and found.sql:
        used_models["sql"] = sql_model
    if found and found.reranked_by:
        used_models["rerank"] = found.reranked_by
    if verdict.model:
        used_models["guard"] = verdict.model

    searched = message_is_a_question and any(enabled.values())
    answer_fields = {
        "source_kind": sources.answer_kind(found, searched, blocked),
        # What the widget was shown, so the page can count which documents
        # and sites actually answer visitors.
        "citations": json.dumps(found.citations) if (found and found.citations) else None,
    }

    async def sse_event_stream():
        if blocked:
            # The refusal stands in for the answer, in the shape the widget
            # already draws. No sources, no model, no meta.
            refusal = guard.refusal_for(bot)
            yield f"data: {json.dumps({'content': refusal})}\n\n"
            waited = elapsed_ms()
            # Written down before [DONE], for the reason given below.
            await save_assistant_message(
                conv_id, content=refusal,
                model_trace=roles.model_trace(None, used_models),
                first_token_ms=waited, response_ms=waited, **answer_fields)
            yield "data: [DONE]\n\n"
            return

        transcript = TranscriptCollector()
        saved = False
        first_token_ms = None

        async def finish():
            """Check the answer if the bot is guarded, then write it down."""
            nonlocal saved
            # Taken before the guard checks the answer: the visitor already
            # had the whole reply by then.
            response_ms = elapsed_ms()
            full_text = transcript.answer
            if not full_text:
                saved = True
                return

            flag = None
            if guarding:
                # After the stream, not before: holding every answer back
                # for a check would make every guarded bot feel broken.
                # What was sent cannot be unsent, so it is flagged.
                checked = await guard.check(bot, guard.exchange(req.message, full_text),
                                            engine_settings)
                if not checked.safe:
                    flag = checked.category or "unsafe"
                if checked.model:
                    used_models["guard"] = checked.model

            await save_assistant_message(
                conv_id,
                content=full_text,
                reasoning=transcript.thinking,
                tokens_used=transcript.tokens,
                tokens_in=transcript.tokens_in,
                tokens_out=transcript.tokens_out,
                # An operator auditing a wrong answer needs the
                # statement, not a guess at it.
                db_sql=(found.sql or None) if found else None,
                db_row_count=found.row_count if (found and found.sql) else None,
                # The model per job, so a wrong answer can be traced to
                # the machine that gave it.
                model_trace=roles.model_trace(
                    transcript.model or bot.model_name, used_models),
                guard_flag=flag,
                first_token_ms=first_token_ms,
                response_ms=response_ms,
                **answer_fields,
            )
            saved = True

        # Sent before the tokens so the widget can name its sources. An older
        # widget ignores an event type it does not know. A web source carries
        # a url the widget turns into a link; a document and a database
        # answer do not, and the widget draws those as plain text already.
        # The kind travels with them so the widget can mark which of the three
        # answered, which the titles alone do not say.
        if found and found.citations:
            payload = {"type": "sources", "kind": found.kind,
                       "sources": found.citations}
            yield f"data: {json.dumps(payload)}\n\n"
        try:
            async for chunk in LLMAdapter.stream_chat(
                base_url=bot_base_url,
                api_key=bot_api_key,
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
                if chunk.startswith("data: "):
                    raw = chunk[6:].strip()
                    # Held back. A client that stops reading at [DONE] closes
                    # the connection, and the server cancels whatever the
                    # stream still had to do, so the answer is written down
                    # first and [DONE] is sent after it.
                    if raw == "[DONE]":
                        continue
                    try:
                        payload = json.loads(raw)
                        transcript.observe(payload)
                        # Thinking counts: the widget shows it as it arrives.
                        if first_token_ms is None and (payload.get("content") or payload.get("reasoning")):
                            first_token_ms = elapsed_ms()
                    except Exception:
                        pass
                yield chunk

            await finish()
            yield "data: [DONE]\n\n"

        finally:
            # A visitor who leaves mid-answer cancels the stream before the
            # save above ran. Shielded, so what they were shown is still logged.
            if not saved:
                await asyncio.shield(finish())

    return StreamingResponse(
        sse_event_stream(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no"  # Disables proxy buffering for Nginx/FrankenPHP
        }
    )
