<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
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
            'temperature' => ['required', 'numeric', 'min:0', 'max:1'],
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
            'providers' => $this->providersFor($request->user(), $bot->system_id, $bot->provider_id),
            'providerSystemId' => $bot->system_id,
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
            'provider_id' => $this->providerRule($request->user(), $bot->provider_id),
            'model_name' => ['required', 'string', 'max:255'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:1'],
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

        // The dialog only unlocks on an exact match; this holds it to that for
        // a request that did not come through the dialog.
        if ($request->input('confirm_name') !== $bot->name) {
            return back()->with('error', 'The name typed did not match, so the bot profile was not deleted.');
        }

        // Marks the bot only. It stops answering and leaves this workspace, and
        // a super admin can restore or erase it from the Bots page.
        $bot->delete();

        return redirect()->route('bots.index')->with('success', "{$bot->name} was deleted. A super admin can still restore it.");
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
    /**
     * The endpoints this user may choose from: the bot's own workspace first,
     * then other workspaces by name, then the platform's.
     *
     * The bot's current provider stays even when this user could not pick it
     * (a super admin set it), flagged so the form names it without its URL or key.
     */
    private function providersFor(User $user, string $systemId, ?string $currentId = null)
    {
        $providers = AiProvider::usableBy($user)->with('system')->withCount('bots')->get();

        if ($currentId && !$providers->contains('id', $currentId)) {
            $current = AiProvider::with('system')->withCount('bots')->find($currentId);
            if ($current) {
                $current->setAttribute('locked', true);
                $providers->push($current);
            }
        }

        return $providers->sortBy(fn (AiProvider $p) => [
            $p->system_id === $systemId ? 0 : ($p->system_id ? 1 : 2),
            $p->ownerName(),
            $p->name,
        ])->values();
    }

    /**
     * A bot may point only at an endpoint this user may use, or keep the one
     * it already has. Checking here is what stops a crafted form id from
     * borrowing a key the user was never shown.
     */
    private function providerRule(User $user, ?string $currentId = null): array
    {
        return ['required', 'string', function (string $attribute, $value, $fail) use ($user, $currentId) {
            if ($value === $currentId) {
                return;
            }
            if (!AiProvider::usableBy($user)->whereKey($value)->exists()) {
                $fail('Pick a provider from the list.');
            }
        }];
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
