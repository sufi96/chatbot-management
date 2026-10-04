"""The server's routes and settings, with a stand-in engine so no model is needed."""
import io
import sys
import wave
from pathlib import Path

import numpy as np
import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from engines import EngineLoading, split_sentences  # noqa: E402
from app import create_app  # noqa: E402


class FakeEngine:
    def __init__(self, name, loading=False):
        self.name, self.loading, self.calls, self.settings = name, loading, [], {}

    def installed(self):
        return True

    def configure(self, settings):
        self.settings = settings

    def status(self):
        return {"installed": True, "state": "ready", "message": ""}

    def load_in_background(self):
        pass

    def unload(self):
        pass

    def speak(self, voice, text, speed):
        if self.loading:
            raise EngineLoading("F5-TTS is loading")
        self.calls.append((voice, text, speed))
        return np.zeros(2205, dtype=np.float32), 22050


@pytest.fixture
def server(tmp_path):
    engines = {"vits": FakeEngine("vits"), "f5": FakeEngine("f5")}
    return TestClient(create_app(tmp_path, engines, api_key="")), engines


def test_speaks_a_default_voice_as_mp3(server):
    client, engines = server
    res = client.post("/v1/audio/speech", json={"model": "tts-1", "voice": "yasmin",
                                                 "input": "Selamat pagi.", "speed": 1.25})
    assert res.status_code == 200
    assert res.headers["content-type"].startswith("audio/")
    voice, text, speed = engines["vits"].calls[0]
    assert voice["model"] == "mesolitica/VITS-yasmin" and text == "Selamat pagi." and speed == 1.25


def test_wav_is_a_real_wav(server):
    client, _ = server
    res = client.post("/v1/audio/speech", json={"voice": "osman", "input": "Hai", "response_format": "wav"})
    with wave.open(io.BytesIO(res.content)) as w:
        assert w.getframerate() == 22050 and w.getnframes() == 2205


def test_the_chat_apps_microsoft_names_reach_the_default_voices(server):
    # The portal's Voice page offers ms-MY-YasminNeural; it must work as it is.
    client, engines = server
    res = client.post("/v1/audio/speech", json={"voice": "ms-MY-YasminNeural", "input": "Hai"})
    assert res.status_code == 200
    assert engines["vits"].calls[0][0]["model"] == "mesolitica/VITS-yasmin"
    listed = client.get("/v1/audio/voices").json()
    assert {"id": "osman", "engine": "vits", "aliases": ["ms-MY-OsmanNeural"]} in listed["data"]


def test_aliases_are_kept_and_cannot_be_shared(server):
    client, _ = server
    ok = client.put("/api/voices/amira", json={"voice": {"engine": "vits", "model": "mesolitica/VITS-orkid",
                                                         "aliases": "Amira, en-US-AvaNeural, amira-2"}})
    assert ok.status_code == 200
    assert client.get("/api/state").json()["voices"]["amira"]["aliases"] == ["Amira", "en-US-AvaNeural", "amira-2"]
    assert client.post("/v1/audio/speech", json={"voice": "EN-US-AVANEURAL", "input": "Hi"}).status_code == 200
    taken = client.put("/api/voices/other", json={"voice": {"engine": "vits", "model": "mesolitica/VITS-male",
                                                            "aliases": ["ms-MY-OsmanNeural"]}})
    assert taken.status_code == 422 and "osman" in taken.text
    named_like_alias = client.put("/api/voices/amira-2", json={"voice": {"engine": "vits", "model": "mesolitica/VITS-male"}})
    assert named_like_alias.status_code == 422
    # Saving a voice again keeps its own aliases without a clash.
    again = client.put("/api/voices/amira", json={"voice": {"engine": "vits", "model": "mesolitica/VITS-orkid",
                                                            "aliases": ["en-US-AvaNeural"]}, "rename_from": "amira"})
    assert again.status_code == 200
    assert client.put("/api/voices/x", json={"voice": {"engine": "vits", "model": "mesolitica/VITS-male",
                                                       "aliases": ["bad name!"]}}).status_code == 422


def test_unknown_voice_names_the_voice(server):
    # The chat app turns a 400 that mentions "voice" into "does not know the voice".
    client, _ = server
    res = client.post("/v1/audio/speech", json={"voice": "nobody", "input": "Hai"})
    assert res.status_code == 400
    assert "voice" in res.text.lower() and "yasmin" in res.text


def test_loading_engine_answers_503(tmp_path):
    engines = {"vits": FakeEngine("vits"), "f5": FakeEngine("f5", loading=True)}
    client = TestClient(create_app(tmp_path, engines, api_key=""))
    ref = client.post("/api/refs", files={"file": ("a.wav", b"RIFF....", "audio/wav")}).json()["ref"]
    client.put("/api/voices/f5-amira", json={"voice": {"engine": "f5", "ref_audio": ref, "ref_text": "Helo"}})
    res = client.post("/v1/audio/speech", json={"voice": "f5-amira", "input": "Hai"})
    assert res.status_code == 503 and "loading" in res.text


