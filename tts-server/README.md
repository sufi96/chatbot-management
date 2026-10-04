# Malaysian TTS server

A self-hosted text-to-speech server for the chat app's **Speech server** voice
engine. It speaks the OpenAI audio API (`POST /v1/audio/speech`) and makes the
sound with one of two Malaysian models:

| Engine | What a voice is | Languages | Hardware | Licence |
|---|---|---|---|---|
| **VITS** (Malaya-Speech) | One of 14 Mesolitica speakers: Yasmin, Osman, Orkid, Bunga, Jebat, Tuah… (about 150 MB each) | Malay | CPU is enough: about 0.25× real time on a desktop CPU | Library MIT; the model pages state none, so ask Mesolitica before selling a product on them |
| **F5-TTS** (Malaysian-F5-TTS-v3) | A 5–12 second reference clip and the words in it; the model speaks new text in that voice | Malay, Malaysian English, Mandarin, mixed in one sentence | NVIDIA GPU, 2–4 GB of VRAM while speaking (8 GB cards are fine) | **CC-BY-NC 4.0**: testing only, unless Mesolitica licenses it |

Two voices come ready: `yasmin` (female) and `osman` (male), both VITS.

## Run it

With the dev start scripts: `start-dev.bat` (or `start-dev.sh`) sets it up in
`tts-server/.venv` with CPU PyTorch and starts it with the portal and engine.
Set `TTS_TORCH=cu128` for CUDA PyTorch, or `SKIP_TTS=1` to leave it out. See
[INSTALLATION.md](../INSTALLATION.md#5-install-the-tts-server-optional).

With Docker (from the repository root):

```bash
docker compose -f tts-server/compose.yaml --profile gpu up -d --build   # NVIDIA GPU
docker compose -f tts-server/compose.yaml --profile cpu up -d --build   # no GPU
```

The GPU profile needs the NVIDIA driver and, on Windows, Docker Desktop with
the WSL2 backend. Voices and clips are kept in the `tts-data` volume and
downloaded models in `tts-models`.

Without Docker (Python 3.11):

```bash
cd tts-server
python -m venv .venv && .venv/Scripts/activate        # Linux/macOS: source .venv/bin/activate
pip install torch torchaudio --index-url https://download.pytorch.org/whl/cu128   # or .../whl/cpu
pip install -r requirements.txt
uvicorn app:app --port 5051
```

Then open **http://localhost:5051**.

## The settings page

- **Voices**: add, rename, and delete voices. For a VITS voice, pick the speaker
  and how much its reading varies. For an F5 voice, upload a clip or record
  one in the browser, type the exact words in it, and set quality steps
  (16 is quick, 32 is the default).
- **Try it**: play any text in the voice as the form has it, saved or not,
  with the time taken. **Play every saved voice** compares them on the same
  text.
- **Engines**: VITS on CPU or GPU, the F5 checkpoint and device, Load and
  Unload. The header shows how much of the GPU is in use and how much of that
  is this server. F5 loads only when you press **Load**, or at start when
  ticked: the first load downloads 5.4 GB.
- **Connect the chat app**: the URL and voice names to enter.

Set `TTS_API_KEY` to require `Authorization: Bearer <key>` on every API call;
the page then asks for the key.

## Connect the chat app

1. Admin settings → **Providers**: add one with base URL
   `http://localhost:5051/v1`, or `http://host.docker.internal:5051/v1` when
   the API engine runs in Docker.
2. Admin settings → **Voice → Speaking**: choose **Speech server** and that
   provider. The page reads this server's model (`tts-1`) and voices from
   `GET /v1/audio/voices` and lists them in each slot. Press ▶ to hear them.

A voice can also answer to other names (*Also answers to* on the settings
page). `yasmin` and `osman` answer to Microsoft's `ms-MY-YasminNeural` and
`ms-MY-OsmanNeural`, the chat app's default Malay voices, so those work
unchanged.

The English slots need an F5 voice, an English speaker on another engine, or
Azure. VITS reads English with a Malay accent.

## Known limits

- Malaya's normaliser reads times and amounts well. Phone numbers it reads
  as a range: `03-8888 1234` becomes "tiga hingga lapan ribu…".
- F5 makes a whole sentence before any sound plays, so a short reply takes
  about 1–3 seconds on a consumer GPU.
- Malaya-Speech 1.4 is published as a release candidate (`1.4.0rc2`); the
  stable 1.3 has only four VITS speakers.

## Tests

```bash
pytest -q tests
```

These use a stand-in engine, so they need neither model.
