<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a bot asks its knowledge base and database together and answers
 * from both, rather than from the first source in its order with something.
 * On by default, existing bots included: a question needing a policy and a
 * record is common, and first-hit answered it from the policy alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->boolean('combine_sources')->default(true)->after('source_order');
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('combine_sources');
        });
    }
};
