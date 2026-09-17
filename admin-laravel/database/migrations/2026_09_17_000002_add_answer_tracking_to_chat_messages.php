<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a bot's analytics page needs to know about each answer and the
     * rest of the row does not say: which source answered, what it cited,
     * and how long the visitor waited. The engine writes them.
     *
     * Null on every answer written before this, which the page counts as
     * untracked rather than guessing.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // documents, database, web, none (searched, nothing found),
            // model (nothing searched) or refused.
            $table->string('source_kind', 20)->nullable();
            // The citations the widget was sent, as JSON.
            $table->text('citations')->nullable();
            $table->unsignedInteger('first_token_ms')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['source_kind', 'citations', 'first_token_ms', 'response_ms']);
        });
    }
};
