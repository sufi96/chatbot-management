# Admin Assistant Implementation Plan

> For agentic workers: executed inline (superpowers:executing-plans), TDD per task.

**Goal:** The console assistant reads and changes C⁴ through tools that run as the signed-in admin, with a coverage map and test that make every future feature decide its assistant tools.

**Spec:** `docs/superpowers/specs/2026-11-05-admin-assistant-design.md`

## Global Constraints

- No Claude name, co-author or session trailer in commits.
- Rights are checked in the portal with `User::canManageSystem`, at the same minimum role as the console controller for the same action.
- Never return a secret (provider keys, connector auth, DB credentials, passwords, MCP token hashes).
- Writes run only through Confirm; the portal re-checks rights at call time and records every write in `assistant_actions`.

## Review Focus

1. A system_admin of workspace A asking about workspace B's bot by **name**: must not find it.
2. An expired or tampered console token: no tools, no database source, a plain reply.
3. A Confirm click with a token for a different user than the one who asked: the portal checks the clicking user's rights.
4. A bot name that matches two bots: a list to choose from, never a guess.
5. A new console route with no Coverage entry: the coverage test fails.

---

### Task 1: Portal: token, base, registry, internal routes, audit table
- Create: `app/Assistant/{ConsoleToken,Tool,Registry,Lookup}.php`, `app/Http/Controllers/InternalAssistantController.php`, migration `2026_11_05_000002_create_assistant_actions.php`, `app/Models/AssistantAction.php`
- Modify: `routes/internal.php`
- Test: `tests/Feature/AssistantEndpointTest.php`:
  - a good token lists the tools for that user;
  - a bad or expired token gives 401 with `{ok:false}`;
  - a missing engine token gives 401;
  - a write call records an `assistant_actions` row;
  - an unknown tool gives `ok:false`.

### Task 2: Portal: read tools
- Create one class per read tool in `app/Assistant/Tools/`.
- Test: `tests/Feature/AssistantReadToolsTest.php`. Each tool's text names what it should, and:
  - cross-workspace by id and by name finds nothing;
  - an ambiguous name lists the choices;
  - no secret strings appear.

### Task 3: Portal: write tools
- Create the write tools.
- Test: `tests/Feature/AssistantWriteToolsTest.php`:
  - each change applies;
  - a viewer or editor below the needed role is refused;
  - a platform bot cannot be switched off by a non-super-admin;
  - file settings are clamped to the allowed types and formats.

### Task 4: Coverage map, page, test
- Create: `app/Assistant/Coverage.php`, `docs/admin-assistant.md`, `tests/Feature/AssistantCoverageTest.php`.

### Task 5: Console: who sees it, token, migration
- Modify `resources/views/layouts/app.blade.php`: widget for super admins and system admins, plus `data-console-token`.
- Migration: on the console bot, set `agentic_enabled` true, put `tools` first in `source_order`, and update the prompt.
- Test: extend `ConsoleAssistantTest`:
  - a system admin sees the widget with a token;
  - an editor doesn't see it.

### Task 6: Engine
- Create `api-engine/console_tools.py`: `load(token) -> Console(user, tools)` and `call(token, name, arguments) -> CallResult`.
- Modify:
  - `routers/chat.py`: request fields, load for the platform bot, database gating, Confirm path.
  - `sources/__init__.py` and `sources/attempts.py`: `extra_tools`.
- Test: `tests/test_console_tools.py`:
  - load parses the tools;
  - writes become pending;
  - no token means no tools and the database is off;
  - Confirm calls the portal with the token.

### Task 7: Widget
- `widget/widget.js`: read `data-console-token`, send it as `console_token` in the stream and action bodies.

### Task 8: Live run
- Run the full suites:
  - engine pytest;
  - portal `php artisan test`;
  - widget `node --test`.
- Live chat in the console as a super admin and as a system admin:
  - a read;
  - a write through Confirm;
  - a cross-workspace refusal.
