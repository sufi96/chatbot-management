"""The server's settings: its voices and engine choices, in one JSON file.

A voice is a name the chat app asks for (the "voice" of an OpenAI audio
request) and what it means here: an engine and that engine's options. The
settings page edits this file; requests read it.
"""
import json
import re
import threading
import uuid
from pathlib import Path

from engines import F5_CHECKPOINTS, VITS_MODELS

VOICE_NAME = re.compile(r"^[a-z0-9][a-z0-9_.-]{0,63}$")
# Another name a voice answers to, such as the Microsoft name the chat app's
# voice list offers (ms-MY-YasminNeural), so that choice works unchanged.
ALIAS = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}$")
REF_TYPES = {".wav", ".mp3", ".flac", ".ogg", ".m4a", ".webm"}
REF_BYTES = 10 * 1024 * 1024

DEFAULTS = {
    "voices": {
        "yasmin": {"engine": "vits", "model": "mesolitica/VITS-yasmin",
                   "temperature": 0.6666, "temperature_durator": 0.6666,
                   "aliases": ["ms-MY-YasminNeural"]},
        "osman": {"engine": "vits", "model": "mesolitica/VITS-osman",
                  "temperature": 0.6666, "temperature_durator": 0.6666,
                  "aliases": ["ms-MY-OsmanNeural"]},
    },
    "engines": {
        "vits": {"device": "cpu", "preload": ["mesolitica/VITS-yasmin", "mesolitica/VITS-osman"]},
        "f5": {"device": "auto", "checkpoint": F5_CHECKPOINTS[0], "preload": False},
    },
}

VITS_FIELDS = {"temperature": (0.0, 1.5), "temperature_durator": (0.0, 1.5), "speaker": (0, 8)}
F5_FIELDS = {"nfe_step": (8, 64), "cfg_strength": (0.0, 5.0), "seed": (-1, 2**31 - 1)}


class SettingsError(ValueError):
    """A setting the page sent cannot be kept. The message is safe to show."""


class Store:
    def __init__(self, data_dir: Path):
        self.dir = Path(data_dir)
        self.refs = self.dir / "refs"
        self.refs.mkdir(parents=True, exist_ok=True)
        self.file = self.dir / "settings.json"
        self.lock = threading.Lock()
        self.data = self._read()

    def _read(self) -> dict:
        if self.file.exists():
            data = json.loads(self.file.read_text(encoding="utf-8"))
            for engine, defaults in DEFAULTS["engines"].items():
                data.setdefault("engines", {})[engine] = {**defaults, **data["engines"].get(engine, {})}
            data.setdefault("voices", {})
            return data
        return json.loads(json.dumps(DEFAULTS))

    def _write(self):
        temp = self.file.with_suffix(".tmp")
        temp.write_text(json.dumps(self.data, indent=2, ensure_ascii=False), encoding="utf-8")
        temp.replace(self.file)

    # Voices

    def voices(self) -> dict:
        return self.data["voices"]

    def voice(self, name: str) -> dict | None:
        """A voice by its name or by one of its aliases, ignoring case."""
        name = (name or "").strip().lower()
        voice = self.data["voices"].get(name)
        if voice is None:
            voice = next((v for v in self.data["voices"].values()
                          if name in (a.lower() for a in v.get("aliases", []))), None)
        return self.resolved(voice) if voice else None

    def resolved(self, voice: dict) -> dict:
        """The voice with its reference clip as a path on disk, for the engine."""
        voice = dict(voice)
        if voice.get("ref_audio"):
            voice["ref_audio_path"] = str(self.ref_path(voice["ref_audio"]))
        return voice

    def save_voice(self, name: str, voice: dict, rename_from: str = "") -> str:
        name = (name or "").strip().lower()
        if not VOICE_NAME.match(name):
            raise SettingsError("A voice name is lowercase letters, digits, '-', '_' or '.', up to 64 characters.")
        clean = clean_voice(voice, self)
        with self.lock:
            if name != rename_from and name in self.data["voices"]:
                raise SettingsError(f"There is already a voice named {name!r}.")
            # Every name and alias picks out one voice.
            owners = {}
            for other, saved in self.data["voices"].items():
                for taken in [other, *saved.get("aliases", [])]:
                    owners[taken.lower()] = other
            for wanted in [name, *clean.get("aliases", [])]:
                owner = owners.get(wanted.lower())
                if owner is not None and owner not in (name, rename_from):
                    raise SettingsError(f"{wanted!r} already belongs to the voice {owner!r}.")
            if rename_from and rename_from != name:
                self.data["voices"].pop(rename_from, None)
            self.data["voices"][name] = clean
            self._write()
        return name

    def delete_voice(self, name: str):
        with self.lock:
            self.data["voices"].pop(name, None)
            self._write()

    # Engines

    def engines(self) -> dict:
        return self.data["engines"]

    def save_engines(self, engines: dict):
        vits = engines.get("vits", {})
        f5 = engines.get("f5", {})
        clean = {
            "vits": {"device": _choice(vits.get("device", "cpu"), ("cpu", "cuda", "auto")),
                     "preload": [m for m in vits.get("preload", []) if m in VITS_MODELS]},
            "f5": {"device": _choice(f5.get("device", "auto"), ("auto", "cuda", "cpu")),
                   "checkpoint": (f5.get("checkpoint") or F5_CHECKPOINTS[0]).strip(),
                   "preload": bool(f5.get("preload", False))},
        }
        with self.lock:
            self.data["engines"] = clean
            self._write()

    # Reference clips

    def save_ref(self, filename: str, content: bytes) -> str:
        suffix = Path(filename or "clip.wav").suffix.lower()
        if suffix not in REF_TYPES:
            raise SettingsError(f"A reference clip is one of {', '.join(sorted(REF_TYPES))}.")
        if not content:
            raise SettingsError("The clip was empty.")
        if len(content) > REF_BYTES:
            raise SettingsError("A reference clip is at most 10 MB; 5 to 12 seconds is what F5 uses.")
        ref = f"{uuid.uuid4().hex}{suffix}"
        (self.refs / ref).write_bytes(content)
        return ref

    def ref_path(self, ref: str) -> Path:
        if not re.fullmatch(r"[0-9a-f]{32}\.[a-z0-9]{2,5}", ref or ""):
            raise SettingsError("Unknown reference clip.")
        return self.refs / ref


