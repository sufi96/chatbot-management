<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-17 12:00:00');

        System::create(['id' => 'sys_mine', 'name' => 'Mine', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_mine', 'system_id' => 'sys_mine', 'name' => 'Desk']);
        BotProfile::create(['id' => 'bot_mine_2', 'system_id' => 'sys_mine', 'name' => 'Sales']);
        BotProfile::create(['id' => 'bot_other', 'system_id' => 'sys_other', 'name' => 'Elsewhere']);

        $this->viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $this->viewer->systems()->attach('sys_mine', ['role' => 'viewer']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin',
        ]);
    }

    /** A message at a moment, with any extra columns. */
    private function message(string $id, string $conversationId, string $sender, string $at, array $fields = []): void
    {
        Carbon::setTestNow($at);
        ChatMessage::create(['id' => $id, 'conversation_id' => $conversationId, 'sender' => $sender,
            'content' => $fields['content'] ?? "{$sender} {$id}"] + $fields);
        Carbon::setTestNow('2026-09-17 12:00:00');
    }

    private function conversation(string $id, string $botId, string $at, string $origin = 'https://shop.example.com'): void
    {
        Carbon::setTestNow($at);
        ChatConversation::create(['id' => $id, 'bot_id' => $botId, 'session_id' => "sess_{$id}", 'origin' => $origin]);
        Carbon::setTestNow('2026-09-17 12:00:00');
    }

    private function report(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->viewer)
            ->get(route('analytics.index', $query + ['tz' => 'UTC']))
            ->assertOk()
            ->viewData('report');
    }

    public function test_the_sidebar_links_to_the_page(): void
    {
        $this->actingAs($this->viewer)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('analytics.index'), false);
    }

    public function test_bot_profiles_no_longer_link_to_analytics(): void
    {
        $response = $this->actingAs($this->viewer)->get(route('bots.index'))->assertOk();

        $this->assertStringNotContainsString('bot-card-head', $response->getContent());
    }

    public function test_a_viewer_sees_only_the_bots_of_their_own_workspaces(): void
    {
        $this->conversation('c_x', 'bot_other', '2026-09-16 10:00:00');
        $this->message('m_x', 'c_x', 'user', '2026-09-16 10:00:01');

        $response = $this->actingAs($this->viewer)->get(route('analytics.index', ['tz' => 'UTC']))->assertOk();

        $this->assertSame(['bot_mine', 'bot_mine_2'], $response->viewData('bots')->pluck('id')->sort()->values()->all());
        $this->assertSame(0, $response->viewData('report')['kpis']['visitor_messages']);
        $this->assertFalse($response->viewData('multiWorkspace'));
        $response->assertDontSee('Elsewhere');
    }

    public function test_picking_a_bot_the_viewer_cannot_open_is_ignored(): void
    {
        $this->conversation('c_x', 'bot_other', '2026-09-16 10:00:00');
        $this->message('m_x', 'c_x', 'user', '2026-09-16 10:00:01');

        $report = $this->report(['bots' => ['bot_other']]);

        $this->assertSame(0, $report['kpis']['visitor_messages']);
    }

    public function test_a_super_admin_sees_every_workspace_grouped_in_the_picker(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('analytics.index', ['tz' => 'UTC']))->assertOk();

        $this->assertSame(['Mine', 'Other'], $response->viewData('botGroups')->map(fn ($g) => $g['system']->name)->all());
        $this->assertTrue($response->viewData('multiWorkspace'));
        $this->assertCount(3, $response->viewData('report')['by_bot']);
    }

    public function test_picking_bots_narrows_every_figure_to_them(): void
    {
        $this->conversation('c1', 'bot_mine', '2026-09-16 09:00:00');
        $this->message('m1', 'c1', 'user', '2026-09-16 09:00:01');
        $this->conversation('c2', 'bot_mine_2', '2026-09-16 09:00:00');
        $this->message('m2', 'c2', 'user', '2026-09-16 09:00:01');
        $this->message('m3', 'c2', 'user', '2026-09-16 09:01:01');

        $all = $this->report();
        $one = $this->report(['bots' => ['bot_mine_2']]);

        $this->assertSame(3, $all['kpis']['visitor_messages']);
        $this->assertSame(2, $one['kpis']['visitor_messages']);
        $this->assertSame(['bot_mine' => 1, 'bot_mine_2' => 2],
            collect($all['by_bot'])->mapWithKeys(fn ($row) => [$row['bot']->id => $row['visitor_messages']])->sortKeys()->all());
    }

    public function test_someone_with_no_bots_gets_an_empty_state(): void
    {
        BotProfile::query()->forceDelete();

        $this->actingAs($this->viewer)->get(route('analytics.index'))
            ->assertOk()
            ->assertSee('No bot profiles to report on');
    }

    public function test_the_figures_count_only_the_viewers_bots_inside_the_window(): void
    {
        $this->conversation('c1', 'bot_mine', '2026-09-16 09:00:00');
        $this->message('m1', 'c1', 'user', '2026-09-16 09:00:01', ['intent' => 'facts', 'content' => 'Opening hours?']);
        $this->message('m2', 'c1', 'assistant', '2026-09-16 09:00:03', [
            'tokens_used' => 100, 'source_kind' => 'documents', 'first_token_ms' => 800, 'response_ms' => 2000,
            'citations' => json_encode([['n' => 1, 'title' => 'Handbook', 'source_id' => 'src_1']]),
            'model_trace' => json_encode(['chat' => 'qwen3.5:4b']),
        ]);
        $this->message('m3', 'c1', 'user', '2026-09-16 09:05:00', ['intent' => 'facts', 'content' => 'opening hours']);
        $this->message('m4', 'c1', 'assistant', '2026-09-16 09:05:04', [
            'tokens_used' => 50, 'source_kind' => 'none', 'first_token_ms' => 1200, 'response_ms' => 4000,
        ]);

        // One visitor message only: a bounce, flagged, from the sandbox.
        $this->conversation('c2', 'bot_mine', '2026-09-15 10:00:00', '');
        $this->message('m5', 'c2', 'user', '2026-09-15 10:00:01', ['guard_flag' => 'Violent']);
        $this->message('m6', 'c2', 'assistant', '2026-09-15 10:00:01', ['source_kind' => 'refused', 'first_token_ms' => 300, 'response_ms' => 300]);

        // Outside the 7-day window, and another bot's.
        $this->conversation('c_old', 'bot_mine', '2026-08-01 10:00:00');
        $this->message('m_old', 'c_old', 'user', '2026-08-01 10:00:01');
        $this->conversation('c_x', 'bot_other', '2026-09-16 10:00:00');
        $this->message('m_x', 'c_x', 'user', '2026-09-16 10:00:01');

        $report = $this->report();
        $kpis = $report['kpis'];

        $this->assertSame(2, $kpis['conversations']);
        $this->assertSame(3, $kpis['visitor_messages']);
        $this->assertSame(3, $kpis['replies']);
        $this->assertSame(150, $kpis['tokens']);
        $this->assertSame(1, $kpis['flagged']);
        $this->assertEqualsWithDelta(0.5, $kpis['bounce_rate'], 0.001);
        // Two searches, one of which found something.
        $this->assertEqualsWithDelta(0.5, $kpis['answer_rate'], 0.001);
        $this->assertSame(800, $kpis['first_token_median']);
        $this->assertSame(4000, $kpis['response_p95']);

        $this->assertSame(['Handbook', 1], [$report['cited']['src_1']['title'], $report['cited']['src_1']['count']]);
        $this->assertSame(['Violent' => 1], $report['flag_categories']);
        $this->assertSame(['qwen3.5:4b' => 1], $report['models']['chat']);
        // Tied counts come back in no promised order.
        $this->assertEquals(['https://shop.example.com' => 1, 'preview sandbox' => 1], $report['origins']);

        // "Opening hours?" and "opening hours" are the same question.
        $this->assertSame([['text' => 'Opening hours?', 'count' => 2]], $report['top_questions']);

        // The question the sources had nothing for, with its answer.
        $this->assertCount(1, $report['gaps']);
        $this->assertSame('opening hours', $report['gaps'][0]['question']);

        // Wednesday, 09:00.
        $this->assertSame(2, $report['heatmap'][2][9]);
    }

    public function test_the_previous_window_is_counted_for_comparison(): void
    {
        $this->conversation('c1', 'bot_mine', '2026-09-08 09:00:00');
        $this->message('m1', 'c1', 'user', '2026-09-08 09:00:01');
        $this->message('m2', 'c1', 'user', '2026-09-08 09:01:00');

        $report = $this->report();

        $this->assertSame(0, $report['kpis']['visitor_messages']);
        $this->assertSame(2, $report['previous']['visitor_messages']);
    }

    public function test_hours_and_days_are_read_in_the_viewers_time_zone(): void
    {
        // 23:30 UTC on Tuesday is 07:30 on Wednesday in Kuala Lumpur.
        $this->conversation('c1', 'bot_mine', '2026-09-15 23:30:00');
        $this->message('m1', 'c1', 'user', '2026-09-15 23:30:00');

        $utc = $this->report();
        $local = $this->report(['tz' => 'Asia/Kuala_Lumpur']);

        $this->assertSame(1, $utc['heatmap'][1][23]);
        $this->assertSame(1, $local['heatmap'][2][7]);
    }

    public function test_a_custom_window_covers_the_last_day_picked_whole(): void
    {
        $this->conversation('c1', 'bot_mine', '2026-09-01 23:59:00');
        $this->message('m1', 'c1', 'user', '2026-09-01 23:59:00');

        $report = $this->report(['range' => 'custom', 'from' => '2026-08-30', 'to' => '2026-09-01']);

        $this->assertSame(1, $report['kpis']['visitor_messages']);
        $this->assertCount(3, $report['series']);
    }

    public function test_the_page_renders_with_activity(): void
    {
        $this->conversation('c1', 'bot_mine', '2026-09-16 09:00:00');
        $this->message('m1', 'c1', 'user', '2026-09-16 09:00:01', ['guard_flag' => 'Violent']);
        $this->message('m2', 'c1', 'assistant', '2026-09-16 09:00:03', ['source_kind' => 'none', 'response_ms' => 900]);

        $this->actingAs($this->viewer)->get(route('analytics.index', ['tz' => 'UTC']))
            ->assertOk()
            ->assertSee('Busy hours')
            ->assertSee('Refused: Violent')
            ->assertSee("viewTranscript('c1')", false);
    }

    public function test_the_transcript_carries_each_answers_timing(): void
    {
        $this->conversation('c1', 'bot_mine', '2026-09-16 09:00:00');
        $this->message('m1', 'c1', 'assistant', '2026-09-16 09:00:03', ['first_token_ms' => 700, 'response_ms' => 2100]);

        $this->actingAs($this->viewer)->getJson(route('logs.transcript', 'c1'))
            ->assertOk()
            ->assertJsonPath('messages.0.first_token_ms', 700)
            ->assertJsonPath('messages.0.response_ms', 2100);
    }

    public function test_a_change_is_coloured_by_whether_it_is_good_news(): void
    {
        // Last week: one conversation, no flags. This week: two, one flagged.
        $this->conversation('c_before', 'bot_mine', '2026-09-08 09:00:00');
        $this->message('m_before', 'c_before', 'user', '2026-09-08 09:00:01');
        $this->conversation('c1', 'bot_mine', '2026-09-16 09:00:00');
        $this->message('m1', 'c1', 'user', '2026-09-16 09:00:01', ['guard_flag' => 'Violent']);
        $this->conversation('c2', 'bot_mine', '2026-09-16 10:00:00');
        $this->message('m2', 'c2', 'user', '2026-09-16 10:00:01');

        $html = $this->actingAs($this->viewer)->get(route('analytics.index', ['tz' => 'UTC']))->assertOk()->getContent();

        // Flags going up is bad; conversations going up is good.
        $this->assertMatchesRegularExpression('/an-delta is-bad"[^>]*>\s*<i class="bi bi-arrow-up-short"><\/i>New/', $html);
        $this->assertMatchesRegularExpression('/an-delta is-good"[^>]*>\s*<i class="bi bi-arrow-up-short"><\/i>100%/', $html);
    }
}
