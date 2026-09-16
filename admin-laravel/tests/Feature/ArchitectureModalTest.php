<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AppSetting;
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

    public function test_other_pages_do_not_carry_the_modal(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings', 'models'))
            ->assertDontSee('id="architectureModal"', false);
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
