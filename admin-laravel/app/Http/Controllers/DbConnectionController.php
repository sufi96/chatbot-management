<?php

namespace App\Http\Controllers;

use App\Models\DbConnection;
use App\Services\Schema\DraftConnection;
use App\Services\Schema\SchemaIntrospector;
use App\Services\Schema\ConsoleDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DbConnectionController extends Controller
{
    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        if (!$activeSystem) {
            return redirect()->route('systems.index')
                ->with('error', 'Select a workspace first.');
        }

        return view('databases.index', [
            'activeSystem' => $activeSystem,
            'connections' => DbConnection::withCount([
                    'tables',
                    'tables as enabled_tables_count' => fn ($q) => $q->where('is_enabled', true),
                ])
                ->where('system_id', $activeSystem->id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        $validated = $this->validated($request);

        DbConnection::create($validated + [
            'id' => 'dbc_' . Str::random(12),
            'system_id' => $activeSystem->id,
            'status' => 'untested',
        ]);

        return redirect()->route('databases.index')
            ->with('success', 'Connection saved. Test it, then discover the schema.');
    }

    public function update(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);
        // The console database follows the application's own settings; its
        // address is not edited here and it is not deleted.
        abort_if(ConsoleDatabase::is($connection), 403, 'The console database cannot be changed or deleted.');

        $validated = $this->validated($request);

        // An empty password means keep the stored one. The form never shows
        // the current value, so blank has to mean "unchanged" rather than
        // "erase it".
        if (($validated['password'] ?? '') === '') {
            unset($validated['password']);
        }

        $connection->update($validated);

        return redirect()->route('databases.index')->with('success', 'Connection updated.');
    }

    public function destroy(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);
        // The console database follows the application's own settings; its
        // address is not edited here and it is not deleted.
        abort_if(ConsoleDatabase::is($connection), 403, 'The console database cannot be changed or deleted.');

        $connection->delete();

        return redirect()->route('databases.index')->with('success', 'Connection deleted.');
    }

    public function test(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);

        $result = SchemaIntrospector::test($connection);

        $connection->update([
            'status' => $result['ok'] ? 'ok' : 'failed',
            'error_message' => $result['ok'] ? null : $result['message'],
        ]);

        return back()->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'Connected.' : 'Could not connect: ' . $result['message']);
    }

    /**
     * The master switch for a whole database.
     *
     * A gate, not an eraser. Every table tick and every description survives,
     * so cutting a bot off for a week costs nobody their allowlist.
     */
    public function toggle(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);

        $connection->update(['is_enabled' => ! $connection->is_enabled]);

        return back()->with('success', $connection->is_enabled
            ? "{$connection->name} is enabled. Bots may read the tables ticked in its schema."
            : "{$connection->name} is disabled. No bot will read it, whatever its tables say.");
    }

    /**
     * Test what is on screen, before any of it is saved.
     *
     * Returns JSON rather than a redirect, so the modal can report without
     * closing and throwing away what the operator typed.
     */
    public function testDraft(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        $validated = $request->validate([
            'connection_id' => ['nullable', 'string', 'max:36'],
            'driver' => ['required', 'in:mysql,pgsql,sqlsrv,sqlite'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'array'],
        ]);

        $validated['options'] = array_filter(
            $validated['options'] ?? [], fn ($v) => $v !== '' && $v !== null);

        // connection_id is how a blank password reaches the stored one, so it
        // is scoped to the workspace in hand. Unscoped it would let an editor
        // borrow another workspace's credentials to probe a host of their
        // choosing.
        $existing = empty($validated['connection_id']) ? null
            : DbConnection::where('id', $validated['connection_id'])
                ->where('system_id', $activeSystem->id)
                ->first();

        return response()->json(SchemaIntrospector::test(
            DraftConnection::build($validated, $existing)));
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'driver' => ['required', 'in:mysql,pgsql,sqlsrv,sqlite'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'array'],
        ], [
            'driver.in' => 'Choose MySQL, PostgreSQL, SQL Server or SQLite.',
            'database.required' => 'A SQLite connection needs a file path here; the others need a database name.',
        ]);

        // Blank advanced fields are absent, not empty. But "0" is a real
        // answer for a checkbox, so array_filter's default test is wrong here.
        $validated['options'] = array_filter(
            $validated['options'] ?? [], fn ($v) => $v !== '' && $v !== null);

        return $validated;
    }

    private function authorizeEditor(Request $request, ?string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }
}
