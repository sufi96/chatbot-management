<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatToolCall;
use App\Models\Connector;
use App\Models\ConnectorTool;
use App\Models\DbConnection;
use App\Models\KbCollection;
use App\Models\KbSource;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AssistantFixtures;
use Tests\TestCase;

/** What the console assistant can read and report, and to whom. */
class AssistantReadToolsTest extends TestCase
{
    use AssistantFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorkspaces();
    }

    private function conversation(string $bot, array $extra = []): ChatConversation
    {
        $conversation = ChatConversation::create(['id' => 'conv_' . $bot . count($extra), 'bot_id' => $bot, 'session_id' => 's_' . $bot]);
        $conversation->forceFill($extra)->save();
        ChatMessage::create(['id' => 'm1_' . $conversation->id, 'conversation_id' => $conversation->id, 'sender' => 'user', 'content' => 'Where is my payslip?']);
        ChatMessage::create(['id' => 'm2_' . $conversation->id, 'conversation_id' => $conversation->id, 'sender' => 'assistant',
            'content' => 'In the HR portal.', 'source_kind' => 'documents']);

        return $conversation;
    }

    public function test_whoami_and_workspaces_name_only_mine(): void
    {
        $admin = $this->person('system_admin');

        $this->assertStringContainsString('system_admin', $this->ran($admin, 'whoami'));
        $text = $this->ran($admin, 'list_workspaces');
        $this->assertStringContainsString('Alpha', $text);
        $this->assertStringNotContainsString('Bravo', $text);
        $this->assertStringContainsString('Bravo', $this->ran($this->person('super_admin'), 'list_workspaces'));
    }

    public function test_list_bots_shows_my_bots_only(): void
    {
        $text = $this->ran($this->person('viewer'), 'list_bots');

        $this->assertStringContainsString('HR Helper', $text);
        $this->assertStringNotContainsString('Sales Desk', $text);
    }

    public function test_all_or_mine_as_a_workspace_means_every_workspace_i_can_reach(): void
    {
        // Found live: qwen3 fills the optional argument with a word rather than leaving it out.
        foreach (['all', 'mine', 'All workspaces', '*'] as $word) {
            $this->assertStringContainsString('HR Helper', $this->ran($this->person('system_admin', 'sys_a'), 'list_bots', ['workspace' => $word]), $word);
            \App\Models\User::where('email', 'system_admin.sys_a@example.test')->delete();
        }
    }

    public function test_another_workspaces_bot_cannot_be_reached_by_id_or_name(): void
    {
        $admin = $this->person('system_admin');

        $this->assertStringContainsString('No bot', $this->refused($admin, 'get_bot', ['bot' => 'bot_b']));
        $this->assertStringContainsString('No bot', $this->refused($admin, 'get_bot', ['bot' => 'Sales Desk']));
        $this->assertStringContainsString('No workspace', $this->refused($admin, 'list_bots', ['workspace' => 'Bravo']));
    }

    public function test_a_name_that_matches_two_bots_asks_which(): void
    {
        BotProfile::create(['id' => 'bot_a2', 'system_id' => 'sys_a', 'name' => 'HR Helper Two']);

        $error = $this->refused($this->person('super_admin'), 'get_bot', ['bot' => 'helper']);

        $this->assertStringContainsString('More than one', $error);
        $this->assertStringContainsString('bot_a2', $error);
    }

    public function test_get_bot_reads_settings_by_name_without_secrets(): void
    {
        \App\Models\AiProvider::create(['id' => 'aip_a', 'system_id' => 'sys_a', 'name' => 'Ours', 'base_url' => 'http://llm/v1', 'api_key' => 'sk-very-secret']);
        BotProfile::find('bot_a')->forceFill(['provider_id' => 'aip_a', 'retrieval_enabled' => true,
            'file_settings' => ['in_enabled' => true, 'in_types' => ['pdf']]])->save();

        $text = $this->ran($this->person('viewer'), 'get_bot', ['bot' => 'hr helper']);

        $this->assertStringContainsString('qwen3:8b', $text);
        $this->assertStringContainsString('documents: on', $text);
        $this->assertStringContainsString('Files in: on (pdf', $text);
        $this->assertStringNotContainsString('sk-very-secret', $text);
    }

    public function test_conversations_waiting_for_a_person_and_a_transcript(): void
    {
        $this->conversation('bot_a', ['handover_requested_at' => now(), 'handover_reason' => 'Wants HR']);
        $this->conversation('bot_b', ['handover_requested_at' => now()]);
        $admin = $this->person('system_admin');

        $text = $this->ran($admin, 'list_conversations', ['waiting_for_person' => true]);
        $this->assertStringContainsString('Wants HR', $text);
        $this->assertStringNotContainsString('Sales Desk', $text);

        $transcript = $this->ran($admin, 'get_conversation', ['conversation_id' => 'conv_bot_a2']);
        $this->assertStringContainsString('Where is my payslip?', $transcript);
        $this->assertStringContainsString('No conversation', $this->refused($admin, 'get_conversation', ['conversation_id' => 'conv_bot_b1']));
    }

    public function test_failed_tool_calls_and_usage(): void
    {
        $conversation = $this->conversation('bot_a');
        ChatToolCall::create(['id' => 'tc1', 'conversation_id' => $conversation->id, 'tool_name' => 'lookup_order',
            'status' => 'failed', 'result_excerpt' => 'HTTP 500']);
        $admin = $this->person('system_admin');

        $failed = $this->ran($admin, 'failed_tool_calls');
        $this->assertStringContainsString('lookup_order', $failed);
        // Found live: the model invented a total from a list cut at 20.
        $this->assertStringStartsWith('1 failed tool call in the last 7 days (lookup_order: 1)', $failed);
        $usage = $this->ran($admin, 'usage_summary', ['days' => 7]);
        $this->assertStringContainsString('HR Helper: 1 conversation', $usage);
        $this->assertStringContainsString('documents 1', $usage);
    }

    public function test_knowledge_connectors_skills_and_databases_without_secrets(): void
    {
        KbCollection::create(['id' => 'col_a', 'system_id' => 'sys_a', 'name' => 'HR Policies']);
        KbSource::create(['id' => 'src_a', 'collection_id' => 'col_a', 'title' => 'Leave rules', 'status' => 'failed', 'error_message' => 'Empty file']);
        $connector = Connector::create(['id' => 'con_a', 'system_id' => 'sys_a', 'name' => 'HRIS', 'type' => 'rest',
            'base_url' => 'http://hris', 'auth_type' => 'bearer', 'auth_secret' => 'tok-hidden']);
        ConnectorTool::create(['id' => 'ct_a', 'connector_id' => $connector->id, 'name' => 'get_leave', 'method' => 'GET', 'path' => '/leave', 'enabled' => true]);
        Skill::create(['id' => 'sk_a', 'system_id' => 'sys_a', 'name' => 'leave-help', 'description' => 'Leave questions', 'body' => 'Steps', 'mode' => 'progressive', 'origin' => 'typed']);
        DbConnection::create(['id' => 'db_a', 'system_id' => 'sys_a', 'name' => 'Payroll', 'driver' => 'mysql',
            'host' => 'db', 'port' => 3306, 'database' => 'pay', 'username' => 'u', 'password' => 'pw-hidden']);
        $viewer = $this->person('viewer');

        $knowledge = $this->ran($viewer, 'list_knowledge');
        $this->assertStringContainsString('Leave rules', $knowledge);
        $this->assertStringContainsString('Empty file', $knowledge);
        $connectors = $this->ran($viewer, 'list_connectors');
        $this->assertStringContainsString('get_leave (ct_a): on', $connectors);
        $this->assertStringNotContainsString('tok-hidden', $connectors);
        $this->assertStringContainsString('leave-help', $this->ran($viewer, 'list_skills'));
        $databases = $this->ran($viewer, 'list_databases');
        $this->assertStringContainsString('Payroll', $databases);
        $this->assertStringNotContainsString('pw-hidden', $databases);
    }

    public function test_members_need_a_system_admin_and_users_a_super_admin(): void
    {
        $this->person('editor');

        $this->assertStringContainsString('Editor sys_a', $this->ran($this->person('system_admin'), 'list_members', ['workspace' => 'Alpha']));
        $this->assertStringContainsString('No workspace', $this->refused($this->person('system_admin', 'sys_b'), 'list_members', ['workspace' => 'Alpha']));
        $this->assertStringContainsString('No such tool', $this->refused($this->person('viewer', 'sys_b'), 'list_members', ['workspace' => 'Bravo']));
        $this->assertStringContainsString('editor.sys_a@example.test', $this->ran($this->person('super_admin'), 'list_users'));
    }
}
