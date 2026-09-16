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
            ->assertSee('Answer source order')
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
}
