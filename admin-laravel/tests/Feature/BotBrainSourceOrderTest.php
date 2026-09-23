<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotBrainSourceOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => "{$role}@example.test",
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => $role]);

        return $user;
    }

    private function bot(array $overrides = []): BotProfile
    {
        return BotProfile::create(array_merge([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper',
        ], $overrides));
    }

    /** Every field the update route insists on, so a test about ordering
     *  fails on ordering rather than on validation. */
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

    public function test_the_brain_page_shows_the_bots_order(): void
    {
        $bot = $this->bot(['source_order' => 'database,documents,web']);

        $html = $this->actingAs($this->userWithRole('editor'))
            ->get(route('bots.brain', $bot->id))
            ->assertOk()
            ->assertSee('Answer sources')
            ->getContent();

        // Scoped to the list's own rows: "Knowledge base" is also the name of
        // a sidebar link, and it sits above all of this.
        $this->assertLessThan(
            strpos($html, 'data-token="documents"'),
            strpos($html, 'data-token="database"'));

        // The rows are still labelled for a person, not by the stored token.
        $this->assertStringContainsString('>Knowledge base<', $html);
    }

    public function test_a_source_that_is_switched_off_says_so_in_the_order(): void
    {
        // An operator who puts the database first and sees nothing change
        // needs to be told why, next to the row they just moved.
        $bot = $this->bot(['db_query_enabled' => false]);

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('bots.brain', $bot->id))
            ->assertOk()
            ->assertSee('switched off');
    }

    public function test_an_editor_changes_the_order(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('bots.brain.update', $bot->id),
                $this->brainPayload(['source_order' => 'web,database,documents']))
            ->assertRedirect();

        $this->assertSame('web,database,documents', $bot->fresh()->source_order);
    }

    public function test_a_malformed_order_is_normalised_rather_than_refused(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('bots.brain.update', $bot->id),
                $this->brainPayload(['source_order' => 'web,web,telepathy']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('web,documents,database', $bot->fresh()->source_order);
    }

    public function test_an_absent_order_falls_back_to_the_default(): void
    {
        $bot = $this->bot(['source_order' => 'web,documents,database']);

        $payload = $this->brainPayload();
        unset($payload['source_order']);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('bots.brain.update', $bot->id), $payload)
            ->assertRedirect();

        $this->assertSame(SourceOrder::DEFAULT, $bot->fresh()->source_order);
    }

    public function test_a_new_bot_combines_and_understands_follow_ups(): void
    {
        $bot = $this->bot()->fresh();

        $this->assertTrue($bot->combine_sources);
        $this->assertTrue($bot->intent_enabled);
    }

    public function test_an_editor_switches_combining_on_and_off(): void
    {
        $bot = $this->bot();
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload(['combine_sources' => '0']))
            ->assertRedirect();
        $this->assertFalse($bot->fresh()->combine_sources);

        $this->actingAs($editor)
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload(['combine_sources' => '1']))
            ->assertRedirect();
        $this->assertTrue($bot->fresh()->combine_sources);
    }

    public function test_temperature_and_max_tokens_are_saved_on_behaviour(): void
    {
        $bot = $this->bot();

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('bots.brain.update', $bot->id), $this->brainPayload(['temperature' => 0.3, 'max_tokens' => 512]))
            ->assertRedirect();

        $this->assertSame(0.3, (float) $bot->fresh()->temperature);
        $this->assertSame(512, (int) $bot->fresh()->max_tokens);
    }

    public function test_temperature_is_capped_at_one(): void
    {
        $this->actingAs($this->userWithRole('editor'))
            ->put(route('bots.brain.update', $this->bot()->id), $this->brainPayload(['temperature' => 1.2]))
            ->assertSessionHasErrors('temperature');
    }

    public function test_the_behaviour_page_has_no_test_widget_button(): void
    {
        $this->actingAs($this->userWithRole('editor'))
            ->get(route('bots.brain', $this->bot()->id))
            ->assertOk()
            ->assertDontSee('Open test widget');
    }

    public function test_the_model_and_endpoint_are_set_on_behaviour(): void
    {
        \App\Models\AiProvider::create(['id' => 'aip_own', 'system_id' => 'sys_test', 'name' => 'Office PC',
            'base_url' => 'http://localhost:11434/v1', 'api_key' => '']);
        $bot = $this->bot();
        $editor = $this->userWithRole('editor');

        $this->actingAs($editor)->get(route('bots.brain', $bot->id))
            ->assertOk()->assertSee('Model and endpoint')->assertSee('Office PC');
        $this->actingAs($editor)->get(route('bots.edit', $bot->id))
            ->assertOk()->assertDontSee('Model and endpoint');

        $this->actingAs($editor)
            ->put(route('bots.brain.update', $bot->id),
                $this->brainPayload(['provider_id' => 'aip_own', 'model_name' => 'qwen3:8b']))
            ->assertSessionHasNoErrors();

        $this->assertSame(['aip_own', 'qwen3:8b'], [$bot->fresh()->provider_id, $bot->fresh()->model_name]);
    }

    public function test_a_provider_this_user_cannot_use_is_refused(): void
    {
        $this->actingAs($this->userWithRole('editor'))
            ->put(route('bots.brain.update', $this->bot()->id), $this->brainPayload(['provider_id' => 'aip_nowhere']))
            ->assertSessionHasErrors('provider_id');
    }
}
