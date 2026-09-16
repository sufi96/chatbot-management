# Database Querying

Design document for phase 6. Written 2026-09-11. It builds on
`2026-09-09-knowledge-base-rag-design.md`,
`2026-09-10-knowledge-base-operations-design.md` and
`2026-09-11-web-search-design.md`. All three still hold, and one decision in the
web search document is deliberately revisited here; section 4 says which and
why.

**Section 8 no longer holds.** The model no longer routes between sources; an
order set per bot decides, and the router is deleted. See
`2026-09-14-answer-source-order-design.md`, which says what was gained and what
was given up. Every other decision in this document stands.

## 1. Problem

A bot has two sources of fact. The knowledge base holds what an operator
uploaded, and the web holds whatever a search engine returns. Both are text, and
both are static between the moment somebody wrote them and the moment somebody
reads them.

Neither can answer a question about the operator's own live records. "Has order
88421 shipped", "how many tickets are still open", "what did this customer buy
last year" are the ordinary questions a support bot is asked, and the answers
sit in a database the operator already runs. Today the only way to get them into
a bot is to export a report, paste it in as a source, and watch it go stale.

Aggregate questions are worse. "How many orders last month" has no passage to
retrieve even in principle, because the answer does not exist until somebody
counts.

## 2. Goals

- Answer questions from the operator's live database, at the moment they are
  asked.
- Let a person who knows the business explain what each table and column means,
  so the model writes sensible queries without anyone writing SQL.
- Discover the schema automatically where the database permits it, and accept it
  by hand where it does not.
- Restrict every query to tables somebody deliberately allowed.
- Make it impossible for a generated statement to change anything.
- Never let a database failure break a conversation.

## 3. Non-goals

- **Writes of any kind, ever.** Not behind a flag, not behind a confirmation.
  The read-only property is what makes this feature safe to switch on, and a
  single exception destroys it.
- **Joins across two connections.** One question, one connection, one statement.
  Federated querying is a database's job, not a chatbot's.
- **Charts.** Rows come back as prose. A visitor in a chat bubble is not reading
  a dashboard.
- **Result caching.** Live data that is cached is not live. Revisit if the query
  volume ever justifies it.
- **Scheduled schema sync.** Introspection is a button somebody presses. A cron
  job that silently re-reads a production schema is a surprise waiting to
  happen.
- **Showing the visitor the SQL.** It leaks table and column names to anyone on
  a public site. Operators see it; visitors see a chip naming the connection.

## 4. Decisions taken

**The model routes between sources.** A short call decides whether the database,
the documents, the web, or nothing should answer. The web search document
rejected model-decided routing, on the grounds that it costs an extra round trip
and classifies badly at small model sizes. That reasoning was correct for a
binary search-or-not question with a cheap deterministic alternative. It does
not survive a third source: there is no word list that distinguishes "what is
your returns policy", which is a document, from "has my return been processed",
which is a row. The cost is accepted, and the classification risk is answered by
section 8 rather than by avoiding the design.

**The deterministic gate stays in front of the router.** `should_retrieve` in
`kb/gating.py` runs first and unchanged. A greeting never reaches the router, so
saying hello still costs nothing and still triggers no model call. The router is
an addition to the chain, not a replacement for its cheapest link.

**Every failure falls through to today's behaviour.** An unparseable router
verdict, an invalid statement, an unreachable portal, a timeout: all of them
land in the existing documents-then-web chain. This is what bounds the risk of
the previous decision. The router can improve on current behaviour and cannot
degrade it.

**Laravel connects to the customer database, and the engine asks it to.** Every
driver this needs is already present and working on the PHP side: `pdo_mysql`,
`pdo_pgsql`, `pdo_sqlite` and `pdo_sqlsrv`. The engine has asyncpg and aiosqlite
only, so putting the connection there would mean adding an async MySQL driver
and an ODBC stack for SQL Server. This reverses the call direction the
architecture has kept one-way so far, which is a real cost and is why section 10
gives the new direction its own secret rather than reusing the existing one.

**The allowlist is the annotation table, gated by a master switch.** A table an
editor enabled, inside a connection that is enabled, is a table the model is
told about and a table a query may name. There is no second list to keep in step
with the first.

The connection-level switch was added after stage one shipped, because cutting a
bot off from a database otherwise meant unticking every table by hand and then
ticking them all back. It is a gate and not an eraser: every tick and every
description survives being switched off.

