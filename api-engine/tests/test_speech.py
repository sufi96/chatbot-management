"""Speech: the engines, and the routes the widget calls."""
import json

import httpx
import pytest
from fastapi import FastAPI
from httpx import ASGITransport, AsyncClient
from sqlalchemy.ext.asyncio import async_sessionmaker, create_async_engine
from sqlalchemy.pool import StaticPool

import database
import speech
from database import AppSetting, Base, BotProfile, System, get_db
from routers import bot as bot_router
from routers import voice


def recorder(status=200, body=b"ID3audio", content_type="audio/mpeg", calls=None):
    def handle(request: httpx.Request):
        if calls is not None:
            calls.append(request)
        return httpx.Response(status, content=body, headers={"content-type": content_type})
    return httpx.MockTransport(handle)


# --- the engines -------------------------------------------------------------

def test_slots_and_defaults():
    assert speech.slot_for("ms", "male") == "ms_male"
    assert speech.slot_for("xx", "yy") == "en_female"
    assert speech.voice_name({}, "ms_female") == "ms-MY-YasminNeural"
    assert speech.voice_name({"voice_ms_female": "kokoro_x"}, "ms_female") == "kokoro_x"


def test_ssml_escapes_the_text():
    ssml = speech.ssml("Fish & chips <now>", "ms-MY-OsmanNeural", "ms-MY", 1.2)
    assert "Fish &amp; chips &lt;now&gt;" in ssml
    assert "xml:lang='ms-MY'" in ssml and "rate='+20%'" in ssml


@pytest.mark.asyncio
async def test_azure_is_asked_with_the_malay_voice_and_key():
    calls = []
    settings = {"speech_engine": "azure", "azure_speech_region": "southeastasia", "azure_speech_key": "k"}
    audio = await speech.synthesize(settings, "ms_male", "Selamat datang.", transport=recorder(calls=calls))

    assert audio.content == b"ID3audio"
    request = calls[0]
    assert request.url.host == "southeastasia.tts.speech.microsoft.com"
    assert request.headers["Ocp-Apim-Subscription-Key"] == "k"
    assert "ms-MY-OsmanNeural" in request.content.decode()


@pytest.mark.asyncio
async def test_a_speech_server_is_asked_openai_style():
    calls = []
    settings = {"speech_engine": "server", "speech_base_url": "http://tts/v1", "speech_api_key": "x",
                "speech_model": "kokoro", "voice_en_female": "af_heart"}
    await speech.synthesize(settings, "en_female", "Hello.", transport=recorder(calls=calls))

    request = calls[0]
    assert str(request.url) == "http://tts/v1/audio/speech"
    assert json.loads(request.content) == {"model": "kokoro", "voice": "af_heart", "input": "Hello.",
                                           "response_format": "mp3", "speed": 1.0}
    assert request.headers["Authorization"] == "Bearer x"


@pytest.mark.asyncio
async def test_the_browser_engine_has_nothing_to_synthesize():
    with pytest.raises(speech.SpeechError):
        await speech.synthesize({"speech_engine": "browser"}, "en_female", "Hi")


@pytest.mark.asyncio
async def test_a_refusal_is_an_error_not_audio():
    settings = {"speech_engine": "azure", "azure_speech_region": "r", "azure_speech_key": "bad"}
    with pytest.raises(speech.SpeechError, match="401"):
        await speech.synthesize(settings, "en_male", "Hi", transport=recorder(status=401, body=b"no"))


@pytest.mark.asyncio
async def test_transcription_sends_the_recording_and_language():
    calls = []
    settings = {"transcribe_engine": "server", "transcribe_base_url": "http://stt/v1", "transcribe_model": "whisper-small"}
    text = await speech.transcribe(settings, b"webm-bytes", "speech.webm", "audio/webm", "ms",
                                   transport=recorder(body=b'{"text": " Berapa harga X200? "}',
                                                      content_type="application/json", calls=calls))
    assert text == "Berapa harga X200?"
    body = calls[0].content
    assert b'name="language"' in body and b"ms" in body and b"webm-bytes" in body


