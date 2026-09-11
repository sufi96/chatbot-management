<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A floor of 0.01 sits below the noise.
     *
     * Hybrid retrieval scores an unrelated top hit at about 0.0164 from a
     * single branch, and only reaches about 0.033 when both branches agree.
     * Anything below 0.0164 therefore lets passages about the wrong subject
     * through on every question.
     *
     * That was survivable before the web was a source. It is not now: the
     * knowledge base never comes back empty, so the web fallback never runs
     * and a bot keeps answering from documents that have nothing to do with
     * the question. 0.02 sits clearly between the two levels.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->float('retrieval_min_score')->default(0.02)->change();
        });

        DB::table('bot_profiles')
            ->where('retrieval_min_score', '<', 0.0164)
            ->update(['retrieval_min_score' => 0.02]);
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->float('retrieval_min_score')->default(0.01)->change();
        });
    }
};
