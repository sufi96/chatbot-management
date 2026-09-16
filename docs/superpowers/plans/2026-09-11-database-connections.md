# Database Connections Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a workspace connect to its own database, discover the schema, and annotate every table and column in plain language, ready for a bot to query in stage two.

**Architecture:** Four new Laravel tables hold connections and the annotated schema, workspace scoped under a system exactly as `kb_collections` are. A `ProbeConnection` helper registers a runtime Laravel database connection from a stored record, so all four drivers go through PDO the framework already configures. `SchemaIntrospector` reads the shape, `SchemaMapper` turns rows into value objects, and `SchemaSync` merges those into stored rows without ever overwriting an annotation. Two controllers split by responsibility: one owns connections and credentials, one owns the schema editor.

**Tech Stack:** Laravel 13, PHP 8.3, Blade with Bootstrap 5 and Bootstrap Icons, PHPUnit 12 with `RefreshDatabase` over in-memory SQLite. No JavaScript build step. No new Composer packages: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite` and `pdo_sqlsrv` are already loaded.

**Spec:** `docs/superpowers/specs/2026-09-11-database-query-design.md`

This plan covers **stage one only**, as defined in section 17 of the spec: everything an operator touches. Nothing here changes how any bot answers. The chat path, the `dbquery` engine package, the internal query route and the playground are stage two and get their own plan.

## Global Constraints

- Drivers are exactly `mysql`, `pgsql`, `sqlsrv`, `sqlite`. Any other value is rejected by validation.
- Connection ids are `'dbc_' . Str::random(12)`, matching the `kbc_` and `kbs_` convention in `KnowledgeBaseController`.
- `db_connections.password` uses the `encrypted` cast. It is never passed to a view, never rendered into a form value, and an empty submitted password means "keep the existing one".
- `db_connections.options` uses the `array` cast.
- Introspection **merges**. It never writes `description` and never writes `is_enabled` on a row that already exists.
- A stored row introspection no longer sees gets `is_present = false`. It is never deleted by introspection.
- New tables and columns are created with `is_enabled = false`. Nothing becomes visible to a model without a person enabling it.
- System schemas are always excluded: `information_schema`, `performance_schema`, `mysql`, `sys`, `pg_catalog`, and any SQLite name starting `sqlite_`.
- A foreign key target is the string `"{table}.{column}"` when the schema is null, and `"{schema}.{table}.{column}"` when it is not.
- Permissions follow section 15 of the spec. Editor and above may create connections, edit annotations, and introspect. Viewer may read annotations and never sees credentials.
- Every controller method authorizes against `$user->canManageSystem($systemId, 'editor')` or `'viewer'`, using private helpers named `authorizeEditor` and `authorizeViewer`, copying the pattern at the bottom of `KnowledgeBaseController`.
- Run Laravel tests with `php artisan test` from `admin-laravel/`.
- Run a single test with `php artisan test --filter=TestClassName` from `admin-laravel/`.

---

### Task 1: Schema and models

**Files:**
- Create: `admin-laravel/database/migrations/2026_09_12_000001_create_database_connection_tables.php`
- Create: `admin-laravel/database/migrations/2026_09_12_000002_add_db_query_to_bot_profiles.php`
- Create: `admin-laravel/database/migrations/2026_09_12_000003_add_db_query_to_chat_messages.php`
- Create: `admin-laravel/app/Models/DbConnection.php`
- Create: `admin-laravel/app/Models/DbTable.php`
- Create: `admin-laravel/app/Models/DbColumn.php`
- Modify: `admin-laravel/app/Models/System.php`
- Modify: `admin-laravel/app/Models/BotProfile.php`
- Test: `admin-laravel/tests/Feature/DatabaseConnectionSchemaTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Models\DbConnection` with `$fillable = ['id','system_id','name','driver','host','port','database','username','password','options','status','error_message','last_introspected_at']`, casts `password` to `encrypted`, `options` to `array`, `last_introspected_at` to `datetime`, and relations `system()`, `tables()`, `bots()`. `App\Models\DbTable` with `$fillable = ['connection_id','schema_name','table_name','description','is_enabled','is_present']`, relations `connection()` and `columns()` (ordered by ordinal), and `qualifiedName(): string` returning `"schema.table"` or just `"table"` when the schema is null. `App\Models\DbColumn` with `$fillable = ['table_id','column_name','data_type','is_nullable','is_primary_key','foreign_key_target','description','ordinal','is_present']` and relation `table()`. `System::dbConnections()` and `BotProfile::dbConnections()`.

The three migrations arrive together even though only the first is used in stage one. The bot and message columns are stage two's, but putting them in now means stage two adds no migration and cannot leave a half-migrated database behind.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/DatabaseConnectionSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseConnectionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeSystem(): System
    {
        return System::create([
            'id' => 'sys_test',
            'name' => 'Test Workspace',
            'allowed_origins' => '*',
        ]);
    }

    private function makeConnection(): DbConnection
    {
        $this->makeSystem();

        return DbConnection::create([
            'id' => 'dbc_1',
            'system_id' => 'sys_test',
            'name' => 'Orders database',
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    public function test_a_connection_belongs_to_a_workspace(): void
    {
        $connection = $this->makeConnection();

        $this->assertSame('sys_test', $connection->system->id);
        $this->assertCount(1, System::find('sys_test')->dbConnections);
    }

    public function test_a_new_connection_is_untested_and_never_introspected(): void
    {
        // Read back rather than trusting the instance just created: the
        // defaults being tested belong to the column, not to the model.
        $connection = $this->makeConnection()->fresh();

        $this->assertSame('untested', $connection->status);
        $this->assertNull($connection->last_introspected_at);
        $this->assertNull($connection->error_message);
    }

    public function test_the_password_is_encrypted_at_rest(): void
    {
        $this->makeSystem();
        DbConnection::create([
            'id' => 'dbc_2',
            'system_id' => 'sys_test',
            'name' => 'Billing',
            'driver' => 'mysql',
            'host' => 'localhost',
            'database' => 'billing',
            'username' => 'reader',
            'password' => 'hunter2',
        ]);

        $raw = DB::table('db_connections')->where('id', 'dbc_2')->value('password');

        $this->assertNotSame('hunter2', $raw);
        $this->assertSame('hunter2', DbConnection::find('dbc_2')->password);
    }

    public function test_options_round_trip_as_an_array(): void
    {
        $this->makeSystem();
        DbConnection::create([
            'id' => 'dbc_3',
            'system_id' => 'sys_test',
            'name' => 'Reporting',
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'reporting',
            'options' => ['sslmode' => 'require'],
        ]);

        $this->assertSame(['sslmode' => 'require'], DbConnection::find('dbc_3')->options);
    }

    public function test_a_discovered_table_starts_disabled_and_present(): void
    {
        $connection = $this->makeConnection();

        $table = DbTable::create([
            'connection_id' => $connection->id,
            'schema_name' => null,
            'table_name' => 'orders',
        ])->fresh();

        $this->assertFalse((bool) $table->is_enabled);
        $this->assertTrue((bool) $table->is_present);
        $this->assertNull($table->description);
    }

    public function test_columns_hang_off_a_table_and_cascade_with_it(): void
    {
        $connection = $this->makeConnection();
        $table = DbTable::create([
            'connection_id' => $connection->id,
            'table_name' => 'orders',
        ]);
        DbColumn::create([
            'table_id' => $table->id,
            'column_name' => 'id',
            'data_type' => 'integer',
            'is_primary_key' => true,
            'ordinal' => 1,
        ]);

        $this->assertCount(1, $table->fresh()->columns);

        $table->delete();

        $this->assertSame(0, DbColumn::count());
    }

    public function test_deleting_a_connection_takes_its_schema_with_it(): void
    {
        $connection = $this->makeConnection();
        $table = DbTable::create(['connection_id' => $connection->id, 'table_name' => 'orders']);
        DbColumn::create(['table_id' => $table->id, 'column_name' => 'id', 'ordinal' => 1]);

        $connection->delete();

        $this->assertSame(0, DbTable::count());
        $this->assertSame(0, DbColumn::count());
    }

    public function test_a_connection_attaches_to_bots(): void
    {
        $connection = $this->makeConnection();
        $bot = BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support bot',
        ]);

        $connection->bots()->attach($bot->id);

        $this->assertCount(1, $connection->fresh()->bots);
        $this->assertCount(1, $bot->fresh()->dbConnections);
    }

    public function test_the_stage_two_columns_exist_with_their_defaults(): void
    {
        $this->makeSystem();
        $bot = BotProfile::create([
            'id' => 'bot_2', 'system_id' => 'sys_test', 'name' => 'Bot',
        ])->fresh();

        $this->assertFalse((bool) $bot->db_query_enabled);
        $this->assertSame(50, (int) $bot->db_max_rows);
        $this->assertSame(10, (int) $bot->db_query_timeout);

        $this->assertTrue(Schema::hasColumn('chat_messages', 'db_sql'));
        $this->assertTrue(Schema::hasColumn('chat_messages', 'db_row_count'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=DatabaseConnectionSchemaTest`
Expected: FAIL with `Class "App\Models\DbConnection" not found`.

- [ ] **Step 3: Write the first migration**

Create `admin-laravel/database/migrations/2026_09_12_000001_create_database_connection_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A workspace's own database, and the schema somebody annotated so a
     * model can write sensible SQL against it. Scoped to a system exactly as
     * kb_collections are, and attached to bots through a pivot shaped like
     * bot_kb_collection.
     */
    public function up(): void
    {
        Schema::create('db_connections', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('system_id', 36);
            $table->string('name', 255);
            $table->string('driver', 20);                  // mysql, pgsql, sqlsrv, sqlite
            $table->string('host', 255)->nullable();       // null for sqlite
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('database', 255);               // a file path when sqlite
            $table->string('username', 255)->nullable();
            $table->text('password')->nullable();          // encrypted cast on the model
            $table->json('options')->nullable();
            $table->string('status', 20)->default('untested');  // untested, ok, failed
            $table->text('error_message')->nullable();
            $table->timestamp('last_introspected_at')->nullable();
            $table->timestamps();

            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
        });

        Schema::create('db_tables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('connection_id', 36);
            $table->string('schema_name', 128)->nullable();
            $table->string('table_name', 128);
            $table->text('description')->nullable();
            // is_enabled is the allowlist. Nothing reaches a model without it.
            $table->boolean('is_enabled')->default(false);
            // Absent rather than deleted, so an annotation survives a schema
            // change or a permissions blip.
            $table->boolean('is_present')->default(true);
            $table->timestamps();

            $table->foreign('connection_id')->references('id')->on('db_connections')->cascadeOnDelete();
            $table->unique(['connection_id', 'schema_name', 'table_name'], 'db_tables_unique_name');
        });

        Schema::create('db_columns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('table_id');
            $table->string('column_name', 128);
            $table->string('data_type', 64)->nullable();   // null when added by hand
            $table->boolean('is_nullable')->default(true);
            $table->boolean('is_primary_key')->default(false);
            $table->string('foreign_key_target', 255)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('ordinal')->default(0);
            $table->boolean('is_present')->default(true);
            $table->timestamps();

            $table->foreign('table_id')->references('id')->on('db_tables')->cascadeOnDelete();
            $table->unique(['table_id', 'column_name'], 'db_columns_unique_name');
        });

        Schema::create('bot_db_connection', function (Blueprint $table) {
            $table->id();
            $table->string('bot_id', 36);
            $table->string('connection_id', 36);

            $table->foreign('bot_id')->references('id')->on('bot_profiles')->cascadeOnDelete();
            $table->foreign('connection_id')->references('id')->on('db_connections')->cascadeOnDelete();
            $table->unique(['bot_id', 'connection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_db_connection');
        Schema::dropIfExists('db_columns');
        Schema::dropIfExists('db_tables');
        Schema::dropIfExists('db_connections');
    }
};
```

- [ ] **Step 4: Write the two column migrations**

Create `admin-laravel/database/migrations/2026_09_12_000002_add_db_query_to_bot_profiles.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage two reads these. They arrive with stage one's migration so the
     * chat path adds no migration of its own and cannot leave a database
     * half way between the two.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('db_query_enabled')->default(false);
            $table->unsignedSmallInteger('db_max_rows')->default(50);
            $table->unsignedSmallInteger('db_query_timeout')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['db_query_enabled', 'db_max_rows', 'db_query_timeout']);
        });
    }
};
```

