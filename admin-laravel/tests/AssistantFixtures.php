<?php

namespace Tests;

use App\Assistant\ConsoleToken;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Two workspaces, a bot in each, and people with each role, for the admin
 * assistant's tests. Calls go through the engine's internal routes, as the
 * engine makes them.
 */
trait AssistantFixtures
{
    protected function seedWorkspaces(): void
    {
        config(['app.portal_internal_token' => 'test-portal-token']);

        System::create(['id' => 'sys_a', 'name' => 'Alpha', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_b', 'name' => 'Bravo', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_a', 'system_id' => 'sys_a', 'name' => 'HR Helper', 'model_name' => 'qwen3:8b']);
        BotProfile::create(['id' => 'bot_b', 'system_id' => 'sys_b', 'name' => 'Sales Desk', 'model_name' => 'qwen3:8b']);
    }

    protected function person(string $role, string $system = 'sys_a'): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' ' . $system, 'email' => "{$role}.{$system}@example.test",
            'password' => 'password', 'global_role' => $role === 'super_admin' ? 'super_admin' : 'user',
        ]);
        if ($role !== 'super_admin') {
            $user->systems()->attach($system, ['role' => $role]);
        }

        return $user;
    }

    protected function tools(User $user): TestResponse
    {
        return $this->postJson('/internal/assistant/tools', ['token' => ConsoleToken::issue($user)],
            ['X-Portal-Token' => 'test-portal-token']);
    }

    protected function callTool(User $user, string $name, array $arguments = []): TestResponse
    {
        return $this->postJson('/internal/assistant/call',
            ['token' => ConsoleToken::issue($user), 'name' => $name, 'arguments' => $arguments],
            ['X-Portal-Token' => 'test-portal-token']);
    }

    /** The tool's text, asserting it ran. */
    protected function ran(User $user, string $name, array $arguments = []): string
    {
        $response = $this->callTool($user, $name, $arguments)->assertOk();
        $this->assertTrue($response->json('ok'), "{$name} failed: " . $response->json('error'));

        return (string) $response->json('text');
    }

    /** The refusal's text, asserting it was refused. */
    protected function refused(User $user, string $name, array $arguments = []): string
    {
        $response = $this->callTool($user, $name, $arguments)->assertOk();
        $this->assertFalse($response->json('ok'), "{$name} should have been refused: " . $response->json('text'));

        return (string) $response->json('error');
    }
}
