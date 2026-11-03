<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\ConnectorTool;
use App\Models\System;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A workspace's outside APIs, and the operations its bots may call. */
class ConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
    }

    private function connector(string $id = 'con_1', string $system = 'sys_test', array $fields = []): Connector
    {
        return Connector::create(array_merge([
            'id' => $id, 'system_id' => $system, 'name' => "API {$id}",
            'base_url' => 'https://api.example.test', 'auth_type' => 'bearer',
            'auth_secret' => 's3cret-token-9876',
        ], $fields));
    }

    public function test_a_connector_hides_its_secret(): void
    {
        $connector = $this->connector();
        ConnectorTool::create(['id' => 'ctl_1', 'connector_id' => 'con_1', 'name' => 'getThing',
            'method' => 'get', 'path' => '/things/{id}', 'writes' => false]);

        $this->assertArrayNotHasKey('auth_secret', $connector->toArray());
        $this->assertSame('…9876', $connector->forPage()['hint']);
        $this->assertSame(1, $connector->forPage()['tools']);

        $connector->delete();
        $this->assertSame(0, ConnectorTool::count());
    }

    public function test_the_console_database_never_reads_connector_secrets(): void
    {
        $this->assertContains('connectors', \App\Services\Schema\ConsoleDatabase::NEVER);
    }

    private function member(string $role, string $system = 'sys_test'): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => "{$role}-{$system}@example.test",
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach($system, ['role' => $role]);

        return $user;
    }

    private function draft(array $overrides = []): array
    {
        return array_merge([
            'system_id' => 'sys_test', 'name' => 'Applications API', 'type' => 'rest',
            'base_url' => 'https://api.example.test', 'auth_type' => 'bearer',
            'auth_secret' => 'very-secret-token-4321',
        ], $overrides);
    }

    private const SPEC = '{"openapi":"3.0.0","paths":{"/applications/{id}":{"get":{"operationId":"getApplication",'
        . '"parameters":[{"name":"id","in":"path","required":true}]}},"/tickets":{"post":{"operationId":"createTicket"}}}}';

    public function test_the_page_lists_the_workspaces_connectors(): void
    {
        $this->connector();
        $this->connector('con_x', 'sys_other');

        $this->actingAs($this->member('viewer'))
            ->withSession(['active_system_id' => 'sys_test'])
            ->get(route('connectors.index'))
            ->assertOk()
            ->assertSee('API con_1')
            ->assertDontSee('API con_x')
            ->assertDontSee('s3cret-token-9876');
    }

    public function test_only_system_admins_manage_connectors(): void
    {
        $connector = $this->connector();
        $tool = ConnectorTool::create(['id' => 'ctl_1', 'connector_id' => 'con_1', 'name' => 'getThing',
            'method' => 'get', 'path' => '/things', 'writes' => false]);
        $editor = $this->actingAs($this->member('editor'));

        $editor->postJson(route('connectors.store'), $this->draft())->assertForbidden();
        $editor->putJson(route('connectors.update', $connector->id), $this->draft())->assertForbidden();
        $editor->deleteJson(route('connectors.destroy', $connector->id))->assertForbidden();
        $editor->postJson(route('connectors.test'), $this->draft())->assertForbidden();
        $editor->postJson(route('connectors.import', $connector->id), ['spec' => self::SPEC])->assertForbidden();
        $editor->putJson(route('connector-tools.update', $tool->id), ['enabled' => true])->assertForbidden();

        $response = $this->actingAs($this->member('system_admin'))
            ->postJson(route('connectors.store'), $this->draft())
            ->assertOk()
            ->assertJsonPath('connector.hint', '…4321');
        $this->assertStringNotContainsString('very-secret-token-4321', $response->getContent());
    }

    public function test_a_blank_secret_on_update_keeps_the_saved_one(): void
    {
        $connector = $this->connector();

        $this->actingAs($this->member('system_admin'))
            ->putJson(route('connectors.update', $connector->id), $this->draft(['name' => 'Renamed', 'auth_secret' => '']))
            ->assertOk();

        $this->assertSame('s3cret-token-9876', $connector->fresh()->auth_secret);
        $this->assertSame('Renamed', $connector->fresh()->name);
    }

    public function test_a_connector_name_is_unique_in_its_workspace(): void
    {
        $this->connector(fields: ['name' => 'Applications API']);
        $this->connector('con_x', 'sys_other', ['name' => 'Elsewhere']);

        $this->actingAs($this->member('system_admin'))
            ->postJson(route('connectors.store'), $this->draft(['name' => 'applications api']))
            ->assertStatus(422);

        $this->actingAs($this->member('system_admin', 'sys_other'))
            ->postJson(route('connectors.store'), $this->draft(['system_id' => 'sys_other']))
            ->assertOk();
    }

    public function test_another_workspaces_connector_is_out_of_reach(): void
    {
        $theirs = $this->connector('con_x', 'sys_other');

        $this->actingAs($this->member('system_admin'))
            ->putJson(route('connectors.update', $theirs->id), $this->draft())
            ->assertForbidden();
    }

    public function test_import_creates_tools_disabled_and_keeps_enabled_ones_on_reimport(): void
    {
        $connector = $this->connector();
        $admin = $this->actingAs($this->member('system_admin'));

        $admin->postJson(route('connectors.import', $connector->id), ['spec' => self::SPEC])->assertOk();

        $tools = $connector->tools()->orderBy('name')->get();
        $this->assertSame(['createTicket', 'getApplication'], $tools->pluck('name')->all());
        $this->assertFalse($tools->contains('enabled', true));
        $this->assertTrue($tools->firstWhere('name', 'createTicket')->writes);

        $get = $tools->firstWhere('name', 'getApplication');
        $admin->putJson(route('connector-tools.update', $get->id), ['enabled' => true])->assertOk();

        $smaller = '{"openapi":"3.0.0","paths":{"/applications/{id}":{"get":{"operationId":"getApplication"}}}}';
        $admin->postJson(route('connectors.import', $connector->id), ['spec' => $smaller])->assertOk();

        $this->assertSame(['getApplication'], $connector->tools()->pluck('name')->all());
        $this->assertTrue($connector->tools()->first()->enabled);
    }

    public function test_import_refuses_yaml_with_a_sentence(): void
    {
        $connector = $this->connector();

        $this->actingAs($this->member('system_admin'))
            ->postJson(route('connectors.import', $connector->id), ['spec' => "openapi: 3.0.0\npaths: {}"])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'The spec must be OpenAPI 3 JSON. YAML is not supported yet.']);
    }

    public function test_test_by_id_sends_the_saved_secret_to_the_engine(): void
    {
        $connector = $this->connector();
        Http::fake(['*/api/v1/connectors/test' => Http::response(['success' => true, 'message' => 'Reached the API (status 200).'])]);

        $this->actingAs($this->member('system_admin'))
            ->postJson(route('connectors.test'), ['connector_id' => $connector->id])
            ->assertOk()
            ->assertJson(['success' => true, 'message' => 'Reached the API (status 200).']);

        Http::assertSent(fn ($request) => $request['auth_secret'] === 's3cret-token-9876'
            && $request['base_url'] === 'https://api.example.test');
    }

    public function test_an_admins_writes_choice_survives_a_reimport_and_can_be_set_on_the_page(): void
    {
        $connector = $this->connector();
        $admin = $this->actingAs($this->member('system_admin'));
        $admin->postJson(route('connectors.import', $connector->id), ['spec' => self::SPEC])->assertOk();

        // A GET that changes something, marked by hand so the visitor confirms it.
        $get = $connector->tools()->where('name', 'getApplication')->first();
        $admin->putJson(route('connector-tools.update', $get->id), ['writes' => true])->assertOk();
        $admin->postJson(route('connectors.import', $connector->id), ['spec' => self::SPEC])->assertOk();

        $this->assertTrue($get->fresh()->writes);

        $admin->withSession(['active_system_id' => 'sys_test'])
            ->get(route('connectors.index'))
            ->assertSee('data-tool-writes="' . $get->id . '"', false);
    }

    public function test_an_mcp_server_is_a_connector_whose_tools_are_imported_from_it(): void
    {
        $connector = $this->connector('con_m', fields: ['type' => 'mcp', 'base_url' => 'http://mcp.test/mcp',
            'auth_type' => 'none', 'auth_secret' => null]);
        Http::fake(['*/api/v1/connectors/mcp-tools' => Http::response(['success' => true, 'tools' => [
            ['name' => 'application_status', 'description' => 'Status', 'parameters' => ['type' => 'object'], 'writes' => false],
            ['name' => 'tickets.create', 'description' => 'Open a ticket', 'parameters' => ['type' => 'object'], 'writes' => true],
        ]])]);

        $this->actingAs($this->member('system_admin'))
            ->postJson(route('connectors.import', $connector->id))
            ->assertOk();

        $tools = $connector->tools()->orderBy('path')->get();
        $this->assertSame(['application_status', 'tickets.create'], $tools->pluck('path')->all());
        $this->assertSame(['application_status', 'tickets_create'], $tools->pluck('name')->all());
        $this->assertSame(['mcp', 'mcp'], $tools->pluck('method')->all());
        $this->assertSame([false, true], $tools->pluck('writes')->all());
        Http::assertSent(fn ($request) => $request['base_url'] === 'http://mcp.test/mcp' && $request['type'] === 'mcp');
    }

    public function test_an_mcp_connector_is_tested_as_one(): void
    {
        $connector = $this->connector('con_m', fields: ['type' => 'mcp', 'base_url' => 'http://mcp.test/mcp']);
        Http::fake(['*/api/v1/connectors/test' => Http::response(['success' => true, 'message' => 'Reached the MCP server (2 tools).'])]);

        $this->actingAs($this->member('system_admin'))
            ->postJson(route('connectors.test'), ['connector_id' => $connector->id])
            ->assertJson(['success' => true]);

        Http::assertSent(fn ($request) => $request['type'] === 'mcp');
    }

    public function test_the_form_offers_mcp(): void
    {
        $this->actingAs($this->member('system_admin'))
            ->withSession(['active_system_id' => 'sys_test'])
            ->get(route('connectors.index'))
            ->assertSee('<option value="mcp">', false);
    }
}
