# Database Querying Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a bot work out what a visitor is asking for, then answer directly, from the documents, from the web, or by writing a read-only query against the workspace's own database and summarizing the rows.

**Architecture:** A new `api-engine/dbquery/` package, organised the way `kb/` and `websearch/` already are: pure functions for the parts worth testing hard, one thin orchestrator, one HTTP client. The existing word-list gate runs first and unchanged, so a greeting still costs nothing. Past that gate a router call returns one word, and only the `database` verdict spends a second call writing SQL. Laravel executes the statement, because every driver is already loaded there. Every failure in the chain falls through to the documents-then-web behaviour that ships today.

**Tech Stack:** Python 3.12, FastAPI, SQLAlchemy 2, httpx with injectable transports, pytest with `asyncio_mode = auto`. Laravel 13, PHP 8.3, Blade with Bootstrap 5, PHPUnit 12 over in-memory SQLite. No new dependencies on either side.

**Spec:** `docs/superpowers/specs/2026-09-11-database-query-design.md`

This is **stage two**, as defined in section 17 of the spec. Stage one shipped the operator surface: connections, introspection, the annotated schema, and the master switch. This plan is the half that changes how a bot answers.

## Global Constraints

- The four router verdicts are exactly `database`, `documents`, `web`, `none`. Anything else is unparseable and falls through.
- `should_retrieve` in `api-engine/kb/gating.py` runs **before** the router, unchanged. A greeting never reaches a model call.
- The router only runs when the bot has `db_query_enabled` true **and** at least one enabled connection attached. Otherwise chat behaviour is byte-identical to today.
- Every failure, an unreachable router, an unparseable verdict, twice-rejected SQL, an unreachable portal, a query timeout, falls through to the existing documents-then-web chain. Logged, never raised.
- A readable table is one where the **connection** is enabled and the **table** is enabled and present. Both switches must agree. See `DbConnection::readableTables()` on the Laravel side.
- Generated SQL is validated in the engine and **again** in Laravel. The engine is a caller like any other and is not trusted on read-only or on the allowlist.
- Only a single `SELECT` or a `WITH` reaching a `SELECT` is ever executed. No exceptions, no flag, no confirmation dialog.
- One retry on a validation failure, with the complaint appended. A second rejection falls through.
- The engine calls Laravel with `PORTAL_INTERNAL_TOKEN`, which is a **different secret** from `ADMIN_API_TOKEN`. Both live only in gitignored `.env` files.
- Row context is trimmed by the existing `context_char_budget` setting, a whole row at a time, following `fit_to_budget` in `kb/retrieval.py`.
- The three platform settings are `sql_model_base_url`, `sql_model_api_key`, `sql_model_name`. All blank means use the bot's own endpoint and model.
- Tests use `httpx.MockTransport` through a `transport=` parameter, following `api-engine/tests/test_embedding.py`. No test makes a network call.
- Run Python tests with `.venv/Scripts/python.exe -m pytest` from `api-engine/`.
- Run Laravel tests with `php artisan test` from `admin-laravel/`.

---

### Task 0: Relationships a person can write down

**Files:**
- Modify: `admin-laravel/app/Http/Controllers/DbSchemaController.php`
- Modify: `admin-laravel/app/Services/Schema/SchemaSync.php`
- Modify: `admin-laravel/resources/views/databases/schema.blade.php`
- Test: `admin-laravel/tests/Feature/DbForeignKeyTest.php`

**Interfaces:**
- Consumes: `DbColumn`, `SchemaSync` from stage one.
- Produces: `foreign_key_target` accepted by `DbSchemaController::updateColumn` and `storeColumn`, and preserved by `SchemaSync` when a discovery run finds none.

Do this first. The whole database branch rests on the model knowing how tables join, and stage one can only learn that from a real constraint. Plenty of systems have none: the relationship is convention, `customer_id` simply matches `customers.id`, and introspection sees nothing at all.

There is a trap behind it. `SchemaSync` currently treats `foreign_key_target` as shape and overwrites it on every run, so a hand-written relationship would survive exactly until somebody pressed Discover. A discovered constraint must still win, but discovering nothing must not erase what a person wrote.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/DbForeignKeyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use App\Models\User;
use App\Services\Schema\DiscoveredColumn;
use App\Services\Schema\DiscoveredTable;
use App\Services\Schema\SchemaSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    private function editor(): User
    {
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    private function connection(): DbConnection
    {
        return DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Legacy',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
    }

    private function ordersWith(?string $target): DbColumn
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        return DbColumn::create([
            'table_id' => $table->id, 'column_name' => 'customer_id',
            'data_type' => 'integer', 'ordinal' => 2, 'foreign_key_target' => $target,
        ]);
    }

    public function test_an_editor_writes_a_relationship_by_hand(): void
    {
        // A legacy database with no constraints. The relationship is real,
        // it is just never declared, and the model cannot guess it.
        $column = $this->ordersWith(null);

        $this->actingAs($this->editor())
            ->put(route('databases.columns.update', $column->id), [
                'description' => 'Who placed the order.',
                'foreign_key_target' => 'customers.id',
            ])
            ->assertRedirect();

        $this->assertSame('customers.id', $column->fresh()->foreign_key_target);
    }

    public function test_a_relationship_can_be_cleared(): void
    {
        $column = $this->ordersWith('wrong.id');

        $this->actingAs($this->editor())
            ->put(route('databases.columns.update', $column->id), [
                'description' => '', 'foreign_key_target' => '',
            ]);

        $this->assertNull($column->fresh()->foreign_key_target);
    }

    public function test_a_hand_added_column_can_carry_a_relationship(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'invoices']);

        $this->actingAs($this->editor())
            ->post(route('databases.columns.store', $table->id), [
                'column_name' => 'order_ref',
                'data_type' => 'varchar',
                'foreign_key_target' => 'orders.id',
            ])
            ->assertRedirect();

        $this->assertSame('orders.id',
            DbColumn::where('column_name', 'order_ref')->value('foreign_key_target'));
    }

    public function test_a_viewer_cannot_write_a_relationship(): void
    {
        $column = $this->ordersWith(null);

        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $viewer->systems()->attach('sys_test', ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->put(route('databases.columns.update', $column->id), [
                'foreign_key_target' => 'customers.id',
            ])
            ->assertForbidden();

        $this->assertNull($column->fresh()->foreign_key_target);
    }

    public function test_a_hand_written_relationship_survives_rediscovery(): void
    {
        // The trap. A discovery run finds no constraint, because there is
        // none, and must not erase what somebody worked out by hand.
        $column = $this->ordersWith('customers.id');
        $connection = DbConnection::find('dbc_1');

        SchemaSync::apply($connection, [new DiscoveredTable(null, 'orders', [
            new DiscoveredColumn('customer_id', 'integer', false, false, null, 2),
        ])]);

        $this->assertSame('customers.id', $column->fresh()->foreign_key_target);
    }

    public function test_a_real_constraint_still_wins(): void
    {
        // If the database does declare one, it is the authority. A stale
        // hand-written guess must not outrank it.
        $column = $this->ordersWith('guessed.id');
        $connection = DbConnection::find('dbc_1');

        SchemaSync::apply($connection, [new DiscoveredTable(null, 'orders', [
            new DiscoveredColumn('customer_id', 'integer', false, false, 'customers.id', 2),
        ])]);

        $this->assertSame('customers.id', $column->fresh()->foreign_key_target);
    }

    public function test_the_editor_offers_a_field_for_it(): void
    {
        $this->ordersWith(null);

        $this->actingAs($this->editor())
            ->get(route('databases.schema', 'dbc_1'))
            ->assertOk()
            ->assertSee('foreign_key_target', false);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=DbForeignKeyTest`
Expected: FAIL, the controller ignores `foreign_key_target` and the sync overwrites it.

- [ ] **Step 3: Accept it in the controller**

In `admin-laravel/app/Http/Controllers/DbSchemaController.php`, in `updateColumn`, replace the validation and update with:

```php
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'foreign_key_target' => ['nullable', 'string', 'max:255'],
        ]);

        $column->update([
            'description' => $validated['description'] ?? null,
            // A relationship nobody declared in the database is still a
            // relationship. This is how a person tells the model about one.
            'foreign_key_target' => $validated['foreign_key_target'] ?: null,
        ]);
```

In `storeColumn`, add to the validation array:

```php
            'foreign_key_target' => ['nullable', 'string', 'max:255'],
```

and to the `DbColumn::create` array:

```php
            'foreign_key_target' => $validated['foreign_key_target'] ?: null,
```

- [ ] **Step 4: Stop the sync erasing it**

In `admin-laravel/app/Services/Schema/SchemaSync.php`, in `syncColumns`, replace the `$shape` array and the update that follows with:

```php
            $shape = [
                'data_type' => $column->dataType,
                'is_nullable' => $column->isNullable,
                'is_primary_key' => $column->isPrimaryKey,
                'ordinal' => $column->ordinal,
                'is_present' => true,
            ];

            $row = $stored->get($column->name);

            if (!$row) {
                DbColumn::create($shape + [
                    'table_id' => $table->id,
                    'column_name' => $column->name,
                    'foreign_key_target' => $column->foreignKeyTarget,
                ]);
                $counts['columns_added']++;
                continue;
            }

            // A declared constraint is the authority and always wins. Finding
            // none is not the same as there being none: plenty of databases
            // never declared theirs, and somebody may have worked the
            // relationship out by hand. Discovering nothing leaves it alone.
            if ($column->foreignKeyTarget !== null) {
                $shape['foreign_key_target'] = $column->foreignKeyTarget;
            }

            // Shape is the database's to state. description is not, and nor
            // is a relationship it never declared.
            $row->update($shape);
```

- [ ] **Step 5: Add the field to the editor**

In `admin-laravel/resources/views/databases/schema.blade.php`, inside the per-column form, replace the single description textarea with a description textarea plus a relationship input. The form currently posts only `description`; it now posts both:

```blade
                                            <form action="{{ route('databases.columns.update', $column->id) }}"
                                                  method="POST">
                                                @csrf
                                                @method('PUT')
                                                <div class="d-flex gap-2">
                                                    <textarea name="description" class="form-control form-control-sm" rows="1"
                                                              maxlength="2000" {{ $canEdit ? '' : 'disabled' }}
                                                              placeholder="Gross amount in ringgit, including tax.">{{ $column->description }}</textarea>
                                                    @if($canEdit)
                                                        <button class="btn btn-sm btn-outline-primary">Save</button>
                                                    @endif
                                                </div>
                                                <input type="text" name="foreign_key_target"
                                                       class="form-control form-control-sm mt-1 figure-mono"
                                                       style="font-size: 0.75rem;" maxlength="255"
                                                       {{ $canEdit ? '' : 'disabled' }}
                                                       value="{{ $column->foreign_key_target }}"
                                                       placeholder="joins to, e.g. customers.id">
                                            </form>
```

Add a line to the Columns card header explaining it, immediately under the card header div:

```blade
                <div class="card-body pb-0">
                    <p class="text-muted mb-2" style="font-size: 0.75rem;">
                        The second box on each row is how this column joins to another
                        table, written as <code>table.column</code>. Discovery fills it
                        in wherever the database declares a foreign key. Where it does
                        not, write the relationship yourself: it is what lets a bot
                        follow a question from an order to the customer who placed it.
                    </p>
                </div>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=DbForeignKeyTest`
Expected: PASS, 7 tests.

- [ ] **Step 7: Run the whole Laravel suite**

Run: `php artisan test`
Expected: PASS. `DbSchemaControllerTest` and `SchemaSyncTest` must still pass unchanged.

- [ ] **Step 8: Commit**

```bash
git add admin-laravel/app/Http/Controllers/DbSchemaController.php \
        admin-laravel/app/Services/Schema/SchemaSync.php \
        admin-laravel/resources/views/databases/schema.blade.php \
        admin-laravel/tests/Feature/DbForeignKeyTest.php
git commit -m "feat: write down a relationship the database never declared"
```

---

### Task 1: Engine models, columns and settings

**Files:**
- Modify: `api-engine/database.py`
- Test: `api-engine/tests/test_dbquery_models.py`

**Interfaces:**
- Consumes: nothing.
- Produces: SQLAlchemy models `DbConnection`, `DbTable`, `DbColumn`, `BotDbConnection` in `database.py`; new columns `BotProfile.db_query_enabled`, `BotProfile.db_max_rows`, `BotProfile.db_query_timeout`, `ChatMessage.db_sql`, `ChatMessage.db_row_count`; three new keys in `SETTING_DEFAULTS`.

Laravel owns these tables and already migrated them in stage one. The engine reads them and never writes them, exactly as it does for `KbSource`.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_dbquery_models.py`:

```python
"""The engine's view of tables Laravel owns.

Stage one migrated these. Nothing here creates them; these tests only pin
that the engine's picture matches, because a column named wrongly here fails
silently at runtime rather than loudly at import.
"""
import pytest
from sqlalchemy import select

from database import (BotDbConnection, BotProfile, ChatMessage, DbColumn,
                      DbConnection, DbTable, SETTING_DEFAULTS)


def test_the_four_tables_are_named_as_laravel_named_them():
    assert DbConnection.__tablename__ == "db_connections"
    assert DbTable.__tablename__ == "db_tables"
    assert DbColumn.__tablename__ == "db_columns"
    assert BotDbConnection.__tablename__ == "bot_db_connection"


def test_a_connection_carries_its_master_switch():
    assert hasattr(DbConnection, "is_enabled")
    assert hasattr(DbConnection, "name")
    assert hasattr(DbConnection, "driver")
    assert hasattr(DbConnection, "system_id")


def test_a_table_carries_its_allowlist_flag_and_annotation():
    for column in ("connection_id", "schema_name", "table_name",
                   "description", "is_enabled", "is_present"):
        assert hasattr(DbTable, column), column


def test_a_column_carries_its_shape_and_annotation():
    for column in ("table_id", "column_name", "data_type", "is_primary_key",
                   "foreign_key_target", "description", "ordinal", "is_present"):
        assert hasattr(DbColumn, column), column


def test_a_bot_carries_its_query_settings():
    assert hasattr(BotProfile, "db_query_enabled")
    assert hasattr(BotProfile, "db_max_rows")
    assert hasattr(BotProfile, "db_query_timeout")


def test_a_message_can_record_the_statement_that_answered_it():
    assert hasattr(ChatMessage, "db_sql")
    assert hasattr(ChatMessage, "db_row_count")


def test_the_sql_model_settings_default_to_blank():
    # Blank means "use the bot's own endpoint and model", which is the
    # supported default and needs no configuration at all.
    assert SETTING_DEFAULTS["sql_model_base_url"] == ""
    assert SETTING_DEFAULTS["sql_model_api_key"] == ""
    assert SETTING_DEFAULTS["sql_model_name"] == ""
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_models.py -q`
Expected: FAIL with `ImportError: cannot import name 'BotDbConnection' from 'database'`.

- [ ] **Step 3: Add the columns to the existing models**

In `api-engine/database.py`, inside `class BotProfile`, immediately after the three `web_search_*` columns:

```python
    # The database branch. A router picks between it, the documents and the
    # web; these only say whether it is available and how much it may return.
    db_query_enabled = Column(Boolean, default=False)
    db_max_rows = Column(Integer, default=50)
    db_query_timeout = Column(Integer, default=10)
```

Inside `class ChatMessage`, immediately after the `reasoning` column:

```python
    # The statement that produced this answer, and how many rows it found.
    # An operator auditing a wrong answer needs the query, not a guess at it.
    db_sql = Column(Text, nullable=True)
    db_row_count = Column(Integer, nullable=True)
```

- [ ] **Step 4: Add the four models**

In `api-engine/database.py`, immediately before `class AppSetting`:

