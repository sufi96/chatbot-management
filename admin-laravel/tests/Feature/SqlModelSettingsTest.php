<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SqlModelSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin',
        ]);
    }

    public function test_the_keys_default_to_blank(): void
    {
        // Blank is the supported default: each bot uses its own model, and the
        // feature works with nothing configured.
        $this->assertSame('', AppSetting::DEFAULTS['sql_model_provider_id']);
        $this->assertSame('', AppSetting::DEFAULTS['sql_model_name']);
    }

    public function test_the_settings_screen_offers_them(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('sql_model_provider_id');
    }

    public function test_they_can_be_saved(): void
    {
        AiProvider::create([
            'id' => 'aip_local', 'system_id' => null, 'name' => 'Local Ollama',
            'base_url' => 'http://localhost:11434/v1', 'api_key' => 'sk-test',
        ]);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), array_merge(
                AppSetting::DEFAULTS,
                [
                    'sql_model_provider_id' => 'aip_local',
                    'sql_model_name' => 'qwen2.5-coder',
                ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('qwen2.5-coder', AppSetting::get('sql_model_name'));
        $this->assertSame('aip_local', AppSetting::get('sql_model_provider_id'));
    }

    public function test_leaving_them_blank_is_accepted(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), array_merge(
                AppSetting::DEFAULTS,
                ['sql_model_provider_id' => '', 'sql_model_name' => '']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}
