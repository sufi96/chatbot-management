# Agentic Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A bot with its Agentic switch on can call the connector tools enabled for it, read results into its answer, and ask the visitor to confirm before any tool that writes; every call is logged.

**Architecture:** Tools become a fourth source, `tools`, in the existing cascade (`sources/`), so order, combine, citations, status lines, guard, leak watch and grounding all apply unchanged. A new `agent.py` runs a short non-streamed tool-calling loop; the final answer still streams through the existing path. A write tool is never run inside the loop: it is saved as an `awaiting_confirm` row in `chat_tool_calls`, the widget shows Confirm / Cancel, and a separate endpoint runs it.

**Tech Stack:** FastAPI, SQLAlchemy async, httpx, pytest; Laravel 13 / PHPUnit; widget vanilla JS (`node --test`).

**Spec:** `docs/superpowers/specs/2026-11-03-agentic-design.md` (sections 2, Guardrails, Model; categories 3 and 5)

**Depends on:** `docs/superpowers/plans/2026-11-03-connector-layer-rest.md` (tables, `connectors.rest.call`).

## Global Constraints

- Off by default: `agentic_enabled = false`; a bot with it off answers exactly as before (no new prompt text, no new events).
- No model routes between sources; `tools` is a place in the operator's source order. Normalising appends `tools` last for saved orders; the new default order is `documents,database,tools,web`. With combine on, `tools` is asked together with documents and database.
- Max tool calls per message: per bot, 1–5, default 3. Each call 15 s (from `connectors.rest`).
- Writes always need the visitor's confirmation. A confirmation expires after 10 minutes and only the same bot + session may confirm it.
- Tool results are shield-screened (`shield.detect`) and wrapped with `spotlight.wrap`, like web results.
- Model: the bot's own model (via `provider_endpoint(bot)`); no new model role.
- Test on a hosted provider now; the Sparks switch is a provider change.

## Review Focus

- A provider that rejects the `tools` key (old Ollama, strict gateway) → the tools source is a miss and the cascade moves on; the chat never breaks.
- The model names a tool that is not enabled for this bot, or sends arguments that are not JSON → the call is refused with an error result fed back to the model, logged as `failed`, never sent to the connector.
- A confirm request with another session's action id, an expired id, or an id already confirmed → 404/409, nothing runs.
- A tool result reading "ignore your instructions…" → withheld from the prompt and the call flagged `injection` in the log.
- Two connectors with a tool of the same name enabled on one bot → refused at save (422) on the Behaviour tab.

---

### Task 1: Tables, bot switch and source order

**Files:**
- Create: `admin-laravel/database/migrations/2026_11_03_000002_add_agentic_to_bot_profiles.php`
- Modify: `admin-laravel/app/Models/BotProfile.php` (fillable + casts, `tools(): BelongsToMany` via `bot_connector_tools`), `admin-laravel/app/Support/SourceOrder.php`, `api-engine/sources/order.py`, `api-engine/database.py`
- Test: `admin-laravel/tests/Feature/AgenticSettingsTest.php`, `api-engine/tests/test_source_order.py`

**Interfaces:**
- Produces: `bot_profiles.agentic_enabled bool default false`, `bot_profiles.agentic_max_calls tinyint default 3`; `bot_connector_tools(bot_id fk cascade, connector_tool_id fk cascade, primary(bot_id, connector_tool_id))`; `chat_tool_calls(id string36 pk, conversation_id fk cascade, message_id string36 nullable, connector_tool_id string36 nullable fk nullOnDelete, tool_name string64, arguments json, status string20, flag string20 nullable, result_excerpt text nullable, duration_ms int nullable, created_at, updated_at)`. `status` ∈ `ran, failed, awaiting_confirm, confirmed, declined, expired`.
- `SourceOrder::SOURCES = ['documents','database','tools','web']`, `DEFAULT = 'documents,database,tools,web'`; Python `SOURCES`/`DEFAULT` identical.
- Engine SQLAlchemy: `BotProfile.agentic_enabled`, `BotProfile.agentic_max_calls`, `BotConnectorTool`, `ChatToolCall`.

- [ ] **Step 1: Write the failing tests:** `test_source_order.py::test_tools_is_appended_to_an_old_order` (`normalise("documents,database,web") == ["documents","database","web","tools"]`) and the same in PHP; `AgenticSettingsTest::test_a_new_bot_is_not_agentic`.
- [ ] **Step 2: Run** `pytest -q tests/test_source_order.py` and `php artisan test --filter=AgenticSettingsTest` — Expected: FAIL.
- [ ] **Step 3: Implement** migration, models, both `SourceOrder`s.
- [ ] **Step 4: Run** both full suites — Expected: PASS (fix any test that hard-codes the old three-source default).
- [ ] **Step 5: Commit** `feat(agentic): agentic switch, tool links and call log tables`

### Task 2: Tool calling in the adapter

**Files:**
- Modify: `api-engine/llm_adapter.py`
- Test: `api-engine/tests/test_llm_tools.py`

