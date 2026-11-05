<?php

namespace Tests\Feature;

use App\Assistant\ConsoleToken;
use App\Models\AssistantAction;
use App\Models\BotProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\AssistantFixtures;
use Tests\TestCase;

/** The engine reaching the admin assistant's tools, as the person signed in. */
class AssistantEndpointTest extends TestCase
{
    use AssistantFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorkspaces();
    }

    public function test_a_good_token_lists_the_tools_for_that_person(): void
    {
        $response = $this->tools($this->person('system_admin'))->assertOk();

        $this->assertSame('system_admin', $response->json('user.role'));
        $this->assertFalse($response->json('user.super_admin'));
        $names = collect($response->json('tools'))->pluck('name');
        $this->assertContains('list_bots', $names);
        $this->assertContains('set_bot_active', $names);
        $tool = collect($response->json('tools'))->firstWhere('name', 'set_bot_active');
        $this->assertTrue($tool['writes']);
        $this->assertSame('object', $tool['parameters']['type']);
    }

    public function test_a_bad_or_expired_token_is_refused(): void
    {
        $headers = ['X-Portal-Token' => 'test-portal-token'];
        $this->postJson('/internal/assistant/tools', ['token' => 'nonsense'], $headers)
            ->assertStatus(401)->assertJson(['ok' => false]);

        $user = $this->person('system_admin');
        $expired = Crypt::encryptString(json_encode(['u' => $user->id, 'exp' => now()->subMinute()->timestamp]));
        $this->postJson('/internal/assistant/tools', ['token' => $expired], $headers)->assertStatus(401);
        $this->assertNull(ConsoleToken::resolve($expired));
        $this->assertSame($user->id, ConsoleToken::resolve(ConsoleToken::issue($user))->id);
    }

    public function test_only_the_engine_may_call(): void
    {
        $token = ConsoleToken::issue($this->person('system_admin'));

        $this->postJson('/internal/assistant/tools', ['token' => $token])->assertStatus(401);
        $this->postJson('/internal/assistant/tools', ['token' => $token], ['X-Portal-Token' => 'wrong'])->assertStatus(401);
    }

    public function test_a_change_is_recorded_with_who_made_it(): void
    {
        $admin = $this->person('system_admin');

        $this->ran($admin, 'set_bot_active', ['bot' => 'bot_a', 'active' => false]);

        $this->assertFalse(BotProfile::find('bot_a')->is_active);
        $action = AssistantAction::sole();
        $this->assertSame([$admin->id, 'set_bot_active', true], [$action->user_id, $action->tool, $action->ok]);
        $this->assertSame(['bot' => 'bot_a', 'active' => false], $action->arguments);
    }

    public function test_a_refused_change_is_recorded_too(): void
    {
        $mixed = $this->person('editor', 'sys_b');
        $mixed->systems()->attach('sys_a', ['role' => 'viewer']);
        $this->refused($mixed, 'set_bot_active', ['bot' => 'bot_a', 'active' => false]);

        $this->assertTrue(BotProfile::find('bot_a')->is_active);
        $this->assertFalse(AssistantAction::sole()->ok);
    }

    public function test_a_read_is_not_recorded_and_an_unknown_tool_is_refused(): void
    {
        $admin = $this->person('system_admin');

        $this->ran($admin, 'list_bots');
        $this->assertStringContainsString('No such tool', $this->refused($admin, 'drop_everything'));
        $this->assertSame(0, AssistantAction::count());
    }

    public function test_a_super_admin_only_tool_is_hidden_from_others(): void
    {
        $names = collect($this->tools($this->person('system_admin'))->json('tools'))->pluck('name');
        $superNames = collect($this->tools($this->person('super_admin'))->json('tools'))->pluck('name');

        $this->assertNotContains('list_users', $names);
        $this->assertContains('list_users', $superNames);
        $this->assertStringContainsString('No such tool', $this->refused($this->person('system_admin', 'sys_b'), 'list_users'));
    }
}
