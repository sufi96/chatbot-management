<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The retrieval upgrades a bot chooses for itself, each off or neutral by
     * default so an existing bot answers exactly as it did:
     *
     * - retrieval_keyword_weight: how much the keyword branch counts against
     *   meaning in rank fusion, 1 being equal.
     * - query_expansion: off, multi_query, hyde or both. See
     *   api-engine/kb/expansion.py.
     * - context_neighbours: passages either side of each hit, from the same
     *   section, handed to the model with it.
     * - cache_*: reuse an answer for a question close enough to one answered
     *   from the knowledge base before. See api-engine/answer_cache.py.
     * - grounding_check: check each sourced answer against its material and
     *   flag unsupported claims. See api-engine/grounding.py.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->float('retrieval_keyword_weight')->default(1.0);
            $table->string('query_expansion', 20)->default('off');
            $table->unsignedTinyInteger('context_neighbours')->default(0);
            $table->boolean('cache_enabled')->default(false);
            $table->float('cache_min_similarity')->default(0.95);
            $table->unsignedSmallInteger('cache_ttl_hours')->default(24);
            $table->boolean('grounding_check')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['retrieval_keyword_weight', 'query_expansion', 'context_neighbours',
                'cache_enabled', 'cache_min_similarity', 'cache_ttl_hours', 'grounding_check']);
        });
    }
};
