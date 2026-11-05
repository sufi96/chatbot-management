<?php

namespace App\Http\Controllers;

use App\Assistant\ConsoleToken;
use App\Assistant\Lookup;
use App\Assistant\Refusal;
use App\Assistant\Registry;
use App\Models\AssistantAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The engine reaching the console assistant's tools, on behalf of the person
 * whose console token it carries. The engine's own token (engine.token) says
 * the call is from the engine; the console token says whose rights apply.
 *
 * A refusal comes back as ok:false with a sentence, not an error status, so
 * the engine hands the model a reason rather than a failure.
 */
class InternalAssistantController extends Controller
{
    public function tools(Request $request): JsonResponse
    {
        $user = ConsoleToken::resolve((string) $request->input('token'));
        if (!$user) {
            return response()->json(['ok' => false, 'error' => 'Sign in to the console again.'], 401);
        }

        $role = $user->isSuperAdmin() ? 'super_admin'
            : collect(['system_admin', 'editor', 'viewer'])->first(
                fn ($r) => Lookup::workspaces($user, $r)->isNotEmpty()) ?? 'none';

        return response()->json([
            'ok' => true,
            'user' => ['name' => $user->name, 'role' => $role, 'super_admin' => $user->isSuperAdmin()],
            'tools' => Registry::forUser($user)->map->describe()->values(),
        ]);
    }

    public function call(Request $request): JsonResponse
    {
        $user = ConsoleToken::resolve((string) $request->input('token'));
        if (!$user) {
            return response()->json(['ok' => false, 'error' => 'Sign in to the console again.'], 401);
        }

        $name = (string) $request->input('name');
        $arguments = $request->input('arguments');
        $arguments = is_array($arguments) ? $arguments : [];
        $tool = Registry::find($user, $name);
        if (!$tool) {
            return response()->json(['ok' => false, 'error' => "No such tool: {$name}."]);
        }

        try {
            $result = ['ok' => true, 'text' => $tool->run($user, $arguments)];
        } catch (Refusal $refusal) {
            $result = ['ok' => false, 'error' => $refusal->getMessage()];
        }

        if ($tool->writes) {
            AssistantAction::create([
                'user_id' => $user->id, 'tool' => $tool->name, 'arguments' => $arguments,
                'ok' => $result['ok'], 'result' => mb_substr($result['text'] ?? $result['error'], 0, 2000),
            ]);
        }

        return response()->json($result);
    }
}
