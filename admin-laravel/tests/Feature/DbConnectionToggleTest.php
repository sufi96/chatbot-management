<?php

namespace Tests\Feature;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbConnectionToggleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
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

    private function connection(string $id = 'dbc_1'): DbConnection
    {
        return DbConnection::create([
            'id' => $id, 'system_id' => 'sys_test', 'name' => 'Shop',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
    }

    public function test_a_new_connection_is_enabled(): void
    {
        // Tables default to off, so nothing is readable until somebody ticks
        // one. Defaulting the connection to on means ticking a table does what
        // it looks like it does.
        $this->assertTrue($this->connection()->fresh()->is_enabled);
    }

    public function test_an_editor_disables_a_whole_connection(): void
    {
        $this->connection();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.toggle', 'dbc_1'))
            ->assertRedirect();

        $this->assertFalse(DbConnection::find('dbc_1')->is_enabled);
    }

    public function test_toggling_twice_puts_it_back(): void
    {
        $this->connection();
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)->post(route('databases.toggle', 'dbc_1'));
        $this->actingAs($editor)->post(route('databases.toggle', 'dbc_1'));

        $this->assertTrue(DbConnection::find('dbc_1')->is_enabled);
    }

    public function test_disabling_leaves_every_table_tick_alone(): void
    {
        // The switch is a gate, not an eraser. Turning a database off for a
        // week must not cost somebody their allowlist.
        $connection = $this->connection();
        DbTable::create([
            'connection_id' => $connection->id, 'table_name' => 'orders',
            'is_enabled' => true, 'description' => 'Worth keeping.',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.toggle', 'dbc_1'));

        $table = DbTable::where('table_name', 'orders')->first();
        $this->assertTrue($table->is_enabled);
        $this->assertSame('Worth keeping.', $table->description);
    }

    public function test_a_viewer_cannot_toggle(): void
    {
        $this->connection();

        $this->actingAs($this->userWithRole('viewer'))
            ->post(route('databases.toggle', 'dbc_1'))
            ->assertForbidden();

        $this->assertTrue(DbConnection::find('dbc_1')->is_enabled);
    }

    public function test_another_workspaces_connection_cannot_be_toggled(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        DbConnection::create([
            'id' => 'dbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.toggle', 'dbc_other'))
            ->assertForbidden();

        $this->assertTrue(DbConnection::find('dbc_other')->is_enabled);
    }

    public function test_the_list_names_both_states(): void
    {
        $this->connection('dbc_on');
        DbConnection::create([
            'id' => 'dbc_off', 'system_id' => 'sys_test', 'name' => 'Archive',
            'driver' => 'sqlite', 'database' => ':memory:', 'is_enabled' => false,
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('databases.index'))
            ->assertOk()
            ->assertSee('Enabled')
            ->assertSee('Disabled');
    }

    public function test_the_schema_screen_warns_when_the_connection_is_off(): void
    {
        $connection = $this->connection();
        $connection->update(['is_enabled' => false]);
        $table = DbTable::create([
            'connection_id' => $connection->id, 'table_name' => 'orders', 'is_enabled' => true,
        ]);
        DbColumn::create(['table_id' => $table->id, 'column_name' => 'id', 'ordinal' => 1]);

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('databases.schema', 'dbc_1'))
            ->assertOk()
            ->assertSee('switched off', false);
    }
}
