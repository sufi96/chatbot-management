<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\ConnectorTool;
use App\Models\System;
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
}
