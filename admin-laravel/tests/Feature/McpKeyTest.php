<?php

namespace Tests\Feature;

use App\Models\McpKey;
use App\Models\System;
use App\Models\User;
use App\Services\Schema\ConsoleDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Keys that let other AI tools use a workspace's bots over MCP. */
class McpKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
    }

    private function member(string $role, string $system = 'sys_test'): User
    {
        if ($existing = User::where('email', "{$role}-{$system}@example.test")->first()) {
            return $existing;
        }
        $user = User::create(['name' => ucfirst($role), 'email' => "{$role}-{$system}@example.test",
            'password' => 'password', 'global_role' => 'user']);
        $user->systems()->attach($system, ['role' => $role]);

        return $user;
    }

    public function test_a_key_is_shown_once_and_only_its_hash_is_kept(): void
    {
        $response = $this->actingAs($this->member('system_admin'))
            ->postJson(route('mcp-keys.store'), ['system_id' => 'sys_test', 'name' => 'Copilot'])
            ->assertOk();

        $token = $response->json('token');
        $this->assertMatchesRegularExpression('/^c4mcp_[A-Za-z0-9]{40}$/', $token);

        $key = McpKey::sole();
        $this->assertSame(hash('sha256', $token), $key->token_hash);
        $this->assertSame(substr($token, -4), $key->hint);
        $this->assertStringNotContainsString($token, json_encode($key->toArray()));
    }

    public function test_only_system_admins_manage_keys(): void
    {
        $key = McpKey::create(['id' => 'mk_1', 'system_id' => 'sys_test', 'name' => 'K',
            'token_hash' => str_repeat('a', 64), 'hint' => 'aaaa']);

        $this->actingAs($this->member('editor'))
            ->postJson(route('mcp-keys.store'), ['system_id' => 'sys_test', 'name' => 'X'])->assertForbidden();
        $this->actingAs($this->member('editor'))->deleteJson(route('mcp-keys.destroy', $key->id))->assertForbidden();
        $this->actingAs($this->member('system_admin', 'sys_other'))->deleteJson(route('mcp-keys.destroy', $key->id))->assertForbidden();

        $this->actingAs($this->member('system_admin'))->deleteJson(route('mcp-keys.destroy', $key->id))->assertOk();
        $this->assertSame(0, McpKey::count());
    }

    public function test_the_connectors_page_lists_keys_and_the_address(): void
    {
        McpKey::create(['id' => 'mk_1', 'system_id' => 'sys_test', 'name' => 'Copilot',
            'token_hash' => str_repeat('a', 64), 'hint' => 'wxyz']);

        $this->actingAs($this->member('system_admin'))
            ->withSession(['active_system_id' => 'sys_test'])
            ->get(route('connectors.index'))
            ->assertSee('Copilot')
            ->assertSee('…wxyz')
            ->assertSee('/mcp/');
    }

    public function test_the_console_database_never_reads_mcp_keys(): void
    {
        $this->assertContains('mcp_keys', ConsoleDatabase::NEVER);
    }
}