Create `admin-laravel/database/migrations/2026_09_12_000003_add_db_query_to_chat_messages.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The statement that produced an answer, beside the reasoning column
     * phase 5 added. An operator auditing a wrong answer needs to see the
     * query, not guess at it.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->text('db_sql')->nullable();
            $table->unsignedInteger('db_row_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['db_sql', 'db_row_count']);
        });
    }
};
```

- [ ] **Step 5: Write the three models**

Create `admin-laravel/app/Models/DbConnection.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DbConnection extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'system_id', 'name', 'driver', 'host', 'port', 'database',
        'username', 'password', 'options', 'status', 'error_message',
        'last_introspected_at',
    ];

    /**
     * The encrypted cast puts the password beyond a database dump. It does
     * not put it beyond the application key, which is why the connection
     * form asks for a read-only account.
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'options' => 'array',
            'last_introspected_at' => 'datetime',
        ];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class, 'system_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DbTable::class, 'connection_id');
    }

    public function bots(): BelongsToMany
    {
        return $this->belongsToMany(
            BotProfile::class, 'bot_db_connection', 'connection_id', 'bot_id');
    }
}
```

Create `admin-laravel/app/Models/DbTable.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DbTable extends Model
{
    protected $fillable = [
        'connection_id', 'schema_name', 'table_name', 'description',
        'is_enabled', 'is_present',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'is_present' => 'boolean'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(DbConnection::class, 'connection_id');
    }

    public function columns(): HasMany
    {
        return $this->hasMany(DbColumn::class, 'table_id')->orderBy('ordinal');
    }

    /** How the table is named in SQL and in the prompt. */
    public function qualifiedName(): string
    {
        return $this->schema_name
            ? "{$this->schema_name}.{$this->table_name}"
            : $this->table_name;
    }
}
```

Create `admin-laravel/app/Models/DbColumn.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DbColumn extends Model
{
    protected $fillable = [
        'table_id', 'column_name', 'data_type', 'is_nullable',
        'is_primary_key', 'foreign_key_target', 'description', 'ordinal',
        'is_present',
    ];

    protected function casts(): array
    {
        return [
            'is_nullable' => 'boolean',
            'is_primary_key' => 'boolean',
            'is_present' => 'boolean',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DbTable::class, 'table_id');
    }
}
```

- [ ] **Step 6: Add the two relations to existing models**

In `admin-laravel/app/Models/System.php`, after the `kbCollections()` method, add:

```php
    public function dbConnections(): HasMany
    {
        return $this->hasMany(DbConnection::class, 'system_id');
    }
```

In `admin-laravel/app/Models/BotProfile.php`, add the import `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` if it is not already there, then add:

```php
    public function dbConnections(): BelongsToMany
    {
        return $this->belongsToMany(
            DbConnection::class, 'bot_db_connection', 'bot_id', 'connection_id');
    }
```

Also add `'db_query_enabled'`, `'db_max_rows'` and `'db_query_timeout'` to the model's `$fillable` array, beside the `web_search_*` entries.

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test --filter=DatabaseConnectionSchemaTest`
Expected: PASS, 10 tests.

- [ ] **Step 8: Run the whole suite to check nothing regressed**

Run: `php artisan test`
Expected: PASS. The new `bot_profiles` and `chat_messages` columns must not break `BotBrainTest`, `BotWebSearchTest` or `ChatMessageReasoningTest`.

- [ ] **Step 9: Commit**

```bash
git add admin-laravel/database/migrations/2026_09_12_000001_create_database_connection_tables.php \
        admin-laravel/database/migrations/2026_09_12_000002_add_db_query_to_bot_profiles.php \
        admin-laravel/database/migrations/2026_09_12_000003_add_db_query_to_chat_messages.php \
        admin-laravel/app/Models/DbConnection.php \
        admin-laravel/app/Models/DbTable.php \
        admin-laravel/app/Models/DbColumn.php \
        admin-laravel/app/Models/System.php \
        admin-laravel/app/Models/BotProfile.php \
        admin-laravel/tests/Feature/DatabaseConnectionSchemaTest.php
git commit -m "feat: tables for database connections and their annotated schema"
```

---

### Task 2: Probe connection and the value objects

**Files:**
- Create: `admin-laravel/app/Services/Schema/DiscoveredColumn.php`
- Create: `admin-laravel/app/Services/Schema/DiscoveredTable.php`
- Create: `admin-laravel/app/Services/Schema/ProbeConnection.php`
- Test: `admin-laravel/tests/Feature/ProbeConnectionTest.php`

**Interfaces:**
- Consumes: `App\Models\DbConnection` from Task 1.
- Produces:
  - `App\Services\Schema\DiscoveredColumn` with readonly public properties `string $name`, `?string $dataType`, `bool $isNullable`, `bool $isPrimaryKey`, `?string $foreignKeyTarget`, `int $ordinal`, in that constructor order.
  - `App\Services\Schema\DiscoveredTable` with readonly public properties `?string $schema`, `string $name`, `array $columns` (a list of `DiscoveredColumn`), in that constructor order, plus `key(): string` returning `"{schema}|{name}"` with an empty schema segment when it is null. `SchemaSync` matches on that key.
  - `ProbeConnection::NAME` the string `'db_probe'`.
  - `ProbeConnection::config(DbConnection $connection): array` — a Laravel database connection config array. Throws `InvalidArgumentException` on an unknown driver.
  - `ProbeConnection::open(DbConnection $connection): \Illuminate\Database\Connection` — registers the config at runtime, purges any stale connection, and returns the live one.

The config builder is separated from the opening so it can be tested for all four drivers without any of those four servers existing.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/ProbeConnectionTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DbConnection;
use App\Services\Schema\ProbeConnection;
use Tests\TestCase;

class ProbeConnectionTest extends TestCase
{
    /**
     * Unsaved on purpose. ProbeConnection only reads attributes, so nothing
     * here needs a database, and two connections in one test cannot collide
     * on a primary key.
     */
    private function connection(array $overrides = []): DbConnection
    {
        return new DbConnection(array_merge([
            'id' => 'dbc_1',
            'system_id' => 'sys_test',
            'name' => 'Orders',
            'driver' => 'mysql',
            'host' => 'db.internal',
            'database' => 'shop',
            'username' => 'reader',
            'password' => 'secret',
        ], $overrides));
    }

    public function test_mysql_gets_its_default_port(): void
    {
        $config = ProbeConnection::config($this->connection());

        $this->assertSame('mysql', $config['driver']);
        $this->assertSame(3306, $config['port']);
        $this->assertSame('shop', $config['database']);
        $this->assertSame('reader', $config['username']);
        $this->assertSame('secret', $config['password']);
    }

    public function test_a_stored_port_wins_over_the_default(): void
    {
        $config = ProbeConnection::config($this->connection(['port' => 3307]));

        $this->assertSame(3307, $config['port']);
    }

    public function test_postgres_defaults_to_prefer_and_honours_an_option(): void
    {
        $plain = ProbeConnection::config($this->connection(['driver' => 'pgsql']));
        $this->assertSame(5432, $plain['port']);
        $this->assertSame('prefer', $plain['sslmode']);

        $strict = ProbeConnection::config($this->connection([
            'driver' => 'pgsql', 'options' => ['sslmode' => 'require'],
        ]));
        $this->assertSame('require', $strict['sslmode']);
    }

    public function test_sql_server_defaults_to_trusting_the_certificate(): void
    {
        $config = ProbeConnection::config($this->connection(['driver' => 'sqlsrv']));

        $this->assertSame(1433, $config['port']);
        $this->assertTrue($config['trust_server_certificate']);
    }

    public function test_sqlite_carries_only_a_file_path(): void
    {
        $config = ProbeConnection::config($this->connection([
            'driver' => 'sqlite', 'database' => '/data/shop.sqlite',
        ]));

        $this->assertSame('sqlite', $config['driver']);
        $this->assertSame('/data/shop.sqlite', $config['database']);
        $this->assertArrayNotHasKey('host', $config);
    }

    public function test_an_unknown_driver_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProbeConnection::config($this->connection(['driver' => 'oracle']));
    }

    public function test_opening_a_sqlite_file_gives_a_usable_connection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'probe') . '.sqlite';
        touch($path);

        try {
            $probe = ProbeConnection::open($this->connection([
                'driver' => 'sqlite', 'database' => $path,
            ]));
            $probe->statement('CREATE TABLE widgets (id integer primary key)');

            $this->assertSame(0, (int) $probe->table('widgets')->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_opening_twice_does_not_reuse_the_first_file(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'probe') . '-a.sqlite';
        $second = tempnam(sys_get_temp_dir(), 'probe') . '-b.sqlite';
        touch($first);
        touch($second);

        try {
            ProbeConnection::open($this->connection([
                'driver' => 'sqlite', 'database' => $first,
            ]))->statement('CREATE TABLE only_in_first (id integer)');

            $probe = ProbeConnection::open($this->connection([
                'driver' => 'sqlite', 'database' => $second,
            ]));

            // Without the purge, the second open would hand back the first
            // file's live PDO and this table would exist.
            $this->assertFalse($probe->getSchemaBuilder()->hasTable('only_in_first'));
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ProbeConnectionTest`
Expected: FAIL with `Class "App\Services\Schema\ProbeConnection" not found`.

- [ ] **Step 3: Write the value objects**

Create `admin-laravel/app/Services/Schema/DiscoveredColumn.php`:

```php
<?php

namespace App\Services\Schema;

/**
 * One column as the database describes it. Carries no annotation: a
 * description belongs to the stored row, never to a discovery run, which is
 * what stops introspection overwriting what a person wrote.
 */
final class DiscoveredColumn
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $dataType,
        public readonly bool $isNullable,
        public readonly bool $isPrimaryKey,
        public readonly ?string $foreignKeyTarget,
        public readonly int $ordinal,
    ) {
    }
}
```

Create `admin-laravel/app/Services/Schema/DiscoveredTable.php`:

```php
<?php

namespace App\Services\Schema;

final class DiscoveredTable
{
    /** @param list<DiscoveredColumn> $columns */
    public function __construct(
        public readonly ?string $schema,
        public readonly string $name,
        public readonly array $columns,
    ) {
    }

    /** The key a discovery run and a stored row are matched on. */
    public function key(): string
    {
        return ($this->schema ?? '') . '|' . $this->name;
    }
}
```

- [ ] **Step 4: Write the probe connection**

Create `admin-laravel/app/Services/Schema/ProbeConnection.php`:

```php
<?php

namespace App\Services\Schema;

use App\Models\DbConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A Laravel database connection built from a stored record at request time.
 *
 * Registering the config rather than opening PDO by hand means all four
 * drivers go through the same layer the framework already configures, and
 * the query builder is available for introspection.
 */
final class ProbeConnection
{
    public const NAME = 'db_probe';

    public static function open(DbConnection $connection): Connection
    {
        Config::set('database.connections.' . self::NAME, self::config($connection));

        // Without the purge a second open in the same request would hand
        // back the first connection's live PDO, pointed at the wrong
        // database.
        DB::purge(self::NAME);

        return DB::connection(self::NAME);
    }

    public static function config(DbConnection $c): array
    {
        $options = $c->options ?? [];

        return match ($c->driver) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => $c->database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => $c->host,
                'port' => (int) ($c->port ?: 3306),
                'database' => $c->database,
                'username' => $c->username,
                'password' => $c->password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => false,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => $c->host,
                'port' => (int) ($c->port ?: 5432),
                'database' => $c->database,
                'username' => $c->username,
                'password' => $c->password,
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => $options['search_path'] ?? 'public',
                'sslmode' => $options['sslmode'] ?? 'prefer',
            ],
            'sqlsrv' => [
                'driver' => 'sqlsrv',
                'host' => $c->host,
                'port' => (int) ($c->port ?: 1433),
                'database' => $c->database,
                'username' => $c->username,
                'password' => $c->password,
                'charset' => 'utf8',
                'prefix' => '',
                'trust_server_certificate' => (bool) ($options['trust_server_certificate'] ?? true),
            ],
            default => throw new InvalidArgumentException("Unknown driver [{$c->driver}]."),
        };
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=ProbeConnectionTest`
Expected: PASS, 8 tests.

