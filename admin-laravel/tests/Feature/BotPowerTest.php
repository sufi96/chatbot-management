<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotPowerTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        AiProvider::create([
            'id' => 'aip_1', 'system_id' => 'sys_test', 'name' => 'Laptop Ollama',
            'base_url' => 'http://localhost:11434/v1', 'api_key' => '',
        ]);

        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    private function bot(bool $active): BotProfile
    {
        return BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support',
            'provider_id' => 'aip_1', 'model_name' => 'llama3.2', 'is_active' => $active,
        ]);
    }

    private function payload(string $isActive): array
    {
        return [
            'name' => 'Support', 'provider_id' => 'aip_1', 'model_name' => 'llama3.2',
            'temperature' => 0.7, 'max_tokens' => 1024,
            'widget_title' => 'Support', 'widget_primary_color' => '#000000',
            'widget_position' => 'bottom-right', 'is_active' => $isActive,
        ];
    }

    public function test_the_edit_page_shows_the_power_card_and_no_brain_card(): void
    {
        $editor = $this->editor();
        $this->bot(false);

        $html = $this->actingAs($editor)->get(route('bots.edit', 'bot_1'))->assertOk()->getContent();

        $this->assertStringContainsString('id="botPower"', $html);
        $this->assertMatchesRegularExpression('/value="0" id="is_active_off"\s+checked/', $html);
        $this->assertStringNotContainsString('<div class="card-header">Brain</div>', $html);
    }

    public function test_switching_off_pauses_the_bot(): void
    {
        $editor = $this->editor();
        $this->bot(true);

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->payload('0'))
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) BotProfile::find('bot_1')->is_active);
    }

    public function test_switching_on_resumes_the_bot(): void
    {
        $editor = $this->editor();
        $this->bot(false);

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->payload('1'))
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) BotProfile::find('bot_1')->is_active);
    }
}
