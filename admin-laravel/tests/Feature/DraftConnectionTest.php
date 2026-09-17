<?php

namespace Tests\Feature;

use App\Models\DbConnection;
use App\Models\System;
use App\Services\Schema\DraftConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithoutConsoleDatabase;
use Tests\TestCase;

class DraftConnectionTest extends TestCase
{
    use RefreshDatabase, WithoutConsoleDatabase;

    private function stored(): DbConnection
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);

        return DbConnection::create([
            'id' => 'dbc_1', 'system_id' => 'sys_test', 'name' => 'Orders',
            'driver' => 'mysql', 'host' => 'db.internal', 'database' => 'shop',
            'username' => 'reader', 'password' => 'hunter2',
        ]);
    }

    private function fields(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'mysql',
            'host' => 'db.internal',
            'port' => 3307,
            'database' => 'shop',
            'username' => 'reader',
            'password' => 'typed',
        ], $overrides);
    }

    public function test_a_draft_carries_the_typed_fields(): void
    {
        $draft = DraftConnection::build($this->fields());

        $this->assertSame('mysql', $draft->driver);
        $this->assertSame('db.internal', $draft->host);
        $this->assertSame(3307, (int) $draft->port);
        $this->assertSame('shop', $draft->database);
        $this->assertSame('reader', $draft->username);
        $this->assertSame('typed', $draft->password);
    }

    public function test_a_draft_is_never_persisted(): void
    {
        $draft = DraftConnection::build($this->fields());

        $this->assertFalse($draft->exists);
        $this->assertSame(0, DbConnection::count());
    }

    public function test_a_blank_password_falls_back_to_the_stored_one(): void
    {
        // The form never renders the stored password, so blank has to mean
        // "the one already saved" or Test would always fail on an edit.
        $draft = DraftConnection::build($this->fields(['password' => '']), $this->stored());

        $this->assertSame('hunter2', $draft->password);
    }

    public function test_a_missing_password_key_also_falls_back(): void
    {
        $fields = $this->fields();
        unset($fields['password']);

        $draft = DraftConnection::build($fields, $this->stored());

        $this->assertSame('hunter2', $draft->password);
    }

    public function test_a_typed_password_wins_over_the_stored_one(): void
    {
        $draft = DraftConnection::build($this->fields(['password' => 'newpass']), $this->stored());

        $this->assertSame('newpass', $draft->password);
    }

    public function test_a_blank_password_with_nothing_stored_stays_empty(): void
    {
        $draft = DraftConnection::build($this->fields(['password' => '']));

        $this->assertNull($draft->password);
    }

    public function test_options_default_to_an_empty_array(): void
    {
        $fields = $this->fields();
        unset($fields['options']);

        $this->assertSame([], DraftConnection::build($fields)->options);
    }

    public function test_options_are_carried_through(): void
    {
        $draft = DraftConnection::build($this->fields([
            'driver' => 'pgsql', 'options' => ['sslmode' => 'require'],
        ]));

        $this->assertSame(['sslmode' => 'require'], $draft->options);
    }
}
