<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_collections', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('system_id', 36);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
        });

        Schema::create('kb_sources', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('collection_id', 36);
            $table->string('type', 20)->default('text');   // text, file, qa; website reserved
            $table->string('title', 500);
            $table->longText('body')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('file_mime', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            $table->foreign('collection_id')->references('id')->on('kb_collections')->cascadeOnDelete();
        });

        Schema::create('kb_chunks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('collection_id', 36)->index();
            $table->string('source_id', 36);
            $table->unsignedInteger('ordinal');
            $table->text('content');
            $table->unsignedInteger('char_count')->default(0);
            $table->string('embedding_model', 120)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('source_id')->references('id')->on('kb_sources')->cascadeOnDelete();
            $table->index(['collection_id', 'source_id']);
        });

        // The embedding column has no Blueprint equivalent, and its type differs
        // per database, so each driver gets its own raw statement.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            DB::statement('ALTER TABLE kb_chunks ADD COLUMN embedding vector(768)');
        } else {
            DB::statement('ALTER TABLE kb_chunks ADD COLUMN embedding blob');
        }

        Schema::create('bot_kb_collection', function (Blueprint $table) {
            $table->id();
            $table->string('bot_id', 36);
            $table->string('collection_id', 36);

            $table->foreign('bot_id')->references('id')->on('bot_profiles')->cascadeOnDelete();
            $table->foreign('collection_id')->references('id')->on('kb_collections')->cascadeOnDelete();
            $table->unique(['bot_id', 'collection_id']);
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 120)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('bot_kb_collection');
        Schema::dropIfExists('kb_chunks');
        Schema::dropIfExists('kb_sources');
        Schema::dropIfExists('kb_collections');
    }
};
