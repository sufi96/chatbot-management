<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The header gets a colour of its own, empty meaning it follows the widget
 * colour as before. Each background picture gets an opacity, so its colour
 * can show through: 100 is the picture as uploaded, 0 is the colour alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('widget_header_color', 20)->nullable()->after('widget_primary_color');
            $table->unsignedTinyInteger('widget_header_image_opacity')->default(100)->after('widget_header_image_url');
            $table->unsignedTinyInteger('widget_background_image_opacity')->default(100)->after('widget_background_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['widget_header_color', 'widget_header_image_opacity', 'widget_background_image_opacity']);
        });
    }
};
