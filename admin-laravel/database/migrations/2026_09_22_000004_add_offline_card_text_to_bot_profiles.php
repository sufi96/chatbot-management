<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The offline card's small print: a line under the bot's name ("Typical
 * reply in 4 hours") and a footer note ("Business hours: Mon–Fri, 9am–6pm").
 * Empty hides the line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('offline_subtitle', 120)->nullable()->after('offline_message');
            $table->string('offline_hours', 160)->nullable()->after('offline_subtitle');
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['offline_subtitle', 'offline_hours']);
        });
    }
};
