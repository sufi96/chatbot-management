<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * An endpoint, saved once and pointed at by many bots.
     *
     * The two hardcoded choices assumed the laptop never moved and there was
     * only ever one key. A row per endpoint lets an operator keep the laptop,
     * the office machine and a hosted key side by side, and — because a bot
     * links to one rather than copying it — moving house is one edit, not one
     * per bot.
     *
     * Scoped to a system exactly as db_connections and kb_collections are:
     * a key belongs to the workspace that pays for it.
     */
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('system_id', 36);
            $table->string('name', 255);
            $table->string('base_url', 500);
            // Plaintext, as bot_profiles.api_key was. The engine reads this
            // table directly, so an encrypted cast would put the key beyond
            // the process that needs it.
            $table->string('api_key', 500)->default('');
            $table->timestamps();

            $table->foreign('system_id')->references('id')->on('systems')->cascadeOnDelete();
        });

        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('provider_id', 36)->nullable()->after('system_prompt');

            $table->foreign('provider_id')->references('id')->on('ai_providers')->nullOnDelete();
        });

        $this->carryExistingEndpointsOver();

        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropColumn(['provider_type', 'base_url', 'api_key']);
        });
    }

    /**
     * Every endpoint already configured becomes a provider, and the bots that
     * were using it point at it. Bots sharing a URL and key share one row, so
     * the list starts as short as the operator's real setup.
     */
    private function carryExistingEndpointsOver(): void
    {
        $bots = DB::table('bot_profiles')
            ->select('id', 'system_id', 'base_url', 'api_key')
            ->get();

        $created = [];

        foreach ($bots as $bot) {
            $url = trim((string) $bot->base_url);
            if ($url === '') {
                continue;
            }

            $key = (string) ($bot->api_key ?? '');
            $fingerprint = $bot->system_id . '|' . $url . '|' . $key;

            if (! isset($created[$fingerprint])) {
                $id = 'aip_' . Str::random(12);

                DB::table('ai_providers')->insert([
                    'id' => $id,
                    'system_id' => $bot->system_id,
                    'name' => $this->nameFor($url),
                    'base_url' => $url,
                    'api_key' => $key,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created[$fingerprint] = $id;
            }

            DB::table('bot_profiles')
                ->where('id', $bot->id)
                ->update(['provider_id' => $created[$fingerprint]]);
        }
    }

    /**
     * A name an operator will recognise on sight. The host is what actually
     * distinguishes one endpoint from another, so lead with it.
     */
    private function nameFor(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return 'Local Ollama';
        }

        return Str::limit($host, 60, '');
    }

    public function down(): void
    {
        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->string('provider_type', 50)->default('ollama');
            $table->string('base_url', 500)->default('http://localhost:11434/v1');
            $table->string('api_key', 500)->default('');
        });

        // Put each bot's endpoint back where it was, so a rollback loses the
        // shared list but never the settings a bot was running on.
        foreach (DB::table('ai_providers')->get() as $provider) {
            DB::table('bot_profiles')
                ->where('provider_id', $provider->id)
                ->update([
                    'base_url' => $provider->base_url,
                    'api_key' => $provider->api_key,
                    'provider_type' => Str::contains($provider->base_url, ['localhost', '127.0.0.1'])
                        ? 'ollama' : 'custom',
                ]);
        }

        Schema::table('bot_profiles', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropColumn('provider_id');
        });

        Schema::dropIfExists('ai_providers');
    }
};
