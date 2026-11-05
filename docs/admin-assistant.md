# The Admin Assistant: What It Covers

The console assistant is the chat widget inside the C⁴ console, shown to super admins and to workspace admins. It reads and changes C⁴ through tools. Every tool runs **as the person chatting**, with the same rights the console pages give them. Every change waits for their **Confirm**, and is recorded in `assistant_actions`.

This page records, for every console page and action, whether the assistant can do it yet. When you improve the admin side, start here:
- **Not yet (later)** is the to-do list.
- **Never** lists what was decided against, and why.

Design: `docs/superpowers/specs/2026-11-05-admin-assistant-design.md`.

## When you add a console feature

1. **Add its tools.** Write one class per tool in `admin-laravel/app/Assistant/Tools/`. Copy a similar tool.
   - Check rights on the actual target with `Lookup::bot`, `Lookup::workspace` or `Lookup::workspaces`, at the **same least role** the console controller uses for that action.
   - Set `$writes = true` for anything that changes data. The person will be asked to Confirm, and the change is recorded.
   - Return short plain lines. **Never return a secret**: keys, passwords, connector auth, credentials.
2. **Register the tools** in `app/Assistant/Registry.php`.
3. **Decide every new route** in `app/Assistant/Coverage.php`: `tool:<names>`, `later: <reason>` or `never: <reason>`.
4. **Regenerate the list below** with `php artisan assistant:coverage`, from `admin-laravel/`.
5. **Run the tests.** `AssistantCoverageTest` fails until 3 and 4 are done.

Nothing in the engine changes for a new tool. The engine asks the portal for the person's tools on every message, so a new tool appears in the chat as soon as the portal has it.

## How it fits together

- **Console token.** The console page renders the widget with an encrypted console token, valid 12 hours, that names the signed-in person.
  - The widget sends it with every message and every Confirm or Cancel.
  - The engine never reads it. It passes it on to the portal.
- **Portal calls.** The engine calls `POST /internal/assistant/tools` and `/internal/assistant/call` with its portal token plus the console token. The portal decides what the person may do.
- **Console database (raw SQL over every workspace).** The assistant only answers from it for a super admin.
- **No valid token.** No tools and no database: the assistant can only chat.

## Coverage

<!-- coverage:start (written by php artisan assistant:coverage; do not edit by hand) -->

### Tools

| Tool | Changes things | Least role | What it does |
|---|---|---|---|
| `whoami` | no | viewer | Who I am talking to: their name, their role, and the workspaces they can reach. |
| `list_workspaces` | no | viewer | The workspaces I can reach, with how many bots each has. |
| `list_bots` | no | viewer | The bots I can see: on or off, model, which sources are on, and whether files are on. |
| `get_bot` | no | viewer | One bot's settings: model, sources and their order, knowledge, tools, skills, files, guard and voice. |
| `list_conversations` | no | viewer | Recent conversations, newest first. Can keep only those waiting for a person, or those with a flagged message. |
| `get_conversation` | no | viewer | One conversation's transcript, with its tool calls and files. |
| `failed_tool_calls` | no | viewer | Tool calls that failed recently: which bot, which tool, and the error. |
| `usage_summary` | no | viewer | Per bot over recent days: conversations, answers, and where the answers came from (documents, database, tools, web, model, none, refused). |
| `list_knowledge` | no | viewer | Knowledge collections and their sources, with each source's status. Sources that failed to index show their error. |
| `list_connectors` | no | viewer | Connectors (REST and MCP systems bots can call) and their tools, each on or off. Never shows keys. |
| `list_skills` | no | viewer | Skills (step-by-step instructions bots follow), with their mode and the tools they use. |
| `list_databases` | no | viewer | Database connections bots can read, with status and readable tables. Never shows credentials. |
| `list_members` | no | system_admin | A workspace's people and their roles. |
| `list_users` | no | super_admin | Every console user, their global role, and their workspaces. |
| `recent_assistant_actions` | no | viewer | Changes made through this assistant: who, what, when, and whether it worked. A super admin sees everyone's; others see their own. |
| `set_bot_active` | yes, after Confirm | editor | Switch a bot on or off. Off: every site running it stops answering. |
| `set_bot_sources` | yes, after Confirm | editor | Switch a bot's answer sources on or off: documents (knowledge base), database, tools, web. Only the ones given change. |
| `set_file_settings` | yes, after Confirm | editor | Change a bot's files and visuals: files in (visitors attach files), files out (the bot hands back files), allowed types, limits, retention, drawn visuals. Only the ones given change. |
| `clear_answer_cache` | yes, after Confirm | editor | Drop a bot's cached answers, so every question is answered afresh. |
| `reindex_knowledge_source` | yes, after Confirm | editor | Index one knowledge source again, such as one that failed. The id comes from list_knowledge. |
| `set_connector_tool_enabled` | yes, after Confirm | system_admin | Switch one connector tool on or off for every bot that uses it. Named by id or name, from list_connectors. |

### Covered (23 routes)

- `dashboard`: `list_bots`, `usage_summary`
- `analytics.index`: `usage_summary`
- `systems.index`: `list_workspaces`
- `systems.users`: `list_members`
- `users.index`: `list_users`
- `bots.index`: `list_bots`
- `bots.edit`: `get_bot`
- `bots.update`: `set_bot_active`
- `bots.brain`: `get_bot`
- `bots.brain.update`: `set_bot_sources`, `set_file_settings`
- `bots.brain.cache.clear`: `clear_answer_cache`
- `admin.bots.index`: `list_bots`
- `logs.index`: `list_conversations`, `failed_tool_calls`
- `logs.transcript`: `get_conversation`
- `kb.index`: `list_knowledge`
- `kb.show`: `list_knowledge`
- `kb.sources.show`: `list_knowledge`
- `kb.sources.reindex`: `reindex_knowledge_source`
- `connectors.index`: `list_connectors`
- `connector-tools.update`: `set_connector_tool_enabled`
- `skills.index`: `list_skills`
- `databases.index`: `list_databases`
- `databases.schema`: `list_databases`

