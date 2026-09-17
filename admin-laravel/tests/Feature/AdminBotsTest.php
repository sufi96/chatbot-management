<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_a', 'name' => 'Alpha', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_b', 'name' => 'Bravo', 'allowed_origins' => '*']);
        AiProvider::create([
            'id' => 'aip_1', 'system_id' => 'sys_a', 'name' => 'Laptop Ollama',
            'base_url' => 'http://localhost:11434/v1', 'api_key' => '',
        ]);

        $this->bot('bot_live', 'sys_a', 'Sales Helper', true);
        $this->bot('bot_off', 'sys_b', 'Billing Desk', false);
        $this->bot('bot_gone', 'sys_b', 'Old Support', true)->delete();
    }

    private function bot(string $id, string $system, string $name, bool $active): BotProfile
    {
        return BotProfile::create([
            'id' => $id, 'system_id' => $system, 'name' => $name,
            'provider_id' => 'aip_1', 'model_name' => 'llama3.2', 'is_active' => $active,
        ]);
    }

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin',
        ]);
    }

    private function workspaceAdmin(): User
    {
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_b', ['role' => 'system_admin']);

        return $user;
    }

    public function test_only_a_super_admin_opens_the_page(): void
    {
        $this->actingAs($this->workspaceAdmin())->get(route('admin.bots.index'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('admin.bots.index'))->assertOk();
    }

    public function test_it_lists_every_workspace_with_a_status_badge(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.bots.index'))
            ->assertOk()
            ->assertSeeInOrder(['Sales Helper', 'Alpha', 'Active'])
            ->assertSeeInOrder(['Billing Desk', 'Bravo', 'Deactivated'])
            ->assertSeeInOrder(['Old Support', 'Bravo', 'Deleted']);
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('admin.bots.index', ['workspace' => 'sys_b']))
            ->assertDontSee('Sales Helper')->assertSee('Billing Desk')->assertSee('Old Support');

        $this->actingAs($admin)->get(route('admin.bots.index', ['status' => 'deleted']))
            ->assertDontSee('Sales Helper')->assertDontSee('Billing Desk')->assertSee('Old Support');

        $this->actingAs($admin)->get(route('admin.bots.index', ['status' => 'deactivated']))
            ->assertSee('Billing Desk')->assertDontSee('Old Support');

        $this->actingAs($admin)->get(route('admin.bots.index', ['q' => 'sales']))
            ->assertSee('Sales Helper')->assertDontSee('Billing Desk');
    }

    public function test_the_sidebar_links_to_the_page_for_a_super_admin_only(): void
    {
        $this->actingAs($this->superAdmin())->get(route('dashboard'))
            ->assertSee('href="' . route('admin.bots.index') . '"', false);

        $this->actingAs($this->workspaceAdmin())->get(route('dashboard'))
            ->assertDontSee('href="' . route('admin.bots.index') . '"', false);
    }

    public function test_a_super_admin_restores_a_deleted_bot(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.bots.restore', 'bot_gone'))
            ->assertSessionHas('success');

        $this->assertNotSoftDeleted('bot_profiles', ['id' => 'bot_gone']);
    }

    public function test_purging_needs_the_name_and_erases_the_conversations(): void
    {
        $admin = $this->superAdmin();
        ChatConversation::create(['id' => 'conv_1', 'bot_id' => 'bot_gone', 'session_id' => 's1']);

        $this->actingAs($admin)
            ->delete(route('admin.bots.purge', 'bot_gone'), ['confirm_name' => 'old support'])
            ->assertSessionHas('error');
        $this->assertSoftDeleted('bot_profiles', ['id' => 'bot_gone']);

        $this->actingAs($admin)
            ->delete(route('admin.bots.purge', 'bot_gone'), ['confirm_name' => 'Old Support'])
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('bot_profiles', ['id' => 'bot_gone']);
        $this->assertDatabaseMissing('chat_conversations', ['id' => 'conv_1']);
    }

    public function test_a_live_bot_cannot_be_purged_without_being_deleted_first(): void
    {
        $this->actingAs($this->superAdmin())
            ->delete(route('admin.bots.purge', 'bot_live'), ['confirm_name' => 'Sales Helper'])
            ->assertNotFound();

        $this->assertNotSoftDeleted('bot_profiles', ['id' => 'bot_live']);
    }

    public function test_a_super_admin_deletes_a_live_bot_from_the_page(): void
    {
        $this->actingAs($this->superAdmin())
            ->delete(route('admin.bots.destroy', 'bot_live'), ['confirm_name' => 'Sales Helper'])
            ->assertSessionHas('success');

        $this->assertSoftDeleted('bot_profiles', ['id' => 'bot_live']);
    }

    public function test_a_workspace_admin_cannot_restore_or_purge(): void
    {
        $admin = $this->workspaceAdmin();

        $this->actingAs($admin)->post(route('admin.bots.restore', 'bot_gone'))->assertForbidden();
        $this->actingAs($admin)
            ->delete(route('admin.bots.purge', 'bot_gone'), ['confirm_name' => 'Old Support'])
            ->assertForbidden();

        $this->assertSoftDeleted('bot_profiles', ['id' => 'bot_gone']);
    }

    public function test_a_deleted_bot_leaves_its_workspace_pages(): void
    {
        $admin = $this->workspaceAdmin();

        $this->actingAs($admin)
            ->withSession(['active_system_id' => 'sys_b'])
            ->get(route('bots.index'))
            ->assertOk()
            ->assertDontSee('Old Support');

        $this->actingAs($admin)->get(route('bots.edit', 'bot_gone'))->assertNotFound();
    }
}
