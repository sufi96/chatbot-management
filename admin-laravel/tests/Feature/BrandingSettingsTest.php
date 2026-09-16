<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Brand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'root@test.com'],
            ['name' => 'Root', 'password' => bcrypt('password'),
             'global_role' => 'super_admin'],
        );
    }

    /** Every field the settings form requires, so a branding test fails on
     *  branding rather than on validation. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'embedding_base_url' => 'http://localhost:11434/v1',
            'embedding_api_key' => '',
            'embedding_model' => 'nomic-embed-text',
            'embedding_dimensions' => 768,
            'chunk_size' => 1800,
            'chunk_overlap' => 200,
            'context_char_budget' => 6000,
            'web_search_provider' => 'duckduckgo',
            'web_search_tavily_key' => '',
            'web_search_brave_key' => '',
            'sql_model_base_url' => '',
            'sql_model_api_key' => '',
            'sql_model_name' => '',
        ], $overrides);
    }

    public function test_the_built_in_mark_is_used_when_nothing_was_uploaded(): void
    {
        $this->assertStringContainsString('brand/logo.png', Brand::logoUrl());
        $this->assertStringContainsString('brand/logo-square.png', Brand::iconUrl());
    }

    public function test_an_uploaded_logo_is_stored_and_recorded(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'brand_logo' => UploadedFile::fake()->image('wordmark.png', 400, 100),
            ]))
            ->assertRedirect();

        $path = AppSetting::get('brand_logo_path');

        $this->assertNotSame('', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString($path, Brand::logoUrl());
    }

    public function test_an_uploaded_icon_is_stored_and_recorded(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'brand_icon' => UploadedFile::fake()->image('square.png', 128, 128),
            ]))
            ->assertRedirect();

        $path = AppSetting::get('brand_icon_path');

        Storage::disk('public')->assertExists($path);
        $this->assertStringContainsString($path, Brand::iconUrl());
    }

    public function test_saving_other_settings_does_not_wipe_the_logo(): void
    {
        // The settings loop writes every key it knows about on every save.
        // A logo swept into that loop would vanish the next time anybody
        // changed the chunk size.
        AppSetting::put('brand_logo_path', 'brand/existing.png');

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload(['chunk_size' => 2000]))
            ->assertRedirect();

        $this->assertSame('brand/existing.png', AppSetting::get('brand_logo_path'));
    }

    public function test_reverting_returns_to_the_built_in_mark(): void
    {
        AppSetting::put('brand_logo_path', 'brand/existing.png');

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'),
                $this->payload(['brand_logo_revert' => '1']))
            ->assertRedirect();

        $this->assertSame('', AppSetting::get('brand_logo_path'));
        $this->assertStringContainsString('brand/logo.png', Brand::logoUrl());
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'brand_logo' => UploadedFile::fake()->create('payload.pdf', 20, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('brand_logo');

        $this->assertSame('', AppSetting::get('brand_logo_path'));
    }

    public function test_an_svg_is_refused(): void
    {
        // An SVG served from our own origin runs its own script when somebody
        // opens it directly. Super admin only is not a reason to allow it.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'brand_logo' => UploadedFile::fake()->create('mark.svg', 4, 'image/svg+xml'),
            ]))
            ->assertSessionHasErrors('brand_logo');
    }

    public function test_an_oversized_image_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'brand_logo' => UploadedFile::fake()->create('huge.png', 3000, 'image/png'),
            ]))
            ->assertSessionHasErrors('brand_logo');
    }

    public function test_the_sidebar_shows_the_uploaded_logo(): void
    {
        AppSetting::put('brand_logo_path', 'brand/mine.png');

        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('brand/mine.png');
    }

    public function test_the_browser_tab_uses_the_uploaded_icon(): void
    {
        AppSetting::put('brand_icon_path', 'brand/square.png');

        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('brand/square.png');
    }

    public function test_an_unreadable_setting_still_draws_the_built_in_mark(): void
    {
        // The sign-in screen draws a mark too, and it is the page somebody
        // reaches when the rest of the system is unwell. It must not need a
        // database to render.
        \Illuminate\Support\Facades\Cache::flush();
        \Illuminate\Support\Facades\Schema::drop('app_settings');

        $this->assertStringContainsString('brand/logo.png', Brand::logoUrl());
        $this->assertFalse(Brand::isCustom('brand_logo_path'));
    }

    public function test_the_settings_page_offers_the_branding_card(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Branding');
    }
}
