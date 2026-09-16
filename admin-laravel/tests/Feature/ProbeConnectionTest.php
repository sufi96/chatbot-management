<?php

namespace Tests\Feature;

use App\Models\DbConnection;
use App\Services\Schema\ProbeConnection;
use Tests\TestCase;

class ProbeConnectionTest extends TestCase
{
    /**
     * Unsaved on purpose. ProbeConnection only reads attributes, so nothing
     * here needs a database, and two connections in one test cannot collide
     * on a primary key.
     */
    private function connection(array $overrides = []): DbConnection
    {
        return new DbConnection(array_merge([
            'id' => 'dbc_1',
            'system_id' => 'sys_test',
            'name' => 'Orders',
            'driver' => 'mysql',
            'host' => 'db.internal',
            'database' => 'shop',
            'username' => 'reader',
            'password' => 'secret',
        ], $overrides));
    }

    public function test_mysql_gets_its_default_port(): void
    {
        $config = ProbeConnection::config($this->connection());

        $this->assertSame('mysql', $config['driver']);
        $this->assertSame(3306, $config['port']);
        $this->assertSame('shop', $config['database']);
        $this->assertSame('reader', $config['username']);
        $this->assertSame('secret', $config['password']);
    }

    public function test_a_stored_port_wins_over_the_default(): void
    {
        $config = ProbeConnection::config($this->connection(['port' => 3307]));

        $this->assertSame(3307, $config['port']);
    }

    public function test_postgres_defaults_to_prefer_and_honours_an_option(): void
    {
        $plain = ProbeConnection::config($this->connection(['driver' => 'pgsql']));
        $this->assertSame(5432, $plain['port']);
        $this->assertSame('prefer', $plain['sslmode']);

        $strict = ProbeConnection::config($this->connection([
            'driver' => 'pgsql', 'options' => ['sslmode' => 'require'],
        ]));
        $this->assertSame('require', $strict['sslmode']);
    }

    public function test_sql_server_defaults_to_trusting_the_certificate(): void
    {
        $config = ProbeConnection::config($this->connection(['driver' => 'sqlsrv']));

        $this->assertSame(1433, $config['port']);
        $this->assertTrue($config['trust_server_certificate']);
    }

    public function test_sql_server_certificate_trust_can_be_turned_off(): void
    {
        $config = ProbeConnection::config($this->connection([
            'driver' => 'sqlsrv', 'options' => ['trust_server_certificate' => '0'],
        ]));

        $this->assertFalse($config['trust_server_certificate']);
    }

    public function test_mysql_asks_for_a_connect_timeout_the_pdo_way(): void
    {
        $config = ProbeConnection::config($this->connection());

        $this->assertSame(5, $config['options'][\PDO::ATTR_TIMEOUT]);
    }

    public function test_sql_server_never_gets_the_pdo_timeout_attribute(): void
    {
        // pdo_sqlsrv rejects PDO::ATTR_TIMEOUT with "an unsupported attribute
        // was designated on the PDO object", and only after the handshake
        // succeeds, so it breaks exactly the servers that are reachable.
        $config = ProbeConnection::config($this->connection(['driver' => 'sqlsrv']));

        $this->assertArrayNotHasKey(\PDO::ATTR_TIMEOUT, $config['options']);
        $this->assertSame(5, $config['login_timeout']);
        $this->assertSame(5, $config['options'][\PDO::SQLSRV_ATTR_QUERY_TIMEOUT]);
    }

    public function test_postgres_never_gets_the_pdo_timeout_attribute(): void
    {
        // pdo_pgsql does not list ATTR_TIMEOUT among its supported attributes,
        // and Laravel's DSN builder has no connect_timeout hook, so there is
        // no safe way to ask.
        $config = ProbeConnection::config($this->connection(['driver' => 'pgsql']));

        $this->assertArrayNotHasKey('options', $config);
    }

    public function test_sqlite_carries_only_a_file_path(): void
    {
        $config = ProbeConnection::config($this->connection([
            'driver' => 'sqlite', 'database' => '/data/shop.sqlite',
        ]));

        $this->assertSame('sqlite', $config['driver']);
        $this->assertSame('/data/shop.sqlite', $config['database']);
        $this->assertArrayNotHasKey('host', $config);
    }

    public function test_an_unknown_driver_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProbeConnection::config($this->connection(['driver' => 'oracle']));
    }

    public function test_opening_a_sqlite_file_gives_a_usable_connection(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'probe') . '.sqlite';
        touch($path);

        try {
            $probe = ProbeConnection::open($this->connection([
                'driver' => 'sqlite', 'database' => $path,
            ]));
            $probe->statement('CREATE TABLE widgets (id integer primary key)');

            $this->assertSame(0, (int) $probe->table('widgets')->count());
        } finally {
            @unlink($path);
        }
    }

    public function test_opening_twice_does_not_reuse_the_first_file(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'probe') . '-a.sqlite';
        $second = tempnam(sys_get_temp_dir(), 'probe') . '-b.sqlite';
        touch($first);
        touch($second);

        try {
            ProbeConnection::open($this->connection([
                'driver' => 'sqlite', 'database' => $first,
            ]))->statement('CREATE TABLE only_in_first (id integer)');

            $probe = ProbeConnection::open($this->connection([
                'driver' => 'sqlite', 'database' => $second,
            ]));

            // Without the purge, the second open would hand back the first
            // file's live PDO and this table would exist.
            $this->assertFalse($probe->getSchemaBuilder()->hasTable('only_in_first'));
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }
}
