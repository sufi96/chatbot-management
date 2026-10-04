<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use App\Support\ModelPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** What each provider serves, and each picker offering only the providers and models for its job. */
class ProviderPurposeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $this->platform('aip_chat', 'Spark Chat', ['chat']);
        $this->platform('aip_embed', 'Spark Embed', ['embedding']);
        $this->platform('aip_rerank', 'Spark Rerank', ['rerank']);
        $this->platform('aip_tts', 'Malaysian TTS', ['speech']);
        $this->platform('aip_openai', 'OpenAI', ['chat', 'embedding', 'speech', 'transcription']);
    }

    private function platform(string $id, string $name, ?array $purposes): AiProvider
    {
        return AiProvider::create(['id' => $id, 'system_id' => null, 'name' => $name,
            'base_url' => "http://{$id}/v1", 'purposes' => $purposes]);
    }

    private function root(): User
    {
        return User::firstOrCreate(['email' => 'root@example.test'],
            ['name' => 'Root', 'password' => 'password', 'global_role' => 'super_admin']);
    }

    /** The options of one select on a page, by its id. */
    private function optionsOf(string $html, string $selectId): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $select = $dom->getElementById($selectId);
        $this->assertNotNull($select, "No select #{$selectId}.");
        $values = [];
        foreach ($select->getElementsByTagName('option') as $option) {
            if ($option->getAttribute('value') !== '') {
                $values[] = $option->getAttribute('value');
            }
        }
        sort($values);

        return $values;
    }

    public function test_each_admin_picker_offers_only_the_providers_serving_its_job(): void
    {
        $models = $this->actingAs($this->root())->get(route('admin.settings', 'models'))->assertOk()->getContent();
        $this->assertSame(['aip_embed', 'aip_openai'], $this->optionsOf($models, 'embedding_provider_id'));
        $this->assertSame(['aip_rerank'], $this->optionsOf($models, 'rerank_model_provider_id'));
        $this->assertSame(['aip_chat', 'aip_openai'], $this->optionsOf($models, 'intent_model_provider_id'));
        $this->assertSame(['aip_chat', 'aip_openai'], $this->optionsOf($models, 'guard_model_provider_id'));

        $voice = $this->actingAs($this->root())->get(route('admin.settings', 'voice'))->assertOk()->getContent();
        $this->assertSame(['aip_openai', 'aip_tts'], $this->optionsOf($voice, 'speech_provider_id'));
        $this->assertSame(['aip_openai'], $this->optionsOf($voice, 'transcribe_provider_id'));
    }

    public function test_a_job_cannot_be_linked_to_a_provider_that_does_not_serve_it(): void
    {
        $this->actingAs($this->root())
            ->from(route('admin.settings', 'voice'))
            ->put(route('admin.settings.update'), [
                'section' => 'voice', 'embedding_model' => 'nomic-embed-text', 'embedding_dimensions' => 768,
                'chunk_size' => 1000, 'chunk_overlap' => 100, 'context_char_budget' => 6000,
                'web_search_provider' => 'duckduckgo',
                'speech_engine' => 'server', 'speech_provider_id' => 'aip_chat',
                'rerank_model_provider_id' => 'aip_tts', 'rerank_model_name' => 'bge-reranker-v2-m3',
            ])
            ->assertSessionHasErrors(['speech_provider_id', 'rerank_model_provider_id']);

        $this->assertNotSame('aip_chat', AppSetting::get('speech_provider_id'));
    }

    public function test_a_new_provider_keeps_the_jobs_ticked_and_defaults_to_chat(): void
    {
        $this->actingAs($this->root())
            ->postJson(route('admin.providers.store'), ['name' => 'Kokoro', 'base_url' => 'http://kokoro/v1',
                'purposes' => ['speech', 'chat', 'speech']])
            ->assertOk()
            ->assertJsonPath('provider.purposes', ['chat', 'speech']);

        $this->actingAs($this->root())
            ->postJson(route('admin.providers.store'), ['name' => 'Older client', 'base_url' => 'http://old/v1'])
            ->assertOk()
            ->assertJsonPath('provider.purposes', ['chat']);

        $this->actingAs($this->root())
            ->postJson(route('admin.providers.store'), ['name' => 'Nothing', 'base_url' => 'http://none/v1', 'purposes' => []])
            ->assertStatus(422);
        $this->actingAs($this->root())
            ->postJson(route('admin.providers.store'), ['name' => 'Odd', 'base_url' => 'http://odd/v1', 'purposes' => ['images']])
            ->assertStatus(422);
    }

    public function test_a_purpose_a_job_still_uses_cannot_be_unticked(): void
    {
        AppSetting::put('speech_provider_id', 'aip_openai');
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper', 'provider_id' => 'aip_openai']);

        $this->actingAs($this->root())
            ->putJson(route('admin.providers.update', 'aip_openai'), ['name' => 'OpenAI', 'base_url' => 'http://aip_openai/v1',
                'purposes' => ['embedding']])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertSee('Text to speech (used by Speech)')
            ->assertSee('the bot Helper');

        // Dropping what nothing uses is fine.
        $this->actingAs($this->root())
            ->putJson(route('admin.providers.update', 'aip_openai'), ['name' => 'OpenAI', 'base_url' => 'http://aip_openai/v1',
                'purposes' => ['chat', 'speech']])
            ->assertOk();
        $this->assertSame(['chat', 'speech'], AiProvider::find('aip_openai')->purposes);
    }

    public function test_bots_are_offered_only_language_model_providers(): void
    {
        $page = $this->actingAs($this->root())
            ->withSession(['active_system_id' => 'sys_test'])
            ->get(route('bots.create'))
            ->assertOk();
        $page->assertSee('Spark Chat')->assertSee('OpenAI')
            ->assertDontSee('Malaysian TTS')->assertDontSee('Spark Rerank')->assertDontSee('Spark Embed');

        $this->actingAs($this->root())
            ->withSession(['active_system_id' => 'sys_test'])
            ->post(route('bots.store'), [
                'name' => 'Talker', 'provider_id' => 'aip_tts', 'model_name' => 'tts-1',
                'temperature' => 0.7, 'max_tokens' => 1024,
                'widget_title' => 'Support', 'widget_primary_color' => '#000000', 'widget_position' => 'bottom-right',
            ])
            ->assertSessionHasErrors('provider_id');
    }

    public function test_an_uncategorised_provider_serves_every_job(): void
    {
        $this->platform('aip_old', 'Old Endpoint', null);

        $this->assertTrue(AiProvider::find('aip_old')->serves('speech'));
        $models = $this->actingAs($this->root())->get(route('admin.settings', 'models'))->getContent();
        $this->assertContains('aip_old', $this->optionsOf($models, 'rerank_model_provider_id'));
    }

    public function test_a_pickers_model_list_shows_its_jobs_models(): void
    {
        Http::fake(['*/api/v1/kb/embedding/models' => Http::response(['ok' => true, 'models' => [
            'gpt-4o', 'text-embedding-3-small', 'tts-1', 'whisper-1', 'dall-e-3']])]);

        $this->actingAs($this->root())
            ->postJson(route('admin.settings.models'), ['provider_id' => 'aip_openai', 'purpose' => 'embedding'])
            ->assertOk()
            ->assertJsonPath('models', ['text-embedding-3-small'])
            ->assertJsonPath('hidden', 4);

        $this->actingAs($this->root())
            ->postJson(route('admin.settings.models'), ['provider_id' => 'aip_openai', 'purpose' => 'chat'])
            ->assertJsonPath('models', ['gpt-4o']);

        // The modal's Test asks for no job, and sees everything.
        $this->actingAs($this->root())
            ->postJson(route('admin.settings.models'), ['provider_id' => 'aip_openai'])
            ->assertJsonCount(5, 'models');
    }

    public function test_a_bots_model_list_leaves_out_other_jobs_models(): void
    {
        Http::fake(['*/api/v1/bot/fetch-models' => Http::response(['success' => true, 'count' => 3,
            'models' => ['llama3.2', 'nomic-embed-text', 'bge-reranker-v2-m3'], 'message' => 'Successfully retrieved 3 model(s).'])]);

        $this->actingAs($this->root())
            ->withSession(['active_system_id' => 'sys_test'])
            ->postJson(route('providers.models'), ['provider_id' => 'aip_chat'])
            ->assertOk()
            ->assertJsonPath('models', ['llama3.2'])
            ->assertJsonPath('count', 1)
            ->assertJsonPath('hidden', 2);
    }

    public function test_model_names_are_sorted_by_job(): void
    {
        $this->assertSame('rerank', ModelPurpose::kindOf('bge-reranker-v2-m3'));
        $this->assertSame('embedding', ModelPurpose::kindOf('BAAI/bge-m3'));
        $this->assertSame('embedding', ModelPurpose::kindOf('qwen3-embedding:0.6b'));
        $this->assertSame('speech', ModelPurpose::kindOf('gpt-4o-mini-tts'));
        $this->assertSame('speech', ModelPurpose::kindOf('kokoro'));
        $this->assertSame('transcription', ModelPurpose::kindOf('whisper-large-v3-turbo'));
        $this->assertNull(ModelPurpose::kindOf('qwen3.5:4b'));
        $this->assertNull(ModelPurpose::kindOf('llama3.2'));
        $this->assertNull(ModelPurpose::kindOf('qwen3guard-gen:0.6b'));
        $this->assertNull(ModelPurpose::kindOf('qwen3-vl:8b'));

        // A server whose names match nothing still lists them all.
        $this->assertSame(['models' => ['my-model'], 'hidden' => 0], ModelPurpose::filter(['my-model'], 'rerank'));
    }
}
