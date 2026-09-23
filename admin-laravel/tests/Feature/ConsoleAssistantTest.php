<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The console's own assistant: made by a migration, owned by no workspace,
 * shown only to super admins, and never deletable.
 */
class ConsoleAssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_1', 'name' => 'Shop', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_shop', 'system_id' => 'sys_1', 'name' => 'Shop Desk']);
        AiProvider::create(['id' => 'aip_platform', 'system_id' => null, 'name' => 'Platform Endpoint',
            'base_url' => 'http://spark:8000/v1', 'api_key' => '']);

        $this->admin = User::create(['name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin']);
        $this->member = User::create(['name' => 'Owner', 'email' => 'owner@example.test',
            'password' => 'password', 'global_role' => 'user']);
        $this->member->systems()->attach('sys_1', ['role' => 'system_admin']);
    }

    private function switchOn(): void
    {
        BotProfile::console()->forceFill(['provider_id' => 'aip_platform', 'model_name' => 'qwen3.5', 'is_active' => true])->save();
    }

    public function test_it_exists_from_the_start_with_no_workspace(): void
    {
        $bot = BotProfile::console();

        $this->assertNotNull($bot);
        $this->assertTrue($bot->is_platform);
        $this->assertNull($bot->system_id);
        $this->assertFalse($bot->is_active);
    }

    public function test_it_is_pinned_apart_on_the_admin_bots_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.bots.index'))
            ->assertOk()
            ->assertSee('bot-card-console', false)
            ->assertSee('Built in')
            ->assertSee('Needs a provider');

        $this->assertNotContains(BotProfile::CONSOLE_ID, $response->viewData('bots')->pluck('id')->all());
    }

    public function test_a_super_admin_edits_it_like_any_bot(): void
    {
        $this->actingAs($this->admin)->get(route('bots.edit', BotProfile::CONSOLE_ID))
            ->assertOk()
            ->assertSee('The console assistant')
            ->assertDontSee('Delete bot profile');
        $this->actingAs($this->admin)->get(route('bots.brain', BotProfile::CONSOLE_ID))->assertOk();

        $this->actingAs($this->admin)->put(route('bots.update', BotProfile::CONSOLE_ID), [
            'name' => 'Console Assistant', 'widget_title' => 'Help',
            'widget_primary_color' => '#1d5f92', 'widget_position' => 'bottom-left', 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $bot = BotProfile::console();
        $this->assertSame(['Help', 'bottom-left', true, true],
            [$bot->widget_title, $bot->widget_position, $bot->is_active, $bot->is_platform]);
    }

    public function test_nobody_else_can_open_it(): void
    {
        $this->actingAs($this->member)->get(route('bots.edit', BotProfile::CONSOLE_ID))->assertForbidden();
        $this->actingAs($this->member)->get(route('bots.brain', BotProfile::CONSOLE_ID))->assertForbidden();
    }

    public function test_it_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('bots.destroy', BotProfile::CONSOLE_ID), ['confirm_name' => 'Console Assistant'])
            ->assertForbidden();
        $this->actingAs($this->admin)
            ->delete(route('admin.bots.destroy', BotProfile::CONSOLE_ID), ['confirm_name' => 'Console Assistant'])
            ->assertForbidden();

        $this->assertFalse(BotProfile::withTrashed()->find(BotProfile::CONSOLE_ID)->trashed());
    }

    public function test_its_widget_is_on_console_pages_for_super_admins_only_once_switched_on(): void
    {
        $widget = 'data-bot-id="' . BotProfile::CONSOLE_ID . '"';

        $this->actingAs($this->admin)->get(route('dashboard'))->assertDontSee($widget, false);

        $this->switchOn();

        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee($widget, false);
        $this->actingAs($this->member)->get(route('dashboard'))->assertDontSee($widget, false);
        // A bot's own settings page carries that bot's widget instead.
        $this->actingAs($this->admin)->get(route('bots.edit', 'bot_shop'))->assertDontSee($widget, false);
    }

    public function test_it_stays_out_of_workspace_pages(): void
    {
        $this->actingAs($this->admin)->get(route('bots.index'))->assertDontSee('Console Assistant');
        $response = $this->actingAs($this->admin)->get(route('analytics.index'))->assertOk();
        $this->assertNotContains(BotProfile::CONSOLE_ID, $response->viewData('bots')->pluck('id')->all());
    }

    /** A conversation of one visitor message for a bot, a minute ago. */
    private function chat(string $id, string $botId): void
    {
        \Illuminate\Support\Carbon::setTestNow(now()->subMinute());
        \App\Models\ChatConversation::create(['id' => $id, 'bot_id' => $botId, 'session_id' => "s_{$id}", 'origin' => '']);
        \App\Models\ChatMessage::create(['id' => "m_{$id}", 'conversation_id' => $id, 'sender' => 'user', 'content' => 'Hello']);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_all_bot_profiles_leaves_it_out_unless_ticked(): void
    {
        $this->chat('c_shop', 'bot_shop');
        $this->chat('c_console', BotProfile::CONSOLE_ID);

        $ids = fn (array $query) => $this->actingAs($this->admin)
            ->get(route('analytics.index', $query + ['tz' => 'UTC']))->assertOk()
            ->viewData('bots')->pluck('id')->sort()->values()->all();

        $this->assertSame(['bot_shop'], $ids([]));
        $this->assertSame([BotProfile::CONSOLE_ID, 'bot_shop'], $ids(['console' => '1']));
        $this->assertSame([BotProfile::CONSOLE_ID], $ids(['console' => 'only']));

        $only = $this->actingAs($this->admin)->get(route('analytics.index', ['console' => 'only', 'tz' => 'UTC']));
        $this->assertSame(1, $only->viewData('report')['kpis']['visitor_messages']);
        $this->assertSame(['c_console'], $only->viewData('conversations')->pluck('id')->all());
    }

    public function test_its_conversations_are_listed_and_opened_by_a_super_admin(): void
    {
        $this->chat('c_shop', 'bot_shop');
        $this->chat('c_console', BotProfile::CONSOLE_ID);

        $all = $this->actingAs($this->admin)->get(route('logs.index'))->assertOk();
        $this->assertSame(['c_shop'], $all->viewData('conversations')->pluck('id')->all());

        $only = $this->actingAs($this->admin)->get(route('logs.index', ['console' => 'only']))->assertOk()
            ->assertSee('Built in');
        $this->assertSame(['c_console'], $only->viewData('conversations')->pluck('id')->all());

        $this->actingAs($this->admin)->getJson(route('logs.transcript', 'c_console'))->assertOk();
    }

    public function test_nobody_else_can_pick_it_or_read_it(): void
    {
        $this->chat('c_console', BotProfile::CONSOLE_ID);

        $response = $this->actingAs($this->member)->get(route('logs.index', ['console' => 'only']))->assertOk()
            ->assertDontSee('Console Assistant');
        // Ignored for them, so the page shows their own bots as usual.
        $this->assertNotContains('c_console', $response->viewData('conversations')->pluck('id')->all());
        $this->actingAs($this->member)->getJson(route('logs.transcript', 'c_console'))->assertForbidden();
    }

    public function test_its_card_links_to_its_analytics_and_conversations(): void
    {
        $this->actingAs($this->admin)->get(route('admin.bots.index'))
            ->assertSee(route('analytics.index', ['console' => 'only']), false)
            ->assertSee(route('logs.index', ['console' => 'only']), false);
    }
}
