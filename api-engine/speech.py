"""Turning answers into speech, and speech into questions.

Two directions, each with an engine an install chooses in Admin Settings, Voice:

Speaking (text to speech):
- browser (default): the visitor's own device speaks, through the Web Speech
  API in the widget. Nothing to install and nothing reaches this module; the
  voices are whatever that device has.
- server: any server speaking the OpenAI audio API, POST {base}/audio/speech,
  linked through a platform provider like every other model job. A Kokoro or
  Whisper-family server on the DGX Sparks, OpenAI itself, or, for testing only,
  the openai-edge-tts shim.
- azure: Microsoft's Azure Speech service, by region and key. Its neural voices
  include exactly the four this system offers, English and Malay, female and
  male.

Listening (speech to text):
- browser (default): the browser's own speech recognition.
- server: POST {base}/audio/transcriptions, a Whisper-family model.

Four voices, always the same four slots, so a bot and a visitor choose by
language and gender and never by a vendor's voice name. Admin Settings maps
each slot to the engine's name for it.
"""
from dataclasses import dataclass
from xml.sax.saxutils import escape

import httpx

SLOTS = ("en_female", "en_male", "ms_female", "ms_male")
ENGINES = ("browser", "server", "azure")
LISTEN_ENGINES = ("browser", "server")

# Microsoft's names, the defaults: they are the same in Azure and the
# openai-edge-tts shim, so a test install and a production one agree.
DEFAULT_VOICES = {
    "en_female": "en-US-AvaNeural",
    "en_male": "en-US-AndrewNeural",
    "ms_female": "ms-MY-YasminNeural",
    "ms_male": "ms-MY-OsmanNeural",
}

LOCALES = {"en": "en-US", "ms": "ms-MY"}

TEXT_CHARS = 1200
AUDIO_BYTES = 10 * 1024 * 1024
TIMEOUT = httpx.Timeout(30.0, connect=5.0)


class SpeechError(Exception):
    """A speech engine refused or could not be reached. The message is safe to show an operator."""


@dataclass(frozen=True)
class Audio:
    content: bytes
    media_type: str = "audio/mpeg"


def engine_for(settings: dict) -> str:
    value = (settings.get("speech_engine") or "browser").strip().lower()
    return value if value in ENGINES else "browser"


def engine_for_bot(bot, settings: dict) -> str:
    """Where one bot's voice is made: its own choice, or the install's.

    A bot can pick the browser, the speech server or Azure over the install's
    default; the server's and Azure's details stay the install's.
    """
    choice = (getattr(bot, "voice_engine", None) or "default").strip().lower()
    return choice if choice in ENGINES else engine_for(settings)


def listen_engine_for(settings: dict) -> str:
    value = (settings.get("transcribe_engine") or "browser").strip().lower()
    return value if value in LISTEN_ENGINES else "browser"


def voice_name(settings: dict, slot: str) -> str:
    return (settings.get(f"voice_{slot}") or "").strip() or DEFAULT_VOICES[slot]


def slot_for(language: str, gender: str) -> str:
    language = language if language in ("en", "ms") else "en"
    gender = gender if gender in ("female", "male") else "female"
    return f"{language}_{gender}"


def ssml(text: str, voice: str, locale: str, rate: float = 1.0) -> str:
    percent = int(round((rate - 1.0) * 100))
    return (f"<speak version='1.0' xml:lang='{locale}'><voice name='{escape(voice)}'>"
            f"<prosody rate='{percent:+d}%'>{escape(text)}</prosody></voice></speak>")


async def synthesize(settings: dict, slot: str, text: str, rate: float = 1.0,
                     transport=None) -> Audio:
    """Audio for text in one of the four voices, on the install's server engine."""
    if slot not in SLOTS:
        raise SpeechError(f"Unknown voice {slot!r}.")
    text = (text or "").strip()[:TEXT_CHARS]
    if not text:
        raise SpeechError("Nothing to say.")

    engine = engine_for(settings)
    voice = voice_name(settings, slot)

    try:
        response = await _ask_engine(engine, settings, slot, voice, text, rate, transport)
    except httpx.ConnectError:
        raise SpeechError(f"Could not reach the speech server at {_where(engine, settings)}. Is it running?")
    except httpx.TimeoutException:
        raise SpeechError(f"The speech server at {_where(engine, settings)} did not answer in time.")

    if response.status_code == 404:
        raise SpeechError(f"{_where(engine, settings)} has no /audio/speech, so it is not a speech server. "
                          "Choose the provider that runs your text-to-speech server.")
    if response.status_code in (401, 403):
        raise SpeechError("The speech engine refused the key (HTTP %d)." % response.status_code)
    if response.status_code == 400 and "voice" in response.text.lower():
        raise SpeechError(f"The speech engine does not know the voice {voice!r}: {response.text[:160]}")
    if response.status_code != 200:
        raise SpeechError(f"The speech engine answered HTTP {response.status_code}: {response.text[:160]}")
    if not response.headers.get("content-type", "").startswith("audio"):
        raise SpeechError("The speech engine answered, but not with audio: "
                          f"{response.headers.get('content-type', 'no content type')}.")

    return Audio(response.content, response.headers.get("content-type", "audio/mpeg").split(";")[0])


