<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ], [
            'name.required' => 'Give it a name you will recognise, like "DGX Spark A".',
            'base_url.required' => 'The base URL is what the engine calls, so it cannot be blank.',
        ]);

        $validated['api_key'] = (string) ($validated['api_key'] ?? '');

        return $validated;
    }
}