```python
class DbConnection(Base):
    """A workspace's own database. Laravel owns this table; we only read it."""
    __tablename__ = "db_connections"

    id = Column(String(36), primary_key=True)
    system_id = Column(String(36), ForeignKey("systems.id", ondelete="CASCADE"), nullable=False)
    name = Column(String(255), nullable=False)
    driver = Column(String(20), nullable=False)
    # Credentials are deliberately not modelled here. The engine never opens
    # this connection; Laravel does, and Laravel holds the password.
    database = Column(String(255), nullable=False)
    # The master switch. A ticked table inside a switched-off connection is
    # not readable, which is the whole point of it.
    is_enabled = Column(Boolean, default=True)


class DbTable(Base):
    __tablename__ = "db_tables"

    id = Column(Integer, primary_key=True, autoincrement=True)
    connection_id = Column(String(36), ForeignKey("db_connections.id", ondelete="CASCADE"), nullable=False)
    schema_name = Column(String(128), nullable=True)
    table_name = Column(String(128), nullable=False)
    # Written by a person who understands the business. This is what the
    # model reads instead of the table itself.
    description = Column(Text, nullable=True)
    is_enabled = Column(Boolean, default=False)
    is_present = Column(Boolean, default=True)


class DbColumn(Base):
    __tablename__ = "db_columns"

    id = Column(Integer, primary_key=True, autoincrement=True)
    table_id = Column(Integer, ForeignKey("db_tables.id", ondelete="CASCADE"), nullable=False)
    column_name = Column(String(128), nullable=False)
    data_type = Column(String(64), nullable=True)
    is_nullable = Column(Boolean, default=True)
    is_primary_key = Column(Boolean, default=False)
    foreign_key_target = Column(String(255), nullable=True)
    description = Column(Text, nullable=True)
    ordinal = Column(Integer, default=0)
    is_present = Column(Boolean, default=True)


class BotDbConnection(Base):
    __tablename__ = "bot_db_connection"

    id = Column(Integer, primary_key=True, autoincrement=True)
    bot_id = Column(String(36), ForeignKey("bot_profiles.id", ondelete="CASCADE"), nullable=False)
    connection_id = Column(String(36), ForeignKey("db_connections.id", ondelete="CASCADE"), nullable=False)
```

- [ ] **Step 5: Add the three settings**

In `api-engine/database.py`, inside `SETTING_DEFAULTS`, after the `web_search_*` entries:

```python
    # Blank means each bot uses its own endpoint and model. Small models are
    # markedly weaker at SQL than at conversation, so an install can point
    # query work somewhere stronger without making every chat cost more.
    "sql_model_base_url": "",
    "sql_model_api_key": "",
    "sql_model_name": "",
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_models.py -q`
Expected: PASS, 7 tests.

- [ ] **Step 7: Run the whole engine suite**

Run: `.venv/Scripts/python.exe -m pytest -q`
Expected: PASS, 221 existing tests plus the 7 new ones.

- [ ] **Step 8: Commit**

```bash
git add api-engine/database.py api-engine/tests/test_dbquery_models.py
git commit -m "feat: the engine's view of connections and their annotated schema"
```

---

### Task 2: The read-only validator

**Files:**
- Create: `api-engine/dbquery/__init__.py`
- Create: `api-engine/dbquery/sql.py`
- Test: `api-engine/tests/test_dbquery_sql.py`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `dbquery.sql.Validation`, a dataclass with `ok: bool`, `sql: str`, `complaint: str`.
  - `dbquery.sql.validate(sql: str, allowed: set[str], driver: str, max_rows: int) -> Validation`. `allowed` holds lowercase qualified names such as `{"orders", "public.orders"}`.
  - `dbquery.sql.strip_comments(sql: str) -> str`.
  - `dbquery.sql.referenced_tables(sql: str) -> set[str]`.

This is the most heavily tested unit in the feature. It is defence in depth beside the allowlist and the read-only account, not a substitute for either.

Create `api-engine/dbquery/__init__.py` as an empty file for now; Task 9 fills it with the orchestrator.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_dbquery_sql.py`:

```python
"""The statement validator.

A model writes these, and a model can be talked into writing anything. Every
case here is a thing that must never reach a customer's database.
"""
import pytest

from dbquery.sql import Validation, referenced_tables, strip_comments, validate

ALLOWED = {"orders", "customers", "public.orders"}


def check(sql, driver="mysql", max_rows=50, allowed=None):
    return validate(sql, ALLOWED if allowed is None else allowed, driver, max_rows)


def test_a_plain_select_passes():
    assert check("SELECT id FROM orders").ok


def test_a_join_between_allowed_tables_passes():
    result = check("SELECT o.id FROM orders o JOIN customers c ON c.id = o.customer_id")
    assert result.ok, result.complaint


def test_a_cte_reaching_a_select_passes():
    result = check("WITH recent AS (SELECT id FROM orders) SELECT * FROM recent")
    assert result.ok, result.complaint


def test_a_cte_name_counts_as_allowed():
    # The statement defines "recent" itself, so it is not an unknown table.
    result = check("WITH recent AS (SELECT id FROM orders) SELECT * FROM recent")
    assert result.ok


@pytest.mark.parametrize("statement", [
    "INSERT INTO orders (id) VALUES (1)",
    "UPDATE orders SET total = 0",
    "DELETE FROM orders",
    "DROP TABLE orders",
    "ALTER TABLE orders ADD c int",
    "CREATE TABLE t (id int)",
    "TRUNCATE TABLE orders",
    "GRANT ALL ON orders TO bob",
    "REVOKE ALL ON orders FROM bob",
    "MERGE INTO orders USING customers ON 1=1",
    "EXEC sp_who",
    "EXECUTE sp_who",
    "CALL do_something()",
    "ATTACH DATABASE 'x' AS y",
    "PRAGMA table_info(orders)",
    "COPY orders TO '/tmp/x'",
])
def test_every_write_and_ddl_verb_is_refused(statement):
    assert not check(statement).ok


def test_select_into_is_refused():
    # SELECT ... INTO writes a new table on several dialects.
    assert not check("SELECT * INTO backup FROM orders").ok


def test_a_second_statement_is_refused():
    assert not check("SELECT id FROM orders; DROP TABLE orders").ok


def test_a_statement_hidden_in_a_line_comment_is_refused():
    sql = "SELECT id FROM orders -- harmless\n; DROP TABLE orders"
    assert not check(sql).ok


def test_a_write_verb_hidden_in_a_block_comment_does_not_sneak_past():
    # Stripping comments first is what makes this a single harmless select
    # rather than something that reads as two statements later on.
    result = check("SELECT id /* DELETE FROM orders */ FROM orders")
    assert result.ok, result.complaint


def test_a_trailing_semicolon_is_fine():
    assert check("SELECT id FROM orders;").ok


def test_a_table_outside_the_allowlist_is_refused():
    result = check("SELECT id FROM salaries")
    assert not result.ok
    assert "salaries" in result.complaint


def test_a_schema_qualified_allowed_table_passes():
    assert check("SELECT id FROM public.orders").ok


def test_a_quoted_identifier_is_recognised():
    assert check('SELECT id FROM "orders"').ok


def test_a_backticked_identifier_is_recognised():
    assert check("SELECT id FROM `orders`").ok


def test_an_alias_after_the_table_is_not_mistaken_for_a_table():
    assert check("SELECT s.id FROM orders AS s").ok


def test_a_column_named_like_a_keyword_still_passes():
    # "created_at" contains "create". Token boundaries, not substrings.
    assert check("SELECT created_at, updated_at FROM orders").ok


def test_a_column_named_delete_count_still_passes():
    assert check("SELECT delete_count FROM orders").ok


def test_an_empty_statement_is_refused():
    assert not check("").ok
    assert not check("   \n  ").ok


def test_something_that_is_not_a_select_is_refused():
    assert not check("SHOW TABLES").ok


def test_a_limit_is_injected_for_mysql():
    result = check("SELECT id FROM orders", driver="mysql", max_rows=25)
    assert result.sql.rstrip().lower().endswith("limit 25")


def test_a_limit_is_injected_for_postgres():
    result = check("SELECT id FROM orders", driver="pgsql", max_rows=25)
    assert result.sql.rstrip().lower().endswith("limit 25")


def test_a_limit_is_injected_for_sqlite():
    result = check("SELECT id FROM orders", driver="sqlite", max_rows=25)
    assert result.sql.rstrip().lower().endswith("limit 25")


def test_sql_server_gets_a_top_instead():
    result = check("SELECT id FROM orders", driver="sqlsrv", max_rows=25)
    assert result.sql.lower().startswith("select top 25")
    assert "limit" not in result.sql.lower()


def test_an_existing_limit_is_left_alone():
    result = check("SELECT id FROM orders LIMIT 5", driver="mysql", max_rows=50)
    assert result.sql.lower().count("limit") == 1
    assert "limit 5" in result.sql.lower()


def test_an_existing_top_is_left_alone():
    result = check("SELECT TOP 5 id FROM orders", driver="sqlsrv", max_rows=50)
    assert result.sql.lower().count("top") == 1


def test_strip_comments_removes_both_kinds():
    assert "secret" not in strip_comments("SELECT 1 -- secret\nFROM t")
    assert "secret" not in strip_comments("SELECT 1 /* secret */ FROM t")


def test_strip_comments_leaves_a_string_literal_alone():
    # A double dash inside quotes is data, not a comment.
    assert "a--b" in strip_comments("SELECT 'a--b' FROM t")


def test_referenced_tables_finds_from_and_join():
    found = referenced_tables("SELECT 1 FROM orders o JOIN customers c ON 1=1")
    assert found == {"orders", "customers"}


def test_referenced_tables_sees_into_a_subquery():
    found = referenced_tables("SELECT 1 FROM (SELECT id FROM orders) x")
    assert "orders" in found


def test_a_subquery_touching_a_forbidden_table_is_refused():
    assert not check("SELECT 1 FROM (SELECT id FROM salaries) x").ok


def test_a_validation_carries_a_complaint_worth_retrying_on():
    result = check("DELETE FROM orders")
    assert isinstance(result, Validation)
    assert result.complaint
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_sql.py -q`
Expected: FAIL with `ModuleNotFoundError: No module named 'dbquery'`.

- [ ] **Step 3: Write the validator**

Create `api-engine/dbquery/__init__.py` empty (one blank line), and create `api-engine/dbquery/sql.py`:

```python
"""Turning a model's statement into one that is safe to run, or refusing it.

A model writes these and a model can be talked into writing anything, so this
assumes nothing about good intent. It is defence in depth beside the
allowlist and the read-only account, never a substitute for either. Laravel
runs the same checks again before it executes.
"""
import re
from dataclasses import dataclass

# Matched as whole tokens. A column called created_at contains "create" and
# must not be mistaken for a CREATE statement.
FORBIDDEN = (
    "insert", "update", "delete", "drop", "alter", "create", "truncate",
    "grant", "revoke", "merge", "exec", "execute", "call", "into",
    "attach", "pragma", "copy",
)

# A string literal can contain anything, including a double dash, so literals
# are taken out of play before comments are looked for.
_LITERAL = re.compile(r"'(?:[^']|'')*'")
_LINE_COMMENT = re.compile(r"--[^\n]*")
_BLOCK_COMMENT = re.compile(r"/\*.*?\*/", re.DOTALL)
_IDENTIFIER = r'(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z_][\w$]*(?:\.[A-Za-z_][\w$]*)*)'
_SOURCE = re.compile(r"\b(?:from|join)\s+(" + _IDENTIFIER + r")", re.IGNORECASE)
_CTE_NAME = re.compile(r"\b(?:with|,)\s+(" + _IDENTIFIER + r")\s+as\s*\(", re.IGNORECASE)


@dataclass
class Validation:
    ok: bool
    sql: str = ""
    complaint: str = ""


def strip_comments(sql: str) -> str:
    """Comments out, string literals preserved.

    This happens before anything else. A second statement hidden in a comment
    must not survive into a later check that only sees one semicolon.
    """
    literals: list[str] = []

    def stash(match: re.Match) -> str:
        literals.append(match.group(0))
        return f"\x00{len(literals) - 1}\x00"

    masked = _LITERAL.sub(stash, sql)
    masked = _BLOCK_COMMENT.sub(" ", masked)
    masked = _LINE_COMMENT.sub(" ", masked)

    return re.sub(r"\x00(\d+)\x00", lambda m: literals[int(m.group(1))], masked)


def _unquote(identifier: str) -> str:
    return identifier.strip().strip('"').strip("`").strip("[]").lower()


def referenced_tables(sql: str) -> set[str]:
    """Every identifier sitting after FROM or JOIN, subqueries included."""
    return {_unquote(match) for match in _SOURCE.findall(strip_comments(sql))}


def _cte_names(sql: str) -> set[str]:
    return {_unquote(match) for match in _CTE_NAME.findall(sql)}


def _has_row_limit(sql: str, driver: str) -> bool:
    lowered = sql.lower()
    if driver == "sqlsrv":
        return re.search(r"^\s*select\s+top\s+\d+", lowered) is not None
    return re.search(r"\blimit\s+\d+", lowered) is not None


def _apply_row_limit(sql: str, driver: str, max_rows: int) -> str:
    if _has_row_limit(sql, driver):
        return sql

    if driver == "sqlsrv":
        return re.sub(r"^(\s*select)\s", rf"\1 TOP {max_rows} ", sql, count=1,
                      flags=re.IGNORECASE)

    return f"{sql.rstrip()} LIMIT {max_rows}"


def validate(sql: str, allowed: set[str], driver: str, max_rows: int) -> Validation:
    cleaned = strip_comments(sql or "").strip()
    cleaned = re.sub(r";\s*$", "", cleaned).strip()

    if not cleaned:
        return Validation(False, complaint="The statement was empty.")

    if ";" in cleaned:
        return Validation(False, complaint="Only one statement may be sent. Remove the semicolon.")

    lowered = cleaned.lower()
    if not re.match(r"^(select|with)\b", lowered):
        return Validation(False, complaint="The statement must begin with SELECT or WITH.")

    if lowered.startswith("with") and not re.search(r"\bselect\b", lowered):
        return Validation(False, complaint="A WITH clause must end in a SELECT.")

    for verb in FORBIDDEN:
        if re.search(rf"\b{verb}\b", lowered):
            return Validation(False, complaint=(
                f"{verb.upper()} is not allowed. Only reading is permitted."))

    permitted = {name.lower() for name in allowed} | _cte_names(cleaned)
    for table in referenced_tables(cleaned):
        if table not in permitted:
            return Validation(False, complaint=(
                f"The table {table} is not one this bot may read. "
                f"Allowed tables: {', '.join(sorted(allowed))}."))

    return Validation(True, sql=_apply_row_limit(cleaned, driver, max_rows))
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_sql.py -q`
Expected: PASS, 45 tests including the parametrized set.

- [ ] **Step 5: Commit**

```bash
git add api-engine/dbquery/__init__.py api-engine/dbquery/sql.py api-engine/tests/test_dbquery_sql.py
git commit -m "feat: refuse every generated statement that is not a plain read"
```

---

### Task 3: The schema prompt block

**Files:**
- Create: `api-engine/dbquery/schema.py`
- Test: `api-engine/tests/test_dbquery_schema.py`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `dbquery.schema.SchemaColumn`, a dataclass with `name: str`, `data_type: str`, `is_primary_key: bool`, `foreign_key_target: str | None`, `description: str`.
  - `dbquery.schema.SchemaTable`, a dataclass with `qualified_name: str`, `description: str`, `columns: list[SchemaColumn]`.
  - `dbquery.schema.build_schema_block(tables: list[SchemaTable]) -> str`.
  - `dbquery.schema.build_table_summary(tables: list[SchemaTable]) -> str`, the shorter form the router sees.
  - `dbquery.schema.allowed_names(tables: list[SchemaTable]) -> set[str]`.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_dbquery_schema.py`:

