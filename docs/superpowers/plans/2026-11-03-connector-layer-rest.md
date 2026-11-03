# Connector Layer (REST/OpenAPI) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A workspace's system admins can add an outside REST API, test it, import its operations from an OpenAPI spec, and choose which operations become tools; the engine can call those tools.

**Architecture:** Laravel owns two new tables (`connectors`, `connector_tools`) and a Connectors page per workspace, copying the `WebSearchKey` pattern (JSON controller, secret hidden, test through the engine, refuse delete while in use). The engine gets read-only SQLAlchemy models for both tables and `connectors/rest.py`, which performs one tool call. Nothing here is wired into chat yet; that is the agentic-core plan.

**Tech Stack:** Laravel 13 / PHPUnit; FastAPI, SQLAlchemy async, httpx, pytest (asyncio_mode=auto).

**Spec:** `docs/superpowers/specs/2026-11-03-agentic-design.md` (sections 1, 2; category 1)

## Global Constraints

- C⁴ consumes APIs; it never builds them. Legacy APIs come from UXP.
- Only the REST/OpenAPI type is built now. `type` column exists so `mygdx`, `mcp`, `sharepoint` can be added later; the form offers `rest` only.
- Auth types now: `none`, `bearer`, `header` (custom header name + value), `basic` (`user:password`). NTLM and client certificates come with the SharePoint/MyGDX types.
- Secrets follow `WebSearchKey`: in `$hidden`, never returned, blank on update means keep, tested by id on the server.
- Add / change / delete / test: workspace `system_admin` only (`canManageSystem($systemId, 'system_admin')`).
- OpenAPI import: JSON only (OpenAPI 3.x). YAML is not supported: `symfony/yaml` is not installed; say so in the error.
- Tool names must match `^[A-Za-z0-9_-]{1,64}$` (OpenAI tool-name rule) and be unique within a connector.
- Engine call limits: 15 s timeout, response text cut to 8,000 characters.
- Every new behaviour is off until an admin adds a connector; existing bots unchanged.

## Review Focus

- A path argument containing `/`, `..`, `?` or `#` must not change which URL is called: it is percent-encoded, and `..` is refused.
- An OpenAPI spec with no `operationId`, duplicate `operationId`s, or names with spaces still imports: names are slugged from method+path and de-duplicated with `_2`, `_3`.
- An unreachable host, a TLS error, a 401 and a non-JSON 200 each come back from Test as a plain sentence, never a 500.
- A connector whose tools are enabled on a bot cannot be deleted (409, names the bots) — this guard lands in the agentic-core plan when the pivot exists; here deletion cascades to its tools.
- A huge response (e.g. 5 MB JSON) is cut to 8,000 characters, not loaded into a prompt whole.

---

### Task 1: Tables and models

**Files:**
- Create: `admin-laravel/database/migrations/2026_11_03_000001_create_connectors.php`
- Create: `admin-laravel/app/Models/Connector.php`, `admin-laravel/app/Models/ConnectorTool.php`
- Modify: `api-engine/database.py` (add `Connector`, `ConnectorTool` after `WebSearchKey`, docstring "Laravel owns the table.")
- Test: `admin-laravel/tests/Feature/ConnectorTest.php`

**Interfaces:**
- Produces: `connectors(id string36 pk, system_id fk systems cascade, name string120, type string20 default 'rest', base_url string500, auth_type string20 default 'none', auth_header string120 nullable, auth_secret text nullable, timestamps)`; `connector_tools(id string36 pk, connector_id fk cascade, name string64, description text nullable, method string10, path string500, parameters json nullable, writes bool, enabled bool default false, timestamps; unique(connector_id, name))`.
- `Connector::TYPES = ['rest' => 'REST / OpenAPI']`, `Connector::AUTH_TYPES = ['none','bearer','header','basic']`, `$hidden = ['auth_secret']`, `tools(): HasMany`, `forPage(): array` (id, name, type, base_url, auth_type, auth_header, `hint` = last four of secret like `WebSearchKey::forPicker`, tools count).
- `ConnectorTool` casts `parameters => array`, `writes => boolean`, `enabled => boolean`. IDs: `con_` / `ctl_` + `Str::random(12)`.
- Engine: `Connector`, `ConnectorTool` SQLAlchemy classes with the same columns (`parameters` as `JSON`).

