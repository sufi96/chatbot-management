<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Each job a model does, by the prefix its settings share. Frozen here
     * rather than read from the controller, so the migration means the same
     * thing after the controller changes.
     */
    private const PREFIXES = [
        'embedding_',
        'intent_model_', 'sql_model_', 'rerank_model_', 'guard_model_', 'vision_model_',
    ];

    /**
     * Admin Settings links platform providers instead of holding endpoints.
     *
     * The same machine was typed into up to six jobs, so moving it meant six
     * edits and a typo in any one of them. A platform provider is an
     * ai_providers row with no workspace: the bot form lists providers by
     * workspace, so it never offers one of these to a bot.
     */
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->string('system_id', 36)->nullable()->change();
        });

        $created = [];

        foreach (self::PREFIXES as $prefix) {
            $url = trim((string) $this->stored($prefix . 'base_url'));
            $key = (string) $this->stored($prefix . 'api_key');

            if ($url !== '') {
                $fingerprint = $url . '|' . $key;

                $created[$fingerprint] ??= $this->createProvider($url, $key);

                $this->put($prefix . 'provider_id', $created[$fingerprint]);
            }

            $this->forget($prefix . 'base_url');
            $this->forget($prefix . 'api_key');
        }
    }

    public function down(): void
    {
        foreach (self::PREFIXES as $prefix) {
            $provider = DB::table('ai_providers')
                ->where('id', (string) $this->stored($prefix . 'provider_id'))
                ->first();

            if ($provider) {
                $this->put($prefix . 'base_url', $provider->base_url);
                $this->put($prefix . 'api_key', $provider->api_key);
            }

            $this->forget($prefix . 'provider_id');
        }

        DB::table('ai_providers')->whereNull('system_id')->delete();

        Schema::table('ai_providers', function (Blueprint $table) {
            $table->string('system_id', 36)->nullable(false)->change();
        });
    }

    private function createProvider(string $url, string $key): string
    {
        $id = 'aip_' . Str::random(12);

        DB::table('ai_providers')->insert([
            'id' => $id,
            'system_id' => null,
            'name' => $this->nameFor($url),
            'base_url' => $url,
            'api_key' => $key,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * The machine is what tells two endpoints apart, so the name leads with
     * it. The port stays unless it is Ollama's own, because two servers on one
     * box differ only by port.
     */
    private function nameFor(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $port = parse_url($url, PHP_URL_PORT);

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) && in_array($port, [null, 11434], true)) {
            return 'Local Ollama';
        }

        return Str::limit($port ? "{$host}:{$port}" : $host, 60, '');
    }

    private function stored(string $key): ?string
    {
        return DB::table('app_settings')->where('key', $key)->value('value');
    }

    private function put(string $key, string $value): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
        );
        Cache::forget("app_setting:{$key}");
    }

    private function forget(string $key): void
    {
        DB::table('app_settings')->where('key', $key)->delete();
        Cache::forget("app_setting:{$key}");
    }
};
