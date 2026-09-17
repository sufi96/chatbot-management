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

class ConversationListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_a', 'system_id' => 'sys_test', 'name' => 'Alpha Desk']);
        BotProfile::create(['id' => 'bot_z', 'system_id' => 'sys_test', 'name' => 'Zulu Sales']);
        BotProfile::create(['id' => 'bot_x', 'system_id' => 'sys_other', 'name' => 'Elsewhere']);

        $this->user = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $this->user->systems()->attach('sys_test', ['role' => 'editor']);
    }

    /** A conversation with its visitor's opening line and a number of turns. */
    private function conversation(string $id, string $botId, string $opening, int $turns, string $origin, string $startedAt): void
    {
        Carbon::setTestNow($startedAt);
        ChatConversation::create(['id' => $id, 'bot_id' => $botId, 'session_id' => "sess_{$id}", 'origin' => $origin]);

        for ($i = 0; $i < $turns; $i++) {
            Carbon::setTestNow(Carbon::parse($startedAt)->addSeconds($i + 1));
            ChatMessage::create([
                'id' => "{$id}_m{$i}", 'conversation_id' => $id,
                'sender' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => $i === 0 ? $opening : "Reply {$i} in {$id}",
            ]);
        }
        Carbon::setTestNow();
    }

    private function seedThree(): void
    {
        $this->conversation('conv_1', 'bot_a', 'Where is my refund?', 4, 'https://shop.example.com', '2026-09-10 09:00:00');
        $this->conversation('conv_2', 'bot_z', 'Any 50% off deals?', 2, 'https://blog.example.com', '2026-09-12 09:00:00');
        $this->conversation('conv_3', 'bot_a', 'Opening hours please', 6, '', '2026-09-11 09:00:00');
        $this->conversation('conv_x', 'bot_x', 'Where is my refund?', 2, 'https://elsewhere.test', '2026-09-13 09:00:00');
    }

    /** The conversation ids in the order the table lists them. */
    private function listed(array $query = []): array
    {
        $response = $this->actingAs($this->user)->get(route('logs.index', $query))->assertOk();

        return $response->viewData('conversations')->pluck('id')->all();
    }

    public function test_the_newest_session_comes_first_by_default(): void
    {
        $this->seedThree();

        $this->assertSame(['conv_2', 'conv_3', 'conv_1'], $this->listed());
    }

    public function test_every_column_but_the_number_sorts_both_ways(): void
    {
        $this->seedThree();

        $this->assertSame(['conv_1', 'conv_3', 'conv_2'], $this->listed(['sort' => 'started', 'dir' => 'asc']));
        $this->assertSame(['conv_2', 'conv_1', 'conv_3'], $this->listed(['sort' => 'turns', 'dir' => 'asc']));
        $this->assertSame(['conv_3', 'conv_1', 'conv_2'], $this->listed(['sort' => 'turns', 'dir' => 'desc']));
        $this->assertSame(['conv_2', 'conv_3', 'conv_1'], $this->listed(['sort' => 'opening', 'dir' => 'asc']));
        $this->assertSame(['conv_3', 'conv_2', 'conv_1'], $this->listed(['sort' => 'origin', 'dir' => 'asc']));
        $this->assertSame('conv_2', $this->listed(['sort' => 'bot', 'dir' => 'desc'])[0]);
    }

    public function test_an_unknown_sort_falls_back_to_newest_first(): void
    {
        $this->seedThree();

        $this->assertSame(['conv_2', 'conv_3', 'conv_1'], $this->listed(['sort' => 'id; drop table', 'dir' => 'sideways']));
    }

    public function test_search_reaches_messages_origin_session_and_bot_name(): void
    {
        $this->seedThree();

        $this->assertSame(['conv_1'], $this->listed(['q' => 'REFUND']));
        $this->assertSame(['conv_3'], $this->listed(['q' => 'Reply 5']));
        $this->assertSame(['conv_2'], $this->listed(['q' => 'blog.example']));
        $this->assertSame(['conv_3'], $this->listed(['q' => 'sess_conv_3']));
        $this->assertSame(['conv_2'], $this->listed(['q' => 'zulu']));
    }

    public function test_search_treats_wildcards_as_plain_text(): void
    {
        $this->seedThree();

        $this->assertSame(['conv_2'], $this->listed(['q' => '50%']));
        $this->assertSame([], $this->listed(['q' => '%%']));
    }

    public function test_search_and_bot_filter_combine(): void
    {
        $this->seedThree();

        $this->assertSame(['conv_3', 'conv_1'], $this->listed(['bots' => ['bot_a']]));
        $this->assertSame(['conv_2', 'conv_3', 'conv_1'], $this->listed(['bots' => ['bot_a', 'bot_z']]));
        $this->assertSame(['conv_1'], $this->listed(['bots' => ['bot_a'], 'q' => 'refund']));
        $this->assertSame([], $this->listed(['bots' => ['bot_z'], 'q' => 'refund']));
    }

    /**
     * Every workspace the user can open is listed, and no other: a session
     * from a workspace they are not in never shows, whatever the filter says.
     */
    public function test_sessions_come_from_every_workspace_the_user_can_see(): void
    {
        $this->seedThree();
        System::create(['id' => 'sys_third', 'name' => 'Third', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_t', 'system_id' => 'sys_third', 'name' => 'Third Bot']);
        $this->conversation('conv_t', 'bot_t', 'Hello from third', 2, '', '2026-09-14 09:00:00');
        $this->user->systems()->attach('sys_other', ['role' => 'viewer']);

        $this->assertSame(['conv_x', 'conv_2', 'conv_3', 'conv_1'], $this->listed());
        $this->assertSame(['conv_x', 'conv_1'], $this->listed(['bots' => ['bot_x', 'bot_a'], 'q' => 'refund']));
        $this->assertNotContains('conv_t', $this->listed(['bots' => ['bot_t']]));
    }

    public function test_the_picker_groups_bots_under_their_workspace(): void
    {
        $this->seedThree();
        $this->user->systems()->attach('sys_other', ['role' => 'viewer']);

        $this->actingAs($this->user)
            ->get(route('logs.index', ['bots' => ['bot_z']]))
            ->assertOk()
            ->assertSeeInOrder(['Other', 'Elsewhere', 'W', 'Alpha Desk', 'Zulu Sales'])
            ->assertSee('value="bot_z" checked', false)
            ->assertDontSee('value="bot_a" checked', false);
    }

    public function test_a_super_admin_sees_every_workspace(): void
    {
        $this->seedThree();
        $root = User::create([
            'name' => 'Root', 'email' => 'root@example.test',
            'password' => 'password', 'global_role' => 'super_admin',
        ]);

        $ids = $this->actingAs($root)->get(route('logs.index'))->assertOk()
            ->viewData('conversations')->pluck('id')->all();

        $this->assertSame(['conv_x', 'conv_2', 'conv_3', 'conv_1'], $ids);
    }

    public function test_a_transcript_outside_the_users_workspaces_is_refused(): void
    {
        $this->seedThree();

        $this->actingAs($this->user)->get(route('logs.transcript', 'conv_1'))->assertOk();
        $this->actingAs($this->user)->get(route('logs.transcript', 'conv_x'))->assertForbidden();
    }

    public function test_rows_per_page_is_chosen_from_a_fixed_list(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->conversation(sprintf('conv_%02d', $i), 'bot_a', "Question {$i}", 1, '', sprintf('2026-09-01 09:%02d:00', $i));
        }

        $this->assertCount(10, $this->listed(['per_page' => 10]));
        $this->assertCount(12, $this->listed(['per_page' => 50]));
        $this->assertCount(12, $this->listed(['per_page' => 7]), 'An unlisted size falls back to the default of 25.');
    }

    public function test_the_number_column_counts_on_from_the_page_before(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->conversation(sprintf('conv_%02d', $i), 'bot_a', "Question {$i}", 1, '', sprintf('2026-09-01 09:%02d:00', $i));
        }

        $this->actingAs($this->user)
            ->get(route('logs.index', ['per_page' => 10, 'page' => 2, 'q' => 'Question']))
            ->assertOk()
            ->assertSeeInOrder(['>11<', '>12<'], false)
            ->assertSee('q=Question', false)
            ->assertSee('per_page=10', false);
    }

    /** Gives a conversation's first answer its token counts. */
    private function tokens(string $conversationId, int $total, ?int $in, ?int $out): void
    {
        ChatMessage::where('conversation_id', $conversationId)->where('sender', 'assistant')->orderBy('created_at')->first()
            ->forceFill(['tokens_used' => $total, 'tokens_in' => $in, 'tokens_out' => $out])->save();
    }

    public function test_each_row_shows_its_tokens_in_total_and_split(): void
    {
        $this->seedThree();
        $this->tokens('conv_1', 1500, 1200, 300);
        // Saved before the split was kept: a total and no halves.
        $this->tokens('conv_2', 90, null, null);

        $response = $this->actingAs($this->user)->get(route('logs.index'))->assertOk();
        $rows = $response->viewData('conversations')->keyBy('id');

        $this->assertSame([1500, 1200, 300], [(int) $rows['conv_1']->tokens_total, (int) $rows['conv_1']->tokens_in, (int) $rows['conv_1']->tokens_out]);
        $this->assertNull($rows['conv_2']->tokens_in);
        $response->assertSeeInOrder(['1,500', '<span>in</span> 1,200', '<span>out</span> 300'], false);
    }

    public function test_the_tokens_column_sorts_both_ways(): void
    {
        $this->seedThree();
        $this->tokens('conv_1', 1500, 1200, 300);
        $this->tokens('conv_2', 90, null, null);

        $this->assertSame(['conv_1', 'conv_2', 'conv_3'], $this->listed(['sort' => 'tokens', 'dir' => 'desc']));
        $this->assertSame(['conv_3', 'conv_2', 'conv_1'], $this->listed(['sort' => 'tokens', 'dir' => 'asc']));
    }

    public function test_the_analytics_page_draws_the_same_table_for_its_bots_and_window(): void
    {
        $this->seedThree();
        Carbon::setTestNow('2026-09-12 12:00:00');

        $response = $this->actingAs($this->user)
            ->get(route('analytics.index', ['range' => '24h', 'bots' => ['bot_z'], 'q' => 'deals', 'tz' => 'UTC']))
            ->assertOk()
            ->assertSee('Sessions active in this window')
            ->assertSee('id="conversations"', false);

        $this->assertSame(['conv_2'], $response->viewData('conversations')->pluck('id')->all());
        // A search that the bots or window rule out finds nothing.
        $none = $this->actingAs($this->user)
            ->get(route('analytics.index', ['range' => '24h', 'bots' => ['bot_a'], 'tz' => 'UTC']));
        $this->assertSame([], $none->viewData('conversations')->pluck('id')->all());

        Carbon::setTestNow();
    }
}
