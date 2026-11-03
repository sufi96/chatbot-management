import asyncio
import json
import time
import uuid
from dataclasses import dataclass
from datetime import datetime, timedelta
from typing import List, Dict, Optional
from fastapi import APIRouter, Depends, HTTPException, Request
from fastapi.responses import StreamingResponse
from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, update
from pydantic import BaseModel, Field

from config import settings as app_settings
from database import (get_db, get_settings, provider_endpoint, provider_merges_system, BotProfile, System,
                      ChatConversation, ChatMessage, ChatToolCall, BotKbCollection)
import answer_cache
import grounding
import guard
import history
import intent
import leak
import roles
import shield
import sources
import trace_token
from connectors import rest
from kb.embedding import client_for as embedding_client_for
from kb.retrieval import augment_system_prompt
from llm_adapter import LLMAdapter
from reasoning import TranscriptCollector


router = APIRouter(prefix="/api/v1/chat", tags=["chat"])

# What the widget shows while the visitor waits, one line per step the engine
# actually takes. An older widget ignores an event type it does not know.
STATUS_TEXT = {
    "reading": "Understanding intent...",
    "documents": "Consulting the knowledge base...",
    "database": "Retrieving records...",
    "web": "Researching the web...",
    "tools": "Working with connected systems...",
    "writing": "Composing a response...",
}


def status(stage: str) -> str:
    payload = {"type": "status", "stage": stage, "text": STATUS_TEXT[stage]}
    return f"data: {json.dumps(payload)}\n\n"


@dataclass
class Consulted:
    found: object


async def consult(order: list, enabled: dict, attempts: dict, combine: bool = False):
    """Runs the source cascade, yielding each source's name as it is tried.

    The cascade itself stays unaware of the widget: each attempt is wrapped to
    announce itself on a queue, and this reads the queue while the cascade
    runs. The last thing yielded is the cascade's result.
    """
    tried = asyncio.Queue()

    def announced(name, attempt):
        async def run():
            tried.put_nowait(name)
            return await attempt()
        return run

    cascade = asyncio.ensure_future(sources.resolve(
        order, enabled, {name: announced(name, attempt) for name, attempt in attempts.items()},
        combine))
    try:
        while True:
            waiting = asyncio.ensure_future(tried.get())
            await asyncio.wait({cascade, waiting}, return_when=asyncio.FIRST_COMPLETED)
            if waiting.done():
                yield waiting.result()
                continue
            waiting.cancel()
            break
        yield Consulted(cascade.result())
    finally:
        # A visitor who leaves mid-search takes the search with them.
        cascade.cancel()

SUMMARY_CHARS = 300


def trace_line(record) -> str:
    """One line of the test chat's trace: a skill loaded, or a call and how it went."""
    if record.status == "loaded":
        return f"Loaded skill {(record.arguments or {}).get('name', '')}"
    args = ", ".join(f"{k}={v}" for k, v in (record.arguments or {}).items())
    flag = f" (flagged: {record.flag})" if record.flag else ""
    return f"{record.name}({args}) → {record.status.replace('_', ' ')}{flag}"[:300]


def action_summary(record) -> str:
    """What a change waiting on the visitor will do, in one line for the Confirm card."""
    what = (record.tool.description if record.tool else "") or record.name
    args = ", ".join(f"{k}={v if isinstance(v, (str, int, float, bool)) else json.dumps(v)}"
                     for k, v in (record.arguments or {}).items())
    return f"{what}: {args}"[:SUMMARY_CHARS] if args else what[:SUMMARY_CHARS]


async def log_tool_calls(db, conv_id: str, message_id: str, records: list):
    """One row per tool call. Returns the record waiting on the visitor, if any,
    with row_id set to its row's id."""
    pending = None
    for record in records:
        row_id = str(uuid.uuid4())
        db.add(ChatToolCall(
            id=row_id, conversation_id=conv_id, message_id=message_id,
            connector_tool_id=record.tool.id if record.tool else None,
            tool_name=(record.name or "")[:64], arguments=record.arguments or {},
            status=record.status, flag=record.flag,
            result_excerpt=record.excerpt or None, duration_ms=record.ms or None))
        if record.status == "awaiting_confirm":
            record.row_id = row_id
            pending = record
        if record.name == "request_human" and record.status == "ran" and record.tool and record.tool.local:
            conversation = await db.get(ChatConversation, conv_id)
            if conversation is not None:
                conversation.handover_requested_at = datetime.utcnow()
                conversation.handover_reason = str((record.arguments or {}).get("reason") or "")[:500] or None
    await db.commit()
    return pending


