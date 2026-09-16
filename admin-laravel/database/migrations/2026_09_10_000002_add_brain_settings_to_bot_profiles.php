<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('retrieval_enabled')->default(false);
            $table->string('retrieval_mode', 20)->default('hybrid');   // hybrid, vector, keyword
            $table->unsignedSmallInteger('retrieval_top_k')->default(5);
            $table->unsignedSmallInteger('retrieval_candidates')->default(30);
            $table->float('retrieval_min_score')->default(0);
            $table->string('retrieval_fallback', 20)->default('say_unknown'); // or answer_anyway

            $table->float('top_p')->default(1);
            $table->unsignedSmallInteger('top_k_sampling')->nullable();
            $table->float('presence_penalty')->default(0);
            $table->float('frequency_penalty')->default(0);
            $table->string('thinking_level', 10)->default('off');       // off, low, medium, high
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'retrieval_enabled', 'retrieval_mode', 'retrieval_top_k',
                'retrieval_candidates', 'retrieval_min_score', 'retrieval_fallback',
                'top_p', 'top_k_sampling', 'presence_penalty',
                'frequency_penalty', 'thinking_level',
            ]);
        });
    }
};