**Interfaces:**
- Produces: `LLMAdapter.complete_with_tools(base_url, api_key, model_name, messages: list[dict], tools: list[dict], temperature: float = 0.0, max_tokens: int = 512, transport=None) -> dict | None` — POSTs `{"model","messages","tools","tool_choice":"auto","stream":false,"temperature","max_tokens","chat_template_kwargs":{"enable_thinking":false}}`; drops `chat_template_kwargs` and retries on a 400 naming it (as `complete` does); returns `choices[0].message` (dict with `content` and maybe `tool_calls`), or `None` on any failure, including a 400 that names `tools`.

- [ ] **Step 1: Write the failing tests** with `httpx.MockTransport`: returns the message dict including `tool_calls`; returns `None` on a 400 whose body mentions `tools`; retries without `chat_template_kwargs` on a 400 naming it.
- [ ] **Step 2: Run** `pytest -q tests/test_llm_tools.py` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat(agentic): tool-calling completion in the adapter`

### Task 3: The agent loop

**Files:**
- Create: `api-engine/agent.py`
- Test: `api-engine/tests/test_agent.py`

**Interfaces:**
- Consumes: `connectors.rest.call`, `CallResult`; `LLMAdapter.complete_with_tools`; `shield.detect`; `spotlight.wrap`.
- Produces:
  - `@dataclass Tool(id: str, name: str, description: str, parameters: dict, writes: bool, connector: object, row: object)`; `def schema(tool) -> dict` → OpenAI `{"type":"function","function":{"name","description","parameters"}}` (parameters with `x-in` keys stripped).
  - `@dataclass CallRecord(tool: Tool | None, name: str, arguments: dict, status: str, flag: str | None = None, excerpt: str = "", ms: int = 0)`.
  - `@dataclass AgentResult(calls: list[CallRecord], context_block: str = "", pending: CallRecord | None = None, model: str = "")`.
  - `async def tools_for(session, bot) -> list[Tool]` — enabled tools linked to the bot, whose connector is in the bot's workspace.
  - `async def run(bot, message: str, tools: list[Tool], endpoint, ask=None, call=None, extra_system: str = "") -> AgentResult` — `endpoint` is `(base_url, api_key, bot.model_name)` with the first two from `database.provider_endpoint(bot)`; `extra_system` is where skills hook in later (the skills plan adds a `skills` parameter).
- Loop: system prompt = "You can call tools to look things up for the visitor. Call a tool only when the question needs it. Never invent arguments; if one is missing, call no tool." + `extra_system`; user = message. Up to `bot.agentic_max_calls` rounds: `reply = await ask(...)`; `None` or no `tool_calls` → stop. For each tool call: unknown name or bad JSON → record `failed`, feed back `{"error": "..."}`; `tool.writes` → record `awaiting_confirm`, set `pending`, stop the loop; else `call(...)`: result text with `shield.detect(...).found` → record `ran` + `flag="injection"`, feed back `"[withheld]"`; otherwise feed back the text. `context_block` = `spotlight.wrap("Results from the systems this bot is connected to:", …)` of each `ran` call as `"[n] {connector name}: {tool name}\n{text}"`, empty when none ran.

- [ ] **Step 1: Write the failing tests** with fake `ask` / `call` (no network):
  - `test_no_tool_call_is_a_miss` → `calls == []`, `context_block == ""`.
  - `test_a_read_tool_runs_and_its_result_is_wrapped` → one `ran` record, block contains `<context>` and the result text.
  - `test_a_write_tool_waits_for_confirmation` → `call` never invoked, `pending.status == "awaiting_confirm"`.
  - `test_an_unknown_tool_is_refused_and_not_called`.
  - `test_calls_stop_at_the_bots_limit` (`agentic_max_calls=2`, model always asks again → `ask` called 2 times).
  - `test_an_instruction_shaped_result_is_withheld` → flag `injection`, block lacks the text.
  - `test_a_provider_without_tools_is_a_miss` (`ask` returns `None`).
- [ ] **Step 2: Run** `pytest -q tests/test_agent.py` — Expected: FAIL.
- [ ] **Step 3: Implement** `agent.py`.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat(agentic): bounded tool-calling loop`

### Task 4: Tools as a source, and the chat route

**Files:**
- Modify: `api-engine/sources/result.py` (`tool_calls: list = field(default_factory=list)`, `pending: object = None`), `api-engine/sources/__init__.py` (`enabled_for` adds `"tools": bool(getattr(bot, "agentic_enabled", False))`; `build_attempts` adds `"tools"`; `COMBINED = ("documents", "database", "tools")`; `merge` carries `tool_calls` and `pending`), `api-engine/sources/attempts.py` (`async def tools(session, bot, message, settings, run=None, load=None) -> SourceResult`, kind `"tools"`, `has_content` when any call `ran` or `pending`, citations one per `ran` call titled `"{connector}: {tool}"`), `api-engine/routers/chat.py`
- Test: `api-engine/tests/test_source_attempts.py`, `api-engine/tests/test_chat_tools.py`

