<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use App\Models\WebSearchKey;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A workspace's own web search keys, and which search each bot uses. */
class WebSearchKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper']);
    }

    private function member(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => "{$role}@example.test",
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => $role]);

        return $user;
    }

    private function key(string $id = 'wsk_1', string $system = 'sys_test'): WebSearchKey
    {
        return WebSearchKey::create(['id' => $id, 'system_id' => $system, 'name' => "Key {$id}",
            'provider' => 'brave', 'api_key' => 'secret-brave-key-1234']);
    }

    private function brainPayload(array $overrides = []): array
    {
        return array_merge([
            'system_prompt' => 'Be helpful.', 'retrieval_mode' => 'hybrid',
            'retrieval_top_k' => 5, 'retrieval_candidates' => 30,
            'retrieval_min_score' => 0.02, 'retrieval_fallback' => 'say_unknown',
            'web_search_max_results' => 3, 'web_search_country' => null,
            'top_p' => 1.0, 'top_k_sampling' => null, 'presence_penalty' => 0,
            'frequency_penalty' => 0, 'thinking_level' => 'off',
            'db_max_rows' => 50, 'db_query_timeout' => 10,
            'source_order' => SourceOrder::DEFAULT,
        ], $overrides);
    }

    public function test_a_bot_keeps_the_platform_search_until_told_otherwise(): void
    {
        $bot = BotProfile::find('bot_1');

        $this->assertSame('platform', $bot->web_search_mode);
        $this->assertNull($bot->web_search_key_id);
        $this->assertSame('all', AppSetting::get('web_search_lending'));
    }

    public function test_a_system_admin_adds_a_key_and_it_never_comes_back(): void
    {
        $response = $this->actingAs($this->member('system_admin'))
            ->postJson(route('web-search-keys.store'), [
                'system_id' => 'sys_test', 'name' => 'Marketing', 'provider' => 'tavily', 'api_key' => 'tvly-abcdef123456',
            ])
            ->assertOk()
            ->assertJsonPath('key.name', 'Marketing')
            ->assertJsonPath('key.hint', '…3456');

        $this->assertStringNotContainsString('tvly-abcdef123456', $response->getContent());
        $this->assertSame('tvly-abcdef123456', WebSearchKey::first()->api_key);
    }

    public function test_an_editor_cannot_add_or_change_keys(): void
    {
        $editor = $this->member('editor');
        $key = $this->key();

        $this->actingAs($editor)->postJson(route('web-search-keys.store'), [
            'system_id' => 'sys_test', 'name' => 'Mine', 'provider' => 'brave', 'api_key' => 'x',
        ])->assertForbidden();
        $this->actingAs($editor)->deleteJson(route('web-search-keys.destroy', $key->id))->assertForbidden();
    }

    public function test_a_blank_key_on_edit_keeps_the_saved_one(): void
    {
        $key = $this->key();

        $this->actingAs($this->member('system_admin'))
            ->putJson(route('web-search-keys.update', $key->id), ['name' => 'Renamed', 'provider' => 'brave', 'api_key' => ''])
            ->assertOk();

        $this->assertSame('Renamed', $key->fresh()->name);
        $this->assertSame('secret-brave-key-1234', $key->fresh()->api_key);
    }

    public function test_a_key_in_use_is_not_deleted(): void
    {
        $key = $this->key();
        BotProfile::find('bot_1')->update(['web_search_mode' => 'own', 'web_search_key_id' => $key->id]);

        $this->actingAs($this->member('system_admin'))
            ->deleteJson(route('web-search-keys.destroy', $key->id))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Still used by Helper. Point it at another search first.');

        $this->assertNotNull($key->fresh());
    }

    public function test_an_editor_points_a_bot_at_a_workspace_key(): void
    {
        $key = $this->key();

        $this->actingAs($this->member('editor'))
            ->put(route('bots.brain.update', 'bot_1'), $this->brainPayload([
                'web_search_mode' => 'own', 'web_search_key_id' => $key->id,
            ]))
            ->assertSessionHasNoErrors();

        $bot = BotProfile::find('bot_1');
        $this->assertSame('own', $bot->web_search_mode);
        $this->assertSame($key->id, $bot->web_search_key_id);
    }

    public function test_another_workspaces_key_is_refused(): void
    {
        $this->key('wsk_theirs', 'sys_other');

        $this->actingAs($this->member('editor'))
            ->put(route('bots.brain.update', 'bot_1'), $this->brainPayload([
                'web_search_mode' => 'own', 'web_search_key_id' => 'wsk_theirs',
            ]))
            ->assertSessionHasErrors('web_search_key_id');
    }

    public function test_own_needs_a_key_and_other_modes_drop_it(): void
    {
        $key = $this->key();
        $editor = $this->member('editor');

        $this->actingAs($editor)
            ->put(route('bots.brain.update', 'bot_1'), $this->brainPayload(['web_search_mode' => 'own']))
            ->assertSessionHasErrors('web_search_key_id');

        BotProfile::find('bot_1')->update(['web_search_mode' => 'own', 'web_search_key_id' => $key->id]);
        $this->actingAs($editor)
            ->put(route('bots.brain.update', 'bot_1'), $this->brainPayload([
                'web_search_mode' => 'duckduckgo', 'web_search_key_id' => $key->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull(BotProfile::find('bot_1')->web_search_key_id);
    }

    public function test_the_behaviour_page_offers_the_choice_without_any_key(): void
    {
        $this->key();

        $this->actingAs($this->member('system_admin'))
            ->get(route('bots.brain', 'bot_1'))
            ->assertOk()
            ->assertSee('name="web_search_mode"', false)
            ->assertSee('Key wsk_1')
            ->assertSee('New key')
            ->assertDontSee('secret-brave-key-1234');
    }

    public function test_a_saved_key_is_tested_on_the_server(): void
    {
        Http::fake(['*/api/v1/kb/websearch/test' => Http::response(['ok' => true, 'message' => 'It works: 3 results for a test search.'])]);
        $key = $this->key();

        $this->actingAs($this->member('system_admin'))
            ->postJson(route('web-search-keys.test'), ['key_id' => $key->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        Http::assertSent(fn ($request) => $request['api_key'] === 'secret-brave-key-1234' && $request['provider'] === 'brave');
    }

    public function test_the_console_assistant_can_never_read_the_keys(): void
    {
        $this->assertContains('web_search_keys', \App\Services\Schema\ConsoleDatabase::NEVER);
    }

    public function test_a_super_admin_can_stop_lending_the_platform_search(): void
    {
        $root = User::create(['name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin']);

        $this->actingAs($root)
            ->put(route('admin.settings.update'), [
                'section' => 'web-search', 'embedding_model' => 'nomic-embed-text', 'embedding_dimensions' => 768,
                'chunk_size' => 1800, 'chunk_overlap' => 200, 'context_char_budget' => 6000,
                'web_search_provider' => 'brave', 'web_search_lending' => 'none',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('none', AppSetting::get('web_search_lending'));

        $this->actingAs($this->member('editor'))
            ->get(route('bots.brain', 'bot_1'))
            ->assertSee('The platform does not lend its search to workspaces');
    }
}
