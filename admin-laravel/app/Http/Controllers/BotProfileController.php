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

        $bots = BotProfile::where('system_id', $activeSystem->id)->latest()->get();

        return view('bots.index', [
            'bots' => $bots,
            'activeSystem' => $activeSystem,
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
                'widget_primary_color' => '#0d6efd',
                'widget_position' => 'bottom-right',
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
            'bot_avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'avatar_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['id'] = 'bot_' . Str::random(8);
        $validated['system_id'] = $activeSystem->id;
        $validated['is_active'] = $request->boolean('is_active');
        $validated['launcher_shape'] = $request->input('launcher_shape', 'circle');
        $validated['avatar_shape'] = $request->input('avatar_shape', 'circle');

        // Handle Launcher Icon Upload
        if ($request->hasFile('launcher_icon')) {
            $path = $request->file('launcher_icon')->store('bots/icons', 'public');
            $validated['launcher_icon_url'] = asset('storage/' . $path);
        }

        // Handle Bot Avatar Upload
        if ($request->hasFile('bot_avatar')) {
            $path = $request->file('bot_avatar')->store('bots/avatars', 'public');
            $validated['bot_avatar_url'] = asset('storage/' . $path);
        }

        $bot = BotProfile::create($validated);

        return redirect()->route('bots.embed', $bot->id)->with('success', 'Bot profile created successfully!');
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
            'bot_avatar' => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
            'avatar_shape' => ['nullable', 'string', 'in:circle,circle_transparent,transparent_fit'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['launcher_shape'] = $request->input('launcher_shape', 'circle');
        $validated['avatar_shape'] = $request->input('avatar_shape', 'circle');

        // Handle Launcher Icon Upload or Reset
        if ($request->boolean('remove_launcher_icon')) {
            $validated['launcher_icon_url'] = null;
        } elseif ($request->hasFile('launcher_icon')) {
            $path = $request->file('launcher_icon')->store('bots/icons', 'public');
            $validated['launcher_icon_url'] = asset('storage/' . $path);
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

        $apiHost = env('API_HOST_URL', 'http://localhost:8000');

        return view('bots.embed', [
            'bot' => $bot,
            'apiHost' => $apiHost,
        ]);
    }
}
