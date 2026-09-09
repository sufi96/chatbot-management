<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\KbCollection;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotBrainTest extends TestCase
{
    use RefreshDatabase;

    private System $system;
    private BotProfile $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->system = System::create([
            'id' => 'sys_test', 'name' => 'Test Workspace', 'allowed_origins' => '*',
        ]);
        $this->bot = BotProfile::create([
            'id' => 'test_chat_01', 'system_id' => 'sys_test', 'name' => 'Bot',
            'system_prompt' => 'Original prompt.',
        ]);
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'One']);
        KbCollection::create(['id' => 'kbc_2', 'system_id' => 'sys_test', 'name' => 'Two']);
    }

    private function editor(): User
    {
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'system_prompt' => 'p',
            'retrieval_mode' => 'hybrid',
            'retrieval_top_k' => 5,
            'retrieval_candidates' => 30,
            'retrieval_min_score' => 0,
            'retrieval_fallback' => 'say_unknown',
            'top_p' => 1,
            'presence_penalty' => 0,
            'frequency_penalty' => 0,
            'thinking_level' => 'off',
        ], $overrides);
    }

    public function test_the_brain_page_shows_the_prompt_and_the_collections(): void
    {
        $this->actingAs($this->editor())
            ->get(route('bots.brain', $this->bot->id))
            ->assertOk()
            ->assertSee('Original prompt.')
            ->assertSee('One')
            ->assertSee('Two');
    }

    public function test_saving_updates_the_prompt_and_the_settings(): void
    {
        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $this->bot->id), $this->payload([
                'system_prompt' => 'New prompt.',
                'retrieval_enabled' => '1',
                'retrieval_mode' => 'vector',
                'retrieval_top_k' => 8,
                'retrieval_candidates' => 40,
                'retrieval_min_score' => 0.02,
                'retrieval_fallback' => 'answer_anyway',
                'top_p' => 0.9,
                'presence_penalty' => 0.1,
                'frequency_penalty' => 0.2,
                'thinking_level' => 'medium',
                'collections' => ['kbc_1'],
            ]))
            ->assertRedirect();

        $bot = $this->bot->fresh();
        $this->assertSame('New prompt.', $bot->system_prompt);
        $this->assertTrue($bot->retrieval_enabled);
        $this->assertSame('vector', $bot->retrieval_mode);
        $this->assertSame(8, $bot->retrieval_top_k);
        $this->assertSame('answer_anyway', $bot->retrieval_fallback);
        $this->assertSame('medium', $bot->thinking_level);
        $this->assertEqualsWithDelta(0.9, $bot->top_p, 0.0001);
        $this->assertSame(['kbc_1'], $bot->collections->pluck('id')->all());
    }

    public function test_unchecking_every_collection_detaches_them_all(): void
    {
        $this->bot->collections()->sync(['kbc_1', 'kbc_2']);

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $this->bot->id), $this->payload())
            ->assertRedirect();

        $this->assertCount(0, $this->bot->fresh()->collections);
    }

    public function test_retrieval_is_off_when_the_switch_is_not_posted(): void
    {
        $this->bot->update(['retrieval_enabled' => true]);

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $this->bot->id), $this->payload())
            ->assertRedirect();

        $this->assertFalse($this->bot->fresh()->retrieval_enabled);
    }

    public function test_a_viewer_cannot_open_the_brain_page(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $viewer->systems()->attach('sys_test', ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->get(route('bots.brain', $this->bot->id))
            ->assertForbidden();
    }

    public function test_a_collection_from_another_workspace_cannot_be_attached(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs']);

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', $this->bot->id),
                $this->payload(['collections' => ['kbc_other']]));

        $this->assertCount(0, $this->bot->fresh()->collections);
    }

    public function test_the_edit_form_no_longer_carries_the_prompt(): void
    {
        $this->actingAs($this->editor())
            ->get(route('bots.edit', $this->bot->id))
            ->assertOk()
            ->assertDontSee('name="system_prompt"', false)
            ->assertSee('Open Brain');
    }
}