### Not yet (later) (58 routes)

- `systems.store`: create a workspace
- `systems.update`: rename a workspace or change its allowed sites
- `systems.destroy`: deleting stays a deliberate act on its page in this version
- `systems.users.assign`: add a person to a workspace
- `systems.users.update`: change a person's role in a workspace
- `systems.users.remove`: remove a person from a workspace
- `users.destroy`: deleting stays a deliberate act on its page in this version
- `bots.store`: create a bot
- `bots.destroy`: deleting stays a deliberate act on its page in this version
- `bots.embed`: hand over a bot's embed snippet
- `admin.bots.destroy`: deleting stays a deliberate act on its page in this version
- `admin.bots.restore`: restore a deleted bot
- `admin.bots.purge`: deleting stays a deliberate act on its page in this version
- `logs.file`: hand over a file from a transcript
- `kb.store`: create a collection
- `kb.destroy`: deleting stays a deliberate act on its page in this version
- `kb.sources.store`: add a text or Q&A source
- `kb.sources.upload`: add a file source (needs files from the chat)
- `kb.sources.update`: edit a source
- `kb.sources.download`: hand over a source's file
- `kb.sources.destroy`: deleting stays a deliberate act on its page in this version
- `kb.playground.run`: try a search against a collection
- `connectors.destroy`: deleting stays a deliberate act on its page in this version
- `connectors.test`: test a connector
- `connectors.import`: re-import a connector's tools
- `mcp-keys.destroy`: deleting stays a deliberate act on its page in this version
- `skills.store`: write a skill
- `skills.draft`: draft a skill with a model
- `skills.upload`: upload a skill file
- `skills.update`: edit a skill
- `skills.destroy`: deleting stays a deliberate act on its page in this version
- `skills.mode`: change a skill's mode
- `skills.copy`: copy a built-in skill
- `skills.download`: hand over a skill file
- `databases.destroy`: deleting stays a deliberate act on its page in this version
- `databases.test`: test a connection
- `databases.toggle`: switch a connection on or off
- `databases.introspect`: read a database's tables again
- `databases.tables.store`: describe a table
- `databases.tables.readable`: choose readable tables
- `databases.tables.update`: describe a table
- `databases.tables.destroy`: deleting stays a deliberate act on its page in this version
- `databases.columns.store`: describe a column
- `databases.columns.update`: describe a column
- `databases.columns.destroy`: deleting stays a deliberate act on its page in this version
- `databases.playground.run`: try a question against a database
- `providers.destroy`: deleting stays a deliberate act on its page in this version
- `providers.models`: list a provider's models
- `providers.test`: test a provider
- `admin.providers.destroy`: deleting stays a deliberate act on its page in this version
- `web-search-keys.destroy`: deleting stays a deliberate act on its page in this version
- `web-search-keys.test`: test a search key
- `admin.settings`: read the platform settings (super admin)
- `admin.settings.update`: change platform settings (super admin; some hold secrets)
- `admin.settings.test`: test a model role
- `admin.settings.models`: list a model server's models
- `admin.settings.reindex`: re-index every knowledge base
- `admin.settings.speech-server`: check the speech server

### Never (27 routes)

- `login`: signing in is the person's own act
- `logout`: signing out is the person's own act
- `storage.local`: file streaming for the framework
- `storage.local.upload`: file streaming for the framework
- `internal.db.query`: the engine's own call into the portal
- `internal.assistant.tools`: the assistant's own channel
- `internal.assistant.call`: the assistant's own channel
- `systems.switch`: changes which workspace the console pages show, not the system
- `users.store`: carries a secret, and a chat transcript is no place for one (a password)
- `users.update`: carries a secret, and a chat transcript is no place for one (a password)
- `bots.create`: a page or form, not an action; its data is read by the tools named on its actions
- `bots.brain.voice-preview`: plays audio in the page
- `kb.playground`: a page or form, not an action; its data is read by the tools named on its actions
- `connectors.store`: carries a secret, and a chat transcript is no place for one (connector auth)
- `connectors.update`: carries a secret, and a chat transcript is no place for one (connector auth)
- `mcp-keys.store`: carries a secret, and a chat transcript is no place for one (an MCP key, shown once)
- `databases.store`: carries a secret, and a chat transcript is no place for one (database credentials)
- `databases.update`: carries a secret, and a chat transcript is no place for one (database credentials)
- `databases.test-draft`: carries a secret, and a chat transcript is no place for one (database credentials)
- `databases.playground`: a page or form, not an action; its data is read by the tools named on its actions
- `providers.store`: carries a secret, and a chat transcript is no place for one (a provider key)
- `providers.update`: carries a secret, and a chat transcript is no place for one (a provider key)
- `admin.providers.store`: carries a secret, and a chat transcript is no place for one (a provider key)
- `admin.providers.update`: carries a secret, and a chat transcript is no place for one (a provider key)
- `web-search-keys.store`: carries a secret, and a chat transcript is no place for one (a search key)
- `web-search-keys.update`: carries a secret, and a chat transcript is no place for one (a search key)
- `admin.settings.voice-test`: plays audio in the page

<!-- coverage:end -->