```python
"""What the model is told about a database.

It never sees the tables, only these sentences. Everything the feature is
worth rests on them arriving intact and on nothing else arriving at all.
"""
from dbquery.schema import (SchemaColumn, SchemaTable, allowed_names,
                            build_schema_block, build_table_summary)


def column(name, description="", pk=False, fk=None, data_type="integer"):
    return SchemaColumn(name=name, data_type=data_type, is_primary_key=pk,
                        foreign_key_target=fk, description=description)


def orders():
    return SchemaTable(
        qualified_name="orders",
        description="Orders placed through the web shop. One row per order.",
        columns=[
            column("id", pk=True),
            column("customer_id", fk="customers.id", description="Who placed it."),
            column("amt_ttl", data_type="numeric",
                   description="Total charged, including tax."),
        ],
    )


def customers():
    return SchemaTable(
        qualified_name="customers",
        description="Everyone who has ever ordered.",
        columns=[column("id", pk=True), column("full_name", data_type="text")],
    )


def test_the_table_name_and_its_description_both_appear():
    block = build_schema_block([orders()])

    assert "orders" in block
    assert "One row per order" in block


def test_every_column_appears_with_its_type():
    block = build_schema_block([orders()])

    assert "amt_ttl" in block
    assert "numeric" in block


def test_a_column_description_appears():
    # This is the whole point: amt_ttl means nothing without the sentence.
    block = build_schema_block([orders()])

    assert "Total charged, including tax." in block


def test_a_primary_key_is_marked():
    assert "primary key" in build_schema_block([orders()]).lower()


def test_a_foreign_key_is_rendered_as_a_relationship():
    # A model told that orders.customer_id points at customers.id writes the
    # join without being asked for one.
    block = build_schema_block([orders()])

    assert "customers.id" in block


def test_two_tables_both_appear():
    block = build_schema_block([orders(), customers()])

    assert "orders" in block
    assert "customers" in block
    assert "full_name" in block


def test_no_tables_gives_an_empty_block():
    assert build_schema_block([]) == ""


def test_the_router_summary_is_names_and_descriptions_only():
    summary = build_table_summary([orders()])

    assert "orders" in summary
    assert "One row per order" in summary
    # Column detail is generation's business, not the router's.
    assert "amt_ttl" not in summary


def test_a_table_with_no_description_still_lists_in_the_summary():
    bare = SchemaTable(qualified_name="audit_log", description="", columns=[])

    assert "audit_log" in build_table_summary([bare])


def test_allowed_names_are_lowercase_qualified_names():
    names = allowed_names([orders(), customers()])

    assert names == {"orders", "customers"}


def test_allowed_names_lowercase_a_mixed_case_table():
    mixed = SchemaTable(qualified_name="Public.Orders", description="", columns=[])

    assert allowed_names([mixed]) == {"public.orders"}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_schema.py -q`
Expected: FAIL with `ModuleNotFoundError: No module named 'dbquery.schema'`.

- [ ] **Step 3: Write the module**

Create `api-engine/dbquery/schema.py`:

```python
"""The database, as the model is told about it.

The model never sees a table, only these sentences. A column called amt_ttl
is worth nothing until somebody writes down that it is the total charged
including tax, which is why the schema editor exists at all.
"""
from dataclasses import dataclass


@dataclass
class SchemaColumn:
    name: str
    data_type: str
    is_primary_key: bool
    foreign_key_target: str | None
    description: str


@dataclass
class SchemaTable:
    qualified_name: str
    description: str
    columns: list[SchemaColumn]


def allowed_names(tables: list[SchemaTable]) -> set[str]:
    """The allowlist the validator checks a statement against."""
    return {table.qualified_name.lower() for table in tables}


def build_table_summary(tables: list[SchemaTable]) -> str:
    """Names and descriptions only, for the router.

    The router is deciding whether this database could answer at all. Column
    detail would triple the prompt without improving that decision.
    """
    lines = []
    for table in tables:
        description = table.description.strip() if table.description else ""
        lines.append(f"- {table.qualified_name}: {description}" if description
                     else f"- {table.qualified_name}")

    return "\n".join(lines)


def build_schema_block(tables: list[SchemaTable]) -> str:
    """Everything generation needs: shape, keys, and the human explanation."""
    if not tables:
        return ""

    parts = []
    for table in tables:
        parts.append(f"TABLE {table.qualified_name}")
        if table.description and table.description.strip():
            parts.append(f"  {table.description.strip()}")

        for col in table.columns:
            notes = [col.data_type or "unknown type"]
            if col.is_primary_key:
                notes.append("primary key")
            if col.foreign_key_target:
                notes.append(f"references {col.foreign_key_target}")

            line = f"  - {col.name} ({', '.join(notes)})"
            if col.description and col.description.strip():
                line += f": {col.description.strip()}"
            parts.append(line)

        parts.append("")

    return "\n".join(parts).strip()
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_schema.py -q`
Expected: PASS, 11 tests.

- [ ] **Step 5: Commit**

```bash
git add api-engine/dbquery/schema.py api-engine/tests/test_dbquery_schema.py
git commit -m "feat: describe a database to a model in an operator's own words"
```

---

### Task 4: The router prompt and its parser

**Files:**
- Create: `api-engine/dbquery/routing.py`
- Test: `api-engine/tests/test_dbquery_routing.py`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `dbquery.routing.VERDICTS`, the tuple `("database", "documents", "web", "none")`.
  - `dbquery.routing.build_router_prompt(table_summary: str, collection_names: list[str], web_enabled: bool) -> str`.
  - `dbquery.routing.parse_verdict(answer: str) -> str | None`, returning `None` when the answer is unparseable.

The parser is deliberately forgiving, because a small model will wrap its answer in a sentence. Unparseable is not an error; it falls through to the chain that ships today.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_dbquery_routing.py`:

```python
"""Reading one word out of whatever a small model actually said.

Unparseable is not an error here. It is the signal to fall through to the
deterministic documents-then-web chain, which is what makes a weak router
unable to make anything worse.
"""
import pytest

from dbquery.routing import VERDICTS, build_router_prompt, parse_verdict


def test_the_four_verdicts_are_fixed():
    assert VERDICTS == ("database", "documents", "web", "none")


@pytest.mark.parametrize("verdict", VERDICTS)
def test_a_bare_verdict_parses(verdict):
    assert parse_verdict(verdict) == verdict


def test_case_and_whitespace_do_not_matter():
    assert parse_verdict("  DATABASE \n") == "database"


def test_a_verdict_wrapped_in_a_sentence_parses():
    assert parse_verdict("I think the answer is: database.") == "database"


def test_trailing_punctuation_is_ignored():
    assert parse_verdict("documents!") == "documents"


def test_a_verdict_in_quotes_parses():
    assert parse_verdict('"web"') == "web"


def test_two_verdicts_in_one_answer_is_unparseable():
    # It could not decide, so neither can we.
    assert parse_verdict("either database or documents") is None


def test_the_same_verdict_repeated_still_parses():
    assert parse_verdict("database. database.") == "database"


def test_an_unknown_word_is_unparseable():
    assert parse_verdict("sql") is None


def test_an_empty_answer_is_unparseable():
    assert parse_verdict("") is None
    assert parse_verdict("   ") is None
    assert parse_verdict(None) is None


def test_a_word_containing_a_verdict_does_not_count():
    assert parse_verdict("databases") is None


def test_the_prompt_carries_the_table_summary():
    prompt = build_router_prompt("- orders: web shop orders", [], False)

    assert "orders: web shop orders" in prompt


def test_the_prompt_names_the_attached_collections():
    prompt = build_router_prompt("", ["Refund policy", "Product notes"], False)

    assert "Refund policy" in prompt
    assert "Product notes" in prompt


def test_the_prompt_offers_web_only_when_the_bot_has_it():
    with_web = build_router_prompt("", [], True)
    without = build_router_prompt("", [], False)

    assert "web" in with_web
    assert "web" not in without.replace("web shop", "")


def test_the_prompt_asks_for_one_word():
    prompt = build_router_prompt("- orders:", ["Docs"], True)

    assert "one word" in prompt.lower()
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_routing.py -q`
Expected: FAIL with `ModuleNotFoundError: No module named 'dbquery.routing'`.

- [ ] **Step 3: Write the module**

Create `api-engine/dbquery/routing.py`:

```python
"""Deciding which source, if any, should answer.

The web search work rejected model-decided routing because it costs a round
trip and small models classify badly. That held while the choice was binary
and a word list could stand in. It does not survive a third source: no
vocabulary separates "what is your returns policy", which is a document,
from "has my return been processed", which is a row.

The cost is accepted. The risk is answered by the caller, where anything
other than a clean verdict falls through to the deterministic chain.
"""
import re

VERDICTS = ("database", "documents", "web", "none")


def build_router_prompt(table_summary: str, collection_names: list[str],
                        web_enabled: bool) -> str:
    sources = ["database", "documents"]
    if web_enabled:
        sources.append("web")
    sources.append("none")

    parts = [
        "You route a visitor's message to whichever source can answer it.",
        "",
        "database: live records in the operator's own database. Choose this "
        "for anything about a specific record, a count, a total, a status, or "
        "anything that changes as business happens. The tables available are:",
        table_summary or "  (no tables are available)",
        "",
        "documents: written material the operator uploaded. Choose this for "
        "policies, procedures, descriptions and explanations. The collections "
        "available are:",
        "\n".join(f"- {name}" for name in collection_names) or "  (no collections are available)",
        "",
    ]

    if web_enabled:
        parts += [
            "web: a public web search, for questions about the wider world "
            "that neither of the above covers.",
            "",
        ]

    parts += [
        "none: no source is needed. Choose this for greetings, small talk, "
        "thanks, and anything answerable from the conversation so far.",
        "",
        f"Reply with exactly one word from: {', '.join(sources)}.",
        "Reply with one word and nothing else.",
    ]

    return "\n".join(parts)


def parse_verdict(answer: str | None) -> str | None:
    """One of the four verdicts, or None when the answer was not one of them.

    Forgiving on purpose. A small model will wrap its answer in a sentence,
    and refusing that would throw away a decision it actually made. Two
    different verdicts in one answer means it did not decide, so neither
    do we.
    """
    if not answer or not answer.strip():
        return None

    words = set(re.findall(r"[a-z]+", answer.lower()))
    found = [verdict for verdict in VERDICTS if verdict in words]

    return found[0] if len(found) == 1 else None
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_routing.py -q`
Expected: PASS, 18 tests including the parametrized set.

- [ ] **Step 5: Commit**

```bash
git add api-engine/dbquery/routing.py api-engine/tests/test_dbquery_routing.py
git commit -m "feat: route a message to the source that can answer it"
```

---

### Task 5: Query results and their prompt block

**Files:**
- Create: `api-engine/dbquery/result.py`
- Create: `api-engine/dbquery/context.py`
- Test: `api-engine/tests/test_dbquery_context.py`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `dbquery.result.QueryResult`, a dataclass with `columns: list[str]`, `rows: list[list]`, `row_count: int`, `elapsed_ms: int`.
  - `dbquery.context.fit_rows_to_budget(result: QueryResult, budget: int) -> QueryResult`.
  - `dbquery.context.build_database_context_block(connection_name: str, result: QueryResult, queried_on: str) -> str`.

`result.py` is its own module for the same reason `websearch/result.py` is: so `context.py` and `portal.py` can import the type without a circular import once `__init__.py` imports them both.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_dbquery_context.py`:

```python
"""Rows on their way into a prompt.

Two jobs. Keep the block inside the budget the knowledge base already
respects, and make sure the model presents the numbers as something it just
read rather than something it remembers.
"""
from dbquery.context import build_database_context_block, fit_rows_to_budget
from dbquery.result import QueryResult


def result(rows=None, columns=None):
    return QueryResult(
        columns=columns or ["id", "status", "total"],
        rows=rows if rows is not None else [[1, "shipped", "438.00"],
                                            [2, "pending", "1299.00"]],
        row_count=len(rows) if rows is not None else 2,
        elapsed_ms=12,
    )


def test_the_block_carries_the_column_names():
    block = build_database_context_block("Shop database", result(), "2026-09-14")

    assert "status" in block
    assert "total" in block


def test_the_block_carries_every_value():
    block = build_database_context_block("Shop database", result(), "2026-09-14")

    assert "shipped" in block
    assert "1299.00" in block


def test_the_block_names_the_connection():
    block = build_database_context_block("Shop database", result(), "2026-09-14")

    assert "Shop database" in block


def test_the_block_says_the_data_is_live_and_when_it_was_read():
    # Without this the model reports a queried figure as something it
    # remembers, which reads to a visitor as a guess.
    block = build_database_context_block("Shop database", result(), "2026-09-14")

    assert "2026-09-14" in block
    assert "live" in block.lower()


def test_no_rows_is_a_real_answer_not_an_empty_block():
    # "Is there an order 88421" deserves "the query found nothing", which is
    # a different statement from "I have no information".
    block = build_database_context_block("Shop database", result(rows=[]), "2026-09-14")

    assert block
    assert "no rows" in block.lower() or "nothing" in block.lower()


def test_the_budget_drops_whole_rows():
    wide = [[i, "x" * 100, "y"] for i in range(20)]
    trimmed = fit_rows_to_budget(result(rows=wide), 400)

    assert len(trimmed.rows) < 20
    assert all(len(row) == 3 for row in trimmed.rows)


def test_the_first_row_is_always_kept():
    # One long row beats no rows, matching fit_to_budget in kb/retrieval.py.
    huge = [["x" * 5000, "y", "z"]]
    trimmed = fit_rows_to_budget(result(rows=huge), 100)

    assert len(trimmed.rows) == 1


def test_trimming_records_how_many_rows_the_query_actually_found():
    wide = [[i, "x" * 100, "y"] for i in range(20)]
    trimmed = fit_rows_to_budget(result(rows=wide), 400)

    # The model must not be told six when the answer to "how many" is twenty.
    assert trimmed.row_count == 20


def test_trimming_keeps_the_columns():
    trimmed = fit_rows_to_budget(result(), 10_000)

    assert trimmed.columns == ["id", "status", "total"]


def test_an_empty_result_survives_trimming():
    trimmed = fit_rows_to_budget(result(rows=[]), 1000)

    assert trimmed.rows == []
    assert trimmed.row_count == 0


def test_the_block_says_when_it_shows_fewer_rows_than_were_found():
    partial = QueryResult(columns=["id"], rows=[[1]], row_count=97, elapsed_ms=5)
    block = build_database_context_block("Shop database", partial, "2026-09-14")

    assert "97" in block
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_context.py -q`
Expected: FAIL with `ModuleNotFoundError: No module named 'dbquery.result'`.

- [ ] **Step 3: Write the result type**

Create `api-engine/dbquery/result.py`:

```python
"""What came back from a query.

Its own module so context.py and portal.py can both import it without a
circular import once the package's __init__ imports them.
"""
from dataclasses import dataclass, field


@dataclass
class QueryResult:
    columns: list[str] = field(default_factory=list)
    rows: list[list] = field(default_factory=list)
    # How many rows the query matched, which is not always how many are in
    # rows: the budget may have dropped some. "How many orders" is answered
    # from this, never from len(rows).
    row_count: int = 0
    elapsed_ms: int = 0
```

- [ ] **Step 4: Write the context module**

Create `api-engine/dbquery/context.py`:

```python
"""Rows rendered for a prompt, inside the budget the rest of the system uses."""
from dbquery.result import QueryResult

LIVE_NOTICE = (
    "The rows below are live data, read from the operator's own database at "
    "the moment this question was asked, on {date}, from the connection "
    "named \"{name}\". Answer from these rows only. Do not invent a row that "
    "is not here, and do not present a figure as remembered when it was read."
)


def _render_row(row: list) -> str:
    return " | ".join("" if value is None else str(value) for value in row)


def fit_rows_to_budget(result: QueryResult, budget: int) -> QueryResult:
    """As many whole rows as fit, keeping the true match count.

    The first row is kept whatever its size, matching fit_to_budget in
    kb/retrieval.py: one long row beats none at all.
    """
    kept: list[list] = []
    used = 0

    for row in result.rows:
        rendered = len(_render_row(row))
        if kept and used + rendered > budget:
            break
        kept.append(row)
        used += rendered

    return QueryResult(columns=result.columns, rows=kept,
                       row_count=result.row_count, elapsed_ms=result.elapsed_ms)


def build_database_context_block(connection_name: str, result: QueryResult,
                                 queried_on: str) -> str:
    parts = [LIVE_NOTICE.format(date=queried_on, name=connection_name), ""]

    if not result.rows:
        parts.append("The query ran and matched no rows at all.")
        return "\n".join(parts)

    parts.append(" | ".join(result.columns))
    parts.append("-|-".join("-" for _ in result.columns))
    parts.extend(_render_row(row) for row in result.rows)

    if result.row_count > len(result.rows):
        parts.append("")
        parts.append(
            f"The query matched {result.row_count} rows in total. "
            f"The first {len(result.rows)} are shown above.")

    return "\n".join(parts)
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_context.py -q`
Expected: PASS, 11 tests.

