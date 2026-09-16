<?php

namespace App\Http\Controllers;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Services\Schema\SchemaIntrospector;
use App\Services\Schema\SchemaSync;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The schema editor: what each table and column holds, in the words of
 * somebody who knows the business. This is the surface the whole feature's
 * answer quality rests on, which is why a description is never written by
 * anything but a person.
 */
class DbSchemaController extends Controller
{
    public function show(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeViewer($request, $connection->system_id);

        // What a bot may read is what an operator came to look at, so the
        // readable ones come first and the rest stay alphabetical beneath.
        $tables = DbTable::with('columns')
            ->where('connection_id', $connection->id)
            ->orderByDesc('is_enabled')
            ->orderBy('schema_name')
            ->orderBy('table_name')
            ->get();

        return view('databases.schema', [
            'connection' => $connection,
            'tables' => $tables,
            'selected' => $tables->firstWhere('id', (int) $request->query('table'))
                ?? $tables->first(),
            'canEdit' => $request->user()->canManageSystem($connection->system_id, 'editor'),
            // A real system has hundreds of tables. The counts are how an
            // operator knows where they stand without scrolling the list.
            'readableCount' => $tables->where('is_enabled', true)->count(),
        ]);
    }

    public function introspect(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);

        try {
            $counts = SchemaSync::apply($connection, SchemaIntrospector::discover($connection));
        } catch (Throwable $e) {
            // A restricted account may refuse information_schema outright.
            // The editor below still works, by hand.
            $connection->update([
                'status' => 'failed',
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);

            return redirect()->route('databases.schema', $connection->id)
                ->with('error', 'Could not read the schema: ' . substr($e->getMessage(), 0, 300));
        }

        $connection->update(['status' => 'ok', 'error_message' => null]);

        return redirect()->route('databases.schema', $connection->id)->with('success', sprintf(
            '%d tables and %d columns added. %d tables and %d columns are no longer in the database.',
            $counts['tables_added'], $counts['columns_added'],
            $counts['tables_absent'], $counts['columns_absent']));
    }

    public function storeTable(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);

        $validated = $request->validate([
            'schema_name' => ['nullable', 'string', 'max:128'],
            'table_name' => [
                'required', 'string', 'max:128',
                Rule::unique('db_tables', 'table_name')
                    ->where('connection_id', $connection->id)
                    ->where('schema_name', $request->input('schema_name') ?: null),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'table_name.unique' => 'This connection already has that table.',
        ]);

        $table = DbTable::create([
            'connection_id' => $connection->id,
            'schema_name' => $validated['schema_name'] ?: null,
            'table_name' => $validated['table_name'],
            'description' => $validated['description'] ?? null,
            'is_enabled' => false,
            'is_present' => true,
        ]);

        return redirect()->route('databases.schema', [$connection->id, 'table' => $table->id])
            ->with('success', 'Table added. Add its columns, then enable it.');
    }