MESSAGE_CHARS = 4000


class ChatStreamRequest(BaseModel):
    bot_id: str
    session_id: str
    # Long enough for any real question; a cap stops one request filling the
    # model's context, and the guard's, with a pasted document.
    message: str = Field(max_length=MESSAGE_CHARS)
    # What the browser says came before. Read only when the install trusts it
    # or the caller holds the admin token; see history.py.
    history: Optional[List[Dict[str, str]]] = []
    # Set only by the portal's own test chat; see trace_token.py.
    trace_token: Optional[str] = None

async def save_assistant_message(conv_id: str, **fields) -> None:
    """Write the bot's side of an exchange once its stream has ended.

    A new session, because the stream outlives the request's own. A failure is
    logged and swallowed: the visitor already has the answer, and a lost log
    row must not look like a broken reply.
    """
    try:
        from database import async_session_factory
        async with async_session_factory() as session:
            # An answer whose tool calls are logged arrives with its id made
            # already, so those rows could point at it before it existed.
            message_id = fields.pop("id", None) or str(uuid.uuid4())
            session.add(ChatMessage(id=message_id, conversation_id=conv_id,
                                    sender="assistant", **fields))
            await session.commit()
    except Exception as error:
        print(f"[Chat Log Error] Could not save assistant response: {error}")


def portal_origin_allowed(origin: str, portal_base_url: str) -> bool:
    """Whether a request comes from the console's own pages.

    The console assistant has no workspace and so no allowlist of its own. It
    answers the console and nothing else: a site that learns its id cannot
    borrow the platform's model. localhost and 127.0.0.1 count as one host, as
    they do for workspace allowlists.
    """
    from urllib.parse import urlsplit

    def key(url: str):
        parts = urlsplit(url.strip().rstrip("/"))
        host = (parts.hostname or "").replace("127.0.0.1", "localhost")
        port = parts.port or {"http": 80, "https": 443}.get(parts.scheme)
        return (parts.scheme, host, port)

    return bool(origin) and key(origin) == key(portal_base_url)


