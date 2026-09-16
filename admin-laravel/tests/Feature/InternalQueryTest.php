<?php

namespace Tests\Feature;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use App\Services\Schema\ProbeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalQueryTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.portal_internal_token' => 'test-portal-token']);

        $this->path = tempnam(sys_get_temp_dir(), 'query') . '.sqlite';
        touch($this->path);

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function shop(bool $connectionEnabled = true, bool $ordersEnabled = true): DbConnection
    {
        $connection = DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Shop',
            'driver' => 'sqlite', 'database' => $this->path,
            'is_enabled' => $connectionEnabled,
        ]);

        $probe = ProbeConnection::open($connection);
        $probe->statement('CREATE TABLE orders (id integer primary key, status text, total numeric)');
        $probe->statement("INSERT INTO orders (id, status, total) VALUES (1, 'shipped', 438.0)");
        $probe->statement("INSERT INTO orders (id, status, total) VALUES (2, 'pending', 1299.0)");
        $probe->statement('CREATE TABLE salaries (id integer primary key, amount numeric)');
        $probe->statement('INSERT INTO salaries (id, amount) VALUES (1, 99999)');

        $table = DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'orders',
            'is_enabled' => $ordersEnabled, 'is_present' => true,
        ]);
        DbColumn::create(['table_id' => $table->id, 'column_name' => 'id', 'ordinal' => 1]);

        // Never ticked, so it is not on the allowlist.
        DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'salaries',
            'is_enabled' => false, 'is_present' => true,
        ]);

        return $connection;
    }

    private function ask(array $body, ?string $token = 'test-portal-token')
    {
        $headers = $token === null ? [] : ['X-Portal-Token' => $token];

        return $this->withHeaders($headers)->postJson('/internal/db/query', $body);
    }

    public function test_a_select_returns_columns_and_rows(): void
    {
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1',
            'sql' => 'SELECT id, status FROM orders LIMIT 50',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertTrue($response->json('ok'));
        $this->assertSame(['id', 'status'], $response->json('columns'));
        $this->assertSame(2, $response->json('row_count'));
        $this->assertSame('shipped', $response->json('rows.0.1'));
    }

    public function test_a_missing_token_is_refused(): void
    {
        $this->shop();

        $this->ask(['connection_id' => 'dbc_1', 'sql' => 'SELECT id FROM orders',
                    'max_rows' => 50, 'timeout' => 10], null)
            ->assertStatus(401);
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $this->shop();

        $this->ask(['connection_id' => 'dbc_1', 'sql' => 'SELECT id FROM orders',
                    'max_rows' => 50, 'timeout' => 10], 'not-the-token')
            ->assertStatus(401);
    }

    public function test_a_write_is_refused_even_though_the_engine_validated(): void
    {
        // The engine is a caller like any other. Its validation is not
        // evidence, so every rule runs again here.
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'DELETE FROM orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertSame(2, ProbeConnection::open(DbConnection::find('dbc_1'))
            ->table('orders')->count());
    }

    public function test_a_second_statement_is_refused(): void
    {
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1',
            'sql' => 'SELECT id FROM orders; DROP TABLE orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
    }

    public function test_a_table_nobody_ticked_is_refused(): void
    {
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'SELECT amount FROM salaries',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertStringContainsString('salaries', $response->json('message'));
    }

    public function test_a_switched_off_connection_refuses_everything(): void
    {
        // The master switch is a gate the query path has to honour, not just
        // a label on a screen.
        $this->shop(connectionEnabled: false);

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'SELECT id FROM orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
    }

    public function test_a_table_switched_off_refuses_that_table(): void
    {
        $this->shop(ordersEnabled: false);

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'SELECT id FROM orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
    }

    public function test_an_unknown_connection_is_refused(): void
    {
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_nope', 'sql' => 'SELECT id FROM orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
    }

    public function test_a_broken_statement_reports_rather_than_throws(): void
    {
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'SELECT nosuchcolumn FROM orders',
            'max_rows' => 50, 'timeout' => 10,
        ])->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertNotSame('', $response->json('message'));
    }

    public function test_the_row_cap_is_enforced_here_too(): void
    {
        // The engine injects a limit, but a caller that did not is still
        // capped rather than trusted.
        $this->shop();

        $response = $this->ask([
            'connection_id' => 'dbc_1', 'sql' => 'SELECT id FROM orders',
            'max_rows' => 1, 'timeout' => 10,
        ])->assertOk();

        $this->assertTrue($response->json('ok'));
        $this->assertCount(1, $response->json('rows'));
    }
}
