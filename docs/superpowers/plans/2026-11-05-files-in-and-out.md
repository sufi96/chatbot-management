# Files In and Out Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan
> task-by-task, inline (the user does not want subagents). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Per-bot files in (documents and images a person attaches), files out (a `make_file` tool and
a "Download as" button), and visuals drawn by the display (data blocks and cleaned SVG).

**Architecture:** A new engine package `api-engine/files/` holds settings, storage, checks, scanning,
reading, material, conversion and retention; a new router `routers/files.py` holds upload, export and
download. The chat route gains attachment ids, a file material block, an `answer` event and a `file`
event. The widget gains `widget/visuals.js` (joined into `/widget.js` like `markdown.js` and
`voice.js`), an attach button and download chips. The portal gains one `file_settings` JSON column,
a "Files & visuals" card on the Behaviour page, and file chips in the transcript.

**Tech Stack:** FastAPI, SQLAlchemy async, Laravel 11 migrations (Laravel owns the schema),
markitdown (existing), Pillow (existing), openpyxl (existing), python-docx and fpdf2 (new), boto3 (new,
imported only for the S3 backend), ClamAV `clamd` INSTREAM over TCP (optional), vanilla JS widget.

**Spec:** `docs/superpowers/specs/2026-11-05-files-in-and-out-design.md`

## Global Constraints

- Off by default: a bot with no `file_settings` behaves exactly as today (no attach button, no events, no prompt text).
- Settings change needs editor or above (`canManageSystem($systemId, 'editor')`), which the Behaviour page already enforces.
- Retention default **30 days**, per bot.
- Files-in types offered: `pdf, docx, xlsx, csv, pptx, txt, md, png, jpg, webp`.
- Files-out formats offered: `txt, md, csv, docx, xlsx, pdf`. Mode: `on_request` or `own_initiative`.
- ClamAV missing or down: accept after the cheap checks, `scan_status = "not_scanned"`.
- An infected file is refused, never stored.
- A file never enters a knowledge base.
- Model output and file text are untrusted: shield-screened, spotlight-wrapped, and escaped when drawn.
- Commits carry no AI attribution of any kind (user rule).

## Review Focus

1. A visitor sends attachments that belong to another session or bot: they must be ignored, not read. (Task 6 test)
2. An upload with a renamed extension (`evil.exe` named `report.pdf`): refused for its real type. (Task 3 test)
3. A file the model cannot read (scanned PDF with no vision model, image on a text-only install): the answer says it could not read it. (Task 6 test)
4. A download link used from another session or after expiry: 404/410, never the bytes. (Task 8 test)
5. An SVG block with `<script>`, `onload=`, `href="javascript:"` or `<foreignObject>`: drawn without them. (Task 9 test)

---

### Task 1: Settings, schema and models

**Files:**
- Create: `admin-laravel/database/migrations/2026_11_05_000001_add_files_to_chat.php`
- Create: `api-engine/files/__init__.py`, `api-engine/files/settings.py`
- Modify: `api-engine/database.py` (BotProfile column, new `ChatFile` model)
- Modify: `admin-laravel/app/Models/BotProfile.php` (cast + `FILE_DEFAULTS` + `fileSettings()`)
- Test: `api-engine/tests/test_files_settings.py`

**Interfaces:**
- Produces: `files.settings.for_bot(bot) -> FileSettings` (frozen dataclass: `in_enabled: bool, in_types: tuple[str], max_mb: int, per_message: int, daily: int, out_enabled: bool, out_formats: tuple[str], out_mode: str, visuals: bool, retention_days: int`), `IN_TYPES`, `OUT_FORMATS` tuples.
- Produces: table `chat_files(id uuid pk, conversation_id fk cascade, message_id null, bot_id, direction 'in'|'out', name, mime, ext, size int, storage_key, sha256, scan_status 'clean'|'not_scanned', text longtext null, read_error string null, flag string null, ip_hash string null, token string(64) unique, created_at, expires_at)`; `bot_profiles.file_settings json null`.

