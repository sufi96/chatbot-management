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

class BotGuardSettingsTest extends TestCase
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
        return BotProfile::create(array_merge(
            ['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper'], $overrides));
    }

    private function brainPayload(array $overrides = []): array
    {
        return array_merge([
            'system_prompt' => 'Be helpful.', 'retrieval_mode' => 'hybrid',
            'retrieval_top_k' => 5, 'retrieval_candidates' => 30,
            'retrieval_min_score' => 0.02, 'retrieval_fallback' => 'say_unknown',
            'web_search_max_results' => 3, 'web_search_country' => null,
            'top_p' => 1.0, 'top_k_sampling' => null, 'presence_penalty' => 0,
            'frequency_penalty' => 0, 'thinking_level' => 'off',
            'db_max_rows' => 50, 'db_query_timeout' => 10,
            'source_order' => SourceOrder::DEFAULT,
        ], $overrides);
    }

    public function test_the_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'guard_enabled'));
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'guard_refusal'));
        $this->assertTrue(Schema::hasColumn('chat_messages', 'guard_flag'));
    }

    public function test_the_guard_is_off_by_default(): void
    {
        $bot = $this->bot()->fresh();

        $this->assertFalse($bot->guard_enabled);
        $this->assertNull($bot->guard_refusal);
    }

    public function test_the_brain_page_offers_it(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->get(route('bots.brain', $bot->id))
            ->assertOk()
            ->assertSee('Check messages and answers for harm')
            ->assertSee('name="guard_refusal"', false);
    }

    public function test_an_editor_switches_it_on_with_a_refusal(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload([
                'guard_enabled' => '1',
                'guard_refusal' => 'Maaf, saya tidak dapat membantu dengan itu.',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $bot->fresh();
        $this->assertTrue($fresh->guard_enabled);
        $this->assertSame('Maaf, saya tidak dapat membantu dengan itu.', $fresh->guard_refusal);
    }

    public function test_an_unticked_box_switches_it_off(): void
    {
        $bot = $this->bot(['guard_enabled' => true]);

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload())
            ->assertRedirect();

        $this->assertFalse($bot->fresh()->guard_enabled);
    }

    public function test_an_overlong_refusal_is_refused(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id),
                $this->brainPayload(['guard_refusal' => str_repeat('x', 501)]))
            ->assertSessionHasErrors('guard_refusal');
    }

    public function test_the_transcript_carries_the_flag(): void
    {
        $user = $this->editor();
        $this->bot();
        ChatConversation::create(['id' => 'conv_1', 'bot_id' => 'bot_1', 'session_id' => 's1', 'origin' => '']);
        ChatMessage::create([
            'id' => 'msg_1', 'conversation_id' => 'conv_1', 'sender' => 'user',
            'content' => 'something harmful', 'guard_flag' => 'Violent',
        ]);

        $this->actingAs($user)
            ->get(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertJsonPath('messages.0.guard_flag', 'Violent');
    }

    public function test_a_bot_adds_its_own_blocked_topics(): void
    {
        $bot = $this->bot();
        $editor = $this->editor();

        $this->actingAs($editor)
            ->get(route('bots.brain', $bot->id))
            ->assertSee('name="guard_topics"', false);

        $this->actingAs($editor)
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload([
                'guard_enabled' => '1',
                'guard_topics' => "competitor pricing
legal advice",
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame("competitor pricing
legal advice", $bot->fresh()->guard_topics);
    }

    public function test_overlong_topics_are_refused(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id),
                $this->brainPayload(['guard_topics' => str_repeat('x', 2001)]))
            ->assertSessionHasErrors('guard_topics');
    }
}