- [ ] **Step 6: Commit**

```bash
git add admin-laravel/app/Services/Schema/ admin-laravel/tests/Feature/ProbeConnectionTest.php
git commit -m "feat: build a live database connection from a stored record"
```

---

### Task 3: Mapping information schema rows into tables

**Files:**
- Create: `admin-laravel/app/Services/Schema/SchemaMapper.php`
- Test: `admin-laravel/tests/Feature/SchemaMapperTest.php`

**Interfaces:**
- Consumes: `DiscoveredTable` and `DiscoveredColumn` from Task 2.
- Produces: `SchemaMapper::fromRows(array $columnRows, array $constraintRows): array` returning a list of `DiscoveredTable` ordered by schema then table name, columns ordered by ordinal.
  - A `$columnRows` entry is an array with keys `schema`, `table`, `column`, `data_type`, `is_nullable` (the string `'YES'` or `'NO'`), `ordinal`.
  - A `$constraintRows` entry is an array with keys `schema`, `table`, `column`, `constraint_type` (`'PRIMARY KEY'` or `'FOREIGN KEY'`), `ref_schema`, `ref_table`, `ref_column`.

Mapping is a pure function over rows, separated from the queries that produce them, because no test here can stand up MySQL, PostgreSQL and SQL Server. The queries in Task 4 normalize three dialects into these two row shapes, and this is where the result is turned into value objects.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/SchemaMapperTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Services\Schema\SchemaMapper;
use Tests\TestCase;

class SchemaMapperTest extends TestCase
{
    private function columnRow(array $overrides = []): array
    {
        return array_merge([
            'schema' => 'public',
            'table' => 'orders',
            'column' => 'id',
            'data_type' => 'integer',
            'is_nullable' => 'NO',
            'ordinal' => 1,
        ], $overrides);
    }