Both switches have to agree, and **stage two must ask
`DbConnection::readableTables()` rather than the table flag alone.** Asking the
table flag by itself would make the master switch appear to work while the query
path quietly ignored it, which is the worst of both worlds.

**Annotations are merged on re-introspection, never replaced.** Descriptions are
the expensive part of this feature. They are written by a person who understands
the business, and they must survive a schema change, a permissions blip, and a
column being renamed back.

**Both services validate.** The engine validates the statement it generated, and
Laravel validates the statement it was handed. The engine is a caller like any
other and is not trusted on read-only or on the allowlist.

## 5. Data model

Three migrations on the Laravel side: one creating the four new tables, one
adding columns to `bot_profiles`, one adding columns to `chat_messages`. This
follows phase 4, where every knowledge base table arrived in a single migration.

Connections are workspace scoped under a system, exactly as `kb_collections`
are, and attach to bots through a pivot shaped like `bot_kb_collection`.

Laravel owns these tables, as it owns every table. The engine reads them
directly through SQLAlchemy models added to `database.py`, the way it already
reads `KbSource` and `BotKbCollection`. It never writes them.

### `db_connections`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | string(36) | primary key |
| `system_id` | string(36) | foreign key to `systems`, cascade on delete |
| `name` | string(255) | what the visitor's source chip says |
| `driver` | string(20) | `mysql`, `pgsql`, `sqlsrv` or `sqlite` |
| `host` | string(255) | null for sqlite |
| `port` | unsigned small int | null falls back to the driver default |
| `database` | string(255) | file path when the driver is sqlite |
| `username` | string(255) | nullable |
| `password` | text | nullable, `encrypted` cast |
| `options` | json | nullable, driver extras such as `sslmode` |
| `status` | string(20) | `untested`, `ok`, `failed` |
| `error_message` | text | nullable, what the last test said |
| `last_introspected_at` | timestamp | nullable |
| `is_enabled` | boolean | default true, the master switch |

`is_enabled` defaults to true because tables default to false. Nothing is
readable until somebody ticks a table either way, and defaulting this to false
would make that tick silently do nothing.

`port` is an `unsignedInteger`, not an `unsignedSmallInteger`. Postgres has no
unsigned types, so a small integer lands as a signed `smallint` capped at 32767
and rejects an ordinary high port such as 50075.

The `encrypted` cast puts the password beyond a database dump but not beyond the
application key. The connection form says so, and says the account should be a
read-only one.

### `db_tables`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | big increments | |
| `connection_id` | string(36) | foreign key, cascade on delete |
| `schema_name` | string(128) | nullable, `public` or `dbo` where it applies |
| `table_name` | string(128) | |
| `description` | text | nullable, the editor's annotation |
| `is_enabled` | boolean | default false, and the allowlist |
| `is_present` | boolean | default true, false once introspection stops seeing it |

Unique on `connection_id`, `schema_name`, `table_name`.

### `db_columns`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | big increments | |
| `table_id` | big int | foreign key, cascade on delete |
| `column_name` | string(128) | |
| `data_type` | string(64) | nullable, blank when added by hand |
| `is_nullable` | boolean | default true |
| `is_primary_key` | boolean | default false |
| `foreign_key_target` | string(255) | nullable, `schema.table.column` |
| `description` | text | nullable, the editor's annotation |
| `ordinal` | unsigned int | default 0, display order |
| `is_present` | boolean | default true |

Unique on `table_id`, `column_name`.

### `bot_db_connection`

`bot_id` and `connection_id`, both foreign keys with cascade delete, unique
together. Identical in shape to `bot_kb_collection`.

### Columns added to `bot_profiles`

| Column | Type | Default |
| --- | --- | --- |
| `db_query_enabled` | boolean | false |
| `db_max_rows` | unsigned small int | 50 |
| `db_query_timeout` | unsigned small int | 10 |

### Columns added to `chat_messages`

| Column | Type | Notes |
| --- | --- | --- |
| `db_sql` | text | nullable, the statement that produced the answer |
| `db_row_count` | unsigned int | nullable |

These sit beside `reasoning`, which phase 5 added, and are written by the same
post-stream session.

### Keys added to `SETTING_DEFAULTS`

| Key | Default | Meaning |
| --- | --- | --- |
| `sql_model_base_url` | empty | blank means use the bot's own endpoint |
| `sql_model_api_key` | empty | |
| `sql_model_name` | empty | blank means use the bot's own model |