@pytest.mark.asyncio
async def test_an_oversized_recording_is_refused():
    settings = {"transcribe_engine": "server", "transcribe_base_url": "http://stt/v1"}
    with pytest.raises(speech.SpeechError, match="too long"):
        await speech.transcribe(settings, b"x" * (speech.AUDIO_BYTES + 1), "a.webm", "audio/webm")


# --- the routes --------------------------------------------------------------

@pytest.fixture
async def factory(monkeypatch):
    engine = create_async_engine("sqlite+aiosqlite://", poolclass=StaticPool)
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
    made = async_sessionmaker(engine, expire_on_commit=False)
    monkeypatch.setattr(database, "async_session_factory", made)
    async with made() as s:
        s.add(System(id="sys_1", name="W", allowed_origins="https://shop.example"))
        s.add(BotProfile(id="bot_1", system_id="sys_1", name="Helper", is_active=True,
                         voice_output=True, voice_input=True, voice_gender="male", voice_language="ms"))
        s.add(AppSetting(key="speech_engine", value="azure"))
        await s.commit()
    yield made
    await engine.dispose()


async def call(factory, method, path, origin="https://shop.example", **kwargs):
    app = FastAPI()
    app.include_router(voice.router)
    app.include_router(bot_router.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        return await client.request(method, path, headers={"origin": origin}, **kwargs)


@pytest.mark.asyncio
async def test_the_widget_is_told_the_voice_but_no_names_or_keys(factory):
    config = (await call(factory, "GET", "/api/v1/bot/bot_1/config")).json()
    assert config["voice"] == {"output": True, "autoplay": False, "gender": "male", "language": "ms",
                               "input": True, "speak_with": "server", "listen_with": "browser"}
    assert "Neural" not in json.dumps(config)


@pytest.mark.asyncio
async def test_an_answer_is_spoken_for_an_allowed_page(factory, monkeypatch):
    seen = {}

    async def synthesize(settings, slot, text, rate=1.0, transport=None):
        seen.update(slot=slot, text=text)
        return speech.Audio(b"mp3!")
    monkeypatch.setattr(voice.speech, "synthesize", synthesize)

    response = await call(factory, "POST", "/api/v1/voice/speech",
                          json={"bot_id": "bot_1", "text": "Selamat datang.", "voice": "ms_male"})

    assert response.status_code == 200
    assert response.content == b"mp3!"
    assert seen == {"slot": "ms_male", "text": "Selamat datang."}


@pytest.mark.asyncio
async def test_another_site_cannot_spend_the_voice(factory):
    response = await call(factory, "POST", "/api/v1/voice/speech", origin="https://evil.example",
                          json={"bot_id": "bot_1", "text": "Hi", "voice": "en_female"})
    assert response.status_code == 403


@pytest.mark.asyncio
async def test_a_bot_without_voice_will_not_speak(factory):
    async with factory() as s:
        (await s.get(BotProfile, "bot_1")).voice_output = False
        await s.commit()
    response = await call(factory, "POST", "/api/v1/voice/speech",
                          json={"bot_id": "bot_1", "text": "Hi", "voice": "en_female"})
    assert response.status_code == 403


@pytest.mark.asyncio
async def test_long_text_is_refused(factory):
    response = await call(factory, "POST", "/api/v1/voice/speech",
                          json={"bot_id": "bot_1", "text": "x" * (speech.TEXT_CHARS + 1), "voice": "en_female"})
    assert response.status_code == 422


@pytest.mark.asyncio
async def test_a_failing_voice_is_a_plain_502(factory, monkeypatch):
    async def broken(*args, **kwargs):
        raise speech.SpeechError("key rejected")
    monkeypatch.setattr(voice.speech, "synthesize", broken)

    response = await call(factory, "POST", "/api/v1/voice/speech",
                          json={"bot_id": "bot_1", "text": "Hi", "voice": "en_female"})
    assert response.status_code == 502
    assert "key" not in response.text


@pytest.mark.asyncio
async def test_a_spoken_question_comes_back_as_text(factory, monkeypatch):
    async def transcribe(settings, audio, filename, content_type, language="", transport=None):
        assert audio == b"rec" and language == "ms"
        return "Berapa harga X200?"
    monkeypatch.setattr(voice.speech, "transcribe", transcribe)

    response = await call(factory, "POST", "/api/v1/voice/transcribe",
                          data={"bot_id": "bot_1", "language": "ms"},
                          files={"audio": ("speech.webm", b"rec", "audio/webm")})

    assert response.json() == {"text": "Berapa harga X200?"}


@pytest.mark.asyncio
async def test_a_sample_can_be_of_a_voice_not_yet_saved(factory, monkeypatch):
    from config import settings as app_settings
    monkeypatch.setattr(app_settings, "ADMIN_API_TOKEN", "secret")
    seen = {}

    async def synthesize(settings, slot, text, rate=1.0, transport=None):
        seen.update(name=speech.voice_name(settings, slot), text=text)
        return speech.Audio(b"mp3")
    monkeypatch.setattr(voice.speech, "synthesize", synthesize)

    app = FastAPI()
    app.include_router(voice.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        response = await client.post("/api/v1/voice/test", headers={"X-Admin-Token": "secret"},
                                     json={"voice": "ms_female", "text": "Helo.", "voice_name": "id-ID-GadisNeural"})

    assert response.status_code == 200
    assert seen == {"name": "id-ID-GadisNeural", "text": "Helo."}


@pytest.mark.asyncio
async def test_a_server_that_is_not_running_says_so():
    def refuse(request):
        raise httpx.ConnectError("refused", request=request)
    settings = {"speech_engine": "server", "speech_base_url": "http://127.0.0.1:5050/v1"}
    with pytest.raises(speech.SpeechError, match="Could not reach the speech server at http://127.0.0.1:5050/v1. Is it running"):
        await speech.synthesize(settings, "en_female", "Hi", transport=httpx.MockTransport(refuse))


@pytest.mark.asyncio
async def test_a_chat_server_is_not_mistaken_for_a_speech_server():
    settings = {"speech_engine": "server", "speech_base_url": "http://llm/v1"}
    with pytest.raises(speech.SpeechError, match="not a speech server"):
        await speech.synthesize(settings, "en_female", "Hi", transport=recorder(status=404, body=b"not found"))
    with pytest.raises(speech.SpeechError, match="not with audio"):
        await speech.synthesize(settings, "en_female", "Hi",
                                transport=recorder(body=b"{}", content_type="application/json"))


@pytest.mark.asyncio
async def test_a_sample_can_use_an_engine_not_yet_saved(factory, monkeypatch):
    from config import settings as app_settings
    monkeypatch.setattr(app_settings, "ADMIN_API_TOKEN", "secret")
    seen = {}

    async def synthesize(settings, slot, text, rate=1.0, transport=None):
        seen.update(engine=speech.engine_for(settings), base=settings.get("speech_base_url"))
        return speech.Audio(b"mp3")
    monkeypatch.setattr(voice.speech, "synthesize", synthesize)

    app = FastAPI()
    app.include_router(voice.router)

    async def session():
        async with factory() as s:
            yield s

    app.dependency_overrides[get_db] = session
    async with AsyncClient(transport=ASGITransport(app=app), base_url="http://test") as client:
        await client.post("/api/v1/voice/test", headers={"X-Admin-Token": "secret"},
                          json={"voice": "en_male", "speech_engine": "server",
                                "speech_base_url": "http://tts:5050/v1"})

    # The saved engine is azure; the page's unsaved choice wins for the sample.
    assert seen == {"engine": "server", "base": "http://tts:5050/v1"}
