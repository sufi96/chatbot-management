<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres has no unsigned types, so unsignedSmallInteger lands as a
     * signed smallint capped at 32767. A perfectly ordinary high port such as
     * 50075 passed form validation and then failed on insert with "value out
     * of range for type smallint". The TCP range needs 65535, so the column
     * has to be an integer.
     */
    public function up(): void
    {
        Schema::table('db_connections', function (Blueprint $table) {
            $table->unsignedInteger('port')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('db_connections', function (Blueprint $table) {
            $table->unsignedSmallInteger('port')->nullable()->change();
        });
    }
};
