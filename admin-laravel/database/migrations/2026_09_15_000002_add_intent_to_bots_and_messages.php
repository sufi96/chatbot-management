<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a bot reads each question with the conversation before it, and
     * what that reading decided.
     *
     * Off by default. It costs a model call on every question, and a bot that
     * answers well today must not get slower because of an upgrade.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('intent_enabled')->default(false);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            // On the visitor's row: "facts" or "chat", null when no model read it.
            $table->string('intent', 20)->nullable();
            // What the sources searched for, when it differed from the words sent.
            $table->text('intent_query')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['intent', 'intent_query']);
        });

        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('intent_enabled');
        });
    }
};
