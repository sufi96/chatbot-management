<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatFile;
use App\Models\ChatMessage;
use App\Models\System;
use App\Models\User;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A bot's Files & visuals card, and files in transcripts. */
class FileSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper']);
        BotProfile::create(['id' => 'bot_x', 'system_id' => 'sys_other', 'name' => 'Theirs']);
    }

    private function member(string $role, string $system = 'sys_test'): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => "{$role}.{$system}@example.test",
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach($system, ['role' => $role]);

        return $user;
    }

    private function payload(array $overrides = []): array
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

    private function files(array $fields): array
    {
        return ['files_present' => 1] + $fields;
    }

    public function test_a_new_bot_has_files_off(): void
    {
        $settings = BotProfile::find('bot_1')->fileSettings();

        $this->assertFalse($settings['in_enabled']);
        $this->assertFalse($settings['out_enabled']);
        $this->assertFalse($settings['visuals']);
        $this->assertSame(30, $settings['retention_days']);
    }

    public function test_an_editor_turns_files_on(): void
    {
        $this->actingAs($this->member('editor'))
            ->put(route('bots.brain.update', 'bot_1'), $this->payload($this->files([
                'files_in_enabled' => 1, 'files_in_types' => ['pdf', 'png'], 'files_max_mb' => 5,
                'files_per_message' => 2, 'files_daily' => 40, 'files_out_enabled' => 1,
                'files_out_formats' => ['docx', 'xlsx'], 'files_out_mode' => 'own_initiative',
                'visuals_enabled' => 1, 'files_retention_days' => 7,
            ])))
            ->assertSessionHasNoErrors();

        $this->assertSame([
            'in_enabled' => true, 'in_types' => ['pdf', 'png'], 'max_mb' => 5, 'per_message' => 2,
            'daily' => 40, 'out_enabled' => true, 'out_formats' => ['docx', 'xlsx'],
            'out_mode' => 'own_initiative', 'visuals' => true, 'retention_days' => 7,
        ], BotProfile::find('bot_1')->file_settings);
    }

    public function test_a_viewer_cannot_change_them(): void
    {
        $this->actingAs($this->member('viewer'))
            ->put(route('bots.brain.update', 'bot_1'), $this->payload($this->files(['files_in_enabled' => 1])))
            ->assertForbidden();

        $this->assertNull(BotProfile::find('bot_1')->file_settings);
    }

    public function test_unknown_types_and_out_of_range_numbers_are_refused(): void
    {
        $this->actingAs($this->member('editor'))
            ->put(route('bots.brain.update', 'bot_1'), $this->payload($this->files([
                'files_in_types' => ['exe'], 'files_max_mb' => 500, 'files_retention_days' => 0,
            ])))
            ->assertSessionHasErrors(['files_in_types.0', 'files_max_mb', 'files_retention_days']);
    }

    public function test_an_older_form_leaves_them_alone(): void
    {
        BotProfile::find('bot_1')->update(['file_settings' => ['in_enabled' => true]]);

        $this->actingAs($this->member('editor'))
            ->put(route('bots.brain.update', 'bot_1'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertTrue(BotProfile::find('bot_1')->file_settings['in_enabled']);
    }

    public function test_the_files_and_vision_tab_shows_the_card(): void
    {
        $this->actingAs($this->member('editor'))
            ->get(route('bots.settings', ['bot_1', 'files']))
            ->assertOk()
            ->assertSee('Files &amp; vision', false)
            ->assertSee('name="files_in_enabled"', false);
    }

    private function aFile(string $bot): ChatFile
    {
        ChatConversation::create(['id' => "conv_{$bot}", 'bot_id' => $bot, 'session_id' => "s_{$bot}"]);
        ChatMessage::create(['id' => "msg_{$bot}", 'conversation_id' => "conv_{$bot}", 'sender' => 'user', 'content' => 'see file']);

        return ChatFile::create(['id' => "file_{$bot}", 'conversation_id' => "conv_{$bot}", 'message_id' => "msg_{$bot}",
            'bot_id' => $bot, 'direction' => 'in', 'name' => 'plan.pdf', 'mime' => 'application/pdf', 'ext' => 'pdf',
            'size' => 12, 'storage_key' => "{$bot}/x.pdf", 'sha256' => str_repeat('0', 64),
            'scan_status' => 'not_scanned', 'token' => "tok_{$bot}"]);
    }

    public function test_the_transcript_lists_a_messages_files(): void
    {
        $this->aFile('bot_1');

        $messages = $this->actingAs($this->member('viewer'))
            ->getJson(route('logs.transcript', 'conv_bot_1'))
            ->assertOk()
            ->json('messages');

        $this->assertSame('plan.pdf', $messages[0]['files'][0]['name']);
        $this->assertSame('not_scanned', $messages[0]['files'][0]['scan_status']);
        $this->assertArrayNotHasKey('token', $messages[0]['files'][0]);
        $this->assertArrayNotHasKey('text', $messages[0]['files'][0]);
    }

    public function test_a_file_downloads_through_the_portal_for_its_workspace_only(): void
    {
        $this->aFile('bot_1');
        $this->aFile('bot_x');
        Http::fake(['*/api/v1/chat/files/file_bot_1/admin' => Http::response('%PDF-1.4', 200,
            ['Content-Type' => 'application/pdf'])]);
        $viewer = $this->member('viewer');

        $this->actingAs($viewer)->get(route('logs.file', 'file_bot_1'))
            ->assertOk()
            ->assertHeader('content-disposition');
        $this->actingAs($viewer)->get(route('logs.file', 'file_bot_x'))->assertForbidden();
    }
}
