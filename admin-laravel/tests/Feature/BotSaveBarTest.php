<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The save bar on the Profile and Behaviour tabs waits for a change before it
 * shows. The counting runs in the browser; these cover what the server
 * decides: hidden, tracked, or shown from the start.
 */
class BotSaveBarTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        AiProvider::create([
            'id' => 'aip_1', 'system_id' => 'sys_test', 'name' => 'Laptop Ollama',
            'base_url' => 'http://localhost:11434/v1', 'api_key' => '',
        ]);
        BotProfile::create([
            'id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Support',
            'provider_id' => 'aip_1', 'model_name' => 'llama3.2',
        ]);

        $this->editor = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $this->editor->systems()->attach('sys_test', ['role' => 'editor']);
    }

    public function test_the_profile_bar_is_tracked_and_hidden_until_a_change(): void
    {
        $this->actingAs($this->editor)
            ->get(route('bots.edit', 'bot_1'))
            ->assertOk()
            ->assertSee('data-track-form="botForm"', false)
            ->assertSee('Save Profile changes')
            ->assertSee('data-discard', false)
            ->assertSeeInOrder(['id="botFormActions"', 'hidden', 'data-unsaved-text'], false);
    }

    public function test_the_brain_bar_is_tracked_and_hidden_until_a_change(): void
    {
        $this->actingAs($this->editor)
            ->get(route('bots.brain', 'bot_1'))
            ->assertOk()
            ->assertSee('data-track-form="brainForm"', false)
            ->assertSee('Save behaviour changes')
            ->assertSeeInOrder(['id="brainFormActions"', 'hidden', 'data-unsaved-text'], false);
    }

    public function test_a_new_bot_always_shows_its_create_button(): void
    {
        $html = $this->actingAs($this->editor)
            ->get(route('bots.create'))
            ->assertOk()
            ->assertSee('Create bot profile')
            ->assertDontSee('data-track-form', false)
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="botFormActions"[^>]*\bhidden\b/', $html);
    }

    public function test_a_failed_save_shows_the_bar_straight_away(): void
    {
        $this->actingAs($this->editor)
            ->from(route('bots.edit', 'bot_1'))
            ->put(route('bots.update', 'bot_1'), ['name' => ''])
            ->assertRedirect(route('bots.edit', 'bot_1'));

        $html = $this->actingAs($this->editor)
            ->get(route('bots.edit', 'bot_1'))
            ->assertOk()
            ->assertSee('Not saved')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/id="botFormActions"[^>]*\bhidden\b/', $html);
    }
}
