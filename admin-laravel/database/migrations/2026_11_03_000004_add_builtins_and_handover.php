<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tools C⁴ carries itself, and the mark one of them leaves.
     *
     * - bot_profiles.builtin_tools: which built-ins an agentic bot may use
     *   (current_datetime, request_human). None by default.
     * - chat_conversations.handover_*: set when request_human asked staff to
     *   follow up, so the Conversations list can show who is waiting.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->json('builtin_tools')->nullable();
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->timestamp('handover_requested_at')->nullable();
            $table->string('handover_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn(['handover_requested_at', 'handover_reason']);
        });

        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('builtin_tools');
        });
    }
};
