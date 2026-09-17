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
use Tests\Concerns\WithoutConsoleDatabase;
use Tests\TestCase;

class DatabaseConnectionSchemaTest extends TestCase
{
    use RefreshDatabase, WithoutConsoleDatabase;

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
