<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A reasoning model narrates before it answers. That narration is kept
     * apart from the answer so the transcript shows what the visitor read,
     * and the thinking stays available behind a disclosure.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->text('reasoning')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('reasoning');
        });
    }
};
