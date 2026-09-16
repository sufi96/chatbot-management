# Sparks Arrival Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Everything the two DGX Sparks need that can be built before they arrive:
- the engine asks embedding models for the configured vector size and refuses a mismatch
- a `doctor` command checks every model endpoint and shared secret the install depends on
- Compose files for both nodes
- a runbook from unboxing to a measured model choice

**Architecture:**
- **Embeddings.** `kb/embedding.py` gains a `dimensions` argument and a `client_for(settings)` constructor, used by indexing and retrieval alike. The engine sends `dimensions` and drops it when an endpoint refuses it. It raises when the returned length differs from the setting, because pgvector's column has a fixed width.
- **Doctor.** `api-engine/doctor.py` reads the install's own settings and bots from the database and sends one small request per endpoint. It covers:
  - every answer model an active bot uses
  - the configured SQL, intent and guard roles
  - embedding, rerank and vision
  - the engine's call into the portal, which is exactly the check that would have caught today's missing secrets
- **Deploy files.** `deploy/sparks/` holds one Compose file per node plus an example environment file. Every image tag and model repository is a variable, because none can be confirmed before the hardware exists.

**Tech Stack:** Python, httpx, pytest; Docker Compose; vLLM.

**Spec:** `docs/model-stack-review.md` sections 6 to 8, the roster in `docs/model-stack-review.md` section 6, and the plan 7 row in `docs/superpowers/plans/2026-09-15-spark-readiness-roadmap.md`.

## Measured before planning (2026-09-15)

- **Ollama honours `dimensions`.** `nomic-embed-text` returned 768 numbers with no parameter, 768 when asked for 768, and 256 when asked for 256.
- **Docker Compose v5.4.0 is installed** on the development PC. The Docker engine is not running, but `docker compose config` validates files without it.

## Global Constraints

- **`dimensions`.** Sent only when the setting is a positive integer. A 400 response whose body mentions `dimensions` is retried once without it.
- **Length check.** A returned vector whose length differs from the configured dimensions raises `ValueError`. The message names both numbers and the setting to change.
- **Doctor reads, never writes.** Its only requests are one-word chat completions, one short embedding, one three-passage rerank, a one-pixel vision request, and a portal query for a connection id that cannot exist.
- **Doctor exit codes.** Exits 0 when every check that ran passed, 1 when any failed. An unconfigured role is reported as skipped, not failed.
- **Deploy files.**
  - They contain no secrets.
  - The API key, Hugging Face token, image tag and model ids come from `deploy/sparks/.env`, which git ignores.
  - The committed example is `deploy/sparks/.env.example`.
- **vLLM memory shares.** They sum below 1.0 on each node:
  - node A: 0.85
  - node B: intent and SQL 0.34, embedding 0.10, rerank 0.04, guard 0.09, vision 0.15, for 0.72 in all

  This leaves about 36 GB for the system and image generation later. The first draft gave node B 0.65, which was too tight: an 8B vision model in 16-bit is about 17 GB before any KV cache, so vision gets 0.15 and an FP8 checkpoint.
- **Commands.** Engine tests run from `api-engine/` with `.venv\Scripts\python.exe -m pytest`.

## File map

| File | Change | Responsibility |
|---|---|---|
| `api-engine/kb/embedding.py` | Modify | `dimensions`, refusal retry, length check, `client_for` |
| `api-engine/kb/indexer.py`, `api-engine/kb/retrieval.py` | Modify | Build the client through `client_for` |
| `api-engine/tests/test_embedding.py` | Modify | Dimension behaviour |
| `api-engine/doctor.py` | Create | Checks and CLI |
| `api-engine/tests/test_doctor.py` | Create | Checks against faked endpoints |
| `deploy/sparks/node-a.compose.yaml` | Create | The answer model |
| `deploy/sparks/node-b.compose.yaml` | Create | Intent and SQL, embedding, rerank, guard, vision |
| `deploy/sparks/.env.example` | Create | Every variable the files read |
| `.gitignore` | Modify | `deploy/sparks/.env` |
| `docs/sparks-setup.md` | Create | The runbook |
| `docs/superpowers/plans/2026-09-15-spark-readiness-roadmap.md` | Modify | Plan 7 status |

