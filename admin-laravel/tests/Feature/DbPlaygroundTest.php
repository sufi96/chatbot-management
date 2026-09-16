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
        // The opening check fires before the verb list, so this is the
        // message a DELETE earns rather than "DELETE is not allowed".
        $this->shop();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.playground.run'), [
                'connection_id' => 'dbc_1', 'sql' => 'DELETE FROM orders',
            ])
            ->assertOk()
            ->assertSee('must begin with SELECT');
    }

    public function test_a_forbidden_verb_inside_a_select_is_refused_by_name(): void
    {
        // SELECT ... INTO writes a table on several dialects, and it gets
        // past the opening check, so this is the verb list doing its job.
        $this->shop();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.playground.run'), [
                'connection_id' => 'dbc_1', 'sql' => 'SELECT * INTO backup FROM orders',
            ])
            ->assertOk()
            ->assertSee('INTO is not allowed');
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
