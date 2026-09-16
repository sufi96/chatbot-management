<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin Settings stops holding raw endpoints and links platform providers.
 *
 * Each test rolls the migration back, stores endpoints the way the old screen
 * saved them, and runs it forward again.
 */
class PlatformProviderMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_16_000001_move_setting_endpoints_to_platform_providers.php';

    private function back(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--realpath' => false]);
    }

    private function forward(): void
    {
        Artisan::call('migrate', ['--path' => self::MIGRATION, '--realpath' => false]);
    }

    private function stored(array $settings): void
    {
        foreach ($settings as $key => $value) {
            DB::table('app_settings')->insert([
                'key' => $key, 'value' => $value,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function value(string $key): ?string
    {
        return DB::table('app_settings')->where('key', $key)->value('value');
    }

    public function test_each_stored_endpoint_becomes_a_linked_platform_provider(): void
    {
        $this->back();
        $this->stored([
            'embedding_base_url' => 'http://localhost:11434/v1',
            'embedding_api_key' => '',
            'sql_model_base_url' => 'http://spark-a:8000/v1',
            'sql_model_api_key' => 'sk-a',
            'sql_model_name' => 'qwen3-coder:30b',
            'rerank_model_base_url' => 'http://spark-a:8000/v1',
            'rerank_model_api_key' => 'sk-a',
        ]);

        $this->forward();

        $local = AiProvider::platform()->where('base_url', 'http://localhost:11434/v1')->sole();
        $spark = AiProvider::platform()->where('base_url', 'http://spark-a:8000/v1')->sole();

        $this->assertSame('Local Ollama', $local->name);
        $this->assertSame('spark-a:8000', $spark->name);
        $this->assertSame('sk-a', $spark->api_key);

        $this->assertSame($local->id, $this->value('embedding_provider_id'));
        // Same URL and key: one provider, two jobs on it.
        $this->assertSame($spark->id, $this->value('sql_model_provider_id'));
        $this->assertSame($spark->id, $this->value('rerank_model_provider_id'));
        $this->assertSame('qwen3-coder:30b', $this->value('sql_model_name'));

        foreach (['embedding_base_url', 'embedding_api_key', 'sql_model_base_url', 'sql_model_api_key'] as $gone) {
            $this->assertNull($this->value($gone), $gone);
        }
    }

    public function test_the_same_url_with_a_different_key_is_a_different_provider(): void
    {
        $this->back();
        $this->stored([
            'intent_model_base_url' => 'https://api.example.com/v1',
            'intent_model_api_key' => 'one',
            'guard_model_base_url' => 'https://api.example.com/v1',
            'guard_model_api_key' => 'two',
        ]);

        $this->forward();

        $this->assertSame(2, AiProvider::platform()->count());
    }

    public function test_a_blank_endpoint_makes_no_provider(): void
    {
        $this->back();
        $this->stored(['vision_model_base_url' => '', 'vision_model_api_key' => '']);

        $this->forward();

        $this->assertSame(0, AiProvider::platform()->count());
        $this->assertNull($this->value('vision_model_provider_id'));
    }

    public function test_the_new_setting_is_read_rather_than_a_cached_old_one(): void
    {
        $this->back();
        $this->stored(['sql_model_base_url' => 'http://spark-a:8000/v1']);
        AppSetting::get('sql_model_provider_id');

        $this->forward();

        $this->assertNotSame('', AppSetting::get('sql_model_provider_id'));
    }

    public function test_rolling_back_writes_the_endpoints_back(): void
    {
        $this->back();
        $this->stored([
            'embedding_base_url' => 'http://spark-a:8000/v1',
            'embedding_api_key' => 'sk-a',
        ]);
        $this->forward();

        $this->back();

        $this->assertSame('http://spark-a:8000/v1', $this->value('embedding_base_url'));
        $this->assertSame('sk-a', $this->value('embedding_api_key'));
        $this->assertNull($this->value('embedding_provider_id'));
        $this->assertSame(0, DB::table('ai_providers')->whereNull('system_id')->count());
    }
}
