<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        AiProvider::create([
            'id' => 'aip_1', 'system_id' => 'sys_test', 'name' => 'Laptop Ollama',
            'base_url' => 'http://localhost:11434/v1', 'api_key' => '',
        ]);
        BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support',
            'provider_id' => 'aip_1', 'model_name' => 'llama3.2',
        ]);
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

    public function test_the_list_has_no_delete_button(): void
    {
        $this->actingAs($this->member('system_admin'))
            ->get(route('bots.index'))
            ->assertOk()
            ->assertSee('bi-gear', false)
            ->assertDontSee('action="' . route('bots.destroy', 'bot_1') . '"', false);
    }

    public function test_the_settings_page_asks_for_the_name_before_deleting(): void
    {
        $this->actingAs($this->member('system_admin'))
            ->get(route('bots.edit', 'bot_1'))
            ->assertOk()
            ->assertSee('Delete bot profile')
            ->assertSee('form="botDeleteForm"', false)
            ->assertSee('data-confirm-type="Support"', false)
            ->assertSee('action="' . route('bots.destroy', 'bot_1') . '"', false);
    }

    public function test_an_editor_does_not_see_the_delete_section(): void
    {
        $this->actingAs($this->member('editor'))
            ->get(route('bots.edit', 'bot_1'))
            ->assertOk()
            ->assertDontSee('form="botDeleteForm"', false);
    }

    public function test_the_typed_name_soft_deletes_the_bot(): void
    {
        $this->actingAs($this->member('system_admin'))
            ->delete(route('bots.destroy', 'bot_1'), ['confirm_name' => 'Support'])
            ->assertRedirect(route('bots.index'));

        $this->assertSoftDeleted('bot_profiles', ['id' => 'bot_1']);
    }

    public function test_a_wrong_or_missing_name_keeps_the_bot(): void
    {
        $admin = $this->member('system_admin');

        $this->actingAs($admin)
            ->from(route('bots.edit', 'bot_1'))
            ->delete(route('bots.destroy', 'bot_1'), ['confirm_name' => 'support'])
            ->assertRedirect(route('bots.edit', 'bot_1'))
            ->assertSessionHas('error');

        $this->actingAs($admin)->delete(route('bots.destroy', 'bot_1'));

        $this->assertDatabaseHas('bot_profiles', ['id' => 'bot_1']);
    }
}
