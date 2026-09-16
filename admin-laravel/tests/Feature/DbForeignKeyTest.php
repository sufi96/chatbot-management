<?php

namespace Tests\Feature;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use App\Models\User;
use App\Services\Schema\DiscoveredColumn;
use App\Services\Schema\DiscoveredTable;
use App\Services\Schema\SchemaSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    private function editor(): User
    {
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    private function connection(): DbConnection
    {
        return DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Legacy',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
    }

    private function ordersWith(?string $target): DbColumn
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        return DbColumn::create([
            'table_id' => $table->id, 'column_name' => 'customer_id',
            'data_type' => 'integer', 'ordinal' => 2, 'foreign_key_target' => $target,
        ]);
    }

    public function test_an_editor_writes_a_relationship_by_hand(): void
    {
        // A legacy database with no constraints. The relationship is real,
        // it is just never declared, and the model cannot guess it.
        $column = $this->ordersWith(null);

        $this->actingAs($this->editor())
            ->put(route('databases.columns.update', $column->id), [
                'description' => 'Who placed the order.',
                'foreign_key_target' => 'customers.id',
            ])
            ->assertRedirect();

        $this->assertSame('customers.id', $column->fresh()->foreign_key_target);
    }

    public function test_a_relationship_can_be_cleared(): void
    {
        $column = $this->ordersWith('wrong.id');

        $this->actingAs($this->editor())
            ->put(route('databases.columns.update', $column->id), [
                'description' => '', 'foreign_key_target' => '',
            ]);

        $this->assertNull($column->fresh()->foreign_key_target);
    }

    public function test_a_hand_added_column_can_carry_a_relationship(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'invoices']);

        $this->actingAs($this->editor())
            ->post(route('databases.columns.store', $table->id), [
                'column_name' => 'order_ref',
                'data_type' => 'varchar',
                'foreign_key_target' => 'orders.id',
            ])
            ->assertRedirect();

        $this->assertSame('orders.id',
            DbColumn::where('column_name', 'order_ref')->value('foreign_key_target'));
    }

    public function test_a_viewer_cannot_write_a_relationship(): void
    {
        $column = $this->ordersWith(null);

        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $viewer->systems()->attach('sys_test', ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->put(route('databases.columns.update', $column->id), [
                'foreign_key_target' => 'customers.id',
            ])
            ->assertForbidden();

        $this->assertNull($column->fresh()->foreign_key_target);
    }

    public function test_a_hand_written_relationship_survives_rediscovery(): void
    {
        // The trap. A discovery run finds no constraint, because there is
        // none, and must not erase what somebody worked out by hand.
        $column = $this->ordersWith('customers.id');
        $connection = DbConnection::find('dbc_1');

        SchemaSync::apply($connection, [new DiscoveredTable(null, 'orders', [
            new DiscoveredColumn('customer_id', 'integer', false, false, null, 2),
        ])]);

        $this->assertSame('customers.id', $column->fresh()->foreign_key_target);
    }

    public function test_a_real_constraint_still_wins(): void
    {
        // If the database does declare one, it is the authority. A stale
        // hand-written guess must not outrank it.
        $column = $this->ordersWith('guessed.id');
        $connection = DbConnection::find('dbc_1');

        SchemaSync::apply($connection, [new DiscoveredTable(null, 'orders', [
            new DiscoveredColumn('customer_id', 'integer', false, false, 'customers.id', 2),
        ])]);

        $this->assertSame('customers.id', $column->fresh()->foreign_key_target);
    }

    public function test_the_editor_offers_a_field_for_it(): void
    {
        $this->ordersWith(null);

        $this->actingAs($this->editor())
            ->get(route('databases.schema', 'dbc_1'))
            ->assertOk()
            ->assertSee('foreign_key_target', false);
    }
}
