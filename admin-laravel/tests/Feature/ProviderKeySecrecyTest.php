<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A provider's API key is written once and never read back by a browser.
 * Fetch models and Test inference name a provider; the portal looks the key
 * up and calls the engine itself.
 */
class ProviderKeySecrecyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-live-very-secret-123';

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'Default', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        AiProvider::create([
            'id' => 'aip_hosted', 'system_id' => 'sys_test', 'name' => 'Mireld AI',
            'base_url' => 'https://api.mireld.my/v1', 'api_key' => self::KEY,
        ]);
        AiProvider::create([
            'id' => 'aip_platform', 'system_id' => null, 'name' => 'Platform Spark',
            'base_url' => 'http://spark:8000/v1', 'api_key' => 'sk-platform-secret',
        ]);
        BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support',
            'provider_id' => 'aip_hosted', 'model_name' => 'deepseek-v4-flash',
        ]);
    }

    private function member(string $role, string $systemId = 'sys_test'): User
    {
        $user = User::create([
            'name' => $role, 'email' => "{$role}.{$systemId}@example.test",
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach($systemId, ['role' => $role]);

        return $user;
    }

    private function fakeEngine(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'models' => ['deepseek-v4-flash'], 'count' => 1, 'message' => 'ok'], 200)]);
    }

    public function test_the_bot_form_never_contains_the_key(): void
    {
        $this->actingAs($this->member('editor'))
            ->get(route('bots.brain', 'bot_1'))
            ->assertOk()
            ->assertDontSee(self::KEY)
            ->assertDontSee('data-api-key', false)
            ->assertSee('data-has-key="1"', false);
    }

    public function test_saving_a_provider_does_not_send_the_key_back(): void
    {
        $response = $this->actingAs($this->member('editor'))
            ->putJson(route('providers.update', 'aip_hosted'), [
                'name' => 'Mireld AI', 'base_url' => 'https://api.mireld.my/v1', 'api_key' => 'sk-new-key',
            ])
            ->assertOk();

        $this->assertStringNotContainsString('sk-new-key', $response->getContent());
        $this->assertTrue($response->json('provider.has_key'));
    }

    public function test_a_blank_key_on_edit_keeps_the_saved_one(): void
    {
        $this->actingAs($this->member('editor'))
            ->putJson(route('providers.update', 'aip_hosted'), [
                'name' => 'Mireld (renamed)', 'base_url' => 'https://api.mireld.my/v1', 'api_key' => '',
            ])
            ->assertOk();

        $this->assertSame(self::KEY, AiProvider::find('aip_hosted')->api_key);
    }

    public function test_the_saved_key_can_be_removed_on_purpose(): void
    {
        $this->actingAs($this->member('editor'))
            ->putJson(route('providers.update', 'aip_hosted'), [
                'name' => 'Mireld AI', 'base_url' => 'https://api.mireld.my/v1', 'api_key' => '', 'clear_api_key' => true,
            ])
            ->assertOk()
            ->assertJsonPath('provider.has_key', false);

        $this->assertSame('', AiProvider::find('aip_hosted')->api_key);
    }

    public function test_test_inference_uses_the_stored_key_on_the_server(): void
    {
        $this->fakeEngine();

        $this->actingAs($this->member('editor'))
            ->postJson(route('providers.test'), ['provider_id' => 'aip_hosted', 'model_name' => 'deepseek-v4-flash'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/api/v1/bot/test-connection')
            && $request['base_url'] === 'https://api.mireld.my/v1'
            && $request['api_key'] === self::KEY
            && $request['model_name'] === 'deepseek-v4-flash'
            && $request->hasHeader('X-Admin-Token'));
    }

    public function test_fetch_models_uses_the_stored_key_on_the_server(): void
    {
        $this->fakeEngine();

        $this->actingAs($this->member('editor'))
            ->postJson(route('providers.models'), ['provider_id' => 'aip_hosted'])
            ->assertOk()
            ->assertJsonPath('models.0', 'deepseek-v4-flash');

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/api/v1/bot/fetch-models')
            && $request['api_key'] === self::KEY);
    }

    public function test_an_unsaved_draft_is_tested_with_what_was_typed(): void
    {
        $this->fakeEngine();

        $this->actingAs($this->member('editor'))
            ->postJson(route('providers.models'), [
                'system_id' => 'sys_test', 'base_url' => 'https://api.groq.com/openai/v1', 'api_key' => 'gsk_typed',
            ])
            ->assertOk();

        Http::assertSent(fn (HttpRequest $request) => $request['base_url'] === 'https://api.groq.com/openai/v1'
            && $request['api_key'] === 'gsk_typed');
    }

    public function test_a_draft_of_a_saved_provider_with_a_blank_key_uses_the_saved_key(): void
    {
        $this->fakeEngine();

        $this->actingAs($this->member('editor'))
            ->postJson(route('providers.models'), [
                'system_id' => 'sys_test', 'provider_id' => 'aip_hosted',
                'base_url' => 'https://api.mireld.my/v1', 'api_key' => '',
            ])
            ->assertOk();

        Http::assertSent(fn (HttpRequest $request) => $request['api_key'] === self::KEY);
    }

    public function test_another_workspace_cannot_use_the_key(): void
    {
        $this->fakeEngine();
        $outsider = $this->member('editor', 'sys_other');

        $this->actingAs($outsider)
            ->postJson(route('providers.test'), ['provider_id' => 'aip_hosted', 'model_name' => 'x'])
            ->assertNotFound();
        $this->actingAs($outsider)
            ->postJson(route('providers.models'), [
                'system_id' => 'sys_other', 'provider_id' => 'aip_hosted', 'base_url' => 'http://evil.test/v1', 'api_key' => '',
            ])
            ->assertOk();

        Http::assertNotSent(fn (HttpRequest $request) => $request['api_key'] === self::KEY);
    }

    public function test_a_viewer_cannot_test_endpoints(): void
    {
        $this->fakeEngine();
        $viewer = $this->member('viewer');

        $this->actingAs($viewer)
            ->postJson(route('providers.test'), ['provider_id' => 'aip_hosted', 'model_name' => 'x'])
            ->assertNotFound();
        $this->actingAs($viewer)
            ->postJson(route('providers.models'), ['system_id' => 'sys_test', 'base_url' => 'http://internal/v1'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_bot_on_a_platform_provider_can_still_be_tested_by_its_editor(): void
    {
        $this->fakeEngine();
        BotProfile::find('bot_1')->update(['provider_id' => 'aip_platform']);

        $this->actingAs($this->member('editor'))
            ->postJson(route('providers.test'), ['provider_id' => 'aip_platform', 'bot_id' => 'bot_1', 'model_name' => 'qwen'])
            ->assertOk();

        Http::assertSent(fn (HttpRequest $request) => $request['api_key'] === 'sk-platform-secret');
    }

    public function test_a_bot_id_does_not_unlock_a_provider_the_bot_does_not_use(): void
    {
        $this->fakeEngine();

        $this->actingAs($this->member('editor'))
            ->postJson(route('providers.test'), ['provider_id' => 'aip_platform', 'bot_id' => 'bot_1', 'model_name' => 'qwen'])
            ->assertNotFound();

        Http::assertNothingSent();
    }
}
