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
        'chunk_size' => '1800',
        'chunk_overlap' => '200',
        'context_char_budget' => '6000',
        'web_search_provider' => 'duckduckgo',
        'web_search_tavily_key' => '',
        'web_search_brave_key' => '',
        // Query work can go to a stronger endpoint than a bot's chat model.
        // Blank means each bot uses its own, which is the supported default.
        'sql_model_base_url' => '',
        'sql_model_api_key' => '',
        'sql_model_name' => '',
        // The same shape for every other job a model does. Blank borrows each
        // bot's own model, except the reranker, which has no stand-in and is
        // skipped. api-engine/roles.py holds that rule.
        'intent_model_base_url' => '',
        'intent_model_api_key' => '',
        'intent_model_name' => '',
        'rerank_model_base_url' => '',
        'rerank_model_api_key' => '',
        'rerank_model_name' => '',
        'guard_model_base_url' => '',
        'guard_model_api_key' => '',
        'guard_model_name' => '',
        'vision_model_base_url' => '',
        'vision_model_api_key' => '',
        'vision_model_name' => '',
        // Empty means the mark shipped with the console. Deliberately outside
        // the settings form's write-every-key loop, so an unrelated save
        // cannot blank somebody's logo.
        'brand_logo_path' => '',
        'brand_icon_path' => '',
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
