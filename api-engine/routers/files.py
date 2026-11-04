"""Files in and out of a conversation, over HTTP.

POST /api/v1/chat/files takes one attachment: it is checked, optionally
scanned, read, and stored for the conversation, and its id goes with the next
chat message. Only a bot with files in switched on takes uploads, and only
within its limits. See docs/superpowers/specs/2026-11-05-files-in-and-out-design.md.
"""
import hashlib
import secrets
import uuid
from datetime import datetime, timedelta

from fastapi import APIRouter, Depends, File, Form, HTTPException, Request, Response, UploadFile
from pydantic import BaseModel, Field
from sqlalchemy import func, or_, select
from sqlalchemy.ext.asyncio import AsyncSession

from config import settings as app_settings
from database import BotProfile, ChatConversation, ChatFile, System, get_db, get_settings
from files import checks, reading, storage
from files import scan as scanning
from files import settings as file_settings
from files.settings import IMAGE_TYPES
from routers.kb import require_admin_token

router = APIRouter(prefix="/api/v1/chat/files", tags=["files"])

MB = 1024 * 1024


async def bot_for(db, bot_id: str, request: Request) -> BotProfile:
    from routers.chat import check_origin

    bot = (await db.execute(select(BotProfile).where(
        BotProfile.id == bot_id, BotProfile.is_active.is_(True),
        BotProfile.deleted_at.is_(None)))).scalars().first()
    if not bot:
        raise HTTPException(status_code=404, detail="Bot profile not found or inactive")
    system = (await db.execute(select(System).where(System.id == bot.system_id))).scalars().first()
    check_origin(bot, system, request.headers.get("origin", ""))
    return bot


def ip_hash(request: Request) -> str | None:
    host = request.client.host if request.client else ""
    return hashlib.sha256(host.encode()).hexdigest() if host else None


async def uploads_today(db, bot_id: str, session_id: str, ip: str | None) -> int:
    since = datetime.utcnow() - timedelta(hours=24)
    same_visitor = ChatConversation.session_id == session_id
    if ip:
        same_visitor = or_(same_visitor, ChatFile.ip_hash == ip)
    return (await db.execute(
        select(func.count(ChatFile.id)).join(ChatConversation, ChatConversation.id == ChatFile.conversation_id)
        .where(ChatFile.bot_id == bot_id, ChatFile.direction == "in", ChatFile.created_at > since,
               same_visitor))).scalar_one()


async def store_file(db, bot, conversation_id: str, direction: str, name: str, checked, *,
                     message_id=None, scan_status="not_scanned", text=None, read_error=None, flag=None,
                     ip=None) -> ChatFile:
    """The bytes into storage and a chat_files row for them."""
    fs = file_settings.for_bot(bot)
    file_id = str(uuid.uuid4())
    key = f"{bot.id}/{file_id}.{checked.ext}"
    await storage.current().put(key, checked.data)
    now = datetime.utcnow()
    row = ChatFile(id=file_id, conversation_id=conversation_id, message_id=message_id, bot_id=bot.id,
                   direction=direction, name=name[:255], mime=checked.mime, ext=checked.ext,
                   size=len(checked.data), storage_key=key, sha256=hashlib.sha256(checked.data).hexdigest(),
                   scan_status=scan_status, text=text, read_error=read_error, flag=flag, ip_hash=ip,
                   token=secrets.token_urlsafe(32), created_at=now,
                   expires_at=now + timedelta(days=fs.retention_days))
    db.add(row)
    await db.commit()
    return row


@router.post("")
async def upload(request: Request, bot_id: str = Form(...), session_id: str = Form(..., max_length=200),
                 file: UploadFile = File(...), db: AsyncSession = Depends(get_db)):
    from routers.chat import conversation_for

    bot = await bot_for(db, bot_id, request)
    fs = file_settings.for_bot(bot)
    if not fs.in_enabled:
        raise HTTPException(status_code=409, detail="This chat does not take files.")

    limit = fs.max_mb * MB
    data = await file.read(limit + 1)
    if len(data) > limit:
        raise HTTPException(status_code=413, detail=f"Files here can be up to {fs.max_mb} MB.")

    ip = ip_hash(request)
    if await uploads_today(db, bot.id, session_id, ip) >= fs.daily:
        raise HTTPException(status_code=429, detail="That is as many files as this chat takes in a day.")

    name = (file.filename or "file").replace("\\", "/").rsplit("/", 1)[-1] or "file"
    try:
        checked = checks.inspect(data, name, fs.in_types)
    except checks.Refused as refused:
        raise HTTPException(status_code=415, detail=str(refused))

    scanned = await scanning.scan(checked.data, app_settings.CLAMAV_HOST, app_settings.CLAMAV_PORT)
    if scanned.status == "infected":
        print(f"[Files] Refused an upload to {bot.id}: {scanned.name}")
        raise HTTPException(status_code=422, detail="This file was flagged by the virus scan and was not accepted.")

    got = await reading.read(checked.data, checked.ext, await get_settings(db), bot)
    conversation = await conversation_for(db, bot, session_id, request.headers.get("origin", ""))
    row = await store_file(db, bot, conversation.id, "in", name, checked,
                           scan_status="clean" if scanned.status == "clean" else "not_scanned",
                           text=got.text, read_error=got.error or None, flag=got.flag, ip=ip)

    return {"id": row.id, "name": row.name, "size": row.size,
            "kind": "image" if row.ext in IMAGE_TYPES else "document",
            "scanned": row.scan_status == "clean", "readable": got.text is not None, "note": got.note}