- [ ] Write tests: `None` settings → all off, defaults `max_mb=10, per_message=3, daily=20, retention_days=30, out_mode="on_request"`; unknown types dropped; numbers clamped (`max_mb` 1–50, `per_message` 1–10, `daily` 1–500, `retention_days` 1–365); a JSON string is parsed.
- [ ] Run, see them fail; implement; run, see them pass.
- [ ] Migration + `ChatFile` model mirroring it; `php artisan migrate`.
- [ ] Commit `feat(files): per-bot file settings and the chat_files table`.

### Task 2: Storage and retention

**Files:**
- Create: `api-engine/files/storage.py`, `api-engine/files/retention.py`
- Modify: `api-engine/config.py` (`FILE_STORAGE="local"`, `FILE_STORAGE_ROOT` default `<repo>/storage/chat-files`, `S3_BUCKET`, `S3_ENDPOINT`, `S3_KEY`, `S3_SECRET`, `S3_REGION`)
- Modify: `api-engine/main.py` (hourly sweep task in lifespan), `api-engine/requirements.txt` (`boto3`)
- Test: `api-engine/tests/test_files_storage.py`

**Interfaces:**
- Produces: `storage.current() -> Storage` with `async put(key: str, data: bytes)`, `async get(key) -> bytes` (raises `FileNotFoundError`), `async delete(key)`; `LocalStorage(root)`, `S3Storage(client, bucket)` (boto3 imported only inside `S3Storage.from_settings`).
- Produces: `retention.sweep(session, storage, now: datetime) -> int` deletes expired rows and their bytes.

- [ ] Tests: local put/get/delete round trip; a key with `..` is refused; `S3Storage` with a fake client calls `put_object/get_object/delete_object` with the bucket; sweep deletes expired rows and bytes, keeps live ones, survives a missing blob.
- [ ] Fail, implement, pass. Commit `feat(files): local and S3 storage, retention sweep`.

### Task 3: Cheap checks

**Files:** Create `api-engine/files/checks.py`; Test `api-engine/tests/test_files_checks.py`

**Interfaces:**
- Produces: `inspect(data: bytes, filename: str, allowed: tuple[str]) -> Checked(ext, mime, data)`; raises `Refused(message)` with a sentence fit for a visitor.

Real type from content: `%PDF` → pdf; PNG/JPEG/WEBP magic → image (decoded with Pillow, `MAX_IMAGE_PIXELS` 40M, re-saved in the same format with no EXIF); `PK\x03\x04` → read `[Content_Types].xml` / names to tell docx/xlsx/pptx, refuse `vbaProject.bin` (macros), refuse > 1000 entries or > 100 MB uncompressed or ratio > 100 (zip bomb); text kinds (txt, md, csv) must decode as UTF-8 with no NUL. The extension the name claims must agree with the real type (jpg/jpeg alike). PDF refused if it contains `/JavaScript`, `/JS`, `/Launch` or `/EmbeddedFile`.

- [ ] Tests: exe renamed `.pdf` refused; docx with `vbaProject.bin` refused; PDF with `/JavaScript` refused; zip bomb refused; JPEG with EXIF GPS comes back without EXIF; type not in `allowed` refused; plain CSV accepted.
- [ ] Fail, implement, pass. Commit `feat(files): check an upload's real type and refuse risky files`.

### Task 4: ClamAV scan

**Files:** Create `api-engine/files/scan.py`; Modify `config.py` (`CLAMAV_HOST=""`, `CLAMAV_PORT=3310`); Test `api-engine/tests/test_files_scan.py`

**Interfaces:**
- Produces: `async scan(data: bytes, host: str, port: int, timeout: float = 15) -> Scan(status: "clean"|"infected"|"unavailable", name: str = "")`.

- [ ] Tests against a fake asyncio clamd: `stream: OK` → clean; `stream: Eicar-Signature FOUND` → infected with name; nothing listening → unavailable; empty host → unavailable without connecting.
- [ ] Fail, implement (`zINSTREAM\0`, 4-byte big-endian chunk lengths, zero terminator), pass. Commit `feat(files): optional ClamAV scan`.

### Task 5: Upload route and reading

