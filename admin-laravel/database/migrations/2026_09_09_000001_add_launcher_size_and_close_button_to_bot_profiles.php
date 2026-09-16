<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds sizing for the launcher and a fully configurable close button.
     *
     * A cutout launcher used to be capped at a fixed height, which made tall
     * artwork (a person, a mascot) render far too small. launcher_size lets
     * each profile set that height itself.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('launcher_size')->default(60)->after('launcher_shape');

            $table->string('close_icon_url', 500)->nullable()->after('launcher_size');
            $table->string('close_shape', 30)->default('circle')->after('close_icon_url');
            $table->unsignedSmallInteger('close_size')->default(52)->after('close_shape');
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'launcher_size',
                'close_icon_url',
                'close_shape',
                'close_size',
            ]);
        });
    }
};
