<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Answers a bot may give again. The engine writes and reads the rows;
     * the portal counts them and empties a bot's when its behaviour is saved.
     *
     * The question's embedding is JSON text, not a vector column: a bot holds
     * at most a few hundred rows, compared in the engine, and a vector column's
     * width would have to follow the embedding setting.
     */
    public function up(): void
    {
        Schema::create('answer_cache', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('bot_id', 36)->index();
            $table->foreign('bot_id')->references('id')->on('bot_profiles')->cascadeOnDelete();
            $table->text('question');
            $table->string('embedding_model', 120);
            $table->longText('embedding');
            $table->longText('answer');
            $table->text('citations')->nullable();
            $table->string('source_kind', 20)->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('last_hit_at')->nullable();
            $table->timestamp('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('answer_cache');
    }
};