---

### Task 1: Embeddings at the configured size

- [ ] **Step 1: Write the failing tests.** Append to `tests/test_embedding.py`:
  - `dimensions` is sent when given and absent when not
  - a 400 naming `dimensions` is retried without it
  - a vector of the wrong length raises with both numbers in the message
  - `client_for(settings)` carries URL, key, model and `int(embedding_dimensions)`
  - a blank or zero dimensions setting yields `dimensions=None`
- [ ] **Step 2: Run them.** Expected: failures on the unexpected `dimensions` argument and the missing `client_for`.
- [ ] **Step 3: Implement** in `kb/embedding.py`. Replace the `EmbeddingClient(...)` constructions in `kb/indexer.py` and `kb/retrieval.py` with `client_for(settings)`.
- [ ] **Step 4: Run the full engine suite.**
- [ ] **Step 5: Probe the real endpoint.** Call Ollama through `client_for` with the dev settings and confirm 768 numbers come back.
- [ ] **Step 6: Commit** with `feat: embeddings are asked for the configured size and checked against it`.

### Task 2: `python -m doctor`

Each check returns `Check(name, endpoint, model, status, detail, seconds)`, where `status` is `ok`, `fail` or `skipped`.

| Check | Request | Passes when |
|---|---|---|
| `answer` (one per distinct endpoint and model used by active bots) | Chat completion, `max_tokens` 5, thinking off | HTTP 200 with a `choices` list |
| `sql`, `intent`, `guard` | Same, against the configured role | Same; `skipped` when the role is blank |
| `embedding` | `client_for(settings).embed(["doctor"])` | No exception; detail names the length |
| `rerank` | Rerank "How long is the warranty?" against a warranty, a shipping and a contact passage | The warranty passage ranks first; `skipped` when blank |
| `vision` | Chat completion with a one-pixel PNG | HTTP 200; `skipped` when blank |
| `portal` | `run_query` for connection `doctor-no-such-connection` | `ok:false` with "No such connection."; HTTP 503 or 401 fails, with the missing or mismatched secret named |

- [ ] **Step 1: Write the failing tests.** Create `tests/test_doctor.py`, driving each check through `httpx.MockTransport`: a passing and a failing case per check, a blank role skipped, and the exit code.
- [ ] **Step 2: Run them.** Expected: `No module named 'doctor'`.
- [ ] **Step 3: Implement** `api-engine/doctor.py`.
- [ ] **Step 4: Run the full engine suite.**
- [ ] **Step 5: Run it for real** from `api-engine/`: `.venv\Scripts\python.exe -m doctor`. Expected against the dev install: `answer` ok (laptop vLLM), `embedding` ok (768), `rerank` ok (local llama.cpp), `portal` ok, the rest skipped.
- [ ] **Step 6: Commit** with `feat: python -m doctor checks every model endpoint and shared secret`.

### Task 3: Compose files for both nodes

- [ ] **Step 1: Write the files.** Create `deploy/sparks/node-a.compose.yaml`, `node-b.compose.yaml` and `.env.example`, and add `deploy/sparks/.env` to `.gitignore`.
- [ ] **Step 2: Validate the syntax.** Run `docker compose --env-file deploy/sparks/.env.example -f deploy/sparks/node-a.compose.yaml config --quiet`, and the same for node B. Expected: exit 0.
- [ ] **Step 3: Commit** with `feat: compose files for the two DGX Sparks`.

### Task 4: The runbook

- [ ] **Step 1: Write `docs/sparks-setup.md`,** covering:
  - before power-on
  - networking the two nodes
  - model downloads
  - starting each node
  - pointing the portal at them
  - running `doctor`
  - switching the embedding model and rebuilding the index
  - choosing models with the evaluation sets
  - load testing
  - keeping services up
  - what could not be verified in advance
- [ ] **Step 2: Update the roadmap.** Set plan 7's status to `Ready; to be run on arrival`.
- [ ] **Step 3: Commit** with `docs: a runbook for bringing the Sparks up`.