    /**
     * A table with sixty columns was sixty saves, each one a fresh page that
     * threw the reader back to the top. The whole panel is one form now, so
     * the table's own words and every column's arrive together.
     */
    public function updateTable(Request $request, int $tableId)
    {
        $table = DbTable::findOrFail($tableId);
        $this->authorizeEditor($request, $table->connection->system_id);

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'columns' => ['array'],
            'columns.*.description' => ['nullable', 'string', 'max:2000'],
            'columns.*.foreign_key_target' => ['nullable', 'string', 'max:255'],
        ]);

        $table->update([
            'description' => $validated['description'] ?? null,
            // An unticked checkbox sends nothing at all, which means off.
            'is_enabled' => $request->boolean('is_enabled'),
        ]);

        $changed = $this->applyColumnEdits($table, $validated['columns'] ?? []);

        return redirect()->route('databases.schema', [$table->connection_id, 'table' => $table->id])
            ->with('success', $changed === 0 ? 'Saved.' : sprintf(
                'Saved. %d column%s updated.', $changed, $changed === 1 ? '' : 's'));
    }

    /**
     * Applies one save's worth of column edits, and answers how many columns
     * it really wrote. A posted id is not proof of ownership, so the edits are
     * matched against the columns this table actually has and anything else is
     * dropped. A row whose words did not change is not written at all, which
     * keeps a sixty column save to the handful of rows a person touched.
     */
    private function applyColumnEdits(DbTable $table, array $edits): int
    {
        $changed = 0;

        foreach ($table->columns as $column) {
            $edit = $edits[$column->id] ?? null;

            if (!is_array($edit)) {
                continue;
            }

            $values = [];

            // A field the form never sent is left alone. A field sent empty is
            // a person clearing it, which is not the same thing.
            if (array_key_exists('description', $edit)) {
                $values['description'] = ($edit['description'] ?? '') !== ''
                    ? $edit['description'] : null;
            }

            // A relationship nobody declared in the database is still a
            // relationship. This is how a person tells the model about one.
            if (array_key_exists('foreign_key_target', $edit)) {
                $values['foreign_key_target'] = ($edit['foreign_key_target'] ?? '') !== ''
                    ? $edit['foreign_key_target'] : null;
            }

            $untouched = collect($values)->every(
                fn ($value, $field) => $column->{$field} === $value);

            if ($values === [] || $untouched) {
                continue;
            }

            $column->update($values);
            $changed++;
        }

        return $changed;
    }

    /**
     * Every table at once. Ticking each of two hundred in turn was the slowest
     * honest way to say "read all of it", so one tick above the list says it.
     */
    public function bulkSetReadable(Request $request, string $id)
    {
        $connection = DbConnection::findOrFail($id);
        $this->authorizeEditor($request, $connection->system_id);

        $enabled = $request->boolean('enabled');
        $tables = DbTable::where('connection_id', $connection->id);
        $count = (clone $tables)->count();

        $tables->update(['is_enabled' => $enabled]);

        // The table being read stays open, so the tick does not also lose a
        // person's place in the list.
        $params = [$connection->id];
        if ($request->filled('table')) {
            $params['table'] = $request->input('table');
        }

        return redirect()->route('databases.schema', $params)->with('success', $enabled
            ? sprintf('All %d tables are readable.', $count)
            : sprintf('All %d tables are ignored. No bot will read this database.', $count));
    }

    public function destroyTable(Request $request, int $tableId)
    {
        $table = DbTable::findOrFail($tableId);
        $this->authorizeEditor($request, $table->connection->system_id);

        $connectionId = $table->connection_id;
        $table->delete();

        return redirect()->route('databases.schema', $connectionId)->with('success', 'Table removed.');
    }

    public function storeColumn(Request $request, int $tableId)
    {
        $table = DbTable::findOrFail($tableId);
        $this->authorizeEditor($request, $table->connection->system_id);

        $validated = $request->validate([
            'column_name' => [
                'required', 'string', 'max:128',
                Rule::unique('db_columns', 'column_name')->where('table_id', $table->id),
            ],
            'data_type' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'foreign_key_target' => ['nullable', 'string', 'max:255'],
        ], [
            'column_name.unique' => 'This table already has that column.',
        ]);

        DbColumn::create([
            'table_id' => $table->id,
            'column_name' => $validated['column_name'],
            'data_type' => $validated['data_type'] ?? null,
            'description' => $validated['description'] ?? null,
            'foreign_key_target' => ($validated['foreign_key_target'] ?? '') ?: null,
            'ordinal' => (int) DbColumn::where('table_id', $table->id)->max('ordinal') + 1,
            'is_present' => true,
        ]);

        return redirect()->route('databases.schema', [$table->connection_id, 'table' => $table->id])
            ->with('success', 'Column added.');
    }

    public function updateColumn(Request $request, int $columnId)
    {
        $column = DbColumn::findOrFail($columnId);
        $this->authorizeEditor($request, $column->table->connection->system_id);

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:2000'],
            'foreign_key_target' => ['nullable', 'string', 'max:255'],
        ]);

        $column->update([
            'description' => $validated['description'] ?? null,
            // A relationship nobody declared in the database is still a
            // relationship. This is how a person tells the model about one.
            'foreign_key_target' => ($validated['foreign_key_target'] ?? '') ?: null,
        ]);

        return redirect()->route('databases.schema',
            [$column->table->connection_id, 'table' => $column->table_id])
            ->with('success', 'Saved.');
    }

    public function destroyColumn(Request $request, int $columnId)
    {
        $column = DbColumn::findOrFail($columnId);
        $this->authorizeEditor($request, $column->table->connection->system_id);

        $table = $column->table;
        $column->delete();

        return redirect()->route('databases.schema', [$table->connection_id, 'table' => $table->id])
            ->with('success', 'Column removed.');
    }

    private function authorizeEditor(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }

    private function authorizeViewer(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'viewer'), 403);
    }
}
