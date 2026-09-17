<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * For a provider that silently drops system messages. Some gateways discard
 * the system role, so a bot's prompt, its retrieved context and the SQL
 * writer's schema never reach the model. With this on, the engine sends those
 * instructions inside the user message instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->boolean('merge_system_prompt')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->dropColumn('merge_system_prompt');
        });
    }
};
