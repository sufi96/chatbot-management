<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a bot checks what comes in and what goes out, what it says when
     * a message is refused, and what a check flagged.
     *
     * Off by default: every guarded message costs a model call, and a bot
     * that works today must not get slower because of an upgrade.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('guard_enabled')->default(false);
            // Null means the engine's own polite refusal.
            $table->text('guard_refusal')->nullable();
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            // What the guard named. On the visitor's row for a refused
            // message, on the assistant's row for a flagged answer.
            $table->string('guard_flag', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('guard_flag');
        });

        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['guard_enabled', 'guard_refusal']);
        });
    }
};