def check_origin(bot, system, origin_header: str) -> None:
    """Refuse a page that may not use this bot, with 403.

    The console assistant answers the console only. A workspace bot answers the
    origins on its workspace's allowlist, localhost and 127.0.0.1 counting as
    one. Shared with the voice routes, which spend the same accounts.
    """
    if bot.is_platform and not portal_origin_allowed(
            origin_header, app_settings.CONSOLE_ORIGIN or app_settings.PORTAL_BASE_URL):
        raise HTTPException(status_code=403, detail="This assistant only answers inside the console.")

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
    check_origin(bot, system, origin_header)

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

    # The evaluation runner sends a conversation it made up, and holds the
    # engine's admin token to say so. A browser never has it.
    caller_trusted = history.trusted(request.headers.get("x-admin-token"),
                                     app_settings.ADMIN_API_TOKEN)

    # Everything from here on happens inside the stream, so the headers go out
    # at once and the widget can say which step the visitor is waiting on
    # instead of guessing. The request's session stays open until the response
    # has been sent, which FastAPI has guaranteed since 0.118.
    async def sse_event_stream():
        engine_settings = await get_settings(db)
        enabled = sources.enabled_for(bot)

        guarding = bool(bot.guard_enabled)

        # The turns before this one, from the engine's own records unless the
        # install chose the browser's copy. See history.py.
        turns = await history.for_request(db, engine_settings, conv_id, user_msg.id,
                                          req.history, caller_trusted)

        # Instruction-shaped wording, caught by pattern before any model reads
        # the message. Instant, and independent of the guard switch.
        shield_mode = shield.mode_for(engine_settings)
        injected = shield.detect(req.message) if shield_mode != "off" else shield.Detection()

        # Whether to search, and for what. A greeting is settled by word rules and
        # costs nothing. A bot told to understand follow-ups also has the intent
        # model rewrite the question, so the sources search what the visitor meant.
        # On a guarded bot the input check runs beside it rather than before it,
        # so the visitor waits for the slower of the two, not both in turn.
        yield status("reading")
        if injected.found and shield_mode == "block":
            # Refused on wording alone: no model is asked anything about it.
            decision = intent.Decision(is_question=False, query=req.message)
            verdict = guard.Verdict(safe=False, category=shield.FLAG)
        else:
            reading = intent.decide(bot, req.message, turns, engine_settings, enabled)
            if guarding:
                decision, verdict = await asyncio.gather(
                    reading, guard.check(bot, req.message, engine_settings))
            else:
                decision, verdict = await reading, guard.Verdict()

        blocked = not verdict.safe
        # A refused message consults nothing: searching for it would only put
        # material about the harm in front of a model that is not going to answer.
        message_is_a_question = decision.is_question and not blocked

        flagged_input = ((verdict.category or "unsafe") if blocked
                         else (shield.FLAG if injected.found else None))
        if decision.intent or flagged_input:
            if decision.intent:
                # Kept on the visitor's own row, beside the words it was read from.
                user_msg.intent = decision.intent
                user_msg.intent_query = decision.query if decision.query != req.message else None
            if flagged_input:
                user_msg.guard_flag = flagged_input
            await db.commit()

        # The answer cache, for a bot that keeps one: a question close enough to
        # one already answered from the knowledge base gets that answer back.
        # Only a question that stands on its own is looked up. See answer_cache.py.
        caching = (message_is_a_question and enabled.get("documents")
                   and answer_cache.enabled(bot)
                   and answer_cache.standalone(decision, intent.earlier_turns(req.message, turns)))
        query_vector = None
        if caching:
            try:
                query_vector = (await embedding_client_for(engine_settings).embed([decision.query]))[0]
                cached = await answer_cache.lookup(db, bot, query_vector,
                                                   engine_settings["embedding_model"])
            except Exception as error:
                print(f"[Cache] Lookup failed, answering as usual: {error}")
                cached = None

            if cached:
                if cached.citations:
                    payload = {"type": "sources", "kind": cached.source_kind,
                               "sources": cached.citations}
                    yield f"data: {json.dumps(payload)}\n\n"
                yield status("writing")
                yield f"data: {json.dumps({'content': cached.answer})}\n\n"
                yield f"data: {json.dumps({'meta': {'model': 'answer cache'}})}\n\n"
                waited = elapsed_ms()
                await save_assistant_message(
                    conv_id, content=cached.answer, cache_hit=True,
                    source_kind=cached.source_kind,
                    citations=json.dumps(cached.citations) if cached.citations else None,
                    model_trace=roles.model_trace(None, {"intent": decision.model,
                                                         "cache": engine_settings["embedding_model"]}),
                    first_token_ms=waited, response_ms=waited)
                yield "data: [DONE]\n\n"
                return

        # The sources are consulted in the operator's order until one of them has
        # something. Nothing here decides which source suits the question; that was
        # a router, and an order is what an operator can actually configure.
        found = None
        # Every tool call the tools source made, answered or not. See agent.py.
        tool_log = []
        if message_is_a_question and any(enabled.values()):
            rows = await db.execute(
                select(BotKbCollection.collection_id).where(BotKbCollection.bot_id == bot.id))
            collection_ids = list(rows.scalars().all())

            combine = bool(getattr(bot, "combine_sources", False))
            # Two sources answering together share one prompt, so each gets half
            # the budget rather than doubling what the answer model is sent.
            # ponytail: halved before knowing whether both hit; share the
            # leftover if a lone hit is ever measurably cut short.
            source_settings = ({**engine_settings, "context_char_budget":
                                int(engine_settings["context_char_budget"]) // 2}
                               if combine else engine_settings)

            consulting = consult(
                sources.normalise(bot.source_order),
                enabled,
                sources.build_attempts(db, bot, decision.query, source_settings, collection_ids,
                                       tool_log=tool_log, turns=turns,
                                       **({"query_vector": query_vector} if query_vector else {})),
                combine)
            async for step in consulting:
                if isinstance(step, str):
                    yield status(step)
                else:
                    found = step.found

        context_block = found.context_block if found else ""
        fallback = sources.fallback_for(bot, message_is_a_question, enabled)

        # A found result with no material is a change awaiting Confirm or a
        # skill's steps: there is something to say, so not "nothing found".
        if found and not context_block and (found.guidance or any(
                call.status == "awaiting_confirm" for call in tool_log)):
            fallback = "answer_anyway"
        final_prompt = augment_system_prompt(bot.system_prompt or "", context_block, fallback)
        if found and found.guidance:
            final_prompt += ("\n\nFor this request, follow these steps from a skill the operator wrote:\n"
                             + found.guidance)

        # Written down now, so a change waiting on the visitor has an id the
        # widget's Confirm can name. The answer is made with the id they point at.
        answer_id = str(uuid.uuid4()) if tool_log else None
        confirm_event = None
        if tool_log:
            pending = await log_tool_calls(db, conv_id, answer_id, tool_log)
            if pending:
                summary = action_summary(pending)
                confirm_event = {"type": "confirm", "action_id": pending.row_id,
                                 "tool": pending.name, "summary": summary}
                final_prompt += (f"\n\nAn action is waiting for the visitor to confirm: {summary}. "
                                 "Tell them what will happen and ask them to press Confirm. "
                                 "Do not say it has been done.")

        # A reference nobody else knows, so a model reciting its instructions
        # is caught by the reference appearing. See leak.py.
        canary = leak.new_canary() if leak.enabled(engine_settings) else ""
        if canary:
            final_prompt += leak.instruction(canary)

        # The endpoint belongs to the provider the bot points at, not to the bot.
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
        if found and found.expanded_by:
            used_models["expand"] = found.expanded_by
        if tool_log:
            used_models["tools"] = bot.model_name
        if verdict.model:
            used_models["guard"] = verdict.model

        searched = message_is_a_question and any(enabled.values())
        answer_fields = {
            "source_kind": sources.answer_kind(found, searched, blocked),
            # What the widget was shown, so the page can count which documents
            # and sites actually answer visitors.
            "citations": json.dumps(found.citations) if (found and found.citations) else None,
        }
        if answer_id:
            answer_fields["id"] = answer_id

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
        leaked = False

        async def finish():
            """Check the answer if the bot asks for it, then write it down."""
            nonlocal saved
            # Taken before the checks run: the visitor already had the whole
            # reply by then.
            response_ms = elapsed_ms()

            if leaked:
                await save_assistant_message(
                    conv_id, content=guard.refusal_for(bot), guard_flag=leak.FLAG,
                    reasoning=transcript.thinking,
                    model_trace=roles.model_trace(transcript.model or bot.model_name, used_models),
                    first_token_ms=first_token_ms, response_ms=response_ms, **answer_fields)
                saved = True
                return

            full_text = transcript.answer
            if not full_text:
                saved = True
                return

            # After the stream, not before: holding every answer back for a
            # check would make every guarded bot feel broken. What was sent
            # cannot be unsent, so these flag it for the operator.
            async def output_guard():
                if not guarding:
                    return guard.Verdict()
                return await guard.check(bot, guard.exchange(req.message, full_text), engine_settings)

            async def grounding_check():
                if not (found and grounding.enabled(bot)):
                    return grounding.Verdict()
                return await grounding.check(bot, found.context_block, full_text, engine_settings)

            checked, grounded = await asyncio.gather(output_guard(), grounding_check())

            flag = None
            if not checked.safe:
                flag = checked.category or "unsafe"
            if checked.model:
                used_models["guard"] = checked.model
            if grounded.model:
                used_models["verify"] = grounded.model
            # A paraphrase carries no canary, so a long run of the bot's own
            # prompt copied word for word is flagged as well.
            if not flag and canary and leak.copies_prompt(full_text, bot.system_prompt or ""):
                flag = leak.FLAG

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
                grounded=grounded.grounded,
                grounding_note=grounded.claim or None,
                first_token_ms=first_token_ms,
                response_ms=response_ms,
                **answer_fields,
            )
            saved = True

            # Kept for the next visitor who asks the same thing, when nothing
            # about this answer says it should not be repeated.
            if (caching and query_vector is not None and found and not flag
                    and grounded.grounded is not False):
                try:
                    from database import async_session_factory
                    async with async_session_factory() as session:
                        await answer_cache.store(
                            session, bot, decision.query, query_vector,
                            engine_settings["embedding_model"], full_text,
                            found.citations, found.kind)
                except Exception as error:
                    print(f"[Cache] Could not keep the answer: {error}")

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
        if tool_log and trace_token.valid(req.trace_token, bot.id, app_settings.ADMIN_API_TOKEN,
                                          int(time.time())):
            for record in tool_log:
                yield f"data: {json.dumps({'type': 'trace', 'text': trace_line(record)})}\n\n"
        if confirm_event:
            yield f"data: {json.dumps(confirm_event)}\n\n"
        yield status("writing")

        # The answer is held back by as many characters as could still turn
        # into the canary; thinking has the canary blanked instead.
        answer_watch = leak.LeakWatch(canary) if canary else None
        thinking_watch = leak.LeakWatch(canary, redact=True) if canary else None

        def watched(payload: dict) -> dict | None:
            """The payload as it may be sent, or None when nothing may yet."""
            if not canary:
                return payload
            if payload.get("content"):
                text = answer_watch.feed(payload["content"])
                return {**payload, "content": text} if text else None
            if payload.get("reasoning"):
                text = thinking_watch.feed(payload["reasoning"])
                return {**payload, "reasoning": text} if text else None
            if payload.get("meta") or payload.get("reclassify"):
                # Whatever is held back goes out before the stream's last word.
                pending = []
                rest = answer_watch.flush()
                if rest:
                    pending.append({"content": rest})
                thought = thinking_watch.flush()
                if thought:
                    pending.append({"reasoning": thought})
                return {"batch": pending + [payload]}
            return payload

        upstream = LLMAdapter.stream_chat(
            base_url=bot_base_url,
            api_key=bot_api_key,
            model_name=bot.model_name,
            system_prompt=final_prompt,
            temperature=bot.temperature or 0.7,
            max_tokens=bot.max_tokens or 1024,
            history=turns,
            user_message=req.message,
            top_p=bot.top_p if bot.top_p is not None else 1.0,
            top_k_sampling=bot.top_k_sampling,
            presence_penalty=bot.presence_penalty or 0.0,
            frequency_penalty=bot.frequency_penalty or 0.0,
            thinking_level=bot.thinking_level or "off",
            merge_system=provider_merges_system(bot),
        )
        try:
            async for chunk in upstream:
                if not chunk.startswith("data: "):
                    yield chunk
                    continue

                raw = chunk[6:].strip()
                # Held back. A client that stops reading at [DONE] closes
                # the connection, and the server cancels whatever the
                # stream still had to do, so the answer is written down
                # first and [DONE] is sent after it.
                if raw == "[DONE]":
                    continue
                try:
                    payload = json.loads(raw)
                except Exception:
                    yield chunk
                    continue

                outgoing = watched(payload)
                if answer_watch is not None and answer_watch.leaked:
                    # The model began reciting its instructions. Nothing of the
                    # canary was sent; the bubble is replaced with the refusal.
                    leaked = True
                    before = (outgoing or {}).get("content") if outgoing else None
                    if before:
                        transcript.observe({"content": before})
                    print("[Leak] The answer repeated the confidential reference; stopped.")
                    yield f"data: {json.dumps({'type': 'retract', 'content': guard.refusal_for(bot)})}\n\n"
                    break
                if outgoing is None:
                    continue

                for piece in outgoing.get("batch") or [outgoing]:
                    transcript.observe(piece)
                    # Thinking counts: the widget shows it as it arrives.
                    if first_token_ms is None and (piece.get("content") or piece.get("reasoning")):
                        first_token_ms = elapsed_ms()
                    yield f"data: {json.dumps(piece)}\n\n"

            # A stream that ended without meta still owes what was held back.
            if canary and not leaked:
                for piece, key in ((answer_watch.flush(), "content"), (thinking_watch.flush(), "reasoning")):
                    if piece:
                        transcript.observe({key: piece})
                        yield f"data: {json.dumps({key: piece})}\n\n"

            await finish()
            yield "data: [DONE]\n\n"

        finally:
            await upstream.aclose()
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


