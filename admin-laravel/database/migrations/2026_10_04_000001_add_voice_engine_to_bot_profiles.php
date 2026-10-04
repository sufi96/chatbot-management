<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where one bot's voice is made, over the install's choice:
     *
     * - default: the install's engine (Admin Settings, Voice), as every bot did before.
     * - browser: each visitor's own device.
     * - server: the install's speech server, even when the install defaults to the browser.
     * - azure: the install's Azure Speech resource.
     *
     * The server and Azure details stay in Admin Settings; a bot only picks
     * among them. See api-engine/speech.py, engine_for_bot.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('voice_engine', 10)->default('default');
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('voice_engine');
        });
    }
};
