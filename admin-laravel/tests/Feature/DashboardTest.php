<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        $user = User::create(['name' => ucfirst($role), 'email' => "{$role}@example.test",
            'password' => 'password', 'global_role' => 'user']);
        $user->systems()->attach('sys_1', ['role' => $role]);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_1', 'name' => 'Shop', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_1', 'name' => 'Desk', 'is_active' => true]);
        BotProfile::create(['id' => 'bot_2', 'system_id' => 'sys_1', 'name' => 'Sales', 'is_active' => false]);
    }

    public function test_it_is_an_overview_without_the_old_shortcuts(): void
    {
        $this->actingAs($this->member('editor'))->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('New bot profile')
            ->assertDontSee('Latest conversations')
            ->assertViewHas('activeBotCount', 1)
            ->assertSee('Workspace');
    }

    public function test_each_bot_has_one_actions_menu_with_embed_settings_and_analytics(): void
    {
        $this->actingAs($this->member('editor'))->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Actions for Desk"', false)
            ->assertSeeInOrder(['#embedModal' . 'bot_1', route('bots.edit', 'bot_1'), route('analytics.index', ['bots' => ['bot_1']])], false);
    }

    public function test_a_viewer_gets_no_settings_in_the_menu(): void
    {
        $this->actingAs($this->member('viewer'))->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('bots.edit', 'bot_1'), false)
            ->assertSee(route('analytics.index', ['bots' => ['bot_1']]), false);
    }

    public function test_it_counts_the_last_seven_days_only(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        ChatConversation::create(['id' => 'c_old', 'bot_id' => 'bot_1', 'session_id' => 's_old', 'origin' => '']);
        ChatMessage::create(['id' => 'm_old', 'conversation_id' => 'c_old', 'sender' => 'user', 'content' => 'Old']);

        Carbon::setTestNow('2026-09-16 10:00:00');
        ChatConversation::create(['id' => 'c_new', 'bot_id' => 'bot_1', 'session_id' => 's_new', 'origin' => '']);
        ChatMessage::create(['id' => 'm_1', 'conversation_id' => 'c_new', 'sender' => 'user', 'content' => 'Hi']);
        ChatMessage::create(['id' => 'm_2', 'conversation_id' => 'c_new', 'sender' => 'assistant', 'content' => 'Hello']);

        Carbon::setTestNow('2026-09-17 12:00:00');

        $this->actingAs($this->member('viewer'))->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('weekConversations', 1)
            ->assertViewHas('weekMessages', 1);

        Carbon::setTestNow();
    }
}
