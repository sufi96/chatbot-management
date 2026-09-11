<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $primaryKey = 'key';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    /** Defaults live here so a fresh install needs no seeding. */
    public const DEFAULTS = [
        'embedding_base_url' => 'http://localhost:11434/v1',
        'embedding_api_key' => '',
        'embedding_model' => 'nomic-embed-text',
        'embedding_dimensions' => '768',
        'vector_driver' => 'pgvector',
        'chunk_size' => '1800',
        'chunk_overlap' => '200',
        'context_char_budget' => '6000',
        'web_search_provider' => 'duckduckgo',
        'web_search_tavily_key' => '',
        'web_search_brave_key' => '',
    ];

    public static function get(string $key, $default = null)
    {
        $stored = Cache::remember("app_setting:{$key}", 300, function () use ($key) {
            // Cache cannot hold null as "known missing", so absence is an empty
            // marker instead.
            return static::query()->find($key)?->value ?? '__missing__';
        });

        if ($stored !== '__missing__') {
            return $stored;
        }

        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget("app_setting:{$key}");
    }
}
