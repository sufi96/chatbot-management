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

    public function test_the_editor_opens_on_the_offline_state_while_the_bot_is_off(): void
    {
        $editor = $this->editor();
        $this->bot(true);

        $html = $this->actingAs($editor)->get(route('bots.edit', 'bot_1'))->getContent();
        $this->assertStringContainsString('id="appearanceOffline"', $html);
        $this->assertStringNotContainsString("showState('offline');", $html);

        BotProfile::find('bot_1')->update(['is_active' => false]);
        $html = $this->actingAs($editor)->get(route('bots.edit', 'bot_1'))->getContent();
        $this->assertStringContainsString("showState('offline');", $html);
    }

    public function test_the_offline_message_is_saved(): void
    {
        $editor = $this->editor();
        $this->bot(true);

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->payload('0') + [
                'offline_mode' => 'message', 'offline_message' => 'Back at 9am.',
                'offline_subtitle' => 'Typical reply in 4 hours', 'offline_hours' => 'Mon–Fri, 9am–6pm',
            ])
            ->assertSessionHasNoErrors();

        $bot = BotProfile::find('bot_1');
        $this->assertSame('message', $bot->offline_mode);
        $this->assertSame('Back at 9am.', $bot->offline_message);
        $this->assertSame('Typical reply in 4 hours', $bot->offline_subtitle);
        $this->assertSame('Mon–Fri, 9am–6pm', $bot->offline_hours);
    }

    public function test_the_offline_card_style_is_saved_and_its_pictures_can_be_removed(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $editor = $this->editor();
        $this->bot(true);

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->payload('0') + [
                'offline_style' => ['header_bg' => '#111111', 'body_text' => '#222222', 'avatar_source' => 'text', 'avatar_emoji' => '🌙',
                                    'avatar_shape' => 'circle', 'title' => 'Away 😴', 'position' => 'bottom-left', 'footer_image_opacity' => '0'],
                'offline_footer_image' => \Illuminate\Http\UploadedFile::fake()->image('footer.gif'),
            ])
            ->assertSessionHasNoErrors();

        $style = BotProfile::find('bot_1')->offline_style;
        $this->assertSame('#111111', $style['header_bg']);
        $this->assertSame('🌙', $style['avatar_emoji']);
        $this->assertSame('text', $style['avatar_source']);
        $this->assertSame('Away 😴', $style['title']);
        $this->assertSame('bottom-left', $style['position']);
        $this->assertSame(0, $style['footer_image_opacity']);
        $this->assertSame('circle', $style['avatar_shape']);
        $this->assertStringContainsString('bots/offline/', $style['footer_image']);

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->payload('0') + ['remove_offline_footer_image' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertNull(BotProfile::find('bot_1')->offline_style['footer_image']);

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->payload('0') + ['offline_style' => ['header_bg' => 'red']])
            ->assertSessionHasErrors('offline_style.header_bg');
    }

    public function test_the_offline_preview_starts_from_the_saved_pictures(): void
    {
        $editor = $this->editor();
        $this->bot(false)->update(['offline_style' => ['header_image' => 'http://x/head.gif', 'avatar_image' => 'http://x/pug.png']]);

        $html = $this->actingAs($editor)->get(route('bots.edit', 'bot_1'))->getContent();
        $this->assertStringContainsString('id="prevOfflineHeader" class="pv-off-head" data-own="http://x/head.gif"', $html);
        $this->assertStringContainsString('id="prevOfflineAvatarImg" data-own="http://x/pug.png"', $html);
    }

    public function test_the_offline_launcher_image_is_saved_and_can_be_removed(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $editor = $this->editor();
        $this->bot(false);

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->payload('0') + [
                'offline_mode' => 'message',
                'offline_icon' => \Illuminate\Http\UploadedFile::fake()->image('away.png'),
            ])
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('storage/bots/icons/', BotProfile::find('bot_1')->offline_icon_url);

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->payload('0') + ['offline_mode' => 'message', 'remove_offline_icon' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertNull(BotProfile::find('bot_1')->offline_icon_url);
    }

    public function test_the_offline_button_shapes_and_open_image_are_saved(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $editor = $this->editor();
        $this->bot(false);

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->payload('0') + [
                'offline_mode' => 'message',
                'offline_launcher_shape' => 'cutout_ring',
                'offline_close_shape' => 'transparent_fit',
                'offline_close_icon' => \Illuminate\Http\UploadedFile::fake()->image('x.png'),
            ])
            ->assertSessionHasNoErrors();

        $bot = BotProfile::find('bot_1');
        $this->assertSame('cutout_ring', $bot->offline_launcher_shape);
        $this->assertSame('transparent_fit', $bot->offline_close_shape);
        $this->assertStringContainsString('storage/bots/icons/', $bot->offline_close_icon_url);

        $html = $this->actingAs($editor)->get(route('bots.edit', 'bot_1'))->getContent();
        $this->assertStringContainsString('id="pane-offline"', $html);
        $this->assertMatchesRegularExpression('/id="offline_launcher_shape_cutout_ring" value="cutout_ring"\s+checked/', $html);
    }

    public function test_an_unknown_offline_shape_is_refused(): void
    {
        $editor = $this->editor();
        $this->bot(false);

        $this->actingAs($editor)
            ->put(route('bots.update', 'bot_1'), $this->payload('0') + ['offline_launcher_shape' => 'star'])
            ->assertSessionHasErrors('offline_launcher_shape');
    }
}
