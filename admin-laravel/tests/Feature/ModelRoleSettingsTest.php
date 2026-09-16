<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminSettingsController;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRoleSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const SUFFIXES = ['base_url', 'api_key', 'name'];

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin',
        ]);
    }

    public function test_the_roles_match_the_engine(): void
    {
        // api-engine/roles.py lists the same five. A role the portal does not
        // offer can never be configured; one the engine does not know is
        // silently ignored.
        $this->assertSame(['intent', 'sql', 'rerank', 'guard', 'vision'],
            array_keys(AdminSettingsController::MODEL_ROLES));
    }

    public function test_every_role_defaults_to_blank(): void
    {
        // Blank is the supported default: nothing changes until an install
        // points a job somewhere.
        foreach (array_keys(AdminSettingsController::MODEL_ROLES) as $role) {
            foreach (self::SUFFIXES as $suffix) {
                $this->assertSame('', AppSetting::DEFAULTS["{$role}_model_{$suffix}"], "{$role}_model_{$suffix}");
            }
        }
    }

    public function test_the_settings_screen_offers_every_role(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Models');

        foreach (array_keys(AdminSettingsController::MODEL_ROLES) as $role) {
            $response->assertSee("{$role}_model_base_url");
            $response->assertSee("{$role}_model_name");
        }
    }

    public function test_a_role_can_be_saved(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), array_merge(
                AppSetting::DEFAULTS,
                [
                    'rerank_model_base_url' => 'http://localhost:8012/v1',
                    'rerank_model_name' => 'bge-reranker-v2-m3',
                ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('http://localhost:8012/v1', AppSetting::get('rerank_model_base_url'));
        $this->assertSame('bge-reranker-v2-m3', AppSetting::get('rerank_model_name'));
    }

    public function test_leaving_every_role_blank_is_accepted(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), AppSetting::DEFAULTS)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    public function test_an_overlong_model_name_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), array_merge(
                AppSetting::DEFAULTS, ['guard_model_name' => str_repeat('x', 256)]))
            ->assertSessionHasErrors('guard_model_name');
    }
}
