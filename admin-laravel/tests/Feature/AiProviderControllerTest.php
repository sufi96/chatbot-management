<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProviderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function system(string $id = 'sys_test', string $name = 'W'): System
    {
        return System::create(['id' => $id, 'name' => $name, 'allowed_origins' => '*']);
    }

    private function userWithRole(string $role, string $systemId = 'sys_test'): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => "{$role}@example.test",
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach($systemId, ['role' => $role]);

        return $user;
    }

    private function provider(string $id = 'aip_1', string $systemId = 'sys_test'): AiProvider
    {
        return AiProvider::create([
            'id' => $id, 'system_id' => $systemId, 'name' => 'Laptop Ollama',
            'base_url' => 'http://localhost:11434/v1', 'api_key' => '',
        ]);
    }

    public function test_an_editor_creates_a_provider(): void
    {
        $this->system();

        $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('providers.store'), [
                'name' => 'Office PC',
                'base_url' => 'http://192.168.1.5:11434/v1',
                'api_key' => '',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('ai_providers', [
            'system_id' => 'sys_test',
            'name' => 'Office PC',
            'base_url' => 'http://192.168.1.5:11434/v1',
        ]);
    }

    public function test_the_created_provider_comes_back_so_the_form_can_select_it(): void
    {
        $this->system();

        $response = $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('providers.store'), [
                'name' => 'Groq', 'base_url' => 'https://api.groq.com/openai/v1',
                'api_key' => 'gsk_secret',
            ])
            ->assertOk();

        $id = $response->json('provider.id');

        $this->assertNotEmpty($id);
        $this->assertSame('Groq', $response->json('provider.name'));
        $this->assertSame($id, AiProvider::first()->id);
    }

    public function test_a_viewer_cannot_create_a_provider(): void
    {
        $this->system();

        $this->actingAs($this->userWithRole('viewer'))
            ->postJson(route('providers.store'), [
                'name' => 'Sneaky', 'base_url' => 'http://evil.test/v1', 'api_key' => '',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('ai_providers', 0);
    }

    public function test_a_provider_needs_a_name_and_a_base_url(): void
    {
        $this->system();

        $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('providers.store'), ['name' => '', 'base_url' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'base_url']);
    }

    public function test_an_editor_updates_a_provider(): void
    {
        $this->system();
        $this->provider();

        $this->actingAs($this->userWithRole('editor'))
            ->putJson(route('providers.update', 'aip_1'), [
                'name' => 'Laptop Ollama',
                'base_url' => 'http://192.168.1.9:11434/v1',
                'api_key' => '',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('http://192.168.1.9:11434/v1', AiProvider::find('aip_1')->base_url);
    }

    /**
     * The whole point of linking rather than copying: one edit moves every
     * bot on that endpoint.
     */
    public function test_editing_a_provider_moves_every_bot_that_uses_it(): void
    {
        $this->system();
        $this->provider();
        BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support',
            'provider_id' => 'aip_1', 'model_name' => 'llama3.2',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->putJson(route('providers.update', 'aip_1'), [
                'name' => 'Laptop Ollama', 'base_url' => 'http://10.0.0.4:11434/v1', 'api_key' => '',
            ])
            ->assertOk();

        $this->assertSame(
            'http://10.0.0.4:11434/v1',
            BotProfile::find('bot_1')->provider->base_url);
    }

    public function test_an_editor_deletes_an_unused_provider(): void
    {
        $this->system();
        $this->provider();

        $this->actingAs($this->userWithRole('editor'))
            ->deleteJson(route('providers.destroy', 'aip_1'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseCount('ai_providers', 0);
    }

    /**
     * Deleting an endpoint out from under a live bot would leave it unable to
     * answer, so the refusal names the bots holding it.
     */
    public function test_a_provider_a_bot_still_uses_is_not_deleted(): void
    {
        $this->system();
        $this->provider();
        BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support',
            'provider_id' => 'aip_1', 'model_name' => 'llama3.2',
        ]);

        $response = $this->actingAs($this->userWithRole('editor'))
            ->deleteJson(route('providers.destroy', 'aip_1'))
            ->assertStatus(409)
            ->assertJson(['success' => false]);

        $this->assertStringContainsString('Support', $response->json('message'));
        $this->assertDatabaseCount('ai_providers', 1);
    }

    public function test_a_provider_from_another_workspace_is_out_of_reach(): void
    {
        $this->system();
        $this->system('sys_other', 'Other');
        $this->provider('aip_other', 'sys_other');

        $this->actingAs($this->userWithRole('editor'))
            ->putJson(route('providers.update', 'aip_other'), [
                'name' => 'Hijacked', 'base_url' => 'http://evil.test/v1', 'api_key' => '',
            ])
            ->assertForbidden();

        $this->assertSame('Laptop Ollama', AiProvider::find('aip_other')->name);
    }

    public function test_the_bot_form_lists_the_workspaces_providers(): void
    {
        $this->system();
        $this->system('sys_other', 'Other');
        $this->provider();
        AiProvider::create([
            'id' => 'aip_other', 'system_id' => 'sys_other', 'name' => 'Somebody Elses Key',
            'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk_theirs',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('bots.create'))
            ->assertOk()
            ->assertSee('Laptop Ollama')
            ->assertDontSee('Somebody Elses Key')
            ->assertDontSee('sk_theirs');
    }

    public function test_the_bot_list_names_the_provider_a_bot_points_at(): void
    {
        $this->system();
        $this->provider();
        BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support',
            'provider_id' => 'aip_1', 'model_name' => 'llama3.2',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('bots.index'))
            ->assertOk()
            ->assertSee('Laptop Ollama')
            ->assertSee('http://localhost:11434/v1');
    }

    public function test_the_dashboard_names_the_provider_a_bot_points_at(): void
    {
        $this->system();
        $this->provider();
        BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support',
            'provider_id' => 'aip_1', 'model_name' => 'llama3.2',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Laptop Ollama');
    }

    /**
     * A crafted form id must not let one workspace borrow another's key.
     */
    public function test_a_bot_cannot_be_pointed_at_another_workspaces_provider(): void
    {
        $this->system();
        $this->system('sys_other', 'Other');
        AiProvider::create([
            'id' => 'aip_other', 'system_id' => 'sys_other', 'name' => 'Theirs',
            'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk_theirs',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('bots.store'), [
                'name' => 'Sneaky', 'provider_id' => 'aip_other', 'model_name' => 'gpt-4o-mini',
                'temperature' => 0.7, 'max_tokens' => 1024,
                'widget_title' => 'Support', 'widget_primary_color' => '#000000',
                'widget_position' => 'bottom-right',
            ])
            ->assertSessionHasErrors('provider_id');

        // The console assistant is always there; no workspace bot was made.
        $this->assertSame(0, BotProfile::where('is_platform', false)->count());
    }

    public function test_a_bot_saves_the_provider_it_was_pointed_at(): void
    {
        $this->system();
        $this->provider();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('bots.store'), [
                'name' => 'Support', 'provider_id' => 'aip_1', 'model_name' => 'llama3.2',
                'temperature' => 0.7, 'max_tokens' => 1024,
                'widget_title' => 'Support', 'widget_primary_color' => '#000000',
                'widget_position' => 'bottom-right',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('aip_1', BotProfile::where('is_platform', false)->first()->provider_id);
    }

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin',
        ]);
    }

    private function botPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Support', 'model_name' => 'llama3.2',
            'temperature' => 0.7, 'max_tokens' => 1024,
            'widget_title' => 'Support', 'widget_primary_color' => '#000000',
            'widget_position' => 'bottom-right',
        ], $overrides);
    }

    private function platformProvider(string $baseUrl = 'http://spark:8000/v1', string $key = ''): AiProvider
    {
        return AiProvider::create([
            'id' => 'aip_platform', 'system_id' => null, 'name' => 'Platform Endpoint',
            'base_url' => $baseUrl, 'api_key' => $key,
        ]);
    }

    /**
     * Two endpoints can share a name and a URL and differ only in whose key
     * they carry, so every entry names the workspace it belongs to.
     */
    public function test_a_super_admin_sees_every_provider_named_by_its_workspace(): void
    {
        $this->system('sys_test', 'Corporate');
        $this->system('sys_other', 'Store');
        $this->provider();
        AiProvider::create([
            'id' => 'aip_other', 'system_id' => 'sys_other', 'name' => 'Store Key',
            'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk_store',
        ]);
        $this->platformProvider();

        $this->actingAs($this->superAdmin())
            ->withSession(['active_system_id' => 'sys_test'])
            ->get(route('bots.create'))
            ->assertOk()
            ->assertSee('Laptop Ollama')
            ->assertSee('Store Key')
            ->assertSee('Platform Endpoint')
            ->assertSee('label="Store"', false)
            ->assertSee('label="Platform"', false);
    }

    public function test_an_editor_of_two_workspaces_sees_both_and_nothing_else(): void
    {
        $this->system('sys_test', 'Corporate');
        $this->system('sys_other', 'Store');
        $this->system('sys_third', 'Third');
        $this->provider();
        $this->provider('aip_other', 'sys_other');
        AiProvider::create([
            'id' => 'aip_third', 'system_id' => 'sys_third', 'name' => 'Third Key',
            'base_url' => 'https://api.openai.com/v1', 'api_key' => 'sk_third',
        ]);
        $this->platformProvider(key: 'sk_platform');

        $editor = $this->userWithRole('editor');
        $editor->systems()->attach('sys_other', ['role' => 'system_admin']);
        $editor->systems()->attach('sys_third', ['role' => 'viewer']);

        $this->actingAs($editor)
            ->withSession(['active_system_id' => 'sys_test'])
            ->get(route('bots.create'))
            ->assertOk()
            ->assertSee('label="Corporate', false)
            ->assertSee('label="Store"', false)
            ->assertDontSee('Third Key')
            ->assertDontSee('sk_third')
            ->assertDontSee('Platform Endpoint')
            ->assertDontSee('sk_platform');
    }

    public function test_a_super_admin_points_a_bot_at_any_provider(): void
    {
        $this->system();
        $this->platformProvider();

        $this->actingAs($this->superAdmin())
            ->withSession(['active_system_id' => 'sys_test'])
            ->post(route('bots.store'), $this->botPayload(['provider_id' => 'aip_platform']))
            ->assertSessionHasNoErrors();

        $this->assertSame('aip_platform', BotProfile::where('is_platform', false)->first()->provider_id);
    }

    public function test_an_editor_of_two_workspaces_may_use_either_workspaces_provider(): void
    {
        $this->system();
        $this->system('sys_other', 'Other');
        $this->provider('aip_other', 'sys_other');

        $editor = $this->userWithRole('editor');
        $editor->systems()->attach('sys_other', ['role' => 'editor']);

        $this->actingAs($editor)
            ->withSession(['active_system_id' => 'sys_test'])
            ->post(route('bots.store'), $this->botPayload(['provider_id' => 'aip_other']))
            ->assertSessionHasNoErrors();

        $this->assertSame('aip_other', BotProfile::where('is_platform', false)->first()->provider_id);
    }

    public function test_viewing_a_workspace_does_not_lend_its_providers(): void
    {
        $this->system();
        $this->system('sys_other', 'Other');
        $this->provider('aip_other', 'sys_other');

        $editor = $this->userWithRole('editor');
        $editor->systems()->attach('sys_other', ['role' => 'viewer']);

        $this->actingAs($editor)
            ->withSession(['active_system_id' => 'sys_test'])
            ->post(route('bots.store'), $this->botPayload(['provider_id' => 'aip_other']))
            ->assertSessionHasErrors('provider_id');
    }

    /**
     * A super admin may have pointed this bot somewhere its own editors cannot
     * see. They keep the choice and its name, never its URL or key.
     */
    public function test_a_provider_set_from_above_stays_but_does_not_leak(): void
    {
        $this->system('sys_test', 'Corporate');
        $this->provider();
        $this->platformProvider('http://spark-secret:8000/v1', 'sk_platform');
        BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support',
            'provider_id' => 'aip_platform', 'model_name' => 'llama3.2',
        ]);
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)
            ->get(route('bots.brain', 'bot_1'))
            ->assertOk()
            ->assertSee('Platform Endpoint')
            ->assertDontSee('spark-secret')
            ->assertDontSee('sk_platform');

        $this->actingAs($editor)
            ->get(route('bots.index'))
            ->assertOk()
            ->assertSee('Set by Platform')
            ->assertDontSee('spark-secret');

        $this->actingAs($editor)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('spark-secret');

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->botPayload(['provider_id' => 'aip_platform']))
            ->assertSessionHasNoErrors();

        $this->assertSame('aip_platform', BotProfile::find('bot_1')->provider_id);
    }

    public function test_a_new_provider_is_saved_into_the_workspace_the_form_names(): void
    {
        $this->system();
        $this->system('sys_other', 'Other');

        $this->actingAs($this->superAdmin())
            ->withSession(['active_system_id' => 'sys_test'])
            ->postJson(route('providers.store'), [
                'system_id' => 'sys_other', 'name' => 'Office PC',
                'base_url' => 'http://192.168.1.5:11434/v1', 'api_key' => '',
            ])
            ->assertOk()
            ->assertJsonPath('provider.owner', 'Other');

        $this->assertDatabaseHas('ai_providers', ['name' => 'Office PC', 'system_id' => 'sys_other']);
    }

    /**
     * Two entries in one workspace that read the same in the picker leave an
     * operator guessing which one a bot is on.
     */
    public function test_a_workspace_cannot_hold_two_providers_with_one_name(): void
    {
        $this->system();
        $this->provider();

        $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('providers.store'), [
                'name' => 'laptop ollama ', 'base_url' => 'http://192.168.1.5:11434/v1', 'api_key' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('ai_providers', 1);
    }

    public function test_a_workspace_cannot_save_the_same_endpoint_and_key_twice(): void
    {
        $this->system();
        $this->provider();

        $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('providers.store'), [
                'name' => 'Office Ollama', 'base_url' => 'http://localhost:11434/v1/', 'api_key' => '',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('base_url');
    }

    public function test_the_same_endpoint_with_another_key_is_a_different_provider(): void
    {
        $this->system();
        $this->provider();

        $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('providers.store'), [
                'name' => 'Ollama with key', 'base_url' => 'http://localhost:11434/v1', 'api_key' => 'sk_other',
            ])
            ->assertOk();
    }

    public function test_another_workspace_may_reuse_the_name(): void
    {
        $this->system();
        $this->system('sys_other', 'Other');
        $this->provider('aip_other', 'sys_other');

        $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('providers.store'), [
                'name' => 'Laptop Ollama', 'base_url' => 'http://localhost:11434/v1', 'api_key' => '',
            ])
            ->assertOk();
    }

    public function test_saving_a_provider_unchanged_is_not_a_clash_with_itself(): void
    {
        $this->system();
        $this->provider();

        $this->actingAs($this->userWithRole('editor'))
            ->putJson(route('providers.update', 'aip_1'), [
                'name' => 'Laptop Ollama', 'base_url' => 'http://localhost:11434/v1', 'api_key' => '',
            ])
            ->assertOk();
    }

    public function test_an_editor_cannot_save_a_provider_into_a_workspace_they_do_not_edit(): void
    {
        $this->system();
        $this->system('sys_other', 'Other');

        $this->actingAs($this->userWithRole('editor'))
            ->postJson(route('providers.store'), [
                'system_id' => 'sys_other', 'name' => 'Sneaky',
                'base_url' => 'http://evil.test/v1', 'api_key' => '',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('ai_providers', 0);
    }
}
