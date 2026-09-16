<?php

namespace App\Services\Schema;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use Illuminate\Support\Facades\DB;

/**
 * Folds a discovery run into the stored schema.
 *
 * Merges, never replaces. A description is written by somebody who
 * understands the business and is the expensive part of this feature, so it
 * survives a schema change, a permissions blip, and a column being renamed
 * back. A row the run did not see is marked absent rather than deleted, for
 * the same reason.
 */
final class SchemaSync
{
    /**
     * @param list<DiscoveredTable> $discovered
     * @return array{tables_added: int, tables_absent: int, columns_added: int, columns_absent: int}
     */
    public static function apply(DbConnection $connection, array $discovered): array
    {
        $counts = ['tables_added' => 0, 'tables_absent' => 0,
                   'columns_added' => 0, 'columns_absent' => 0];

        DB::transaction(function () use ($connection, $discovered, &$counts) {
            $stored = DbTable::where('connection_id', $connection->id)
                ->get()
                ->keyBy(fn (DbTable $t) => ($t->schema_name ?? '') . '|' . $t->table_name);

            $seen = [];

            foreach ($discovered as $table) {
                $key = $table->key();
                $seen[$key] = true;
                $row = $stored->get($key);

                if (!$row) {
                    $row = DbTable::create([
                        'connection_id' => $connection->id,
                        'schema_name' => $table->schema,
                        'table_name' => $table->name,
                        'is_enabled' => false,
                        'is_present' => true,
                    ]);
                    $counts['tables_added']++;
                } elseif (!$row->is_present) {
                    // description and is_enabled are deliberately not touched.
                    $row->update(['is_present' => true]);
                }

                self::syncColumns($row, $table->columns, $counts);
            }

            foreach ($stored as $key => $row) {
                if (!isset($seen[$key]) && $row->is_present) {
                    $row->update(['is_present' => false]);
                    $counts['tables_absent']++;
                }
            }

            $connection->update(['last_introspected_at' => now()]);
        });

        return $counts;
    }

    /**
     * @param list<DiscoveredColumn> $discovered
     * @param array{tables_added: int, tables_absent: int, columns_added: int, columns_absent: int} $counts
     */
    private static function syncColumns(DbTable $table, array $discovered, array &$counts): void
    {
        $stored = DbColumn::where('table_id', $table->id)->get()->keyBy('column_name');
        $seen = [];

        foreach ($discovered as $column) {
            $seen[$column->name] = true;
            $shape = [
                'data_type' => $column->dataType,
                'is_nullable' => $column->isNullable,
                'is_primary_key' => $column->isPrimaryKey,
                'ordinal' => $column->ordinal,
                'is_present' => true,
            ];

            $row = $stored->get($column->name);

            if (!$row) {
                DbColumn::create($shape + [
                    'table_id' => $table->id,
                    'column_name' => $column->name,
                    'foreign_key_target' => $column->foreignKeyTarget,
                ]);
                $counts['columns_added']++;
                continue;
            }

            // A declared constraint is the authority and always wins. Finding
            // none is not the same as there being none: plenty of databases
            // never declared theirs, and somebody may have worked the
            // relationship out by hand. Discovering nothing leaves it alone.
            if ($column->foreignKeyTarget !== null) {
                $shape['foreign_key_target'] = $column->foreignKeyTarget;
            }

            // Shape is the database's to state. description is not, and nor
            // is a relationship it never declared.
            $row->update($shape);
        }

        foreach ($stored as $name => $row) {
            if (!isset($seen[$name]) && $row->is_present) {
                $row->update(['is_present' => false]);
                $counts['columns_absent']++;
            }
        }
    }
}
