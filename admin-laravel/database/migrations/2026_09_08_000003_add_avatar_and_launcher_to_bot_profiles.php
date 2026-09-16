<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('launcher_icon_url', 500)->nullable()->after('widget_position');
            $table->string('bot_avatar_url', 500)->nullable()->after('launcher_icon_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['launcher_icon_url', 'bot_avatar_url']);
        });
    }
};
