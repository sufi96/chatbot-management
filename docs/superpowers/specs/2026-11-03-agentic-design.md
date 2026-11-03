# Agentic C⁴: Connectors, Tools and Skills

Status: **planning**, not built. Started 2026-11-03. Pick up from "Open questions".

## Why

C⁴ bots answer questions today (knowledge base, database, web). The next step is bots
that **do things**: look up a record in another agency's system, submit a request, open
a ticket. The aim is to be ready *before* the DGX Sparks arrive, so that going live on
them is a provider switch, not a rebuild. Nothing is serving real visitors yet, so the
design can change freely.

## What exists today (what this builds on)

- Retrieval is current: hybrid BM25 + vectors, RRF, rerank, HyDE/multi-query,
  contextual chunks, answer cache, grounding check, injection shield, leak guard.
- Sources are picked by a fixed order or "combined". **No model chooses tools.**
- `api-engine/llm_adapter.py` speaks OpenAI-compatible `/v1/chat/completions` but sends
  no `tools`. Ollama, vLLM, OpenAI and most gateways accept the same `tools` format, so
  tool calling can be added there once.
- Provider rows already declare the jobs they serve (`app/Support/ModelPurpose.php`).
  Testing on a hosted model now and switching to the Sparks model later is a provider change.
- Per-workspace secrets already have a pattern: `WebSearchKey` (encrypted, never shown
  again, test button, cannot delete while in use). Connector credentials should copy it.

## Decisions so far

### 1. C⁴ stays standalone; it consumes APIs, it does not build them

C⁴ connects *out* to the organisation's systems. Creating APIs for legacy systems is
**not** C⁴'s job: the organisation's MyGDX UXP can connect to a legacy database and
publish it as an API. C⁴ only needs to call APIs well.

- Legacy system → API made in **UXP** → published through MyGDX → C⁴ calls it.
- New systems → built API-first (OpenAPI spec, ideally an MCP server too).
- The bot never writes straight into a legacy database. Writes always go through an API
  that enforces the business rules. The existing database source stays read-only SQL.

### 2. Connector layer (admin chooses what to connect)

The admin adds connectors per workspace. Three types:

| Type | For | Notes |
|---|---|---|
| **MyGDX (X-Road/UXP)** | services on the exchange: the organisation's own (incl. UXP-wrapped legacy) and other agencies' | C⁴ calls the org's **Security Server (SS)**; the SS handles certificates, signing and the audit log |
| **MCP server** | new systems, third-party tools | C⁴ is the MCP **client** |
| **REST / OpenAPI** | any plain HTTP API with a spec | import the spec, pick operations |

Flow: add a connector → test it → import its services (MyGDX SS can usually list
services and serve their OpenAPI descriptions; MCP lists its tools; OpenAPI from the
spec) → tick which become **tools** → enable tools per bot.

*To verify with the MyGDX team:* SS address reachable from C⁴, the org's client
identifier (instance/class/member/subsystem), which services the org's subsystem is
allowed to call, and whether the SS exposes service listing / OpenAPI to us.

### 3. Skills (dynamic, from four sources)

A skill is packaged know-how for one task: when to use it, which tools, in what order,
what to confirm with the visitor. The format is the open Agent Skills format (`SKILL.md`
with name/description front matter, optional extra files), so skills written elsewhere
work as-is. Skills come from:

1. **Upload**: a `SKILL.md` file or a zipped folder.
2. **Type**: an editor in the portal.
3. **Generate**: the workspace's own model drafts a skill from a description; the admin edits and saves.
4. **Pre-built**: a few shipped with C⁴ (first candidates: hand over to a human, create ticket, check application status).

Only each skill's name and description sit in the prompt; the body is loaded when the
model picks it. A skill declares the tools it needs and is available to a bot only when
those tools are enabled for it. Uploaded and generated skills are **data, screened by the
injection shield**, not trusted instructions.

### 4. MCP both ways

- **C⁴ as MCP client** (section 2): bots call other systems' tools.
- **C⁴ as MCP server** (optional, later): other systems' AI assistants use C⁴'s
  knowledge. The engine exposes per-workspace tools such as `search_knowledge_base`
  (retrieval only, same as the playground) and `ask_bot` (a full answer with
  citations), over MCP's streamable HTTP transport, with a per-workspace key copied
  from the `WebSearchKey` pattern. The same shield, guard and logging apply. Build it
  only when a named system wants to be the client.

### 5. SharePoint on-prem as a knowledge-base source (not agentic; can be built now)

This is ingestion, not a tool: SharePoint files flow into the existing indexer and are
answered by normal RAG. It does not need the agentic work or the Sparks.

- New knowledge-base source type **SharePoint**: site URL + document library (+ optional
  folder), a service account, a sync schedule.
- Sync: list files through the on-prem REST API (`/_api/web/...`; Microsoft Graph is
  SharePoint Online only), download new or changed files by modified date, hand them to
  the existing indexer (markitdown already reads docx/pdf/pptx/xlsx), and delete chunks
  for files removed from the library.
