<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the engine records about an answer beyond its text: whether it came
     * from the answer cache, and the grounding check's verdict with the claim
     * it found unsupported. grounded is null when the check did not run.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('cache_hit')->default(false);
            $table->boolean('grounded')->nullable();
            $table->text('grounding_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['cache_hit', 'grounded', 'grounding_note']);
        });
    }
};
