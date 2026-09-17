<?php

namespace App\Services\Schema;

use App\Models\DbConnection;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

/**
 * Runs one read-only statement against a workspace's own database.
 *
 * Everything here refuses rather than throws. A visitor must never see a
 * stack trace because a database was down or a model wrote nonsense.
 */
final class DbQueryRunner
{
    /**
     * @return array{ok: bool, columns: list<string>, rows: list<list>,
     *               row_count: int, elapsed_ms: int, message: string}
     */
    public static function run(DbConnection $connection, string $sql,
                               int $maxRows, int $timeout): array
    {
        if (!$connection->is_enabled) {
            return self::refuse("The connection {$connection->name} is switched off.");
        }

        // Both switches have to agree. A ticked table inside a switched-off
        // connection is not readable, and a table the database no longer has
        // is not readable either.
        $allowed = $connection->readableTables()->get()
            // On the console's own database, tables holding secrets stay shut
            // even if someone ticked them.
            ->reject(fn ($table) => ConsoleDatabase::is($connection)
                && in_array(strtolower($table->table_name), ConsoleDatabase::NEVER, true))
            ->map(fn ($table) => strtolower($table->qualifiedName()))
            ->all();

        if (!$allowed) {
            return self::refuse('No tables on this connection are enabled for reading.');
        }

        $complaint = SqlGuard::check($sql, $allowed);
        if ($complaint !== null) {
            return self::refuse($complaint);
        }

        $started = microtime(true);

        try {
            $probe = ProbeConnection::open($connection);
            self::applyTimeout($probe, $connection->driver, $timeout);
            if (ConsoleDatabase::is($connection)) {
                self::readOnly($probe, $connection->driver);
            }
            $rows = $probe->select($sql);
        } catch (Throwable $e) {
            return self::refuse(substr($e->getMessage(), 0, 500));
        }

        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $capped = array_slice($rows, 0, $maxRows);

        return [
            'ok' => true,
            'columns' => $capped ? array_keys((array) $capped[0]) : [],
            'rows' => array_map(fn ($row) => array_values((array) $row), $capped),
            'row_count' => count($rows),
            'elapsed_ms' => $elapsed,
            'message' => '',
        ];
    }

    /**
     * The console's own database is read with the application's account,
     * which can write. So the session is made read-only too, behind the
     * SELECT-only checks. Not on the application's own connection, as in the
     * test suite, where it would stop the application writing.
     */
    private static function readOnly($probe, string $driver): void
    {
        if ($probe->getName() !== ProbeConnection::NAME) {
            return;
        }

        match ($driver) {
            'sqlite' => $probe->statement('PRAGMA query_only = ON'),
            'pgsql' => $probe->statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY'),
            'mysql' => $probe->statement('SET SESSION TRANSACTION READ ONLY'),
            default => null,
        };
    }

    private static function applyTimeout($probe, string $driver, int $timeout): void
    {
        try {
            match ($driver) {
                'mysql' => $probe->statement("SET SESSION max_execution_time = " . ($timeout * 1000)),
                'pgsql' => $probe->statement('SET statement_timeout = ' . ($timeout * 1000)),
                'sqlsrv' => $probe->getPdo()->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, $timeout),
                default => null,
            };
        } catch (Throwable $e) {
            // An older MySQL, a MariaDB that spells it differently, or a
            // restricted account may refuse. A query without a timeout is
            // worse than one with, but far better than no feature at all.
            Log::info("Query timeout not set for {$driver}: " . $e->getMessage());
        }
    }

    private static function refuse(string $message): array
    {
        return ['ok' => false, 'columns' => [], 'rows' => [],
                'row_count' => 0, 'elapsed_ms' => 0, 'message' => $message];
    }
}