- Citations link back to the file in SharePoint.
- **Permissions:** a public bot sees everything the service account can read. Point it
  at libraries meant for the bot, not at whole sites. No per-visitor permission trimming.
- *To collect:* SharePoint version (2016 / 2019 / Subscription Edition), authentication
  (NTLM, Kerberos, or ADFS/claims), a read-only service account, which sites/libraries,
  network path from C⁴, rough file count and how often files change. Ask the vendor
  what exactly they proposed (their connector, or C⁴ reading SharePoint directly).

## Guardrails (my calls, to confirm)

- Any tool that **writes or changes** something asks the visitor to confirm first, with
  a summary of what will be sent.
- Every tool call is logged with the message (tool, arguments, result status, time) and
  shown in the transcript and analytics, next to the existing flags.
- Per-bot limits: maximum tool calls per message, timeout per call.
- Tool results are wrapped and marked as data, like retrieved passages today.
- Everything agentic is off by default; an existing bot answers exactly as before.

## Model

- Tool choice needs a stronger model than qwen3.5-4b. Build and test now on a hosted
  model through an existing provider row; switch to the Sparks model when it arrives.
- Add a provider purpose / model role for tool use only if testing shows the chat
  model and the tool model need to differ. Default: the bot's own model.
- Run the existing evals plus new tool-choice cases on each model before switching.

## Open questions (start here next session)

1. ~~Whose data?~~ **Decided 2026-11-03:** the bot does **not** look up the visitor's own
   records through MyGDX. No visitor login / MyDigital ID needed.
2. MyGDX details: the SS and client ID already exist in the org; the user is collecting
   them. Checklist to bring back:
   - Security Server address C⁴ calls (host, port) and the firewall path from C⁴ to it.
   - How the SS accepts its clients: HTTP, HTTPS, or HTTPS with a client certificate
     (then C⁴'s certificate must be registered on the SS).
   - C⁴'s client identifier: instance / member class / member code / subsystem code.
     Ask whether C⁴ should get its own subsystem (preferred: its own audit trail).
   - For each service the bot will use: full service ID (instance / class / member /
     subsystem / service code / version), REST or SOAP, and its OpenAPI or WSDL.
   - Access rights: the service owner must grant C⁴'s subsystem access to each service.
   - One sample request and response per service, for testing.
   - Whether UXP's REST calling convention matches X-Road's (`/r1/{service id}` plus an
     `X-Road-Client` header) or differs.
3. ~~Bot setting~~ **Decided 2026-11-03:** no "normal vs agentic" bot type. An
   **Agentic** section is added inside the bot profile / Behaviour (brain) page, off by
   default; on, it shows the bot's tools and skills. The bot keeps its knowledge base,
   database and web sources either way.
3a. SharePoint details (see section 5's "to collect" list), pending from the org/vendor.
3b. MCP server: which system, if any, would be the client?
4. Which real actions first? Pick 2–3 that visitors actually ask for.
5. Who may add connectors and skills: workspace system admins (like web search keys) or
   Super Admin only?

## Main categories of work

C⁴ today has **no general connection to outside APIs**; its outside calls are fixed
per job (AI providers, web search, the database source, voice servers). Everything
below rests on category 1.

| # | Category | What it is | Blocked by |
|---|---|---|---|
| 1 | **Connector layer** | Admin adds an outside API: address, auth (API key, basic, NTLM, client certificate), Test button, encrypted credentials (`WebSearchKey` pattern). Types: REST/OpenAPI, MyGDX (via SS), MCP server, SharePoint (a REST API). | REST type: nothing. MyGDX/SharePoint types: their details |
| 2 | **SharePoint knowledge source** | Sync a library's files through a connector into the existing indexer. Plain RAG, not agentic. | SharePoint details |
| 3 | **Agentic core** | Tool calling in `llm_adapter.py`, agent loop in `routers/chat.py`, Agentic section per bot choosing which connector services it may use. | Nothing (test on a hosted model) |
| 4 | **Skills** | Upload / type / generate / pre-built, `SKILL.md` format, screened by the shield. | Nothing for storage and editor; pre-built ones need real actions |
| 5 | **Action safety and visibility** | Visitor confirms writes, per-message limits and timeouts, every tool call logged in transcript and analytics. | Ships with 3 |
| 6 | **C⁴ as MCP server** (later) | Other systems' AI uses C⁴'s knowledge via `search_knowledge_base` / `ask_bot`. | A named client system |
| 7 | **Evals and the Sparks switch** | Tool-choice evals on a hosted model, then switch provider. | The Sparks |

Order: 1 → 2 (early value, no agentic needed) → 3 + 4 + 5 together → 6 and 7.

**Start now (no outside dependency):** category 1 with the **REST/OpenAPI type only**,
tested against a public or mock API. MyGDX and SharePoint then become new connector
types on the same base once their details arrive.
