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
        // A platform provider's id. Blank means Ollama on this machine, at
        // http://localhost:11434/v1, which is what a fresh install has.
        'embedding_provider_id' => '',
        'embedding_model' => 'nomic-embed-text',
        'embedding_dimensions' => '768',
        'chunk_size' => '1800',
        'chunk_overlap' => '200',
        'context_char_budget' => '6000',
        'web_search_provider' => 'duckduckgo',
        'web_search_tavily_key' => '',
        'web_search_brave_key' => '',
        // all: a bot set to the platform's search uses the provider and key
        // above, whatever workspace it is in. none: only the console's own
        // bots do; a workspace bot set to it searches DuckDuckGo instead, and
        // brings its own key for anything better.
        'web_search_lending' => 'all',
        // Query work can go to a stronger provider than a bot's chat model.
        // Blank means each bot uses its own, which is the supported default.
        'sql_model_provider_id' => '',
        'sql_model_name' => '',
        // The same shape for every other job a model does. Blank borrows each
        // bot's own model, except the reranker, which has no stand-in and is
        // skipped. api-engine/roles.py holds that rule.
        'intent_model_provider_id' => '',
        'intent_model_name' => '',
        'rerank_model_provider_id' => '',
        'rerank_model_name' => '',
        'guard_model_provider_id' => '',
        'guard_model_name' => '',
        'vision_model_provider_id' => '',
        'vision_model_name' => '',
        'expand_model_provider_id' => '',
        'expand_model_name' => '',
        'verify_model_provider_id' => '',
        'verify_model_name' => '',
        'context_model_provider_id' => '',
        'context_model_name' => '',
        // How keywords are ranked: bm25 inside the engine, or postgres for the
        // database's own full-text search. api-engine/kb/bm25.py says why.
        'keyword_engine' => 'bm25',
        // on writes a model's sentence of context into every chunk while
        // indexing. Needs a re-index; see api-engine/kb/contextual.py.
        'contextual_chunks' => 'off',
        // Voice. browser speaks and listens on the visitor's own device, with
        // nothing to install. server is any OpenAI-compatible audio server on a
        // platform provider; azure is Azure Speech. The four voice names are
        // the engine's names for English and Malay, female and male; the
        // defaults are Microsoft's, the same in Azure and in openai-edge-tts.
        // See api-engine/speech.py.
        'speech_engine' => 'browser',
        'speech_provider_id' => '',
        'speech_model' => 'tts-1',
        'azure_speech_region' => '',
        'azure_speech_key' => '',
        'voice_en_female' => 'en-US-AvaNeural',
        'voice_en_male' => 'en-US-AndrewNeural',
        'voice_ms_female' => 'ms-MY-YasminNeural',
        'voice_ms_male' => 'ms-MY-OsmanNeural',
        'transcribe_engine' => 'browser',
        'transcribe_provider_id' => '',
        'transcribe_model' => 'whisper-1',
        // Security. server reads a conversation from the engine's own records;
        // client trusts the widget's copy, cleaned. See api-engine/history.py,
        // shield.py and leak.py.
        'history_source' => 'server',
        'injection_shield' => 'block',
        'injection_shield_sources' => 'drop',
        'leak_guard' => 'on',
        // What the guard blocks. Every category on is what it did before it
        // had settings; api-engine/guard.py holds the keys.
        'guard_categories' => 'violence,illegal,sexual,self_harm,hate,personal_data,jailbreak,political,copyright',
        'guard_topics' => '',
        'guard_borderline' => 'allow',
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
