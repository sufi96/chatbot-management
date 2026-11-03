<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatToolCall;
use App\Models\Connector;
use App\Models\ConnectorTool;
use App\Models\System;
use App\Models\User;
use App\Services\Analytics;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A bot's Agentic section: whether it may call connector tools, and which. */
class AgenticSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper']);
    }

    public function test_a_new_bot_is_not_agentic(): void
    {
        $bot = BotProfile::find('bot_1');

        $this->assertFalse($bot->agentic_enabled);
        $this->assertSame(3, $bot->agentic_max_calls);
        $this->assertCount(0, $bot->tools);
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

    private function tool(string $id, string $name, string $system = 'sys_test', bool $enabled = true): ConnectorTool
    {
        $connectorId = "con_{$system}_{$id}";
        Connector::firstOrCreate(['id' => $connectorId], ['system_id' => $system, 'name' => "API {$id}",
            'base_url' => 'https://a.test']);

        return ConnectorTool::create(['id' => $id, 'connector_id' => $connectorId, 'name' => $name,
            'method' => 'get', 'path' => '/x', 'writes' => false, 'enabled' => $enabled]);
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

    private function save(array $overrides)
    {
        return $this->actingAs($this->member('editor'))
            ->put(route('bots.brain.update', 'bot_1'), $this->brainPayload($overrides + ['agentic_present' => 1]));
    }

    public function test_the_switch_limit_and_tools_are_saved(): void
    {
        $this->tool('ctl_a', 'getA');

        $this->save(['agentic_enabled' => 1, 'agentic_max_calls' => 2, 'tools' => ['ctl_a']])
            ->assertSessionHasNoErrors();

        $bot = BotProfile::find('bot_1');
        $this->assertTrue($bot->agentic_enabled);
        $this->assertSame(2, $bot->agentic_max_calls);
        $this->assertSame(['ctl_a'], $bot->tools->pluck('id')->all());
    }

    public function test_an_older_form_leaves_the_agentic_settings_alone(): void
    {
        $this->tool('ctl_a', 'getA');
        BotProfile::find('bot_1')->update(['agentic_enabled' => true]);
        BotProfile::find('bot_1')->tools()->sync(['ctl_a']);

        $this->actingAs($this->member('editor'))
            ->put(route('bots.brain.update', 'bot_1'), $this->brainPayload())
            ->assertSessionHasNoErrors();

        $this->assertTrue(BotProfile::find('bot_1')->agentic_enabled);
        $this->assertCount(1, BotProfile::find('bot_1')->tools);
    }

    public function test_another_workspaces_tool_is_refused(): void
    {
        $this->tool('ctl_x', 'getX', 'sys_other');

        $this->save(['agentic_enabled' => 1, 'tools' => ['ctl_x']])->assertSessionHasErrors('tools');
        $this->assertCount(0, BotProfile::find('bot_1')->tools);
    }

    public function test_a_switched_off_tool_is_refused(): void
    {
        $this->tool('ctl_off', 'getOff', enabled: false);

        $this->save(['agentic_enabled' => 1, 'tools' => ['ctl_off']])->assertSessionHasErrors('tools');
    }

    public function test_two_tools_with_one_name_are_refused(): void
    {
        $this->tool('ctl_1', 'getStatus');
        $this->tool('ctl_2', 'getStatus');

        $this->save(['agentic_enabled' => 1, 'tools' => ['ctl_1', 'ctl_2']])
            ->assertSessionHasErrors(['tools' => 'Two tools are both called getStatus. Enable only one.']);
    }

    public function test_the_limit_stays_between_one_and_five(): void
    {
        $this->save(['agentic_max_calls' => 9])->assertSessionHasErrors('agentic_max_calls');
    }

    public function test_the_behaviour_page_offers_the_agentic_card(): void
    {
        $this->tool('ctl_a', 'getApplication');

        $this->actingAs($this->member('editor'))
            ->get(route('bots.brain', 'bot_1'))
            ->assertOk()
            ->assertSee('data-card-collapse="agentic"', false)
            ->assertSee('getApplication');
    }

    public function test_a_connector_whose_tool_a_bot_uses_cannot_be_deleted(): void
    {
        $this->tool('ctl_a', 'getA');
        BotProfile::find('bot_1')->tools()->sync(['ctl_a']);
        $admin = User::create(['name' => 'A', 'email' => 'a@example.test', 'password' => 'password', 'global_role' => 'user']);
        $admin->systems()->attach('sys_test', ['role' => 'system_admin']);

        $this->actingAs($admin)
            ->deleteJson(route('connectors.destroy', 'con_sys_test_ctl_a'))
            ->assertStatus(409)
            ->assertJsonFragment(['success' => false])
            ->assertSee('Helper');
    }

    public function test_the_transcript_lists_each_tool_call(): void
    {
        $this->tool('ctl_a', 'getA');
        ChatConversation::create(['id' => 'conv_1', 'bot_id' => 'bot_1', 'session_id' => 's1']);
        ChatMessage::create(['id' => 'msg_1', 'conversation_id' => 'conv_1', 'sender' => 'assistant',
            'content' => 'It is open.', 'source_kind' => 'tools']);
        ChatToolCall::create(['id' => 'tc_1', 'conversation_id' => 'conv_1', 'message_id' => 'msg_1',
            'connector_tool_id' => 'ctl_a', 'tool_name' => 'getA', 'arguments' => ['id' => '7'],
            'status' => 'ran', 'result_excerpt' => 'open', 'duration_ms' => 40]);

        $this->actingAs($this->member('viewer'))
            ->getJson(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertJsonPath('messages.0.tool_calls.0.tool_name', 'getA')
            ->assertJsonPath('messages.0.tool_calls.0.status', 'ran')
            ->assertJsonPath('messages.0.tool_calls.0.arguments.id', '7');
    }

    public function test_analytics_counts_answers_from_connected_systems(): void
    {
        $this->assertArrayHasKey('tools', Analytics::SOURCE_KINDS);
    }
}