**Files:**
- Create: `api-engine/files/reading.py`, `api-engine/routers/files.py`
- Modify: `api-engine/main.py` (include router), `api-engine/routers/chat.py` (move find-or-create conversation into `conversation_for(db, bot, session_id, origin)` and reuse it)
- Test: `api-engine/tests/test_files_upload.py`

**Interfaces:**
- Consumes: Tasks 1–4.
- Produces: `POST /api/v1/chat/files` (multipart `bot_id`, `session_id`, `file`) → `{id, name, size, kind: "document"|"image", scanned: bool, readable: bool, note}`; 403 origin, 404 bot, 409 files-in off, 413 too big, 415 refused type, 422 infected, 429 daily cap.
- Produces: `reading.read(data, ext, settings, bot) -> (text, error)`: documents via `kb.extract.extract_file` on a temp file; images via `kb.vision.read_file` with `kb.vision.client_for(settings, bot)`; text cut to 200 000 chars; `shield.detect` hit → text withheld and `flag="injection"`.
- Daily cap counts `direction="in"` rows for this bot in the last 24 h whose conversation has this session **or** whose `ip_hash` matches.

- [ ] Tests: off bot → 409; good PDF stored with text and `scan_status="not_scanned"` when no ClamAV; infected (fake scan) → 422 and nothing stored; 21st upload in a day → 429; image on an install with no vision model → stored, `readable=false`, note says no model can read images.
- [ ] Fail, implement, pass. Commit `feat(files): upload route reads documents and images`.

### Task 6: Files in the answer

**Files:** Create `api-engine/files/material.py`; Modify `api-engine/routers/chat.py`; Test `api-engine/tests/test_files_material.py`, `api-engine/tests/test_chat_files.py`

**Interfaces:**
- Produces: `ChatStreamRequest.attachments: list[str] = []` (max 10); `message` may be empty when attachments are given (then `"Please read the attached file."` is used).
- Produces: `material.block(files: list, query: str, budget: int) -> str`: every in-file of the conversation, newest first, spotlight-wrapped under "Files the visitor attached in this conversation:"; a file whose text fits goes whole, else its best-scoring ~1200-char passages by query-word overlap until the budget; an unreadable or withheld file contributes one line telling the model it could not be read and must not guess its contents.
- Chat: attachments linked (`message_id = user_msg.id`) only when the row's conversation is this session's and `direction="in"` and unlinked; more than `per_message` → 400 before anything runs; block prepended to `context_block` before the source material; a non-empty block forces `answer_anyway`; nothing added when blocked.

- [ ] Tests: whole vs passages; unreadable line; another session's file id ignored; empty message with attachment answered from the file (fake LLM sees the file text in its system prompt); bot without files has an unchanged prompt.
- [ ] Fail, implement, pass. Commit `feat(files): attached files go into the answer`.

### Task 7: Converting an answer into a file

**Files:** Create `api-engine/files/convert.py`, `api-engine/files/visual_text.py`; Modify `requirements.txt` (`python-docx`, `fpdf2`); Test `api-engine/tests/test_files_convert.py`

