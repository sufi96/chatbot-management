<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Topics one bot refuses on top of the platform's, one per line. A shop
     * bot may need to stay off competitor pricing that nobody else minds.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->text('guard_topics')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('guard_topics');
        });
    }
};
