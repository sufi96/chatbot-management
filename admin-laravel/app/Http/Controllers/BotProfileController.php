<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PicksProviders;
use App\Models\BotProfile;
use App\Models\System;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BotProfileController extends Controller
{
    use PicksProviders;

    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');

        if (!$activeSystem) {
            return redirect()->route('systems.index')->with('error', 'Please create or select a system workspace first.');
        }

        $bots = BotProfile::with(['system', 'provider'])->where('system_id', $activeSystem->id)->latest()->get();

        return view('bots.index', [
            'bots' => $bots,
            'activeSystem' => $activeSystem,
            'apiHost' => $this->apiHost(),
        ]);
    }

    public function create(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');

        if (!$request->user()->canManageSystem($activeSystem->id, 'editor')) {
            abort(403, 'Unauthorized. At least Editor role is required to create bot profiles.');
        }

        $providers = $this->providersFor($request->user(), $activeSystem->id);

        return view('bots.form', [
            'isEdit' => false,
            'providers' => $providers,
            'providerSystemId' => $activeSystem->id,
            'bot' => new BotProfile([
                'provider_id' => optional($providers->firstWhere('system_id', $activeSystem->id) ?? $providers->first())->id,
                'model_name' => 'llama3.2',
                'temperature' => 0.7,
                'max_tokens' => 1024,
                'widget_title' => 'Support Assistant',
                'widget_greeting' => 'Hello! How can I help you today?',
                'widget_primary_color' => '#1f2937',
                'widget_background_color' => '#FAFAFA',
                'widget_header_text_color' => '#FFFFFF',
                'widget_position' => 'bottom-right',
                'launcher_shape' => 'circle',
                'launcher_size' => 60,
                'close_shape' => 'circle',
                'close_size' => 52,
                'avatar_shape' => 'circle',
                'is_active' => true,
                'system_prompt' => 'You are a professional, helpful and courteous AI assistant.',
            ]),
            'activeSystem' => $activeSystem,
        ]);
    }

    public function store(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');

        if (!$request->user()->canManageSystem($activeSystem->id, 'editor')) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'system_prompt' => ['nullable', 'string'],
            'provider_id' => $this->providerRule($request->user()),
            'model_name' => ['required', 'string', 'max:255'],
            'widget_title' => ['required', 'string', 'max:255'],
            'widget_greeting' => ['nullable', 'string'],
            'widget_primary_color' => ['required', 'string', 'max:20'],
            'widget_header_color' => ['nullable', 'string', 'max:20'],
            'widget_header_text_color' => ['nullable', 'string', 'max:20'],
            'header_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'header_image_opacity' => ['nullable', 'integer', 'min:0', 'max:100'],
            'widget_background_color' => ['nullable', 'string', 'max:20'],
            'background_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'background_image_opacity' => ['nullable', 'integer', 'min:0', 'max:100'],
            'widget_position' => ['required', 'in:bottom-right,bottom-left'],
            'launcher_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'launcher_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit,cutout_circle,cutout_ring'],
            'launcher_size' => ['nullable', 'integer', 'min:40', 'max:160'],
            'close_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'close_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit,cutout_circle,cutout_ring'],
            'close_size' => ['nullable', 'integer', 'min:32', 'max:120'],
            'bot_avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'avatar_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit,cutout_circle,cutout_ring'],
            'is_active' => ['nullable', 'boolean'],
            'offline_mode' => ['nullable', 'in:hide,message'],
            'offline_message' => ['nullable', 'string', 'max:1000'],
            'offline_subtitle' => ['nullable', 'string', 'max:120'],
            'offline_hours' => ['nullable', 'string', 'max:160'],
            'offline_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'offline_launcher_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit,cutout_circle,cutout_ring'],
            'offline_close_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'offline_close_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit,cutout_circle,cutout_ring'],
        ] + $this->offlineStyleRules());

        $validated['id'] = $this->makeBotId($activeSystem);
        $validated['system_id'] = $activeSystem->id;
        $validated['is_active'] = $request->boolean('is_active');
        $validated['offline_mode'] = $request->input('offline_mode', 'hide');
        $validated['launcher_shape'] = $request->input('launcher_shape', 'circle');
        $validated['avatar_shape'] = $request->input('avatar_shape', 'circle');
        $validated['close_shape'] = $request->input('close_shape', 'circle');
        $validated['launcher_size'] = (int) $request->input('launcher_size', 60);
        $validated['close_size'] = (int) $request->input('close_size', 52);
        $validated['widget_background_color'] = $request->input('widget_background_color') ?: '#FAFAFA';
        // Ticked, the header follows the widget colour wherever it goes next.
        $validated['widget_header_color'] = $request->boolean('header_color_matches')
            ? null
            : ($request->input('widget_header_color') ?: null);
        $validated['widget_header_text_color'] = $request->input('widget_header_text_color') ?: '#FFFFFF';
        $validated['widget_header_image_opacity'] = (int) $request->input('header_image_opacity', 100);
        $validated['widget_background_image_opacity'] = (int) $request->input('background_image_opacity', 100);

        // Handle Launcher Icon Upload
        if ($request->hasFile('launcher_icon')) {
            $path = $request->file('launcher_icon')->store('bots/icons', 'public');
            $validated['launcher_icon_url'] = asset('storage/' . $path);
        }

        if ($request->hasFile('offline_icon')) {
            $path = $request->file('offline_icon')->store('bots/icons', 'public');
            $validated['offline_icon_url'] = asset('storage/' . $path);
        }
        if ($request->hasFile('offline_close_icon')) {
            $path = $request->file('offline_close_icon')->store('bots/icons', 'public');
            $validated['offline_close_icon_url'] = asset('storage/' . $path);
        }

        // Handle Close Button Icon Upload
        if ($request->hasFile('close_icon')) {
            $path = $request->file('close_icon')->store('bots/icons', 'public');
            $validated['close_icon_url'] = asset('storage/' . $path);
        }

        // Handle Bot Avatar Upload
        if ($request->hasFile('bot_avatar')) {
            $path = $request->file('bot_avatar')->store('bots/avatars', 'public');
            $validated['bot_avatar_url'] = asset('storage/' . $path);
        }

        // Handle Header and Chat Background Uploads
        if ($request->hasFile('header_image')) {
            $path = $request->file('header_image')->store('bots/backgrounds', 'public');
            $validated['widget_header_image_url'] = asset('storage/' . $path);
        }

        if ($request->hasFile('background_image')) {
            $path = $request->file('background_image')->store('bots/backgrounds', 'public');
            $validated['widget_background_image_url'] = asset('storage/' . $path);
        }

        $validated['offline_style'] = $this->offlineStyle($request, []);

        $bot = BotProfile::create($validated);

        return redirect()->route('bots.edit', $bot->id)->with('success', 'Bot profile created. Its embed snippet is in the Embed tab.');
    }

    public function edit(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);
        $activeSystem = view()->shared('activeSystem');

        if (!$request->user()->canManageSystem($bot->system_id, 'editor')) {
            abort(403, 'Unauthorized.');
        }

        return view('bots.form', [
            'isEdit' => true,
            'bot' => $bot,
            'activeSystem' => $activeSystem,
            'apiHost' => $this->apiHost(),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);

        if (!$request->user()->canManageSystem($bot->system_id, 'editor')) {
            abort(403, 'Unauthorized.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'widget_title' => ['required', 'string', 'max:255'],
            'widget_greeting' => ['nullable', 'string'],
            'widget_primary_color' => ['required', 'string', 'max:20'],
            'widget_header_color' => ['nullable', 'string', 'max:20'],
            'widget_header_text_color' => ['nullable', 'string', 'max:20'],
            'header_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'header_image_opacity' => ['nullable', 'integer', 'min:0', 'max:100'],
            'widget_background_color' => ['nullable', 'string', 'max:20'],
            'background_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'background_image_opacity' => ['nullable', 'integer', 'min:0', 'max:100'],
            'widget_position' => ['required', 'in:bottom-right,bottom-left'],
            'launcher_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'launcher_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit,cutout_circle,cutout_ring'],
            'launcher_size' => ['nullable', 'integer', 'min:40', 'max:160'],
            'close_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'close_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit,cutout_circle,cutout_ring'],
            'close_size' => ['nullable', 'integer', 'min:32', 'max:120'],
            'bot_avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'avatar_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit,cutout_circle,cutout_ring'],
            'is_active' => ['nullable', 'boolean'],
            'offline_mode' => ['nullable', 'in:hide,message'],
            'offline_message' => ['nullable', 'string', 'max:1000'],
            'offline_subtitle' => ['nullable', 'string', 'max:120'],
            'offline_hours' => ['nullable', 'string', 'max:160'],
            'offline_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'offline_launcher_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit,cutout_circle,cutout_ring'],
            'offline_close_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'],
            'offline_close_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit,cutout_circle,cutout_ring'],
        ] + $this->offlineStyleRules());

        $validated['is_active'] = $request->boolean('is_active');
        $validated['offline_mode'] = $request->input('offline_mode', 'hide');
        $validated['launcher_shape'] = $request->input('launcher_shape', 'circle');
        $validated['avatar_shape'] = $request->input('avatar_shape', 'circle');
        $validated['close_shape'] = $request->input('close_shape', 'circle');
        $validated['launcher_size'] = (int) $request->input('launcher_size', 60);
        $validated['close_size'] = (int) $request->input('close_size', 52);
        $validated['widget_background_color'] = $request->input('widget_background_color') ?: '#FAFAFA';
        // Ticked, the header follows the widget colour wherever it goes next.
        $validated['widget_header_color'] = $request->boolean('header_color_matches')
            ? null
            : ($request->input('widget_header_color') ?: null);
        $validated['widget_header_text_color'] = $request->input('widget_header_text_color') ?: '#FFFFFF';
        $validated['widget_header_image_opacity'] = (int) $request->input('header_image_opacity', 100);
        $validated['widget_background_image_opacity'] = (int) $request->input('background_image_opacity', 100);

        // Handle Launcher Icon Upload or Reset
        if ($request->boolean('remove_launcher_icon')) {
            $validated['launcher_icon_url'] = null;
        } elseif ($request->hasFile('launcher_icon')) {
            $path = $request->file('launcher_icon')->store('bots/icons', 'public');
            $validated['launcher_icon_url'] = asset('storage/' . $path);
        }

        if ($request->boolean('remove_offline_icon')) {
            $validated['offline_icon_url'] = null;
        } elseif ($request->hasFile('offline_icon')) {
            $path = $request->file('offline_icon')->store('bots/icons', 'public');
            $validated['offline_icon_url'] = asset('storage/' . $path);
        }

        if ($request->boolean('remove_offline_close_icon')) {
            $validated['offline_close_icon_url'] = null;
        } elseif ($request->hasFile('offline_close_icon')) {
            $path = $request->file('offline_close_icon')->store('bots/icons', 'public');
            $validated['offline_close_icon_url'] = asset('storage/' . $path);
        }

        // Handle Close Button Icon Upload or Reset
        if ($request->boolean('remove_close_icon')) {
            $validated['close_icon_url'] = null;
        } elseif ($request->hasFile('close_icon')) {
            $path = $request->file('close_icon')->store('bots/icons', 'public');
            $validated['close_icon_url'] = asset('storage/' . $path);
        }

        // Handle Bot Avatar Upload or Reset
        if ($request->boolean('remove_bot_avatar')) {
            $validated['bot_avatar_url'] = null;
        } elseif ($request->hasFile('bot_avatar')) {
            $path = $request->file('bot_avatar')->store('bots/avatars', 'public');
            $validated['bot_avatar_url'] = asset('storage/' . $path);
        }

        // Handle Header and Chat Background Uploads or Reset
        if ($request->boolean('remove_header_image')) {
            $validated['widget_header_image_url'] = null;
        } elseif ($request->hasFile('header_image')) {
            $path = $request->file('header_image')->store('bots/backgrounds', 'public');
            $validated['widget_header_image_url'] = asset('storage/' . $path);
        }

        if ($request->boolean('remove_background_image')) {
            $validated['widget_background_image_url'] = null;
        } elseif ($request->hasFile('background_image')) {
            $path = $request->file('background_image')->store('bots/backgrounds', 'public');
            $validated['widget_background_image_url'] = asset('storage/' . $path);
        }

        $validated['offline_style'] = $this->offlineStyle($request, $bot->offline_style ?? []);

        $bot->update($validated);

        return redirect()->route('bots.edit', $bot->id)->with('success', 'Bot profile updated successfully!');
    }

    public function destroy(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);

        if (!$request->user()->canManageSystem($bot->system_id, 'system_admin')) {
            abort(403, 'Unauthorized. Only System Admin can delete bot profiles.');
        }

        // The console's own assistant is part of the platform, not a workspace's to remove.
        abort_if($bot->is_platform, 403, 'The console assistant cannot be deleted.');

        // The dialog only unlocks on an exact match; this holds it to that for
        // a request that did not come through the dialog.
        if ($request->input('confirm_name') !== $bot->name) {
            return back()->with('error', 'The name typed did not match, so the bot profile was not deleted.');
        }

        // Marks the bot only. It stops answering and leaves this workspace, and
        // a super admin can restore or erase it from the Bots page. Everyone
        // else is told it is gone for good, which for them it is.
        $bot->delete();

        return redirect()->route('bots.index')->with('success', $request->user()->isSuperAdmin()
            ? "{$bot->name} was deleted. It can be restored from Bots under Admin settings."
            : "{$bot->name} was deleted permanently.");
    }

    public function embed(Request $request, string $id)
    {
        $bot = BotProfile::with('system')->findOrFail($id);

        if (!$request->user()->canManageSystem($bot->system_id, 'viewer')) {
            abort(403, 'Unauthorized.');
        }

        return $request->user()->canManageSystem($bot->system_id, 'editor')
            ? redirect()->route('bots.edit', $bot->id)
            : redirect()->route('bots.index');
    }

    /**
     * Builds a readable id such as "support_desk_chat_01": the workspace name
     * slugged, then a number that counts up within that workspace.
     *
     * The id column holds 36 characters, so the slug is capped to leave room
     * for the suffix. Existing profiles keep the ids they were created with,
     * because embed snippets already deployed on customer sites point at them.
     */
    private const OFFLINE_STYLE_TEXT = ['title', 'position', 'header_bg', 'header_text', 'body_bg', 'body_text', 'footer_bg', 'footer_text',
                                        'avatar_source', 'avatar_emoji', 'avatar_shape'];
    private const OFFLINE_STYLE_OPACITY = ['header_image_opacity', 'body_image_opacity', 'footer_image_opacity'];
    private const OFFLINE_STYLE_FILES = ['header_image' => 'offline_header_image', 'body_image' => 'offline_body_image',
                                         'footer_image' => 'offline_footer_image', 'avatar_image' => 'offline_avatar'];

    private function offlineStyleRules(): array
    {
        $rules = [
            'offline_style' => ['nullable', 'array'],
            'offline_style.title' => ['nullable', 'string', 'max:100'],
            'offline_style.position' => ['nullable', 'in:bottom-right,bottom-left'],
            'offline_style.avatar_source' => ['nullable', 'in:chat,image,text'],
            'offline_style.avatar_emoji' => ['nullable', 'string', 'max:16'],
            'offline_style.avatar_shape' => ['nullable', 'in:rounded,circle,circle_transparent,transparent_fit'],
        ];
        foreach (['header', 'body', 'footer'] as $part) {
            $rules["offline_style.{$part}_bg"] = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];
            $rules["offline_style.{$part}_text"] = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];
            $rules["offline_style.{$part}_image_opacity"] = ['nullable', 'integer', 'min:0', 'max:100'];
        }
        foreach (self::OFFLINE_STYLE_FILES as $field) {
            $rules[$field] = ['nullable', 'image', 'mimes:png,jpg,jpeg,gif,svg,webp', 'max:2048'];
        }
        return $rules;
    }

    /** The offline notice's look: the posted text, colours and choices, plus any new or removed pictures. */
    private function offlineStyle(Request $request, array $style): array
    {
        foreach (self::OFFLINE_STYLE_TEXT as $key) {
            $style[$key] = $request->input("offline_style.$key") ?: null;
        }
        foreach (self::OFFLINE_STYLE_OPACITY as $key) {
            $style[$key] = $request->filled("offline_style.$key") ? (int) $request->input("offline_style.$key") : null;
        }
        foreach (self::OFFLINE_STYLE_FILES as $key => $field) {
            if ($request->boolean("remove_$field")) {
                $style[$key] = null;
            } elseif ($request->hasFile($field)) {
                $style[$key] = asset('storage/' . $request->file($field)->store('bots/offline', 'public'));
            }
        }
        return $style;
    }

    private function makeBotId(System $system): string
    {
        $slug = Str::slug($system->name, '_');

        // Trim to fit the id column, but only on a word boundary: a name cut
        // mid-word reads worse than a shorter one.
        if (strlen($slug) > 24) {
            $kept = '';
            foreach (explode('_', $slug) as $word) {
                $next = $kept === '' ? $word : $kept . '_' . $word;
                if (strlen($next) > 24) {
                    break;
                }
                $kept = $next;
            }
            $slug = $kept !== '' ? $kept : substr($slug, 0, 24);
        }

        $slug = $slug !== '' ? $slug : 'workspace';

        $n = BotProfile::where('system_id', $system->id)->count() + 1;

        do {
            $candidate = $slug . '_chat_' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $n++;
        } while (BotProfile::whereKey($candidate)->exists());

        return $candidate;
    }

    private function apiHost(): string
    {
        return env('API_HOST_URL', 'http://localhost:8000');
    }
}
