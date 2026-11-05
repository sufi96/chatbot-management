<?php

namespace Tests\Feature;

use App\Models\AnswerCache;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A bot's settings as tabs: Appearance, Behaviour, Brain, Agents,
 * Files & vision and Safety. Each tab saves only its own settings.
 */
class BotSettingsTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper', 'model_name' => 'qwen3:8b']);
        BotProfile::find('bot_1')->forceFill([
            'system_prompt' => 'Be kind.', 'retrieval_enabled' => true, 'web_search_enabled' => true,
            'agentic_enabled' => true, 'guard_enabled' => true, 'grounding_check' => true,
            'voice_output' => true, 'cache_enabled' => true, 'intent_enabled' => true,
            'file_settings' => ['in_enabled' => true, 'in_types' => ['pdf']],
            'builtin_tools' => ['current_datetime'],
        ])->save();

        $this->editor = User::create(['name' => 'Ed', 'email' => 'ed@example.test', 'password' => 'password', 'global_role' => 'user']);
        $this->editor->systems()->attach('sys_test', ['role' => 'editor']);
    }

    private function save(string $section, array $fields)
    {
        return $this->actingAs($this->editor)
            ->put(route('bots.brain.update', 'bot_1'), ['section' => $section] + $fields);
    }

    private function bot(): BotProfile
    {
        return BotProfile::find('bot_1');
    }

    public function test_behaviour_saves_the_prompt_and_voice_and_leaves_the_rest(): void
    {
        $this->save('behaviour', ['system_prompt' => 'Be brief.', 'thinking_level' => 'low',
            'top_p' => 0.9, 'presence_penalty' => 0, 'frequency_penalty' => 0])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('bots.settings', ['bot_1', 'behaviour']));

        $bot = $this->bot();
        $this->assertSame(['Be brief.', 'low'], [$bot->system_prompt, $bot->thinking_level]);
        // Voice is on this tab, and its switch was not sent: it is off now.
        $this->assertFalse((bool) $bot->voice_output);
        // Everything on the other tabs is as it was.
        $this->assertTrue((bool) $bot->retrieval_enabled);
        $this->assertTrue((bool) $bot->web_search_enabled);
        $this->assertTrue((bool) $bot->agentic_enabled);
        $this->assertTrue((bool) $bot->guard_enabled);
        $this->assertTrue((bool) $bot->grounding_check);
        $this->assertTrue((bool) $bot->cache_enabled);
        $this->assertTrue($bot->fileSettings()['in_enabled']);
        $this->assertSame(['current_datetime'], $bot->builtin_tools);
    }

    public function test_brain_saves_the_sources_and_leaves_agents_files_voice_and_guard(): void
    {
        $this->save('brain', ['retrieval_enabled' => 1, 'retrieval_mode' => 'vector', 'retrieval_top_k' => 4,
            'retrieval_candidates' => 20, 'retrieval_min_score' => 0.02, 'retrieval_fallback' => 'answer_anyway',
            'web_search_max_results' => 3, 'db_max_rows' => 50, 'db_query_timeout' => 10,
            'source_order' => 'web,documents,database,tools'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('bots.settings', ['bot_1', 'brain']));

        $bot = $this->bot();
        $this->assertSame(['vector', 'answer_anyway'], [$bot->retrieval_mode, $bot->retrieval_fallback]);
        $this->assertStringStartsWith('web,', $bot->source_order);
        // Switches on this tab that were not sent are off.
        $this->assertFalse((bool) $bot->web_search_enabled);
        $this->assertFalse((bool) $bot->cache_enabled);
        $this->assertFalse((bool) $bot->intent_enabled);
        // Other tabs untouched.
        $this->assertSame('Be kind.', $bot->system_prompt);
        $this->assertTrue((bool) $bot->agentic_enabled);
        $this->assertTrue((bool) $bot->guard_enabled);
        $this->assertTrue((bool) $bot->voice_output);
        $this->assertTrue((bool) $bot->grounding_check);
        $this->assertTrue($bot->fileSettings()['in_enabled']);
    }

    public function test_agents_files_and_safety_each_save_their_own(): void
    {
        $this->save('agents', ['agentic_max_calls' => 2])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $this->bot()->agentic_enabled);
        $this->assertSame(2, (int) $this->bot()->agentic_max_calls);
        $this->assertSame([], $this->bot()->builtin_tools);

        $this->save('files', ['files_out_enabled' => 1, 'files_out_formats' => ['xlsx']])->assertSessionHasNoErrors();
        $files = $this->bot()->fileSettings();
        $this->assertSame([false, true, ['xlsx']], [$files['in_enabled'], $files['out_enabled'], $files['out_formats']]);

        $this->save('safety', ['guard_refusal' => 'Not here.'])->assertSessionHasNoErrors();
        $bot = $this->bot();
        $this->assertFalse((bool) $bot->guard_enabled);
        $this->assertFalse((bool) $bot->grounding_check);
        $this->assertSame('Not here.', $bot->guard_refusal);

        // None of those touched Behaviour or Brain.
        $this->assertSame('Be kind.', $bot->system_prompt);
        $this->assertTrue((bool) $bot->retrieval_enabled);
        $this->assertTrue((bool) $bot->voice_output);
    }

    public function test_safety_keeps_the_answer_cache_and_the_others_clear_it(): void
    {
        if (!Schema::hasTable('answer_cache')) {
            $this->markTestSkipped('No answer cache table.');
        }
        $cache = fn () => \Illuminate\Support\Facades\DB::table('answer_cache')->insert(['id' => (string) \Illuminate\Support\Str::uuid(),
            'bot_id' => 'bot_1', 'question' => 'q', 'answer' => 'a', 'embedding_model' => 'm', 'embedding' => '[]']);

        $cache();
        $this->save('safety', ['guard_refusal' => 'No.']);
        $this->assertSame(1, AnswerCache::where('bot_id', 'bot_1')->count());

        $this->save('files', []);
        $this->assertSame(0, AnswerCache::where('bot_id', 'bot_1')->count());
    }

    public function test_each_tab_shows_its_cards_and_not_the_others(): void
    {
        $cards = [
            'behaviour' => ['bcard-system-prompt', 'bcard-generation', 'bcard-voice'],
            'brain' => ['bcard-sources', 'bcard-web-search', 'bcard-cache', 'pane-kb'],
            'agents' => ['bcard-agentic'],
            'files' => ['bcard-files'],
            'safety' => ['bcard-safety'],
        ];

        foreach ($cards as $section => $mine) {
            $html = $this->actingAs($this->editor)->get(route('bots.settings', ['bot_1', $section]))->assertOk()->getContent();
            foreach ($cards as $other => $theirs) {
                foreach ($theirs as $id) {
                    $other === $section
                        ? $this->assertStringContainsString("id=\"{$id}\"", $html, "{$section} lacks {$id}")
                        : $this->assertStringNotContainsString("id=\"{$id}\"", $html, "{$section} shows {$id}");
                }
            }
            $this->assertStringContainsString('name="section" value="' . $section . '"', $html);
        }
    }

    public function test_the_tabs_bar_has_six_tabs_with_appearance_first(): void
    {
        $html = $this->actingAs($this->editor)->get(route('bots.settings', ['bot_1', 'brain']))->getContent();

        preg_match_all('/class="nav-link[^"]*"[^>]*>\s*<i class="bi [^"]+"><\/i>\s*([^<]+?)\s*</', $html, $m);
        $this->assertSame(['Appearance', 'Behaviour', 'Brain', 'Agents', 'Files &amp; vision', 'Safety'], array_slice($m[1], 0, 6));
        $this->actingAs($this->editor)->get(route('bots.edit', 'bot_1'))->assertSee('Appearance');
    }

    public function test_the_old_behaviour_address_opens_the_behaviour_tab_and_unknown_tabs_are_404(): void
    {
        $this->actingAs($this->editor)->get(route('bots.brain', 'bot_1'))
            ->assertRedirect(route('bots.settings', ['bot_1', 'behaviour']));
        $this->actingAs($this->editor)->get('/bots/bot_1/settings/nonsense')->assertNotFound();
    }

    public function test_a_viewer_cannot_open_a_tab(): void
    {
        $viewer = User::create(['name' => 'V', 'email' => 'v@example.test', 'password' => 'password', 'global_role' => 'user']);
        $viewer->systems()->attach('sys_test', ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('bots.settings', ['bot_1', 'safety']))->assertForbidden();
    }
}
