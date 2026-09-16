<?php

namespace Tests\Feature;

use App\Models\DbConnection;
use App\Models\System;
use App\Services\Schema\ProbeConnection;
use App\Services\Schema\SchemaIntrospector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaIntrospectorTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'introspect') . '.sqlite';
        touch($this->path);

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function connection(): DbConnection
    {
        return DbConnection::create([
            'id' => 'dbc_1',
            'system_id' => 'sys_test',
            'name' => 'Shop',
            'driver' => 'sqlite',
            'database' => $this->path,
        ]);
    }

    private function seedShop(): DbConnection
    {
        $connection = $this->connection();
        $probe = ProbeConnection::open($connection);

        $probe->statement('CREATE TABLE customers (
            id integer primary key,
            name text not null,
            email text
        )');
        $probe->statement('CREATE TABLE orders (
            id integer primary key,
            customer_id integer not null references customers(id),
            total numeric not null,
            placed_at text
        )');

        return $connection;
    }

    public function test_every_table_is_found(): void
    {
        $tables = SchemaIntrospector::discover($this->seedShop());

        $this->assertSame(['customers', 'orders'], array_map(fn ($t) => $t->name, $tables));
    }

    public function test_sqlite_tables_have_no_schema(): void
    {
        $tables = SchemaIntrospector::discover($this->seedShop());

        $this->assertNull($tables[0]->schema);
    }

    public function test_columns_carry_their_type_and_nullability(): void
    {
        $tables = SchemaIntrospector::discover($this->seedShop());
        $customers = $tables[0];

        $this->assertSame(['id', 'name', 'email'], array_map(
            fn ($c) => $c->name, $customers->columns));
        $this->assertSame('text', strtolower($customers->columns[1]->dataType));
        $this->assertFalse($customers->columns[1]->isNullable);
        $this->assertTrue($customers->columns[2]->isNullable);
    }

    public function test_the_primary_key_is_marked(): void
    {
        $tables = SchemaIntrospector::discover($this->seedShop());

        $this->assertTrue($tables[0]->columns[0]->isPrimaryKey);
        $this->assertFalse($tables[0]->columns[1]->isPrimaryKey);
    }

    public function test_a_foreign_key_points_at_its_target(): void
    {
        $tables = SchemaIntrospector::discover($this->seedShop());
        $orders = $tables[1];

        $this->assertSame('customers.id', $orders->columns[1]->foreignKeyTarget);
        $this->assertNull($orders->columns[2]->foreignKeyTarget);
    }

    public function test_sqlite_internal_tables_are_excluded(): void
    {
        // SQLite refuses a table named sqlite_*, so the internal table has to
        // be provoked rather than created. AUTOINCREMENT makes sqlite_sequence.
        $connection = $this->seedShop();
        $probe = ProbeConnection::open($connection);
        $probe->statement('CREATE TABLE receipts (id integer primary key autoincrement, note text)');
        $probe->statement("INSERT INTO receipts (note) VALUES ('first')");

        $names = array_map(fn ($t) => $t->name, SchemaIntrospector::discover($connection));

        $this->assertContains('receipts', $names);
        $this->assertNotContains('sqlite_sequence', $names);
    }

    public function test_an_empty_database_discovers_nothing_without_erroring(): void
    {
        $this->assertSame([], SchemaIntrospector::discover($this->connection()));
    }

    public function test_a_reachable_database_tests_ok(): void
    {
        $result = SchemaIntrospector::test($this->seedShop());

        $this->assertTrue($result['ok']);
    }

    public function test_an_unreachable_database_reports_rather_than_throws(): void
    {
        $connection = $this->connection();
        $connection->driver = 'mysql';
        $connection->host = '127.0.0.1';
        $connection->port = 1;          // nothing listens here
        $connection->database = 'nope';

        $result = SchemaIntrospector::test($connection);

        $this->assertFalse($result['ok']);
        $this->assertNotSame('', $result['message']);
    }
}
