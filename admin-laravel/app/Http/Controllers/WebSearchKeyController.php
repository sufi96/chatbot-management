<?php

namespace App\Http\Controllers;

use App\Models\WebSearchKey;
use App\Services\EngineClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * A workspace's web search keys, edited from the Behaviour tab.
 *
 * JSON throughout, as the AI provider modal is: the modal sits on top of a
 * half-filled Behaviour form, and a redirect would throw that away.
 *
 * Adding, changing or deleting a key is for the workspace's system admins: a
 * key is the workspace's account with a paid provider. An editor picks from the
 * keys there are, on a bot.
 */
class WebSearchKeyController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $systemId = (string) $request->input('system_id');
        $this->authorizeAdmin($request, $systemId);

        $validated = $this->validated($request, true);
        $this->refuseDuplicateName($systemId, $validated['name']);

        $key = WebSearchKey::create($validated + [
            'id' => 'wsk_' . Str::random(12),
            'system_id' => $systemId,
        ]);

        return response()->json(['success' => true, 'message' => "{$key->name} saved.", 'key' => $key->forPicker()]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $key = WebSearchKey::findOrFail($id);
        $this->authorizeAdmin($request, $key->system_id);

        $validated = $this->validated($request, false);
        $this->refuseDuplicateName($key->system_id, $validated['name'], $key->id);

        // The form never holds the saved key, so a blank one means "keep it".
        if (($validated['api_key'] ?? '') === '') {
            unset($validated['api_key']);
        }

        $key->update($validated);

        return response()->json(['success' => true, 'message' => "{$key->name} updated. Every bot on it follows.",
            'key' => $key->fresh()->forPicker()]);
    }

    /** A key still in use is not deleted; the bots on it would lose their search. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $key = WebSearchKey::findOrFail($id);
        $this->authorizeAdmin($request, $key->system_id);

        $inUse = $key->bots()->orderBy('name')->pluck('name');
        if ($inUse->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Still used by ' . $inUse->join(', ', ' and ')
                    . '. Point ' . ($inUse->count() === 1 ? 'it' : 'them') . ' at another search first.',
            ], 409);
        }

        $name = $key->name;
        $key->delete();

        return response()->json(['success' => true, 'message' => "{$name} deleted."]);
    }

    /**
     * One real search with a key, so a typo is found before a visitor finds it.
     *
     * A draft is tested as typed. A saved key is named by id and looked up
     * here, so the browser never holds it.
     */
    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key_id' => ['nullable', 'string'],
            'system_id' => ['nullable', 'string'],
            'provider' => ['nullable', Rule::in(array_keys(WebSearchKey::PROVIDERS))],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $provider = $validated['provider'] ?? null;
        $apiKey = (string) ($validated['api_key'] ?? '');

        if (!empty($validated['key_id'])) {
            $saved = WebSearchKey::findOrFail($validated['key_id']);
            $this->authorizeAdmin($request, $saved->system_id);
            $provider ??= $saved->provider;
            if ($apiKey === '') {
                $apiKey = (string) $saved->api_key;
            }
        } else {
            $this->authorizeAdmin($request, (string) ($validated['system_id'] ?? ''));
        }

        if (!$provider || $apiKey === '') {
            return response()->json(['success' => false, 'message' => 'Choose a provider and enter its key first.']);
        }

        return response()->json(EngineClient::testWebSearch($provider, $apiKey));
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', Rule::in(array_keys(WebSearchKey::PROVIDERS))],
            'api_key' => [$creating ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'api_key.required' => 'Enter the API key from your Tavily or Brave account.',
        ]);
    }

    private function refuseDuplicateName(string $systemId, string $name, ?string $ignoreId = null): void
    {
        $taken = WebSearchKey::where('system_id', $systemId)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->contains(fn (WebSearchKey $key) => mb_strtolower(trim($key->name)) === mb_strtolower(trim($name)));

        abort_if($taken, response()->json([
            'success' => false,
            'message' => 'This workspace already has a key by that name.',
            'errors' => ['name' => ['This workspace already has a key by that name.']],
        ], 422));
    }

    private function authorizeAdmin(Request $request, string $systemId): void
    {
        abort_unless($systemId !== '' && $request->user()->canManageSystem($systemId, 'system_admin'), 403);
    }
}
