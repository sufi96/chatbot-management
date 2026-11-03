<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A bot's Agentic section: whether it may call connector tools, and which. */
class AgenticSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper']);
    }

    public function test_a_new_bot_is_not_agentic(): void
    {
        $bot = BotProfile::find('bot_1');

        $this->assertFalse($bot->agentic_enabled);
        $this->assertSame(3, $bot->agentic_max_calls);
        $this->assertCount(0, $bot->tools);
    }
}
