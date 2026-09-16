<?php

namespace App\Services\Schema;

/**
 * Normalized information schema rows, turned into value objects.
 *
 * Pure, so it can be tested against all three dialects' output without any
 * of those three servers existing. The queries that produce these rows live
 * in SchemaIntrospector and are the only dialect-aware part.
 */
final class SchemaMapper
{
    /**
     * @param list<array<string,mixed>> $columnRows
     * @param list<array<string,mixed>> $constraintRows
     * @return list<DiscoveredTable>
     */
    public static function fromRows(array $columnRows, array $constraintRows): array
    {
        $keys = [];
        foreach ($constraintRows as $row) {
            $key = self::key($row['schema'] ?? null, $row['table'], $row['column']);

            if ($row['constraint_type'] === 'PRIMARY KEY') {
                $keys[$key]['primary'] = true;
                continue;
            }

            if ($row['constraint_type'] === 'FOREIGN KEY' && !empty($row['ref_table'])) {
                $keys[$key]['target'] = self::target(
                    $row['ref_schema'] ?? null, $row['ref_table'], $row['ref_column']);
            }
        }

        $grouped = [];
        foreach ($columnRows as $row) {
            $schema = $row['schema'] !== null && $row['schema'] !== ''
                ? (string) $row['schema'] : null;
            $tableKey = ($schema ?? '') . '|' . $row['table'];
            $columnKey = self::key($schema, $row['table'], $row['column']);

            $grouped[$tableKey]['schema'] = $schema;
            $grouped[$tableKey]['table'] = (string) $row['table'];
            $grouped[$tableKey]['columns'][] = new DiscoveredColumn(
                name: (string) $row['column'],
                dataType: $row['data_type'] !== null ? (string) $row['data_type'] : null,
                isNullable: strtoupper((string) $row['is_nullable']) === 'YES',
                isPrimaryKey: $keys[$columnKey]['primary'] ?? false,
                foreignKeyTarget: $keys[$columnKey]['target'] ?? null,
                ordinal: (int) $row['ordinal'],
            );
        }

        ksort($grouped);

        $tables = [];
        foreach ($grouped as $entry) {
            $columns = $entry['columns'];
            usort($columns, fn ($a, $b) => $a->ordinal <=> $b->ordinal);
            $tables[] = new DiscoveredTable($entry['schema'], $entry['table'], $columns);
        }

        return $tables;
    }

    private static function key(?string $schema, string $table, string $column): string
    {
        return ($schema ?? '') . '|' . $table . '|' . $column;
    }

    private static function target(?string $schema, string $table, ?string $column): string
    {
        $parts = array_filter([$schema, $table, $column], fn ($p) => $p !== null && $p !== '');

        return implode('.', $parts);
    }
}
