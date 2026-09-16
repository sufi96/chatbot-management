<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The one part of this change that touches data somebody already has.
 *
 * Each test rolls the migration back to the old shape, puts bots there as an
 * operator would have left them, and runs it forward again.
 */
class AiProviderBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_14_000004_create_ai_providers.php';

    private function backToTheOldShape(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--realpath' => false]);

        DB::table('systems')->insert([
            'id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function forward(): void
    {
        Artisan::call('migrate', ['--path' => self::MIGRATION, '--realpath' => false]);
    }

    private function legacyBot(string $id, string $baseUrl, string $apiKey = ''): void
    {
        DB::table('bot_profiles')->insert([
            'id' => $id, 'system_id' => 'sys_test', 'name' => 'Bot ' . $id,
            'provider_type' => str_contains($baseUrl, 'localhost') ? 'ollama' : 'custom',
            'base_url' => $baseUrl, 'api_key' => $apiKey, 'model_name' => 'llama3.2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_rolling_back_restores_the_columns_a_bot_used_to_carry(): void
    {
        $this->backToTheOldShape();

        $this->assertTrue(Schema::hasColumn('bot_profiles', 'base_url'));
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'api_key'));
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'provider_type'));
        $this->assertFalse(Schema::hasTable('ai_providers'));
    }

    public function test_an_existing_endpoint_becomes_a_provider_the_bot_points_at(): void
    {
        $this->backToTheOldShape();
        $this->legacyBot('bot_1', 'http://localhost:11434/v1');

        $this->forward();

        $provider = AiProvider::first();

        $this->assertSame('http://localhost:11434/v1', $provider->base_url);
        $this->assertSame('Local Ollama', $provider->name);
        $this->assertSame(
            $provider->id,
            DB::table('bot_profiles')->where('id', 'bot_1')->value('provider_id'));
    }

    /**
     * Two bots on one endpoint are two bots on one provider, so the list
     * starts as short as the setup really is.
     */
    public function test_bots_sharing_an_endpoint_share_one_provider(): void
    {
        $this->backToTheOldShape();
        $this->legacyBot('bot_1', 'http://192.168.1.5:11434/v1');
        $this->legacyBot('bot_2', 'http://192.168.1.5:11434/v1');

        $this->forward();

        $this->assertSame(1, AiProvider::count());
        $this->assertSame('192.168.1.5', AiProvider::first()->name);
    }

    public function test_the_same_host_with_a_different_key_stays_a_separate_provider(): void
    {
        $this->backToTheOldShape();
        $this->legacyBot('bot_1', 'https://api.openai.com/v1', 'sk_one');
        $this->legacyBot('bot_2', 'https://api.openai.com/v1', 'sk_two');

        $this->forward();

        $this->assertSame(2, AiProvider::count());
        $this->assertEqualsCanonicalizing(
            ['sk_one', 'sk_two'],
            AiProvider::pluck('api_key')->all());
    }

    public function test_a_bot_with_no_endpoint_at_all_is_left_unattached(): void
    {
        $this->backToTheOldShape();
        $this->legacyBot('bot_1', '');

        $this->forward();

        $this->assertSame(0, AiProvider::count());
        $this->assertNull(DB::table('bot_profiles')->where('id', 'bot_1')->value('provider_id'));
    }

    /**
     * A rollback loses the shared list, but must not lose the settings a bot
     * was answering on.
     */
    public function test_rolling_back_puts_each_bots_endpoint_back_where_it_was(): void
    {
        $this->backToTheOldShape();
        $this->legacyBot('bot_1', 'https://api.groq.com/openai/v1', 'gsk_key');
        $this->forward();

        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--realpath' => false]);

        $bot = DB::table('bot_profiles')->where('id', 'bot_1')->first();

        $this->assertSame('https://api.groq.com/openai/v1', $bot->base_url);
        $this->assertSame('gsk_key', $bot->api_key);
        $this->assertSame('custom', $bot->provider_type);
    }
}
