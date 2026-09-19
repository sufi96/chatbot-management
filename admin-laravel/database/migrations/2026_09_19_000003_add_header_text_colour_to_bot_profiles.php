<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The colour of everything drawn on the header: the title, the status line
 * and the clear, expand and close icons. White suits a dark header; a light
 * header picture needs something darker to stay readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('widget_header_text_color', 20)->default('#FFFFFF')->after('widget_header_color');
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('widget_header_text_color');
        });
    }
};