- [ ] **Step 6: Commit**

```bash
git add api-engine/dbquery/result.py api-engine/dbquery/context.py api-engine/tests/test_dbquery_context.py
git commit -m "feat: render queried rows as live data inside the prompt budget"
```

---

### Task 6: A non-streaming completion on the adapter

**Files:**
- Modify: `api-engine/llm_adapter.py`
- Test: `api-engine/tests/test_llm_complete.py`

**Interfaces:**
- Consumes: nothing.
- Produces: `LLMAdapter.complete(base_url: str, api_key: str, model_name: str, system_prompt: str, user_message: str, temperature: float = 0.0, max_tokens: int = 512, transport=None) -> str`. Returns the assistant's message content, or `""` on any failure.

The router and the generator both need a whole answer rather than a stream, and neither wants creativity, hence the zero default temperature.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_llm_complete.py`:

```python
"""One-shot completion, for the router and the SQL generator.

Neither wants a stream and neither wants creativity, which is why the
temperature defaults to zero.
"""
import httpx
import pytest

from llm_adapter import LLMAdapter


def responder(payload, capture=None, status=200):
    def handler(request: httpx.Request) -> httpx.Response:
        if capture is not None:
            capture["url"] = str(request.url)
            capture["headers"] = dict(request.headers)
            capture["body"] = request.read().decode()
        return httpx.Response(status, json=payload)
    return handler


ANSWER = {"choices": [{"message": {"content": "database"}}]}


@pytest.mark.asyncio
async def test_the_content_comes_back():
    answer = await LLMAdapter.complete(
        "http://localhost:11434/v1", "", "llama3.2", "route this", "hello",
        transport=httpx.MockTransport(responder(ANSWER)))

    assert answer == "database"


@pytest.mark.asyncio
async def test_the_request_does_not_ask_for_a_stream():
    seen = {}
    await LLMAdapter.complete(
        "http://localhost:11434/v1", "", "llama3.2", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert '"stream": false' in seen["body"].replace(" ", " ")


@pytest.mark.asyncio
async def test_the_endpoint_is_normalised_like_the_streaming_one():
    seen = {}
    await LLMAdapter.complete(
        "http://localhost:11434/v1", "", "llama3.2", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert seen["url"].endswith("/v1/chat/completions")


@pytest.mark.asyncio
async def test_a_key_is_sent_as_a_bearer_token():
    seen = {}
    await LLMAdapter.complete(
        "http://x/v1", "sk-abc", "m", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert seen["headers"]["authorization"] == "Bearer sk-abc"


@pytest.mark.asyncio
async def test_no_key_sends_no_authorization_header():
    seen = {}
    await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert "authorization" not in seen["headers"]


@pytest.mark.asyncio
async def test_the_temperature_defaults_to_zero():
    seen = {}
    await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u",
        transport=httpx.MockTransport(responder(ANSWER, seen)))

    assert '"temperature": 0' in seen["body"] or '"temperature":0' in seen["body"]


@pytest.mark.asyncio
async def test_an_http_error_returns_empty_rather_than_raising():
    # Every caller treats empty as "fall through", so a failure here must
    # never escape into a visitor's conversation.
    answer = await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u",
        transport=httpx.MockTransport(responder({"error": "nope"}, status=500)))

    assert answer == ""


@pytest.mark.asyncio
async def test_a_malformed_body_returns_empty():
    def handler(request):
        return httpx.Response(200, text="not json")

    answer = await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u", transport=httpx.MockTransport(handler))

    assert answer == ""


@pytest.mark.asyncio
async def test_a_missing_choices_key_returns_empty():
    answer = await LLMAdapter.complete(
        "http://x/v1", "", "m", "s", "u",
        transport=httpx.MockTransport(responder({"id": "x"})))

    assert answer == ""
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `.venv/Scripts/python.exe -m pytest tests/test_llm_complete.py -q`
Expected: FAIL with `AttributeError: type object 'LLMAdapter' has no attribute 'complete'`.

- [ ] **Step 3: Write the method**

In `api-engine/llm_adapter.py`, add this classmethod to `LLMAdapter`, immediately after `stream_chat`:

```python
    @classmethod
    async def complete(
        cls,
        base_url: str,
        api_key: str,
        model_name: str,
        system_prompt: str,
        user_message: str,
        temperature: float = 0.0,
        max_tokens: int = 512,
        transport=None,
    ) -> str:
        """A whole answer in one call, for the router and the SQL generator.

        Neither wants a stream and neither wants creativity. Every failure
        returns an empty string, because every caller reads empty as "fall
        through to what would have happened anyway".
        """
        headers = {"Content-Type": "application/json"}
        if api_key and api_key.strip():
            headers["Authorization"] = f"Bearer {api_key.strip()}"

        messages = []
        if system_prompt and system_prompt.strip():
            messages.append({"role": "system", "content": system_prompt.strip()})
        messages.append({"role": "user", "content": user_message})

        payload = {
            "model": model_name,
            "messages": messages,
            "stream": False,
            "temperature": temperature,
            "max_tokens": max_tokens,
        }

        try:
            async with httpx.AsyncClient(timeout=30.0, transport=transport) as client:
                response = await client.post(
                    cls._normalize_endpoint(base_url), headers=headers, json=payload)
                response.raise_for_status()
                body = response.json()

            return (body["choices"][0]["message"]["content"] or "").strip()
        except Exception as error:
            print(f"[LLM] Completion failed, falling through: {error}")
            return ""
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `.venv/Scripts/python.exe -m pytest tests/test_llm_complete.py -q`
Expected: PASS, 9 tests.

- [ ] **Step 5: Commit**

```bash
git add api-engine/llm_adapter.py api-engine/tests/test_llm_complete.py
git commit -m "feat: a one-shot completion for routing and SQL generation"
```

---

### Task 7: Laravel runs the query

**Files:**
- Create: `admin-laravel/app/Services/Schema/SqlGuard.php`
- Create: `admin-laravel/app/Services/Schema/DbQueryRunner.php`
- Create: `admin-laravel/app/Http/Middleware/EnsureEngineToken.php`
- Create: `admin-laravel/app/Http/Controllers/InternalQueryController.php`
- Create: `admin-laravel/routes/internal.php`
- Modify: `admin-laravel/bootstrap/app.php`
- Modify: `admin-laravel/.env.example`
- Test: `admin-laravel/tests/Feature/InternalQueryTest.php`

**Interfaces:**
- Consumes: `DbConnection`, `DbTable`, `ProbeConnection` from stage one.
- Produces:
  - `App\Services\Schema\SqlGuard::check(string $sql, array $allowed): ?string` returning a complaint, or `null` when the statement is acceptable.
  - `App\Services\Schema\DbQueryRunner::run(DbConnection $connection, string $sql, int $maxRows, int $timeout): array` returning `['ok' => bool, 'columns' => [], 'rows' => [], 'row_count' => int, 'elapsed_ms' => int, 'message' => string]`.
  - Route `POST /internal/db/query`, named `internal.db.query`, guarded by header `X-Portal-Token`.

The engine already validated. This validates again anyway, because the engine is a caller like any other and its validation is not evidence. The route sits in its own routes file with no session and no CSRF, rather than inside the web group with an exclusion list.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/InternalQueryTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use App\Services\Schema\ProbeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalQueryTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.portal_internal_token' => 'test-portal-token']);

        $this->path = tempnam(sys_get_temp_dir(), 'query') . '.sqlite';
        touch($this->path);

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function shop(bool $connectionEnabled = true, bool $ordersEnabled = true): DbConnection
    {
        $connection = DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Shop',
            'driver' => 'sqlite', 'database' => $this->path,
            'is_enabled' => $connectionEnabled,
        ]);

        $probe = ProbeConnection::open($connection);
        $probe->statement('CREATE TABLE orders (id integer primary key, status text, total numeric)');
        $probe->statement("INSERT INTO orders (id, status, total) VALUES (1, 'shipped', 438.0)");
        $probe->statement("INSERT INTO orders (id, status, total) VALUES (2, 'pending', 1299.0)");
        $probe->statement('CREATE TABLE salaries (id integer primary key, amount numeric)');
        $probe->statement("INSERT INTO salaries (id, amount) VALUES (1, 99999)");

        $table = DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'orders',
            'is_enabled' => $ordersEnabled, 'is_present' => true,
        ]);
        DbColumn::create(['table_id' => $table->id, 'column_name' => 'id', 'ordinal' => 1]);

        // Never ticked, so it is not on the allowlist.
        DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'salaries',
            'is_enabled' => false, 'is_present' => true,
        ]);

        return $connection;
    }

    private function ask(array $body, ?string $token = 'test-portal-token')
    {
        $headers = $token === null ? [] : ['X-Portal-Token' => $token];

        return $this->withHeaders($headers)->postJson('/internal/db/query', $body);
    }

    public function test_a_select_returns_columns_and_rows(): void
    {
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1',
            'sql' => 'SELECT id, status FROM orders LIMIT 50',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertTrue($response->json('ok'));
        $this->assertSame(['id', 'status'], $response->json('columns'));
        $this->assertSame(2, $response->json('row_count'));
        $this->assertSame('shipped', $response->json('rows.0.1'));
    }

    public function test_a_missing_token_is_refused(): void
    {
        $this->shop();

        $this->ask(['connection_id' => 'dbc_1', 'sql' => 'SELECT id FROM orders',
                    'max_rows' => 50, 'timeout' => 10], null)
            ->assertStatus(401);
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $this->shop();

        $this->ask(['connection_id' => 'dbc_1', 'sql' => 'SELECT id FROM orders',
                    'max_rows' => 50, 'timeout' => 10], 'not-the-token')
            ->assertStatus(401);
    }

    public function test_a_write_is_refused_even_though_the_engine_validated(): void
    {
        // The engine is a caller like any other. Its validation is not
        // evidence, so every rule runs again here.
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'DELETE FROM orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertSame(2, ProbeConnection::open(DbConnection::find('dbc_1'))
            ->table('orders')->count());
    }

    public function test_a_second_statement_is_refused(): void
    {
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1',
            'sql' => 'SELECT id FROM orders; DROP TABLE orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
    }

    public function test_a_table_nobody_ticked_is_refused(): void
    {
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'SELECT amount FROM salaries',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('salaries', $response->json('message'));
    }

    public function test_a_switched_off_connection_refuses_everything(): void
    {
        // The master switch is a gate the query path has to honour, not just
        // a label on a screen.
        $this->shop(connectionEnabled: false);

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'SELECT id FROM orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
    }

    public function test_a_table_switched_off_refuses_that_table(): void
    {
        $this->shop(ordersEnabled: false);

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'SELECT id FROM orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
    }

    public function test_an_unknown_connection_is_refused(): void
    {
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_nope', 'sql' => 'SELECT id FROM orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
    }

    public function test_a_broken_statement_reports_rather_than_throws(): void
    {
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'SELECT nosuchcolumn FROM orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertNotSame('', $response->json('message'));
    }

    public function test_the_row_cap_is_enforced_here_too(): void
    {
        // The engine injects a limit, but a caller that did not is still
        // capped rather than trusted.
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'SELECT id FROM orders',
            'max_rows' => 1, 'timeout' => 10,
        ])->assertOk();

        $this->assertTrue($response->json('ok'));
        $this->assertCount(1, $response->json('rows'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=InternalQueryTest`
Expected: FAIL with a 404, because the route does not exist.

- [ ] **Step 3: Write the guard**

Create `admin-laravel/app/Services/Schema/SqlGuard.php`:

```php
<?php

namespace App\Services\Schema;

/**
 * The same read-only rules the engine applies, applied again here.
 *
 * Deliberate duplication. The engine is a caller like any other and its
 * validation is not evidence, so nothing reaches a customer's database on the
 * strength of a check that happened somewhere else.
 */
final class SqlGuard
{
    /** Matched as whole words. A column named created_at is not a CREATE. */
    private const FORBIDDEN = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'grant', 'revoke', 'merge', 'exec', 'execute', 'call', 'into',
        'attach', 'pragma', 'copy',
    ];

    /**
     * @param list<string> $allowed lowercase qualified table names
     * @return string|null a complaint, or null when the statement is acceptable
     */
    public static function check(string $sql, array $allowed): ?string
    {
        $cleaned = trim(preg_replace('/;\s*$/', '', self::stripComments($sql)));

        if ($cleaned === '') {
            return 'The statement was empty.';
        }

        if (str_contains($cleaned, ';')) {
            return 'Only one statement may be sent.';
        }

        if (!preg_match('/^(select|with)\b/i', $cleaned)) {
            return 'The statement must begin with SELECT or WITH.';
        }

        foreach (self::FORBIDDEN as $verb) {
            if (preg_match('/\b' . $verb . '\b/i', $cleaned)) {
                return strtoupper($verb) . ' is not allowed. Only reading is permitted.';
            }
        }

        $permitted = array_map('strtolower', $allowed);
        foreach (self::cteNames($cleaned) as $name) {
            $permitted[] = $name;
        }

        foreach (self::referencedTables($cleaned) as $table) {
            if (!in_array($table, $permitted, true)) {
                return "The table {$table} is not one this bot may read.";
            }
        }

        return null;
    }

    /** Comments out, string literals preserved. */
    public static function stripComments(string $sql): string
    {
        $literals = [];
        $masked = preg_replace_callback("/'(?:[^']|'')*'/", function ($match) use (&$literals) {
            $literals[] = $match[0];

            return "\x00" . (count($literals) - 1) . "\x00";
        }, $sql);

        $masked = preg_replace('/\/\*.*?\*\//s', ' ', $masked);
        $masked = preg_replace('/--[^\n]*/', ' ', $masked);

        return preg_replace_callback("/\x00(\d+)\x00/", fn ($m) => $literals[(int) $m[1]], $masked);
    }

    /** @return list<string> */
    public static function referencedTables(string $sql): array
    {
        preg_match_all('/\b(?:from|join)\s+(' . self::IDENTIFIER . ')/i',
            self::stripComments($sql), $matches);

        return array_values(array_unique(array_map(
            fn ($name) => self::unquote($name), $matches[1])));
    }

    private const IDENTIFIER = '(?:"[^"]+"|`[^`]+`|\[[^\]]+\]|[A-Za-z_][\w$]*(?:\.[A-Za-z_][\w$]*)*)';

    /** @return list<string> */
    private static function cteNames(string $sql): array
    {
        preg_match_all('/\b(?:with|,)\s+(' . self::IDENTIFIER . ')\s+as\s*\(/i', $sql, $matches);

        return array_map(fn ($name) => self::unquote($name), $matches[1]);
    }

    private static function unquote(string $identifier): string
    {
        return strtolower(trim(trim(trim(trim($identifier), '"'), '`'), '[]'));
    }
}
```

- [ ] **Step 4: Write the runner**

Create `admin-laravel/app/Services/Schema/DbQueryRunner.php`:

```php
<?php

namespace App\Services\Schema;

use App\Models\DbConnection;
use Throwable;

/**
 * Runs one read-only statement against a workspace's own database.
 *
 * Everything here refuses rather than throws. A visitor must never see a
 * stack trace because a database was down or a model wrote nonsense.
 */
final class DbQueryRunner
{
    /**
     * @return array{ok: bool, columns: list<string>, rows: list<list>,
     *               row_count: int, elapsed_ms: int, message: string}
     */
    public static function run(DbConnection $connection, string $sql,
                               int $maxRows, int $timeout): array
    {
        if (!$connection->is_enabled) {
            return self::refuse("The connection {$connection->name} is switched off.");
        }

        // Both switches have to agree. A ticked table inside a switched-off
        // connection is not readable, and a table the database no longer has
        // is not readable either.
        $allowed = $connection->readableTables()->get()
            ->map(fn ($table) => strtolower($table->qualifiedName()))
            ->all();

        if (!$allowed) {
            return self::refuse('No tables on this connection are enabled for reading.');
        }

        $complaint = SqlGuard::check($sql, $allowed);
        if ($complaint !== null) {
            return self::refuse($complaint);
        }

        $started = microtime(true);

        try {
            $probe = ProbeConnection::open($connection);
            self::applyTimeout($probe, $connection->driver, $timeout);
            $rows = $probe->select($sql);
        } catch (Throwable $e) {
            return self::refuse(substr($e->getMessage(), 0, 500));
        }

        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $capped = array_slice($rows, 0, $maxRows);

        return [
            'ok' => true,
            'columns' => $capped ? array_keys((array) $capped[0]) : [],
            'rows' => array_map(fn ($row) => array_values((array) $row), $capped),
            'row_count' => count($rows),
            'elapsed_ms' => $elapsed,
            'message' => '',
        ];
    }

