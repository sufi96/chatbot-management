<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A workspace's own web search keys, and which search each bot uses.
     *
     * Tavily and Brave are paid per query, and until now the only key was the
     * platform's, set in Admin Settings, so every workspace's bots searched on
     * the platform's account. A workspace can now bring its own key, the way it
     * brings its own AI provider, and each bot chooses:
     *
     * - platform: the search set in Admin Settings, as every bot did before.
     *   Admin Settings can stop lending it to workspaces.
     * - duckduckgo: free, no key, rate limited.
     * - own: one of its workspace's keys, by web_search_key_id.
     *
     * A key in use cannot be deleted (the portal refuses); should a row go
     * anyway, the link is nulled and the engine falls back to DuckDuckGo.
     */
    public function up(): void
    {
        Schema::create('web_search_keys', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('system_id', 36)->index();
            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('provider', 20);
            $table->text('api_key');
            $table->timestamps();
        });

        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('web_search_mode', 20)->default('platform');
            $table->string('web_search_key_id', 36)->nullable();
            $table->foreign('web_search_key_id')->references('id')->on('web_search_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropForeign(['web_search_key_id']);
            $table->dropColumn(['web_search_mode', 'web_search_key_id']);
        });

        Schema::dropIfExists('web_search_keys');
    }
};
