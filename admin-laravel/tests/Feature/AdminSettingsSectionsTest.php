<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminSettingsController;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSettingsSectionsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'root@test.com'],
            ['name' => 'Root', 'password' => bcrypt('password'), 'global_role' => 'super_admin'],
        );
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'section' => 'chunking',
            'embedding_provider_id' => '',
            'embedding_model' => 'nomic-embed-text',
            'embedding_dimensions' => 768,
            'chunk_size' => 1800,
            'chunk_overlap' => 200,
            'context_char_budget' => 6000,
            'web_search_provider' => 'duckduckgo',
        ], $overrides);
    }

    private function spark(): AiProvider
    {
        return AiProvider::create([
            'id' => 'aip_spark', 'system_id' => null, 'name' => 'Spark',
            'base_url' => 'http://spark:8000/v1', 'api_key' => 'sk-spark',
        ]);
    }

    public function test_every_section_has_a_page(): void
    {
        $admin = $this->superAdmin();

        foreach (AdminSettingsController::SECTIONS as $key => $meta) {
            $this->actingAs($admin)
                ->get(route('admin.settings', $key))
                ->assertOk()
                ->assertSee('data-section-current="' . $key . '"', false);
        }
    }

    public function test_the_bare_url_opens_providers(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin/settings')
            ->assertOk()
            ->assertSee('data-section-current="providers"', false);
    }

    public function test_an_unknown_section_is_not_found(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/admin/settings/nope')
            ->assertNotFound();
    }

    public function test_the_sidebar_groups_the_sections_under_admin_settings(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings', 'models'))
            ->assertSee('<div class="sidebar-group">Admin Settings</div>', false);

        foreach (AdminSettingsController::SECTIONS as $key => $meta) {
            $response->assertSee(route('admin.settings', $key), false);
            $response->assertSee($meta['label']);
        }
    }

    public function test_a_workspace_admin_sees_no_admin_settings_group(): void
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'system_admin']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertDontSee('Admin Settings');
    }

    public function test_saving_returns_to_the_section_it_was_pressed_on(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload(['section' => 'web-search']))
            ->assertRedirect(route('admin.settings', 'web-search'))
            ->assertSessionHasNoErrors();
    }

    public function test_an_error_elsewhere_redirects_to_its_section_and_marks_it(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->from(route('admin.settings', 'chunking'))
            ->put(route('admin.settings.update'), $this->payload(['embedding_model' => '']))
            ->assertRedirect(route('admin.settings', 'models'))
            ->assertSessionHasErrors('embedding_model');

        $this->actingAs($admin)
            ->followingRedirects()
            ->put(route('admin.settings.update'), $this->payload(['embedding_model' => '']))
            ->assertSee('data-section-current="models"', false)
            ->assertSee('sidebar-error-dot', false)
            ->assertSee('Embedding needs a model.');
    }

    public function test_a_role_is_linked_to_a_provider_and_a_model(): void
    {
        $this->spark();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'intent_model_provider_id' => 'aip_spark',
                'intent_model_name' => 'qwen3.5:4b',
                'embedding_provider_id' => 'aip_spark',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('aip_spark', AppSetting::get('intent_model_provider_id'));
        $this->assertSame('qwen3.5:4b', AppSetting::get('intent_model_name'));
        $this->assertSame('aip_spark', AppSetting::get('embedding_provider_id'));
    }

    public function test_a_provider_without_a_model_is_refused(): void
    {
        $this->spark();

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'guard_model_provider_id' => 'aip_spark',
                'guard_model_name' => '',
            ]))
            ->assertSessionHasErrors(['guard_model_name' => 'Guard needs a model as well as a provider.']);
    }

    public function test_a_model_without_a_provider_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'vision_model_name' => 'qwen3-vl:8b',
            ]))
            ->assertSessionHasErrors('vision_model_provider_id');
    }

    public function test_a_workspace_provider_cannot_be_linked(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'O', 'allowed_origins' => '*']);
        AiProvider::create([
            'id' => 'aip_ws', 'system_id' => 'sys_other', 'name' => 'Theirs',
            'base_url' => 'http://theirs/v1', 'api_key' => 'their-key',
        ]);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'sql_model_provider_id' => 'aip_ws', 'sql_model_name' => 'x',
            ]))
            ->assertSessionHasErrors('sql_model_provider_id');
    }

    public function test_models_are_listed_through_a_saved_provider(): void
    {
        $this->spark();
        Http::fake(['*' => Http::response(['ok' => true, 'models' => ['qwen3.5:4b']], 200)]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.settings.models'), ['provider_id' => 'aip_spark'])
            ->assertOk()
            ->assertJson(['ok' => true, 'models' => ['qwen3.5:4b']]);

        Http::assertSent(fn ($request) => $request['base_url'] === 'http://spark:8000/v1'
            && $request['api_key'] === 'sk-spark');
    }

    public function test_listing_models_needs_a_provider_or_a_url(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.settings.models'), [])
            ->assertUnprocessable();
    }

    public function test_the_embedding_test_uses_the_linked_provider(): void
    {
        $this->spark();
        Http::fake(['*' => Http::response(['ok' => true, 'dimensions' => 1024], 200)]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.settings.test'), [
                'provider_id' => 'aip_spark', 'embedding_model' => 'bge-m3',
            ])
            ->assertOk()
            ->assertJson(['dimensions' => 1024]);

        Http::assertSent(fn ($request) => $request['base_url'] === 'http://spark:8000/v1');
    }

    public function test_the_embedding_test_without_a_provider_uses_local_ollama(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'dimensions' => 768], 200)]);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.settings.test'), ['embedding_model' => 'nomic-embed-text'])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['base_url'] === 'http://localhost:11434/v1');
    }
}
