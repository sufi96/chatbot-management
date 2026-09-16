<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which model did each job behind an answer, as JSON such as
     * {"chat": "qwen3.5:4b", "sql": "qwen3-coder:30b"}. Once jobs can run on
     * different machines, "which model said that" stops having one answer.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->text('model_trace')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('model_trace');
        });
    }
};
