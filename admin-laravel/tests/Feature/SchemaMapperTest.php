<?php

namespace Tests\Feature;

use App\Services\Schema\SchemaMapper;
use Tests\TestCase;

class SchemaMapperTest extends TestCase
{
    private function columnRow(array $overrides = []): array
    {
        return array_merge([
            'schema' => 'public',
            'table' => 'orders',
            'column' => 'id',
            'data_type' => 'integer',
            'is_nullable' => 'NO',
            'ordinal' => 1,
        ], $overrides);
    }

    public function test_columns_are_grouped_into_their_table(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(),
            $this->columnRow(['column' => 'total', 'data_type' => 'numeric', 'ordinal' => 2]),
        ], []);

        $this->assertCount(1, $tables);
        $this->assertSame('orders', $tables[0]->name);
        $this->assertSame('public', $tables[0]->schema);
        $this->assertCount(2, $tables[0]->columns);
        $this->assertSame('id', $tables[0]->columns[0]->name);
        $this->assertSame('total', $tables[0]->columns[1]->name);
    }

    public function test_columns_come_back_in_ordinal_order_whatever_the_rows_said(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['column' => 'total', 'ordinal' => 2]),
            $this->columnRow(['column' => 'id', 'ordinal' => 1]),
        ], []);

        $this->assertSame(['id', 'total'], array_map(
            fn ($c) => $c->name, $tables[0]->columns));
    }

    public function test_two_schemas_may_hold_the_same_table_name(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['schema' => 'public']),
            $this->columnRow(['schema' => 'archive']),
        ], []);

        $this->assertCount(2, $tables);
        // Ordered by schema, so archive comes first.
        $this->assertSame('archive', $tables[0]->schema);
        $this->assertSame('public', $tables[1]->schema);
    }

    public function test_nullability_reads_the_ansi_yes_and_no(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['column' => 'id', 'is_nullable' => 'NO', 'ordinal' => 1]),
            $this->columnRow(['column' => 'note', 'is_nullable' => 'YES', 'ordinal' => 2]),
        ], []);

        $this->assertFalse($tables[0]->columns[0]->isNullable);
        $this->assertTrue($tables[0]->columns[1]->isNullable);
    }

    public function test_a_primary_key_constraint_marks_its_column(): void
    {
        $tables = SchemaMapper::fromRows([$this->columnRow()], [[
            'schema' => 'public', 'table' => 'orders', 'column' => 'id',
            'constraint_type' => 'PRIMARY KEY',
            'ref_schema' => null, 'ref_table' => null, 'ref_column' => null,
        ]]);

        $this->assertTrue($tables[0]->columns[0]->isPrimaryKey);
        $this->assertNull($tables[0]->columns[0]->foreignKeyTarget);
    }

    public function test_a_foreign_key_becomes_a_qualified_target(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['column' => 'customer_id', 'ordinal' => 2]),
        ], [[
            'schema' => 'public', 'table' => 'orders', 'column' => 'customer_id',
            'constraint_type' => 'FOREIGN KEY',
            'ref_schema' => 'public', 'ref_table' => 'customers', 'ref_column' => 'id',
        ]]);

        $this->assertSame('public.customers.id', $tables[0]->columns[0]->foreignKeyTarget);
    }

    public function test_an_unqualified_foreign_key_drops_the_schema(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['schema' => null, 'column' => 'customer_id']),
        ], [[
            'schema' => null, 'table' => 'orders', 'column' => 'customer_id',
            'constraint_type' => 'FOREIGN KEY',
            'ref_schema' => null, 'ref_table' => 'customers', 'ref_column' => 'id',
        ]]);

        $this->assertSame('customers.id', $tables[0]->columns[0]->foreignKeyTarget);
    }

    public function test_a_constraint_for_an_unknown_column_is_ignored(): void
    {
        // A restricted account can see constraints on tables whose columns it
        // cannot list. That must not invent a column.
        $tables = SchemaMapper::fromRows([$this->columnRow()], [[
            'schema' => 'public', 'table' => 'invoices', 'column' => 'id',
            'constraint_type' => 'PRIMARY KEY',
            'ref_schema' => null, 'ref_table' => null, 'ref_column' => null,
        ]]);

        $this->assertCount(1, $tables);
        $this->assertSame('orders', $tables[0]->name);
    }

    public function test_a_composite_primary_key_marks_every_column(): void
    {
        $tables = SchemaMapper::fromRows([
            $this->columnRow(['column' => 'order_id', 'ordinal' => 1]),
            $this->columnRow(['column' => 'line_no', 'ordinal' => 2]),
        ], [
            ['schema' => 'public', 'table' => 'orders', 'column' => 'order_id',
             'constraint_type' => 'PRIMARY KEY', 'ref_schema' => null,
             'ref_table' => null, 'ref_column' => null],
            ['schema' => 'public', 'table' => 'orders', 'column' => 'line_no',
             'constraint_type' => 'PRIMARY KEY', 'ref_schema' => null,
             'ref_table' => null, 'ref_column' => null],
        ]);

        $this->assertTrue($tables[0]->columns[0]->isPrimaryKey);
        $this->assertTrue($tables[0]->columns[1]->isPrimaryKey);
    }

    public function test_no_rows_is_no_tables_not_an_error(): void
    {
        $this->assertSame([], SchemaMapper::fromRows([], []));
    }
}
