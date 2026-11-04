<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files in and out of a conversation.
     *
     * - bot_profiles.file_settings: files in, files out, visuals and retention
     *   for one bot. Null means all off, so a bot never set behaves as before.
     *   The engine reads it through files/settings.py, which clamps it.
     * - chat_files: one file a visitor attached (direction "in") or the bot
     *   handed back (direction "out"). The bytes live in the engine's file
     *   storage under storage_key; text is what was read from an "in" file.
     *   token is the unguessable part of a visitor's download link.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->json('file_settings')->nullable();
        });

        Schema::create('chat_files', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36);
            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
            $table->string('message_id', 36)->nullable()->index();
            $table->string('bot_id', 36)->index();
            $table->string('direction', 3);
            $table->string('name', 255);
            $table->string('mime', 120);
            $table->string('ext', 10);
            $table->unsignedInteger('size');
            $table->string('storage_key', 255);
            $table->string('sha256', 64);
            $table->string('scan_status', 20);
            $table->longText('text')->nullable();
            $table->string('read_error', 500)->nullable();
            $table->string('flag', 20)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_files');

        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('file_settings');
        });
    }
};