    private static function applyTimeout($probe, string $driver, int $timeout): void
    {
        try {
            match ($driver) {
                'mysql' => $probe->statement("SET STATEMENT max_statement_time={$timeout} FOR SELECT 1"),
                'pgsql' => $probe->statement('SET statement_timeout = ' . ($timeout * 1000)),
                'sqlsrv' => $probe->getPdo()->setAttribute(\PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $timeout),
                default => null,
            };
        } catch (Throwable $e) {
            // An older MySQL or a restricted account may refuse to set it.
            // A missing timeout is worse than none of the feature, so carry on.
            \Illuminate\Support\Facades\Log::info(
                "Query timeout not set for {$driver}: " . $e->getMessage());
        }
    }

    private static function refuse(string $message): array
    {
        return ['ok' => false, 'columns' => [], 'rows' => [],
                'row_count' => 0, 'elapsed_ms' => 0, 'message' => $message];
    }
}
```

- [ ] **Step 5: Write the token middleware and the controller**

Create `admin-laravel/app/Http/Middleware/EnsureEngineToken.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The engine calling the portal, which is the one direction that did not
 * exist before.
 *
 * A different secret from ENGINE_ADMIN_TOKEN on purpose. A secret that
 * authenticates one direction should not authenticate the other, or
 * compromising the engine would hand over the portal with it.
 */
class EnsureEngineToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('app.portal_internal_token');

        if (!$expected) {
            abort(503, 'PORTAL_INTERNAL_TOKEN is not set on the portal.');
        }

        if (!hash_equals($expected, (string) $request->header('X-Portal-Token'))) {
            abort(401, 'Invalid portal token.');
        }

        return $next($request);
    }
}
```

Create `admin-laravel/app/Http/Controllers/InternalQueryController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\DbConnection;
use App\Services\Schema\DbQueryRunner;
use Illuminate\Http\Request;

class InternalQueryController extends Controller
{
    public function query(Request $request)
    {
        $validated = $request->validate([
            'connection_id' => ['required', 'string', 'max:36'],
            'sql' => ['required', 'string', 'max:8000'],
            'max_rows' => ['required', 'integer', 'min:1', 'max:1000'],
            'timeout' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $connection = DbConnection::find($validated['connection_id']);

        if (!$connection) {
            return response()->json([
                'ok' => false, 'columns' => [], 'rows' => [], 'row_count' => 0,
                'elapsed_ms' => 0, 'message' => 'No such connection.',
            ]);
        }

        return response()->json(DbQueryRunner::run(
            $connection, $validated['sql'],
            (int) $validated['max_rows'], (int) $validated['timeout']));
    }
}
```

- [ ] **Step 6: Register the route and the config key**

Create `admin-laravel/routes/internal.php`:

```php
<?php

use App\Http\Controllers\InternalQueryController;
use Illuminate\Support\Facades\Route;

// The engine calling in. No session, no CSRF, no cookies: this is a
// service-to-service call carrying its own shared secret, and putting it in
// its own file is cleaner than excluding it from the web group's middleware.
Route::middleware('engine.token')->post('/internal/db/query',
    [InternalQueryController::class, 'query'])->name('internal.db.query');
```

In `admin-laravel/bootstrap/app.php`, change the routing and middleware blocks:

```php
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            \Illuminate\Support\Facades\Route::group([], base_path('routes/internal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'super_admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'system.access' => \App\Http\Middleware\EnsureSystemAccess::class,
            'engine.token' => \App\Http\Middleware\EnsureEngineToken::class,
        ]);
    })
```

In `admin-laravel/config/app.php`, add inside the returned array:

```php
    // The secret the engine sends when it asks the portal to run a query.
    // Distinct from ENGINE_ADMIN_TOKEN, which travels the other way.
    'portal_internal_token' => env('PORTAL_INTERNAL_TOKEN', ''),
```

In `admin-laravel/.env.example`, add:

```
# Shared secret for the engine's calls into this portal. Generate with
# `php artisan key:generate --show` and copy the same value into
# api-engine/.env. Different from ENGINE_ADMIN_TOKEN by design.
PORTAL_INTERNAL_TOKEN=
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter=InternalQueryTest`
Expected: PASS, 11 tests.

- [ ] **Step 8: Run the whole Laravel suite**

Run: `php artisan test`
Expected: PASS, the 184 existing tests plus the 11 new ones.

- [ ] **Step 9: Commit**

```bash
git add admin-laravel/app/Services/Schema/SqlGuard.php \
        admin-laravel/app/Services/Schema/DbQueryRunner.php \
        admin-laravel/app/Http/Middleware/EnsureEngineToken.php \
        admin-laravel/app/Http/Controllers/InternalQueryController.php \
        admin-laravel/routes/internal.php \
        admin-laravel/bootstrap/app.php \
        admin-laravel/config/app.php \
        admin-laravel/.env.example \
        admin-laravel/tests/Feature/InternalQueryTest.php
git commit -m "feat: the portal runs one read-only query for the engine"
```

---

### Task 8: The engine's portal client

**Files:**
- Create: `api-engine/dbquery/portal.py`
- Modify: `api-engine/config.py`
- Modify: `api-engine/.env.example` (create it if absent)
- Test: `api-engine/tests/test_dbquery_portal.py`

**Interfaces:**
- Consumes: `dbquery.result.QueryResult` from Task 5.
- Produces: `dbquery.portal.run_query(base_url: str, token: str, connection_id: str, sql: str, max_rows: int, timeout: int, transport=None) -> tuple[QueryResult | None, str]`. The second element is a complaint when the first is `None`.
- Produces: `settings.PORTAL_BASE_URL` and `settings.PORTAL_INTERNAL_TOKEN` in `config.py`.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_dbquery_portal.py`:

```python
"""Asking the portal to run a statement.

This is the call that reverses the architecture's one-way direction, so it
carries its own secret and it never raises.
"""
import httpx
import pytest

from dbquery.portal import run_query

OK_BODY = {
    "ok": True,
    "columns": ["id", "status"],
    "rows": [[1, "shipped"], [2, "pending"]],
    "row_count": 2,
    "elapsed_ms": 9,
    "message": "",
}


def responder(body, capture=None, status=200):
    def handler(request: httpx.Request) -> httpx.Response:
        if capture is not None:
            capture["url"] = str(request.url)
            capture["headers"] = dict(request.headers)
            capture["body"] = request.read().decode()
        return httpx.Response(status, json=body)
    return handler


@pytest.mark.asyncio
async def test_a_successful_query_becomes_a_result():
    result, complaint = await run_query(
        "http://localhost:8080", "tok", "dbc_1", "SELECT 1", 50, 10,
        transport=httpx.MockTransport(responder(OK_BODY)))

    assert complaint == ""
    assert result.columns == ["id", "status"]
    assert result.row_count == 2
    assert result.rows[0] == [1, "shipped"]


@pytest.mark.asyncio
async def test_the_token_travels_in_its_own_header():
    seen = {}
    await run_query("http://localhost:8080", "tok", "dbc_1", "SELECT 1", 50, 10,
                    transport=httpx.MockTransport(responder(OK_BODY, seen)))

    assert seen["headers"]["x-portal-token"] == "tok"


@pytest.mark.asyncio
async def test_the_request_goes_to_the_internal_route():
    seen = {}
    await run_query("http://localhost:8080/", "tok", "dbc_1", "SELECT 1", 50, 10,
                    transport=httpx.MockTransport(responder(OK_BODY, seen)))

    assert seen["url"] == "http://localhost:8080/internal/db/query"


@pytest.mark.asyncio
async def test_the_body_carries_every_limit():
    seen = {}
    await run_query("http://localhost:8080", "tok", "dbc_1", "SELECT 1", 25, 7,
                    transport=httpx.MockTransport(responder(OK_BODY, seen)))

    assert '"max_rows": 25' in seen["body"] or '"max_rows":25' in seen["body"]
    assert '"timeout": 7' in seen["body"] or '"timeout":7' in seen["body"]


@pytest.mark.asyncio
async def test_a_refusal_comes_back_as_a_complaint():
    body = {"ok": False, "columns": [], "rows": [], "row_count": 0,
            "elapsed_ms": 0, "message": "DELETE is not allowed."}

    result, complaint = await run_query(
        "http://x", "tok", "dbc_1", "DELETE FROM t", 50, 10,
        transport=httpx.MockTransport(responder(body)))

    assert result is None
    assert "DELETE" in complaint


@pytest.mark.asyncio
async def test_an_http_error_is_a_complaint_not_an_exception():
    result, complaint = await run_query(
        "http://x", "tok", "dbc_1", "SELECT 1", 50, 10,
        transport=httpx.MockTransport(responder({"error": "x"}, status=500)))

    assert result is None
    assert complaint


@pytest.mark.asyncio
async def test_an_unreachable_portal_is_a_complaint_not_an_exception():
    def handler(request):
        raise httpx.ConnectError("refused")

    result, complaint = await run_query(
        "http://x", "tok", "dbc_1", "SELECT 1", 50, 10,
        transport=httpx.MockTransport(handler))

    assert result is None
    assert complaint


@pytest.mark.asyncio
async def test_a_malformed_body_is_a_complaint():
    def handler(request):
        return httpx.Response(200, text="not json")

    result, complaint = await run_query(
        "http://x", "tok", "dbc_1", "SELECT 1", 50, 10,
        transport=httpx.MockTransport(handler))

    assert result is None
    assert complaint


@pytest.mark.asyncio
async def test_an_empty_result_set_is_success_not_failure():
    # "No order 88421" is an answer. It is not the query failing.
    body = {"ok": True, "columns": ["id"], "rows": [], "row_count": 0,
            "elapsed_ms": 3, "message": ""}

    result, complaint = await run_query(
        "http://x", "tok", "dbc_1", "SELECT 1", 50, 10,
        transport=httpx.MockTransport(responder(body)))

    assert complaint == ""
    assert result is not None
    assert result.rows == []
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_portal.py -q`
Expected: FAIL with `ModuleNotFoundError: No module named 'dbquery.portal'`.

- [ ] **Step 3: Write the client**

Create `api-engine/dbquery/portal.py`:

```python
"""Asking Laravel to run a statement.

This is the one call that goes engine to portal. Every other call in this
system goes the other way, which is why it carries its own secret rather than
reusing the one Laravel sends here.

Nothing raises. A refusal, an unreachable portal and a malformed reply all
come back as a complaint the caller turns into a fall-through.
"""
import httpx

from dbquery.result import QueryResult


async def run_query(base_url: str, token: str, connection_id: str, sql: str,
                    max_rows: int, timeout: int,
                    transport=None) -> tuple[QueryResult | None, str]:
    endpoint = f"{base_url.rstrip('/')}/internal/db/query"
    payload = {
        "connection_id": connection_id,
        "sql": sql,
        "max_rows": max_rows,
        "timeout": timeout,
    }

    # Room for the portal's own timeout to fire and report properly, rather
    # than this one cutting it off and losing the reason.
    budget = timeout + 5

    try:
        async with httpx.AsyncClient(timeout=budget, transport=transport) as client:
            response = await client.post(
                endpoint, json=payload,
                headers={"X-Portal-Token": token, "Accept": "application/json"})
            response.raise_for_status()
            body = response.json()
    except Exception as error:
        return None, f"Could not reach the portal: {error}"

    if not body.get("ok"):
        return None, body.get("message") or "The portal refused the query."

    return QueryResult(
        columns=body.get("columns") or [],
        rows=body.get("rows") or [],
        row_count=int(body.get("row_count") or 0),
        elapsed_ms=int(body.get("elapsed_ms") or 0),
    ), ""
```

- [ ] **Step 4: Add the settings**

In `api-engine/config.py`, inside `class Settings`, after `ADMIN_API_TOKEN`:

```python
    # Where the admin portal listens, and the secret for calling into it.
    # Distinct from ADMIN_API_TOKEN, which is what Laravel sends this way.
    # A secret that authenticates one direction should not authenticate the
    # other, or compromising the engine would hand over the portal with it.
    PORTAL_BASE_URL: str = os.getenv("PORTAL_BASE_URL", "http://localhost:8080")
    PORTAL_INTERNAL_TOKEN: str = os.getenv("PORTAL_INTERNAL_TOKEN", "")
```

Create or append to `api-engine/.env.example`:

```
# The admin portal, and the shared secret for calling into it. The token must
# match PORTAL_INTERNAL_TOKEN in admin-laravel/.env.
PORTAL_BASE_URL=http://localhost:8080
PORTAL_INTERNAL_TOKEN=
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_portal.py -q`
Expected: PASS, 9 tests.

- [ ] **Step 6: Commit**

```bash
git add api-engine/dbquery/portal.py api-engine/config.py api-engine/.env.example \
        api-engine/tests/test_dbquery_portal.py
git commit -m "feat: the engine asks the portal to run a query"
```

---

### Task 9: The orchestrator

**Files:**
- Modify: `api-engine/dbquery/__init__.py`
- Test: `api-engine/tests/test_dbquery_run.py`

**Interfaces:**
- Consumes: everything from Tasks 1 through 8.
- Produces:
  - `dbquery.Outcome`, a dataclass with `verdict: str`, `context_block: str`, `sql: str`, `row_count: int`, `connection_name: str`.
  - `async def load_bot_schema(session, bot_id: str) -> tuple[str, str, str, list[SchemaTable]]`, returning `(connection_id, connection_name, driver, tables)`, or `("", "", "", [])` when the bot has no enabled connection. The driver is carried because the row limit is written differently per dialect: SQL Server takes `TOP`, the other three take `LIMIT`.
  - `async def route_and_query(session, bot, message, collection_names, settings, complete=None, portal_call=None, load_schema=None) -> Outcome`. The three keyword arguments default to the real implementations and exist so orchestration can be tested without a model, a portal, or a database.

`complete` and `portal_call` are injectable so the orchestration can be tested without a model or a portal, which is the same seam `transport=` gives the adapters.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_dbquery_run.py`:

```python
"""The orchestration, with the model and the portal swapped for fakes.

The rule being tested throughout: every failure lands on a verdict the chat
route already knows how to handle, so a router that classifies badly can
improve on today's behaviour and cannot degrade it.
"""
import pytest

from dbquery import Outcome, route_and_query
from dbquery.result import QueryResult
from dbquery.schema import SchemaColumn, SchemaTable


class FakeBot:
    def __init__(self, db_query_enabled=True, web_search_enabled=True):
        self.id = "bot_1"
        self.db_query_enabled = db_query_enabled
        self.web_search_enabled = web_search_enabled
        self.db_max_rows = 50
        self.db_query_timeout = 10
        self.base_url = "http://localhost:11434/v1"
        self.api_key = ""
        self.model_name = "llama3.2"


ORDERS = SchemaTable(
    qualified_name="orders",
    description="Orders placed through the web shop.",
    columns=[SchemaColumn("id", "integer", True, None, ""),
             SchemaColumn("status", "text", False, None, "shipped or pending")],
)

SETTINGS = {"context_char_budget": "6000", "sql_model_base_url": "",
            "sql_model_api_key": "", "sql_model_name": ""}


def answers(*replies):
    """A fake complete() that returns each reply in turn."""
    queue = list(replies)

    async def complete(**kwargs):
        return queue.pop(0) if queue else ""

    return complete


def portal_returning(result, complaint=""):
    async def call(**kwargs):
        return result, complaint

    return call


def schema_loader(connection_id="dbc_1", name="Shop database",
                  driver="mysql", tables=None):
    async def load(session, bot_id):
        return connection_id, name, driver, [ORDERS] if tables is None else tables

    return load


ROWS = QueryResult(columns=["id", "status"], rows=[[1, "shipped"]],
                   row_count=1, elapsed_ms=8)


