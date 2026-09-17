<?php

namespace Tests\Concerns;

use App\Models\DbColumn;
use App\Models\DbConnection;
use App\Models\DbTable;
use App\Services\Schema\ConsoleDatabase;

/**
 * Starts a test with no stored connections at all, for tests that count
 * connections, tables or columns. A migration registers the console's own
 * database (see ConsoleDatabase), which would otherwise be in every count.
 */
trait WithoutConsoleDatabase
{
    protected function setUpWithoutConsoleDatabase(): void
    {
        $tableIds = DbTable::where('connection_id', ConsoleDatabase::ID)->pluck('id');
        DbColumn::whereIn('table_id', $tableIds)->delete();
        DbTable::whereIn('id', $tableIds)->delete();
        DbConnection::whereKey(ConsoleDatabase::ID)->delete();
    }
}
