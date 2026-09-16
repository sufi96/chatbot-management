<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The endpoint list, edited from inside the bot form.
 *
 * Every action answers JSON rather than redirecting: the modal sits on top of
 * a half-filled bot form, and a redirect would throw that away.
 *
 * Platform providers (no workspace) are Admin Settings' own, and are not
 * found from here.
 */
class AiProviderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        $provider = AiProvider::create($this->validated($request) + [
            'id' => 'aip_' . Str::random(12),
            'system_id' => $activeSystem->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$provider->name} saved.",
            'provider' => $this->asJson($provider),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $provider = AiProvider::whereNotNull('system_id')->findOrFail($id);
        $this->authorizeEditor($request, $provider->system_id);

        $provider->update($this->validated($request));

        return response()->json([
            'success' => true,
            'message' => "{$provider->name} updated. Every bot on it follows.",
            'provider' => $this->asJson($provider),
        ]);
    }

    /**
     * A provider still in use is not deleted.
     *
     * Cutting the endpoint out from under a live bot would leave it unable to
     * answer, and the operator would find out from a visitor. Naming the bots
     * says exactly what to move first.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $provider = AiProvider::whereNotNull('system_id')->findOrFail($id);
        $this->authorizeEditor($request, $provider->system_id);

        $inUse = $provider->bots()->orderBy('name')->pluck('name');

        if ($inUse->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Still used by ' . $inUse->join(', ', ' and ')
                    . '. Point ' . ($inUse->count() === 1 ? 'it' : 'them')
                    . ' at another provider first.',
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
            'name.required' => 'Give it a name you will recognise, like "Office PC".',
            'base_url.required' => 'The base URL is what the engine calls, so it cannot be blank.',
        ]);

        // A local endpoint wants no key at all, and the column is not
        // nullable, so an absent key is an empty one.
        $validated['api_key'] = (string) ($validated['api_key'] ?? '');

        return $validated;
    }

    private function asJson(AiProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'base_url' => $provider->base_url,
            'api_key' => $provider->api_key,
            'label' => $provider->label(),
        ];
    }

    private function authorizeEditor(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }
}
