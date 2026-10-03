<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bot's voice, all off by default so a bot sounds exactly as before.
     *
     * - voice_output: a speaker on each answer, and the voice menu.
     * - voice_autoplay: answers read aloud as they arrive, until the visitor
     *   turns it off. Browsers allow sound only after the visitor has clicked
     *   something, which sending a message is.
     * - voice_gender, voice_language: the starting voice, one of four
     *   (English or Malay, female or male); auto follows each answer's
     *   language. The visitor can change both.
     * - voice_input: a microphone beside the message box.
     *
     * Where the sound is made is the install's choice under Admin Settings,
     * Voice. See api-engine/speech.py.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('voice_output')->default(false);
            $table->boolean('voice_autoplay')->default(false);
            $table->string('voice_gender', 10)->default('female');
            $table->string('voice_language', 10)->default('auto');
            $table->boolean('voice_input')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['voice_output', 'voice_autoplay', 'voice_gender', 'voice_language', 'voice_input']);
        });
    }
};