async def run(bot=None, replies=("database",), portal=None, tables=None):
    return await route_and_query(
        session=None,
        bot=bot or FakeBot(),
        message="has order 1 shipped?",
        collection_names=["Refund policy"],
        settings=SETTINGS,
        complete=answers(*replies),
        portal_call=portal or portal_returning(ROWS),
        load_schema=schema_loader(tables=tables),
    )


@pytest.mark.asyncio
async def test_a_database_verdict_queries_and_returns_a_block():
    outcome = await run(replies=("database", "SELECT id, status FROM orders"))

    assert outcome.verdict == "database"
    assert "shipped" in outcome.context_block
    assert outcome.row_count == 1
    assert outcome.connection_name == "Shop database"


@pytest.mark.asyncio
async def test_the_generated_statement_is_kept_for_the_transcript():
    outcome = await run(replies=("database", "SELECT id, status FROM orders"))

    assert "select" in outcome.sql.lower()


@pytest.mark.asyncio
async def test_a_documents_verdict_does_not_query():
    outcome = await run(replies=("documents",))

    assert outcome.verdict == "documents"
    assert outcome.context_block == ""
    assert outcome.sql == ""


@pytest.mark.asyncio
async def test_a_web_verdict_does_not_query():
    outcome = await run(replies=("web",))

    assert outcome.verdict == "web"


@pytest.mark.asyncio
async def test_a_none_verdict_answers_from_nothing():
    outcome = await run(replies=("none",))

    assert outcome.verdict == "none"
    assert outcome.context_block == ""


@pytest.mark.asyncio
async def test_a_bot_without_the_switch_never_calls_the_model():
    called = {"n": 0}

    async def counting(**kwargs):
        called["n"] += 1
        return "database"

    outcome = await route_and_query(
        session=None, bot=FakeBot(db_query_enabled=False),
        message="hello", collection_names=[], settings=SETTINGS,
        complete=counting, portal_call=portal_returning(ROWS),
        load_schema=schema_loader())

    assert called["n"] == 0
    assert outcome.verdict == "documents"


@pytest.mark.asyncio
async def test_a_bot_with_no_enabled_tables_never_calls_the_model():
    called = {"n": 0}

    async def counting(**kwargs):
        called["n"] += 1
        return "database"

    outcome = await route_and_query(
        session=None, bot=FakeBot(), message="hi", collection_names=[],
        settings=SETTINGS, complete=counting,
        portal_call=portal_returning(ROWS),
        load_schema=schema_loader(tables=[]))

    assert called["n"] == 0
    assert outcome.verdict == "documents"


@pytest.mark.asyncio
async def test_an_unparseable_verdict_falls_through_to_documents():
    outcome = await run(replies=("I am not sure, maybe?",))

    assert outcome.verdict == "documents"
    assert outcome.context_block == ""


@pytest.mark.asyncio
async def test_an_empty_router_answer_falls_through_to_documents():
    outcome = await run(replies=("",))

    assert outcome.verdict == "documents"


@pytest.mark.asyncio
async def test_a_rejected_statement_is_retried_once_then_falls_through():
    # Two refusals, so the retry happens and then gives up.
    outcome = await run(replies=("database", "DELETE FROM orders", "DROP TABLE orders"))

    assert outcome.verdict == "documents"
    assert outcome.context_block == ""


@pytest.mark.asyncio
async def test_a_retry_that_produces_a_good_statement_is_used():
    outcome = await run(replies=("database", "DELETE FROM orders",
                                 "SELECT id, status FROM orders"))

    assert outcome.verdict == "database"
    assert "shipped" in outcome.context_block


@pytest.mark.asyncio
async def test_a_statement_naming_a_forbidden_table_falls_through():
    outcome = await run(replies=("database", "SELECT * FROM salaries",
                                 "SELECT * FROM salaries"))

    assert outcome.verdict == "documents"


@pytest.mark.asyncio
async def test_an_unreachable_portal_falls_through_to_documents():
    outcome = await run(replies=("database", "SELECT id FROM orders"),
                        portal=portal_returning(None, "Could not reach the portal"))

    assert outcome.verdict == "documents"
    assert outcome.context_block == ""


@pytest.mark.asyncio
async def test_zero_rows_still_counts_as_the_database_answering():
    # "There is no order 88421" is the right answer, and it comes from here.
    empty = QueryResult(columns=["id"], rows=[], row_count=0, elapsed_ms=2)
    outcome = await run(replies=("database", "SELECT id FROM orders"),
                        portal=portal_returning(empty))

    assert outcome.verdict == "database"
    assert outcome.context_block
    assert outcome.row_count == 0


@pytest.mark.asyncio
async def test_a_bot_without_web_search_is_never_routed_to_the_web():
    outcome = await run(bot=FakeBot(web_search_enabled=False), replies=("web",))

    assert outcome.verdict == "documents"


@pytest.mark.asyncio
async def test_the_outcome_is_the_documented_shape():
    outcome = await run(replies=("none",))

    assert isinstance(outcome, Outcome)
    assert outcome.verdict in ("database", "documents", "web", "none")


@pytest.mark.asyncio
async def test_sql_server_gets_a_top_rather_than_a_limit():
    # The driver has to travel with the schema, or every SQL Server query
    # would be handed a LIMIT clause it cannot parse.
    captured = {}

    async def capturing_portal(**kwargs):
        captured["sql"] = kwargs["sql"]
        return ROWS, ""

    await route_and_query(
        session=None, bot=FakeBot(), message="how many orders?",
        collection_names=[], settings=SETTINGS,
        complete=answers("database", "SELECT id FROM orders"),
        portal_call=capturing_portal,
        load_schema=schema_loader(driver="sqlsrv"))

    assert "top" in captured["sql"].lower()
    assert "limit" not in captured["sql"].lower()
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_run.py -q`
Expected: FAIL with `ImportError: cannot import name 'Outcome' from 'dbquery'`.

- [ ] **Step 3: Write the orchestrator**

Replace the contents of `api-engine/dbquery/__init__.py`:

```python
"""Routing a message, and querying when the database is the answer.

The shape of this module is one rule repeated: every way it can go wrong ends
on a verdict the chat route already handles. An unreachable model, a verdict
nobody can read, a statement that fails validation twice, a portal that is
down, a query that times out. All of them come back as "documents", which is
what would have happened without any of this.

That is what makes a router built on a small model safe to add. It can
improve on the deterministic chain and it cannot degrade it.
"""
from dataclasses import dataclass
from datetime import date

from sqlalchemy import select

from dbquery import context as row_context
from dbquery import portal as portal_module
from dbquery import routing, sql as sql_module
from dbquery.schema import (SchemaColumn, SchemaTable, allowed_names,
                            build_schema_block, build_table_summary)
from llm_adapter import LLMAdapter

GENERATION_PROMPT = """You write one read-only SQL query for a {driver} database.

{schema}

Rules:
- Reply with the SQL statement and nothing else. No explanation, no markdown fence.
- Only SELECT. Never INSERT, UPDATE, DELETE, or anything that changes data.
- Only the tables listed above may be named.
- Prefer the columns whose descriptions match what was asked for."""


@dataclass
class Outcome:
    verdict: str = "documents"
    context_block: str = ""
    sql: str = ""
    row_count: int = 0
    connection_name: str = ""


async def load_bot_schema(session, bot_id: str) -> tuple[str, str, str, list[SchemaTable]]:
    """The one enabled connection attached to this bot, and its readable tables.

    Both switches have to agree, which is why is_enabled is checked on the
    connection and on the table. A table the database no longer has is
    excluded too: it cannot answer anything and naming it would fail.

    The driver travels with them because the row limit is written differently
    per dialect, and handing SQL Server a LIMIT clause fails every time.
    """
    from database import DbColumn, DbConnection, DbTable, BotDbConnection

    rows = await session.execute(
        select(DbConnection.id, DbConnection.name, DbConnection.driver)
        .join(BotDbConnection, BotDbConnection.connection_id == DbConnection.id)
        .where(BotDbConnection.bot_id == bot_id, DbConnection.is_enabled.is_(True))
        .limit(1))
    found = rows.first()

    if not found:
        return "", "", "", []

    connection_id, connection_name, driver = found

    table_rows = await session.execute(
        select(DbTable)
        .where(DbTable.connection_id == connection_id,
               DbTable.is_enabled.is_(True), DbTable.is_present.is_(True))
        .order_by(DbTable.schema_name, DbTable.table_name))
    stored_tables = list(table_rows.scalars().all())

    if not stored_tables:
        return connection_id, connection_name, driver, []

    column_rows = await session.execute(
        select(DbColumn)
        .where(DbColumn.table_id.in_([t.id for t in stored_tables]),
               DbColumn.is_present.is_(True))
        .order_by(DbColumn.ordinal))
    by_table: dict[int, list] = {}
    for column in column_rows.scalars().all():
        by_table.setdefault(column.table_id, []).append(column)

    tables = []
    for table in stored_tables:
        qualified = (f"{table.schema_name}.{table.table_name}"
                     if table.schema_name else table.table_name)
        tables.append(SchemaTable(
            qualified_name=qualified,
            description=table.description or "",
            columns=[SchemaColumn(
                name=column.column_name,
                data_type=column.data_type or "",
                is_primary_key=bool(column.is_primary_key),
                foreign_key_target=column.foreign_key_target,
                description=column.description or "",
            ) for column in by_table.get(table.id, [])],
        ))

    return connection_id, connection_name, driver, tables


def _sql_endpoint(bot, settings: dict) -> tuple[str, str, str]:
    """Where query work goes. Blank settings mean the bot's own model."""
    base_url = (settings.get("sql_model_base_url") or "").strip()
    model = (settings.get("sql_model_name") or "").strip()

    if base_url and model:
        return base_url, (settings.get("sql_model_api_key") or ""), model

    return bot.base_url, bot.api_key or "", bot.model_name


async def route_and_query(session, bot, message: str, collection_names: list[str],
                          settings: dict, complete=None, portal_call=None,
                          load_schema=None) -> Outcome:
    complete = complete or LLMAdapter.complete
    portal_call = portal_call or portal_module.run_query
    loader = load_schema or load_bot_schema

    if not bot.db_query_enabled:
        return Outcome(verdict="documents")

    connection_id, connection_name, driver, tables = await loader(session, bot.id)
    if not tables:
        # Nothing readable, so there is nothing for a router to choose between
        # that the existing chain does not already handle.
        return Outcome(verdict="documents")

    web_enabled = bool(getattr(bot, "web_search_enabled", False))
    base_url, api_key, model = _sql_endpoint(bot, settings)

    verdict = routing.parse_verdict(await complete(
        base_url=base_url, api_key=api_key, model_name=model,
        system_prompt=routing.build_router_prompt(
            build_table_summary(tables), collection_names, web_enabled),
        user_message=message))

    if verdict is None:
        return Outcome(verdict="documents")
    if verdict == "web" and not web_enabled:
        return Outcome(verdict="documents")
    if verdict != "database":
        return Outcome(verdict=verdict)

    from config import settings as engine_settings

    dialects = {"mysql": "MySQL", "pgsql": "PostgreSQL",
                "sqlsrv": "SQL Server", "sqlite": "SQLite"}
    generation_prompt = GENERATION_PROMPT.format(
        driver=dialects.get(driver, "SQL"), schema=build_schema_block(tables))
    allowed = allowed_names(tables)
    max_rows = int(bot.db_max_rows or 50)

    statement = ""
    complaint = ""
    for attempt in range(2):
        prompt = generation_prompt if attempt == 0 else (
            f"{generation_prompt}\n\nYour last statement was rejected: {complaint}\n"
            "Write a statement that obeys the rules.")

        raw = await complete(base_url=base_url, api_key=api_key, model_name=model,
                             system_prompt=prompt, user_message=message)
        checked = sql_module.validate(_unfence(raw), allowed, driver, max_rows)

        if checked.ok:
            statement = checked.sql
            break

        complaint = checked.complaint
        print(f"[DbQuery] Statement rejected: {complaint}")

    if not statement:
        return Outcome(verdict="documents")

    result, failure = await portal_call(
        base_url=engine_settings.PORTAL_BASE_URL,
        token=engine_settings.PORTAL_INTERNAL_TOKEN,
        connection_id=connection_id, sql=statement,
        max_rows=max_rows, timeout=int(bot.db_query_timeout or 10))

    if result is None:
        print(f"[DbQuery] Query failed, answering without it: {failure}")
        return Outcome(verdict="documents")

    trimmed = row_context.fit_rows_to_budget(
        result, int(settings.get("context_char_budget", 6000)))

    return Outcome(
        verdict="database",
        context_block=row_context.build_database_context_block(
            connection_name, trimmed, date.today().isoformat()),
        sql=statement,
        row_count=result.row_count,
        connection_name=connection_name,
    )


def _unfence(answer: str) -> str:
    """Models wrap SQL in a markdown fence however firmly you ask them not to."""
    text = (answer or "").strip()
    if text.startswith("```"):
        text = text.split("\n", 1)[-1]
        if "```" in text:
            text = text.rsplit("```", 1)[0]

    return text.strip()
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `.venv/Scripts/python.exe -m pytest tests/test_dbquery_run.py -q`
Expected: PASS, 16 tests.

- [ ] **Step 5: Commit**

```bash
git add api-engine/dbquery/__init__.py api-engine/tests/test_dbquery_run.py
git commit -m "feat: route, generate, validate and query, falling through on every failure"
```

---

### Task 10: Wiring it into the chat route

**Files:**
- Modify: `api-engine/routers/chat.py`
- Test: `api-engine/tests/test_chat_database.py`

**Interfaces:**
- Consumes: `dbquery.route_and_query` and `dbquery.Outcome` from Task 9.
- Produces: no new public functions. The route now honours the router's verdict, emits a database source chip, and records the statement on the assistant message.

The existing `should_retrieve` gate stays in front of everything, so a greeting still costs nothing.

- [ ] **Step 1: Write the failing test**

Create `api-engine/tests/test_chat_database.py`:

```python
"""What the chat route does with a verdict.

These are about the route's branching, so the outcome is handed in rather
than produced by a real router.
"""
import pytest

from dbquery import Outcome
from routers.chat import context_for, sources_payload_for


DB_OUTCOME = Outcome(verdict="database", context_block="id | status\n1 | shipped",
                     sql="SELECT id FROM orders LIMIT 50", row_count=1,
                     connection_name="Shop database")


def test_a_database_outcome_supplies_the_context_block():
    block = context_for(DB_OUTCOME, kb_block="documents block", web_block="web block")

    assert "shipped" in block
    assert "documents block" not in block


def test_a_documents_outcome_uses_the_knowledge_base_block():
    outcome = Outcome(verdict="documents")
    block = context_for(outcome, kb_block="documents block", web_block="web block")

    assert block == "documents block"


def test_a_documents_outcome_with_nothing_retrieved_falls_to_the_web():
    outcome = Outcome(verdict="documents")
    block = context_for(outcome, kb_block="", web_block="web block")

    assert block == "web block"


def test_a_web_outcome_uses_the_web_block():
    outcome = Outcome(verdict="web")

    assert context_for(outcome, kb_block="docs", web_block="web block") == "web block"


def test_a_none_outcome_supplies_no_context_at_all():
    outcome = Outcome(verdict="none")

    assert context_for(outcome, kb_block="docs", web_block="web") == ""


def test_a_database_answer_cites_the_connection_by_name():
    payload = sources_payload_for(DB_OUTCOME, retrieved=[], source_titles={},
                                  web_results=[])

    assert payload["sources"][0]["title"] == "Shop database"


def test_a_database_citation_carries_no_url_and_no_sql():
    # A visitor on a public site must not learn the table names.
    payload = sources_payload_for(DB_OUTCOME, retrieved=[], source_titles={},
                                  web_results=[])

    assert "url" not in payload["sources"][0]
    assert "SELECT" not in str(payload)


def test_no_sources_event_when_nothing_answered():
    outcome = Outcome(verdict="none")

    assert sources_payload_for(outcome, retrieved=[], source_titles={},
                               web_results=[]) is None
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `.venv/Scripts/python.exe -m pytest tests/test_chat_database.py -q`
Expected: FAIL with `ImportError: cannot import name 'context_for' from 'routers.chat'`.

- [ ] **Step 3: Add the two pure helpers**

In `api-engine/routers/chat.py`, after the `key_for` function and before `router = APIRouter(...)`:

```python
def context_for(outcome, kb_block: str, web_block: str) -> str:
    """Which block the prompt gets, given the router's verdict.

    Lifted out of the route so the branching can be tested without a model,
    a database, or an HTTP request, the way should_retrieve is.
    """
    if outcome.verdict == "database":
        return outcome.context_block
    if outcome.verdict == "none":
        return ""
    if outcome.verdict == "web":
        return web_block

    # documents, including every fall-through. The web stays the fallback for
    # a knowledge base that came back empty, exactly as it does today.
    return kb_block or web_block


