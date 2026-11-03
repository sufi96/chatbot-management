<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a skill reaches an answer. progressive (the default): loaded only
     * when a request matches it. always: a short rule added to every answer
     * of an agentic bot that has it.
     */
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->string('mode', 20)->default('progressive');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
