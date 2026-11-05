# The Admin Assistant

Status: **built** (2026-11-05) on branch `feat/admin-assistant`. Plan: `docs/superpowers/plans/2026-11-05-admin-assistant.md`. Tested live on qwen3:8b as a super admin and as a workspace admin, including a change through Confirm. What it covers today, and what
not yet, is kept in `docs/admin-assistant.md`. A test keeps that page honest.

## Why

C⁴'s admins and super admins run many bots across many workspaces. Most of their questions are
"what is happening" questions:
- which bots failed a tool call today?
- which conversations are waiting for a person?
- is the HR bot taking files?

Most of their changes are small switches. Today each one is a hunt through console pages. The
console already has a chat assistant: the platform bot `bot_console_assistant`, shown to super
admins only. It can read the console's own database with SQL and nothing more.

C⁴ is not finished. New features will keep landing, and an assistant that only knows today's
features goes stale on the first new one. So the real requirement is **growth**: every new feature
should reach the assistant as part of being built, and a missing piece should be visible rather
than forgotten.

## Goals

1. **Read and report.**
   - Workspaces, bots and their settings.
   - Conversations, including those waiting for a person.
   - Failed tool calls and usage.
   - Knowledge, connectors, skills, databases, members.
2. **Make changes, always behind the Confirm button** the widget already has for tools that write.
3. **Act as the signed-in person.** Every tool runs with that person's own rights, the same rights
   the console pages check (`User::canManageSystem`). An editor cannot do through chat what the
   console would refuse them.
4. **Grow with the system.**
   - Tools live in one place in the portal, one class each.
   - Every console route is listed in a coverage map as covered, later or never.
   - A test fails when a new route is in none of them, or when the coverage page misses it.
5. **An audit trail.** Every change made through the assistant records who made it, what, and the
   result.

## Non-goals (this version)

- **Deleting anything.** Bots, workspaces, knowledge, connectors, skills, users: deleting stays a
  deliberate act on its console page. Listed as "later" in the coverage map, so it is a visible
  decision. *(Builder's call; easy to change.)*
- **Anything that carries a secret.** Provider keys, search keys, MCP keys, connector auth,
  passwords. These are "never": a chat transcript is no place for a secret.
- Creating bots, collections, connectors or skills from chat. "Later".
- An MCP endpoint for outside tools to administer C⁴. The registry is shaped so this is a thin
  wrapper later. *(The portal has no MCP library today, so the engine reaches the tools over a
  plain internal HTTP call instead.)*

## Who sees it

- Super admins, as today.
- Anyone who is **system_admin** in at least one workspace (new).
- Editors and viewers don't see it in this version. *(Builder's call: the user's words were
  "admin/superadmin". Each tool checks its own minimum role anyway, so widening this later is a
  one-line change.)*

## How it works

### Identity: the console token

When the console page renders the widget, it adds `data-console-token`: an encrypted, expiring
token naming the user (`Crypt::encryptString`, 12 hours).
- The widget sends it with every chat message and every Confirm/Cancel.
- The engine never reads it. It only passes it on to the portal.
- The portal decrypts it, checks the expiry, and loads the user.

So the engine cannot impersonate anyone, and a stolen engine secret alone does not give admin
rights. Every portal call also carries the engine's existing internal token (`engine.token`
middleware), so the portal only listens to its own engine.

### Tools live in the portal

`app/Assistant/` holds:
- **`Tool`**, the base class. A tool has a name, a description, a JSON-schema of arguments, a flag
  for whether it changes anything (`writes`), and a minimum role. It implements
  `run(User $user, array $args): string`. It checks rights on the actual target (bot, workspace,
  source) with `canManageSystem`, exactly as the console controller does.
- **`Registry`**: the list of tool classes. `forUser($user)` returns the tools this person could
  ever use (a super-admin-only tool is hidden from others).
- **`Coverage`**: the route-name map described under "Growth".
- **`ConsoleToken`**: issue and resolve.

There are two internal routes, both behind `engine.token`:
- `POST /internal/assistant/tools` takes `{token}` and returns `{user: {name, role, super_admin},
  tools: [{name, description, parameters, writes}]}`.