- [ ] **Step 1: Write the failing test** `test_a_connector_hides_its_secret`: create a connector with `auth_secret` `'s3cret-token-9876'`; assert `toArray()` has no `auth_secret` and `forPage()['hint'] === '…9876'`; delete the connector and assert its tools are gone.
- [ ] **Step 2: Run** `cd admin-laravel && php artisan test --filter=ConnectorTest` — Expected: FAIL (class not found).
- [ ] **Step 3: Implement** migration and both models as above.
- [ ] **Step 4: Run** the same command — Expected: PASS. Also `cd api-engine && pytest -q tests/test_websearch_account.py` still passes (metadata builds with the new models).
- [ ] **Step 5: Commit** `feat(connectors): connectors and connector_tools tables`

### Task 2: OpenAPI import

**Files:**
- Create: `admin-laravel/app/Support/OpenApiImport.php`
- Test: `admin-laravel/tests/Unit/OpenApiImportTest.php`

**Interfaces:**
- Produces: `OpenApiImport::tools(array $spec): array` — list of `['name','description','method','path','parameters','writes']`. Throws `InvalidArgumentException` with a sentence when `$spec['paths']` is missing or not OpenAPI 3.
- Rules: one entry per path × method in `get,post,put,patch,delete`. `name` = `operationId` slugged to `[A-Za-z0-9_-]`, cut to 64, else `{method}_{path slug}`; duplicates get `_2`, `_3`. `description` = `summary` ?: `description`, cut to 1,000. `parameters` = one JSON-schema object: `{"type":"object","properties":{…},"required":[…]}` built from `parameters[]` (path and query, with `in` kept as `x-in`) plus `requestBody.content.application/json.schema` merged as property `body`. `$ref` to `#/components/schemas/X` is resolved one level; deeper refs become `{"type":"object"}`. `writes` = method is not `get`.

- [ ] **Step 1: Write the failing tests:**
  - `test_each_operation_becomes_a_tool`: a spec with `GET /applications/{id}` (operationId `getApplication`, path param `id` required) and `POST /tickets` (requestBody schema with `subject`) → two tools; first `name === 'getApplication'`, `writes === false`, `parameters['properties']['id']['x-in'] === 'path'`, `parameters['required'] === ['id']`; second `writes === true` and has property `body`.
  - `test_missing_or_clashing_operation_ids_are_named_from_the_route`: two operations without operationId on `/a b` and one duplicate → names match `/^[A-Za-z0-9_-]{1,64}$/` and are unique.
  - `test_a_non_openapi_document_is_refused`: `['swagger' => '2.0']` throws with message containing `OpenAPI 3`.
