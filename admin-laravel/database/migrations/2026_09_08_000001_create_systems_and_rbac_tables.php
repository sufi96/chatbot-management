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
        // Systems / Workspaces table
        Schema::create('systems', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('allowed_origins')->default('*');
            $table->timestamps();
        });

        // Pivot table for User-System RBAC assignments
        Schema::create('system_user', function (Blueprint $table) {
            $table->id();
            $table->string('system_id', 36);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('viewer'); // 'system_admin', 'editor', 'viewer'
            $table->timestamps();

            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
            $table->unique(['system_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_user');
        Schema::dropIfExists('systems');
    }
};
