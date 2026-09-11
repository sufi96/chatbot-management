<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The web is the fallback when a bot's own documents have nothing, so
     * every bot decides for itself whether to use it, how much of it to read,
     * and which country's sources to favour.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('web_search_enabled')->default(false);
            $table->unsignedSmallInteger('web_search_max_results')->default(3);
            $table->string('web_search_country', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['web_search_enabled', 'web_search_max_results', 'web_search_country']);
        });
    }
};
