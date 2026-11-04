# Files In and Out of a Conversation

Status: **design approved** (2026-11-05), not started. Replaces the rough sketch "Files and images in and out"
at the end of `2026-11-03-agentic-design.md`. The admin assistant (a built-in bot for running C⁴
itself) is a separate project, designed after this one.

## Why

C⁴ hosts many kinds of bots for many organisations: public website bots, staff bots, complaint
bots, HR bots. Some of them need what any modern chatbot has: the person attaches a file and asks
about it, and the bot hands a file back ("give me that as Excel"). Today a conversation is text
only, both ways.

This is a **platform capability, off by default**. A bot whose use case has no need for files
(the Aduan Awam test bot, for one) leaves it off and nothing about it changes.

## Goals

1. **Files in:** a person attaches documents or images to a message, and the bot answers about them.
2. **Files out:** the bot produces a downloadable file (text, Markdown, CSV, Word, Excel, PDF), when
   asked, or on its own when the bot's settings allow that.
3. **Per-bot control by editors**, not only admins: with many bots, one admin must not be the
   bottleneck. Anyone with **editor** or above in the bot's workspace
   (`User::canManageSystem($systemId, 'editor')`) switches each direction on and sets its limits.
4. **Safe for anonymous public visitors** as well as logged-in staff: the same feature, with
   different limits per bot.
5. **Storage that can move.** Local disk now; MinIO or S3 later as a settings change, with no code
   change and no effect on the running server beyond where the bytes go.

## Non-goals (this project)

- Image **generation**, including visuals as image files. Waits for the picture model planned on DGX Spark
  node B.
- Organisation templates (letterhead, logo) for generated documents. Plain layout now; template
  upload later.
- More output formats beyond the first set. Later, as more built-in tools.
- Passing a visitor's uploaded file **into** a connector or MCP tool (e.g. "submit this form to
  MyGDX"). Later; it needs its own Confirm and audit design.
- Putting uploads into a knowledge base. An uploaded file belongs to its conversation only.

## Anti-goals (would be a failure even if it works)

- A bot that never had files switched on behaves any differently.
- A public bot can be used to flood the server's disk, or to pass malware on.
- The model invents what a file says when it could not read it (an unreadable file, an image with
  no vision model). It must say plainly that it could not read the file.
- Uploaded text bypasses the prompt-injection shield.
- Moving storage to S3 later means a code change or a migration outage.

## Constraints from what exists

- **Roles:** viewer < editor < system_admin < super_admin per workspace (`admin-laravel/app/Models/User.php`).
- **Reading documents:** `api-engine/kb/extract.py` already turns PDF, Word, PowerPoint, Excel, CSV,
  HTML, Markdown, text, JSON and XML into text with markitdown. Reuse it.
- **Reading images:** the `vision` model role (`api-engine/roles.py`) and `api-engine/kb/vision.py`
  already turn an image or a scanned page into Markdown; a blank role borrows the bot's main model.
- **The widget's chat route is public** (`POST /api/v1/chat/stream`, guarded by the bot's allowed
  origins). Text only: `ChatStreamRequest.message`, `ChatMessage.content`.
- **Tool results are text only:** `connectors/mcp.py` keeps only text content; REST likewise.
- **Built-in tools** live in `api-engine/builtin_tools.py` and are switched on per bot.
- **Shield:** `shield.detect()` screens the visitor's message for injection before the model sees it.
- **Retention and privacy:** Malaysian PDPA applies to what visitors upload.

## Decided

### Switching it on (per bot, editor or above)

Two independent switches on the bot's Behaviour page, each with its settings:

**Files in**
- Allowed types, chosen from: PDF, Word (.docx), Excel (.xlsx, .csv), PowerPoint (.pptx), text and
  Markdown, images (.png, .jpg, .webp).
- Max size per file, max files per message, and a per-visitor daily cap (counts by session and IP).

**Files out**
- Allowed formats, chosen from: .txt, .md, .csv, .docx, .xlsx, .pdf. None ticked means no files out.
- When: **only when the visitor asks**, or **also when the bot judges a file is the better answer**.
  The admin picks per bot.

**Retention**, per bot, default **30 days**: uploaded and generated files are deleted after it.

### Files in: what happens to an upload

1. Held in a temporary area, not yet in storage.
2. **Cheap checks**, always: real type from the file's content (magic bytes), not its extension;
   size and count caps; refuse Office files with macros, PDFs with JavaScript, and zip bombs (Word
   and Excel files are zips); images are decoded and re-saved, which strips hidden payloads and
   EXIF data such as GPS location.
3. **Virus scan with ClamAV** (`clamd`, free and open source, streamed over a local socket) when
   one is configured and reachable.
4. **If ClamAV is not installed or is down, the upload is accepted** after the cheap checks, and
   the file is marked *not scanned*. This is the user's decision.
