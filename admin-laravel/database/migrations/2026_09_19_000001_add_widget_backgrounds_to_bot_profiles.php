<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pictures and colour behind the chat. The header can carry an image in place
 * of the widget colour, and the message area takes its own colour and an
 * optional image. The widget colour still paints the launcher, the visitor's
 * bubbles and the send button, where a picture would be unreadable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('widget_header_image_url', 500)->nullable()->after('widget_primary_color');
            $table->string('widget_background_color', 20)->default('#FAFAFA')->after('widget_header_image_url');
            $table->string('widget_background_image_url', 500)->nullable()->after('widget_background_color');
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['widget_header_image_url', 'widget_background_color', 'widget_background_image_url']);
        });
    }
};
