<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use App\Models\User;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BotIntentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    private function editor(): User
    {
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    private function bot(array $overrides = []): BotProfile
    {
        return BotProfile::create(array_merge([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper',
        ], $overrides));
    }

    private function brainPayload(array $overrides = []): array
    {
        return array_merge([
            'system_prompt' => 'Be helpful.',
            'retrieval_mode' => 'hybrid',
            'retrieval_top_k' => 5,
            'retrieval_candidates' => 30,
            'retrieval_min_score' => 0.02,
            'retrieval_fallback' => 'say_unknown',
            'web_search_max_results' => 3,
            'web_search_country' => null,
            'top_p' => 1.0,
            'top_k_sampling' => null,
            'presence_penalty' => 0,
            'frequency_penalty' => 0,
            'thinking_level' => 'off',
            'db_max_rows' => 50,
            'db_query_timeout' => 10,
            'source_order' => SourceOrder::DEFAULT,
        ], $overrides);
    }

    public function test_the_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'intent_enabled'));
        $this->assertTrue(Schema::hasColumn('chat_messages', 'intent'));
        $this->assertTrue(Schema::hasColumn('chat_messages', 'intent_query'));
    }

    public function test_a_new_bot_has_it_on(): void
    {
        // A follow-up searched in the visitor's own words finds nothing, so a
        // new bot starts with it on. The migration that made this the default
        // leaves existing bots as their operators set them.
        $this->assertTrue($this->bot()->fresh()->intent_enabled);
    }

    public function test_the_brain_page_offers_the_switch(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->get(route('bots.brain', $bot->id))
            ->assertOk()
            ->assertSee('Understand follow-up questions')
            ->assertSee('name="intent_enabled"', false);
    }

    public function test_an_editor_switches_it_on(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload(['intent_enabled' => '1']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($bot->fresh()->intent_enabled);
    }

    public function test_an_unticked_box_switches_it_off(): void
    {
        $bot = $this->bot(['intent_enabled' => true]);

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload())
            ->assertRedirect();

        $this->assertFalse($bot->fresh()->intent_enabled);
    }

    public function test_the_transcript_shows_what_a_question_was_searched_as(): void
    {
        $user = $this->editor();
        $this->bot();
        ChatConversation::create([
            'id' => 'conv_1', 'bot_id' => 'bot_1', 'session_id' => 's1', 'origin' => '',
        ]);

        ChatMessage::create([
            'id' => 'msg_1', 'conversation_id' => 'conv_1', 'sender' => 'user',
            'content' => 'and the warranty?',
            'intent' => 'facts',
            'intent_query' => 'What is the warranty on the X200 air fryer?',
        ]);

        $this->actingAs($user)
            ->get(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertJsonPath('messages.0.intent', 'facts')
            ->assertJsonPath('messages.0.intent_query', 'What is the warranty on the X200 air fryer?');
    }
}
