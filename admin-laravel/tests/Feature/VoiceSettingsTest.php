<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use App\Support\SourceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Where speech is made (Admin settings, Voice) and what each bot does with it. */
class VoiceSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Helper']);
    }

    private function root(): User
    {
        return User::firstOrCreate(['email' => 'root@example.test'], ['name' => 'Root',
            'password' => 'password', 'global_role' => 'super_admin']);
    }

    private function editor(): User
    {
        $user = User::create(['name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user']);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'section' => 'voice', 'embedding_model' => 'nomic-embed-text', 'embedding_dimensions' => 768,
            'chunk_size' => 1800, 'chunk_overlap' => 200, 'context_char_budget' => 6000,
            'web_search_provider' => 'duckduckgo',
        ], $overrides);
    }

    private function brainPayload(array $overrides = []): array
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

    public function test_out_of_the_box_the_browser_speaks_in_the_four_microsoft_voices(): void
    {
        $this->assertSame('browser', AppSetting::get('speech_engine'));
        $this->assertSame('browser', AppSetting::get('transcribe_engine'));
        $this->assertSame('ms-MY-YasminNeural', AppSetting::get('voice_ms_female'));
        $this->assertSame('ms-MY-OsmanNeural', AppSetting::get('voice_ms_male'));
        $this->assertFalse(BotProfile::find('bot_1')->voice_output);
    }

    public function test_the_voice_page_offers_the_engines_and_the_four_voices(): void
    {
        $this->actingAs($this->root())
            ->get(route('admin.settings', 'voice'))
            ->assertOk()
            ->assertSee('name="speech_engine"', false)
            ->assertSee('name="transcribe_engine"', false)
            ->assertSee('Osman · Malaysian')
            ->assertSee('id="voice_preview_text"', false)
            ->assertSee('ms-MY-OsmanNeural')
            ->assertDontSee('Indonesian');
    }

    public function test_every_category_in_the_settings_form_can_be_saved(): void
    {
        foreach (['models', 'guard', 'security', 'chunking', 'voice', 'web-search', 'branding'] as $section) {
            $this->actingAs($this->root())
                ->get(route('admin.settings', $section))
                ->assertOk()
                ->assertSee('Save settings');
        }
    }

    public function test_azure_needs_its_region_and_key(): void
    {
        $this->actingAs($this->root())
            ->put(route('admin.settings.update'), $this->settings(['speech_engine' => 'azure']))
            ->assertRedirect(route('admin.settings', 'voice'))
            ->assertSessionHasErrors(['azure_speech_region', 'azure_speech_key']);
    }

    public function test_a_super_admin_switches_to_azure_and_renames_a_voice(): void
    {
        $this->actingAs($this->root())
            ->put(route('admin.settings.update'), $this->settings([
                'speech_engine' => 'azure', 'azure_speech_region' => 'southeastasia', 'azure_speech_key' => 'k1',
                'voice_en_female' => 'en-GB-SoniaNeural',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('azure', AppSetting::get('speech_engine'));
        $this->assertSame('en-GB-SoniaNeural', AppSetting::get('voice_en_female'));
    }

    public function test_a_speech_server_needs_a_platform_provider(): void
    {
        $root = $this->root();

        $this->actingAs($root)
            ->put(route('admin.settings.update'), $this->settings(['speech_engine' => 'server']))
            ->assertSessionHasErrors('speech_provider_id');

        $provider = AiProvider::create(['id' => 'aip_tts', 'system_id' => null, 'name' => 'Edge TTS',
            'base_url' => 'http://localhost:5050/v1', 'api_key' => '']);

        $this->actingAs($root)
            ->put(route('admin.settings.update'), $this->settings(['speech_engine' => 'server', 'speech_provider_id' => $provider->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['Speech'], \App\Http\Controllers\AdminSettingsController::usageOf($provider->id));
    }

    public function test_another_settings_page_leaves_the_engines_alone(): void
    {
        AppSetting::put('speech_engine', 'azure');
        AppSetting::put('azure_speech_region', 'southeastasia');
        AppSetting::put('azure_speech_key', 'k1');

        $this->actingAs($this->root())
            ->put(route('admin.settings.update'), $this->settings(['section' => 'chunking',
                'azure_speech_region' => 'southeastasia', 'azure_speech_key' => 'k1']))
            ->assertSessionHasNoErrors();

        $this->assertSame('azure', AppSetting::get('speech_engine'));
    }

    public function test_a_sample_plays_through_the_portal(): void
    {
        Http::fake(['*/api/v1/voice/test' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);

        $this->actingAs($this->root())
            ->postJson(route('admin.settings.voice-test'), ['voice' => 'ms_female'])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

        Http::assertSent(fn ($request) => $request['voice'] === 'ms_female');

        $this->actingAs($this->root())
            ->postJson(route('admin.settings.voice-test'), ['voice' => 'ms_female', 'text' => 'Helo.', 'voice_name' => 'yasmin'])
            ->assertOk();

        Http::assertSent(fn ($request) => ($request['voice_name'] ?? '') === 'yasmin' && $request['text'] === 'Helo.');
    }

    public function test_a_sample_uses_the_speech_server_chosen_but_not_saved(): void
    {
        Http::fake(['*/api/v1/voice/test' => Http::response('mp3', 200, ['Content-Type' => 'audio/mpeg'])]);
        AiProvider::create(['id' => 'aip_tts', 'system_id' => null, 'name' => 'Edge TTS',
            'base_url' => 'http://localhost:5050/v1', 'api_key' => 'k']);

        $this->actingAs($this->root())
            ->postJson(route('admin.settings.voice-test'), [
                'voice' => 'en_female', 'speech_engine' => 'server', 'speech_provider_id' => 'aip_tts', 'speech_model' => 'tts-1',
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => $request['speech_engine'] === 'server'
            && $request['speech_base_url'] === 'http://localhost:5050/v1' && $request['speech_api_key'] === 'k');
        $this->assertSame('browser', AppSetting::get('speech_engine'), 'A sample saves nothing.');
    }

    public function test_the_voice_page_lists_a_speech_servers_models_and_voices(): void
    {
        Http::fake([
            '*/api/v1/kb/embedding/models' => Http::response(['ok' => true, 'models' => ['tts-1']]),
            '*/api/v1/voice/server-voices' => Http::response(['ok' => true, 'voices' => [
                ['id' => 'yasmin', 'aliases' => ['ms-MY-YasminNeural']]]]),
        ]);
        AiProvider::create(['id' => 'aip_tts', 'system_id' => null, 'name' => 'Malaysian TTS',
            'base_url' => 'http://localhost:5051/v1', 'api_key' => 'k']);

        $this->actingAs($this->root())
            ->postJson(route('admin.settings.speech-server'), ['provider_id' => 'aip_tts'])
            ->assertOk()
            ->assertExactJson(['models' => ['tts-1'], 'message' => '',
                'voices' => [['id' => 'yasmin', 'aliases' => ['ms-MY-YasminNeural']]]]);

        // The key goes from the portal to the engine, never through the browser.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/voice/server-voices')
            && $request['base_url'] === 'http://localhost:5051/v1' && $request['api_key'] === 'k');
    }

    public function test_only_a_super_admin_asks_a_speech_server(): void
    {
        AiProvider::create(['id' => 'aip_tts', 'system_id' => null, 'name' => 'Malaysian TTS',
            'base_url' => 'http://localhost:5051/v1']);

        $this->actingAs($this->editor())
            ->postJson(route('admin.settings.speech-server'), ['provider_id' => 'aip_tts'])
            ->assertForbidden();
        $this->actingAs($this->root())
            ->postJson(route('admin.settings.speech-server'), [])
            ->assertStatus(422);
    }

    public function test_a_speech_server_sample_needs_a_provider_picked(): void
    {
        $this->actingAs($this->root())
            ->postJson(route('admin.settings.voice-test'), ['voice' => 'en_female', 'speech_engine' => 'server'])
            ->assertStatus(422);
    }

    public function test_only_a_super_admin_can_play_samples(): void
    {
        $this->actingAs($this->editor())
            ->postJson(route('admin.settings.voice-test'), ['voice' => 'en_male'])
            ->assertForbidden();
    }

    public function test_an_editor_gives_a_bot_its_voice(): void
    {
        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', 'bot_1'), $this->brainPayload([
                'upgrades_present' => '1', 'voice_output' => '1', 'voice_autoplay' => '1', 'voice_input' => '1',
                'voice_gender' => 'male', 'voice_language' => 'ms',
            ]))
            ->assertSessionHasNoErrors();

        $bot = BotProfile::find('bot_1');
        $this->assertTrue($bot->voice_output);
        $this->assertTrue($bot->voice_autoplay);
        $this->assertTrue($bot->voice_input);
        $this->assertSame('male', $bot->voice_gender);
        $this->assertSame('ms', $bot->voice_language);
    }

    public function test_an_editor_hears_typed_text_in_a_voice(): void
    {
        Http::fake(['*/api/v1/voice/test' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg'])]);
        AppSetting::put('speech_engine', 'azure');

        $this->actingAs($this->editor())
            ->postJson(route('bots.brain.voice-preview', 'bot_1'), ['voice' => 'ms_male', 'text' => 'Selamat datang.'])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

        Http::assertSent(fn ($request) => $request['voice'] === 'ms_male' && $request['text'] === 'Selamat datang.');
    }

    private function speechServer(): void
    {
        AiProvider::create(['id' => 'aip_tts', 'system_id' => null, 'name' => 'Malaysian TTS',
            'base_url' => 'http://localhost:5051/v1']);
        AppSetting::put('speech_provider_id', 'aip_tts');
    }

    public function test_a_bot_speaks_with_the_speech_server_while_the_install_uses_browsers(): void
    {
        $this->speechServer();

        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', 'bot_1'), $this->brainPayload([
                'upgrades_present' => '1', 'voice_output' => '1', 'voice_engine' => 'server',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('server', BotProfile::find('bot_1')->voice_engine);
        $this->assertSame('browser', AppSetting::get('speech_engine'), 'The install keeps its own default.');
    }

    public function test_a_bot_cannot_pick_an_engine_the_install_has_not_set_up(): void
    {
        $this->actingAs($this->editor())
            ->put(route('bots.brain.update', 'bot_1'), $this->brainPayload([
                'upgrades_present' => '1', 'voice_engine' => 'server',
            ]))
            ->assertSessionHasErrors('voice_engine');

        $this->assertSame('default', BotProfile::find('bot_1')->voice_engine);
    }

    public function test_the_voice_card_offers_only_the_engines_set_up(): void
    {
        $editor = $this->editor();
        $this->actingAs($editor)
            ->get(route('bots.brain', 'bot_1'))
            ->assertOk()
            ->assertSee('Default settings by Admin (Visitor&#039;s browser)', false)
            ->assertDontSee('<option value="server"', false);

        $this->speechServer();
        $this->actingAs($editor)
            ->get(route('bots.brain', 'bot_1'))
            ->assertSee('<option value="server"', false);
    }

    public function test_a_preview_uses_the_engine_the_card_shows(): void
    {
        $editor = $this->editor();
        Http::fake(['*/api/v1/voice/test' => Http::response('mp3', 200, ['Content-Type' => 'audio/mpeg'])]);
        $this->speechServer();

        $this->actingAs($editor)
            ->postJson(route('bots.brain.voice-preview', 'bot_1'), ['voice' => 'ms_female', 'text' => 'Helo.', 'engine' => 'server'])
            ->assertOk();
        Http::assertSent(fn ($request) => $request['speech_engine'] === 'server');

        // A bot on the browser plays in the page; the engine is not asked.
        $this->actingAs($editor)
            ->postJson(route('bots.brain.voice-preview', 'bot_1'), ['voice' => 'ms_female', 'text' => 'Helo.', 'engine' => 'browser'])
            ->assertStatus(422);
    }

    public function test_a_preview_needs_text_and_a_known_voice(): void
    {
        $this->actingAs($this->editor())
            ->postJson(route('bots.brain.voice-preview', 'bot_1'), ['voice' => 'fr_female', 'text' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['voice', 'text']);
    }

    public function test_someone_outside_the_workspace_cannot_preview(): void
    {
        $stranger = User::create(['name' => 'Other', 'email' => 'other@example.test',
            'password' => 'password', 'global_role' => 'user']);

        $this->actingAs($stranger)
            ->postJson(route('bots.brain.voice-preview', 'bot_1'), ['voice' => 'en_female', 'text' => 'Hi'])
            ->assertForbidden();
    }

    public function test_the_left_cards_fold_with_safety_last(): void
    {
        $html = $this->actingAs($this->editor())->get(route('bots.brain', 'bot_1'))->assertOk()->getContent();

        $order = ['bcard-system-prompt', 'bcard-generation', 'bcard-sources', 'bcard-web-search',
            'bcard-cache', 'bcard-voice', 'bcard-safety'];
        $positions = array_map(fn ($id) => strpos($html, 'id="' . $id . '"'), $order);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Cards are out of order.');

        // Open on a first visit: the prompt and generation only.
        $this->assertStringContainsString('class="collapse show" id="bcard-system-prompt"', $html);
        $this->assertStringContainsString('class="collapse show" id="bcard-generation"', $html);
        $this->assertStringContainsString('class="collapse" id="bcard-sources"', $html);
        $this->assertStringContainsString('class="collapse" id="bcard-safety"', $html);
        $this->assertStringContainsString('id="voicePreview"', $html);
    }

    public function test_the_behaviour_page_offers_voice(): void
    {
        $this->actingAs($this->editor())
            ->get(route('bots.brain', 'bot_1'))
            ->assertOk()
            ->assertSee('name="voice_output"', false)
            ->assertSee('name="voice_input"', false)
            ->assertSee("Visitor's browser");
    }
}
