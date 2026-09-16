<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The vector store setting is gone: the engine uses the store that matches
     * the database it is connected to, because a setting that disagreed could
     * only break search, and its pgvector default did on every SQLite install.
     * A stored value would be read by nothing, so it goes too.
     */
    public function up(): void
    {
        DB::table('app_settings')->where('key', 'vector_driver')->delete();
        Cache::forget('app_setting:vector_driver');
    }

    /**
     * Nothing to put back. No code reads the setting, so restoring a guess at
     * its old value would only reintroduce a row that means nothing.
     */
    public function down(): void
    {
    }
};
