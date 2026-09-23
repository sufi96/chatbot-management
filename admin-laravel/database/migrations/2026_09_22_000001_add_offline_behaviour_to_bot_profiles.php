<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the embedded widget does while a bot is switched off: disappear from
 * the page ('hide'), or stay up and show a message with the input disabled
 * ('message').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('offline_mode', 20)->default('hide')->after('is_active');
            $table->text('offline_message')->nullable()->after('offline_mode');
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['offline_mode', 'offline_message']);
        });
    }
};
