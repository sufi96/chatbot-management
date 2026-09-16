<?php

namespace App\Http\Controllers;

use App\Models\DbConnection;
use App\Services\Schema\DbQueryRunner;
use Illuminate\Http\Request;

/**
 * Where an editor tunes an annotation.
 *
 * Write a description, run the question a customer would ask, read the rows.
 * Without this, annotation is guesswork. It goes through DbQueryRunner, so
 * everything the chat path refuses is refused here identically.
 */
class DbPlaygroundController extends Controller
{
    public function show(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        if (!$activeSystem) {
            return redirect()->route('systems.index')->with('error', 'Select a workspace first.');
        }

        $this->authorizeEditor($request, $activeSystem->id);

        return view('databases.playground', [
            'activeSystem' => $activeSystem,
            'connections' => DbConnection::where('system_id', $activeSystem->id)
                ->orderBy('name')->get(),
            'result' => null,
            'sql' => '',
            'selected' => '',
        ]);
    }

    public function run(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        $validated = $request->validate([
            'connection_id' => ['required', 'string'],
            'sql' => ['required', 'string', 'max:8000'],
        ]);

        $connection = DbConnection::findOrFail($validated['connection_id']);
        abort_unless($connection->system_id === $activeSystem->id, 403);

        return view('databases.playground', [
            'activeSystem' => $activeSystem,
            'connections' => DbConnection::where('system_id', $activeSystem->id)
                ->orderBy('name')->get(),
            'result' => DbQueryRunner::run($connection, $validated['sql'], 50, 10),
            'sql' => $validated['sql'],
            'selected' => $connection->id,
        ]);
    }

    private function authorizeEditor(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }
}