**Interfaces:**
- Produces: `convert.to_file(markdown: str, fmt: str, title: str) -> Made(data: bytes, mime: str, filename: str)`. txt: Markdown marks removed; md: as is; csv: first Markdown table, else one line per row; xlsx: one sheet per table (else lines in column A), header row bold; docx: headings, paragraphs, bullet/numbered lists, tables, bold/italic runs; pdf: same structure with fpdf2 and a Unicode TTF if one is found (DejaVu bundled with fpdf2 fonts dir or Windows Arial), else Latin-1 replacement. Visual blocks (```chart/diagram/timeline/svg) go through `visual_text.to_text(lang, source)` first. Filename = slug of title + ext, default `answer`.
- Produces: `visual_text.to_text(lang, source) -> str`: chart → bar rows with `█` scaled to 30 plus the numbers as a table; diagram → `A → B (label)` lines; timeline → `when — label` lines; svg → `[drawing: <title or "picture">]`; invalid → the source as a code block.

- [ ] Tests: each format opens with its own library (openpyxl, python-docx, `%PDF` header) and contains the table cells / heading text; csv from a table; chart text fallback.
- [ ] Fail, implement, pass. Commit `feat(files): turn an answer into txt, md, csv, docx, xlsx or pdf`.

### Task 8: Files out: export, make_file, download

**Files:** Modify `api-engine/routers/files.py`, `api-engine/routers/chat.py`, `api-engine/builtin_tools.py`; Test `api-engine/tests/test_files_out.py`

**Interfaces:**
- Produces: `POST /api/v1/chat/files/export` `{bot_id, session_id, message_id, format}` → file meta `{id, name, size, url}`; only an assistant message of this session's conversation; format must be allowed; 409 when files out off.
- Produces: `GET /api/v1/chat/files/{id}/download?session_id=&token=` → bytes with `Content-Disposition: attachment`; wrong session/token → 404; expired → 410.
- Produces: `GET /api/v1/chat/files/{id}/admin` (admin token) → bytes, for the portal.
- Chat: the answer id is always made; with files out on, `{"type":"answer","id":...,"formats":[...]}` is sent before `[DONE]`.
- `builtin_tools.for_bot(bot)` adds `make_file(format: enum of allowed formats, title: str)` when files out is on and Agentic is on; description per mode (`on_request`: "Only when the visitor asks for a file…"; `own_initiative`: "…or when a file would serve them better than a chat reply, such as a long table…"). It returns "A <fmt> file titled <title> will be attached to your reply. Write the file's full content as your reply, in Markdown." After the stream, a ran `make_file` makes the file from the answer and sends `{"type":"file", id, name, size, url}` before `[DONE]`.

- [ ] Tests: export of own answer works and of another session's → 404; download wrong token → 404, expired → 410; `make_file` offered only with files out + agentic, format enum = allowed formats; chat with a scripted `make_file` call emits a `file` event and stores an `out` row linked to the answer.
- [ ] Fail, implement, pass. Commit `feat(files): make_file, Download as, and download links`.

### Task 8b: Files that tools return

**Files:** Modify `api-engine/connectors/rest.py` (`CallResult.files`), `api-engine/connectors/mcp.py`, `api-engine/agent.py` (`CallRecord.files`), `api-engine/routers/chat.py`; Test `api-engine/tests/test_files_from_tools.py`

**Interfaces:**
- Produces: `CallResult.files: list[Returned(name, mime, data)] = []`. MCP: `ImageContent` (base64) and `EmbeddedResource` with a blob become `Returned`; text parts stay text. REST: a response whose content type is not JSON/text (an image, a PDF, an octet stream) becomes one `Returned` named from `Content-Disposition` or the tool name, and the text says "returned a file: <name>".
- Chat: for a bot with files out on, each returned file of a ran call is checked with `checks.inspect` against the out formats plus images, stored as `direction="out"` on the answer, and sent as a `file` event. Files out off: dropped as today, with the text line kept.

- [ ] Tests: an in-memory FastMCP tool returning an `Image` yields one `Returned`; a REST tool answering `application/pdf` yields one; the chat stores and announces it only when files out is on.
- [ ] Fail, implement, pass. Commit `feat(files): files returned by tools reach the visitor`.

### Task 9: Visuals

**Files:** Create `widget/visuals.js`, `widget/visuals.test.js`; Modify `widget/markdown.js` (fence hook), `api-engine/main.py` (join `visuals.js` into `/widget.js`, serve `/widget-visuals.js`), `api-engine/routers/chat.py` (visuals prompt), Test `api-engine/tests/test_files_visuals_prompt.py`

**Interfaces:**
- Produces: `window.__ChatbotVisuals.render(lang, source) -> string|null` (an `<svg>` string built only from escaped values, or null to fall back); `sanitizeSvg(source) -> string|null`.
- Data blocks (JSON): `chart {type: "bar"|"line"|"pie", title?, labels[], series[{name, values[]}]}`; `diagram {nodes[{id,label}], links[{from,to,label?}]}` laid out in layers top-down by longest path; `timeline {items[{when,label}]}`.
- SVG allowlist: elements `svg g path rect circle ellipse line polyline polygon text tspan title desc defs linearGradient radialGradient stop marker`; attributes geometry/presentation only (no `style`, no `on*`, no `href`/`xlink:href`); any value with `javascript:`, `<`, `expression(`, or `url(` other than `url(#id)` drops the attribute. Rebuilt from tokens, so nothing unparsed passes through.
- `markdown.js`: a fence whose language is `chart|diagram|timeline|svg` asks the visuals renderer; null → ordinary code block.
- Prompt (visuals on): one paragraph naming the four block kinds with a one-line example each.

- [ ] Tests (node): bar chart SVG has one `<rect>` per value and escaped labels; diagram has a node per id; malicious SVG loses script/handlers/foreignObject/javascript href; invalid JSON → null. Python: prompt present only when visuals on.
- [ ] Fail, implement, pass (`node --test widget/`). Commit `feat(widget): visuals drawn from data blocks and cleaned SVG`.

### Task 10: Widget files UI

**Files:** Modify `widget/widget.js`, `api-engine/routers/bot.py` (`files` in config); Test `api-engine/tests/test_files_config.py`

**Interfaces:**
- Produces: config `files: {in: {enabled, accept: ".pdf,…", max_mb, per_message}, out: {enabled, formats}}` (all off for a bot without settings).
- Widget: paperclip button before the textarea only when `in.enabled`; choosing files uploads each at once and shows a chip (name, size, ✓ / error text); send allowed with chips and no text; `attachments` sent with the message; the user bubble shows the chips. On `file` event: download chip under the answer. On `answer` event: a small "Download as" row with the bot's formats; clicking one calls export and turns into a download chip.

- [ ] Test: config `files` off by default and reflects settings. Widget behaviour verified in Task 12's browser run.
- [ ] Implement. Commit `feat(widget): attach files and download answers as files`.

### Task 11: Portal: settings card and transcript

**Files:** Modify `admin-laravel/app/Http/Controllers/BotBrainController.php`, `admin-laravel/resources/views/bots/brain.blade.php`, `admin-laravel/app/Models/ChatMessage.php` (+ `ChatFile` model), `admin-laravel/app/Http/Controllers/LogController.php`, `admin-laravel/resources/views/logs/_transcript.blade.php`, `admin-laravel/routes/web.php`, `admin-laravel/app/Services/EngineClient.php`; Test `admin-laravel/tests/Feature/FileSettingsTest.php`

**Interfaces:**
- Form fields under marker `files_present`: `files_in_enabled`, `files_in_types[]`, `files_max_mb`, `files_per_message`, `files_daily`, `files_out_enabled`, `files_out_formats[]`, `files_out_mode`, `visuals_enabled`, `files_retention_days`, validated to the Global Constraints ranges and saved as `file_settings`.
- Transcript: each message carries `files[] {id, name, size, direction, scan_status}`; chips link to `GET /logs/files/{id}` which checks `canManageSystem(bot system, viewer)` and streams the engine's `/api/v1/chat/files/{id}/admin`.

- [ ] Tests: editor saves settings; viewer gets 403; bad type ignored/refused; old form without marker leaves settings unchanged; transcript download refused for another workspace.
- [ ] Fail, implement, pass (`php artisan test --filter=FileSettings`). Commit `feat(admin): files and visuals settings, files in transcripts`.

### Task 12: End to end on this PC

**Files:** Create `chatbot-management-agent-test/c4-e2e/step8_files.py` (outside the repo, local only); Modify the spec's status and the design doc's "Progress".

- [ ] Pull-ready check: `ollama list` shows `qwen2.5vl:7b`; set the vision role to it (Local Ollama provider) in engine settings.
- [ ] Script: switch files in/out/visuals on for the test bot with qwen3:8b; upload a PDF and ask about it; upload a photo with text and ask about it; export an answer as docx/xlsx/pdf and open each; ask for a table "as Excel" (make_file path); ask for a flowchart (visual block drawn in the widget, screenshot); a renamed .exe refused; download with a wrong session refused.
- [ ] Full suites: `pytest -q` in api-engine, `php artisan test`, `node --test widget/`.
- [ ] Commit `docs: files in and out built`.
