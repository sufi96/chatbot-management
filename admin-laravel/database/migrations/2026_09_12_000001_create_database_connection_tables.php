<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A workspace's own database, and the schema somebody annotated so a
     * model can write sensible SQL against it. Scoped to a system exactly as
     * kb_collections are, and attached to bots through a pivot shaped like
     * bot_kb_collection.
     */
    public function up(): void
    {
        Schema::create('db_connections', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('system_id', 36);
            $table->string('name', 255);
            $table->string('driver', 20);                  // mysql, pgsql, sqlsrv, sqlite
            $table->string('host', 255)->nullable();       // null for sqlite
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('database', 255);               // a file path when sqlite
            $table->string('username', 255)->nullable();
            $table->text('password')->nullable();          // encrypted cast on the model
            $table->json('options')->nullable();
            $table->string('status', 20)->default('untested');  // untested, ok, failed
            $table->text('error_message')->nullable();
            $table->timestamp('last_introspected_at')->nullable();
            $table->timestamps();

            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
        });

        Schema::create('db_tables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('connection_id', 36);
            $table->string('schema_name', 128)->nullable();
            $table->string('table_name', 128);
            $table->text('description')->nullable();
            // is_enabled is the allowlist. Nothing reaches a model without it.
            $table->boolean('is_enabled')->default(false);
            // Absent rather than deleted, so an annotation survives a schema
            // change or a permissions blip.
            $table->boolean('is_present')->default(true);
            $table->timestamps();

            $table->foreign('connection_id')->references('id')->on('db_connections')->cascadeOnDelete();
            $table->unique(['connection_id', 'schema_name', 'table_name'], 'db_tables_unique_name');
        });

        Schema::create('db_columns', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('table_id');
            $table->string('column_name', 128);
            $table->string('data_type', 64)->nullable();   // null when added by hand
            $table->boolean('is_nullable')->default(true);
            $table->boolean('is_primary_key')->default(false);
            $table->string('foreign_key_target', 255)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('ordinal')->default(0);
            $table->boolean('is_present')->default(true);
            $table->timestamps();

            $table->foreign('table_id')->references('id')->on('db_tables')->cascadeOnDelete();
            $table->unique(['table_id', 'column_name'], 'db_columns_unique_name');
        });

        Schema::create('bot_db_connection', function (Blueprint $table) {
            $table->id();
            $table->string('bot_id', 36);
            $table->string('connection_id', 36);

            $table->foreign('bot_id')->references('id')->on('bot_profiles')->cascadeOnDelete();
            $table->foreign('connection_id')->references('id')->on('db_connections')->cascadeOnDelete();
            $table->unique(['bot_id', 'connection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_db_connection');
        Schema::dropIfExists('db_columns');
        Schema::dropIfExists('db_tables');
        Schema::dropIfExists('db_connections');
    }
};
