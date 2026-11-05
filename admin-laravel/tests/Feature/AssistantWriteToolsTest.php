<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\Connector;
use App\Models\ConnectorTool;
use App\Models\KbCollection;
use App\Models\KbSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\AssistantFixtures;
use Tests\TestCase;

/** The changes the console assistant can make, each with the console's own rights. */
class AssistantWriteToolsTest extends TestCase
{
    use AssistantFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorkspaces();
    }

    public function test_an_editor_switches_a_bot_off_and_a_viewer_cannot(): void
    {
        $this->assertStringContainsString('No such tool', $this->refused($this->person('viewer'), 'set_bot_active', ['bot' => 'HR Helper', 'active' => false]));
        // An editor elsewhere is offered the tool, but only a viewer of this bot's workspace.
        $mixed = $this->person('editor', 'sys_b');
        $mixed->systems()->attach('sys_a', ['role' => 'viewer']);
        $this->assertStringContainsString('role', $this->refused($mixed, 'set_bot_active', ['bot' => 'HR Helper', 'active' => false]));

        $this->assertStringContainsString('off', $this->ran($this->person('editor'), 'set_bot_active', ['bot' => 'HR Helper', 'active' => false]));
        $this->assertFalse(BotProfile::find('bot_a')->is_active);
    }

    public function test_the_console_assistant_is_only_a_super_admins_to_change(): void
    {
        $this->assertStringContainsString('No bot', $this->refused($this->person('system_admin'), 'set_bot_active',
            ['bot' => BotProfile::CONSOLE_ID, 'active' => false]));
    }

    public function test_sources_are_switched_one_by_one(): void
    {
        BotProfile::find('bot_a')->forceFill(['retrieval_enabled' => true, 'web_search_enabled' => false])->save();

        $editor = $this->person('editor');
        $text = $this->ran($editor, 'set_bot_sources', ['bot' => 'bot_a', 'web' => true, 'documents' => false]);

        $bot = BotProfile::find('bot_a');
        $this->assertTrue((bool) $bot->web_search_enabled);
        $this->assertFalse((bool) $bot->retrieval_enabled);
        $this->assertStringContainsString('web: on', $text);
        $this->assertStringContainsString('Nothing to change', $this->refused($editor, 'set_bot_sources', ['bot' => 'bot_a']));
    }

    public function test_file_settings_are_merged_and_kept_to_what_is_allowed(): void
    {
        $this->ran($this->person('editor'), 'set_file_settings', ['bot' => 'bot_a', 'files_in' => true,
            'in_types' => ['pdf', 'exe', 'PNG'], 'max_mb' => 500, 'out_formats' => ['xlsx']]);

        $files = BotProfile::find('bot_a')->fileSettings();
        $this->assertTrue($files['in_enabled']);
        $this->assertSame(['pdf', 'png'], $files['in_types']);
        $this->assertSame(50, $files['max_mb']);
        $this->assertSame(['xlsx'], $files['out_formats']);
        $this->assertFalse($files['out_enabled']);
        $this->assertSame(30, $files['retention_days']);
    }

    public function test_reindex_and_clear_cache_reach_the_engine(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        KbCollection::create(['id' => 'col_a', 'system_id' => 'sys_a', 'name' => 'HR Policies']);
        KbSource::create(['id' => 'src_a', 'collection_id' => 'col_a', 'title' => 'Leave rules', 'status' => 'failed']);
        KbCollection::create(['id' => 'col_b', 'system_id' => 'sys_b', 'name' => 'Theirs']);
        KbSource::create(['id' => 'src_b', 'collection_id' => 'col_b', 'title' => 'Prices', 'status' => 'ready']);
        $editor = $this->person('editor');

        $this->ran($editor, 'reindex_knowledge_source', ['source_id' => 'src_a']);
        $this->assertSame('pending', KbSource::find('src_a')->status);
        $this->assertStringContainsString('No knowledge source', $this->refused($editor, 'reindex_knowledge_source', ['source_id' => 'src_b']));
        $this->assertStringContainsString('Cleared', $this->ran($editor, 'clear_answer_cache', ['bot' => 'bot_a']));
    }

    public function test_connector_tools_need_a_system_admin(): void
    {
        $connector = Connector::create(['id' => 'con_a', 'system_id' => 'sys_a', 'name' => 'HRIS', 'type' => 'rest', 'base_url' => 'http://hris']);
        ConnectorTool::create(['id' => 'ct_a', 'connector_id' => $connector->id, 'name' => 'get_leave', 'method' => 'GET', 'path' => '/leave', 'enabled' => true]);

        $this->assertStringContainsString('No such tool', $this->refused($this->person('editor'), 'set_connector_tool_enabled', ['tool_id' => 'ct_a', 'enabled' => false]));
        $this->assertStringContainsString('No connector tool', $this->refused($this->person('system_admin', 'sys_b'), 'set_connector_tool_enabled', ['tool_id' => 'ct_a', 'enabled' => false]));
        $this->ran($this->person('system_admin'), 'set_connector_tool_enabled', ['tool_id' => 'get_leave', 'enabled' => false]);
        $this->assertFalse(ConnectorTool::find('ct_a')->enabled);
    }
}
