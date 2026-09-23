<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The offline card's look, as one JSON object: background colour, text colour
 * and background picture for its header, body and footer (header_bg,
 * header_text, header_image, ...), plus its own avatar (avatar_image,
 * avatar_emoji, avatar_shape). Missing keys fall back to the default card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->json('offline_style')->nullable()->after('offline_hours');
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('offline_style');
        });
    }
};
