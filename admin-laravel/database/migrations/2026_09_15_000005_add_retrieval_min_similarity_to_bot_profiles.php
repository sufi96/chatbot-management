<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How similar the best passage must be before the knowledge base counts as
     * having an answer, when no reranker is set.
     *
     * The relevance floor cannot say it: fusion ranks nearly every chunk of a
     * small collection in both branches, so an unrelated question scored
     * exactly as a real one and the database behind the knowledge base was
     * never asked. 0.65 is where nomic-embed-text separated the two on the
     * Kedai Aina set (answers 0.69 to 0.83, non-answers 0.44 to 0.63). A
     * different embedding model has a different scale and needs this re-tuned.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->float('retrieval_min_similarity')->default(0.65);
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('retrieval_min_similarity');
        });
    }
};