def download_url(row: ChatFile, session_id: str) -> str:
    from urllib.parse import quote
    return f"/api/v1/chat/files/{row.id}/download?session_id={quote(session_id)}&token={row.token}"


def meta(row: ChatFile, session_id: str) -> dict:
    return {"id": row.id, "name": row.name, "size": row.size, "url": download_url(row, session_id)}


def _title(markdown: str, fallback: str) -> str:
    from files.convert import blocks, plain
    for block in blocks(markdown):
        if block[0] == "heading":
            return plain(block[2])
    words = plain(markdown).split()
    return " ".join(words[:8]) or fallback


async def answer_as_file(db, bot, conversation_id: str, message_id: str, markdown: str, fmt: str,
                         title: str | None = None) -> ChatFile:
    """One of the bot's answers, converted and stored as a file of the answer."""
    from files import convert

    made = convert.to_file(markdown, fmt, title or _title(markdown, bot.name or "answer"))
    checked = checks.Checked(ext=fmt, mime=made.mime, data=made.data)
    return await store_file(db, bot, conversation_id, "out", made.filename, checked,
                            message_id=message_id, scan_status="clean")


class ExportRequest(BaseModel):
    bot_id: str
    session_id: str = Field(max_length=200)
    message_id: str
    format: str


@router.post("/export")
async def export(req: ExportRequest, request: Request, db: AsyncSession = Depends(get_db)):
    """The Download-as button: one of the bot's answers in this chat, as a file."""
    from database import ChatMessage

    bot = await bot_for(db, req.bot_id, request)
    fs = file_settings.for_bot(bot)
    if not fs.out_enabled:
        raise HTTPException(status_code=409, detail="This chat does not hand out files.")
    if req.format not in fs.out_formats:
        raise HTTPException(status_code=400, detail="That format is not offered here.")
    message = (await db.execute(
        select(ChatMessage).join(ChatConversation, ChatConversation.id == ChatMessage.conversation_id)
        .where(ChatMessage.id == req.message_id, ChatMessage.sender == "assistant",
               ChatConversation.bot_id == bot.id, ChatConversation.session_id == req.session_id))).scalars().first()
    if not message:
        raise HTTPException(status_code=404, detail="No such answer in this chat.")
    row = await answer_as_file(db, bot, message.conversation_id, message.id, message.content or "", req.format)
    return meta(row, req.session_id)


def _send(row: ChatFile, data: bytes) -> Response:
    from urllib.parse import quote
    return Response(content=data, media_type=row.mime, headers={
        "Content-Disposition": f"attachment; filename*=UTF-8''{quote(row.name)}",
        "Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff"})


async def _bytes(row: ChatFile) -> bytes:
    if row.expires_at and row.expires_at < datetime.utcnow():
        raise HTTPException(status_code=410, detail="This file has expired.")
    try:
        return await storage.current().get(row.storage_key)
    except FileNotFoundError:
        raise HTTPException(status_code=410, detail="This file is no longer kept.")


@router.get("/{file_id}/download")
async def download(file_id: str, session_id: str, token: str, db: AsyncSession = Depends(get_db)):
    """A visitor's own file, by a link only their session holds."""
    found = (await db.execute(select(ChatFile).join(ChatConversation, ChatConversation.id == ChatFile.conversation_id)
                              .where(ChatFile.id == file_id, ChatConversation.session_id == session_id))).scalars().first()
    if not found or not secrets.compare_digest(found.token, token):
        raise HTTPException(status_code=404, detail="No such file.")
    return _send(found, await _bytes(found))


@router.get("/{file_id}/admin", dependencies=[Depends(require_admin_token)])
async def admin_download(file_id: str, db: AsyncSession = Depends(get_db)):
    """Any file, for the portal's transcript, which checks the user's rights first."""
    found = await db.get(ChatFile, file_id)
    if not found:
        raise HTTPException(status_code=404, detail="No such file.")
    return _send(found, await _bytes(found))
