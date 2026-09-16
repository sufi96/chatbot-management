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

        $this->assertDatabaseCount('bot_profiles', 0);
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

        $this->assertSame('aip_1', BotProfile::first()->provider_id);
    }
}