Small models are markedly weaker at SQL than at conversation, so the platform
can point query work at a stronger endpoint without making every bot more
expensive to run. All three blank is the supported default and keeps the feature
working with no extra configuration.

## 6. Introspection

A Laravel service, `App\Services\SchemaIntrospector`. It builds a runtime
connection from a `DbConnection` record by writing a `probe` entry into the
database config at request time, then reads through it and forgets it.

Per driver:

- **MySQL, PostgreSQL and SQL Server** read `information_schema.tables` and
  `information_schema.columns` for shape, and `table_constraints` joined to
  `key_column_usage` for primary and foreign keys.
- **SQLite** reads `PRAGMA table_list`, `PRAGMA table_info` and
  `PRAGMA foreign_key_list`, because it has no information schema.

System schemas are excluded by name: `information_schema`, `performance_schema`,
`mysql`, `sys`, `pg_catalog` and `sqlite_%`.

**Merging.** A run matches discovered tables to stored rows on schema and table
name, and discovered columns on column name. A match updates the shape fields
and leaves `description` and `is_enabled` alone. A miss inserts a row with
`is_enabled` false, so nothing becomes visible to a model without somebody
deciding it should. A stored row the run did not see gets `is_present` false
rather than a delete. The screen shows those greyed with a note that the
database no longer has them, and an editor can remove one deliberately.

**When introspection cannot run.** A restricted account may reject
`information_schema` outright. The screen then shows the error and the same
table and column editor, empty, with an add button. A hand-added row is an
ordinary row with no `data_type`, so there is one editing surface rather than a
manual mode bolted beside an automatic one.

## 7. Engine package

A new package `api-engine/dbquery/`, organised the way `kb/` and `websearch/`
are.

```
dbquery/
  __init__.py   run() — the orchestrator
  routing.py    the router prompt, and the parser for its answer
  schema.py     enabled tables and annotations rendered as a prompt block
  sql.py        the generation prompt, and the read-only validator
  portal.py     the httpx client that calls Laravel
  result.py     the QueryResult type
  context.py    rows to prompt block, and budget trimming
```

`result.py` is separate for the reason `websearch/result.py` is: so a module can
import the type without a circular import once `__init__.py` imports the
modules. `routing.py`, `schema.py`, `sql.py` and `context.py` are pure functions
with no I/O, which is what makes them cheap to test, and is the same split that
keeps `kb/gating.py` apart from `kb/retrieval.py`.

`LLMAdapter` currently only streams. It gains one method:

```python
async def complete(base_url, api_key, model_name, system_prompt,
                   user_message, temperature=0.0, max_tokens=512) -> str
```

Non-streaming, low temperature, returning the whole answer. The router and the
generator both use it. Temperature defaults to zero because neither call wants
creativity.

## 8. Routing

The router call receives the visitor's message, one line naming each attached
collection, and the enabled table names with their descriptions. Table
descriptions matter here, not just at generation time: "orders placed through
the web shop" is what tells a router that "did my order go through" is a
database question.

It is asked to answer with exactly one word: `database`, `documents`, `web` or
`none`.

The parser is deliberately forgiving, because a small model will wrap its answer
in a sentence. It lowercases, strips punctuation, and looks for exactly one of
the four words. Zero matches or more than one is treated as unparseable.

Unparseable is not an error. It falls through to section 12 along with every
other failure, which means the deterministic documents-then-web chain that ships
today. The router is only consulted when the bot has a connection attached and
`db_query_enabled` is on. Without one it never runs, and behaviour is identical
to the current release.

`none` skips retrieval and the web both, and the model answers from the
conversation alone. This is the router's one genuine saving, and it is why the
routing call is not purely an added cost.

## 9. Generation and validation

On a `database` verdict, a second call receives the full annotated schema block:
every table that is both enabled and present, its description, and each present
column with its type, key role and description. Foreign keys are rendered as
relationships, because a model that is told `orders.customer_id` points at
`customers.id` writes the join without being asked.

Enabled and present is also what defines the allowlist in step 5 below. A table
the database no longer has is not one a query may name, whatever its enable flag
still says.

The model returns a statement and nothing else. Then `sql.py` validates it, as a
pure function over the statement and the allowlist:

