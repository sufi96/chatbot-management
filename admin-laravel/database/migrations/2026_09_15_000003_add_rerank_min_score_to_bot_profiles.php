<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How relevant a passage must be once a reranker has scored it. Unlike the
     * fusion floor this is a real relevance measure, between 0 and 1, and it
     * is only read when the install has a reranker configured.
     *
     * 0.1 is deliberately lenient: rerankers such as bge-reranker-v2-m3 put
     * an unrelated passage far below it, and a floor set too high turns real
     * answers into misses that hand the question to the web.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->float('rerank_min_score')->default(0.1);
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('rerank_min_score');
        });
    }
};
