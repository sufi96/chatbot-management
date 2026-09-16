<?php

namespace Tests\Feature;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Models\System;
use App\Models\User;
use App\Services\Schema\ProbeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbSchemaControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'schema') . '.sqlite';
        touch($this->path);

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => "{$role}@example.test",
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => $role]);

        return $user;
    }

    private function connection(): DbConnection
    {
        $connection = DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Shop',
            'driver' => 'sqlite', 'database' => $this->path,
        ]);

        $probe = ProbeConnection::open($connection);
        $probe->statement('CREATE TABLE customers (id integer primary key, name text not null)');
        $probe->statement('CREATE TABLE orders (
            id integer primary key,
            customer_id integer references customers(id),
            total numeric
        )');

        return $connection;
    }

    public function test_the_schema_screen_opens_for_a_viewer(): void
    {
        $this->connection();

        $this->actingAs($this->userWithRole('viewer'))
            ->get(route('databases.schema', 'dbc_1'))
            ->assertOk()
            ->assertSee('Shop');
    }

    public function test_introspecting_stores_the_discovered_schema(): void
    {
        $this->connection();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.introspect', 'dbc_1'))
            ->assertRedirect(route('databases.schema', 'dbc_1'));

        $this->assertSame(2, DbTable::where('connection_id', 'dbc_1')->count());
        $this->assertSame(5, DbColumn::count());
        $this->assertNotNull(DbConnection::find('dbc_1')->last_introspected_at);
    }

    public function test_a_viewer_cannot_introspect(): void
    {
        $this->connection();

        $this->actingAs($this->userWithRole('viewer'))
            ->post(route('databases.introspect', 'dbc_1'))
            ->assertForbidden();

        $this->assertSame(0, DbTable::count());
    }

    public function test_a_failing_introspection_reports_rather_than_throws(): void
    {
        DbConnection::create([
            'id' => 'dbc_2', 'system_id' => 'sys_test', 'name' => 'Nowhere',
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
            'database' => 'nope', 'username' => 'x', 'password' => 'y',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.introspect', 'dbc_2'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('failed', DbConnection::find('dbc_2')->status);
    }

    public function test_an_editor_annotates_and_enables_a_table(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.tables.update', $table->id), [
                'description' => 'Orders placed through the web shop.',
                'is_enabled' => '1',
            ])
            ->assertRedirect();

        $fresh = $table->fresh();
        $this->assertSame('Orders placed through the web shop.', $fresh->description);
        $this->assertTrue($fresh->is_enabled);
    }

    public function test_an_unticked_checkbox_disables_a_table(): void
    {
        $this->connection();
        $table = DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'orders', 'is_enabled' => true,
        ]);

        // An unticked checkbox sends nothing at all, which must mean off.
        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.tables.update', $table->id), ['description' => 'Orders.']);

        $this->assertFalse($table->fresh()->is_enabled);
    }

    public function test_a_viewer_cannot_annotate(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        $this->actingAs($this->userWithRole('viewer'))
            ->put(route('databases.tables.update', $table->id), ['description' => 'Mine now.'])
            ->assertForbidden();

        $this->assertNull($table->fresh()->description);
    }

    public function test_an_editor_annotates_a_column(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);
        $column = DbColumn::create([
            'table_id' => $table->id, 'column_name' => 'total', 'ordinal' => 1,
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.columns.update', $column->id), [
                'description' => 'Gross amount in ringgit, including tax.',
            ])
            ->assertRedirect();

        $this->assertSame('Gross amount in ringgit, including tax.',
            $column->fresh()->description);
    }

    public function test_a_table_can_be_added_by_hand(): void
    {
        $this->connection();

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.tables.store', 'dbc_1'), [
                'table_name' => 'legacy_invoices',
                'schema_name' => '',
                'description' => 'Pre-2020 invoices the reader account cannot list.',
            ])
            ->assertRedirect();

        $table = DbTable::where('table_name', 'legacy_invoices')->first();
        $this->assertNotNull($table);
        $this->assertNull($table->schema_name);
        $this->assertFalse($table->is_enabled);
    }

    public function test_a_hand_added_table_cannot_duplicate_an_existing_one(): void
    {
        $this->connection();
        DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.tables.store', 'dbc_1'), ['table_name' => 'orders'])
            ->assertSessionHasErrors('table_name');

        $this->assertSame(1, DbTable::count());
    }

    public function test_a_column_can_be_added_by_hand(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'legacy']);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.columns.store', $table->id), [
                'column_name' => 'invoice_no',
                'data_type' => 'varchar',
                'description' => 'The printed invoice number.',
            ])
            ->assertRedirect();

        $column = DbColumn::where('column_name', 'invoice_no')->first();
        $this->assertSame('varchar', $column->data_type);
        $this->assertSame(1, $column->ordinal);
    }

    public function test_a_hand_added_column_takes_the_next_ordinal(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'legacy']);
        DbColumn::create(['table_id' => $table->id, 'column_name' => 'a', 'ordinal' => 7]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.columns.store', $table->id), ['column_name' => 'b']);

        $this->assertSame(8, DbColumn::where('column_name', 'b')->value('ordinal'));
    }

    public function test_an_editor_deletes_an_absent_table(): void
    {
        $this->connection();
        $table = DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'gone', 'is_present' => false,
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->delete(route('databases.tables.destroy', $table->id))
            ->assertRedirect();

        $this->assertSame(0, DbTable::count());
    }

    public function test_the_headers_count_readable_tables_and_columns(): void
    {
        // A real system has hundreds of tables, so the counts are how an
        // operator knows where they are without scrolling the list.
        $this->connection();
        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.introspect', 'dbc_1'));

        DbTable::where('table_name', 'orders')->update(['is_enabled' => true]);

        $response = $this->actingAs($this->userWithRole('viewer'))
            ->get(route('databases.schema', ['dbc_1', 'table' => DbTable::where('table_name', 'orders')->value('id')]))
            ->assertOk();

        // Two tables discovered, one of them ticked.
        $response->assertSee('1 / 2');
        // orders has id, customer_id and total.
        $response->assertSee('3 columns');
    }

    public function test_both_table_states_are_labelled(): void
    {
        $this->connection();
        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.introspect', 'dbc_1'));

        DbTable::where('table_name', 'orders')->update(['is_enabled' => true]);

        $this->actingAs($this->userWithRole('viewer'))
            ->get(route('databases.schema', 'dbc_1'))
            ->assertOk()
            ->assertSee('Readable')
            ->assertSee('Ignored');
    }

    public function test_another_workspaces_schema_is_out_of_reach(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        $other = DbConnection::create([
            'id' => 'dbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
        $table = DbTable::create(['connection_id' => $other->id, 'table_name' => 'secrets']);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.tables.update', $table->id), ['description' => 'Mine now.'])
            ->assertForbidden();

        $this->assertNull($table->fresh()->description);
    }

    public function test_ticking_readable_for_all_makes_every_table_readable(): void
    {
        $this->connection();
        DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);
        DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'customers']);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.tables.readable', 'dbc_1'), ['enabled' => '1'])
            ->assertRedirect();

        $this->assertSame(2, DbTable::where('connection_id', 'dbc_1')
            ->where('is_enabled', true)->count());
    }

    public function test_unticking_readable_for_all_ignores_every_table(): void
    {
        $this->connection();
        DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'orders', 'is_enabled' => true,
        ]);
        DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'customers', 'is_enabled' => true,
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.tables.readable', 'dbc_1'), ['enabled' => '0']);

        $this->assertSame(0, DbTable::where('connection_id', 'dbc_1')
            ->where('is_enabled', true)->count());
    }

    public function test_a_viewer_cannot_make_every_table_readable(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        $this->actingAs($this->userWithRole('viewer'))
            ->post(route('databases.tables.readable', 'dbc_1'), ['enabled' => '1'])
            ->assertForbidden();

        $this->assertFalse($table->fresh()->is_enabled);
    }

    public function test_making_every_table_readable_leaves_another_connection_alone(): void
    {
        $this->connection();
        DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        $neighbour = DbConnection::create([
            'id' => 'dbc_2', 'system_id' => 'sys_test', 'name' => 'Warehouse',
            'driver' => 'sqlite', 'database' => ':memory:',
        ]);
        $theirs = DbTable::create([
            'connection_id' => $neighbour->id, 'table_name' => 'stock',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('databases.tables.readable', 'dbc_1'), ['enabled' => '1']);

        $this->assertFalse($theirs->fresh()->is_enabled);
    }

    public function test_one_save_annotates_every_column_at_once(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);
        $total = DbColumn::create([
            'table_id' => $table->id, 'column_name' => 'total', 'ordinal' => 1,
        ]);
        $customer = DbColumn::create([
            'table_id' => $table->id, 'column_name' => 'customer_id', 'ordinal' => 2,
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.tables.update', $table->id), [
                'description' => 'Orders placed through the web shop.',
                'is_enabled' => '1',
                'columns' => [
                    $total->id => ['description' => 'Gross amount in ringgit.'],
                    $customer->id => [
                        'description' => 'Who placed it.',
                        'foreign_key_target' => 'customers.id',
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertSame('Gross amount in ringgit.', $total->fresh()->description);
        $this->assertSame('Who placed it.', $customer->fresh()->description);
        $this->assertSame('customers.id', $customer->fresh()->foreign_key_target);
        $this->assertTrue($table->fresh()->is_enabled);
    }

    public function test_one_save_clears_an_emptied_relationship(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);
        $column = DbColumn::create([
            'table_id' => $table->id, 'column_name' => 'customer_id', 'ordinal' => 1,
            'foreign_key_target' => 'customers.id',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.tables.update', $table->id), [
                'columns' => [
                    $column->id => ['description' => 'Who placed it.', 'foreign_key_target' => ''],
                ],
            ]);

        $this->assertNull($column->fresh()->foreign_key_target);
    }

    public function test_one_save_leaves_a_column_of_another_table_alone(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);
        $elsewhere = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'customers']);
        $theirs = DbColumn::create([
            'table_id' => $elsewhere->id, 'column_name' => 'name', 'ordinal' => 1,
            'description' => 'The customer name.',
        ]);

        // A posted id is not proof of ownership, whoever typed it.
        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.tables.update', $table->id), [
                'columns' => [$theirs->id => ['description' => 'Mine now.']],
            ]);

        $this->assertSame('The customer name.', $theirs->fresh()->description);
    }

    public function test_one_save_reports_how_many_columns_changed(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);
        $touched = DbColumn::create([
            'table_id' => $table->id, 'column_name' => 'total', 'ordinal' => 1,
        ]);
        $untouched = DbColumn::create([
            'table_id' => $table->id, 'column_name' => 'id', 'ordinal' => 2,
            'description' => 'The order number.',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->put(route('databases.tables.update', $table->id), [
                'columns' => [
                    $touched->id => ['description' => 'Gross amount in ringgit.'],
                    $untouched->id => ['description' => 'The order number.'],
                ],
            ])
            ->assertSessionHas('success', 'Saved. 1 column updated.');
    }

    public function test_the_columns_are_saved_by_one_form_not_one_each(): void
    {
        $this->connection();
        $table = DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);
        $column = DbColumn::create([
            'table_id' => $table->id, 'column_name' => 'total', 'ordinal' => 1,
        ]);

        $response = $this->actingAs($this->userWithRole('editor'))
            ->get(route('databases.schema', ['dbc_1', 'table' => $table->id]))
            ->assertOk()
            ->assertSee('name="columns[' . $column->id . '][description]"', false)
            ->assertSee('Save changes');

        // One save means one PUT form on the page, not one per column.
        $this->assertSame(1, substr_count($response->getContent(), 'name="_method" value="PUT"'));
    }

    public function test_the_table_list_offers_one_tick_for_all_of_them(): void
    {
        $this->connection();
        DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('databases.schema', 'dbc_1'))
            ->assertOk()
            ->assertSee('All tables readable')
            ->assertSee(route('databases.tables.readable', 'dbc_1'), false);
    }

    public function test_a_viewer_is_not_offered_the_tick_for_all_tables(): void
    {
        $this->connection();
        DbTable::create(['connection_id' => 'dbc_1', 'table_name' => 'orders']);

        $this->actingAs($this->userWithRole('viewer'))
            ->get(route('databases.schema', 'dbc_1'))
            ->assertOk()
            ->assertDontSee(route('databases.tables.readable', 'dbc_1'), false);
    }

    public function test_the_readable_tables_are_listed_first(): void
    {
        $this->connection();
        DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'archive_ignored',
        ]);
        DbTable::create([
            'connection_id' => 'dbc_1', 'table_name' => 'zebra_readable',
            'is_enabled' => true,
        ]);

        $html = $this->actingAs($this->userWithRole('editor'))
            ->get(route('databases.schema', 'dbc_1'))
            ->assertOk()
            ->getContent();

        // What a bot may read is what an operator came to look at.
        $this->assertLessThan(
            strpos($html, 'archive_ignored'),
            strpos($html, 'zebra_readable'));
    }

    public function test_the_tables_stay_alphabetical_within_readable_and_ignored(): void
    {
        $this->connection();
        foreach (['b_on' => true, 'a_on' => true, 'd_off' => false, 'c_off' => false] as $name => $on) {
            DbTable::create([
                'connection_id' => 'dbc_1', 'table_name' => $name, 'is_enabled' => $on,
            ]);
        }

        $html = $this->actingAs($this->userWithRole('editor'))
            ->get(route('databases.schema', 'dbc_1'))
            ->assertOk()
            ->getContent();

        $order = ['a_on', 'b_on', 'c_off', 'd_off'];
        $positions = array_map(fn ($name) => strpos($html, $name), $order);
        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions);
    }
}
