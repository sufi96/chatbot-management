<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\Connector;
use App\Models\ConnectorTool;
use App\Services\EngineClient;
use App\Support\OpenApiImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * A workspace's connectors: outside APIs and the operations its bots may call.
 *
 * The page lists them; everything else is JSON for its modals, as with web
 * search keys. A connector holds the workspace's credentials for another
 * system, so adding, changing, testing and switching tools on is for the
 * workspace's system admins.
 */
class ConnectorController extends Controller
{
    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        if (!$activeSystem) {
            return redirect()->route('systems.index')->with('error', 'Select a workspace first.');
        }

        $connectors = Connector::where('system_id', $activeSystem->id)
            ->with(['tools' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        return view('connectors.index', [
            'activeSystem' => $activeSystem,
            'connectors' => $connectors,
            'canManage' => $request->user()->canManageSystem($activeSystem->id, 'system_admin'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $systemId = (string) $request->input('system_id');
        $this->authorizeAdmin($request, $systemId);

        $validated = $this->validated($request, true);
        $this->refuseDuplicateName($systemId, $validated['name']);

        $connector = Connector::create($validated + [
            'id' => 'con_' . Str::random(12),
            'system_id' => $systemId,
        ]);

        return response()->json(['success' => true, 'message' => "{$connector->name} saved.",
            'connector' => $connector->forPage()]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $connector = Connector::findOrFail($id);
        $this->authorizeAdmin($request, $connector->system_id);

        $validated = $this->validated($request, false);
        $this->refuseDuplicateName($connector->system_id, $validated['name'], $connector->id);

        // The form never holds the saved secret, so a blank one means "keep it".
        if (($validated['auth_secret'] ?? '') === '') {
            unset($validated['auth_secret']);
        }

        $connector->update($validated);

        return response()->json(['success' => true, 'message' => "{$connector->name} updated.",
            'connector' => $connector->fresh()->forPage()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $connector = Connector::findOrFail($id);
        $this->authorizeAdmin($request, $connector->system_id);

        // Bots on it would lose their tools without a word.
        $inUse = BotProfile::whereHas('tools', fn ($q) => $q->where('connector_id', $connector->id))
            ->orderBy('name')->pluck('name');
        if ($inUse->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Still used by ' . $inUse->join(', ', ' and ')
                    . '. Take its operations off ' . ($inUse->count() === 1 ? 'that bot' : 'those bots') . ' first.',
            ], 409);
        }

        $name = $connector->name;
        $connector->delete();

        return response()->json(['success' => true, 'message' => "{$name} deleted."]);
    }

    /**
     * Whether the API answers, from the engine, which is what will call it.
     *
     * A draft is tested as typed. A saved connector is named by id and its
     * secret looked up here, so the browser never holds it.
     */
    public function test(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'connector_id' => ['nullable', 'string'],
            'system_id' => ['nullable', 'string'],
            'base_url' => ['nullable', 'url', 'max:500'],
            'auth_type' => ['nullable', Rule::in(Connector::AUTH_TYPES)],
            'auth_header' => ['nullable', 'string', 'max:120'],
            'auth_secret' => ['nullable', 'string', 'max:2000'],
        ]);

        $fields = array_filter([
            'base_url' => $validated['base_url'] ?? null,
            'auth_type' => $validated['auth_type'] ?? null,
            'auth_header' => $validated['auth_header'] ?? null,
            'auth_secret' => $validated['auth_secret'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if (!empty($validated['connector_id'])) {
            $saved = Connector::findOrFail($validated['connector_id']);
            $this->authorizeAdmin($request, $saved->system_id);
            $fields += [
                'base_url' => $saved->base_url,
                'auth_type' => $saved->auth_type,
                'auth_header' => $saved->auth_header,
                'auth_secret' => $saved->auth_secret,
            ];
        } else {
            $this->authorizeAdmin($request, (string) ($validated['system_id'] ?? ''));
        }

        if (empty($fields['base_url'])) {
            return response()->json(['success' => false, 'message' => 'Enter the API\'s base URL first.']);
        }

        return response()->json(EngineClient::testConnector($fields + ['auth_type' => 'none']));
    }

    /**
     * Tools from an OpenAPI 3 JSON spec, pasted or fetched from a URL.
     *
     * Re-importing replaces the list by name: an operation still in the spec
     * keeps whether it was switched on, one that left the spec goes.
     */
    public function import(Request $request, string $id): JsonResponse
    {
        $connector = Connector::findOrFail($id);
        $this->authorizeAdmin($request, $connector->system_id);

        $validated = $request->validate([
            'spec' => ['nullable', 'string', 'max:2000000', 'required_without:spec_url'],
            'spec_url' => ['nullable', 'url', 'max:500'],
        ]);

        $text = $validated['spec'] ?? null;
        if ($text === null) {
            try {
                $text = Http::timeout(15)->get($validated['spec_url'])->throw()->body();
            } catch (\Throwable $e) {
                return $this->refuse('Could not fetch the spec: ' . $e->getMessage());
            }
        }

        $spec = json_decode((string) $text, true);
        if (!is_array($spec)) {
            return $this->refuse('The spec must be OpenAPI 3 JSON. YAML is not supported yet.');
        }

        try {
            $imported = OpenApiImport::tools($spec);
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        DB::transaction(function () use ($connector, $imported) {
            $enabled = $connector->tools()->where('enabled', true)->pluck('name')->all();
            $connector->tools()->whereNotIn('name', array_column($imported, 'name'))->delete();

            foreach ($imported as $tool) {
                $existing = $connector->tools()->where('name', $tool['name'])->first();
                $tool['enabled'] = in_array($tool['name'], $enabled, true);
                $existing
                    ? $existing->update($tool)
                    : $connector->tools()->create($tool + ['id' => 'ctl_' . Str::random(12)]);
            }
        });

        $count = count($imported);

        return response()->json(['success' => true,
            'message' => "Imported {$count} " . Str::plural('operation', $count) . '. Switch on the ones bots may use.']);
    }

    /** Switch a tool on or off, or say whether it changes anything. */
    public function updateTool(Request $request, string $id): JsonResponse
    {
        $tool = ConnectorTool::with('connector')->findOrFail($id);
        $this->authorizeAdmin($request, $tool->connector->system_id);

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'writes' => ['sometimes', 'boolean'],
        ]);

        $tool->update($validated);

        return response()->json(['success' => true, 'message' => "{$tool->name} " . ($tool->enabled ? 'on.' : 'off.')]);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(Connector::TYPES))],
            'base_url' => ['required', 'url', 'max:500'],
            'auth_type' => ['required', Rule::in(Connector::AUTH_TYPES)],
            'auth_header' => ['nullable', 'required_if:auth_type,header', 'string', 'max:120'],
            'auth_secret' => [$creating ? 'required_unless:auth_type,none' : 'nullable', 'nullable', 'string', 'max:2000'],
        ], [
            'auth_secret.required_unless' => 'Enter the token, key or user:password this API signs in with.',
        ]);
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => ['spec' => [$message]]], 422);
    }

    private function refuseDuplicateName(string $systemId, string $name, ?string $ignoreId = null): void
    {
        $taken = Connector::where('system_id', $systemId)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get()
            ->contains(fn (Connector $c) => mb_strtolower(trim($c->name)) === mb_strtolower(trim($name)));

        abort_if($taken, response()->json([
            'success' => false,
            'message' => 'This workspace already has a connector by that name.',
            'errors' => ['name' => ['This workspace already has a connector by that name.']],
        ], 422));
    }

    private function authorizeAdmin(Request $request, string $systemId): void
    {
        abort_unless($systemId !== '' && $request->user()->canManageSystem($systemId, 'system_admin'), 403);
    }
}
