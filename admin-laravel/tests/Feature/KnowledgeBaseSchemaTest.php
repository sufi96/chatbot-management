<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\BotProfile;
use App\Models\KbCollection;
use App\Models\KbSource;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeSystem(): System
    {
        return System::create([
            'id' => 'sys_test',
            'name' => 'Test Workspace',
            'allowed_origins' => '*',
        ]);
    }

    public function test_a_collection_belongs_to_a_workspace(): void
    {
        $system = $this->makeSystem();
        $collection = KbCollection::create([
            'id' => 'kbc_1',
            'system_id' => $system->id,
            'name' => 'Refund policy',
        ]);

        $this->assertSame($system->id, $collection->system->id);
        $this->assertCount(1, $system->fresh()->kbCollections);
    }

    public function test_a_source_belongs_to_a_collection_and_defaults_to_pending(): void
    {
        $system = $this->makeSystem();
        KbCollection::create(['id' => 'kbc_1', 'system_id' => $system->id, 'name' => 'C']);

        $source = KbSource::create([
            'id' => 'kbs_1',
            'collection_id' => 'kbc_1',
            'type' => 'text',
            'title' => 'Policy',
            'body' => 'Refunds within 30 days.',
        ]);

        $this->assertSame('pending', $source->fresh()->status);
        $this->assertSame(0, $source->fresh()->chunk_count);
        $this->assertSame('kbc_1', $source->collection->id);
    }

    public function test_a_bot_reads_from_many_collections(): void
    {
        $system = $this->makeSystem();
        KbCollection::create(['id' => 'kbc_1', 'system_id' => $system->id, 'name' => 'One']);
        KbCollection::create(['id' => 'kbc_2', 'system_id' => $system->id, 'name' => 'Two']);

        $bot = BotProfile::create([
            'id' => 'test_chat_01',
            'system_id' => $system->id,
            'name' => 'Bot',
        ]);
        $bot->collections()->sync(['kbc_1', 'kbc_2']);

        $this->assertCount(2, $bot->fresh()->collections);
    }

    public function test_bot_brain_settings_have_defaults(): void
    {
        $system = $this->makeSystem();
        $bot = BotProfile::create([
            'id' => 'test_chat_02',
            'system_id' => $system->id,
            'name' => 'Bot',
        ])->fresh();

        $this->assertFalse($bot->retrieval_enabled);
        $this->assertSame('hybrid', $bot->retrieval_mode);
        $this->assertSame(5, $bot->retrieval_top_k);
        $this->assertSame(30, $bot->retrieval_candidates);
        $this->assertSame('say_unknown', $bot->retrieval_fallback);
        $this->assertSame('off', $bot->thinking_level);
        $this->assertEqualsWithDelta(1.0, $bot->top_p, 0.0001);
    }

    public function test_app_settings_read_write_and_default(): void
    {
        $this->assertSame('fallback', AppSetting::get('nothing_here', 'fallback'));

        AppSetting::put('embedding_model', 'nomic-embed-text');
        $this->assertSame('nomic-embed-text', AppSetting::get('embedding_model'));

        AppSetting::put('embedding_model', 'other-model');
        $this->assertSame('other-model', AppSetting::get('embedding_model'));
        $this->assertDatabaseCount('app_settings', 1);
    }

    public function test_app_settings_fall_back_to_the_shipped_default(): void
    {
        $this->assertSame('nomic-embed-text', AppSetting::get('embedding_model'));
        $this->assertSame('900', AppSetting::get('chunk_size'));
    }

    public function test_deleting_a_collection_removes_its_sources(): void
    {
        $system = $this->makeSystem();
        KbCollection::create(['id' => 'kbc_1', 'system_id' => $system->id, 'name' => 'C']);
        KbSource::create([
            'id' => 'kbs_1', 'collection_id' => 'kbc_1',
            'type' => 'text', 'title' => 'T', 'body' => 'B',
        ]);

        KbCollection::find('kbc_1')->delete();

        $this->assertDatabaseCount('kb_sources', 0);
    }
}
