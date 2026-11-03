# Agentic Extras Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The three picks from the agent prototype (built-in tools, live tool trace in the test chat, always-on skills) and FastMCP both ways (MCP servers as connectors; C⁴ as an MCP server).

**Architecture:** Builds on `feat/agentic`. Built-in tools are `agent.Tool`s with a local runner instead of a connector. The trace is an SSE event the engine sends only when the request carries a short-lived HMAC token the portal minted for its own test chat. Always-on skills are a `mode` column read by the chat route. FastMCP 4.1.4: `connectors/mcp.py` is a second caller beside `rest.py` behind one dispatcher; `mcp_server.py` is a FastMCP app mounted at `/mcp`, authorised by per-workspace MCP keys.

**Tech Stack:** FastAPI, FastMCP 4.1.4 (pinned), SQLAlchemy async, pytest; Laravel 13, PHPUnit; widget JS.

**Spec:** `docs/superpowers/specs/2026-11-03-agentic-design.md` ("Ideas from the agent prototype", "FastMCP")

## Global Constraints

- Everything new is off until switched on: no built-in tool on a bot by default; skills default to `progressive`; no MCP key exists until an admin makes one.
- Names reserved for the engine: `load_skill`, `current_datetime`, `request_human`. A connector tool with one of those names cannot be enabled on a bot (422).
- `current_datetime` answers in `Asia/Kuala_Lumpur`.
- The trace never reaches a public visitor: only a request with a valid, unexpired (15 min) token for that bot gets it.
- MCP keys: shown once, stored as SHA-256, last four kept as a hint; every MCP tool is limited to the key's workspace and never serves the console (platform) bot.
- MCP connector tools: the MCP tool name is kept in `path`; `writes` = not `read_only_hint`.

## Review Focus

- A forged or expired trace token, or one minted for another bot → no trace events.
- An MCP key from workspace A naming a bot of workspace B → refused, nothing answered.
- An MCP server that is down or returns `is_error` → a sentence, never an exception in the chat.
- An always-on skill whose tools the bot lacks → not applied.
- A connector tool named `request_human` → refused on the Behaviour tab.

---

### Task 1: Built-in tools

**Files:** `admin-laravel/database/migrations/2026_11_03_000004_add_builtins_and_handover.php`; `api-engine/builtin_tools.py`; `api-engine/database.py`; `api-engine/agent.py`; `api-engine/sources/attempts.py`; `api-engine/routers/chat.py`; `BotBrainController.php`; `bots/brain.blade.php`; `logs` list view; tests `api-engine/tests/test_builtin_tools.py`, `tests/test_chat_tools.py`, `AgenticSettingsTest.php`.

**Interfaces:**
- `bot_profiles.builtin_tools json nullable`; `chat_conversations.handover_requested_at timestamp nullable`, `handover_reason string(500) nullable`.
- `builtin_tools.NAMES = ("current_datetime", "request_human")`; `builtin_tools.for_bot(bot) -> list[agent.Tool]` (Tool with `connector=SimpleNamespace(name="C⁴")`, `row=None`, `local=callable`); `agent.Tool.local: object = None` — when set, `run` awaits `tool.local(arguments)` instead of `call`.
- `request_human` (writes=False) returns "A member of staff has been asked to follow up." — the chat route, logging a `ran` `request_human`, sets the conversation's handover columns (reason = argument `reason`, cut to 500).
- Brain: "Built-in" checkboxes in the Agentic card; `builtin_tools` validated `in:current_datetime,request_human`; reserved names refused for connector tools.
- Conversations list: a "Needs a person" badge on conversations with `handover_requested_at`.

- [ ] Tests first (engine: datetime is KL time ISO; request_human runs locally without the connector call; route marks the conversation; portal: saves builtins, refuses unknown builtin, refuses a connector tool named `request_human`, badge shows) → RED → implement → GREEN → commit `feat(agentic): built-in tools: current date and time, hand over to a person`.

### Task 2: Live trace in the Behaviour page's test chat

