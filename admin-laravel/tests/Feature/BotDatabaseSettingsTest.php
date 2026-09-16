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