CONFIRM_MINUTES = 10


class ActionDecision(BaseModel):
    bot_id: str
    session_id: str
    decision: str = Field(pattern="^(confirm|decline)$")


@router.post("/actions/{action_id}")
async def decide_action(action_id: str, req: ActionDecision, request: Request,
                        db: AsyncSession = Depends(get_db)):
    """The visitor's Confirm or Cancel on a change a tool was going to make.

    Only the conversation that was asked may answer, once, within ten minutes.
    The tool is looked up again rather than trusted from when it was asked: an
    admin may have switched it off or unlinked it in between.
    """
    from database import BotConnectorTool, Connector, ConnectorTool

    bot = (await db.execute(select(BotProfile).where(
        BotProfile.id == req.bot_id, BotProfile.is_active.is_(True),
        BotProfile.deleted_at.is_(None)))).scalars().first()
    if not bot:
        raise HTTPException(status_code=404, detail="Bot profile not found or inactive")
    system = (await db.execute(select(System).where(System.id == bot.system_id))).scalars().first()
    check_origin(bot, system, request.headers.get("origin", ""))

    action = (await db.execute(
        select(ChatToolCall).join(ChatConversation, ChatConversation.id == ChatToolCall.conversation_id)
        .where(ChatToolCall.id == action_id, ChatConversation.bot_id == bot.id,
               ChatConversation.session_id == req.session_id))).scalars().first()
    if not action:
        raise HTTPException(status_code=404, detail="No such action in this conversation.")
    if action.status != "awaiting_confirm":
        raise HTTPException(status_code=409, detail="This action has already been answered.")
    if datetime.utcnow() - action.created_at > timedelta(minutes=CONFIRM_MINUTES):
        action.status = "expired"
        await db.commit()
        raise HTTPException(status_code=409, detail="This action has expired. Ask again.")

    # Claimed in one conditional write before anything runs, so a double
    # click or a retry finds it taken and nothing is sent twice.
    claimed = await db.execute(
        update(ChatToolCall).where(ChatToolCall.id == action.id, ChatToolCall.status == "awaiting_confirm")
        .values(status="declined" if req.decision == "decline" else "running"))
    await db.commit()
    if claimed.rowcount != 1:
        raise HTTPException(status_code=409, detail="This action has already been answered.")
    await db.refresh(action)

    if req.decision == "decline":
        text = "Cancelled. Nothing was sent."
    else:
        found = (await db.execute(
            select(ConnectorTool, Connector)
            .join(Connector, Connector.id == ConnectorTool.connector_id)
            .join(BotConnectorTool, BotConnectorTool.connector_tool_id == ConnectorTool.id)
            .where(ConnectorTool.id == action.connector_tool_id, ConnectorTool.enabled.is_(True),
                   BotConnectorTool.bot_id == bot.id, Connector.system_id == bot.system_id))).first()
        if not found or not bot.agentic_enabled:
            action.status, text = "failed", "That action is no longer available, so nothing was sent."
        else:
            tool_row, connector = found
            outcome = await rest.call(connector, tool_row, action.arguments or {})
            action.duration_ms = outcome.ms or None
            action.result_excerpt = (outcome.detail or outcome.text or outcome.error or "")[:500] or None
            if outcome.ok:
                action.status, text = "confirmed", "Done."
            else:
                action.status, text = "failed", f"That didn't go through: {outcome.error}"

    db.add(ChatMessage(id=str(uuid.uuid4()), conversation_id=action.conversation_id,
                       sender="assistant", content=text, source_kind="tools"))
    await db.commit()

    return {"status": action.status, "text": text}
