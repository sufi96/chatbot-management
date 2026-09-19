<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What a bot's widget looks like: the pictures behind the header and the
 * conversation, the chat background colour, and the shapes its launcher,
 * close button and avatar are drawn in.
 */
class BotWidgetAppearanceTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

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

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Support', 'provider_id' => 'aip_1', 'model_name' => 'llama3.2',
            'temperature' => 0.7, 'max_tokens' => 1024,
            'widget_title' => 'Support', 'widget_primary_color' => '#000000',
            'widget_position' => 'bottom-right',
        ], $overrides);
    }

    private function save(array $overrides = [])
    {
        return $this->actingAs($this->editor)
            ->withSession(['active_system_id' => 'sys_test'])
            ->put(route('bots.update', 'bot_1'), $this->payload($overrides));
    }

    public function test_a_gif_is_accepted_for_the_launcher(): void
    {
        $this->save(['launcher_icon' => UploadedFile::fake()->image('wave.gif', 64, 64)])
            ->assertSessionHasNoErrors();

        $this->assertStringEndsWith('.gif', BotProfile::find('bot_1')->launcher_icon_url);
    }

    public function test_header_and_background_pictures_are_stored(): void
    {
        $this->save([
            'header_image' => UploadedFile::fake()->image('header.jpg', 800, 200),
            'background_image' => UploadedFile::fake()->image('paper.png', 400, 800),
            'widget_background_color' => '#ECFDF5',
        ])->assertSessionHasNoErrors();

        $bot = BotProfile::find('bot_1');
        $this->assertStringContainsString('/storage/bots/backgrounds/', $bot->widget_header_image_url);
        $this->assertStringContainsString('/storage/bots/backgrounds/', $bot->widget_background_image_url);
        $this->assertSame('#ECFDF5', $bot->widget_background_color);
    }

    public function test_removing_a_picture_falls_back_to_the_colour(): void
    {
        BotProfile::find('bot_1')->update([
            'widget_header_image_url' => 'http://x/header.jpg',
            'widget_background_image_url' => 'http://x/paper.png',
        ]);

        $this->save(['remove_header_image' => '1', 'remove_background_image' => '1'])
            ->assertSessionHasNoErrors();

        $bot = BotProfile::find('bot_1');
        $this->assertNull($bot->widget_header_image_url);
        $this->assertNull($bot->widget_background_image_url);
    }

    public function test_a_blank_background_colour_keeps_the_default(): void
    {
        $this->save(['widget_background_color' => ''])->assertSessionHasNoErrors();

        $this->assertSame('#FAFAFA', BotProfile::find('bot_1')->widget_background_color);
    }

    public function test_the_header_follows_the_widget_colour_while_ticked(): void
    {
        $this->save(['header_color_matches' => '1', 'widget_header_color' => '#FF0000'])
            ->assertSessionHasNoErrors();

        $this->assertNull(BotProfile::find('bot_1')->widget_header_color);
    }

    public function test_the_header_keeps_its_own_colour_once_unticked(): void
    {
        $this->save(['widget_header_color' => '#FF0000'])->assertSessionHasNoErrors();

        $this->assertSame('#FF0000', BotProfile::find('bot_1')->widget_header_color);
    }

    public function test_the_header_text_takes_its_own_colour_and_defaults_to_white(): void
    {
        $this->save(['widget_header_text_color' => '#18181B'])->assertSessionHasNoErrors();
        $this->assertSame('#18181B', BotProfile::find('bot_1')->widget_header_text_color);

        $this->save(['widget_header_text_color' => ''])->assertSessionHasNoErrors();
        $this->assertSame('#FFFFFF', BotProfile::find('bot_1')->widget_header_text_color);
    }

    public function test_each_picture_keeps_its_opacity(): void
    {
        $this->save(['header_image_opacity' => 40, 'background_image_opacity' => 0])
            ->assertSessionHasNoErrors();

        $bot = BotProfile::find('bot_1');
        $this->assertSame(40, (int) $bot->widget_header_image_opacity);
        $this->assertSame(0, (int) $bot->widget_background_image_opacity);
    }

    public function test_an_opacity_above_full_is_refused(): void
    {
        $this->save(['header_image_opacity' => 140])->assertSessionHasErrors('header_image_opacity');
    }

    public function test_every_part_takes_a_cutout_in_a_circle(): void
    {
        $this->save([
            'launcher_shape' => 'cutout_circle',
            'close_shape' => 'cutout_ring',
            'avatar_shape' => 'cutout_circle',
        ])->assertSessionHasNoErrors();

        $bot = BotProfile::find('bot_1');
        $this->assertSame('cutout_circle', $bot->launcher_shape);
        $this->assertSame('cutout_ring', $bot->close_shape);
        $this->assertSame('cutout_circle', $bot->avatar_shape);
    }

    public function test_an_unknown_shape_is_refused(): void
    {
        $this->save(['launcher_shape' => 'hexagon'])->assertSessionHasErrors('launcher_shape');
    }
}
