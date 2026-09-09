<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\System;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BotProfileController extends Controller
{
    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');

        if (!$activeSystem) {
            return redirect()->route('systems.index')->with('error', 'Please create or select a system workspace first.');
        }

        $bots = BotProfile::with('system')->where('system_id', $activeSystem->id)->latest()->get();

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

        return view('bots.form', [
            'isEdit' => false,
            'bot' => new BotProfile([
                'provider_type' => 'ollama',
                'base_url' => 'http://localhost:11434/v1',
                'model_name' => 'llama3.2',
                'temperature' => 0.7,
                'max_tokens' => 1024,
                'widget_title' => 'Support Assistant',
                'widget_greeting' => 'Hello! How can I help you today?',
                'widget_primary_color' => '#1f2937',
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
            'provider_type' => ['required', 'in:ollama,custom'],
            'base_url' => ['required', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'model_name' => ['required', 'string', 'max:255'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['required', 'integer', 'min:64', 'max:8192'],
            'widget_title' => ['required', 'string', 'max:255'],
            'widget_greeting' => ['nullable', 'string'],
            'widget_primary_color' => ['required', 'string', 'max:20'],
            'widget_position' => ['required', 'in:bottom-right,bottom-left'],
            'launcher_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'launcher_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit'],
            'launcher_size' => ['nullable', 'integer', 'min:40', 'max:160'],
            'close_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'close_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit'],
            'close_size' => ['nullable', 'integer', 'min:32', 'max:120'],
            'bot_avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'avatar_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['id'] = $this->makeBotId($activeSystem);
        $validated['system_id'] = $activeSystem->id;
        $validated['is_active'] = $request->boolean('is_active');
        $validated['launcher_shape'] = $request->input('launcher_shape', 'circle');
        $validated['avatar_shape'] = $request->input('avatar_shape', 'circle');
        $validated['close_shape'] = $request->input('close_shape', 'circle');
        $validated['launcher_size'] = (int) $request->input('launcher_size', 60);
        $validated['close_size'] = (int) $request->input('close_size', 52);

        // Handle Launcher Icon Upload
        if ($request->hasFile('launcher_icon')) {
            $path = $request->file('launcher_icon')->store('bots/icons', 'public');
            $validated['launcher_icon_url'] = asset('storage/' . $path);
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
            'system_prompt' => ['nullable', 'string'],
            'provider_type' => ['required', 'in:ollama,custom'],
            'base_url' => ['required', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'model_name' => ['required', 'string', 'max:255'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['required', 'integer', 'min:64', 'max:8192'],
            'widget_title' => ['required', 'string', 'max:255'],
            'widget_greeting' => ['nullable', 'string'],
            'widget_primary_color' => ['required', 'string', 'max:20'],
            'widget_position' => ['required', 'in:bottom-right,bottom-left'],
            'launcher_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'launcher_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit'],
            'launcher_size' => ['nullable', 'integer', 'min:40', 'max:160'],
            'close_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'close_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit'],
            'close_size' => ['nullable', 'integer', 'min:32', 'max:120'],
            'bot_avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'avatar_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['launcher_shape'] = $request->input('launcher_shape', 'circle');
        $validated['avatar_shape'] = $request->input('avatar_shape', 'circle');
        $validated['close_shape'] = $request->input('close_shape', 'circle');
        $validated['launcher_size'] = (int) $request->input('launcher_size', 60);
        $validated['close_size'] = (int) $request->input('close_size', 52);

        // Handle Launcher Icon Upload or Reset
        if ($request->boolean('remove_launcher_icon')) {
            $validated['launcher_icon_url'] = null;
        } elseif ($request->hasFile('launcher_icon')) {
            $path = $request->file('launcher_icon')->store('bots/icons', 'public');
            $validated['launcher_icon_url'] = asset('storage/' . $path);
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

        $bot->update($validated);

        return redirect()->route('bots.edit', $bot->id)->with('success', 'Bot profile updated successfully!');
    }

    public function destroy(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);

        if (!$request->user()->canManageSystem($bot->system_id, 'system_admin')) {
            abort(403, 'Unauthorized. Only System Admin can delete bot profiles.');
        }

        $bot->delete();

        return redirect()->route('bots.index')->with('success', 'Bot profile deleted successfully.');
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