- `POST /internal/assistant/call` takes `{token, name, arguments}` and returns
  `{ok, text}` or `{ok: false, error}`.
  - A write is only ever called after the person pressed Confirm (see below). Rights are checked
    again at that moment.
  - Each write is recorded in `assistant_actions`: user, tool, arguments, ok, result, time.

### The engine side

- `ChatStreamRequest` and `ActionDecision` take an optional `console_token`.
- For the platform bot only, the chat route asks the portal for the person's tools
  (`console_tools.load`). They join the tools source as ordinary `agent.Tool`s: reads run at once,
  writes come back as pending and the widget shows Confirm. They are logged in `chat_tool_calls`
  like any other tool.
- **The console database source**, raw SQL over every workspace, runs only for a super admin. For
  anyone else it is switched off for that message, because it would show other workspaces' data.
- With no token or a bad one, there are no console tools and no database: the assistant can only
  talk.
- **Confirm** for a console tool (no `connector_tool_id`, platform bot) calls the portal with the
  token sent with the Confirm click. The portal checks rights again.
- A migration switches the console assistant's tools source on and puts it first in its source
  order. It also gives the assistant a short prompt about being C⁴'s admin assistant.

### Growth: the coverage map

`App\Assistant\Coverage::ROUTES` maps **every named console route** to one of:
- `tool:<name>[,<name>]`: covered by these tools.
- `later: <reason>`: not yet. This is the to-do list for the next upgrade.
- `never: <reason>`: deliberately not (secrets, sign-in, file streaming, internal).

`tests/Feature/AssistantCoverageTest.php` fails when:
- a named route is missing from the map (a new feature forgot the assistant);
- a `tool:` names a tool the registry doesn't have;
- `docs/admin-assistant.md` doesn't mention every route name and every tool name.

So adding a page forces a decision, and the doc lists every decision.

## First tool set

Reads (no Confirm):

| Tool | Answers |
|---|---|
| `whoami` | who I am, my role, my workspaces |
| `list_workspaces` | workspaces I can see, with bot counts |
| `list_bots` | bots (optionally in one workspace): on/off, model, sources, files |
| `get_bot` | one bot's settings in full, readable |
| `list_conversations` | recent conversations: filter by bot, waiting for a person, flagged, days |
| `get_conversation` | one transcript, with tool calls and files |
| `failed_tool_calls` | tool calls that failed, by bot and days |
| `usage_summary` | conversations, messages and answer sources per bot over N days |
| `list_knowledge` | collections, sources, and any that failed to index |
| `list_connectors` | connectors and their tools, enabled or not (never secrets) |
| `list_skills` | skills and their mode |
| `list_databases` | database connections and readable tables (never credentials) |
| `list_members` | a workspace's people and roles (system_admin) |
| `list_users` | every console user (super admin only) |
| `recent_assistant_actions` | changes made through the assistant: everyone's for a super admin, your own otherwise |

Changes (Confirm, then the portal re-checks):

| Tool | Does | Needs |
|---|---|---|
| `set_bot_active` | switch a bot on or off | editor |
| `set_bot_sources` | switch documents / database / web / tools on or off | editor |
| `set_file_settings` | files in/out, types, formats, size, retention, visuals | editor |
| `clear_answer_cache` | drop a bot's cached answers | editor |
| `reindex_knowledge_source` | index one knowledge source again | editor |
| `set_connector_tool_enabled` | switch one connector tool on or off | system_admin |

Bots can be named by id or by name (small models do better with names). A name that matches more
than one bot gets back the list to choose from.

## What's left to the builder

- Wording of tool descriptions and results: short and plain, readable by a small model.
- Exact output shape: plain text lines, not JSON. The answer model rewrites them anyway.
- Usage numbers come from the existing tables directly. No new analytics.

## Found in the live run

Fixed:
- Small models fill an optional `workspace` with a word ("all", "mine"). `Lookup::meansAll` reads such words as no filter.
- The console bot's original prompt says "point to where things are in the console", so it sent people to pages even when a tool had the answer. The new prompt paragraph says to answer from the tool results.
- `failed_tool_calls` listed the newest 20, and the model made up a total. It now opens with the true count, per tool.
- A refused read reaches the model as "Not done. The console said: …", not as a failed call, so the person hears the reason.

Known, not fixed:
- On a refusal, qwen3:8b sometimes repeats the `<context>` block before its answer. The reason still reaches the person. This is small-model behaviour; recheck on the DGX models.
