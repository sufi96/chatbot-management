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

    public function test_a_port_above_the_smallint_ceiling_is_savable(): void
    {
        // unsignedSmallInteger becomes a signed smallint on Postgres, capped at
        // 32767, so a perfectly ordinary high port used to blow up on save.
        $this->system();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.store'), [
                'name' => 'High port', 'driver' => 'sqlsrv', 'host' => 'db',
                'port' => 50075, 'database' => 'reporting',
            ])
            ->assertRedirect(route('databases.index'));

        $this->assertSame(50075, (int) DbConnection::where('name', 'High port')->value('port'));
    }

    public function test_a_port_beyond_the_tcp_range_is_still_rejected(): void
    {
        $this->system();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.store'), [
                'name' => 'Silly port', 'driver' => 'sqlsrv', 'host' => 'db',
                'port' => 70000, 'database' => 'reporting',
            ])
            ->assertSessionHasErrors('port');
    }

    public function test_a_draft_can_be_tested_before_it_is_saved(): void
    {
        $this->system();
        $path = tempnam(sys_get_temp_dir(), 'draft') . '.sqlite';
        touch($path);

        try {
            $this->actingAs($this->userWithRole('editor'))
                ->postJson(route('databases.test-draft'), [
                    'driver' => 'sqlite', 'database' => $path,
                ])
                ->assertOk()
                ->assertJson(['ok' => true]);

            // Testing must never leave a record behind.
            $this->assertSame(0, DbConnection::count());
        } finally {
            @unlink($path);
        }
    }

    public function test_a_failing_draft_reports_the_drivers_message(): void
    {
        $this->system();

        $response = $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('databases.test-draft'), [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
                'database' => 'nope', 'username' => 'x', 'password' => 'y',
            ])
            ->assertOk()
            ->assertJson(['ok' => false]);

        $this->assertNotSame('', $response->json('message'));
    }

    public function test_a_viewer_cannot_test_a_draft(): void
    {
        $this->system();

        $this->actingAs($this->userWithRole('viewer'))
            ->postJson(route('databases.test-draft'), [
                'driver' => 'sqlite', 'database' => ':memory:',
            ])
            ->assertForbidden();
    }

    public function test_a_draft_test_rejects_an_unknown_driver(): void
    {
        $this->system();

        $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('databases.test-draft'), [
                'driver' => 'oracle', 'database' => 'legacy',
            ])
            ->assertStatus(422);
    }

    public function test_a_draft_test_cannot_borrow_another_workspaces_password(): void
    {
        // connection_id is how a blank password reaches the stored one. Scoped
        // to the active workspace, or it would be a credential oracle.
        $this->system();
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        DbConnection::create([
            'id' => 'dbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs',
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
            'database' => 'theirs', 'username' => 'root', 'password' => 'theirsecret',
        ]);

        $response = $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('databases.test-draft'), [
                'connection_id' => 'dbc_other',
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
                'database' => 'theirs', 'username' => 'root', 'password' => '',
            ])
            ->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertStringNotContainsString('theirsecret', $response->json('message'));
    }
}
