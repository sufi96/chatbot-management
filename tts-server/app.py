"""A Malaysian text-to-speech server on the OpenAI audio API, with a settings page.

The chat app's "Speech server" engine calls POST /v1/audio/speech with a voice
name; this server answers in that voice, made by Malaya-Speech VITS or by
Malaysian F5-TTS. The page at / adds, edits and tries voices, switches models
and devices, and shows what the GPU holds.

    TTS_DATA_DIR   where settings.json and reference clips live (default ./data)
    TTS_API_KEY    when set, every /v1 and /api call needs "Authorization: Bearer <key>"
"""
import asyncio
import os
import secrets
import time
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import Depends, FastAPI, File, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse, JSONResponse, Response
from pydantic import BaseModel, Field

import audio
from engines import F5_CHECKPOINTS, VITS_MODELS, EngineError, EngineLoading, F5Engine, VitsEngine, gpu_status
from store import SettingsError, Store, clean_voice

HERE = Path(__file__).parent
INPUT_CHARS = 4096


def create_app(data_dir: Path | None = None, engines: dict | None = None,
               api_key: str | None = None) -> FastAPI:
    store = Store(data_dir or Path(os.environ.get("TTS_DATA_DIR", HERE / "data")))
    engines = engines if engines is not None else {"vits": VitsEngine(), "f5": F5Engine()}
    api_key = api_key if api_key is not None else os.environ.get("TTS_API_KEY", "")

    def configure_engines():
        for name, engine in engines.items():
            engine.configure(store.engines().get(name, {}))

    configure_engines()

    @asynccontextmanager
    async def lifespan(app: FastAPI):
        settings = store.engines()
        for name, engine in engines.items():
            if engine.installed() and settings.get(name, {}).get("preload"):
                engine.load_in_background()
        yield

    app = FastAPI(title="Malaysian TTS server", version="1.0.0", lifespan=lifespan)
    app.state.store, app.state.engines = store, engines

    def require_key(request: Request):
        if not api_key:
            return
        given = request.headers.get("authorization", "").removeprefix("Bearer ").strip()
        if not secrets.compare_digest(given, api_key):
            raise HTTPException(status_code=401, detail="This server needs its API key.")

    async def speak(voice: dict, text: str, speed: float):
        engine = engines.get(voice.get("engine"))
        if engine is None:
            raise EngineError(f"No {voice.get('engine')!r} engine in this server.")
        started = time.monotonic()
        samples, rate = await asyncio.to_thread(engine.speak, voice, text, speed)
        return samples, rate, time.monotonic() - started

    def engine_failure(error: EngineError) -> JSONResponse:
        status = 503 if isinstance(error, EngineLoading) else 500
        return JSONResponse({"error": {"message": str(error), "type": "engine_error"}}, status_code=status)

    # The OpenAI audio API, for the chat app

    class SpeechRequest(BaseModel):
        model: str = "tts-1"
        input: str = Field(max_length=INPUT_CHARS)
        voice: str
        response_format: str = "mp3"
        speed: float = Field(default=1.0, ge=0.25, le=4.0)

    @app.post("/v1/audio/speech", dependencies=[Depends(require_key)])
    async def openai_speech(req: SpeechRequest):
        voice = store.voice(req.voice)
        if voice is None:
            names = ", ".join(sorted(store.voices())) or "none yet"
            return JSONResponse({"error": {"message": f"Unknown voice {req.voice!r}. This server has: {names}.",
                                           "type": "invalid_request_error", "param": "voice"}}, status_code=400)
        if not req.input.strip():
            return JSONResponse({"error": {"message": "input is empty.", "type": "invalid_request_error"}},
                                status_code=400)
        try:
            samples, rate, _ = await speak(voice, req.input.strip(), req.speed)
        except EngineError as error:
            return engine_failure(error)
        content, media_type = audio.encode(samples, rate, req.response_format)
        return Response(content=content, media_type=media_type)

    @app.get("/v1/models", dependencies=[Depends(require_key)])
    async def models():
        return {"object": "list", "data": [{"id": "tts-1", "object": "model", "owned_by": "local"}]}

    @app.get("/v1/audio/voices", dependencies=[Depends(require_key)])
    async def voices():
        names = sorted(store.voices())
        return {"voices": names,
                "data": [{"id": name, "engine": store.voices()[name]["engine"],
                          "aliases": store.voices()[name].get("aliases", [])} for name in names]}

    @app.get("/health")
    async def health():
        return {"ok": True}

    # The settings page

    @app.get("/", include_in_schema=False)
    async def page():
        return FileResponse(HERE / "static" / "index.html")

    @app.get("/api/state", dependencies=[Depends(require_key)])
    async def state():
        return {
            "voices": store.voices(),
            "engines": {name: engine.status() for name, engine in engines.items()},
            "engine_settings": store.engines(),
            "gpu": gpu_status(),
            "choices": {"vits_models": VITS_MODELS, "f5_checkpoints": F5_CHECKPOINTS},
        }

    class VoiceSave(BaseModel):
        voice: dict
        rename_from: str = ""

    @app.put("/api/voices/{name}", dependencies=[Depends(require_key)])
    async def save_voice(name: str, req: VoiceSave):
        try:
            saved = store.save_voice(name, req.voice, req.rename_from)
        except SettingsError as error:
            raise HTTPException(status_code=422, detail=str(error))
        return {"name": saved}

    @app.delete("/api/voices/{name}", dependencies=[Depends(require_key)])
    async def delete_voice(name: str):
        store.delete_voice(name)
        return {"ok": True}

    @app.post("/api/refs", dependencies=[Depends(require_key)])
    async def upload_ref(file: UploadFile = File(...)):
        try:
            ref = store.save_ref(file.filename, await file.read(11 * 1024 * 1024))
        except SettingsError as error:
            raise HTTPException(status_code=422, detail=str(error))
        return {"ref": ref}

    @app.get("/api/refs/{ref}", dependencies=[Depends(require_key)])
    async def get_ref(ref: str):
        try:
            path = store.ref_path(ref)
        except SettingsError:
            raise HTTPException(status_code=404)
        if not path.exists():
            raise HTTPException(status_code=404)
        return FileResponse(path)

    @app.put("/api/engines", dependencies=[Depends(require_key)])
    async def save_engines(settings: dict):
        store.save_engines(settings)
        await asyncio.to_thread(configure_engines)
        return {"ok": True}

    @app.post("/api/engines/{name}/{action}", dependencies=[Depends(require_key)])
    async def engine_action(name: str, action: str):
        engine = engines.get(name)
        if engine is None or action not in ("load", "unload"):
            raise HTTPException(status_code=404)
        if action == "load":
            if not engine.installed():
                raise HTTPException(status_code=409, detail=f"The {name} engine is not installed here.")
            engine.load_in_background()
        else:
            await asyncio.to_thread(engine.unload)
        return engine.status()

    class TestRequest(BaseModel):
        # A saved voice by name, or the voice as the page has it now, saved or not.
        name: str = ""
        voice: dict | None = None
        text: str = Field(min_length=1, max_length=INPUT_CHARS)
        speed: float = Field(default=1.0, ge=0.25, le=4.0)

    @app.post("/api/test", dependencies=[Depends(require_key)])
    async def test(req: TestRequest):
        if req.voice is not None:
            try:
                voice = store.resolved(clean_voice(req.voice, store))
            except SettingsError as error:
                raise HTTPException(status_code=422, detail=str(error))
        else:
            voice = store.voice(req.name)
            if voice is None:
                raise HTTPException(status_code=404, detail=f"Unknown voice {req.name!r}.")
        try:
            samples, rate, seconds = await speak(voice, req.text.strip(), req.speed)
        except EngineError as error:
            return engine_failure(error)
        content, media_type = audio.encode(samples, rate, "wav")
        length = len(samples) / rate if rate else 0
        return Response(content=content, media_type=media_type, headers={
            "X-Synth-Seconds": f"{seconds:.3f}", "X-Audio-Seconds": f"{length:.3f}",
            "X-Sample-Rate": str(rate)})

    return app


app = create_app()
