<?php

namespace App\Http\Controllers;

use App\Models\McpKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Keys for other AI tools to use a workspace's bots over MCP, from the
 * Connectors page. A key is shown once, when it is made; the engine checks
 * the SHA-256 kept here. Making and deleting keys is for system admins.
 */
class McpKeyController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $systemId = (string) $request->input('system_id');
        abort_unless($systemId !== '' && $request->user()->canManageSystem($systemId, 'system_admin'), 403);

        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $token = 'c4mcp_' . Str::random(40);

        McpKey::create([
            'id' => 'mk_' . Str::random(12),
            'system_id' => $systemId,
            'name' => $validated['name'],
            'token_hash' => hash('sha256', $token),
            'hint' => substr($token, -4),
        ]);

        return response()->json(['success' => true, 'token' => $token,
            'message' => 'Copy this key now. It will not be shown again.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $key = McpKey::findOrFail($id);
        abort_unless($request->user()->canManageSystem($key->system_id, 'system_admin'), 403);

        $key->delete();

        return response()->json(['success' => true, 'message' => "{$key->name} deleted. Tools using it stop working."]);
    }
}
