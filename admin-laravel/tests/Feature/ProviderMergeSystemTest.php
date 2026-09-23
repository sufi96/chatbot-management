<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Send instructions inside the message", for a gateway that drops system
 * messages. Saved on a provider from either form; the engine reads it.
 */
class ProviderMergeSystemTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        System::create(['id' => 'sys_1', 'name' => 'Shop', 'allowed_origins' => '*']);
        $user = User::create(['name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user']);
        $user->systems()->attach('sys_1', ['role' => 'editor']);

        return $user;
    }

    private function admin(): User
    {
        return User::create(['name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin']);
    }

    public function test_it_is_off_unless_ticked(): void
    {
        $this->actingAs($this->editor())
            ->postJson(route('providers.store'), ['system_id' => 'sys_1', 'name' => 'Local', 'base_url' => 'http://localhost:11434/v1'])
            ->assertOk()
            ->assertJsonPath('provider.merge_system_prompt', false);
    }

    public function test_a_workspace_provider_saves_and_clears_it(): void
    {
        $editor = $this->editor();

        $id = $this->actingAs($editor)
            ->postJson(route('providers.store'), ['system_id' => 'sys_1', 'name' => 'Gateway',
                'base_url' => 'https://gateway.test/v1', 'merge_system_prompt' => true])
            ->assertOk()
            ->assertJsonPath('provider.merge_system_prompt', true)
            ->json('provider.id');
        $this->assertTrue(AiProvider::find($id)->merge_system_prompt);

        $this->actingAs($editor)
            ->putJson(route('providers.update', $id), ['name' => 'Gateway', 'base_url' => 'https://gateway.test/v1'])
            ->assertOk();
        $this->assertFalse(AiProvider::find($id)->merge_system_prompt);
    }

    public function test_a_platform_provider_saves_it(): void
    {
        $id = $this->actingAs($this->admin())
            ->postJson(route('admin.providers.store'), ['name' => 'Gateway',
                'base_url' => 'https://gateway.test/v1', 'merge_system_prompt' => true])
            ->assertOk()
            ->assertJsonPath('provider.merge_system_prompt', true)
            ->json('provider.id');

        $this->assertTrue(AiProvider::find($id)->merge_system_prompt);
    }

    public function test_the_bot_form_carries_it_for_the_provider_editor(): void
    {
        $editor = $this->editor();
        AiProvider::create(['id' => 'aip_gw', 'system_id' => 'sys_1', 'name' => 'Gateway',
            'base_url' => 'https://gateway.test/v1', 'api_key' => '', 'merge_system_prompt' => true]);
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_1', 'name' => 'Desk', 'provider_id' => 'aip_gw']);

        $this->actingAs($editor)->get(route('bots.brain', 'bot_1'))
            ->assertOk()
            ->assertSee('data-merge-system="1"', false)
            ->assertSee('id="providerMergeSystem"', false);
    }
}
