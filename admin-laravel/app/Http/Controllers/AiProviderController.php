<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\BotProfile;
use App\Services\EngineClient;
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
 * found from here, though a super admin may point a bot at one.
 *
 * A key goes in and never comes back out. Nothing here answers with one, and
 * the model list and inference test look the key up on the server, so the
 * browser only ever names a provider.
 */
class AiProviderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // The form names the bot's workspace, which on an edit need not be
        // the active one.
        $systemId = (string) $request->input('system_id', view()->shared('activeSystem')->id);
        $this->authorizeEditor($request, $systemId);

        $validated = $this->validated($request);
        AiProvider::refuseLookalike($systemId, $validated);

        $provider = AiProvider::create($validated + [
            'id' => 'aip_' . Str::random(12),
            'system_id' => $systemId,
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

        $validated = $this->validated($request);

        // The form never holds the saved key, so a blank one means "keep it".
        // Removing a key is its own choice.
        if ($validated['api_key'] === '' && !$request->boolean('clear_api_key')) {
            $validated['api_key'] = (string) $provider->api_key;
        }

        AiProvider::refuseLookalike($provider->system_id, $validated, $provider->id);

        $provider->update($validated);

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

    /**
     * What an endpoint publishes, for Fetch models and the modal's Test.
     *
     * A saved provider is named by id. A draft in the modal is sent as typed,
     * with its workspace; when it is an edit and the key box was left blank,
     * the saved key stands in, as it would on save.
     */
    public function models(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['nullable', 'string'],
            'bot_id' => ['nullable', 'string'],
            'system_id' => ['nullable', 'string'],
            'base_url' => ['nullable', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        if (empty($validated['base_url'])) {
            $provider = $this->reachableProvider($request, $validated['provider_id'] ?? null, $validated['bot_id'] ?? null);

            return response()->json(EngineClient::chatModels($provider->base_url, (string) $provider->api_key));
        }

        $systemId = (string) ($validated['system_id'] ?? view()->shared('activeSystem')?->id);
        $this->authorizeEditor($request, $systemId);

        $apiKey = (string) ($validated['api_key'] ?? '');
        if ($apiKey === '' && !empty($validated['provider_id'])) {
            $saved = AiProvider::whereNotNull('system_id')->find($validated['provider_id']);
            if ($saved && $request->user()->canManageSystem($saved->system_id, 'editor')) {
                $apiKey = (string) $saved->api_key;
            }
        }

        return response()->json(EngineClient::chatModels($validated['base_url'], $apiKey));
    }

    /** Test inference against a saved provider, with its key looked up here. */
    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider_id' => ['required', 'string'],
            'bot_id' => ['nullable', 'string'],
            'model_name' => ['required', 'string', 'max:255'],
        ], [
            'model_name.required' => 'Choose a model first, or fetch the list.',
        ]);

        $provider = $this->reachableProvider($request, $validated['provider_id'], $validated['bot_id'] ?? null);

        return response()->json(EngineClient::testInference(
            $provider->base_url, (string) $provider->api_key, $validated['model_name']));
    }

    /**
     * A provider this user may call: one they could pick for a bot, or the one
     * a bot they edit already points at, which a super admin may have set from
     * outside their reach. Anything else is reported as missing.
     */
    private function reachableProvider(Request $request, ?string $providerId, ?string $botId): AiProvider
    {
        $user = $request->user();
        $provider = $providerId ? AiProvider::usableBy($user)->find($providerId) : null;

        if (!$provider && $providerId && $botId) {
            $bot = BotProfile::find($botId);
            if ($bot && $bot->provider_id === $providerId && $user->canManageSystem($bot->system_id, 'editor')) {
                $provider = AiProvider::find($providerId);
            }
        }

        abort_unless($provider, 404, 'That provider is not available to you.');

        return $provider;
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
            'has_key' => $provider->api_key !== null && $provider->api_key !== '',
            'label' => $provider->label(),
            'system_id' => $provider->system_id,
            'owner' => $provider->ownerName(),
        ];
    }

    private function authorizeEditor(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }
}
