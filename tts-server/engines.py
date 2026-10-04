"""The two Malaysian speech models, loaded on first use and kept until unloaded.

- vits: Mesolitica's VITS voices through Malaya-Speech. One small model
  (about 150 MB) per speaker, quick enough on a CPU. Malay; English comes out
  with a Malay accent.
- f5: Mesolitica's Malaysian F5-TTS, one model for every voice. A voice is a
  short reference clip and the words spoken in it, and the model speaks new
  text in that voice: Malay, Malaysian English and Mandarin, switching inside
  a sentence. About 1.3 GB of weights and 2-4 GB of VRAM while speaking, so it
  wants a GPU. The checkpoint is CC-BY-NC 4.0: testing, not a product, unless
  Mesolitica licenses it.

Neither library is imported until a model is loaded, so the server starts, and
its settings page works, with either one missing.
"""
import gc
import importlib.util
import inspect
import re
import threading
import time

import numpy as np

VITS_MODELS = {
    # name: speakers. From malaya_speech.tts.available_vits().
    "mesolitica/VITS-yasmin": 1, "mesolitica/VITS-osman": 1,
    "mesolitica/VITS-orkid": 1, "mesolitica/VITS-bunga": 1,
    "mesolitica/VITS-jebat": 1, "mesolitica/VITS-tuah": 1,
    "mesolitica/VITS-female": 1, "mesolitica/VITS-male": 1,
    "mesolitica/VITS-husein": 1, "mesolitica/VITS-jordan": 1,
    "mesolitica/VITS-haqkiem": 1, "mesolitica/VITS-female-singlish": 1,
    "mesolitica/VITS-multispeaker-clean": 9, "mesolitica/VITS-multispeaker-noisy": 3,
}

F5_CHECKPOINTS = [
    "hf://mesolitica/Malaysian-F5-TTS-v3/checkpoints/model_220000.pt",
    "hf://mesolitica/Malaysian-F5-TTS-v3/checkpoints/model_210000.pt",
    "hf://mesolitica/Malaysian-F5-TTS-v3/checkpoints/model_200000.pt",
]

PAUSE_SECONDS = 0.18
VITS_CHUNK_CHARS = 220


class EngineError(Exception):
    """The engine cannot speak. The message is safe to show an operator."""


class EngineLoading(EngineError):
    """The engine is loading; ask again shortly."""


def split_sentences(text: str, limit: int = VITS_CHUNK_CHARS) -> list[str]:
    """Sentences short enough for a model that speaks one short text at a time."""
    pieces = []
    for sentence in re.split(r"(?<=[.!?])\s+|\n+", text.strip()):
        sentence = sentence.strip()
        while len(sentence) > limit:
            cut = max(sentence.rfind(mark, 0, limit) for mark in (",", ";", ":", " "))
            cut = cut if cut > limit // 3 else limit
            pieces.append(sentence[:cut + 1].strip())
            sentence = sentence[cut + 1:].strip()
        if sentence:
            pieces.append(sentence)
    return pieces


def join_with_pauses(waves: list[np.ndarray], rate: int) -> np.ndarray:
    pause = np.zeros(int(rate * PAUSE_SECONDS), dtype=np.float32)
    out = []
    for i, wave in enumerate(waves):
        if i:
            out.append(pause)
        out.append(np.asarray(wave, dtype=np.float32).reshape(-1))
    return np.concatenate(out) if out else np.zeros(0, dtype=np.float32)


def torch_device(choice: str) -> str:
    import torch
    if choice in ("cuda", "cpu"):
        if choice == "cuda" and not torch.cuda.is_available():
            raise EngineError("CUDA was chosen, but PyTorch sees no GPU here.")
        return choice
    return "cuda" if torch.cuda.is_available() else "cpu"


class Engine:
    name = ""
    module = ""

    def __init__(self):
        self.lock = threading.Lock()
        self.state = "idle"          # idle, loading, ready, error
        self.message = ""
        self.settings: dict = {}

    def installed(self) -> bool:
        return importlib.util.find_spec(self.module) is not None

    def configure(self, settings: dict):
        """New engine settings. A change of model or device drops what is loaded."""
        if settings != self.settings:
            if self.settings:
                self.unload()
            self.settings = dict(settings)

    def status(self) -> dict:
        return {"installed": self.installed(), "state": self.state, "message": self.message}

    def load_in_background(self):
        if self.state == "loading":
            return
        self.state, self.message = "loading", "Starting..."
        threading.Thread(target=self._load_safely, daemon=True).start()

    def _load_safely(self):
        try:
            with self.lock:
                started = time.monotonic()
                self.load()
            self.state, self.message = "ready", f"Loaded in {time.monotonic() - started:.0f}s"
        except Exception as error:  # noqa: BLE001 — shown on the settings page
            self.state, self.message = "error", f"{type(error).__name__}: {error}"
            print(f"[{self.name}] Could not load: {self.message}")

    def check_installed(self):
        if not self.installed():
            raise EngineError(f"The {self.name} engine is not installed in this server "
                              f"(Python package '{self.module}' is missing).")

    def load(self):
        raise NotImplementedError

    def unload(self):
        raise NotImplementedError

    def speak(self, voice: dict, text: str, speed: float) -> tuple[np.ndarray, int]:
        raise NotImplementedError


