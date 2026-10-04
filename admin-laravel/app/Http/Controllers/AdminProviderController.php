<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\AppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Platform providers, edited from Admin Settings.
 *
 * The same shape as a workspace's providers, owned by no workspace. Model
 * jobs link to these, and a super admin may point a bot at one. Every action answers JSON because the
 * modal sits over a settings form that may not be saved yet.
 */
class AdminProviderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        AiProvider::refuseLookalike(null, $validated);

        $provider = AiProvider::create($validated + [
            'id' => 'aip_' . Str::random(12),
            'system_id' => null,
            'purposes' => ['chat'],
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$provider->name} saved.",
            'provider' => AdminSettingsController::providerJson($provider),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $provider = AiProvider::platform()->findOrFail($id);

        $validated = $this->validated($request);
        AiProvider::refuseLookalike(null, $validated, $provider->id);

        // A purpose a saved job still uses stays ticked; the job would
        // otherwise run on a provider its own list no longer offers.
        $stillUsed = [];
        foreach (isset($validated['purposes']) ? self::purposesInUse($provider) : [] as $purpose => $jobs) {
            if (!in_array($purpose, $validated['purposes'], true)) {
                $stillUsed[] = AiProvider::PURPOSES[$purpose] . ' (used by ' . implode(', ', $jobs) . ')';
            }
        }
        if ($stillUsed) {
            return response()->json([
                'success' => false,
                'message' => 'Still needed: ' . implode('; ', $stillUsed) . '. Move those to another provider first.',
            ], 409);
        }

        $provider->update($validated);

        return response()->json([
            'success' => true,
            'message' => "{$provider->name} updated. Every job on it follows.",
            'provider' => AdminSettingsController::providerJson($provider),
        ]);
    }

    /**
     * A provider a saved setting still links is not deleted.
     *
     * The job would stop working at the next request, and the first sign
     * would be a bot answering worse. Naming the jobs says what to move first.
     */
    public function destroy(string $id): JsonResponse
    {
        $provider = AiProvider::platform()->findOrFail($id);

        $inUse = collect(AdminSettingsController::usageOf($provider->id))
            ->concat($provider->bots()->orderBy('name')->pluck('name')->map(fn ($name) => "the bot {$name}"));

        if ($inUse->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Still used by ' . $inUse->join(', ', ' and ')
                    . '. Point ' . ($inUse->count() === 1 ? 'it' : 'them')
                    . ' at another provider and save first.',
            ], 409);
        }

        $name = $provider->name;
        $provider->delete();

        return response()->json(['success' => true, 'message' => "{$name} deleted."]);
    }

    /**
     * The purposes saved settings and bots use this provider for, each with
     * the jobs that use it.
     *
     * @return array<string, array<int, string>>
     */
    public static function purposesInUse(AiProvider $provider): array
    {
        $inUse = [];

        foreach (AdminSettingsController::providerLinks() as $key => $label) {
            if (AppSetting::get($key) === $provider->id) {
                $inUse[AdminSettingsController::purposeOf($key)][] = $label;
            }
        }
        foreach ($provider->bots()->orderBy('name')->pluck('name') as $bot) {
            $inUse['chat'][] = "the bot {$bot}";
        }

        return $inUse;
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            // Sometimes: a client written before purposes keeps working;
            // a new provider is then chat, and an edit keeps what it had.
            'purposes' => ['sometimes', 'array', 'min:1'],
            'purposes.*' => ['string', Rule::in(array_keys(AiProvider::PURPOSES))],
        ], [
            'name.required' => 'Give it a name you will recognise, like "DGX Spark A".',
            'base_url.required' => 'The base URL is what the engine calls, so it cannot be blank.',
            'purposes.min' => 'Tick at least one job this provider serves.',
        ]);

        // In the order of the list, without repeats.
        if (isset($validated['purposes'])) {
            $validated['purposes'] = array_values(array_intersect(array_keys(AiProvider::PURPOSES), $validated['purposes']));
        }

        $validated['api_key'] = (string) ($validated['api_key'] ?? '');
        // An unticked box sends nothing, which means the provider keeps system messages.
        $validated['merge_system_prompt'] = $request->boolean('merge_system_prompt');

        return $validated;
    }
}
