<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_sources', function (Blueprint $table) {
            // What this document is for, in the author's words. It rides along
            // with every chunk, so it is retrieval input, not just a label.
            $table->text('description')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('kb_sources', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