5. Saved to storage.

Then it is read for the answer:
- **Documents** become text with `kb/extract.py`.
- **Images** become text through the vision role, so a text-only chat model can still answer about
  a photo. If no model can read images, the bot says so plainly instead of guessing.
- Extracted text goes through the shield like any untrusted input before the model sees it.

### Files out

- The model never writes a binary file. It writes what it is good at (Markdown for a document, rows
  for a spreadsheet) and C⁴ converts that into the real format.
- Plain layout. No templates yet.
- The widget shows a produced file as a download chip under the answer; the transcript shows it too.
- A file or image that a connector or MCP tool returns becomes an attachment the same way, instead
  of being dropped as it is today.
- **Two ways to get a file, both built** (the user's decision):
  - **The `make_file` tool**, for bots whose model handles tool calls. The bot produces a file when
    asked, or on its own if the bot's settings allow that.
  - **A "Download as …" button under every answer** of a bot with files out on, offering that
    bot's allowed formats. It converts the answer itself, with no model involved, so it works with
    any model, small local ones included.

### Visuals (drawn by the display, not by an image model)

Charts were only the user's example. Anything visual that can be drawn with SVG is in scope:
charts, flowcharts and process diagrams, org charts, timelines, simple maps of steps, labelled
shapes. Only what truly needs an image model (photos, illustrations) waits for it.

- The model answers with a **visual block**, in one of two forms:
  - **A data block** for the common kinds (chart, flow/diagram of nodes and links, timeline): plain
    data, which C⁴'s own code lays out and draws. Easiest for small models to get right.
  - **An SVG block**, for anything else: SVG the model writes, passed through a strict allowlist
    before drawing (shapes, paths, text, groups, gradients only; no scripts, no event attributes, no
    `foreignObject`, no external links or images). Anything outside the list is removed; an SVG
    that is broken or empty after cleaning is not drawn.
- The **widget and test chat draw it** inline, as something the person can see.
- Where nothing can draw it (a transcript export, a plain-text channel), it falls back to a **text
  drawing** made of keyboard characters, plus the numbers as a table where there are numbers.
- Visuals as image files (PNG) come later with the image model, and can then also be offered as a
  file. An SVG visual may be offered as a `.svg` download sooner, through the same files-out path.

### Storage

One small storage interface with two backends: **local disk** now, **S3-compatible** (MinIO or S3,
one S3 client covers both) later. Which one is a setting.

## The builder's calls (mine, change them if wrong)

- **Uploads land at the engine**, which is where the widget already talks: a separate upload request
  returns a file id, and the chat message carries the ids. Keeps `/chat/stream` a JSON request.
- **`make_file(format, title, content)` is a built-in tool** in `builtin_tools.py`, so the agent loop,
  log and trace treat it like any tool. It is offered only to bots with Agentic on; every bot with
  files out on gets the Download button regardless.
- Visuals are switched on per bot (a "visuals" tick), since many bots have no use for them. A data
  block is validated before drawing, and every label is escaped like any model text
  (`widget/markdown.js` rules); an SVG block goes through the allowlist above.
- The widget draws data blocks with its own small SVG code rather than a charting or diagram
  library, to keep the embed light; the portal may use a library.
- When the bot may offer files unprompted, its tool description says so; when only on request,
  the description says to call it only when the person asks for a file.
- A file not scanned shows a small *not scanned* mark in the transcript, and a later scan pass may
  pick such files up once ClamAV is available.
- The Behaviour page's test chat gets the same attach button as the widget.
- An attached file stays available for the rest of the conversation, so follow-up questions about
  it work without attaching it again.
- A file's text goes to the model whole when it fits the model's budget; when it does not, only the
  passages relevant to the question go, picked the way the knowledge base picks them.
- When the question is about an attached file, the file is read first, ahead of the bot's source
  order (it is the person's own material for this conversation).
- Downloading a file from the transcript is open to whoever may already read that bot's transcripts.
- A visitor's download link is unguessable and works only for that visitor's session.
- Files count against the per-bot retention clock from upload or creation.

## Suggested order

1. Storage interface, retention clean-up, the per-bot settings (both switches) and their permission.
2. Files in: documents (upload, checks, optional ClamAV, extract, shield, answer, transcript).
3. Files in: images (through the vision role).
4. Files out: the Download button, then `make_file` and the download chip.
5. Visuals: data blocks (chart, diagram, timeline), cleaned SVG blocks, the text fallback.
6. Files out from connector and MCP results.

## Left to the builder

Table and column names, the upload endpoint's exact shape, which libraries convert Markdown and
rows to .docx/.xlsx/.pdf, the ClamAV client, the exact default caps, how the daily cap is counted,
and how the widget draws the attach button and chips.
