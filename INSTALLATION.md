# Installation

What this project is made of, what each machine needs, and how to bring it up
on a fresh clone. The [README](README.md) covers features; this file covers
setup.

Last updated 2026-10-04.

---

## 1. The stack at a glance

| Part | Folder | Built with | Port | Needed? |
|---|---|---|---|---|
| **Admin portal**: every screen, users, bots, settings, and the owner of the database schema | `admin-laravel/` | Laravel 13 on **PHP 8.3+**, Composer | 8080 | Yes |
| **API engine**: chat streaming, retrieval, embedding, web search, voice routes; also serves the widget | `api-engine/` | FastAPI on **Python 3.10–3.13** (not 3.14 yet) | 8000 | Yes |
| **Widget**: the chat bubble a website embeds with one script tag | `widget/` | Plain JavaScript, served by the engine at `/widget.js` | (8000) | Yes |
| **Database** | — | **PostgreSQL 17 + pgvector 0.8** (default), or SQLite (fallback, no setup) | 5432 | Yes, one of them |
| **Language models**: answering, embedding, and the other model roles | — | Any OpenAI-compatible server or cloud provider; Ollama for local tests; vLLM on the DGX Sparks for production | 11434 (Ollama), 8000–8005 (Sparks) | Yes, at least one provider |
| **Malaysian TTS server**: self-hosted Malay voices | `tts-server/` | FastAPI + PyTorch; Malaya-Speech VITS and Malaysian F5-TTS | 5051 | Optional |
| Demo pages | `demo/` | Static HTML | — | No |
| Old portal | `admin-php/` | Plain PHP | — | No. Replaced by `admin-laravel/`; no script or compose file starts it |

How they talk to each other:

```
 Browser (admin) ──► Admin portal :8080 ──► API engine :8000 (admin routes, shared secret)
                          │                     │
 Website visitor ──► widget.js ───────────────► │ (chat, voice; allowed sites only)
                          │                     │
                          └──── Database ◄──────┘
                                                │
                     Model providers ◄──────────┤  (Ollama / cloud / DGX Sparks)
                     TTS server :5051 ◄─────────┘  (only when Voice → Speaking = Speech server)
```

The portal and engine share no code, only the database tables. See
[`docs/architecture.md`](docs/architecture.md) for the reasoning.

---

## 2. How many servers

**Development and testing: one machine.** The portal and engine run as two
processes, with SQLite or a local PostgreSQL, and a model provider (Ollama or
a cloud key). The start scripts run the TTS server as a third process (port
5051); set `SKIP_TTS=1` to leave it out.

**Production plan: three machines.**

| Machine | Runs | Ports |
|---|---|---|
| **App host** (any server; no GPU needed) | Admin portal, API engine, PostgreSQL + pgvector. The TTS server's VITS voices can run here on CPU too | 8080, 8000, 5432 (5051) |
| **DGX Spark node A** | The answer model, alone (vLLM) | 8000 |
| **DGX Spark node B** | Intent/SQL, embedding, rerank, guard and vision models (vLLM) | 8001–8005 |

The Sparks (expected late 2026) have their own runbook:
[`docs/sparks-setup.md`](docs/sparks-setup.md), with compose files in
`deploy/sparks/`. F5-TTS, if it is used beyond testing, needs a GPU: a Spark,
or any NVIDIA card with 8 GB or more.

### The machines in use now

| Machine | GPU | Used for |
|---|---|---|
| Main PC (Windows 11) | RTX 3080, 10 GB | Development. `start-dev.bat` runs the TTS server on **CPU PyTorch** (the default; VITS voices). CUDA PyTorch is not installed here |
| Second test machine | 8 GB VRAM | Next: the GPU test of F5-TTS (section 6) |

---

## 3. What to install

### For the portal and engine

