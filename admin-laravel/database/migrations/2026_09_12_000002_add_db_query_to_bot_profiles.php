<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage two reads these. They arrive with stage one's migration so the
     * chat path adds no migration of its own and cannot leave a database
     * half way between the two.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('db_query_enabled')->default(false);
            $table->unsignedSmallInteger('db_max_rows')->default(50);
            $table->unsignedSmallInteger('db_query_timeout')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['db_query_enabled', 'db_max_rows', 'db_query_timeout']);
        });
    }
};
