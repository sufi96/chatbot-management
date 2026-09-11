<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BotWebSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeBot(): BotProfile
    {
        System::create(['id' => 'sys_test', 'name' => 'Test Workspace', 'allowed_origins' => '*']);

        return BotProfile::create([
            'id' => 'bot_ws_1', 'system_id' => 'sys_test', 'name' => 'Bot',
        ]);
    }

    public function test_the_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'web_search_enabled'));
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'web_search_max_results'));
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'web_search_country'));
    }

    public function test_web_search_is_off_by_default(): void
    {
        // Read back rather than trusting the instance just created: the
        // defaults being tested belong to the column, not to the model.
        $bot = $this->makeBot()->fresh();

        $this->assertFalse((bool) $bot->web_search_enabled);
        $this->assertSame(3, (int) $bot->web_search_max_results);
        $this->assertNull($bot->web_search_country);
    }

    public function test_the_settings_can_be_saved(): void
    {
        $bot = $this->makeBot();
        $bot->update([
            'web_search_enabled' => true,
            'web_search_max_results' => 5,
            'web_search_country' => 'MY',
        ]);

        $fresh = BotProfile::find('bot_ws_1');
        $this->assertTrue((bool) $fresh->web_search_enabled);
        $this->assertSame(5, (int) $fresh->web_search_max_results);
        $this->assertSame('MY', $fresh->web_search_country);
    }
}
