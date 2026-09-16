<?php

namespace Tests\Feature;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use App\Services\Schema\DiscoveredColumn;
use App\Services\Schema\DiscoveredTable;
use App\Services\Schema\SchemaSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaSyncTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): DbConnection
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);

        return DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Shop',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
    }

    private function column(string $name, int $ordinal, ?string $fk = null): DiscoveredColumn
    {
        return new DiscoveredColumn($name, 'integer', false, $ordinal === 1, $fk, $ordinal);
    }

    private function ordersTable(array $columnNames = ['id', 'total']): DiscoveredTable
    {
        $columns = [];
        foreach ($columnNames as $i => $name) {
            $columns[] = $this->column($name, $i + 1);
        }

        return new DiscoveredTable(null, 'orders', $columns);
    }

    public function test_a_first_run_creates_everything_disabled(): void
    {
        $connection = $this->connection();

        $counts = SchemaSync::apply($connection, [$this->ordersTable()]);

        $this->assertSame(1, $counts['tables_added']);
        $this->assertSame(2, $counts['columns_added']);

        $table = DbTable::where('connection_id', 'dbc_1')->first();
        $this->assertSame('orders', $table->table_name);
        $this->assertFalse($table->is_enabled);
        $this->assertTrue($table->is_present);
        $this->assertCount(2, $table->columns);
    }

    public function test_the_run_stamps_when_it_happened(): void
    {
        $connection = $this->connection();
        $this->assertNull($connection->last_introspected_at);

        SchemaSync::apply($connection, [$this->ordersTable()]);

        $this->assertNotNull($connection->fresh()->last_introspected_at);
    }

    public function test_a_second_run_adds_nothing_it_already_has(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);

        $counts = SchemaSync::apply($connection, [$this->ordersTable()]);

        $this->assertSame(0, $counts['tables_added']);
        $this->assertSame(0, $counts['columns_added']);
        $this->assertSame(1, DbTable::count());
        $this->assertSame(2, DbColumn::count());
    }

    public function test_a_table_description_and_enable_flag_survive_a_rerun(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);

        DbTable::where('table_name', 'orders')->update([
            'description' => 'Orders placed through the web shop.',
            'is_enabled' => true,
        ]);

        SchemaSync::apply($connection, [$this->ordersTable()]);

        $table = DbTable::where('table_name', 'orders')->first();
        $this->assertSame('Orders placed through the web shop.', $table->description);
        $this->assertTrue($table->is_enabled);
    }

    public function test_a_column_description_survives_a_rerun(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);

        DbColumn::where('column_name', 'total')->update([
            'description' => 'Gross amount in ringgit, including tax.',
        ]);

        SchemaSync::apply($connection, [$this->ordersTable()]);

        $this->assertSame('Gross amount in ringgit, including tax.',
            DbColumn::where('column_name', 'total')->value('description'));
    }

    public function test_a_changed_type_is_updated(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);

        $changed = new DiscoveredTable(null, 'orders', [
            $this->column('id', 1),
            new DiscoveredColumn('total', 'numeric', true, false, null, 2),
        ]);
        SchemaSync::apply($connection, [$changed]);

        $column = DbColumn::where('column_name', 'total')->first();
        $this->assertSame('numeric', $column->data_type);
        $this->assertTrue($column->is_nullable);
    }

    public function test_a_vanished_table_is_marked_absent_not_deleted(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);
        DbTable::where('table_name', 'orders')->update([
            'description' => 'Worth keeping.', 'is_enabled' => true,
        ]);

        $counts = SchemaSync::apply($connection, []);

        $this->assertSame(1, $counts['tables_absent']);
        $table = DbTable::where('table_name', 'orders')->first();
        $this->assertNotNull($table);
        $this->assertFalse($table->is_present);
        $this->assertSame('Worth keeping.', $table->description);
        $this->assertTrue($table->is_enabled);
    }

    public function test_a_vanished_column_is_marked_absent_not_deleted(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable(['id', 'total'])]);
        DbColumn::where('column_name', 'total')->update(['description' => 'Keep me.']);

        $counts = SchemaSync::apply($connection, [$this->ordersTable(['id'])]);

        $this->assertSame(1, $counts['columns_absent']);
        $column = DbColumn::where('column_name', 'total')->first();
        $this->assertFalse($column->is_present);
        $this->assertSame('Keep me.', $column->description);
    }

    public function test_a_returning_table_becomes_present_again_with_its_annotation(): void
    {
        $connection = $this->connection();
        SchemaSync::apply($connection, [$this->ordersTable()]);
        DbTable::where('table_name', 'orders')->update([
            'description' => 'Still true.', 'is_enabled' => true,
        ]);
        SchemaSync::apply($connection, []);

        SchemaSync::apply($connection, [$this->ordersTable()]);

        $table = DbTable::where('table_name', 'orders')->first();
        $this->assertTrue($table->is_present);
        $this->assertTrue($table->is_enabled);
        $this->assertSame('Still true.', $table->description);
        $this->assertSame(1, DbTable::count());
    }

    public function test_two_schemas_with_the_same_table_name_stay_separate(): void
    {
        $connection = $this->connection();

        SchemaSync::apply($connection, [
            new DiscoveredTable('public', 'orders', [$this->column('id', 1)]),
            new DiscoveredTable('archive', 'orders', [$this->column('id', 1)]),
        ]);

        $this->assertSame(2, DbTable::count());
    }

    public function test_another_connection_is_untouched(): void
    {
        $connection = $this->connection();
        $other = DbConnection::create([
            'id' => 'dbc_2', 'system_id' => 'sys_test', 'name' => 'Other',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
        SchemaSync::apply($other, [$this->ordersTable()]);

        SchemaSync::apply($connection, []);

        $this->assertTrue(DbTable::where('connection_id', 'dbc_2')->first()->is_present);
    }

    public function test_a_foreign_key_target_is_stored(): void
    {
        $connection = $this->connection();

        SchemaSync::apply($connection, [new DiscoveredTable(null, 'orders', [
            $this->column('id', 1),
            $this->column('customer_id', 2, 'customers.id'),
        ])]);

        $this->assertSame('customers.id',
            DbColumn::where('column_name', 'customer_id')->value('foreign_key_target'));
    }
}
