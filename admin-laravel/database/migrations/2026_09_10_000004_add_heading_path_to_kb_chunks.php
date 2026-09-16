<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_chunks', function (Blueprint $table) {
            // Nullable because chunks indexed before structure-aware chunking
            // have no path until they are re-indexed.
            $table->string('heading_path', 500)->nullable()->after('char_count');
        });
    }

    public function down(): void
    {
        Schema::table('kb_chunks', function (Blueprint $table) {
            $table->dropColumn('heading_path');
        });
    }
};