def free_memory():
    gc.collect()
    try:
        import torch
        if torch.cuda.is_available():
            torch.cuda.empty_cache()
    except ImportError:
        pass


class VitsEngine(Engine):
    """Malaya-Speech VITS. Each model loads in seconds, so it loads when first asked for."""
    name = "vits"
    module = "malaya_speech"
    rate = 22050

    def __init__(self):
        super().__init__()
        self.models: dict = {}

    def status(self) -> dict:
        return {**super().status(), "loaded": sorted(self.models)}

    def wanted(self) -> list[str]:
        return list(self.settings.get("preload", []))

    def load(self):
        for model in self.wanted():
            self.message = f"Loading {model}..."
            self._model(model)

    def unload(self):
        with self.lock:
            self.models.clear()
            free_memory()
            self.state, self.message = "idle", ""

    def _model(self, name: str):
        if name not in self.models:
            self.check_installed()
            import malaya_speech
            model = malaya_speech.tts.vits(model=name)
            device = torch_device(self.settings.get("device", "cpu"))
            if device == "cuda" and hasattr(model, "cuda"):
                model = model.cuda()
            if hasattr(model, "eval"):
                model.eval()
            self.models[name] = model
        return self.models[name]

    def speak(self, voice: dict, text: str, speed: float):
        name = voice.get("model") or "mesolitica/VITS-yasmin"
        if name not in VITS_MODELS:
            raise EngineError(f"Unknown VITS model {name!r}.")
        with self.lock:
            model = self._model(name)
            self.state = "ready"
            accepted = inspect.signature(model.predict).parameters
            options = {
                "temperature": float(voice.get("temperature", 0.6666)),
                "temperature_durator": float(voice.get("temperature_durator", 0.6666)),
                "length_ratio": 1.0 / speed,
            }
            if VITS_MODELS[name] > 1:
                options["sid"] = int(voice.get("speaker", 0))
            options = {key: value for key, value in options.items() if key in accepted}
            waves = [model.predict(sentence, **options)["y"] for sentence in split_sentences(text)]
        return join_with_pauses(waves, self.rate), self.rate


class F5Engine(Engine):
    """Malaysian F5-TTS. Loading takes a while (and a 5.4 GB download the first
    time), so it loads in the background when asked, and a request before then
    is told to wait."""
    name = "f5"
    module = "f5_tts"

    def __init__(self):
        super().__init__()
        self.model = None

    def status(self) -> dict:
        status = super().status()
        if self.model is not None:
            status["device"] = str(self.model.device)
        return status

    def load(self):
        self.check_installed()
        if self.model is not None:
            return
        checkpoint = self.settings.get("checkpoint") or F5_CHECKPOINTS[0]
        self.message = "Fetching the checkpoint (5.4 GB the first time)..."
        path = resolve_checkpoint(checkpoint)
        device = torch_device(self.settings.get("device", "auto"))
        self.message = f"Loading on {device}..."
        from f5_tts.api import F5TTS
        self.model = F5TTS(model="F5TTS_v1_Base", ckpt_file=path, device=device)

    def unload(self):
        with self.lock:
            self.model = None
            free_memory()
            self.state, self.message = "idle", ""

    def speak(self, voice: dict, text: str, speed: float):
        self.check_installed()
        ref_audio = voice.get("ref_audio_path")
        if not ref_audio:
            raise EngineError("This F5 voice has no reference clip. Upload or record one.")
        if self.state == "loading":
            raise EngineLoading(f"F5-TTS is loading: {self.message}")
        # Never loaded by a request: the first load downloads 5.4 GB, which
        # should be a choice someone makes on the settings page.
        if self.model is None:
            raise EngineLoading("F5-TTS is not loaded. Press Load under Engines on the settings page, "
                                "or tick 'Load when the server starts'.")
        seed = voice.get("seed")
        seed = int(seed) if seed not in (None, "") and int(seed) >= 0 else None
        with self.lock:
            wave, rate, _ = self.model.infer(
                ref_file=ref_audio,
                # Blank makes F5 transcribe the clip with Whisper, which loads
                # another 1.5 GB model; the settings page asks for the words.
                ref_text=(voice.get("ref_text") or "").strip(),
                gen_text=text,
                nfe_step=int(voice.get("nfe_step", 32)),
                cfg_strength=float(voice.get("cfg_strength", 2.0)),
                speed=speed,
                remove_silence=bool(voice.get("remove_silence", False)),
                seed=seed,
                show_info=lambda *args, **kwargs: None,
            )
        return np.asarray(wave, dtype=np.float32), int(rate)


def resolve_checkpoint(checkpoint: str) -> str:
    """A local path for hf://owner/repo/path/to/file, downloading it once into the Hugging Face cache."""
    if not checkpoint.startswith("hf://"):
        return checkpoint
    owner, repo, *path = checkpoint[len("hf://"):].split("/")
    from huggingface_hub import hf_hub_download
    return hf_hub_download(repo_id=f"{owner}/{repo}", filename="/".join(path))


def gpu_status() -> dict | None:
    try:
        import torch
    except ImportError:
        return None
    if not torch.cuda.is_available():
        return None
    free, total = torch.cuda.mem_get_info()
    return {"name": torch.cuda.get_device_name(0),
            "total_mb": total // 2**20,
            "used_mb": (total - free) // 2**20,
            "this_server_mb": torch.cuda.memory_reserved() // 2**20}
