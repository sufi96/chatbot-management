<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use App\Models\User;
use App\Services\Schema\ConsoleDatabase;
use App\Services\Schema\DbQueryRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The console assistant's Brain comes linked to this console's own database,
 * read-only, with the tables that hold secrets shut.
 */
class ConsoleDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): DbConnection
    {
        return DbConnection::findOrFail(ConsoleDatabase::ID);
    }

    private function admin(): User
    {
        return User::firstOrCreate(['email' => 'root@example.test'],
            ['name' => 'Root', 'password' => 'password', 'global_role' => 'super_admin']);
    }

    public function test_the_console_assistant_comes_linked_to_it(): void
    {
        $connection = $this->connection();
        $bot = BotProfile::console();

        $this->assertNull($connection->system_id);
        $this->assertTrue(ConsoleDatabase::is($connection));
        $this->assertNull($connection->password);
        $this->assertTrue($bot->db_query_enabled);
        $this->assertSame([ConsoleDatabase::ID], $bot->dbConnections()->pluck('db_connections.id')->all());
    }

    public function test_only_the_safe_tables_are_readable_and_described(): void
    {
        $readable = $this->connection()->readableTables()->pluck('table_name')->all();

        $this->assertContains('bot_profiles', $readable);
        $this->assertContains('chat_messages', $readable);
        foreach (['users', 'ai_providers', 'db_connections', 'app_settings', 'sessions'] as $secret) {
            $this->assertNotContains($secret, $readable);
        }
        $this->assertSame('Workspaces. Each holds bot profiles, knowledge and members.',
            DbTable::where('connection_id', ConsoleDatabase::ID)->where('table_name', 'systems')->value('description'));
    }

    public function test_it_answers_a_question_about_the_console(): void
    {
        System::create(['id' => 'sys_1', 'name' => 'Shop', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_shop', 'system_id' => 'sys_1', 'name' => 'Shop Desk']);

        $result = DbQueryRunner::run($this->connection(),
            "SELECT name FROM bot_profiles WHERE system_id = 'sys_1'", 10, 5);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame([['Shop Desk']], $result['rows']);
    }

    public function test_a_secret_table_is_refused_even_when_ticked_by_hand(): void
    {
        DbTable::where('connection_id', ConsoleDatabase::ID)->where('table_name', 'users')->update(['is_enabled' => true]);

        $result = DbQueryRunner::run($this->connection(), 'SELECT email, password FROM users', 10, 5);

        $this->assertFalse($result['ok']);
    }

    public function test_the_schema_page_cannot_open_a_secret_table(): void
    {
        $users = DbTable::where('connection_id', ConsoleDatabase::ID)->where('table_name', 'users')->firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('databases.tables.update', $users->id), ['is_enabled' => 1, 'description' => 'Accounts'])
            ->assertRedirect();
        $this->assertFalse($users->fresh()->is_enabled);

        $this->actingAs($this->admin())
            ->post(route('databases.tables.readable', ConsoleDatabase::ID), ['enabled' => 1])
            ->assertRedirect();
        $this->assertFalse($users->fresh()->is_enabled);
    }

    public function test_it_shows_on_the_console_assistants_brain_and_only_a_super_admin_opens_it(): void
    {
        System::create(['id' => 'sys_1', 'name' => 'Shop', 'allowed_origins' => '*']);
        $member = User::create(['name' => 'Owner', 'email' => 'owner@example.test',
            'password' => 'password', 'global_role' => 'user']);
        $member->systems()->attach('sys_1', ['role' => 'system_admin']);

        $this->actingAs($this->admin())->get(route('bots.brain', BotProfile::CONSOLE_ID))
            ->assertOk()->assertSee('Console database');
        $this->actingAs($this->admin())->get(route('databases.schema', ConsoleDatabase::ID))->assertOk();

        $this->actingAs($member)->get(route('databases.schema', ConsoleDatabase::ID))->assertForbidden();
        $this->actingAs($member)->get(route('databases.index'))->assertOk()->assertDontSee('Console database');
    }

    public function test_it_cannot_be_edited_or_deleted_as_a_connection(): void
    {
        $this->actingAs($this->admin())
            ->put(route('databases.update', ConsoleDatabase::ID), ['name' => 'Mine', 'driver' => 'sqlite', 'database' => '/tmp/x.sqlite'])
            ->assertForbidden();
        $this->actingAs($this->admin())->delete(route('databases.destroy', ConsoleDatabase::ID))->assertForbidden();

        $this->assertTrue(ConsoleDatabase::is($this->connection()));
    }
}