**Interfaces:**
- Chat route: `STATUS_TEXT["tools"] = "Working with connected systems..."`. After the cascade, write one `ChatToolCall` per record (`message_id` filled in `save_assistant_message`'s caller once the assistant row exists — pass the ids through `answer_fields`-style kwargs). When `found.pending`: emit `data: {"type":"confirm","action_id":…,"tool":…,"summary":…}` before `status("writing")`, where `summary` is `"{tool description or name}: {arguments as key=value, …}"` cut to 300 chars; append to the prompt: `"An action is waiting for the visitor to confirm: {summary}. Tell them what will happen and ask them to press Confirm. Do not say it has been done."`.
- `answer_kind` returns `"tools"` naturally via `found.kind`.

- [ ] **Step 1: Write the failing tests:** attempt-level (`tools` attempt maps an `AgentResult` to `SourceResult` correctly; empty → `has_content is False`) and route-level in `test_chat_tools.py`, following `test_chat_route.py`'s fixture style with the agent `run` monkeypatched: an agentic bot whose run returns a pending write → the stream contains a `confirm` event before the answer and a `chat_tool_calls` row with `awaiting_confirm`; a bot with `agentic_enabled=False` → no `confirm` event, `run` never called.
- [ ] **Step 2: Run** `pytest -q tests/test_source_attempts.py tests/test_chat_tools.py` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `pytest -q` (whole suite) — Expected: PASS.
- [ ] **Step 5: Commit** `feat(agentic): tools as a source in the cascade`

### Task 5: Confirm endpoint and widget

**Files:**
- Modify: `api-engine/routers/chat.py` (route `POST /api/v1/chat/actions/{action_id}`), `widget/widget.js`
- Create: `widget/confirm.test.js`
- Test: `api-engine/tests/test_chat_tools.py`

**Interfaces:**
- Request `{bot_id, session_id, decision: "confirm" | "decline"}`; same `check_origin` as `chat_stream`. The row must belong to a conversation with that `bot_id` + `session_id`, be `awaiting_confirm` and younger than 10 minutes (older → mark `expired`, 409). `decline` → `declined`, text `"Cancelled. Nothing was sent."`. `confirm` → `connectors.rest.call(...)`, status `confirmed` or `failed`, text `"Done."` / `"That didn't go through: {error}"`. Either way save an assistant `ChatMessage` with the text and return `{"status": …, "text": …}`.
- Widget: on `parsed.type === "confirm"` (next to the `status`/`sources` branches near `widget/widget.js:2667`) render a card under the answer with the summary and Confirm / Cancel buttons; a click disables both, POSTs the decision, and shows the returned `text` as a bot line. Pure helper `confirmSummaryHtml(event)` exported for the test, escaping the summary.

- [ ] **Step 1: Write the failing tests:** engine — confirm runs the call once and a second confirm → 409; another session's id → 404; an 11-minute-old row → 409 and `expired`; decline never calls. Widget — `confirmSummaryHtml({summary: "<b>x</b>"})` escapes the tags.
- [ ] **Step 2: Run** `pytest -q tests/test_chat_tools.py` and `node --test widget/confirm.test.js` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** both — Expected: PASS.
- [ ] **Step 5: Commit** `feat(agentic): visitor confirms an action before it runs`

### Task 6: Agentic card, transcript and analytics

**Files:**
- Modify: `admin-laravel/app/Http/Controllers/BotBrainController.php` (`edit` passes the workspace's connectors with enabled tools; `update` validates and syncs), `admin-laravel/resources/views/bots/brain.blade.php` (new folding card "Agentic", `data-card-collapse="agentic"`, placed after Web search, same markup as the other cards), `admin-laravel/resources/views/logs/_transcript.blade.php` (list each `chat_tool_calls` row under its assistant message: tool, status badge, arguments, excerpt), `admin-laravel/app/Services/Analytics.php` (source label `'tools' => 'Connected systems'`, counted in `$searched`), `admin-laravel/app/Http/Controllers/ConnectorController.php` (`destroy` refuses with 409 naming the bots when any of its tools is linked to a bot)
- Test: `admin-laravel/tests/Feature/AgenticSettingsTest.php`

**Interfaces:**
- Validation: `agentic_enabled` boolean; `agentic_max_calls` integer 1–5; `tools` array of `connector_tools.id` that are `enabled` and whose connector's `system_id` equals the bot's; names unique within the set (422 `"Two tools are both called {name}. Enable only one."`). Card: switch, max-calls select, tools checklist grouped by connector with a **Writes** badge, a link to the Connectors page when the workspace has none.

- [ ] **Step 1: Write the failing tests:** saves switch, limit and tools; refuses another workspace's tool (422); refuses a disabled tool (422); refuses duplicate names (422); deleting a connector whose tool is linked → 409 naming the bot; analytics counts a `tools` answer.
- [ ] **Step 2: Run** `php artisan test --filter=AgenticSettingsTest` — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `php artisan test` — Expected: all PASS. Then, with a hosted model on the bot's provider and the petstore connector from the connector plan, ask "Is pet 1 available?" in the widget: the status line shows "Working with connected systems...", the answer cites the tool, the transcript lists the call; enable a POST tool and check the Confirm card appears and nothing is sent until Confirm.
- [ ] **Step 5: Commit** `feat(agentic): Agentic card, tool calls in transcripts and analytics`
