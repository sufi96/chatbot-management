<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminSettingsController;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuardRulesSettingsTest extends TestCase
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
            'section' => 'guard',
            'embedding_model' => 'nomic-embed-text',
            'embedding_dimensions' => 768,
            'chunk_size' => 1800,
            'chunk_overlap' => 200,
            'context_char_budget' => 6000,
            'web_search_provider' => 'duckduckgo',
            'guard_categories_present' => '1',
        ], $overrides);
    }

    public function test_the_categories_match_the_engine(): void
    {
        // api-engine/guard.py CATEGORIES holds the same keys, in this order.
        $this->assertSame(
            ['violence', 'illegal', 'sexual', 'self_harm', 'hate', 'personal_data', 'jailbreak', 'political', 'copyright'],
            array_keys(AdminSettingsController::GUARD_CATEGORIES));
        $this->assertSame(implode(',', array_keys(AdminSettingsController::GUARD_CATEGORIES)),
            AppSetting::DEFAULTS['guard_categories']);
    }

    public function test_the_guard_page_ticks_every_category_by_default(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.settings', 'guard'))
            ->assertOk()
            ->assertSee('Harm categories')
            ->assertSee('name="guard_topics"', false);

        $this->assertSame(9, preg_match_all('/<input[^>]*name="guard_categories\[\]"[^>]*checked/', $response->getContent()));
    }

    public function test_the_rules_are_saved(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'guard_categories' => ['personal_data', 'violence'],
                'guard_topics' => "competitor pricing\nlegal advice",
                'guard_borderline' => 'block',
            ]))
            ->assertRedirect(route('admin.settings', 'guard'))
            ->assertSessionHasNoErrors();

        // Stored in the canonical order, whatever order the form sent.
        $this->assertSame('violence,personal_data', AppSetting::get('guard_categories'));
        $this->assertSame("competitor pricing\nlegal advice", AppSetting::get('guard_topics'));
        $this->assertSame('block', AppSetting::get('guard_borderline'));
    }

    public function test_unticking_every_category_is_kept(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame('', AppSetting::get('guard_categories'));
    }

    public function test_a_form_without_the_checkboxes_leaves_the_categories_alone(): void
    {
        AppSetting::put('guard_categories', 'violence');

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload(['guard_categories_present' => null]))
            ->assertSessionHasNoErrors();

        $this->assertSame('violence', AppSetting::get('guard_categories'));
    }

    public function test_an_unknown_category_is_refused_on_the_guard_page(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'section' => 'chunking', 'guard_categories' => ['astrology'],
            ]))
            ->assertRedirect(route('admin.settings', 'guard'))
            ->assertSessionHasErrors('guard_categories.0');
    }

    public function test_an_unknown_borderline_choice_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload(['guard_borderline' => 'maybe']))
            ->assertSessionHasErrors('guard_borderline');
    }
}