def sources_payload_for(outcome, retrieved, source_titles, web_results):
    """The sources event, or None when nothing is worth citing."""
    if outcome.verdict == "database":
        # The connection's name and nothing else. A visitor on a public site
        # must not learn the table names, let alone the statement.
        return {"type": "sources",
                "sources": [{"n": 1, "title": outcome.connection_name}]}

    if retrieved:
        return {"type": "sources", "sources": [
            {"n": i + 1, "title": source_titles.get(r.source_id, "Untitled"),
             "source_id": r.source_id}
            for i, r in enumerate(retrieved)
        ]}

    if web_results:
        return {"type": "sources", "sources": [
            {"n": i + 1, "title": item.title, "url": item.url}
            for i, item in enumerate(web_results)
        ]}

    return None
```

Add the import at the top of the file, beside the `websearch` imports:

```python
import dbquery
```

- [ ] **Step 4: Run the helper tests**

Run: `.venv/Scripts/python.exe -m pytest tests/test_chat_database.py -q`
Expected: PASS, 8 tests.

- [ ] **Step 5: Wire the route**

In `api-engine/routers/chat.py`, replace the block that starts at `retrieval_ran = bool(bot.retrieval_enabled) and message_is_a_question` and ends just before `context_block = build_context_block(retrieved, source_titles)` with:

```python
    retrieval_ran = bool(bot.retrieval_enabled) and message_is_a_question

    engine_settings = await get_settings(db)

    # The router runs only past the cheap gate, so a greeting still costs
    # nothing. Everything it can get wrong ends on the "documents" verdict,
    # which is the chain that shipped before it existed.
    outcome = dbquery.Outcome(verdict="documents")
    if message_is_a_question:
        try:
            collection_rows = await db.execute(
                select(KbCollection.name)
                .join(BotKbCollection, BotKbCollection.collection_id == KbCollection.id)
                .where(BotKbCollection.bot_id == bot.id))
            outcome = await dbquery.route_and_query(
                db, bot, req.message, list(collection_rows.scalars().all()),
                engine_settings)
        except Exception as routing_error:
            print(f"[DbQuery] Routing skipped: {routing_error}")
            outcome = dbquery.Outcome(verdict="documents")

    if retrieval_ran and outcome.verdict == "documents":
        try:
            rows = await db.execute(
                select(BotKbCollection.collection_id).where(BotKbCollection.bot_id == bot.id))
            collection_ids = list(rows.scalars().all())

            retrieved = await retrieve_for_collections(
                db, collection_ids, req.message,
                mode=bot.retrieval_mode or "hybrid",
                top_k=bot.retrieval_top_k or 5,
                candidates=bot.retrieval_candidates or 30,
                min_score=bot.retrieval_min_score or 0.0,
            )
            retrieved = fit_to_budget(
                retrieved, int(engine_settings["context_char_budget"]))

            if retrieved:
                title_rows = await db.execute(
                    select(KbSource.id, KbSource.title).where(
                        KbSource.id.in_([r.source_id for r in retrieved])))
                source_titles = {row[0]: row[1] for row in title_rows.all()}
        except Exception as retrieval_error:
            print(f"[Retrieval] Skipped, answering without context: {retrieval_error}")
            retrieved = []

    web_results = []
    web_wanted = outcome.verdict == "web" or (
        outcome.verdict == "documents"
        and web_search_runs(bot.web_search_enabled, message_is_a_question, len(retrieved)))
    if web_wanted and bot.web_search_enabled:
        web_results = await websearch.search(
            provider=engine_settings["web_search_provider"],
            query=req.message,
            count=int(bot.web_search_max_results or 3),
            country=bot.web_search_country,
            api_key=key_for(engine_settings),
        )
        web_results = fit_results_to_budget(
            web_results, int(engine_settings["context_char_budget"]))
```

Then replace the three lines that built `context_block`:

```python
    context_block = context_for(
        outcome,
        kb_block=build_context_block(retrieved, source_titles),
        web_block=build_web_context_block(web_results),
    )
```

Add `KbCollection` to the `database` import list at the top of the file.

- [ ] **Step 6: Emit the database chip**

In `sse_event_stream`, replace the `if retrieved:` / `elif web_results:` pair that yields the sources payload with:

```python
        sources_payload = sources_payload_for(
            outcome, retrieved, source_titles, web_results)
        if sources_payload:
            yield f"data: {json.dumps(sources_payload)}\n\n"
```

- [ ] **Step 7: Record the statement on the message**

In the `finally` block, change the `ChatMessage(...)` construction to include:

```python
                            db_sql=outcome.sql or None,
                            db_row_count=outcome.row_count if outcome.sql else None,
```

- [ ] **Step 8: Run the whole engine suite**

Run: `.venv/Scripts/python.exe -m pytest -q`
Expected: PASS. `tests/test_chat_gating.py` and `tests/test_chat_web_search.py` must still pass unchanged.

- [ ] **Step 9: Commit**

```bash
git add api-engine/routers/chat.py api-engine/tests/test_chat_database.py
git commit -m "feat: the chat route honours the router's verdict"
```

---

### Task 11: The bot brain database block

**Files:**
- Modify: `admin-laravel/app/Http/Controllers/BotBrainController.php`
- Modify: `admin-laravel/resources/views/bots/brain.blade.php`
- Test: `admin-laravel/tests/Feature/BotDatabaseSettingsTest.php`

**Interfaces:**
- Consumes: `DbConnection` and `BotProfile::dbConnections()` from stage one.
- Produces: the brain form accepting `db_query_enabled`, `db_max_rows`, `db_query_timeout` and `db_connections[]`.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/BotDatabaseSettingsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\DbConnection;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotDatabaseSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    private function bot(): BotProfile
    {
        return BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support',
        ]);
    }

    private function connection(string $id = 'dbc_1', string $system = 'sys_test'): DbConnection
    {
        return DbConnection::create([
            'id' => $id, 'system_id' => $system, 'name' => 'Shop',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'system_prompt' => 'You are helpful.',
            'retrieval_mode' => 'hybrid', 'retrieval_top_k' => 5,
            'retrieval_candidates' => 30, 'retrieval_min_score' => 0.02,
            'retrieval_fallback' => 'say_unknown',
            'web_search_max_results' => 3,
            'top_p' => 1, 'presence_penalty' => 0, 'frequency_penalty' => 0,
            'thinking_level' => 'off',
            'db_max_rows' => 50, 'db_query_timeout' => 10,
        ], $overrides);
    }

    public function test_the_brain_screen_lists_the_workspaces_connections(): void
    {
        $editor = $this->editor();
        $this->bot();
        $this->connection();

        $this->actingAs($editor)
            ->get(route('bots.brain', 'bot_1'))
            ->assertOk()
            ->assertSee('Shop');
    }

    public function test_a_connection_can_be_attached(): void
    {
        $editor = $this->editor();
        $this->bot();
        $this->connection();

        $this->actingAs($editor)
            ->put(route('bots.brain.update', 'bot_1'), $this->payload([
                'db_query_enabled' => '1', 'db_connections' => ['dbc_1'],
            ]))
            ->assertRedirect();

        $bot = BotProfile::find('bot_1');
        $this->assertTrue((bool) $bot->db_query_enabled);
        $this->assertCount(1, $bot->dbConnections);
    }

    public function test_an_unticked_switch_turns_querying_off(): void
    {
        $editor = $this->editor();
        $bot = $this->bot();
        $bot->update(['db_query_enabled' => true]);

        $this->actingAs($editor)
            ->put(route('bots.brain.update', 'bot_1'), $this->payload());

        $this->assertFalse((bool) BotProfile::find('bot_1')->db_query_enabled);
    }

    public function test_the_limits_are_saved(): void
    {
        $editor = $this->editor();
        $this->bot();

        $this->actingAs($editor)
            ->put(route('bots.brain.update', 'bot_1'), $this->payload([
                'db_max_rows' => 120, 'db_query_timeout' => 25,
            ]));

        $bot = BotProfile::find('bot_1');
        $this->assertSame(120, (int) $bot->db_max_rows);
        $this->assertSame(25, (int) $bot->db_query_timeout);
    }

    public function test_a_silly_row_cap_is_rejected(): void
    {
        $editor = $this->editor();
        $this->bot();

        $this->actingAs($editor)
            ->put(route('bots.brain.update', 'bot_1'), $this->payload(['db_max_rows' => 5000]))
            ->assertSessionHasErrors('db_max_rows');
    }

    public function test_a_connection_from_another_workspace_cannot_be_attached(): void
    {
        // Whatever the form posted. The same rule the collections picker has.
        $editor = $this->editor();
        $this->bot();
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        $this->connection('dbc_other', 'sys_other');

        $this->actingAs($editor)
            ->put(route('bots.brain.update', 'bot_1'), $this->payload([
                'db_query_enabled' => '1', 'db_connections' => ['dbc_other'],
            ]));

        $this->assertCount(0, BotProfile::find('bot_1')->dbConnections);
    }

    public function test_detaching_leaves_the_connection_itself_alone(): void
    {
        $editor = $this->editor();
        $bot = $this->bot();
        $this->connection();
        $bot->dbConnections()->attach('dbc_1');

        $this->actingAs($editor)
            ->put(route('bots.brain.update', 'bot_1'), $this->payload(['db_connections' => []]));

        $this->assertCount(0, BotProfile::find('bot_1')->dbConnections);
        $this->assertNotNull(DbConnection::find('dbc_1'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=BotDatabaseSettingsTest`
Expected: FAIL, because the view does not mention the connection and the columns are not saved.

- [ ] **Step 3: Update the controller**

In `admin-laravel/app/Http/Controllers/BotBrainController.php`, add the import:

```php
use App\Models\DbConnection;
```

In `edit`, change the eager load and add two view keys:

```php
        $bot = BotProfile::with(['collections', 'dbConnections'])->findOrFail($id);
```

```php
            'dbConnections' => DbConnection::where('system_id', $bot->system_id)
                ->orderBy('name')->get(),
            'attachedDbs' => $bot->dbConnections->pluck('id')->all(),
```

In `update`, add to the validation array:

```php
            'db_max_rows' => ['required', 'integer', 'min:1', 'max:1000'],
            'db_query_timeout' => ['required', 'integer', 'min:1', 'max:120'],
            'db_connections' => ['nullable', 'array'],
            'db_connections.*' => ['string'],
```

Beside the two existing `boolean()` lines:

```php
        $validated['db_query_enabled'] = $request->boolean('db_query_enabled');
```

Beside `unset($validated['collections']);`:

```php
        unset($validated['db_connections']);
```

And after the collections sync:

```php
        // Only connections from this bot's own workspace may be attached,
        // whatever the form posted. The same rule the collections picker has.
        $allowedDbs = DbConnection::where('system_id', $bot->system_id)
            ->whereIn('id', $request->input('db_connections', []))
            ->pluck('id')
            ->all();
        $bot->dbConnections()->sync($allowedDbs);
```

- [ ] **Step 4: Add the block to the brain view**

In `admin-laravel/resources/views/bots/brain.blade.php`, immediately after the card whose header is `Web search` (the one containing `web_search_country`), add:

```blade
        <div class="card mb-3">
            <div class="card-header">Database</div>
            <div class="card-body">
                <p class="text-muted" style="font-size: 0.8rem;">
                    When this is on, the bot works out for itself whether a question
                    is best answered from live data, from the documents above, from
                    the web, or from nothing at all. If it cannot decide, or anything
                    goes wrong, it falls back to the documents and then the web, which
                    is what it does today.
                </p>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="db_query_enabled" value="1" id="db_query_enabled"
                           {{ old('db_query_enabled', $bot->db_query_enabled) ? 'checked' : '' }}>
                    <label class="form-check-label" for="db_query_enabled">Query the database</label>
                </div>

                @if($dbConnections->isEmpty())
                    <p class="text-muted" style="font-size: 0.8rem;">
                        This workspace has no database connections yet.
                        <a href="{{ route('databases.index') }}">Add one</a>, then come back.
                    </p>
                @else
                    <label class="form-label">Connections this bot may read</label>
                    <div class="mb-3">
                        @foreach($dbConnections as $connection)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="db_connections[]"
                                       value="{{ $connection->id }}" id="db_{{ $connection->id }}"
                                       {{ in_array($connection->id, old('db_connections', $attachedDbs)) ? 'checked' : '' }}>
                                <label class="form-check-label" for="db_{{ $connection->id }}">
                                    {{ $connection->name }}
                                    @unless($connection->is_enabled)
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">switched off</span>
                                    @endunless
                                </label>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="db_max_rows" class="form-label">Rows returned at most</label>
                        <input type="number" name="db_max_rows" id="db_max_rows" min="1" max="1000"
                               class="form-control" value="{{ old('db_max_rows', $bot->db_max_rows) }}" required>
                        <div class="form-text">Every query is capped at this, whatever it asks for.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="db_query_timeout" class="form-label">Query timeout, seconds</label>
                        <input type="number" name="db_query_timeout" id="db_query_timeout" min="1" max="120"
                               class="form-control" value="{{ old('db_query_timeout', $bot->db_query_timeout) }}" required>
                        <div class="form-text">The visitor is waiting, so keep this short.</div>
                    </div>
                </div>
            </div>
        </div>
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=BotDatabaseSettingsTest`
Expected: PASS, 7 tests.

- [ ] **Step 6: Commit**

```bash
git add admin-laravel/app/Http/Controllers/BotBrainController.php \
        admin-laravel/resources/views/bots/brain.blade.php \
        admin-laravel/tests/Feature/BotDatabaseSettingsTest.php
git commit -m "feat: attach a database to a bot and let it decide when to query"
```

---

### Task 12: The SQL model settings

**Files:**
- Modify: `admin-laravel/app/Models/AppSetting.php`
- Modify: `admin-laravel/app/Http/Controllers/AdminSettingsController.php`
- Modify: `admin-laravel/resources/views/admin/settings.blade.php`
- Test: `admin-laravel/tests/Feature/SqlModelSettingsTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `sql_model_base_url`, `sql_model_api_key`, `sql_model_name` in `AppSetting::DEFAULTS` and on the settings form.

Read `admin-laravel/app/Models/AppSetting.php` first to find the existing defaults array and match its shape exactly.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/SqlModelSettingsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SqlModelSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin',
        ]);
    }

    public function test_the_three_keys_default_to_blank(): void
    {
        // Blank is the supported default: each bot uses its own model, and the
        // feature works with nothing configured.
        $this->assertSame('', AppSetting::DEFAULTS['sql_model_base_url']);
        $this->assertSame('', AppSetting::DEFAULTS['sql_model_api_key']);
        $this->assertSame('', AppSetting::DEFAULTS['sql_model_name']);
    }

    public function test_the_settings_screen_offers_them(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('sql_model_base_url');
    }

    public function test_they_can_be_saved(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), array_merge(
                AppSetting::DEFAULTS,
                [
                    'sql_model_base_url' => 'http://localhost:11434/v1',
                    'sql_model_name' => 'qwen2.5-coder',
                    'sql_model_api_key' => 'sk-test',
                ]))
            ->assertRedirect();

        $this->assertSame('qwen2.5-coder', AppSetting::get('sql_model_name'));
        $this->assertSame('http://localhost:11434/v1', AppSetting::get('sql_model_base_url'));
    }

    public function test_leaving_them_blank_is_accepted(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), array_merge(
                AppSetting::DEFAULTS,
                ['sql_model_base_url' => '', 'sql_model_name' => '', 'sql_model_api_key' => '']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SqlModelSettingsTest`
