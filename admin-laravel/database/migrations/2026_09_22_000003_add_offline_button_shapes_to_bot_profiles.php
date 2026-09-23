<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The offline corner button in both states: the launcher's shape, and the
 * open (close) button's picture and shape. Empty follows the online button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('offline_launcher_shape', 30)->nullable()->after('offline_icon_url');
            $table->string('offline_close_icon_url', 500)->nullable()->after('offline_launcher_shape');
            $table->string('offline_close_shape', 30)->nullable()->after('offline_close_icon_url');
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['offline_launcher_shape', 'offline_close_icon_url', 'offline_close_shape']);
        });
    }
};
