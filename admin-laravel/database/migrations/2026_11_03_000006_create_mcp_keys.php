<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keys that let other AI tools (Copilot, Claude, a staff agent) use a
     * workspace's bots through the engine's MCP server at /mcp/. A key is shown
     * once; only its SHA-256 and last four characters are kept.
     */
    public function up(): void
    {
        Schema::create('mcp_keys', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('system_id', 36)->index();
            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->string('hint', 8)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_keys');
    }
};
