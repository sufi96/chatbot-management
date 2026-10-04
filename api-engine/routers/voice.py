"""The widget's voice: answers read aloud, and questions spoken.

Only used when a bot speaks with a server engine: its own choice, or the
install's (speech.engine_for_bot). With the browser, the widget speaks and
listens on the visitor's own device and never calls here.

These routes spend the install's speech account, so they hold to the same
rules as chat: an active bot that has the feature switched on, asked from a
page on its workspace's allowlist, and text or audio of bounded size. A route
that would speak any text for anyone is a free text-to-speech service for the
internet.
"""
from fastapi import APIRouter, Depends, File, Form, HTTPException, Request, UploadFile
from fastapi.responses import Response
from pydantic import BaseModel, Field
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession

import speech
from database import BotProfile, System, get_db, get_settings
from routers.chat import check_origin
from routers.kb import require_admin_token

router = APIRouter(prefix="/api/v1/voice", tags=["voice"])


async def _bot_for(db: AsyncSession, bot_id: str, request: Request):
    bot = (await db.execute(select(BotProfile).where(
        BotProfile.id == bot_id, BotProfile.is_active.is_(True),
        BotProfile.deleted_at.is_(None)))).scalars().first()
    if not bot:
        raise HTTPException(status_code=404, detail="Bot profile not found or inactive")
    system = (await db.execute(select(System).where(System.id == bot.system_id))).scalars().first()
    check_origin(bot, system, request.headers.get("origin", ""))
    return bot


class SpeechRequest(BaseModel):
    bot_id: str
    text: str = Field(max_length=speech.TEXT_CHARS)
    voice: str
    rate: float = Field(default=1.0, ge=0.5, le=2.0)


@router.post("/speech")
async def speak(req: SpeechRequest, request: Request, db: AsyncSession = Depends(get_db)):
    bot = await _bot_for(db, req.bot_id, request)
    if not bot.voice_output:
        raise HTTPException(status_code=403, detail="This bot does not read answers aloud.")

    settings = await get_settings(db)
    # The bot's own choice of engine, over the install's.
    settings = {**settings, "speech_engine": speech.engine_for_bot(bot, settings)}
    try:
        audio = await speech.synthesize(settings, req.voice, req.text, req.rate)
    except speech.SpeechError as error:
        print(f"[Voice] Could not speak: {error}")
        raise HTTPException(status_code=502, detail="The voice is not available right now.")

    return Response(content=audio.content, media_type=audio.media_type,
                    headers={"Cache-Control": "no-store"})


@router.post("/transcribe")
async def transcribe(request: Request, bot_id: str = Form(...), language: str = Form(""),
                     audio: UploadFile = File(...), db: AsyncSession = Depends(get_db)):
    bot = await _bot_for(db, bot_id, request)
    if not bot.voice_input:
        raise HTTPException(status_code=403, detail="This bot does not take spoken questions.")

    content = await audio.read(speech.AUDIO_BYTES + 1)
    settings = await get_settings(db)
    try:
        text = await speech.transcribe(settings, content, audio.filename, audio.content_type, language)
    except speech.SpeechError as error:
        print(f"[Voice] Could not transcribe: {error}")
        raise HTTPException(status_code=502, detail="Could not make out the recording. Please type instead.")

    return {"text": text}


class VoiceTestRequest(BaseModel):
    voice: str
    text: str = Field(default="", max_length=300)
    # The engine's name for the voice as typed on the settings page, so a
    # choice can be heard before it is saved. Blank uses the saved one.
    voice_name: str = Field(default="", max_length=120)
    # The engine as the settings page has it now, saved or not, so a choice
    # can be heard before it is saved. The portal resolves a provider to its
    # URL and key; an empty field keeps the saved value.
    speech_engine: str = Field(default="", max_length=20)
    speech_base_url: str = Field(default="", max_length=500)
    speech_api_key: str = Field(default="", max_length=500)
    speech_model: str = Field(default="", max_length=120)
    azure_speech_region: str = Field(default="", max_length=40)
    azure_speech_key: str = Field(default="", max_length=200)


class ServerVoicesRequest(BaseModel):
    base_url: str = Field(max_length=500)
    api_key: str = Field(default="", max_length=500)


@router.post("/server-voices", dependencies=[Depends(require_admin_token)])
async def server_voices(req: ServerVoicesRequest):
    """The voices a speech server offers, for the Voice settings page. Admin only:
    it calls whatever URL it is given, with the provider's key."""
    try:
        voices = await speech.server_voices(req.base_url, req.api_key)
    except speech.SpeechError as error:
        return {"ok": False, "voices": [], "message": str(error)}
    return {"ok": True, "voices": voices}


SAMPLES = {
    "en": "Hello! This is how I will sound when I read answers aloud.",
    "ms": "Helo! Beginilah bunyi suara saya apabila membaca jawapan.",
}


@router.post("/test", dependencies=[Depends(require_admin_token)])
async def test_voice(req: VoiceTestRequest, db: AsyncSession = Depends(get_db)):
    """A short sample in one voice, for the Voice settings page. Admin only."""
    settings = await get_settings(db)
    if req.voice_name.strip() and req.voice in speech.SLOTS:
        settings = {**settings, f"voice_{req.voice}": req.voice_name.strip()}
    unsaved = {key: getattr(req, key).strip() for key in
               ("speech_engine", "speech_base_url", "speech_api_key", "speech_model",
                "azure_speech_region", "azure_speech_key") if getattr(req, key).strip()}
    settings = {**settings, **unsaved}
    try:
        audio = await speech.synthesize(settings, req.voice, req.text or SAMPLES.get(req.voice[:2], SAMPLES["en"]))
    except speech.SpeechError as error:
        return Response(content=str(error), media_type="text/plain", status_code=502)
    return Response(content=audio.content, media_type=audio.media_type)
