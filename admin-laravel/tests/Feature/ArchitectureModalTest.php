<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchitectureModalTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'root@test.com'],
            ['name' => 'Root', 'password' => bcrypt('password'), 'global_role' => 'super_admin'],
        );
    }

    public function test_maintenance_offers_the_architecture(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings', 'maintenance'))
            ->assertOk()
            ->assertSee('View architecture')
            ->assertSee('data-bs-target="#architectureModal"', false)
            ->assertSee('id="architectureModal"', false);
    }

    public function test_every_page_offers_it_from_the_top_bar(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="How the system works"', false)
            ->assertSee('id="architectureModal"', false);
    }

    public function test_a_standard_user_sees_the_plan_but_not_the_live_settings(): void
    {
        System::create(['id' => 'sys_1', 'name' => 'Shop', 'allowed_origins' => '*']);
        $user = User::create(['name' => 'Viewer', 'email' => 'viewer@test.com',
            'password' => 'password', 'global_role' => 'user']);
        $user->systems()->attach('sys_1', ['role' => 'viewer']);

        AiProvider::create([
            'id' => 'aip_spark', 'system_id' => null, 'name' => 'Spark B',
            'base_url' => 'http://spark-b:8000/v1', 'api_key' => '',
        ]);
        AppSetting::put('sql_model_provider_id', 'aip_spark');
        AppSetting::put('sql_model_name', 'qwen3-coder:30b');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="architectureModal"', false)
            ->assertSee('Qwen3-Coder-30B-A3B')
            ->assertDontSee('Now, from settings')
            ->assertDontSee('qwen3-coder:30b')
            ->assertDontSee(route('admin.settings', 'models'), false);
    }

    public function test_the_modal_is_on_the_page_once(): void
    {
        $html = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings', 'maintenance'))
            ->getContent();

        $this->assertSame(1, substr_count($html, 'id="architectureModal"'));
    }

    public function test_the_models_tab_reads_the_live_settings(): void
    {
        AiProvider::create([
            'id' => 'aip_spark', 'system_id' => null, 'name' => 'Spark B',
            'base_url' => 'http://spark-b:8000/v1', 'api_key' => '',
        ]);
        AppSetting::put('sql_model_provider_id', 'aip_spark');
        AppSetting::put('sql_model_name', 'qwen3-coder:30b');

        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings', 'maintenance'))
            ->assertSee('Spark B')
            ->assertSee('qwen3-coder:30b')
            // A job left blank reports its fallback.
            ->assertSee("Default: Bot's main model")
            ->assertSee('Default: Off: no reranking');
    }
}
