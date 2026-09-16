<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProviderControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'root@test.com'],
            ['name' => 'Root', 'password' => bcrypt('password'), 'global_role' => 'super_admin'],
        );
    }

    private function editor(): User
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'system_admin']);

        return $user;
    }

    private function platformProvider(string $id = 'aip_spark'): AiProvider
    {
        return AiProvider::create([
            'id' => $id, 'system_id' => null, 'name' => 'Spark',
            'base_url' => 'http://spark:8000/v1', 'api_key' => 'sk',
        ]);
    }

    public function test_a_super_admin_creates_a_platform_provider(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.providers.store'), [
                'name' => 'DGX Spark A', 'base_url' => 'http://spark-a:8000/v1',
            ])
            ->assertOk()
            ->assertJsonPath('provider.name', 'DGX Spark A')
            ->assertJsonPath('provider.api_key', '')
            ->assertJsonPath('provider.used_by', []);

        $this->assertDatabaseHas('ai_providers', [
            'name' => 'DGX Spark A', 'system_id' => null,
        ]);
    }

    public function test_a_blank_base_url_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.providers.store'), ['name' => 'X', 'base_url' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('base_url');
    }

    public function test_a_workspace_admin_cannot_manage_platform_providers(): void
    {
        $provider = $this->platformProvider();
        $user = $this->editor();

        $this->actingAs($user)
            ->postJson(route('admin.providers.store'), ['name' => 'X', 'base_url' => 'http://x/v1'])
            ->assertForbidden();
        $this->actingAs($user)
            ->deleteJson(route('admin.providers.destroy', $provider->id))
            ->assertForbidden();
    }

    public function test_an_update_reports_the_jobs_on_the_provider(): void
    {
        $this->platformProvider();
        AppSetting::put('sql_model_provider_id', 'aip_spark');

        $this->actingAs($this->superAdmin())
            ->putJson(route('admin.providers.update', 'aip_spark'), [
                'name' => 'Spark B', 'base_url' => 'http://spark-b:8000/v1', 'api_key' => '',
            ])
            ->assertOk()
            ->assertJsonPath('provider.base_url', 'http://spark-b:8000/v1')
            ->assertJsonPath('provider.used_by', ['SQL']);
    }

    public function test_a_provider_still_linked_is_not_deleted(): void
    {
        $this->platformProvider();
        AppSetting::put('embedding_provider_id', 'aip_spark');
        AppSetting::put('sql_model_provider_id', 'aip_spark');

        $this->actingAs($this->superAdmin())
            ->deleteJson(route('admin.providers.destroy', 'aip_spark'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Still used by Embedding and SQL. Point them at another provider and save first.');

        $this->assertDatabaseHas('ai_providers', ['id' => 'aip_spark']);
    }

    public function test_an_unused_provider_is_deleted(): void
    {
        $this->platformProvider();

        $this->actingAs($this->superAdmin())
            ->deleteJson(route('admin.providers.destroy', 'aip_spark'))
            ->assertOk();

        $this->assertDatabaseMissing('ai_providers', ['id' => 'aip_spark']);
    }

    public function test_a_workspace_provider_is_not_reachable_from_here(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'O', 'allowed_origins' => '*']);
        AiProvider::create([
            'id' => 'aip_ws', 'system_id' => 'sys_other', 'name' => 'Theirs',
            'base_url' => 'http://theirs/v1', 'api_key' => 'their-key',
        ]);

        $this->actingAs($this->superAdmin())
            ->deleteJson(route('admin.providers.destroy', 'aip_ws'))
            ->assertNotFound();
    }

    public function test_the_workspace_controller_cannot_reach_a_platform_provider(): void
    {
        $this->platformProvider();

        $this->actingAs($this->superAdmin())
            ->putJson(route('providers.update', 'aip_spark'), [
                'name' => 'Hijack', 'base_url' => 'http://evil/v1',
            ])
            ->assertNotFound();
    }
}
