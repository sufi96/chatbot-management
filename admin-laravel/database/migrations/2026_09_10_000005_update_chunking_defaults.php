<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Old default => new default. A value equal to the old one was never chosen. */
    private const MOVES = [
        'chunk_size' => ['900', '1800'],
        'chunk_overlap' => ['150', '200'],
    ];

    public function up(): void
    {
        foreach (self::MOVES as $key => [$old, $new]) {
            DB::table('app_settings')->where('key', $key)->where('value', $old)
                ->update(['value' => $new]);
            Cache::forget("app_setting:{$key}");
        }
    }

    public function down(): void
    {
        foreach (self::MOVES as $key => [$old, $new]) {
            DB::table('app_settings')->where('key', $key)->where('value', $new)
                ->update(['value' => $old]);
            Cache::forget("app_setting:{$key}");
        }
    }
};
