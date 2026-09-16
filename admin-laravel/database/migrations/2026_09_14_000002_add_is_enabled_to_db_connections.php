<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A master switch for a whole database, so an operator can cut a bot off
     * from it without unpicking every table tick. The effective rule becomes
     * connection enabled AND table enabled.
     *
     * Default on, because tables default off. Nothing becomes readable without
     * somebody deliberately ticking a table either way, and defaulting this to
     * off would make that tick silently do nothing.
     */
    public function up(): void
    {
        Schema::table('db_connections', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('db_connections', function (Blueprint $table) {
            $table->dropColumn('is_enabled');
        });
    }
};
