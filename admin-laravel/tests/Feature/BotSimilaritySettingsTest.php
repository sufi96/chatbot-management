<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BotSimilaritySettingsTest extends TestCase
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

    public function test_the_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumn('bot_profiles', 'retrieval_min_similarity'));
    }

    public function test_the_floor_starts_where_nomic_embed_text_separates_answers_from_noise(): void
    {
        // Measured on the Kedai Aina set: passages that answered scored 0.69 to
        // 0.83, and the best passage for questions they could not answer 0.44
        // to 0.63.
        $this->assertSame(0.65, $this->bot()->fresh()->retrieval_min_similarity);
    }

    public function test_the_brain_page_offers_it(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->get(route('bots.brain', $bot->id))
            ->assertOk()
            ->assertSee('Similarity floor')
            ->assertSee('name="retrieval_min_similarity"', false);
    }

    public function test_an_editor_sets_it(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload(['retrieval_min_similarity' => 0.7]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0.7, $bot->fresh()->retrieval_min_similarity);
    }

    public function test_a_floor_above_one_is_refused(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload(['retrieval_min_similarity' => 1.2]))
            ->assertSessionHasErrors('retrieval_min_similarity');
    }
}
