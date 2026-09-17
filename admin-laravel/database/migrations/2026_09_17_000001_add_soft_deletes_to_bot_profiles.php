<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a bot from its workspace only marks it. The bot and its
 * conversations stay until a super admin restores or purges it from the
 * Bots page, and the engine treats a marked bot as missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