def test_api_key_guards_both_apis(tmp_path):
    client = TestClient(create_app(tmp_path, {"vits": FakeEngine("vits")}, api_key="sekret"))
    assert client.get("/api/state").status_code == 401
    assert client.post("/v1/audio/speech", json={"voice": "yasmin", "input": "Hai"}).status_code == 401
    ok = client.post("/v1/audio/speech", json={"voice": "yasmin", "input": "Hai"},
                     headers={"Authorization": "Bearer sekret"})
    assert ok.status_code == 200
    assert client.get("/").status_code == 200


def test_voice_save_rename_and_delete(server, tmp_path):
    client, _ = server
    res = client.put("/api/voices/Wanita", json={"voice": {"engine": "vits", "model": "mesolitica/VITS-orkid",
                                                           "temperature": 0.3, "junk": 1}})
    assert res.json()["name"] == "wanita"
    assert client.get("/api/state").json()["voices"]["wanita"] == {
        "engine": "vits", "model": "mesolitica/VITS-orkid", "temperature": 0.3}
    client.put("/api/voices/perempuan", json={"voice": {"engine": "vits", "model": "mesolitica/VITS-orkid"},
                                              "rename_from": "wanita"})
    voices = client.get("/api/state").json()["voices"]
    assert "perempuan" in voices and "wanita" not in voices
    client.delete("/api/voices/perempuan")
    # Kept on disk: a new server on the same folder sees the same voices.
    again = TestClient(create_app(tmp_path, {"vits": FakeEngine("vits")}, api_key=""))
    assert "perempuan" not in again.get("/api/state").json()["voices"]
    assert "yasmin" in again.get("/api/state").json()["voices"]


def test_bad_voices_are_refused(server):
    client, _ = server
    assert client.put("/api/voices/a b", json={"voice": {"engine": "vits", "model": "mesolitica/VITS-osman"}}).status_code == 422
    assert client.put("/api/voices/x", json={"voice": {"engine": "vits", "model": "someone/else"}}).status_code == 422
    assert client.put("/api/voices/x", json={"voice": {"engine": "vits", "model": "mesolitica/VITS-osman",
                                                       "temperature": 9}}).status_code == 422
    assert client.put("/api/voices/yasmin", json={"voice": {"engine": "vits", "model": "mesolitica/VITS-osman"},
                                                  "rename_from": "osman"}).status_code == 422
    assert client.put("/api/voices/x", json={"voice": {"engine": "f5", "ref_audio": "../../etc/passwd"}}).status_code == 422


def test_reference_clips(server):
    client, _ = server
    assert client.post("/api/refs", files={"file": ("a.exe", b"MZ", "application/octet-stream")}).status_code == 422
    ref = client.post("/api/refs", files={"file": ("a.wav", b"RIFFdata", "audio/wav")}).json()["ref"]
    assert client.get(f"/api/refs/{ref}").content == b"RIFFdata"
    assert client.get("/api/refs/..%2Fsettings.json").status_code == 404


def test_try_an_unsaved_voice(server):
    client, engines = server
    ref = client.post("/api/refs", files={"file": ("a.wav", b"RIFF", "audio/wav")}).json()["ref"]
    res = client.post("/api/test", json={"voice": {"engine": "f5", "ref_audio": ref, "ref_text": "Helo",
                                                   "nfe_step": 16}, "text": "Apa khabar?"})
    assert res.status_code == 200 and float(res.headers["X-Audio-Seconds"]) == pytest.approx(0.1)
    voice, _, _ = engines["f5"].calls[0]
    assert voice["ref_audio_path"].endswith(ref) and voice["nfe_step"] == 16


def test_engine_settings_reach_the_engines(server):
    client, engines = server
    client.put("/api/engines", json={"vits": {"device": "cuda", "preload": ["mesolitica/VITS-osman", "evil"]},
                                     "f5": {"device": "gpu?", "checkpoint": "", "preload": True}})
    assert engines["vits"].settings == {"device": "cuda", "preload": ["mesolitica/VITS-osman"]}
    assert engines["f5"].settings["device"] == "auto" and engines["f5"].settings["checkpoint"].startswith("hf://")


def test_a_request_never_starts_the_f5_download(monkeypatch):
    from engines import EngineError, F5Engine
    engine = F5Engine()
    monkeypatch.setattr(engine, "installed", lambda: True)
    monkeypatch.setattr(engine, "load_in_background", lambda: pytest.fail("a request started loading F5"))
    with pytest.raises(EngineError, match="no reference clip"):
        engine.speak({"engine": "f5"}, "Hai", 1.0)
    with pytest.raises(EngineLoading, match="Press Load"):
        engine.speak({"engine": "f5", "ref_audio_path": "clip.wav"}, "Hai", 1.0)
    assert engine.state == "idle"


def test_split_sentences_keeps_every_word_and_stays_short():
    text = "Selamat datang. Apa khabar? " + "satu dua tiga, " * 40 + "\nTamat!"
    pieces = split_sentences(text, limit=80)
    assert all(len(p) <= 81 for p in pieces)
    assert " ".join(pieces).split() == text.split()
