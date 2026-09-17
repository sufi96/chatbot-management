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

/**
 * A deleted bot is gone for good as far as its workspace can tell, and still
 * whole for a super admin: its conversations and analytics stay readable
 * until it is restored or erased from the Bots page.
 */
class DeletedBotHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-17 12:00:00');

        System::create(['id' => 'sys_1', 'name' => 'Shop', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_live', 'system_id' => 'sys_1', 'name' => 'Live Desk']);
        BotProfile::create(['id' => 'bot_gone', 'system_id' => 'sys_1', 'name' => 'Old Desk']);

        // A minute ago, inside every window that ends now.
        Carbon::setTestNow('2026-09-17 11:59:00');
        ChatConversation::create(['id' => 'conv_gone', 'bot_id' => 'bot_gone', 'session_id' => 's1', 'origin' => '']);
        ChatMessage::create(['id' => 'msg_1', 'conversation_id' => 'conv_gone', 'sender' => 'user', 'content' => 'Where is my parcel?']);
        ChatMessage::create(['id' => 'msg_2', 'conversation_id' => 'conv_gone', 'sender' => 'assistant', 'content' => 'On its way.']);

        Carbon::setTestNow('2026-09-17 12:00:00');
        BotProfile::find('bot_gone')->delete();

        $this->admin = User::create(['name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin']);
        $this->member = User::create(['name' => 'Owner', 'email' => 'owner@example.test',
            'password' => 'password', 'global_role' => 'user']);
        $this->member->systems()->attach('sys_1', ['role' => 'system_admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_super_admin_still_finds_the_deleted_bot_in_analytics(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('analytics.index', ['bots' => ['bot_gone'], 'tz' => 'UTC']))
            ->assertOk()
            ->assertSee('Deleted');

        $this->assertSame(['bot_gone'], $response->viewData('bots')->pluck('id')->all());
        $this->assertSame(1, $response->viewData('report')['kpis']['visitor_messages']);
    }

    public function test_a_workspace_member_does_not(): void
    {
        $response = $this->actingAs($this->member)
            ->get(route('analytics.index', ['bots' => ['bot_gone'], 'tz' => 'UTC']))
            ->assertOk()
            ->assertDontSee('Old Desk');

        $this->assertSame(['bot_live'], $response->viewData('bots')->pluck('id')->all());
        $this->assertSame(0, $response->viewData('report')['kpis']['visitor_messages']);
    }

    public function test_a_super_admin_can_list_and_read_the_deleted_bots_conversations(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('logs.index', ['bots' => ['bot_gone']]))
            ->assertOk()
            ->assertSee('Old Desk');
        $this->assertSame(['conv_gone'], $response->viewData('conversations')->pluck('id')->all());

        $this->actingAs($this->admin)->getJson(route('logs.transcript', 'conv_gone'))
            ->assertOk()
            ->assertJsonPath('bot_name', 'Old Desk')
            ->assertJsonPath('bot_deleted', true);
    }

    public function test_a_workspace_member_can_neither_list_nor_open_them(): void
    {
        $response = $this->actingAs($this->member)->get(route('logs.index'))->assertOk();
        $this->assertSame([], $response->viewData('conversations')->pluck('id')->all());

        $this->actingAs($this->member)->getJson(route('logs.transcript', 'conv_gone'))->assertForbidden();
    }

    public function test_the_dashboard_no_longer_counts_them_for_the_workspace(): void
    {
        $this->actingAs($this->member)->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('weekConversations', 0)
            ->assertViewHas('weekMessages', 0)
            ->assertDontSee('Old Desk');
    }

    public function test_the_admin_bots_page_links_each_bot_to_its_analytics_and_conversations(): void
    {
        $this->actingAs($this->admin)->get(route('admin.bots.index'))
            ->assertOk()
            ->assertSee(route('analytics.index', ['bots' => ['bot_gone']]), false)
            ->assertSee(route('logs.index', ['bots' => ['bot_gone']]), false);
    }

    public function test_a_workspace_admin_is_told_deleting_is_permanent(): void
    {
        $this->actingAs($this->member)->get(route('bots.edit', 'bot_live'))
            ->assertOk()
            ->assertSee('is deleted permanently')
            ->assertDontSee('restore it from Bots');

        $this->actingAs($this->member)
            ->delete(route('bots.destroy', 'bot_live'), ['confirm_name' => 'Live Desk'])
            ->assertSessionHas('success', 'Live Desk was deleted permanently.');

        // Only marked, all the same.
        $this->assertNotNull(BotProfile::withTrashed()->find('bot_live'));
    }

    public function test_a_super_admin_is_told_it_can_be_restored(): void
    {
        $this->actingAs($this->admin)->get(route('bots.edit', 'bot_live'))
            ->assertOk()
            ->assertSee('restore it from Bots under Admin settings');
    }
}
