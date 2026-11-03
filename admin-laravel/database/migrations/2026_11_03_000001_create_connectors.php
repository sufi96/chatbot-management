<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A workspace's outside APIs, and the operations on them a bot may call.
     *
     * C⁴ calls APIs; it does not build them. A legacy system's API is made in
     * UXP and published through MyGDX, a new system ships its own. A connector
     * is where one lives and how to sign in to it; its tools are the operations,
     * usually imported from an OpenAPI spec, each switched on by an admin.
     *
     * type is 'rest' for now. MyGDX, MCP and SharePoint arrive as more types on
     * the same row rather than as tables of their own.
     */
    public function up(): void
    {
        Schema::create('connectors', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('system_id', 36)->index();
            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 20)->default('rest');
            $table->string('base_url', 500);
            $table->string('auth_type', 20)->default('none');
            $table->string('auth_header', 120)->nullable();
            $table->text('auth_secret')->nullable();
            $table->timestamps();
        });

        Schema::create('connector_tools', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('connector_id', 36)->index();
            $table->foreign('connector_id')->references('id')->on('connectors')->cascadeOnDelete();
            $table->string('name', 64);
            $table->text('description')->nullable();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->json('parameters')->nullable();
            // Anything but a GET changes something, and asks the visitor first.
            $table->boolean('writes')->default(true);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['connector_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_tools');
        Schema::dropIfExists('connectors');
    }
};