- [ ] **Step 2: Run** `php artisan test --filter=OpenApiImportTest` — Expected: FAIL.
- [ ] **Step 3: Implement** `OpenApiImport::tools`.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat(connectors): import tools from an OpenAPI 3 JSON spec`

### Task 3: Engine tool call and test endpoint

**Files:**
- Create: `api-engine/connectors/__init__.py` (empty), `api-engine/connectors/rest.py`
- Create: `api-engine/routers/connectors.py`; Modify: `api-engine/main.py` (include router)
- Test: `api-engine/tests/test_connectors_rest.py`

**Interfaces:**
- Produces: `@dataclass CallResult(ok: bool, status: int = 0, text: str = "", error: str = "", ms: int = 0)`.
- `async def call(connector, tool, arguments: dict, transport=None) -> CallResult` — builds the URL from `connector.base_url` + `tool.path`, substituting `{name}` path args (percent-encoded with `safe=""`; a value containing `..` → `CallResult(ok=False, error="…")` without any request); remaining `x-in: query` args as query string; `body` arg as JSON body; auth headers per `auth_type` (`bearer` → `Authorization: Bearer`, `header` → `auth_header: secret`, `basic` → httpx basic auth split on first `:`). 15 s timeout; text cut to 8,000. Never raises.
- `async def ping(base_url, auth_type, auth_header, auth_secret, transport=None) -> dict` → `{"success": bool, "message": str}`: GET `base_url`; any HTTP status under 500 counts as reachable (message names the status, and says "check the credentials" on 401/403).
- Route `POST /api/v1/connectors/test` guarded by `require_admin_token` (import from `routers.kb`), body `{base_url, auth_type, auth_header?, auth_secret?}` → `ping(...)`.

- [ ] **Step 1: Write the failing tests** using `httpx.MockTransport`:
  - `test_path_arguments_are_encoded_not_followed`: tool path `/applications/{id}`, args `{"id": "a/b?c"}` → requested URL path ends `/applications/a%2Fb%3Fc`.
  - `test_dot_dot_is_refused_without_a_request`: `{"id": "../admin"}` → `ok is False`, transport never called.
  - `test_query_body_and_bearer_auth`: GET with `x-in: query` arg `status=open` → `?status=open`; POST `body={"subject":"x"}` → JSON body; `auth_type="bearer"` → header `Authorization: Bearer tok`.
  - `test_long_responses_are_cut`: 20,000-char body → `len(result.text) == 8000`.
  - `test_unreachable_host_is_a_sentence`: transport raising `httpx.ConnectError` → `ok is False`, `error` non-empty; `ping` → `success is False`.
  - `test_ping_names_an_auth_failure`: 401 → `success is True`, `"credentials" in message`.
  - `test_test_route_needs_the_admin_token`: POST without `X-Admin-Token` → 401/403 (match `test_bot_router_token.py`).
- [ ] **Step 2: Run** `cd api-engine && pytest -q tests/test_connectors_rest.py` — Expected: FAIL (module missing).
- [ ] **Step 3: Implement** `connectors/rest.py` and the router.
- [ ] **Step 4: Run** — Expected: PASS; then `pytest -q` whole suite PASS.
- [ ] **Step 5: Commit** `feat(connectors): engine calls a REST tool and tests a connection`

### Task 4: Connectors page and controller

**Files:**
- Create: `admin-laravel/app/Http/Controllers/ConnectorController.php`
- Create: `admin-laravel/resources/views/connectors/index.blade.php`
- Modify: `admin-laravel/routes/web.php` (inside the `auth`, `system.access` group), `admin-laravel/resources/views/layouts/app.blade.php` (sidebar link "Connectors" after Knowledge base, `routeIs('connectors.*')`), `admin-laravel/app/Services/EngineClient.php` (`testConnector(array $fields): array`, same error-to-sentence shape as `testWebSearch`)
- Test: `admin-laravel/tests/Feature/ConnectorTest.php`

**Interfaces:**
- Consumes: Task 1 models, Task 2 `OpenApiImport::tools`, Task 3 route.
- Routes: `GET /connectors` (`connectors.index`, active workspace via `view()->shared('activeSystem')`, redirect to `systems.index` when none — as `KnowledgeBaseController::index`); JSON: `POST /connectors` (`store`), `PUT /connectors/{id}` (`update`), `DELETE /connectors/{id}` (`destroy`), `POST /connectors/test` (`test`, draft or by `connector_id`), `POST /connectors/{id}/import` (`import`: `spec` text or `spec_url`; replaces tools by name, keeping `enabled` for names that survive), `PUT /connector-tools/{id}` (`toggleTool`: `enabled` bool, optional `writes` override).
- Validation: `name` required max 120, unique per workspace (case-insensitive, like `refuseDuplicateName`); `type` in `Connector::TYPES`; `base_url` required `url` max 500; `auth_type` in `AUTH_TYPES`; `auth_header` required_if `header`; `auth_secret` required on create unless `none`. `spec` max 2 MB; invalid JSON → 422 `"The spec must be OpenAPI 3 JSON. YAML is not supported yet."`.
- Page: list of connectors (name, base URL, auth, hint, tool count, Test); a modal for add/edit (same JSON modal style as the web search key modal in `bots/brain.blade.php`); per connector an Import box and a tools table (name, method + path, description, **Writes** badge, Enabled switch). Viewers see the page read-only.

- [ ] **Step 1: Write the failing tests** in `ConnectorTest` (setUp like `WebSearchKeyTest`):
  - `test_only_system_admins_manage_connectors`: an `editor` gets 403 on store/update/destroy/test/import/toggle; a `system_admin` gets 200 and the JSON never contains the secret.
  - `test_a_blank_secret_on_update_keeps_the_saved_one`.
  - `test_a_connector_name_is_unique_in_its_workspace` (422) but allowed in another workspace.
  - `test_another_workspaces_connector_is_out_of_reach`: admin of `sys_test` gets 403 on `sys_other`'s connector.
  - `test_import_creates_tools_disabled_and_keeps_enabled_ones_on_reimport`.
  - `test_import_refuses_yaml_with_a_sentence` (422, message contains `YAML`).
  - `test_test_by_id_sends_the_saved_secret_to_the_engine`: `Http::fake` the engine URL; assert the recorded request body has the saved secret and the response passes the engine's message through.
- [ ] **Step 2: Run** `php artisan test --filter=ConnectorTest` — Expected: FAIL.
- [ ] **Step 3: Implement** controller, routes, `EngineClient::testConnector`, view, sidebar link.
- [ ] **Step 4: Run** `php artisan test` — Expected: all PASS. Then start the app (`./start-dev.sh`), add a connector pointing at a public OpenAPI JSON (e.g. `https://petstore3.swagger.io/api/v3/openapi.json`, base URL `https://petstore3.swagger.io/api/v3`), Test, Import, enable one tool.
- [ ] **Step 5: Commit** `feat(connectors): Connectors page with test and OpenAPI import`
