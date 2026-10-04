<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a provider serves: chat, embedding, rerank, speech, transcription. Each
 * picker lists only the providers that serve its job, so a speech server is
 * never offered as a bot's language model. See AiProvider::PURPOSES.
 *
 * Stored as a JSON array in a text column, so one LIKE finds a purpose on
 * SQLite and PostgreSQL alike. Null means not yet categorised, and counts as
 * serving everything.
 *
 * Existing providers are tagged from what already uses them, so every saved
 * link stays valid; one nothing uses becomes chat, what most providers are.
 */
return new class extends Migration
{
    private const LINKS = [
        'embedding_provider_id' => 'embedding',
        'speech_provider_id' => 'speech',
        'transcribe_provider_id' => 'transcription',
        'rerank_model_provider_id' => 'rerank',
        'intent_model_provider_id' => 'chat',
        'sql_model_provider_id' => 'chat',
        'guard_model_provider_id' => 'chat',
        'vision_model_provider_id' => 'chat',
        'expand_model_provider_id' => 'chat',
        'verify_model_provider_id' => 'chat',
        'context_model_provider_id' => 'chat',
    ];

    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->text('purposes')->nullable();
        });

        $purposes = [];
        foreach (DB::table('app_settings')->whereIn('key', array_keys(self::LINKS))->get() as $setting) {
            if ($setting->value) {
                $purposes[$setting->value][self::LINKS[$setting->key]] = true;
            }
        }
        foreach (DB::table('bot_profiles')->whereNotNull('provider_id')->distinct()->pluck('provider_id') as $id) {
            $purposes[$id]['chat'] = true;
        }

        foreach (DB::table('ai_providers')->get(['id', 'system_id']) as $provider) {
            // A workspace's provider only ever serves its bots.
            $tags = $provider->system_id ? ['chat'] : array_keys($purposes[$provider->id] ?? ['chat' => true]);
            DB::table('ai_providers')->where('id', $provider->id)->update(['purposes' => json_encode(array_values($tags))]);
        }
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->dropColumn('purposes');
        });
    }
};