| Tool | Version | Notes |
|---|---|---|
| Git | any | Then run the hook command in section 4 |
| PHP | **8.3+** | Extensions: `pdo`, `pdo_sqlite` and/or `pdo_pgsql`, `curl`, `mbstring`, `openssl`, `fileinfo` |
| Composer | 2.x | [getcomposer.org](https://getcomposer.org/) |
| Python | **3.10–3.13** | 3.14 is not supported by the engine's dependencies yet. On Linux/macOS, `start-dev.sh` fetches 3.12 with [uv](https://docs.astral.sh/uv/) if needed |
| PostgreSQL | 17 with **pgvector 0.8** | Optional: SQLite works without it, and is fine into the tens of thousands of passages |
| Node.js | 18+ | Only to run the widget's tests |
| Ollama | latest | Optional, for local models |

### For the TTS server (optional)

| Tool | Needed for | Notes |
|---|---|---|
| Python 3.11 | Running it without Docker | Same as the engine's range |
| **NVIDIA driver** | F5-TTS on GPU | Check with `nvidia-smi`. **The CUDA Toolkit is not needed**: PyTorch's CUDA wheels bring their own CUDA runtime. Only the driver must be recent enough (for CUDA 12.8 wheels, driver 570+) |
| Docker Desktop (WSL2 backend) + NVIDIA driver | Running it in Docker on Windows | GPU containers need the WSL2 backend. Linux needs the NVIDIA Container Toolkit instead |
| Disk space | Models | VITS about 150 MB per voice; F5 checkpoint 5.4 GB; PyTorch CUDA about 2.5 GB |

---

## 4. Install the portal and engine

### 4.1 Clone

```bash
git clone https://github.com/sufi96/chatbot-management.git
cd chatbot-management
git config core.hooksPath .githooks     # turns on the secret scanner; once per clone
```

### 4.2 Start (scripts do everything)

- **Windows:** `start-dev.bat` (or `.\start-dev.ps1`). Stop with Ctrl+C, or `stop-dev.bat`.
- **Linux / macOS:** `./start-dev.sh`

The scripts create `api-engine/.venv`, install Python and Composer
dependencies, create both `.env` files from their `.env.example`, generate the
shared secrets, migrate and seed the database, and start:

- Admin portal: http://localhost:8080 — log in as `admin@chatbothub.com` / `password`
- API engine: http://localhost:8000 (docs at `/docs`, widget at `/widget.js`)

The database is SQLite unless `admin-laravel/.env` says `DB_CONNECTION=pgsql`.

### 4.3 Manual steps

See [README](README.md) → *Step 3: Manual Startup*.
In short: `pip install -r api-engine/requirements.txt` in a venv and
`uvicorn main:app --port 8000`; then in `admin-laravel/` run
`composer install`, `cp .env.example .env`, `php artisan key:generate`,
`php artisan migrate --seed`, `php artisan serve --port=8080`.

### 4.4 PostgreSQL instead of SQLite

```sql
CREATE DATABASE chatbot_hub;
\c chatbot_hub
CREATE EXTENSION IF NOT EXISTS vector;
```

Then set `DB_CONNECTION=pgsql` and the `DB_*` values in `admin-laravel/.env`,
`DATABASE_URL=postgresql+asyncpg://...` in `api-engine/.env`, and run
`php artisan migrate --seed`. Details are in
[README](README.md) → *Database Options*.

> **Known issue with the root `docker-compose.yml`:** it uses
> `postgres:16-alpine`, which has no pgvector, so the knowledge-base
> migration (`CREATE EXTENSION vector`) fails there. Use a pgvector image
> (e.g. `pgvector/pgvector:pg17`) until that file is updated. It also needs
> `POSTGRES_PASSWORD` in `./.env` (copy `.env.example`).

### 4.5 A model provider

Either a cloud key or a local server. For Ollama: `ollama pull llama3.2`, then
add it under the portal's **Providers** (preset *Local Ollama*,
`http://localhost:11434`). See [README](README.md) → *Connecting to Local Ollama*.

---

## 5. Install the TTS server (optional)

Full details are in [`tts-server/README.md`](tts-server/README.md). Briefly:

| Engine | Voices | Hardware | Licence |
|---|---|---|---|
| VITS (Malaya-Speech 1.4.0rc2) | 14 Malay speakers; `yasmin` (female) and `osman` (male) come ready | CPU is enough | Library MIT; model licence unstated, so ask Mesolitica before commercial use |
| F5-TTS (Malaysian-F5-TTS-v3) | Any voice from a 5–12 s clip; Malay, Malaysian English, Mandarin | NVIDIA GPU, 2–4 GB VRAM while speaking | **CC-BY-NC 4.0**: testing only |

### 5.1 With the start scripts (the dev setup)

`start-dev.bat` / `start-dev.ps1` / `start-dev.sh` set it up and start it with
the portal and engine. The setup is step 4 of 6. It gets its own virtualenv,
`tts-server/.venv`, because its PyTorch, transformers and Malaya packages
would clash with the engine's. The first run downloads PyTorch and the voice
libraries (about 1–2 GB with the CPU build) and takes several minutes; later
runs reinstall only when `tts-server/requirements.txt` changes.

| Setting | Effect |
|---|---|
| *(nothing)* | CPU PyTorch. VITS voices are quick; F5 runs, slowly |
| `TTS_TORCH=cu128` | CUDA PyTorch, for F5 on an NVIDIA GPU. Changing it reinstalls PyTorch on the next start |
| `SKIP_TTS=1` | Start without the TTS server |

Windows: `set TTS_TORCH=cu128` in the console before `start-dev.bat`, or set
it once with `setx TTS_TORCH cu128` (applies to new consoles).
Linux/macOS: `TTS_TORCH=cu128 ./start-dev.sh`.

The TTS server is optional to the scripts. If its setup fails, or it stops
later, the portal and engine keep running and the script says so.
`stop-dev.bat` frees its port (5051) along with the others.

### 5.2 With Docker

