<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bot_profiles', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('system_id', 36);
            $table->string('name');
            $table->text('system_prompt')->nullable();
            $table->string('provider_type', 50)->default('ollama'); // 'ollama' or 'custom'
            $table->string('base_url', 500)->default('http://localhost:11434/v1');
            $table->string('api_key', 500)->nullable();
            $table->string('model_name', 255)->default('llama3.2');
            $table->float('temperature')->default(0.7);
            $table->integer('max_tokens')->default(1024);
            $table->string('widget_title', 255)->default('AI Assistant');
            $table->text('widget_greeting')->nullable();
            $table->string('widget_primary_color', 20)->default('#4F46E5');
            $table->string('widget_position', 20)->default('bottom-right');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
        });

        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('bot_id', 36);
            $table->string('session_id', 100);
            $table->string('origin', 500)->nullable();
            $table->timestamps();

            $table->foreign('bot_id')->references('id')->on('bot_profiles')->cascadeOnDelete();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36);
            $table->string('sender', 20); // 'user', 'assistant', 'system'
            $table->text('content');
            $table->integer('tokens_used')->default(0);
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
        Schema::dropIfExists('bot_profiles');
    }
};
