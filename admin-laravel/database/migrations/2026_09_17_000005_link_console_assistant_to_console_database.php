<?php

use App\Models\DbConnection;
use App\Services\Schema\ConsoleDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the console assistant this console's own database to answer from:
 * workspaces, bots, conversations and knowledge, read-only, with the tables
 * that hold secrets shut. See ConsoleDatabase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('db_connections', function (Blueprint $table) {
            // A platform connection has no workspace.
            $table->string('system_id', 36)->nullable()->change();
        });

        ConsoleDatabase::ensure();
    }

    public function down(): void
    {
        DbConnection::whereKey(ConsoleDatabase::ID)->delete();
    }
};