1. **Strip comments first.** `--` to end of line, and `/* */` spans. This
   happens before anything else, so a second statement hidden in a comment
   cannot survive into a later check.
2. **One statement.** After a single trailing semicolon is removed, another
   semicolon is a rejection.
3. **Opens with `SELECT` or `WITH`.** A `WITH` must reach a `SELECT`.
4. **No write or DDL keyword as a bare token.** `INSERT`, `UPDATE`, `DELETE`,
   `DROP`, `ALTER`, `CREATE`, `TRUNCATE`, `GRANT`, `REVOKE`, `MERGE`, `EXEC`,
   `EXECUTE`, `CALL`, `INTO`, `ATTACH`, `PRAGMA`, `COPY`. Matched on token
   boundaries, so a column named `created_at` is not a DDL statement.
5. **Every named table is on the allowlist.** Identifiers are taken from after
   `FROM` and after each `JOIN`. An identifier may be schema-qualified and may
   be quoted; an alias following it is ignored. An unrecognised table is a
   rejection, not a silent drop. A common table expression name defined by the
   statement's own `WITH` clause counts as recognised.
6. **A row limit is injected if absent**, in the dialect the driver wants:
   `LIMIT n` for MySQL, PostgreSQL and SQLite, `TOP n` after the `SELECT`
   keyword for SQL Server.

A rejection gets one retry, with the validator's complaint appended to the
generation prompt. Small models usually fix an obvious mistake when told what it
was, and one retry is cheap. A second rejection falls through.

The validator is defence in depth beside the allowlist and the read-only
account, not a substitute for either. It is the most heavily tested unit in the
feature.

## 10. Execution

A new Laravel route, `POST /internal/db/query`, registered outside the `auth`
and `system.access` groups and guarded by its own header.

Request: connection id, statement, maximum rows, timeout in seconds.
Response: `ok`, column names, rows, row count, elapsed milliseconds. On failure,
`ok` false and a message.

**The token is a new secret.** `PORTAL_INTERNAL_TOKEN`, in both `.env` files and
gitignored in both, distinct from the `ENGINE_ADMIN_TOKEN` that Laravel already
sends to the engine. A secret that authenticates one direction should not
authenticate the other, because compromising the engine would otherwise hand
over the portal as well.

**Laravel re-validates.** The same read-only rules and the same allowlist check,
implemented in PHP against the stored `db_tables` rows, in a service named
`App\Services\DbQueryRunner` beside the introspector. The engine's validation is
not evidence.

**Timeouts are set per driver** before the statement runs:
`SET STATEMENT max_statement_time` for MySQL, `SET LOCAL statement_timeout` for
PostgreSQL, the PDO query timeout attribute for SQL Server, and a PHP-level
guard for SQLite, which has no server to time out.

This route runs synchronously inside a PHP worker while the visitor waits, which
is a real cost of the decision in section 4. The per-bot timeout defaulting to
ten seconds is what bounds it.

## 11. Rows into the prompt

`context.py` renders the result as a markdown table with a header row, and trims
it to `context_char_budget` a whole row at a time, the way `fit_to_budget` trims
chunks and `fit_results_to_budget` trims web results. A single row wider than
the budget is truncated rather than dropped, so a one-row answer is never empty.

The block states that this is live data read from the operator's own database at
query time, and gives the date. Without that the model presents a queried figure
as something it remembers, which reads as a guess.

Zero rows is a real answer, not a failure. The block says the query ran and
matched nothing, which is what "is there an order 88421" deserves.

The database block and the document block never compete for budget, because the
router picked one source.

## 12. Failure handling

Every one of these degrades to the documents-then-web chain that ships today,
logged and otherwise silent:

- The router is unreachable, times out, or answers unparseably.
- Generation is unreachable, times out, or returns nothing.
- The statement fails validation twice.
- The portal is unreachable or returns a non-success status.
- The query times out, or the database refuses the connection.

This mirrors the `try`/`except` already wrapped around retrieval, which chooses
a worse answer over no answer. A visitor must never see a stack trace because a
database was down.

## 13. Citations and logging

The sources event gains a database entry:

```json
{"type": "sources", "sources": [{"n": 1, "title": "Orders database"}]}
```

No `url`, and no SQL. `attachSources` in the widget already draws a chip without
a `url` as plain text, so **the widget needs no change for this feature at all**.