def _choice(value, allowed):
    return value if value in allowed else allowed[0]


def _number(value, low, high, name):
    try:
        number = float(value)
    except (TypeError, ValueError):
        raise SettingsError(f"{name} must be a number.")
    if not low <= number <= high:
        raise SettingsError(f"{name} must be between {low} and {high}.")
    return number


def clean_aliases(value) -> list[str]:
    if isinstance(value, str):
        value = value.split(",")
    aliases = []
    for alias in value or []:
        alias = str(alias).strip()
        if not alias:
            continue
        if not ALIAS.match(alias):
            raise SettingsError(f"{alias!r} cannot be a voice name: letters, digits, '-', '_' or '.'.")
        if alias.lower() not in (a.lower() for a in aliases):
            aliases.append(alias)
    if len(aliases) > 10:
        raise SettingsError("A voice has at most 10 other names.")
    return aliases


def clean_voice(voice: dict, store: Store) -> dict:
    """A voice as the page sent it, checked and with only the fields its engine uses."""
    clean = clean_engine_fields(voice, store)
    aliases = clean_aliases(voice.get("aliases"))
    if aliases:
        clean["aliases"] = aliases
    return clean


def clean_engine_fields(voice: dict, store: Store) -> dict:
    engine = voice.get("engine")
    if engine == "vits":
        model = voice.get("model")
        if model not in VITS_MODELS:
            raise SettingsError(f"Unknown VITS model {model!r}.")
        clean = {"engine": "vits", "model": model}
        for field, (low, high) in VITS_FIELDS.items():
            if voice.get(field) not in (None, ""):
                clean[field] = _number(voice[field], low, high, field)
        if "speaker" in clean:
            clean["speaker"] = int(clean["speaker"])
            if clean["speaker"] >= VITS_MODELS[model]:
                raise SettingsError(f"{model} has {VITS_MODELS[model]} speaker(s).")
        return clean
    if engine == "f5":
        clean = {"engine": "f5", "ref_text": str(voice.get("ref_text") or "").strip()[:1000],
                 "remove_silence": bool(voice.get("remove_silence", False))}
        if voice.get("ref_audio"):
            if not store.ref_path(voice["ref_audio"]).exists():
                raise SettingsError("The reference clip is gone; upload or record it again.")
            clean["ref_audio"] = voice["ref_audio"]
        for field, (low, high) in F5_FIELDS.items():
            if voice.get(field) not in (None, ""):
                clean[field] = _number(voice[field], low, high, field)
        for field in ("nfe_step", "seed"):
            if field in clean:
                clean[field] = int(clean[field])
        return clean
    raise SettingsError("A voice's engine is 'vits' or 'f5'.")
