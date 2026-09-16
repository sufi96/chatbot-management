<?php

namespace App\Services\Schema;

use App\Models\DbConnection;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Reads the shape of a customer's database.
 *
 * SQLite has no information schema and gets its own path. The other three
 * share one, with two constraint queries rather than one: MySQL puts the
 * referenced side of a foreign key directly on key_column_usage, while
 * PostgreSQL and SQL Server make you reach it through
 * referential_constraints.
 */
final class SchemaIntrospector
{
    /** Never described to a model, never worth an operator's attention. */
    private const SYSTEM_SCHEMAS = [
        'information_schema', 'performance_schema', 'mysql', 'sys', 'pg_catalog',
    ];

    /** @return list<DiscoveredTable> */
    public static function discover(DbConnection $connection): array
    {
        $probe = ProbeConnection::open($connection);

        return $connection->driver === 'sqlite'
            ? self::discoverSqlite($probe)
            : SchemaMapper::fromRows(
                self::columnRows($probe, $connection),
                self::constraintRows($probe, $connection),
            );
    }

    /** @return array{ok: bool, message: string} */
    public static function test(DbConnection $connection): array
    {
        try {
            ProbeConnection::open($connection)->select('SELECT 1');
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => substr($e->getMessage(), 0, 500)];
        }

        return ['ok' => true, 'message' => 'Connected.'];
    }

    /** @return list<DiscoveredTable> */
    private static function discoverSqlite(Connection $probe): array
    {
        // sqlite_master holds internal tables too, and they are never an
        // operator's business. LIKE needs an escape clause to match a literal
        // underscore rather than any single character.
        $names = array_map(fn ($row) => (string) $row->name, $probe->select(
            "SELECT name FROM sqlite_master"
            . " WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'"
            . " ORDER BY name"));

        $tables = [];
        foreach ($names as $name) {
            $quoted = str_replace('"', '""', $name);

            $targets = [];
            foreach ($probe->select("PRAGMA foreign_key_list(\"{$quoted}\")") as $fk) {
                // A foreign key with no "to" column points at the target's
                // primary key, which PRAGMA leaves for the caller to name.
                $targets[(string) $fk->from] = (string) $fk->table
                    . '.' . ((string) ($fk->to ?? 'rowid'));
            }

            $columns = [];
            foreach ($probe->select("PRAGMA table_info(\"{$quoted}\")") as $info) {
                $columns[] = new DiscoveredColumn(
                    name: (string) $info->name,
                    dataType: $info->type !== '' ? (string) $info->type : null,
                    isNullable: ! (bool) $info->notnull,
                    isPrimaryKey: (bool) $info->pk,
                    foreignKeyTarget: $targets[(string) $info->name] ?? null,
                    ordinal: (int) $info->cid + 1,
                );
            }

            $tables[] = new DiscoveredTable(null, $name, $columns);
        }

        return $tables;
    }

    /** @return list<array<string,mixed>> */
    private static function columnRows(Connection $probe, DbConnection $connection): array
    {
        [$filter, $bindings] = self::schemaFilter($connection, 'c.table_schema');

        $rows = $probe->select("
            SELECT c.table_schema AS schema_name, c.table_name AS table_name,
                   c.column_name AS column_name, c.data_type AS data_type,
                   c.is_nullable AS is_nullable, c.ordinal_position AS ordinal
            FROM information_schema.columns c
            WHERE {$filter}
            ORDER BY c.table_schema, c.table_name, c.ordinal_position
        ", $bindings);

        return array_map(fn ($row) => [
            'schema' => $row->schema_name,
            'table' => $row->table_name,
            'column' => $row->column_name,
            'data_type' => $row->data_type,
            'is_nullable' => $row->is_nullable,
            'ordinal' => $row->ordinal,
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private static function constraintRows(Connection $probe, DbConnection $connection): array
    {
        $rows = $connection->driver === 'mysql'
            ? self::mysqlConstraints($probe, $connection)
            : self::ansiConstraints($probe, $connection);

        return array_map(fn ($row) => [
            'schema' => $row->schema_name,
            'table' => $row->table_name,
            'column' => $row->column_name,
            'constraint_type' => $row->constraint_type,
            'ref_schema' => $row->ref_schema ?? null,
            'ref_table' => $row->ref_table ?? null,
            'ref_column' => $row->ref_column ?? null,
        ], $rows);
    }

    /**
     * MySQL puts the referenced side of a foreign key straight onto
     * key_column_usage, which the standard does not.
     */
    private static function mysqlConstraints(Connection $probe, DbConnection $connection): array
    {
        [$filter, $bindings] = self::schemaFilter($connection, 'tc.table_schema');

        return $probe->select("
            SELECT kcu.table_schema AS schema_name, kcu.table_name AS table_name,
                   kcu.column_name AS column_name, tc.constraint_type AS constraint_type,
                   kcu.referenced_table_schema AS ref_schema,
                   kcu.referenced_table_name AS ref_table,
                   kcu.referenced_column_name AS ref_column
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON kcu.constraint_name = tc.constraint_name
             AND kcu.table_schema = tc.table_schema
             AND kcu.table_name = tc.table_name
            WHERE tc.constraint_type IN ('PRIMARY KEY', 'FOREIGN KEY')
              AND {$filter}
        ", $bindings);
    }

    /**
     * PostgreSQL and SQL Server reach the referenced column through
     * referential_constraints and back into key_column_usage, matched on
     * ordinal so a composite key pairs up correctly.
     */
    private static function ansiConstraints(Connection $probe, DbConnection $connection): array
    {
        [$filter, $bindings] = self::schemaFilter($connection, 'tc.table_schema');

        return $probe->select("
            SELECT kcu.table_schema AS schema_name, kcu.table_name AS table_name,
                   kcu.column_name AS column_name, tc.constraint_type AS constraint_type,
                   rkcu.table_schema AS ref_schema, rkcu.table_name AS ref_table,
                   rkcu.column_name AS ref_column
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON kcu.constraint_name = tc.constraint_name
             AND kcu.constraint_schema = tc.constraint_schema
            LEFT JOIN information_schema.referential_constraints rc
              ON rc.constraint_name = tc.constraint_name
             AND rc.constraint_schema = tc.constraint_schema
            LEFT JOIN information_schema.key_column_usage rkcu
              ON rkcu.constraint_name = rc.unique_constraint_name
             AND rkcu.constraint_schema = rc.unique_constraint_schema
             AND rkcu.ordinal_position = kcu.ordinal_position
            WHERE tc.constraint_type IN ('PRIMARY KEY', 'FOREIGN KEY')
              AND {$filter}
        ", $bindings);
    }

    /**
     * MySQL calls the database a schema, so it filters to exactly one. The
     * other two exclude the system schemas and keep everything else.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function schemaFilter(DbConnection $connection, string $column): array
    {
        if ($connection->driver === 'mysql') {
            return ["{$column} = ?", [$connection->database]];
        }

        $placeholders = implode(', ', array_fill(0, count(self::SYSTEM_SCHEMAS), '?'));

        return ["LOWER({$column}) NOT IN ({$placeholders})", self::SYSTEM_SCHEMAS];
    }
}