**Files:** `api-engine/trace.py`; `api-engine/routers/chat.py`; `widget/widget.js`; `BotBrainController.php` + brain view (token on the embedded widget); tests `api-engine/tests/test_trace.py`, `tests/test_chat_tools.py`, `AgenticSettingsTest.php`.

**Interfaces:**
- `trace.token(bot_id: str, secret: str, now: int) -> str` = `f"{expires}.{hmac_sha256(secret, f'{bot_id}|{expires}')}"`, expires = now + 900; `trace.valid(token, bot_id, secret, now) -> bool` (constant-time compare). PHP mints the same with `hash_hmac('sha256', "$botId|$expires", ENGINE_ADMIN_TOKEN)`.
- `ChatStreamRequest.trace_token: Optional[str]`. When valid: after the cascade, one `{"type":"trace","text":…}` event per tool call (`"{tool}({k=v, …}) → {status}"`) and per loaded skill (`"Loaded skill {name}"`); `AgentResult.loaded_skills: list[str]`.
- Widget reads `data-trace-token` from its script tag, sends it, and draws trace lines in small muted text under the answer.

- [ ] Tests first (token round trip; wrong bot / expired / tampered → invalid; route emits trace only with a valid token; brain page carries `data-trace-token`) → RED → implement → GREEN → commit `feat(agentic): live tool trace in the Behaviour page's test chat`.

### Task 3: Always-on skills

**Files:** migration `2026_11_03_000005_add_mode_to_skills.php`; `Skill.php`; `SkillController.php` (`PUT /skills/{id}/mode`); skills view; `api-engine/database.py`; `api-engine/agent.py`; `api-engine/routers/chat.py`; tests.

**Interfaces:**
- `skills.mode string(20) default 'progressive'` (`progressive|always`).
- `agent.skills_for(..., mode="progressive")`; chat route, for an agentic bot, appends every `always` skill the bot has the tools for under "Always follow these rules from the operator:" on every answer, greetings included.

- [ ] Tests first → RED → implement → GREEN → commit `feat(skills): always-on skills`.

### Task 4: MCP servers as connectors (C⁴ as MCP client)

**Files:** `api-engine/requirements.txt` (`fastmcp==4.1.4`); `api-engine/connectors/__init__.py` (`async def call(connector, row, arguments, transport=None) -> CallResult` dispatching on `connector.type`); `api-engine/connectors/mcp.py` (`list_tools(base_url, auth_type, auth_header, auth_secret) -> dict`, `call(connector, row, arguments) -> CallResult`, `ping(...) -> dict`); `routers/connectors.py` (`POST /api/v1/connectors/mcp-tools`; `/test` takes `type`); `agent.py` and the confirm route use the dispatcher; Laravel: `Connector::TYPES['mcp']`, import branch, `EngineClient::mcpTools`; tests with an in-memory FastMCP server.

- [ ] Tests first → RED → implement → GREEN → commit `feat(connectors): MCP servers as connectors (FastMCP client)`.

### Task 5: C⁴ as an MCP server

**Files:** migration `2026_11_03_000006_create_mcp_keys.php`; `McpKey.php`; `McpKeyController.php`; Connectors page section "Use C⁴ from other AI tools"; `api-engine/mcp_server.py`; `api-engine/main.py` (mount `/mcp`, combined lifespan); `api-engine/database.py`; tests.

**Interfaces:**
- `mcp_keys(id, system_id fk cascade, name, token_hash char(64) unique, hint string(8), last_used_at nullable, timestamps)`; token `c4mcp_` + 40 random chars, shown once.
- FastMCP tools: `list_bots() -> list[{id,name}]`, `search_knowledge(bot_id, query) -> {passages:[{n,title,text}]}`, `ask_bot(bot_id, question, session_id?) -> {answer, sources, session_id}` (runs the normal chat route in-process, so every guard applies). Each resolves the bearer key → workspace and refuses a bot outside it or the platform bot.

- [ ] Tests first → RED → implement → GREEN → commit `feat(mcp): C⁴ as an MCP server for other AI tools`.