def _where(engine: str, settings: dict) -> str:
    if engine == "azure":
        return f"{(settings.get('azure_speech_region') or '?').strip()}.tts.speech.microsoft.com"
    return (settings.get("speech_base_url") or "?").strip().rstrip("/")


async def _ask_engine(engine, settings, slot, voice, text, rate, transport):
    async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
        if engine == "azure":
            region = (settings.get("azure_speech_region") or "").strip()
            key = (settings.get("azure_speech_key") or "").strip()
            if not (region and key):
                raise SpeechError("Azure Speech needs a region and a key in Admin Settings, Voice.")
            return await client.post(
                f"https://{region}.tts.speech.microsoft.com/cognitiveservices/v1",
                headers={"Ocp-Apim-Subscription-Key": key,
                         "Content-Type": "application/ssml+xml",
                         "X-Microsoft-OutputFormat": "audio-24khz-48kbitrate-mono-mp3",
                         "User-Agent": "chitchat-command-center"},
                content=ssml(text, voice, LOCALES[slot[:2]], rate).encode("utf-8"))
        elif engine == "server":
            base = (settings.get("speech_base_url") or "").strip().rstrip("/")
            if not base:
                raise SpeechError("The speech server has no provider in Admin Settings, Voice.")
            headers = {"Content-Type": "application/json"}
            if settings.get("speech_api_key"):
                headers["Authorization"] = f"Bearer {settings['speech_api_key']}"
            return await client.post(
                f"{base}/audio/speech", headers=headers,
                json={"model": (settings.get("speech_model") or "tts-1").strip(), "voice": voice,
                      "input": text, "response_format": "mp3", "speed": rate})
        else:
            raise SpeechError("The install speaks in the browser; there is no server voice to ask.")


async def server_voices(base_url: str, api_key: str = "", transport=None) -> list[dict]:
    """The voices a speech server names at GET {base}/audio/voices, for the Voice page's lists.

    The OpenAI audio API has no such route, so a server without one simply
    lists nothing and the page keeps its own names. Each voice is
    {"id": name, "aliases": [other names it answers to]}.
    """
    base = (base_url or "").strip().rstrip("/")
    if not base:
        raise SpeechError("The speech server has no base URL.")
    headers = {"Authorization": f"Bearer {api_key}"} if api_key else {}
    try:
        async with httpx.AsyncClient(timeout=httpx.Timeout(8.0, connect=3.0), transport=transport) as client:
            response = await client.get(f"{base}/audio/voices", headers=headers)
    except httpx.HTTPError:
        raise SpeechError(f"Could not reach the speech server at {base}. Is it running?")
    if response.status_code == 404:
        return []
    if response.status_code != 200:
        raise SpeechError(f"The speech server answered HTTP {response.status_code}.")
    try:
        body = response.json()
    except ValueError:
        return []
    if not isinstance(body, dict):
        return []
    items = body.get("data") or body.get("voices") or []
    voices = []
    for item in items:
        if isinstance(item, str):
            voices.append({"id": item, "aliases": []})
        elif isinstance(item, dict) and isinstance(item.get("id"), str):
            aliases = [a for a in item.get("aliases") or [] if isinstance(a, str)]
            voices.append({"id": item["id"], "aliases": aliases})
    return voices


async def transcribe(settings: dict, audio: bytes, filename: str, content_type: str,
                     language: str = "", transport=None) -> str:
    """The words in a recording, by the install's transcription server."""
    if listen_engine_for(settings) != "server":
        raise SpeechError("The install listens in the browser; there is no server to transcribe with.")
    if not audio:
        raise SpeechError("The recording was empty.")
    if len(audio) > AUDIO_BYTES:
        raise SpeechError("The recording is too long.")

    base = (settings.get("transcribe_base_url") or "").strip().rstrip("/")
    if not base:
        raise SpeechError("Transcription has no provider in Admin Settings, Voice.")

    headers = {}
    if settings.get("transcribe_api_key"):
        headers["Authorization"] = f"Bearer {settings['transcribe_api_key']}"
    data = {"model": (settings.get("transcribe_model") or "whisper-1").strip(), "response_format": "json"}
    if language in ("en", "ms"):
        data["language"] = language

    async with httpx.AsyncClient(timeout=TIMEOUT, transport=transport) as client:
        response = await client.post(f"{base}/audio/transcriptions", headers=headers, data=data,
                                     files={"file": (filename or "speech.webm", audio,
                                                     content_type or "audio/webm")})

    if response.status_code != 200:
        raise SpeechError(f"The transcription server answered HTTP {response.status_code}: {response.text[:160]}")

    try:
        return str(response.json().get("text") or "").strip()
    except ValueError:
        return response.text.strip()
