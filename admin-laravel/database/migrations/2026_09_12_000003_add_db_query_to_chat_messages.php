<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The statement that produced an answer, beside the reasoning column
     * phase 5 added. An operator auditing a wrong answer needs to see the
     * query, not guess at it.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->text('db_sql')->nullable();
            $table->unsignedInteger('db_row_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['db_sql', 'db_row_count']);
        });
    }
};
