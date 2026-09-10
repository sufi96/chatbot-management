<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** A floor still at exactly zero was never chosen; it is the old default. */
    public function up(): void
    {
        DB::table('bot_profiles')->where('retrieval_min_score', 0)
            ->update(['retrieval_min_score' => 0.01]);
    }

    public function down(): void
    {
        DB::table('bot_profiles')->where('retrieval_min_score', 0.01)
            ->update(['retrieval_min_score' => 0]);
    }
};