Expected: FAIL with `Undefined array key "sql_model_base_url"`.

- [ ] **Step 3: Add the defaults**

In `admin-laravel/app/Models/AppSetting.php`, add to the `DEFAULTS` constant, after the `web_search_*` entries:

```php
        // Query work can go to a stronger endpoint than a bot's chat model.
        // Blank means each bot uses its own, which is the supported default.
        'sql_model_base_url' => '',
        'sql_model_api_key' => '',
        'sql_model_name' => '',
```

- [ ] **Step 4: Accept them in the controller**

In `admin-laravel/app/Http/Controllers/AdminSettingsController.php`, add to the `update` method's validation array:

```php
            'sql_model_base_url' => ['nullable', 'string', 'max:500'],
            'sql_model_api_key' => ['nullable', 'string', 'max:500'],
            'sql_model_name' => ['nullable', 'string', 'max:255'],
```

- [ ] **Step 5: Add the fields to the settings screen**

In `admin-laravel/resources/views/admin/settings.blade.php`, add a card matching the surrounding ones, before the closing of the form:

```blade
    <div class="card mb-3">
        <div class="card-header">SQL model</div>
        <div class="card-body">
            <p class="text-muted" style="font-size: 0.8rem;">
                Which model writes the queries and decides which source answers.
                Small models are markedly weaker at SQL than at conversation, so
                pointing this at something stronger costs nothing on ordinary chats.
                Leave all three blank and each bot uses its own endpoint and model.
            </p>

            <div class="mb-3">
                <label for="sql_model_base_url" class="form-label">Base URL</label>
                <input type="text" name="sql_model_base_url" id="sql_model_base_url"
                       class="form-control" placeholder="http://localhost:11434/v1"
                       value="{{ old('sql_model_base_url', $settings['sql_model_base_url']) }}">
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="sql_model_name" class="form-label">Model</label>
                    <input type="text" name="sql_model_name" id="sql_model_name"
                           class="form-control" placeholder="qwen2.5-coder"
                           value="{{ old('sql_model_name', $settings['sql_model_name']) }}">
                </div>
                <div class="col-md-6">
                    <label for="sql_model_api_key" class="form-label">API key</label>
                    <input type="password" name="sql_model_api_key" id="sql_model_api_key"
                           class="form-control" autocomplete="new-password"
                           value="{{ old('sql_model_api_key', $settings['sql_model_api_key']) }}">
                </div>
            </div>
        </div>
    </div>
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=SqlModelSettingsTest`
Expected: PASS, 4 tests.

If `test_the_settings_screen_offers_them` fails because the view uses a different variable than `$settings`, read the top of `AdminSettingsController::edit` and use whatever key it passes.

- [ ] **Step 7: Commit**

```bash
git add admin-laravel/app/Models/AppSetting.php \
        admin-laravel/app/Http/Controllers/AdminSettingsController.php \
        admin-laravel/resources/views/admin/settings.blade.php \
        admin-laravel/tests/Feature/SqlModelSettingsTest.php
git commit -m "feat: point query work at a stronger model than the chat one"
```

---

### Task 13: The database playground

**Files:**
- Create: `admin-laravel/app/Http/Controllers/DbPlaygroundController.php`
- Create: `admin-laravel/resources/views/databases/playground.blade.php`
- Modify: `admin-laravel/routes/web.php`
- Modify: `admin-laravel/resources/views/databases/index.blade.php`
- Test: `admin-laravel/tests/Feature/DbPlaygroundTest.php`

**Interfaces:**
- Consumes: `DbQueryRunner` from Task 7, `DbConnection` from stage one.
- Produces: routes `databases.playground` (GET) and `databases.playground.run` (POST).

This is the tuning surface. An editor writes a description, runs a question, and watches the statement change. Without it, annotation is guesswork. It runs the statement through `DbQueryRunner`, so everything the chat path refuses, this refuses identically.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/DbPlaygroundTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use App\Models\User;
use App\Services\Schema\ProbeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbPlaygroundTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'play') . '.sqlite';
        touch($this->path);

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => "{$role}@example.test",
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => $role]);

        return $user;
    }

    private function shop(): DbConnection
    {
        $connection = DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Shop',
            'driver' => 'sqlite', 'database' => $this->path,
        ]);

        $probe = ProbeConnection::open($connection);
        $probe->statement('CREATE TABLE orders (id integer primary key, status text)');
        $probe->statement("INSERT INTO orders (id, status) VALUES (1, 'shipped')");

        $table = DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'orders', 'is_enabled' => true,
        ]);
        DbColumn::create(['table_id' => $table->id, 'column_name' => 'id', 'ordinal' => 1]);

        return $connection;
    }

    public function test_an_editor_opens_the_playground(): void
    {
        $this->shop();

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('databases.playground'))
            ->assertOk()
            ->assertSee('Shop');
    }

    public function test_a_viewer_cannot_open_it(): void
    {
        $this->shop();

        $this->actingAs($this->userWithRole('viewer'))
            ->get(route('databases.playground'))
            ->assertForbidden();
    }

    public function test_a_statement_runs_and_shows_its_rows(): void
    {
        $this->shop();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.playground.run'), [
                'connection_id' => 'dbc_1',
                'sql' => 'SELECT id, status FROM orders',
            ])
            ->assertOk()
            ->assertSee('shipped');
    }

    public function test_a_write_is_refused_with_its_reason(): void
    {
        $this->shop();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.playground.run'), [
                'connection_id' => 'dbc_1', 'sql' => 'DELETE FROM orders',
            ])
            ->assertOk()
            ->assertSee('not allowed');
    }

    public function test_another_workspaces_connection_cannot_be_run(): void
    {
        $this->shop();
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        DbConnection::create([
            'id' => 'dbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.playground.run'), [
                'connection_id' => 'dbc_other', 'sql' => 'SELECT 1',
            ])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=DbPlaygroundTest`
Expected: FAIL with `Route [databases.playground] not defined`.

- [ ] **Step 3: Write the controller**

Create `admin-laravel/app/Http/Controllers/DbPlaygroundController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\DbConnection;
use App\Services\Schema\DbQueryRunner;
use Illuminate\Http\Request;

/**
 * Where an editor tunes an annotation.
 *
 * Write a description, run the question a customer would ask, read the rows.
 * Without this, annotation is guesswork. It goes through DbQueryRunner, so
 * everything the chat path refuses is refused here identically.
 */
class DbPlaygroundController extends Controller
{
    public function show(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        if (!$activeSystem) {
            return redirect()->route('systems.index')->with('error', 'Select a workspace first.');
        }

        $this->authorizeEditor($request, $activeSystem->id);

        return view('databases.playground', [
            'activeSystem' => $activeSystem,
            'connections' => DbConnection::where('system_id', $activeSystem->id)
                ->orderBy('name')->get(),
            'result' => null,
            'sql' => '',
            'selected' => '',
        ]);
    }

    public function run(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        $validated = $request->validate([
            'connection_id' => ['required', 'string'],
            'sql' => ['required', 'string', 'max:8000'],
        ]);

        $connection = DbConnection::findOrFail($validated['connection_id']);
        abort_unless($connection->system_id === $activeSystem->id, 403);

        return view('databases.playground', [
            'activeSystem' => $activeSystem,
            'connections' => DbConnection::where('system_id', $activeSystem->id)
                ->orderBy('name')->get(),
            'result' => DbQueryRunner::run($connection, $validated['sql'], 50, 10),
            'sql' => $validated['sql'],
            'selected' => $connection->id,
        ]);
    }

    private function authorizeEditor(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }
}
```

- [ ] **Step 4: Write the view**

Create `admin-laravel/resources/views/databases/playground.blade.php`:

```blade
@extends('layouts.app')

@section('page-title', 'Database playground')

@section('content')

<div class="page-head mb-4">
    <div>
        <h1>Database playground</h1>
        <p>
            Run a statement the way a bot would, against the tables you have made
            readable. Everything the bot is refused, you are refused here too, so
            this is where you find out what your annotations are worth.
        </p>
    </div>
    <a href="{{ route('databases.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form action="{{ route('databases.playground.run') }}" method="POST">
            @csrf
            <div class="mb-3">
                <label class="form-label">Connection</label>
                <select name="connection_id" class="form-select" required>
                    @foreach($connections as $connection)
                        <option value="{{ $connection->id }}" @selected($selected === $connection->id)>
                            {{ $connection->name }}@unless($connection->is_enabled) (switched off)@endunless
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Statement</label>
                <textarea name="sql" rows="4" class="form-control figure-mono" required
                          placeholder="SELECT id, status FROM orders WHERE status = 'pending'">{{ $sql }}</textarea>
                <div class="form-text">Reading only. A row limit is applied whether or not you write one.</div>
            </div>

            <button class="btn btn-brand">Run</button>
        </form>
    </div>
</div>

@if($result !== null)
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Result</span>
            @if($result['ok'])
                <span class="text-muted" style="font-size: 0.8rem;">
                    {{ $result['row_count'] }} rows in {{ $result['elapsed_ms'] }} ms
                </span>
            @endif
        </div>

        @if(!$result['ok'])
            <div class="card-body">
                <div class="alert alert-danger mb-0">{{ $result['message'] }}</div>
            </div>
        @elseif(!$result['rows'])
            <div class="empty"><p>The statement ran and matched no rows.</p></div>
        @else
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th class="text-end text-muted" style="width: 48px;">#</th>
                            @foreach($result['columns'] as $column)
                                <th>{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($result['rows'] as $row)
                            <tr>
                                <td class="text-end figure-mono text-muted" style="font-size: 0.75rem;">
                                    {{ $loop->iteration }}
                                </td>
                                @foreach($row as $value)
                                    <td>{{ $value === null ? '—' : $value }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif

@endsection
```

- [ ] **Step 5: Register the routes and link to it**

In `admin-laravel/routes/web.php`, add the import `use App\Http\Controllers\DbPlaygroundController;` and, beside the other database routes and **before** the `/databases/{id}` routes:

```php
    // Distinct path, so it cannot be mistaken for /databases/{id}
    Route::get('/database-playground', [DbPlaygroundController::class, 'show'])->name('databases.playground');
    Route::post('/database-playground', [DbPlaygroundController::class, 'run'])->name('databases.playground.run');
```

In `admin-laravel/resources/views/databases/index.blade.php`, inside the `@if($canEdit)` block in the page head, before the New connection button:

```blade
        <a href="{{ route('databases.playground') }}" class="btn btn-outline-secondary">
            <i class="bi bi-terminal"></i> Playground
        </a>
```

Wrap the two buttons in a `<div class="d-flex align-items-center gap-2">` if they are not already.

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=DbPlaygroundTest`
Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```bash
git add admin-laravel/app/Http/Controllers/DbPlaygroundController.php \
        admin-laravel/resources/views/databases/playground.blade.php \
        admin-laravel/routes/web.php \
        admin-laravel/resources/views/databases/index.blade.php \
        admin-laravel/tests/Feature/DbPlaygroundTest.php
git commit -m "feat: a playground for tuning what an annotation is worth"
```

---

### Task 14: The transcript shows the statement, and the docs catch up

**Files:**
- Modify: `admin-laravel/resources/views/logs/transcript.blade.php` (find the actual view name with `grep -n "transcript" admin-laravel/app/Http/Controllers/LogController.php`)
- Modify: `README.md`
- Modify: `docs/architecture.md`
- Test: `admin-laravel/tests/Feature/TranscriptSqlTest.php`

**Interfaces:**
- Consumes: `ChatMessage.db_sql` and `ChatMessage.db_row_count` from stage one's migration.
- Produces: nothing in code.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/TranscriptSqlTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptSqlTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_can_see_the_statement_that_answered(): void
    {
        // Auditing a wrong answer needs the query, not a guess at it.
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Bot']);
        ChatConversation::create([
            'id' => 'conv_1', 'bot_id' => 'bot_1', 'session_id' => 's1', 'origin' => '',
        ]);
        ChatMessage::create([
            'id' => 'msg_1', 'conversation_id' => 'conv_1', 'sender' => 'assistant',
            'content' => 'One order is still pending.',
            'db_sql' => 'SELECT id, status FROM orders LIMIT 50',
            'db_row_count' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertSee('SELECT id, status FROM orders', false);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TranscriptSqlTest`
Expected: FAIL, the statement is not rendered.

- [ ] **Step 3: Show it in the transcript**

Find the block that renders `$message->reasoning` in the transcript view and add, immediately after it:

```blade
                @if($message->db_sql)
                    <details class="mt-2">
                        <summary class="text-muted" style="font-size: 0.75rem;">
                            Answered from live data, {{ $message->db_row_count }} rows
                        </summary>
                        <pre class="figure-mono mt-2 mb-0 p-2 bg-body-tertiary rounded"
                             style="font-size: 0.75rem; white-space: pre-wrap;">{{ $message->db_sql }}</pre>
                    </details>
                @endif
```

Add `'db_sql'` and `'db_row_count'` to `$fillable` on `admin-laravel/app/Models/ChatMessage.php` if they are not already there, so the test can create the row.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=TranscriptSqlTest`
Expected: PASS, 1 test.

- [ ] **Step 5: Update the README**

In `README.md`, replace the "Not yet wired into chat" bullet in the database section with:

```markdown
- **The bot decides for itself.** A router reads the question and picks live data, the documents, the web, or nothing at all. Every failure falls back to the documents and then the web, so it can only improve on the previous behaviour.
- **Read-only, twice over.** The generated statement is validated in the engine and validated again by the portal before anything runs. Only a single SELECT ever reaches a customer's database.
- **Auditable.** Every database-answered message keeps its statement and row count in the conversation log.
- **A playground for tuning.** Run a statement the way a bot would and see exactly what your annotations bought you.
```

- [ ] **Step 6: Update the architecture notes**

In `docs/architecture.md`, in the "Database connections" section, append:

```markdown
The query path reverses the call direction the architecture had kept one-way.
The engine calls the portal at `POST /internal/db/query`, carrying
`PORTAL_INTERNAL_TOKEN`, which is a different secret from the
`ENGINE_ADMIN_TOKEN` Laravel sends the other way. Compromising one direction
should not hand over the other.

The portal re-validates everything the engine already validated. Read-only
rules and the allowlist both run twice, in `dbquery/sql.py` and again in
`SqlGuard`. The duplication is deliberate: nothing reaches a customer's
database on the strength of a check that happened in another process.

| Concern | Lives in |
|---|---|
| Which source should answer | `api-engine/dbquery/routing.py` |
| The schema as the model is told it | `api-engine/dbquery/schema.py` |
| Statement validation and row limits | `api-engine/dbquery/sql.py` |
| Rows into a prompt, inside the budget | `api-engine/dbquery/context.py` |
| Asking the portal to run it | `api-engine/dbquery/portal.py` |
| Orchestration and fall-through | `api-engine/dbquery/__init__.py` |
| Running it, and refusing it again | `admin-laravel/app/Services/Schema/DbQueryRunner.php` |
```

- [ ] **Step 7: Run both suites**

Run: `php artisan test` from `admin-laravel/`
Expected: PASS.

Run: `.venv/Scripts/python.exe -m pytest -q` from `api-engine/`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add admin-laravel/resources/views/logs/ admin-laravel/app/Models/ChatMessage.php \
        admin-laravel/tests/Feature/TranscriptSqlTest.php README.md docs/architecture.md
git commit -m "docs: the statement is visible in the transcript, and the notes catch up"
```

---

## Manual check before calling it done

The tests prove the parts. This proves the whole, and it needs the demo shop
database from stage one plus both services running.

1. Put the same `PORTAL_INTERNAL_TOKEN` in `admin-laravel/.env` and `api-engine/.env`. Generate one with `php artisan key:generate --show`.
2. Start both services.
3. On the bot's brain screen, tick Query the database, attach the Shop database, and save.
4. Ask the bot "how many orders are still pending?". It should answer two, and the transcript should hold the statement.
5. Ask it "what is your refund policy?". It should route to the documents and the transcript should hold no statement.
6. Say "hello". Nothing should be logged, and the router should never have been called.
7. Switch the connection off from the Databases list and ask the first question again. It must fall back rather than answer from live data.
