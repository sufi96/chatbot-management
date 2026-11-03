<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bots that call connector tools.
     *
     * - agentic_enabled: off for every bot, so an existing bot answers exactly
     *   as before. On, the bot's tools become a source in its order.
     * - agentic_max_calls: how many tool calls one message may make.
     * - bot_connector_tools: which switched-on operations this bot may call.
     * - chat_tool_calls: every call a bot made or asked to make, for the
     *   transcript, and the action waiting on a visitor's Confirm.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('agentic_enabled')->default(false);
            $table->unsignedTinyInteger('agentic_max_calls')->default(3);
        });

        Schema::create('bot_connector_tools', function (Blueprint $table) {
            $table->string('bot_id', 36);
            $table->string('connector_tool_id', 36);
            $table->foreign('bot_id')->references('id')->on('bot_profiles')->cascadeOnDelete();
            $table->foreign('connector_tool_id')->references('id')->on('connector_tools')->cascadeOnDelete();
            $table->primary(['bot_id', 'connector_tool_id']);
        });

        Schema::create('chat_tool_calls', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
            $table->string('message_id', 36)->nullable()->index();
            $table->string('connector_tool_id', 36)->nullable();
            $table->foreign('connector_tool_id')->references('id')->on('connector_tools')->nullOnDelete();
            $table->string('tool_name', 64);
            $table->json('arguments')->nullable();
            // ran, failed, awaiting_confirm, confirmed, declined, expired
            $table->string('status', 20);
            $table->string('flag', 20)->nullable();
            $table->text('result_excerpt')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_tool_calls');
        Schema::dropIfExists('bot_connector_tools');
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['agentic_enabled', 'agentic_max_calls']);
        });
    }
};