```bash
docker compose -f tts-server/compose.yaml --profile gpu up -d --build   # NVIDIA GPU
docker compose -f tts-server/compose.yaml --profile cpu up -d --build   # no GPU
```

Check that containers can see the GPU first:
`docker run --rm --gpus all nvidia/cuda:12.8.0-base-ubuntu24.04 nvidia-smi`.

### 5.3 By hand

```bash
cd tts-server
python -m venv .venv
.venv\Scripts\activate                     # Linux/macOS: source .venv/bin/activate

# GPU machine (pick the wheel for your driver at pytorch.org/get-started):
pip install torch torchaudio --index-url https://download.pytorch.org/whl/cu128
# CPU-only machine:
# pip install torch torchaudio --index-url https://download.pytorch.org/whl/cpu

pip install -r requirements.txt
uvicorn app:app --port 5051
```

Install PyTorch **before** `requirements.txt`. Otherwise pip pulls the default
PyTorch build, which can be CPU-only on Windows. Check with:

```bash
python -c "import torch; print(torch.__version__, torch.cuda.is_available(), torch.cuda.get_device_name(0) if torch.cuda.is_available() else '')"
```

### 5.4 Connect it to the app

1. Open http://localhost:5051. The header should show the GPU, if there is one.
2. In the portal, go to Admin settings → **Providers** and add base URL `http://localhost:5051/v1`
   (`http://host.docker.internal:5051/v1` if the engine runs in Docker).
3. Admin settings → **Voice → Speaking**: choose **Speech server** and that provider. The page asks the
   server for its models and voices: Model fills in as `tts-1`, and `yasmin` / `osman` appear at the top
   of each voice list. The default Malay choices (Microsoft's `ms-MY-YasminNeural` / `ms-MY-OsmanNeural`)
   also work unchanged, because the TTS server answers to them. The English slots need an English voice
   on the TTS server (an F5 voice), or Azure.
4. Turn voice on per bot under **Behaviour → Voice**.

---

## 6. Continuing the F5-TTS test on the GPU machine

The F5 code follows the installed `f5-tts` 1.1 API. It has not made a sound
yet, because the main PC was only set up with CPU PyTorch. On the GPU machine:

1. `nvidia-smi` shows the card, and the driver is 570+ for `cu128` wheels.
2. Set `TTS_TORCH=cu128` (5.1) and run the start script. It replaces any CPU PyTorch in
   `tts-server/.venv`. Confirm with
   `tts-server\.venv\Scripts\python -c "import torch; print(torch.cuda.is_available())"`, which should print `True`.
3. Run the unit tests: `tts-server\.venv\Scripts\python -m pytest -q tts-server\tests` (they need no model).
4. Open http://localhost:5051. Under **Engines → F5-TTS**, leave the checkpoint as
   `model_220000.pt (newest)` and the device as *GPU if there is one*, then press **Load**.
   The first load downloads 5.4 GB into the Hugging Face cache (`~/.cache/huggingface`).
5. **+ New voice** → engine F5 → **Record** 5–12 s (or upload a clip), type the exact words, **Save voice**.
6. **Try it** → ▶ Play. Note the "× real time" figure, and the VRAM shown in the header.
7. With an 8 GB card, close other GPU programs. Always fill in the clip's words, or F5 loads
   Whisper (about 1.5 GB more) to transcribe it.

Record what you find (speed, VRAM, quality against VITS) in
[`docs/model-stack-review.md`](docs/model-stack-review.md).

---

## 7. Ports

| Port | Service |
|---|---|
| 8080 | Admin portal |
| 8000 | API engine (and `widget.js`). On the Sparks, node A's answer model also uses 8000, on a different machine |
| 8001–8005 | Spark node B models |
| 5432 | PostgreSQL |
| 5051 | Malaysian TTS server |
| 5050 | `openai-edge-tts` test container, if used (see README → Setting up voice) |
| 11434 | Ollama |

## 8. Settings files

All `.env` files are gitignored. Each has a `.env.example` with secrets left blank.

| File | Holds | Made by |
|---|---|---|
| `admin-laravel/.env` | App key, database, engine URL, shared secret | Start scripts |
| `api-engine/.env` | Database URL, portal URL, shared secret | Start scripts |
| `.env` (root) | `POSTGRES_PASSWORD` for the root `docker-compose.yml` | You |
| `deploy/sparks/.env` | vLLM image, model names, API key | You, on each Spark |
| TTS: `TTS_API_KEY` (environment variable) | Optional key for the TTS server | You |
| `tts-server/data/settings.json` | TTS voices and engine choices (from its settings page) | The TTS server |

## 9. Checking an install

```bash
python verify_services.py                          # engine health, widget, portal
cd api-engine && .venv/Scripts/python -m pytest -q # engine tests (Linux/macOS: .venv/bin/python)
cd admin-laravel && php artisan test               # portal tests
node --test widget/*.test.js                              # widget tests
cd tts-server && pytest -q tests                   # TTS server tests
```
