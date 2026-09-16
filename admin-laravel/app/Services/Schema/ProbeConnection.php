<?php

namespace App\Services\Schema;

use App\Models\DbConnection;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;

/**
 * A Laravel database connection built from a stored record at request time.
 *
 * Registering the config rather than opening PDO by hand means all four
 * drivers go through the same layer the framework already configures, and
 * the query builder is available for introspection.
 */
final class ProbeConnection
{
    public const NAME = 'db_probe';

    /**
     * A probe sits between a visitor's message and the first token they see,
     * so an unreachable host has to fail rather than hang.
     *
     * Each driver wants this asked for differently, and asking the wrong way
     * is not ignored. pdo_sqlsrv rejects PDO::ATTR_TIMEOUT outright, but only
     * after the TCP handshake succeeds, so it surfaces as a baffling
     * "unsupported attribute" error on exactly the servers that are working.
     */
    private const CONNECT_TIMEOUT = 5;

    public static function open(DbConnection $connection): Connection
    {
        Config::set('database.connections.' . self::NAME, self::config($connection));

        // Without the purge a second open in the same request would hand
        // back the first connection's live PDO, pointed at the wrong
        // database.
        DB::purge(self::NAME);

        return DB::connection(self::NAME);
    }

    public static function config(DbConnection $c): array
    {
        $options = $c->options ?? [];

        return match ($c->driver) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => $c->database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => $c->host,
                'port' => (int) ($c->port ?: 3306),
                'database' => $c->database,
                'username' => $c->username,
                'password' => $c->password,
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => false,
                'options' => [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT],
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => $c->host,
                'port' => (int) ($c->port ?: 5432),
                'database' => $c->database,
                'username' => $c->username,
                'password' => $c->password,
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => $options['search_path'] ?? 'public',
                'sslmode' => $options['sslmode'] ?? 'prefer',
                // No timeout attribute. pdo_pgsql does not list ATTR_TIMEOUT
                // among the attributes it supports, and Laravel's DSN builder
                // offers no connect_timeout hook, so there is no way to ask
                // for one that is guaranteed not to be rejected.
            ],
            'sqlsrv' => [
                'driver' => 'sqlsrv',
                'host' => $c->host,
                'port' => (int) ($c->port ?: 1433),
                'database' => $c->database,
                'username' => $c->username,
                'password' => $c->password,
                'charset' => 'utf8',
                'prefix' => '',
                // A string "0" from an unticked checkbox is a real answer and
                // has to read as false, which a plain bool cast would get wrong.
                'trust_server_certificate' => filter_var(
                    $options['trust_server_certificate'] ?? true,
                    FILTER_VALIDATE_BOOL),
                // Laravel turns login_timeout into the DSN's LoginTimeout
                // keyword, which is how this driver wants a connect timeout.
                'login_timeout' => self::CONNECT_TIMEOUT,
                'options' => [PDO::SQLSRV_ATTR_QUERY_TIMEOUT => self::CONNECT_TIMEOUT],
            ],
            default => throw new InvalidArgumentException("Unknown driver [{$c->driver}]."),
        };
    }
}
