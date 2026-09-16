<?php

namespace Tests\Feature;

use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmbeddingModelListTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'root@test.com'],
            ['name' => 'Root', 'password' => bcrypt('password'),
             'global_role' => 'super_admin'],
        );
    }

    public function test_a_super_admin_can_fetch_the_model_list(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => true, 'models' => ['nomic-embed-text', 'llama3.2:1b'],
            'message' => '2 models available.',
        ], 200)]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.settings.models'), [
                'base_url' => 'http://localhost:11434/v1',
                'api_key' => '',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'models' => ['nomic-embed-text', 'llama3.2:1b']]);
    }

    public function test_an_unreachable_provider_is_reported_not_thrown(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.settings.models'), [
                'base_url' => 'http://localhost:11434/v1',
                'api_key' => '',
            ])
            ->assertOk()
            ->assertJson(['ok' => false]);
    }

    public function test_a_system_admin_cannot_fetch_the_model_list(): void
    {
        $system = System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach($system->id, ['role' => 'system_admin']);

        $this->actingAs($user)
            ->postJson(route('admin.settings.models'), [
                'base_url' => 'http://localhost:11434/v1',
            ])
            ->assertForbidden();
    }

    public function test_every_model_field_offers_a_search_button(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings', 'models'))
            ->assertOk();

        // Embedding and the five jobs.
        $this->assertSame(6, preg_match_all('/<button[^>]*data-picker-search/', $response->getContent()));
    }
}
