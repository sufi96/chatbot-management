<?php

use App\Support\SourceOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which source a bot consults first. Existing bots get the order that
     * matches what they did before: their own documents, then live data,
     * then the open web.
     */
    public function up(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('source_order', 64)->default(SourceOrder::DEFAULT);
        });
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn('source_order');
        });
    }
};