    public function test_columns_are_grouped_into_their_table(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(),
            $this->columnRow(['column' => 'total', 'data_type' => 'numeric', 'ordinal' => 2]),
        ], []);

        $this->assertCount(1, $tables);
        $this->assertSame('orders', $tables[0]->name);
        $this->assertSame('public', $tables[0]->schema);
        $this->assertCount(2, $tables[0]->columns);
        $this->assertSame('id', $tables[0]->columns[0]->name);
        $this->assertSame('total', $tables[0]->columns[1]->name);
    }

    public function test_columns_come_back_in_ordinal_order_whatever_the_rows_said(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['column' => 'total', 'ordinal' => 2]),
            $this->columnRow(['column' => 'id', 'ordinal' => 1]),
        ], []);

        $this->assertSame(['id', 'total'], array_map(
            fn ($c) => $c->name, $tables[0]->columns));
    }

    public function test_two_schemas_may_hold_the_same_table_name(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['schema' => 'public']),
            $this->columnRow(['schema' => 'archive']),
        ], []);

        $this->assertCount(2, $tables);
        // Ordered by schema, so archive comes first.
        $this->assertSame('archive', $tables[0]->schema);
        $this->assertSame('public', $tables[1]->schema);
    }

    public function test_nullability_reads_the_ansi_yes_and_no(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['column' => 'id', 'is_nullable' => 'NO', 'ordinal' => 1]),
            $this->columnRow(['column' => 'note', 'is_nullable' => 'YES', 'ordinal' => 2]),
        ], []);

        $this->assertFalse($tables[0]->columns[0]->isNullable);
        $this->assertTrue($tables[0]->columns[1]->isNullable);
    }

    public function test_a_primary_key_constraint_marks_its_column(): void
    {
        $tables = SchemaMapper::fromRows([$this->columnRow()], [[
            'schema' => 'public', 'table' => 'orders', 'column' => 'id',
            'constraint_type' => 'PRIMARY KEY',
            'ref_schema' => null, 'ref_table' => null, 'ref_column' => null,
        ]]);

        $this->assertTrue($tables[0]->columns[0]->isPrimaryKey);
        $this->assertNull($tables[0]->columns[0]->foreignKeyTarget);
    }

    public function test_a_foreign_key_becomes_a_qualified_target(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['column' => 'customer_id', 'ordinal' => 2]),
        ], [[
            'schema' => 'public', 'table' => 'orders', 'column' => 'customer_id',
            'constraint_type' => 'FOREIGN KEY',
            'ref_schema' => 'public', 'ref_table' => 'customers', 'ref_column' => 'id',
        ]]);

        $this->assertSame('public.customers.id', $tables[0]->columns[0]->foreignKeyTarget);
    }

    public function test_an_unqualified_foreign_key_drops_the_schema(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['schema' => null, 'column' => 'customer_id']),
        ], [[
            'schema' => null, 'table' => 'orders', 'column' => 'customer_id',
            'constraint_type' => 'FOREIGN KEY',
            'ref_schema' => null, 'ref_table' => 'customers', 'ref_column' => 'id',
        ]]);

        $this->assertSame('customers.id', $tables[0]->columns[0]->foreignKeyTarget);
    }

    public function test_a_constraint_for_an_unknown_column_is_ignored(): void
    {
        // A restricted account can see constraints on tables whose columns it
        // cannot list. That must not invent a column.
        $tables = SchemaMapper::fromRows([$this->columnRow()], [[
            'schema' => 'public', 'table' => 'invoices', 'column' => 'id',
            'constraint_type' => 'PRIMARY KEY',
            'ref_schema' => null, 'ref_table' => null, 'ref_column' => null,
        ]]);

        $this->assertCount(1, $tables);
        $this->assertSame('orders', $tables[0]->name);
    }

    public function test_a_composite_primary_key_marks_every_column(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['column' => 'order_id', 'ordinal' => 1]),
            $this->columnRow(['column' => 'line_no', 'ordinal' => 2]),
        ], [
            ['schema' => 'public', 'table' => 'orders', 'column' => 'order_id',
             'constraint_type' => 'PRIMARY KEY', 'ref_schema' => null,
             'ref_table' => null, 'ref_column' => null],
            ['schema' => 'public', 'table' => 'orders', 'column' => 'line_no',
             'constraint_type' => 'PRIMARY KEY', 'ref_schema' => null,
             'ref_table' => null, 'ref_column' => null],
        ]);

        $this->assertTrue($tables[0]->columns[0]->isPrimaryKey);
        $this->assertTrue($tables[0]->columns[1]->isPrimaryKey);
    }

    public function test_no_rows_is_no_tables_not_an_error(): void
    {
        $this->assertSame([], SchemaMapper::fromRows([], []));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SchemaMapperTest`
Expected: FAIL with `Class "App\Services\Schema\SchemaMapper" not found`.

- [ ] **Step 3: Write the mapper**

Create `admin-laravel/app/Services/Schema/SchemaMapper.php`:

```php
<?php

namespace App\Services\Schema;

/**
 * Normalized information schema rows, turned into value objects.
 *
 * Pure, so it can be tested against all three dialects' output without any
 * of those three servers existing. The queries that produce these rows live
 * in SchemaIntrospector and are the only dialect-aware part.
 */
final class SchemaMapper
{
    /**
     * @param list<array<string,mixed>> $columnRows
     * @param list<array<string,mixed>> $constraintRows
     * @return list<DiscoveredTable>
     */
    public static function fromRows(array $columnRows, array $constraintRows): array
    {
        $keys = [];
        foreach ($constraintRows as $row) {
            $key = self::key($row['schema'] ?? null, $row['table'], $row['column']);

            if ($row['constraint_type'] === 'PRIMARY KEY') {
                $keys[$key]['primary'] = true;
                continue;
            }

            if ($row['constraint_type'] === 'FOREIGN KEY' && !empty($row['ref_table'])) {
                $keys[$key]['target'] = self::target(
                    $row['ref_schema'] ?? null, $row['ref_table'], $row['ref_column']);
            }
        }

        $grouped = [];
        foreach ($columnRows as $row) {
            $schema = $row['schema'] !== null && $row['schema'] !== ''
                ? (string) $row['schema'] : null;
            $tableKey = ($schema ?? '') . '|' . $row['table'];
            $columnKey = self::key($schema, $row['table'], $row['column']);

            $grouped[$tableKey]['schema'] = $schema;
            $grouped[$tableKey]['table'] = (string) $row['table'];
            $grouped[$tableKey]['columns'][] = new DiscoveredColumn(
                name: (string) $row['column'],
                dataType: $row['data_type'] !== null ? (string) $row['data_type'] : null,
                isNullable: strtoupper((string) $row['is_nullable']) === 'YES',
                isPrimaryKey: $keys[$columnKey]['primary'] ?? false,
                foreignKeyTarget: $keys[$columnKey]['target'] ?? null,
                ordinal: (int) $row['ordinal'],
            );
        }

        ksort($grouped);

        $tables = [];
        foreach ($grouped as $entry) {
            $columns = $entry['columns'];
            usort($columns, fn ($a, $b) => $a->ordinal <=> $b->ordinal);
            $tables[] = new DiscoveredTable($entry['schema'], $entry['table'], $columns);
        }

        return $tables;
    }

    private static function key(?string $schema, string $table, string $column): string
    {
        return ($schema ?? '') . '|' . $table . '|' . $column;
    }

    private static function target(?string $schema, string $table, ?string $column): string
    {
        $parts = array_filter([$schema, $table, $column], fn ($p) => $p !== null && $p !== '');

        return implode('.', $parts);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=SchemaMapperTest`
Expected: PASS, 10 tests.

- [ ] **Step 5: Commit**

```bash
git add admin-laravel/app/Services/Schema/SchemaMapper.php admin-laravel/tests/Feature/SchemaMapperTest.php
git commit -m "feat: map information schema rows into discovered tables"
```

---

### Task 4: Introspecting a live database

**Files:**
- Create: `admin-laravel/app/Services/Schema/SchemaIntrospector.php`
- Test: `admin-laravel/tests/Feature/SchemaIntrospectorTest.php`

**Interfaces:**
- Consumes: `ProbeConnection`, `DiscoveredTable`, `DiscoveredColumn`, `SchemaMapper` from Tasks 2 and 3.
- Produces:
  - `SchemaIntrospector::discover(DbConnection $connection): array` returning a list of `DiscoveredTable`. Throws whatever PDO throws, so the caller can show the driver's own message.
  - `SchemaIntrospector::test(DbConnection $connection): array` returning `['ok' => bool, 'message' => string]`. Never throws.

SQL Server and MySQL disagree with the ANSI standard in different places, so there are two constraint queries rather than one. MySQL puts the referenced side directly on `key_column_usage`. PostgreSQL and SQL Server need `referential_constraints` joined back to `key_column_usage` to reach it.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/SchemaIntrospectorTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DbConnection;
use App\Models\System;
use App\Services\Schema\ProbeConnection;
use App\Services\Schema\SchemaIntrospector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaIntrospectorTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'introspect') . '.sqlite';
        touch($this->path);

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function connection(): DbConnection
    {
        return DbConnection::create([
            'id' => 'dbc_1',
            'system_id' => 'sys_test',
            'name' => 'Shop',
            'driver' => 'sqlite',
            'database' => $this->path,
        ]);
    }

    private function seedShop(): DbConnection
    {
        $connection = $this->connection();
        $probe = ProbeConnection::open($connection);

        $probe->statement('CREATE TABLE customers (
            id integer primary key,
            name text not null,
            email text
        )');
        $probe->statement('CREATE TABLE orders (
            id integer primary key,
            customer_id integer not null references customers(id),
            total numeric not null,
            placed_at text
        )');

        return $connection;
    }

    public function test_every_table_is_found(): void
    {
        $tables = SchemaIntrospector::discover($this->seedShop());

        $this->assertSame(['customers', 'orders'], array_map(fn ($t) => $t->name, $tables));
    }

    public function test_sqlite_tables_have_no_schema(): void
    {
        $tables = SchemaIntrospector::discover($this->seedShop());

        $this->assertNull($tables[0]->schema);
    }

    public function test_columns_carry_their_type_and_nullability(): void
    {
        $tables = SchemaIntrospector::discover($this->seedShop());
        $customers = $tables[0];

        $this->assertSame(['id', 'name', 'email'], array_map(
            fn ($c) => $c->name, $customers->columns));
        $this->assertSame('text', strtolower($customers->columns[1]->dataType));
        $this->assertFalse($customers->columns[1]->isNullable);
        $this->assertTrue($customers->columns[2]->isNullable);
    }

    public function test_the_primary_key_is_marked(): void
    {
        $tables = SchemaIntrospector::discover($this->seedShop());

        $this->assertTrue($tables[0]->columns[0]->isPrimaryKey);
        $this->assertFalse($tables[0]->columns[1]->isPrimaryKey);
    }

    public function test_a_foreign_key_points_at_its_target(): void
    {
        $tables = SchemaIntrospector::discover($this->seedShop());
        $orders = $tables[1];

        $this->assertSame('customers.id', $orders->columns[1]->foreignKeyTarget);
        $this->assertNull($orders->columns[2]->foreignKeyTarget);
    }

    public function test_sqlite_internal_tables_are_excluded(): void
    {
        // SQLite refuses a table named sqlite_*, so the internal table has to
        // be provoked rather than created. AUTOINCREMENT makes sqlite_sequence.
        $connection = $this->seedShop();
        $probe = ProbeConnection::open($connection);
        $probe->statement('CREATE TABLE receipts (id integer primary key autoincrement, note text)');
        $probe->statement("INSERT INTO receipts (note) VALUES ('first')");

        $names = array_map(fn ($t) => $t->name, SchemaIntrospector::discover($connection));

        $this->assertContains('receipts', $names);
        $this->assertNotContains('sqlite_sequence', $names);
    }

    public function test_an_empty_database_discovers_nothing_without_erroring(): void
    {
        $this->assertSame([], SchemaIntrospector::discover($this->connection()));
    }

    public function test_a_reachable_database_tests_ok(): void
    {
        $result = SchemaIntrospector::test($this->seedShop());

        $this->assertTrue($result['ok']);
    }

    public function test_an_unreachable_database_reports_rather_than_throws(): void
    {
        $connection = $this->connection();
        $connection->driver = 'mysql';
        $connection->host = '127.0.0.1';
        $connection->port = 1;          // nothing listens here
        $connection->database = 'nope';

        $result = SchemaIntrospector::test($connection);

        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['message']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SchemaIntrospectorTest`
Expected: FAIL with `Class "App\Services\Schema\SchemaIntrospector" not found`.

- [ ] **Step 3: Write the introspector**

Create `admin-laravel/app/Services/Schema/SchemaIntrospector.php`:

```php
<?php

namespace App\Services\Schema;

use App\Models\DbConnection;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Reads the shape of a customer's database.
 *
 * SQLite has no information schema and gets its own path. The other three
 * share one, with two constraint queries rather than one: MySQL puts the
 * referenced side of a foreign key directly on key_column_usage, while
 * PostgreSQL and SQL Server make you reach it through
 * referential_constraints.
 */
final class SchemaIntrospector
{
    /** Never described to a model, never worth an operator's attention. */
    private const SYSTEM_SCHEMAS = [
        'information_schema', 'performance_schema', 'mysql', 'sys', 'pg_catalog',
    ];

    /** @return list<DiscoveredTable> */
    public static function discover(DbConnection $connection): array
    {
        $probe = ProbeConnection::open($connection);

        return $connection->driver === 'sqlite'
            ? self::discoverSqlite($probe)
            : SchemaMapper::fromRows(
                self::columnRows($probe, $connection),
                self::constraintRows($probe, $connection),
            );
    }

    /** @return array{ok: bool, message: string} */
    public static function test(DbConnection $connection): array
    {
        try {
            ProbeConnection::open($connection)->select('SELECT 1');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => substr($e->getMessage(), 0, 500)];
        }

        return ['ok' => true, 'message' => 'Connected.'];
    }

    /** @return list<DiscoveredTable> */
    private static function discoverSqlite(Connection $probe): array
    {
        $names = array_map(fn ($row) => (string) $row->name, $probe->select(
            "SELECT name FROM sqlite_master
             WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'
             ORDER BY name"));

        $tables = [];
        foreach ($names as $name) {
            $quoted = str_replace('"', '""', $name);

            $targets = [];
            foreach ($probe->select("PRAGMA foreign_key_list(\"{$quoted}\")") as $fk) {
                // A foreign key with no "to" column points at the target's
                // primary key, which PRAGMA leaves for the caller to name.
                $targets[(string) $fk->from] = (string) $fk->table
                    . '.' . ((string) ($fk->to ?? 'rowid'));
            }

            $columns = [];
            foreach ($probe->select("PRAGMA table_info(\"{$quoted}\")") as $info) {
                $columns[] = new DiscoveredColumn(
                    name: (string) $info->name,
                    dataType: $info->type !== '' ? (string) $info->type : null,
                    isNullable: ! (bool) $info->notnull,
                    isPrimaryKey: (bool) $info->pk,
                    foreignKeyTarget: $targets[(string) $info->name] ?? null,
                    ordinal: (int) $info->cid + 1,
                );
            }

            $tables[] = new DiscoveredTable(null, $name, $columns);
        }

        return $tables;
    }

    /** @return list<array<string,mixed>> */
    private static function columnRows(Connection $probe, DbConnection $connection): array
    {
        [$filter, $bindings] = self::schemaFilter($connection, 'c.table_schema');

        $rows = $probe->select("
            SELECT c.table_schema AS schema_name, c.table_name AS table_name,
                   c.column_name AS column_name, c.data_type AS data_type,
                   c.is_nullable AS is_nullable, c.ordinal_position AS ordinal
            FROM information_schema.columns c
            WHERE {$filter}
            ORDER BY c.table_schema, c.table_name, c.ordinal_position
        ", $bindings);

        return array_map(fn ($row) => [
            'schema' => $row->schema_name,
            'table' => $row->table_name,
            'column' => $row->column_name,
            'data_type' => $row->data_type,
            'is_nullable' => $row->is_nullable,
            'ordinal' => $row->ordinal,
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private static function constraintRows(Connection $probe, DbConnection $connection): array
    {
        $rows = $connection->driver === 'mysql'
            ? self::mysqlConstraints($probe, $connection)
            : self::ansiConstraints($probe, $connection);

        return array_map(fn ($row) => [
            'schema' => $row->schema_name,
            'table' => $row->table_name,
            'column' => $row->column_name,
            'constraint_type' => $row->constraint_type,
            'ref_schema' => $row->ref_schema ?? null,
            'ref_table' => $row->ref_table ?? null,
            'ref_column' => $row->ref_column ?? null,
        ], $rows);
    }

    private static function mysqlConstraints(Connection $probe, DbConnection $connection): array
    {
        [$filter, $bindings] = self::schemaFilter($connection, 'tc.table_schema');

        return $probe->select("
            SELECT kcu.table_schema AS schema_name, kcu.table_name AS table_name,
                   kcu.column_name AS column_name, tc.constraint_type AS constraint_type,
                   kcu.referenced_table_schema AS ref_schema,
                   kcu.referenced_table_name AS ref_table,
                   kcu.referenced_column_name AS ref_column
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON kcu.constraint_name = tc.constraint_name
             AND kcu.table_schema = tc.table_schema
             AND kcu.table_name = tc.table_name
            WHERE tc.constraint_type IN ('PRIMARY KEY', 'FOREIGN KEY')
              AND {$filter}
        ", $bindings);
    }

    private static function ansiConstraints(Connection $probe, DbConnection $connection): array
    {
        [$filter, $bindings] = self::schemaFilter($connection, 'tc.table_schema');

        return $probe->select("
            SELECT kcu.table_schema AS schema_name, kcu.table_name AS table_name,
                   kcu.column_name AS column_name, tc.constraint_type AS constraint_type,
                   rkcu.table_schema AS ref_schema, rkcu.table_name AS ref_table,
                   rkcu.column_name AS ref_column
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON kcu.constraint_name = tc.constraint_name
             AND kcu.constraint_schema = tc.constraint_schema
            LEFT JOIN information_schema.referential_constraints rc
              ON rc.constraint_name = tc.constraint_name
             AND rc.constraint_schema = tc.constraint_schema
            LEFT JOIN information_schema.key_column_usage rkcu
              ON rkcu.constraint_name = rc.unique_constraint_name
             AND rkcu.constraint_schema = rc.unique_constraint_schema
             AND rkcu.ordinal_position = kcu.ordinal_position
            WHERE tc.constraint_type IN ('PRIMARY KEY', 'FOREIGN KEY')
              AND {$filter}
        ", $bindings);
    }

    /**
     * MySQL calls the database a schema, so it filters to exactly one. The
     * other two exclude the system schemas and keep everything else.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function schemaFilter(DbConnection $connection, string $column): array
    {
        if ($connection->driver === 'mysql') {
            return ["{$column} = ?", [$connection->database]];
        }

        $placeholders = implode(', ', array_fill(0, count(self::SYSTEM_SCHEMAS), '?'));

        return ["LOWER({$column}) NOT IN ({$placeholders})", self::SYSTEM_SCHEMAS];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=SchemaIntrospectorTest`
Expected: PASS, 9 tests.

If `test_an_unreachable_database_reports_rather_than_throws` hangs rather than failing fast, the MySQL driver is waiting on a connect timeout. Add `PDO::ATTR_TIMEOUT => 5` to the `options` key of the mysql, pgsql and sqlsrv branches of `ProbeConnection::config`, and add an assertion to `ProbeConnectionTest` that the option is present.

- [ ] **Step 5: Commit**

```bash
git add admin-laravel/app/Services/Schema/SchemaIntrospector.php \
        admin-laravel/tests/Feature/SchemaIntrospectorTest.php
git commit -m "feat: discover tables, columns and keys across four drivers"
```

---

### Task 5: Merging a discovery run into stored rows

**Files:**
- Create: `admin-laravel/app/Services/Schema/SchemaSync.php`
- Test: `admin-laravel/tests/Feature/SchemaSyncTest.php`

**Interfaces:**
- Consumes: `DiscoveredTable` from Task 2, `DbConnection`, `DbTable` and `DbColumn` from Task 1.
- Produces: `SchemaSync::apply(DbConnection $connection, array $discovered): array` returning `['tables_added' => int, 'tables_absent' => int, 'columns_added' => int, 'columns_absent' => int]`. It also sets `last_introspected_at` on the connection.

This is the task the whole feature's value rests on. Descriptions are written by a person who understands the business, and they must survive a schema change, a permissions blip, and a column being renamed back.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/SchemaSyncTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use App\Services\Schema\DiscoveredColumn;
use App\Services\Schema\DiscoveredTable;
use App\Services\Schema\SchemaSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaSyncTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): DbConnection
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);

        return DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Shop',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
    }

    private function column(string $name, int $ordinal, ?string $fk = null): DiscoveredColumn
    {
        return new DiscoveredColumn($name, 'integer', false, $ordinal === 1, $fk, $ordinal);
    }

    private function ordersTable(array $columnNames = ['id', 'total']): DiscoveredTable
    {
        $columns = [];
        foreach ($columnNames as $i => $name) {
            $columns[] = $this->column($name, $i + 1);
        }

        return new DiscoveredTable(null, 'orders', $columns);
    }

    public function test_a_first_run_creates_everything_disabled(): void
    {
        $connection = $this->connection();

        $counts = SchemaSync::apply($connection, [$this->ordersTable()]);

        $this->assertSame(1, $counts['tables_added']);
        $this->assertSame(2, $counts['columns_added']);

        $table = DbTable::where('connection_id', 'dbc_1')->first();
        $this->assertSame('orders', $table->table_name);
        $this->assertFalse($table->is_enabled);
        $this->assertTrue($table->is_present);
        $this->assertCount(2, $table->columns);
    }

    public function test_the_run_stamps_when_it_happened(): void
    {
        $connection = $this->connection();
        $this->assertNull($connection->last_introspected_at);

        SchemaSync::apply($connection, [$this->ordersTable()]);

        $this->assertNotNull($connection->fresh()->last_introspected_at);
    }

    public function test_a_second_run_adds_nothing_it_already_has(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);

        $counts = SchemaSync::apply($connection, [$this->ordersTable()]);

        $this->assertSame(0, $counts['tables_added']);
        $this->assertSame(0, $counts['columns_added']);
        $this->assertSame(1, DbTable::count());
        $this->assertSame(2, DbColumn::count());
    }

    public function test_a_table_description_and_enable_flag_survive_a_rerun(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);

        DbTable::where('table_name', 'orders')->update([
            'description' => 'Orders placed through the web shop.',
            'is_enabled' => true,
        ]);

        SchemaSync::apply($connection, [$this->ordersTable()]);

        $table = DbTable::where('table_name', 'orders')->first();
        $this->assertSame('Orders placed through the web shop.', $table->description);
        $this->assertTrue($table->is_enabled);
    }

    public function test_a_column_description_survives_a_rerun(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);

        DbColumn::where('column_name', 'total')->update([
            'description' => 'Gross amount in ringgit, including tax.',
        ]);

        SchemaSync::apply($connection, [$this->ordersTable()]);

        $this->assertSame('Gross amount in ringgit, including tax.',
            DbColumn::where('column_name', 'total')->value('description'));
    }

    public function test_a_changed_type_is_updated(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);

        $changed = new DiscoveredTable(null, 'orders', [
            $this->column('id', 1),
            new DiscoveredColumn('total', 'numeric', true, false, null, 2),
        ]);
        SchemaSync::apply($connection, [$changed]);

        $column = DbColumn::where('column_name', 'total')->first();
        $this->assertSame('numeric', $column->data_type);
        $this->assertTrue($column->is_nullable);
    }

    public function test_a_vanished_table_is_marked_absent_not_deleted(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);
        DbTable::where('table_name', 'orders')->update([
            'description' => 'Worth keeping.', 'is_enabled' => true,
        ]);

        $counts = SchemaSync::apply($connection, []);

        $this->assertSame(1, $counts['tables_absent']);
        $table = DbTable::where('table_name', 'orders')->first();
        $this->assertNotNull($table);
        $this->assertFalse($table->is_present);
        $this->assertSame('Worth keeping.', $table->description);
        $this->assertTrue($table->is_enabled);
    }

    public function test_a_vanished_column_is_marked_absent_not_deleted(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable(['id', 'total'])]);
        DbColumn::where('column_name', 'total')->update(['description' => 'Keep me.']);

        $counts = SchemaSync::apply($connection, [$this->ordersTable(['id'])]);

        $this->assertSame(1, $counts['columns_absent']);
        $column = DbColumn::where('column_name', 'total')->first();
        $this->assertFalse($column->is_present);
        $this->assertSame('Keep me.', $column->description);
    }

    public function test_a_returning_table_becomes_present_again_with_its_annotation(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);
        DbTable::where('table_name', 'orders')->update([
            'description' => 'Still true.', 'is_enabled' => true,
        ]);
        SchemaSync::apply($connection, []);

        SchemaSync::apply($connection, [$this->ordersTable()]);

        $table = DbTable::where('table_name', 'orders')->first();
        $this->assertTrue($table->is_present);
        $this->assertTrue($table->is_enabled);
        $this->assertSame('Still true.', $table->description);
        $this->assertSame(1, DbTable::count());
    }

    public function test_two_schemas_with_the_same_table_name_stay_separate(): void
    {
        $connection = $this->connection();

        SchemaSync::apply($connection, [
            new DiscoveredTable('public', 'orders', [$this->column('id', 1)]),
            new DiscoveredTable('archive', 'orders', [$this->column('id', 1)]),
        ]);

        $this->assertSame(2, DbTable::count());
    }

    public function test_another_connection_is_untouched(): void
    {
        $connection = $this->connection();
        $other = DbConnection::create([
            'id' => 'dbc_2', 'system_id' => 'sys_test', 'name' => 'Other',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
        SchemaSync::apply($other, [$this->ordersTable()]);

        SchemaSync::apply($connection, []);

        $this->assertTrue(DbTable::where('connection_id', 'dbc_2')->first()->is_present);
    }

    public function test_a_foreign_key_target_is_stored(): void
    {
        $connection = $this->connection();

        SchemaSync::apply($connection, [new DiscoveredTable(null, 'orders', [
            $this->column('id', 1),
            $this->column('customer_id', 2, 'customers.id'),
        ])]);

        $this->assertSame('customers.id',
            DbColumn::where('column_name', 'customer_id')->value('foreign_key_target'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SchemaSyncTest`
Expected: FAIL with `Class "App\Services\Schema\SchemaSync" not found`.

- [ ] **Step 3: Write the sync**

Create `admin-laravel/app/Services/Schema/SchemaSync.php`:

```php
<?php

namespace App\Services\Schema;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use Illuminate\Support\Facades\DB;

/**
 * Folds a discovery run into the stored schema.
 *
 * Merges, never replaces. A description is written by somebody who
 * understands the business and is the expensive part of this feature, so it
 * survives a schema change, a permissions blip, and a column being renamed
 * back. A row the run did not see is marked absent rather than deleted, for
 * the same reason.
 */
final class SchemaSync
{
    /**
     * @param list<DiscoveredTable> $discovered
     * @return array{tables_added: int, tables_absent: int, columns_added: int, columns_absent: int}
     */
    public static function apply(DbConnection $connection, array $discovered): array
    {
        $counts = ['tables_added' => 0, 'tables_absent' => 0,
                   'columns_added' => 0, 'columns_absent' => 0];

        DB::transaction(function () use ($connection, $discovered, &$counts) {
            $stored = DbTable::where('connection_id', $connection->id)
                ->get()
                ->keyBy(fn (DbTable $t) => ($t->schema_name ?? '') . '|' . $t->table_name);

            $seen = [];

            foreach ($discovered as $table) {
                $key = $table->key();
                $seen[$key] = true;
                $row = $stored->get($key);

                if (!$row) {
                    $row = DbTable::create([
                        'connection_id' => $connection->id,
                        'schema_name' => $table->schema,
                        'table_name' => $table->name,
                        'is_enabled' => false,
                        'is_present' => true,
                    ]);
                    $counts['tables_added']++;
                } elseif (!$row->is_present) {
                    // description and is_enabled are deliberately not touched.
                    $row->update(['is_present' => true]);
                }

                self::syncColumns($row, $table->columns, $counts);
            }

            foreach ($stored as $key => $row) {
                if (!isset($seen[$key]) && $row->is_present) {
                    $row->update(['is_present' => false]);
                    $counts['tables_absent']++;
                }
            }

            $connection->update(['last_introspected_at' => now()]);
        });

        return $counts;
    }

    /**
     * @param list<DiscoveredColumn> $discovered
     * @param array{tables_added: int, tables_absent: int, columns_added: int, columns_absent: int} $counts
     */
    private static function syncColumns(DbTable $table, array $discovered, array &$counts): void
    {
        $stored = DbColumn::where('table_id', $table->id)->get()->keyBy('column_name');
        $seen = [];

        foreach ($discovered as $column) {
            $seen[$column->name] = true;
            $shape = [
                'data_type' => $column->dataType,
                'is_nullable' => $column->isNullable,
                'is_primary_key' => $column->isPrimaryKey,
                'foreign_key_target' => $column->foreignKeyTarget,
                'ordinal' => $column->ordinal,
                'is_present' => true,
            ];

            $row = $stored->get($column->name);

            if (!$row) {
                DbColumn::create($shape + [
                    'table_id' => $table->id,
                    'column_name' => $column->name,
                ]);
                $counts['columns_added']++;
                continue;
            }

            // Shape is the database's to state. description is not.
            $row->update($shape);
        }

        foreach ($stored as $name => $row) {
            if (!isset($seen[$name]) && $row->is_present) {
                $row->update(['is_present' => false]);
                $counts['columns_absent']++;
            }
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=SchemaSyncTest`
Expected: PASS, 12 tests.

- [ ] **Step 5: Commit**

```bash
git add admin-laravel/app/Services/Schema/SchemaSync.php admin-laravel/tests/Feature/SchemaSyncTest.php
git commit -m "feat: merge a discovery run without losing an annotation"
```

---

### Task 6: Connections list, form and test button

**Files:**
- Create: `admin-laravel/app/Http/Controllers/DbConnectionController.php`
- Create: `admin-laravel/resources/views/databases/index.blade.php`
- Create: `admin-laravel/resources/views/databases/_connection-fields.blade.php`
- Modify: `admin-laravel/routes/web.php`
- Modify: `admin-laravel/resources/views/layouts/app.blade.php:91` (after the knowledge base sidebar link)
- Test: `admin-laravel/tests/Feature/DbConnectionControllerTest.php`

**Interfaces:**
- Consumes: `DbConnection` from Task 1, `SchemaIntrospector::test()` from Task 4.
- Produces: routes named `databases.index`, `databases.store`, `databases.update`, `databases.destroy`, `databases.test`.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/DbConnectionControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\DbConnection;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbConnectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function system(): System
    {
        return System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    private function userWithRole(string $role): User
    {
        $system = System::find('sys_test') ?? $this->system();
        $user = User::create([
            'name' => ucfirst($role), 'email' => "{$role}@example.test",
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach($system->id, ['role' => $role]);

        return $user;
    }

    private function connection(): DbConnection
    {
        return DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Orders',
            'driver' => 'mysql', 'host' => 'localhost', 'database' => 'shop',
            'username' => 'reader', 'password' => 'hunter2',
        ]);
    }

    public function test_an_editor_sees_the_list(): void
    {
        $this->system();
        $this->connection();

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('databases.index'))
            ->assertOk()
            ->assertSee('Orders');
    }

    public function test_the_list_never_renders_a_password(): void
    {
        $this->system();
        $this->connection();

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('databases.index'))
            ->assertDontSee('hunter2');
    }

    public function test_an_editor_creates_a_connection(): void
    {
        $this->system();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.store'), [
                'name' => 'Billing', 'driver' => 'pgsql', 'host' => 'db.internal',
                'port' => 5432, 'database' => 'billing', 'username' => 'reader',
                'password' => 'secret',
            ])
            ->assertRedirect(route('databases.index'));

        $created = DbConnection::where('name', 'Billing')->first();
        $this->assertStringStartsWith('dbc_', $created->id);
        $this->assertSame('sys_test', $created->system_id);
        $this->assertSame('secret', $created->password);
        $this->assertSame('untested', $created->status);
    }

    public function test_a_viewer_cannot_create_one(): void
    {
        $this->system();

        $this->actingAs($this->userWithRole('viewer'))
            ->post(route('databases.store'), [
                'name' => 'Billing', 'driver' => 'pgsql', 'host' => 'db.internal',
                'database' => 'billing',
            ])
            ->assertForbidden();

        $this->assertSame(0, DbConnection::count());
    }

    public function test_blank_advanced_fields_are_dropped_but_a_zero_is_kept(): void
    {
        $this->system();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.store'), [
                'name' => 'Reporting', 'driver' => 'sqlsrv', 'host' => 'db',
                'database' => 'reporting',
                'options' => ['sslmode' => '', 'trust_server_certificate' => '0'],
            ]);

        $options = DbConnection::where('name', 'Reporting')->first()->options;

        $this->assertArrayNotHasKey('sslmode', $options);
        $this->assertSame('0', $options['trust_server_certificate']);
    }

    public function test_an_unknown_driver_is_rejected(): void
    {
        $this->system();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.store'), [
                'name' => 'Legacy', 'driver' => 'oracle', 'host' => 'db',
                'database' => 'legacy',
            ])
            ->assertSessionHasErrors('driver');
    }

    public function test_an_empty_password_on_update_keeps_the_stored_one(): void
    {
        $this->system();
        $this->connection();

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.update', 'dbc_1'), [
                'name' => 'Orders renamed', 'driver' => 'mysql', 'host' => 'localhost',
                'database' => 'shop', 'username' => 'reader', 'password' => '',
            ])
            ->assertRedirect(route('databases.index'));

        $fresh = DbConnection::find('dbc_1');
        $this->assertSame('Orders renamed', $fresh->name);
        $this->assertSame('hunter2', $fresh->password);
    }

    public function test_a_supplied_password_on_update_replaces_the_stored_one(): void
    {
        $this->system();
        $this->connection();

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.update', 'dbc_1'), [
                'name' => 'Orders', 'driver' => 'mysql', 'host' => 'localhost',
                'database' => 'shop', 'username' => 'reader', 'password' => 'newpass',
            ]);

        $this->assertSame('newpass', DbConnection::find('dbc_1')->password);
    }

    public function test_a_failing_test_records_the_drivers_message(): void
    {
        $this->system();
        DbConnection::create([
            'id' => 'dbc_2', 'system_id' => 'sys_test', 'name' => 'Nowhere',
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
            'database' => 'nope', 'username' => 'x', 'password' => 'y',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.test', 'dbc_2'))
            ->assertRedirect();

        $fresh = DbConnection::find('dbc_2');
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->error_message);
    }

    public function test_a_passing_test_clears_the_previous_error(): void
    {
        $this->system();
        $path = tempnam(sys_get_temp_dir(), 'conn') . '.sqlite';
        touch($path);

        try {
            DbConnection::create([
                'id' => 'dbc_3', 'system_id' => 'sys_test', 'name' => 'Local',
                'driver' => 'sqlite', 'database' => $path,
                'status' => 'failed', 'error_message' => 'an old failure',
            ]);

            $this->actingAs($this->userWithRole('editor'))
                ->post(route('databases.test', 'dbc_3'));

            $fresh = DbConnection::find('dbc_3');
            $this->assertSame('ok', $fresh->status);
            $this->assertNull($fresh->error_message);
        } finally {
            @unlink($path);
        }
    }

    public function test_an_editor_deletes_a_connection(): void
    {
        $this->system();
        $this->connection();

        $this->actingAs($this->userWithRole('editor'))
            ->delete(route('databases.destroy', 'dbc_1'))
            ->assertRedirect(route('databases.index'));

        $this->assertSame(0, DbConnection::count());
    }

    public function test_another_workspaces_connection_is_out_of_reach(): void
    {
        $this->system();
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        DbConnection::create([
            'id' => 'dbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->delete(route('databases.destroy', 'dbc_other'))
            ->assertForbidden();

        $this->assertSame(1, DbConnection::count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=DbConnectionControllerTest`
Expected: FAIL with `Route [databases.index] not defined`.

- [ ] **Step 3: Write the controller**

Create `admin-laravel/app/Http/Controllers/DbConnectionController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\DbConnection;
use App\Services\Schema\SchemaIntrospector;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DbConnectionController extends Controller
{
    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        if (!$activeSystem) {
            return redirect()->route('systems.index')
                ->with('error', 'Select a workspace first.');
        }

        return view('databases.index', [
            'activeSystem' => $activeSystem,
            'connections' => DbConnection::withCount([
                    'tables',
                    'tables as enabled_tables_count' => fn ($q) => $q->where('is_enabled', true),
                ])
                ->where('system_id', $activeSystem->id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        $validated = $this->validated($request);

        DbConnection::create($validated + [
            'id' => 'dbc_' . Str::random(12),
            'system_id' => $activeSystem->id,
            'status' => 'untested',
        ]);

        return redirect()->route('databases.index')
            ->with('success', 'Connection saved. Test it, then discover the schema.');
    }

    public function update(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);

        $validated = $this->validated($request);

        // An empty password means keep the stored one. The form never shows
        // the current value, so blank has to mean "unchanged" rather than
        // "erase it".
        if (($validated['password'] ?? '') === '') {
            unset($validated['password']);
        }

        $connection->update($validated);

        return redirect()->route('databases.index')->with('success', 'Connection updated.');
    }

    public function destroy(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);

        $connection->delete();

        return redirect()->route('databases.index')->with('success', 'Connection deleted.');
    }

    public function test(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);

        $result = SchemaIntrospector::test($connection);

        $connection->update([
            'status' => $result['ok'] ? 'ok' : 'failed',
            'error_message' => $result['ok'] ? null : $result['message'],
        ]);

        return back()->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'Connected.' : 'Could not connect: ' . $result['message']);
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'driver' => ['required', 'in:mysql,pgsql,sqlsrv,sqlite'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'array'],
        ], [
            'driver.in' => 'Choose MySQL, PostgreSQL, SQL Server or SQLite.',
            'database.required' => 'A SQLite connection needs a file path here; the others need a database name.',
        ]);

        // Blank advanced fields are absent, not empty. But "0" is a real
        // answer for a checkbox, so array_filter's default test is wrong here.
        $validated['options'] = array_filter(
            $validated['options'] ?? [], fn ($v) => $v !== '' && $v !== null);

        return $validated;
    }

    private function authorizeEditor(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }
}
```

- [ ] **Step 4: Register the routes**

In `admin-laravel/routes/web.php`, add the import beside the others:

```php
use App\Http\Controllers\DbConnectionController;
```

Then inside the `Route::middleware(['auth', 'system.access'])` group, after the knowledge base block:

```php
    // Database connections
    Route::get('/databases', [DbConnectionController::class, 'index'])->name('databases.index');
    Route::post('/databases', [DbConnectionController::class, 'store'])->name('databases.store');
    Route::put('/databases/{id}', [DbConnectionController::class, 'update'])->name('databases.update');
    Route::delete('/databases/{id}', [DbConnectionController::class, 'destroy'])->name('databases.destroy');
    Route::post('/databases/{id}/test', [DbConnectionController::class, 'test'])->name('databases.test');
```

- [ ] **Step 5: Write the shared form fields**

The create and edit forms ask for the same things, and the edit form must not
render the stored password. One partial, given a nullable `$connection`, keeps
the two in step.

Create `admin-laravel/resources/views/databases/_connection-fields.blade.php`:

```blade
@php($c = $connection ?? null)

<div class="alert alert-warning" style="font-size: 0.8rem;">
    Use a read-only database account. A bot only ever reads, and the stored
    password is encrypted against a database dump but not against anyone
    holding this application's key.
</div>

<div class="mb-3">
    <label class="form-label">Name</label>
    <input name="name" class="form-control" required maxlength="255"
           value="{{ $c->name ?? '' }}" placeholder="Orders database">
    <div class="form-text">This is what a visitor sees when a bot cites it.</div>
</div>

<div class="mb-3">
    <label class="form-label">Driver</label>
    <select name="driver" class="form-select" required>
        @foreach(['mysql' => 'MySQL or MariaDB', 'pgsql' => 'PostgreSQL',
                  'sqlsrv' => 'SQL Server', 'sqlite' => 'SQLite'] as $value => $label)
            <option value="{{ $value }}" @selected(($c->driver ?? '') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</div>

<div class="row g-2 mb-3">
    <div class="col-8">
        <label class="form-label">Host</label>
        <input name="host" class="form-control" maxlength="255"
               value="{{ $c->host ?? '' }}" placeholder="db.internal">
    </div>
    <div class="col-4">
        <label class="form-label">Port</label>
        <input name="port" type="number" class="form-control" min="1" max="65535"
               value="{{ $c->port ?? '' }}" placeholder="default">
    </div>
</div>

<div class="mb-3">
    <label class="form-label">Database</label>
    <input name="database" class="form-control" required maxlength="255"
           value="{{ $c->database ?? '' }}">
    <div class="form-text">A database name, or a file path when the driver is SQLite.</div>
</div>

<div class="row g-2 mb-3">
    <div class="col-6">
        <label class="form-label">Username</label>
        <input name="username" class="form-control" maxlength="255"
               value="{{ $c->username ?? '' }}" autocomplete="off">
    </div>
    <div class="col-6">
        <label class="form-label">Password</label>
        <input name="password" type="password" class="form-control" maxlength="255"
               autocomplete="new-password" placeholder="{{ $c ? 'leave empty to keep the current one' : '' }}">
    </div>
</div>

<details>
    <summary class="text-muted" style="font-size: 0.8rem;">Advanced</summary>
    <div class="row g-2 mt-1">
        <div class="col-6">
            <label class="form-label">PostgreSQL SSL mode</label>
            <input name="options[sslmode]" class="form-control" maxlength="20"
                   value="{{ $c->options['sslmode'] ?? '' }}" placeholder="prefer">
        </div>
        <div class="col-6">
            <label class="form-label">PostgreSQL search path</label>
            <input name="options[search_path]" class="form-control" maxlength="128"
                   value="{{ $c->options['search_path'] ?? '' }}" placeholder="public">
        </div>
    </div>
    <div class="form-check mt-2">
        {{-- An unticked checkbox sends nothing, and nothing means the default,
             which is on. The hidden field is what makes turning it off possible. --}}
        <input type="hidden" name="options[trust_server_certificate]" value="0">
        <input class="form-check-input" type="checkbox" name="options[trust_server_certificate]"
               value="1" id="trustCert{{ $c->id ?? 'new' }}"
               @checked($c === null || ($c->options['trust_server_certificate'] ?? true))>
        <label class="form-check-label" for="trustCert{{ $c->id ?? 'new' }}" style="font-size: 0.8rem;">
            Trust the SQL Server certificate
        </label>
    </div>
</details>
```

- [ ] **Step 6: Write the list view**

Create `admin-laravel/resources/views/databases/index.blade.php`:

```blade
@extends('layouts.app')

@section('page-title', 'Databases')

@section('content')

@php($canEdit = auth()->user()->canManageSystem($activeSystem->id, 'editor'))

<div class="page-head mb-4">
    <div>
        <h1>Databases</h1>
        <p>Live data the bots in {{ $activeSystem->name }} can query. Connect a database, discover its tables, and explain what each one holds so a bot can write sensible queries against it.</p>
    </div>

    @if($canEdit)
        <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newConnectionModal">
            <i class="bi bi-plus-lg"></i> New connection
        </button>
    @endif
</div>

<div class="card">
    @if($connections->isEmpty())
        <div class="empty">
            <i class="bi bi-database"></i>
            <h6>No connections yet</h6>
            <p>Point one at your orders or tickets database, using a read-only account.</p>
            @if($canEdit)
                <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newConnectionModal">New connection</button>
            @endif
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 240px;">Connection</th>
                        <th style="width: 120px;">Driver</th>
                        <th style="width: 120px;">Status</th>
                        <th style="width: 140px;">Tables enabled</th>
                        <th class="text-end" style="width: 200px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($connections as $connection)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $connection->name }}</div>
                                <div class="text-muted" style="font-size: 0.75rem;">
                                    {{ $connection->host ? $connection->host . ' / ' : '' }}{{ $connection->database }}
                                </div>
                            </td>
                            <td><span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $connection->driver }}</span></td>
                            <td>
                                @if($connection->status === 'ok')
                                    <span class="badge bg-success-subtle text-success-emphasis">Connected</span>
                                @elseif($connection->status === 'failed')
                                    <span class="badge bg-danger-subtle text-danger-emphasis"
                                          title="{{ $connection->error_message }}">Failed</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis">Untested</span>
                                @endif
                            </td>
                            <td>
                                <span class="figure-mono">{{ $connection->enabled_tables_count }}</span>
                                <span class="text-muted">/ {{ $connection->tables_count }}</span>
                            </td>
                            <td class="text-end">
                                <div class="d-flex align-items-center justify-content-end gap-1">
                                    <a href="{{ route('databases.schema', $connection->id) }}"
                                       class="btn btn-sm btn-outline-primary">Schema</a>
                                    @if($canEdit)
                                        <form action="{{ route('databases.test', $connection->id) }}" method="POST" class="d-inline">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary">Test</button>
                                        </form>
                                        <button class="btn btn-sm btn-outline-secondary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editConnection{{ $connection->id }}">Edit</button>
                                        <form action="{{ route('databases.destroy', $connection->id) }}" method="POST"
                                              onsubmit="return confirm('Delete {{ $connection->name }} and every annotation on it?');"
                                              class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if($canEdit)
<div class="modal fade" id="newConnectionModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="{{ route('databases.store') }}" method="POST">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">New connection</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                @include('databases._connection-fields', ['connection' => null])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-brand">Save connection</button>
            </div>
        </form>
    </div>
</div>

@foreach($connections as $connection)
    <div class="modal fade" id="editConnection{{ $connection->id }}" tabindex="-1">
        <div class="modal-dialog">
            <form class="modal-content" action="{{ route('databases.update', $connection->id) }}" method="POST">
                @csrf
                @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Edit {{ $connection->name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @include('databases._connection-fields', ['connection' => $connection])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-brand">Save changes</button>
                </div>
            </form>
        </div>
    </div>
@endforeach
@endif

@endsection
```

The edit modal never renders a password value. The field's placeholder says that
leaving it empty keeps the stored one, which is what the controller does.

- [ ] **Step 7: Add the sidebar link**

In `admin-laravel/resources/views/layouts/app.blade.php`, immediately after the knowledge base link block that ends around line 94, add:

```blade
            <a href="{{ route('databases.index') }}" class="sidebar-link {{ request()->routeIs('databases.*') ? 'active' : '' }}">
                <i class="bi bi-database"></i> Databases
            </a>
```

Match the surrounding link's exact markup if it differs from this; the icon and the `routeIs` guard are what matter.

- [ ] **Step 8: Run the test**

Run: `php artisan test --filter=DbConnectionControllerTest`
Expected: FAIL on the two tests that touch `route('databases.schema', ...)`, because the list view links to a route Task 7 adds. Every other test passes.

To keep this task self-contained, temporarily register a placeholder route so the view renders, then delete it in Task 7. In `routes/web.php`, beside the other database routes:

```php
    Route::get('/databases/{id}/schema', [DbConnectionController::class, 'index'])->name('databases.schema');
```

Re-run: `php artisan test --filter=DbConnectionControllerTest`
Expected: PASS, 12 tests.

- [ ] **Step 9: Commit**

```bash
git add admin-laravel/app/Http/Controllers/DbConnectionController.php \
        admin-laravel/resources/views/databases/index.blade.php \
        admin-laravel/resources/views/databases/_connection-fields.blade.php \
        admin-laravel/routes/web.php \
        admin-laravel/resources/views/layouts/app.blade.php \
        admin-laravel/tests/Feature/DbConnectionControllerTest.php
git commit -m "feat: create, test and delete a workspace database connection"
```

---

### Task 7: The schema editor

**Files:**
- Create: `admin-laravel/app/Http/Controllers/DbSchemaController.php`
- Create: `admin-laravel/resources/views/databases/schema.blade.php`
- Modify: `admin-laravel/routes/web.php`
- Test: `admin-laravel/tests/Feature/DbSchemaControllerTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1 through 5.
- Produces: routes named `databases.schema`, `databases.introspect`, `databases.tables.store`, `databases.tables.update`, `databases.tables.destroy`, `databases.columns.store`, `databases.columns.update`, `databases.columns.destroy`.

Table and column routes use the distinct prefixes `/database-tables` and `/database-columns` rather than nesting under `/databases/{id}`, so a numeric table id can never be mistaken for a connection id. This is the same reason `/knowledge-playground` sits outside `/knowledge/{id}`.

- [ ] **Step 1: Write the failing test**

Create `admin-laravel/tests/Feature/DbSchemaControllerTest.php`:

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

class DbSchemaControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'schema') . '.sqlite';
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

    private function connection(): DbConnection
    {
        $connection = DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Shop',
            'driver' => 'sqlite', 'database' => $this->path,
        ]);

        $probe = ProbeConnection::open($connection);
        $probe->statement('CREATE TABLE customers (id integer primary key, name text not null)');
        $probe->statement('CREATE TABLE orders (
            id integer primary key,
            customer_id integer references customers(id),
            total numeric
        )');

        return $connection;
    }

    public function test_the_schema_screen_opens_for_a_viewer(): void
    {
        $this->connection();

        $this->actingAs($this->userWithRole('viewer'))
            ->get(route('databases.schema', 'dbc_1'))
            ->assertOk()
            ->assertSee('Shop');
    }

    public function test_introspecting_stores_the_discovered_schema(): void
    {
        $this->connection();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.introspect', 'dbc_1'))
            ->assertRedirect(route('databases.schema', 'dbc_1'));

        $this->assertSame(2, DbTable::where('connection_id', 'dbc_1')->count());
        $this->assertSame(5, DbColumn::count());
        $this->assertNotNull(DbConnection::find('dbc_1')->last_introspected_at);
    }

    public function test_a_viewer_cannot_introspect(): void
    {
        $this->connection();

        $this->actingAs($this->userWithRole('viewer'))
            ->post(route('databases.introspect', 'dbc_1'))
            ->assertForbidden();

        $this->assertSame(0, DbTable::count());
    }

    public function test_a_failing_introspection_reports_rather_than_throws(): void
    {
        DbConnection::create([
            'id' => 'dbc_2', 'system_id' => 'sys_test', 'name' => 'Nowhere',
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
            'database' => 'nope', 'username' => 'x', 'password' => 'y',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.introspect', 'dbc_2'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('failed', DbConnection::find('dbc_2')->status);
    }

    public function test_an_editor_annotates_and_enables_a_table(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.tables.update', $table->id), [
                'description' => 'Orders placed through the web shop.',
                'is_enabled' => '1',
            ])
            ->assertRedirect();

        $fresh = $table->fresh();
        $this->assertSame('Orders placed through the web shop.', $fresh->description);
        $this->assertTrue($fresh->is_enabled);
    }

    public function test_an_unticked_checkbox_disables_a_table(): void
    {
        $this->connection();
        $table = DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'orders', 'is_enabled' => true,
        ]);

        // An unticked checkbox sends nothing at all, which must mean off.
        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.tables.update', $table->id), ['description' => 'Orders.']);

        $this->assertFalse($table->fresh()->is_enabled);
    }

    public function test_a_viewer_cannot_annotate(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        $this->actingAs($this->userWithRole('viewer'))
            ->put(route('databases.tables.update', $table->id), ['description' => 'Mine now.'])
            ->assertForbidden();

        $this->assertNull($table->fresh()->description);
    }

    public function test_an_editor_annotates_a_column(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);
        $column = DbColumn::create([
            'table_id' => $table->id, 'column_name' => 'total', 'ordinal' => 1,
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.columns.update', $column->id), [
                'description' => 'Gross amount in ringgit, including tax.',
            ])
            ->assertRedirect();

        $this->assertSame('Gross amount in ringgit, including tax.',
            $column->fresh()->description);
    }

    public function test_a_table_can_be_added_by_hand(): void
    {
        $this->connection();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.tables.store', 'dbc_1'), [
                'table_name' => 'legacy_invoices',
                'schema_name' => '',
                'description' => 'Pre-2020 invoices the reader account cannot list.',
            ])
            ->assertRedirect();

        $table = DbTable::where('table_name', 'legacy_invoices')->first();
        $this->assertNotNull($table);
        $this->assertNull($table->schema_name);
        $this->assertFalse($table->is_enabled);
    }

    public function test_a_hand_added_table_cannot_duplicate_an_existing_one(): void
    {
        $this->connection();
        DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.tables.store', 'dbc_1'), ['table_name' => 'orders'])
            ->assertSessionHasErrors('table_name');

        $this->assertSame(1, DbTable::count());
    }

    public function test_a_column_can_be_added_by_hand(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'legacy']);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.columns.store', $table->id), [
                'column_name' => 'invoice_no',
                'data_type' => 'varchar',
                'description' => 'The printed invoice number.',
            ])
            ->assertRedirect();

        $column = DbColumn::where('column_name', 'invoice_no')->first();
        $this->assertSame('varchar', $column->data_type);
        $this->assertSame(1, $column->ordinal);
    }

    public function test_a_hand_added_column_takes_the_next_ordinal(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'legacy']);
        DbColumn::create(['table_id' => $table->id, 'column_name' => 'a', 'ordinal' => 7]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.columns.store', $table->id), ['column_name' => 'b']);

        $this->assertSame(8, DbColumn::where('column_name', 'b')->value('ordinal'));
    }

    public function test_an_editor_deletes_an_absent_table(): void
    {
        $this->connection();
        $table = DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'gone', 'is_present' => false,
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->delete(route('databases.tables.destroy', $table->id))
            ->assertRedirect();

        $this->assertSame(0, DbTable::count());
    }

    public function test_another_workspaces_schema_is_out_of_reach(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        $other = DbConnection::create([
            'id' => 'dbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
        $table = DbTable::create(['connection_id' => $other->id, 'table_name' => 'secrets']);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.tables.update', $table->id), ['description' => 'Mine now.'])
            ->assertForbidden();

        $this->assertNull($table->fresh()->description);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=DbSchemaControllerTest`
Expected: FAIL with `Route [databases.introspect] not defined`.

- [ ] **Step 3: Write the controller**

Create `admin-laravel/app/Http/Controllers/DbSchemaController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Services\Schema\SchemaIntrospector;
use App\Services\Schema\SchemaSync;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The schema editor: what each table and column holds, in the words of
 * somebody who knows the business. This is the surface the whole feature's
 * answer quality rests on, which is why a description is never written by
 * anything but a person.
 */
class DbSchemaController extends Controller
{
    public function show(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeViewer($request, $connection->system_id);

        $tables = DbTable::with('columns')
            ->where('connection_id', $connection->id)
            ->orderBy('schema_name')
            ->orderBy('table_name')
            ->get();

        return view('databases.schema', [
            'connection' => $connection,
            'tables' => $tables,
            'selected' => $tables->firstWhere('id', (int) $request->query('table'))
                ?? $tables->first(),
            'canEdit' => $request->user()->canManageSystem($connection->system_id, 'editor'),
        ]);
    }

    public function introspect(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);

        try {
            $counts = SchemaSync::apply($connection, SchemaIntrospector::discover($connection));
        } catch (Throwable $e) {
            // A restricted account may refuse information_schema outright.
            // The editor below still works, by hand.
            $connection->update([
                'status' => 'failed',
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);

            return redirect()->route('databases.schema', $connection->id)
                ->with('error', 'Could not read the schema: ' . substr($e->getMessage(), 0, 300));
        }

        $connection->update(['status' => 'ok', 'error_message' => null]);

        return redirect()->route('databases.schema', $connection->id)->with('success', sprintf(
            '%d tables and %d columns added. %d tables and %d columns are no longer in the database.',
            $counts['tables_added'], $counts['columns_added'],
            $counts['tables_absent'], $counts['columns_absent']));
    }

    public function storeTable(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);

        $validated = $request->validate([
            'schema_name' => ['nullable', 'string', 'max:128'],
            'table_name' => [
                'required', 'string', 'max:128',
                Rule::unique('db_tables', 'table_name')
                    ->where('connection_id', $connection->id)
                    ->where('schema_name', $request->input('schema_name') ?: null),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'table_name.unique' => 'This connection already has that table.',
        ]);

        $table = DbTable::create([
            'connection_id' => $connection->id,
            'schema_name' => $validated['schema_name'] ?: null,
            'table_name' => $validated['table_name'],
            'description' => $validated['description'] ?? null,
            'is_enabled' => false,
            'is_present' => true,
        ]);

        return redirect()->route('databases.schema', [$connection->id, 'table' => $table->id])
            ->with('success', 'Table added. Add its columns, then enable it.');
    }

    public function updateTable(Request $request, int $tableId)
    {
        $table = DbTable::findOrFail($tableId);
        $this->authorizeEditor($request, $table->connection->system_id);

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $table->update([
            'description' => $validated['description'] ?? null,
            // An unticked checkbox sends nothing at all, which means off.
            'is_enabled' => $request->boolean('is_enabled'),
        ]);

        return redirect()->route('databases.schema', [$table->connection_id, 'table' => $table->id])
            ->with('success', 'Saved.');
    }

    public function destroyTable(Request $request, int $tableId)
    {
        $table = DbTable::findOrFail($tableId);
        $this->authorizeEditor($request, $table->connection->system_id);

        $connectionId = $table->connection_id;
        $table->delete();

        return redirect()->route('databases.schema', $connectionId)->with('success', 'Table removed.');
    }

    public function storeColumn(Request $request, int $tableId)
    {
        $table = DbTable::findOrFail($tableId);
        $this->authorizeEditor($request, $table->connection->system_id);

        $validated = $request->validate([
            'column_name' => [
                'required', 'string', 'max:128',
                Rule::unique('db_columns', 'column_name')->where('table_id', $table->id),
            ],
            'data_type' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'column_name.unique' => 'This table already has that column.',
        ]);

        DbColumn::create([
            'table_id' => $table->id,
            'column_name' => $validated['column_name'],
            'data_type' => $validated['data_type'] ?? null,
            'description' => $validated['description'] ?? null,
            'ordinal' => (int) DbColumn::where('table_id', $table->id)->max('ordinal') + 1,
            'is_present' => true,
        ]);

        return redirect()->route('databases.schema', [$table->connection_id, 'table' => $table->id])
            ->with('success', 'Column added.');
    }

    public function updateColumn(Request $request, int $columnId)
    {
        $column = DbColumn::findOrFail($columnId);
        $this->authorizeEditor($request, $column->table->connection->system_id);

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $column->update(['description' => $validated['description'] ?? null]);

        return redirect()->route('databases.schema',
            [$column->table->connection_id, 'table' => $column->table_id])
            ->with('success', 'Saved.');
    }

    public function destroyColumn(Request $request, int $columnId)
    {
        $column = DbColumn::findOrFail($columnId);
        $this->authorizeEditor($request, $column->table->connection->system_id);

        $table = $column->table;
        $column->delete();

        return redirect()->route('databases.schema', [$table->connection_id, 'table' => $table->id])
            ->with('success', 'Column removed.');
    }

    private function authorizeEditor(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }

    private function authorizeViewer(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'viewer'), 403);
    }
}
```

- [ ] **Step 4: Register the routes**

In `admin-laravel/routes/web.php`, add the import:

```php
use App\Http\Controllers\DbSchemaController;
```

Delete the placeholder `databases.schema` route added in Task 6 step 7, and add, after the connection routes:

```php
    // Schema editor. Table and column ids are integers, so they get their own
    // path prefixes rather than nesting under /databases/{id}, where a
    // numeric id could be read as a connection.
    Route::get('/databases/{id}/schema', [DbSchemaController::class, 'show'])->name('databases.schema');
    Route::post('/databases/{id}/introspect', [DbSchemaController::class, 'introspect'])->name('databases.introspect');
    Route::post('/databases/{id}/tables', [DbSchemaController::class, 'storeTable'])->name('databases.tables.store');
    Route::put('/database-tables/{tableId}', [DbSchemaController::class, 'updateTable'])->name('databases.tables.update');
    Route::delete('/database-tables/{tableId}', [DbSchemaController::class, 'destroyTable'])->name('databases.tables.destroy');
    Route::post('/database-tables/{tableId}/columns', [DbSchemaController::class, 'storeColumn'])->name('databases.columns.store');
    Route::put('/database-columns/{columnId}', [DbSchemaController::class, 'updateColumn'])->name('databases.columns.update');
    Route::delete('/database-columns/{columnId}', [DbSchemaController::class, 'destroyColumn'])->name('databases.columns.destroy');
```

- [ ] **Step 5: Write the schema view**

Create `admin-laravel/resources/views/databases/schema.blade.php`:

```blade
@extends('layouts.app')

@section('page-title', 'Schema')

@section('content')

<div class="page-head mb-4">
    <div>
        <h1>{{ $connection->name }}</h1>
        <p>
            Tick the tables a bot may read, and say in plain language what each table
            and column holds. A bot writes its queries from these sentences, so a
            column called <code>amt_ttl</code> is only as useful as the line beside it.
        </p>
    </div>

    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('databases.index') }}" class="btn btn-outline-secondary">Back</a>
        @if($canEdit)
            <form action="{{ route('databases.introspect', $connection->id) }}" method="POST">
                @csrf
                <button class="btn btn-brand"><i class="bi bi-arrow-repeat"></i> Discover schema</button>
            </form>
        @endif
    </div>
</div>

@if($connection->last_introspected_at)
    <p class="text-muted mb-3" style="font-size: 0.8rem;">
        Last discovered {{ $connection->last_introspected_at->diffForHumans() }}.
        Discovering again never overwrites a description you wrote.
    </p>
@endif

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Tables</span>
                @if($canEdit)
                    <button class="btn btn-sm btn-outline-secondary"
                            data-bs-toggle="modal" data-bs-target="#addTableModal">Add by hand</button>
                @endif
            </div>

            @if($tables->isEmpty())
                <div class="empty">
                    <i class="bi bi-table"></i>
                    <h6>No tables yet</h6>
                    <p>Press discover. If the account cannot read the information schema, add them by hand.</p>
                </div>
            @else
                <div class="list-group list-group-flush">
                    @foreach($tables as $table)
                        <a href="{{ route('databases.schema', [$connection->id, 'table' => $table->id]) }}"
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center
                                  {{ $selected && $selected->id === $table->id ? 'active' : '' }}">
                            <span class="{{ $table->is_present ? '' : 'text-decoration-line-through opacity-50' }}">
                                {{ $table->qualifiedName() }}
                            </span>
                            @if($table->is_enabled)
                                <span class="badge bg-success-subtle text-success-emphasis">on</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-8">
        @if(!$selected)
            <div class="card"><div class="empty"><p>Pick a table.</p></div></div>
        @else
            <div class="card mb-3">
                <div class="card-header">{{ $selected->qualifiedName() }}</div>
                <div class="card-body">
                    @unless($selected->is_present)
                        <div class="alert alert-warning" style="font-size: 0.8rem;">
                            The database no longer has this table. Its description is kept in
                            case it comes back, and a query may not name it while it is missing.
                        </div>
                    @endunless

                    <form action="{{ route('databases.tables.update', $selected->id) }}" method="POST">
                        @csrf
                        @method('PUT')

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="is_enabled" value="1"
                                   id="tableEnabled" {{ $selected->is_enabled ? 'checked' : '' }}
                                   {{ $canEdit ? '' : 'disabled' }}>
                            <label class="form-check-label" for="tableEnabled">
                                A bot may read this table
                            </label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">What this table holds</label>
                            <textarea name="description" class="form-control" rows="2" maxlength="2000"
                                      {{ $canEdit ? '' : 'disabled' }}
                                      placeholder="Orders placed through the web shop. One row per order, not per item.">{{ $selected->description }}</textarea>
                        </div>

                        @if($canEdit)
                            <button class="btn btn-brand btn-sm">Save table</button>
                        @endif
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Columns</span>
                    @if($canEdit)
                        <button class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#addColumnModal">Add by hand</button>
                    @endif
                </div>

                @if($selected->columns->isEmpty())
                    <div class="empty"><p>No columns recorded. Discover the schema, or add them by hand.</p></div>
                @else
                    <div class="table-responsive">
                        <table class="table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 220px;">Column</th>
                                    <th>What it means</th>
                                    @if($canEdit)<th style="width: 120px;"></th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($selected->columns as $column)
                                    <tr class="{{ $column->is_present ? '' : 'opacity-50' }}">
                                        <td>
                                            <div class="fw-semibold">{{ $column->column_name }}</div>
                                            <div class="text-muted" style="font-size: 0.7rem;">
                                                {{ $column->data_type ?: 'unknown type' }}@if($column->is_primary_key), primary key @endif
                                                @if($column->foreign_key_target)<br>points at {{ $column->foreign_key_target }}@endif
                                                @unless($column->is_present)<br>no longer in the database@endunless
                                            </div>
                                        </td>
                                        <td>
                                            <form action="{{ route('databases.columns.update', $column->id) }}"
                                                  method="POST" class="d-flex gap-2">
                                                @csrf
                                                @method('PUT')
                                                <textarea name="description" class="form-control form-control-sm" rows="1"
                                                          maxlength="2000" {{ $canEdit ? '' : 'disabled' }}
                                                          placeholder="Gross amount in ringgit, including tax.">{{ $column->description }}</textarea>
                                                @if($canEdit)
                                                    <button class="btn btn-sm btn-outline-primary">Save</button>
                                                @endif
                                            </form>
                                        </td>
                                        @if($canEdit)
                                            <td class="text-end">
                                                <form action="{{ route('databases.columns.destroy', $column->id) }}"
                                                      method="POST" onsubmit="return confirm('Remove {{ $column->column_name }}?');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="btn btn-sm btn-outline-danger">Remove</button>
                                                </form>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>

@if($canEdit)
<div class="modal fade" id="addTableModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="{{ route('databases.tables.store', $connection->id) }}" method="POST">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add a table by hand</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size: 0.8rem;">
                    For a database whose account cannot read the information schema.
                    Name it exactly as SQL would.
                </p>
                <div class="mb-3">
                    <label class="form-label">Schema</label>
                    <input name="schema_name" class="form-control" maxlength="128" placeholder="leave empty if none">
                </div>
                <div class="mb-3">
                    <label class="form-label">Table</label>
                    <input name="table_name" class="form-control" required maxlength="128">
                </div>
                <div>
                    <label class="form-label">What it holds</label>
                    <textarea name="description" class="form-control" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-brand">Add table</button>
            </div>
        </form>
    </div>
</div>

@if($selected)
<div class="modal fade" id="addColumnModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="{{ route('databases.columns.store', $selected->id) }}" method="POST">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add a column to {{ $selected->table_name }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Column</label>
                    <input name="column_name" class="form-control" required maxlength="128">
                </div>
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <input name="data_type" class="form-control" maxlength="64" placeholder="varchar, integer, date">
                </div>
                <div>
                    <label class="form-label">What it means</label>
                    <textarea name="description" class="form-control" rows="2" maxlength="2000"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-brand">Add column</button>
            </div>
        </form>
    </div>
</div>
@endif
@endif

@endsection
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=DbSchemaControllerTest`
Expected: PASS, 14 tests.

- [ ] **Step 7: Run the whole suite**

Run: `php artisan test`
Expected: PASS, every suite.

- [ ] **Step 8: Commit**

```bash
git add admin-laravel/app/Http/Controllers/DbSchemaController.php \
        admin-laravel/resources/views/databases/schema.blade.php \
        admin-laravel/routes/web.php \
        admin-laravel/tests/Feature/DbSchemaControllerTest.php
git commit -m "feat: discover and annotate a database schema"
```

---

### Task 8: Documentation

**Files:**
- Modify: `README.md`
- Modify: `docs/architecture.md`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing in code.

- [ ] **Step 1: Add the feature to the README**

In `README.md`, after the section numbered 6 ("Knowledge Base with Retrieval Augmented Generation"), add a new numbered section:

```markdown
### 7. 🗄️ Database Connections and Annotated Schema
- **Four drivers:** MySQL and MariaDB, PostgreSQL, SQL Server, SQLite.
- **Schema discovery:** one button reads every table, column, primary key and foreign key the connected account can see.
- **Plain-language annotation:** an editor writes what each table and column actually holds. A bot writes its queries from those sentences, which is what makes `amt_ttl` usable.
- **An allowlist, not a blocklist:** a table nobody enabled is invisible. Nothing reaches a model by default.
- **Annotations survive re-discovery.** A table that disappears is marked absent, never deleted, so a permissions blip cannot destroy somebody's work.
- **Manual entry** for accounts that cannot read the information schema, on the same screen as the discovered rows.
- **Read-only by design.** Credentials are stored encrypted and the form asks for a read-only account.
```

Renumber the sections that followed. Also note in the feature list that querying during a chat arrives in the next release, so nobody switches this on expecting a bot to use it yet.

- [ ] **Step 2: Add the reasoning to the architecture notes**

In `docs/architecture.md`, add a short section recording two decisions and their cost:

```markdown
## Database connections

Laravel holds the connection to a customer's database, not the engine. Every
driver needed is already loaded on the PHP side: pdo_mysql, pdo_pgsql,
pdo_sqlite and pdo_sqlsrv. Putting it in the engine would mean an async MySQL
driver and an ODBC stack for SQL Server, for the same four databases.

The cost is that stage two reverses the call direction for the first time: the
engine will call the portal mid-chat. That is why the new direction gets its
own shared secret rather than reusing ENGINE_ADMIN_TOKEN, and why the query
route re-validates everything the engine already validated.

Discovery merges rather than replaces. A description is written by somebody who
understands the business and is the expensive part of the feature. A table the
run no longer sees is marked absent, never deleted.
```

- [ ] **Step 3: Commit**

```bash
git add README.md docs/architecture.md
git commit -m "docs: database connections and the annotated schema"
```

---

## Stage two, for reference

Not in this plan. Sections 7 through 13 of the spec, built once stage one is in
use: the `api-engine/dbquery/` package, `LLMAdapter::complete()`, the router and
generator prompts, the read-only validator, the `POST /internal/db/query` route
with `PORTAL_INTERNAL_TOKEN`, the database playground, the bot brain block, the
three SQL model settings, and the transcript columns this plan already migrated.
