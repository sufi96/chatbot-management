<?php

namespace Tests\Feature;

use App\Models\AnswerCache;
use App\Models\AppSetting;
use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use App\Models\User;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The retrieval upgrades and the security layers, as the portal sets them:
 * per bot under Behaviour, per install under Admin settings.
 */
class RetrievalUpgradeSettingsTest extends TestCase
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

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin',
        ]);
    }

    private function bot(): BotProfile
    {
        return BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper']);
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

    private function cachedAnswer(string $id = 'c1'): AnswerCache
    {
        $row = new AnswerCache();
        $row->forceFill([
            'id' => $id, 'bot_id' => 'bot_1', 'question' => 'warranty?', 'embedding_model' => 'm',
            'embedding' => '[1,0]', 'answer' => 'Two years.', 'source_kind' => 'documents',
            'hits' => 3, 'created_at' => now(), 'expires_at' => now()->addDay(),
        ])->save();

        return $row;
    }

    // --- per bot --------------------------------------------------------------

    public function test_a_new_bot_answers_as_before(): void
    {
        $bot = $this->bot()->fresh();

        $this->assertSame(1.0, $bot->retrieval_keyword_weight);
        $this->assertSame('off', $bot->query_expansion);
        $this->assertSame(0, $bot->context_neighbours);
        $this->assertFalse($bot->cache_enabled);
        $this->assertFalse($bot->grounding_check);
    }

    public function test_the_behaviour_page_offers_every_upgrade(): void
    {
        $this->bot();

        $this->actingAs($this->editor())
            ->get(route('bots.brain', 'bot_1'))
            ->assertOk()
            ->assertSee('name="retrieval_keyword_weight"', false)
            ->assertSee('name="query_expansion"', false)
            ->assertSee('name="context_neighbours"', false)
            ->assertSee('name="cache_enabled"', false)
            ->assertSee('name="grounding_check"', false)
            ->assertSee('Answer cache');
    }

    public function test_an_editor_turns_them_on(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload([
                'retrieval_keyword_weight' => 1.5, 'query_expansion' => 'both', 'context_neighbours' => 1,
                'upgrades_present' => '1', 'cache_enabled' => '1', 'cache_min_similarity' => 0.9,
                'cache_ttl_hours' => 48, 'grounding_check' => '1',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $bot = $bot->fresh();
        $this->assertSame(1.5, $bot->retrieval_keyword_weight);
        $this->assertSame('both', $bot->query_expansion);
        $this->assertSame(1, $bot->context_neighbours);
        $this->assertTrue($bot->cache_enabled);
        $this->assertSame(0.9, $bot->cache_min_similarity);
        $this->assertSame(48, $bot->cache_ttl_hours);
        $this->assertTrue($bot->grounding_check);
    }

    public function test_an_older_client_leaves_the_switches_alone(): void
    {
        $bot = $this->bot();
        $bot->update(['cache_enabled' => true, 'grounding_check' => true]);

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload())
            ->assertSessionHasNoErrors();

        $this->assertTrue($bot->fresh()->cache_enabled);
        $this->assertTrue($bot->fresh()->grounding_check);
    }

    public function test_out_of_range_values_are_refused(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload([
                'query_expansion' => 'magic', 'context_neighbours' => 5,
                'cache_min_similarity' => 0.5, 'retrieval_keyword_weight' => 9,
            ]))
            ->assertSessionHasErrors(['query_expansion', 'context_neighbours',
                'cache_min_similarity', 'retrieval_keyword_weight']);
    }

    public function test_saving_behaviour_starts_the_cache_afresh(): void
    {
        $bot = $this->bot();
        $this->cachedAnswer();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload(['system_prompt' => 'New rules.']));

        $this->assertSame(0, AnswerCache::count());
    }

    public function test_the_cache_card_counts_and_clears(): void
    {
        $this->bot();
        $this->cachedAnswer('c1');
        $this->cachedAnswer('c2');
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('bots.brain', 'bot_1'))
            ->assertSee('2 kept · 6 reused')
            ->assertSee('Clear cached answers');

        $this->actingAs($editor)->delete(route('bots.brain.cache.clear', 'bot_1'))
            ->assertRedirect(route('bots.brain', 'bot_1'))
            ->assertSessionHas('success', 'Cleared 2 cached answers.');

        $this->assertSame(0, AnswerCache::count());
    }

    public function test_someone_outside_the_workspace_cannot_clear_it(): void
    {
        $this->bot();
        $this->cachedAnswer();
        $stranger = User::create([
            'name' => 'Other', 'email' => 'other@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);

        $this->actingAs($stranger)->delete(route('bots.brain.cache.clear', 'bot_1'))->assertForbidden();

        $this->assertSame(1, AnswerCache::count());
    }

    public function test_deleting_a_bot_for_good_takes_its_cache_with_it(): void
    {
        $bot = $this->bot();
        $this->cachedAnswer();

        $bot->forceDelete();

        $this->assertSame(0, AnswerCache::count());
    }

    // --- per install ----------------------------------------------------------

    public function test_the_defences_are_on_by_default(): void
    {
        $this->assertSame('server', AppSetting::get('history_source'));
        $this->assertSame('block', AppSetting::get('injection_shield'));
        $this->assertSame('drop', AppSetting::get('injection_shield_sources'));
        $this->assertSame('on', AppSetting::get('leak_guard'));
        $this->assertSame('bm25', AppSetting::get('keyword_engine'));
        $this->assertSame('off', AppSetting::get('contextual_chunks'));
    }

    public function test_the_security_page_offers_each_choice(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings', 'security'))
            ->assertOk()
            ->assertSee('Injection shield')
            ->assertSee('name="history_source"', false)
            ->assertSee('name="injection_shield"', false)
            ->assertSee('name="injection_shield_sources"', false)
            ->assertSee('name="leak_guard"', false);
    }

    public function test_the_chunking_page_offers_the_search_choices(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings', 'chunking'))
            ->assertOk()
            ->assertSee('name="keyword_engine"', false)
            ->assertSee('name="contextual_chunks"', false);
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'section' => 'security',
            'embedding_model' => 'nomic-embed-text', 'embedding_dimensions' => 768,
            'chunk_size' => 1800, 'chunk_overlap' => 200, 'context_char_budget' => 6000,
            'web_search_provider' => 'duckduckgo',
        ], $overrides);
    }

    public function test_a_super_admin_changes_them(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->settingsPayload([
                'history_source' => 'client', 'injection_shield' => 'flag',
                'injection_shield_sources' => 'off', 'leak_guard' => 'off',
                'keyword_engine' => 'postgres', 'contextual_chunks' => 'on',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('client', AppSetting::get('history_source'));
        $this->assertSame('flag', AppSetting::get('injection_shield'));
        $this->assertSame('off', AppSetting::get('injection_shield_sources'));
        $this->assertSame('off', AppSetting::get('leak_guard'));
        $this->assertSame('postgres', AppSetting::get('keyword_engine'));
        $this->assertSame('on', AppSetting::get('contextual_chunks'));
    }

    public function test_a_save_that_does_not_mention_a_defence_keeps_it(): void
    {
        AppSetting::put('injection_shield', 'flag');

        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->settingsPayload(['section' => 'chunking']))
            ->assertSessionHasNoErrors();

        $this->assertSame('flag', AppSetting::get('injection_shield'));
        $this->assertSame('server', AppSetting::get('history_source'));
    }

    public function test_an_unknown_option_is_refused_on_its_own_page(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->settingsPayload(['injection_shield' => 'maybe']))
            ->assertRedirect(route('admin.settings', 'security'))
            ->assertSessionHasErrors('injection_shield');
    }

    // --- what the engine records ----------------------------------------------

    public function test_the_transcript_carries_the_new_verdicts(): void
    {
        $this->bot();
        $conversation = ChatConversation::create(['id' => 'conv_1', 'bot_id' => 'bot_1', 'session_id' => 's1']);
        ChatMessage::create(['id' => 'm1', 'conversation_id' => $conversation->id, 'sender' => 'assistant',
            'content' => 'Five years.', 'cache_hit' => true, 'grounded' => false, 'grounding_note' => 'Five years']);

        $message = ChatMessage::find('m1');
        $this->assertTrue($message->cache_hit);
        $this->assertFalse($message->grounded);
        $this->assertSame('Five years', $message->grounding_note);
    }
}
