<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\BotProfile;
use App\Models\KbCollection;
use App\Models\KbSource;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
        $this->assertSame('1800', AppSetting::get('chunk_size'));
    }

    public function test_chunks_carry_a_heading_path(): void
    {
        $this->assertTrue(Schema::hasColumn('kb_chunks', 'heading_path'));
    }

    public function test_a_source_carries_a_description(): void
    {
        $this->assertTrue(Schema::hasColumn('kb_sources', 'description'));

        $system = $this->makeSystem();
        KbCollection::create(['id' => 'kbc_9', 'system_id' => $system->id, 'name' => 'C']);
        $source = KbSource::create([
            'id' => 'kbs_9', 'collection_id' => 'kbc_9', 'type' => 'text',
            'title' => 'Policy', 'description' => 'Retail terms.', 'body' => 'B',
        ]);

        $this->assertSame('Retail terms.', $source->fresh()->description);
    }

    public function test_a_new_bot_starts_with_a_relevance_floor(): void
    {
        $system = $this->makeSystem();
        $bot = BotProfile::create([
            'id' => 'test_chat_03', 'system_id' => $system->id, 'name' => 'Bot',
        ])->fresh();

        // Raised from 0.01, which sat below the 0.0164 an unrelated top hit
        // scores, so weak matches were admitted on every question.
        $this->assertEqualsWithDelta(0.02, $bot->retrieval_min_score, 0.0001);
    }

    public function test_an_untouched_relevance_floor_is_raised(): void
    {
        $system = $this->makeSystem();
        BotProfile::create([
            'id' => 'test_chat_04', 'system_id' => $system->id, 'name' => 'Bot',
        ]);
        \DB::table('bot_profiles')->where('id', 'test_chat_04')
            ->update(['retrieval_min_score' => 0]);

        $migration = require database_path(
            'migrations/2026_09_10_000007_raise_the_relevance_floor.php');
        $migration->up();

        $this->assertEqualsWithDelta(
            0.01, BotProfile::find('test_chat_04')->retrieval_min_score, 0.0001);
    }

    public function test_a_chosen_relevance_floor_survives_the_migration(): void
    {
        $system = $this->makeSystem();
        BotProfile::create([
            'id' => 'test_chat_05', 'system_id' => $system->id, 'name' => 'Bot',
        ]);
        \DB::table('bot_profiles')->where('id', 'test_chat_05')
            ->update(['retrieval_min_score' => 0.05]);

        $migration = require database_path(
            'migrations/2026_09_10_000007_raise_the_relevance_floor.php');
        $migration->up();

        $this->assertEqualsWithDelta(
            0.05, BotProfile::find('test_chat_05')->retrieval_min_score, 0.0001);
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