`chat_messages.db_sql` and `db_row_count` are written in the post-stream session
that already writes `reasoning` and `tokens_used`. The transcript screen shows
them in the same collapsed disclosure that holds the reasoning, so an operator
can see after the fact why an answer was wrong.

## 14. Screens

**Connections list**, workspace scoped, beside the knowledge base in the
navigation. Name, driver, status, table count, last introspected.

**Connection form.** Driver, host, port, database, username, password, options.
The password field is blank on edit with "leave empty to keep the current one",
the pattern the bot API key field already uses. Help text states that the
account should be read-only and explains what the encrypted cast does and does
not protect against. A test button reports success, or the driver's error
unedited.

**Schema editor.** The heart of the feature. Tables down the left, columns for
the selected table on the right. Each table has an enable checkbox and a
description box. Each column has a description box, with its type, key role and
foreign key target shown read-only. An introspect button, an add table button,
an add column button. Absent rows greyed with a note.

**Database playground**, at `/database-playground`, a sibling of
`/knowledge-playground`. Pick a bot and a connection, type a question, and see
the router's verdict, the generated SQL, the validator's verdict, the rows, and
the elapsed time. This is the tuning surface: an editor writes a description,
re-runs, and watches the query change. Without it, annotation is guesswork.

**Bot brain screen** gains a database block beside the retrieval and web search
blocks: the switch, which connections are attached, the row cap and the timeout.
Help text says plainly that a router decides between the database, the
documents and the web, so nobody has to read this document to understand why
their bot did not query.

**Platform settings** gains the three SQL model fields, with a line saying that
leaving them blank uses each bot's own model.

## 15. Permissions

Following the roles the platform already has:

| Role | Connections and credentials | Annotations and enable flags | Introspect | Playground |
| --- | --- | --- | --- | --- |
| Super admin | yes | yes | yes | yes |
| System admin | yes | yes | yes | yes |
| Editor | yes | yes | yes | yes |
| Viewer | read, no credentials | read | no | no |

A stored password is never rendered back into any form, for any role.

## 16. Testing

**Engine:**

- The router parser: each of the four verdicts, a verdict wrapped in prose, an
  unknown word, two verdicts in one answer, and an empty string.
- The validator, as a table of accept and reject cases: a plain select, a CTE, a
  join, a statement with a second statement hidden in a `--` comment, stacked
  statements, each write and DDL keyword, a column named `created_at` that must
  still pass, and a select naming a table that is not on the allowlist.
- Limit injection for each of the four drivers, including a statement that
  already has its own limit.
- The schema block: disabled tables absent, absent columns excluded,
  descriptions present, foreign keys rendered as relationships.
- Row trimming, including a single row wider than the whole budget.
- The portal client against `httpx.MockTransport`, asserting the request shape
  and the parsed result, following `tests/test_embedding.py`.
- Orchestration: a bot with no connection makes no router call at all; a
  `documents` verdict runs the existing retrieval path; every failure in section
  12 reaches the documents-then-web chain.

**Laravel:**

- The introspector against a real SQLite file with foreign keys, asserting
  tables, columns, primary keys and foreign key targets.
- A merge across two runs where a column disappeared and a table was renamed,
  asserting descriptions and enable flags survived and the absent row is marked
  rather than deleted.
- The internal query route rejects a missing token, a wrong token, a write
  statement, and a select naming a table that is not enabled.
- Migration defaults for the three bot columns and the two message columns.
- Settings validation accepts the three SQL model keys.

## 17. Build order

Two stages, each useful on its own, so the first can be judged before the second
is written.

**Stage one is everything an operator touches.** The three migrations, the
introspector, the connections list, the connection form with its test button,
and the schema editor. At the end of it somebody can connect a database,
discover its schema, and annotate it. Nothing queries anything during a chat,
and no bot behaviour has changed.

**Stage two is the chat path.** The `dbquery` package, the adapter's
non-streaming method, the internal query route, the playground, the bot brain
block, the platform settings fields, and the transcript columns. This is the
stage that can regress an existing conversation, which is why it comes second
and why section 12 exists.

## 18. What an operator does, start to finish

Create a connection with a read-only account. Press test. Press introspect. Tick
the four tables that matter and write a sentence about each. Write a sentence
about the eight columns whose names are not self-explanatory. Open the
playground, ask the question a customer would ask, and read the SQL. Fix the
sentence that made the model pick the wrong column. Attach the connection to a
bot and turn the switch on.
