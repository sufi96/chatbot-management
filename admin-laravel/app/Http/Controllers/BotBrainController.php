<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PicksProviders;
use App\Models\AnswerCache;
use App\Models\BotProfile;
use App\Models\DbConnection;
use App\Models\KbCollection;
use App\Models\WebSearchKey;
use App\Services\EngineClient;
use App\Support\SourceOrder;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Schema;

class BotBrainController extends Controller
{
    use PicksProviders;

    public function edit(Request $request, string $id)
    {
        $bot = BotProfile::with(['collections', 'dbConnections'])->findOrFail($id);
        abort_unless($request->user()->canManageSystem($bot->system_id, 'editor'), 403);

        return view('bots.brain', [
            'bot' => $bot,
            'collections' => KbCollection::withCount('sources')
                ->where('system_id', $bot->system_id)
                ->orderBy('name')
                ->get(),
            'attached' => $bot->collections->pluck('id')->all(),
            'dbConnections' => DbConnection::where('system_id', $bot->system_id)
                ->orderBy('name')->get(),
            'attachedDbs' => $bot->dbConnections->pluck('id')->all(),
            'providers' => $this->providersFor($request->user(), $bot->system_id, $bot->provider_id),
            'providerSystemId' => $bot->system_id,
            // Rows the engine has kept, and how often they answered. Before
            // the migration there is no table, and the card says nothing.
            'cacheStats' => self::cacheStats($bot->id),
            // The searches this bot may use, and who may change the keys.
            'webSearchKeys' => WebSearchKey::where('system_id', $bot->system_id)->orderBy('name')->get()
                ->map(fn (WebSearchKey $key) => $key->forPicker())->values(),
            'canManageKeys' => $request->user()->canManageSystem($bot->system_id, 'system_admin'),
            'platformSearch' => self::platformSearch(),
            'platformLent' => AppSetting::get('web_search_lending') !== 'none',
            'speechEngine' => AppSetting::get('speech_engine'),
            'voiceEngines' => self::voiceEngines(),
            'listenEngine' => AppSetting::get('transcribe_engine'),
            // The real widget rides along on this page too, so a change to the
            // prompt or the sources can be tried without going anywhere.
            'apiHost' => env('API_HOST_URL', 'http://localhost:8000'),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);
        abort_unless($request->user()->canManageSystem($bot->system_id, 'editor'), 403);

        $validated = $request->validate([
            // Sometimes, so a client written before these moved here from the
            // Profile tab still saves.
            'provider_id' => ['sometimes', ...$this->providerRule($request->user(), $bot->provider_id)],
            'model_name' => ['sometimes', 'required', 'string', 'max:255'],
            'system_prompt' => ['nullable', 'string'],
            'retrieval_mode' => ['required', 'in:hybrid,vector,keyword'],
            'retrieval_top_k' => ['required', 'integer', 'min:1', 'max:20'],
            'retrieval_candidates' => ['required', 'integer', 'min:5', 'max:100'],
            'retrieval_min_score' => ['required', 'numeric', 'min:0', 'max:1'],
            // Sometimes rather than required, so a form or client written
            // before the reranker existed still saves.
            'rerank_min_score' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'retrieval_min_similarity' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'guard_refusal' => ['nullable', 'string', 'max:500'],
            'guard_topics' => ['nullable', 'string', 'max:2000'],
            'retrieval_fallback' => ['required', 'in:say_unknown,answer_anyway'],
            'web_search_max_results' => ['required', 'integer', 'min:1', 'max:10'],
            'web_search_country' => ['nullable', 'string', 'size:2', 'alpha'],
            // Sometimes, so a client written before these moved here from the
            // Profile tab still saves.
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'max_tokens' => ['sometimes', 'integer', 'min:64', 'max:8192'],
            'top_p' => ['required', 'numeric', 'min:0', 'max:1'],
            'top_k_sampling' => ['nullable', 'integer', 'min:1', 'max:200'],
            'presence_penalty' => ['required', 'numeric', 'min:-2', 'max:2'],
            'frequency_penalty' => ['required', 'numeric', 'min:-2', 'max:2'],
            'thinking_level' => ['required', 'in:off,low,medium,high'],
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string'],
            'db_max_rows' => ['required', 'integer', 'min:1', 'max:1000'],
            'db_query_timeout' => ['required', 'integer', 'min:1', 'max:120'],
            'source_order' => ['nullable', 'string', 'max:64'],
            'db_connections' => ['nullable', 'array'],
            'db_connections.*' => ['string'],
            // Sometimes, so a client written before these existed still saves
            // and the bot keeps answering as it did.
            'retrieval_keyword_weight' => ['sometimes', 'numeric', 'min:0', 'max:3'],
            'query_expansion' => ['sometimes', 'in:off,multi_query,hyde,both'],
            'context_neighbours' => ['sometimes', 'integer', 'min:0', 'max:2'],
            'cache_min_similarity' => ['sometimes', 'numeric', 'min:0.8', 'max:1'],
            'cache_ttl_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'web_search_mode' => ['sometimes', 'in:platform,duckduckgo,own'],
            'voice_gender' => ['sometimes', 'in:female,male'],
            'voice_language' => ['sometimes', 'in:auto,en,ms'],
            // Only an engine the install has set up: a bot on an unset speech
            // server would offer a speaker that never makes a sound.
            'voice_engine' => ['sometimes', Rule::in(array_keys(self::voiceEngines()))],
            // Only one of this workspace's keys. A crafted id from another
            // workspace would otherwise spend that workspace's account.
            'web_search_key_id' => ['nullable', 'required_if:web_search_mode,own', 'string',
                Rule::exists('web_search_keys', 'id')->where('system_id', $bot->system_id)],
        ], [
            'web_search_key_id.required_if' => 'Choose one of this workspace\'s keys, or add one.',
            'web_search_key_id.exists' => 'Choose one of this workspace\'s keys.',
            'voice_engine.in' => 'That voice engine is not set up. A super admin sets it up under Voice in admin settings.',
        ]);

        // A key is kept only while it is the one in use.
        if (array_key_exists('web_search_mode', $validated) && $validated['web_search_mode'] !== 'own') {
            $validated['web_search_key_id'] = null;
        }

        $validated['retrieval_enabled'] = $request->boolean('retrieval_enabled');
        $validated['web_search_enabled'] = $request->boolean('web_search_enabled');
        $validated['db_query_enabled'] = $request->boolean('db_query_enabled');
        $validated['intent_enabled'] = $request->boolean('intent_enabled');
        $validated['combine_sources'] = $request->boolean('combine_sources');
        $validated['guard_enabled'] = $request->boolean('guard_enabled');
        // An unticked switch sends nothing, so only the form that has these
        // switches may turn them off. An older client leaves them as they are.
        if ($request->has('upgrades_present')) {
            $validated['cache_enabled'] = $request->boolean('cache_enabled');
            $validated['grounding_check'] = $request->boolean('grounding_check');
            $validated['voice_output'] = $request->boolean('voice_output');
            $validated['voice_autoplay'] = $request->boolean('voice_autoplay');
            $validated['voice_input'] = $request->boolean('voice_input');
        }

        // Normalised rather than refused. A hand made submission must not be
        // able to leave a bot with a source it can never reach.
        $validated['source_order'] = SourceOrder::normalise($request->input('source_order'));

        // So that my and MY are the same setting rather than two.
        if (!empty($validated['web_search_country'])) {
            $validated['web_search_country'] = strtoupper($validated['web_search_country']);
        }
        unset($validated['collections'], $validated['db_connections']);
        $bot->update($validated);

        // Only collections from this bot's own workspace may be attached, whatever
        // the form posted.
        $allowed = KbCollection::where('system_id', $bot->system_id)
            ->whereIn('id', $request->input('collections', []))
            ->pluck('id')
            ->all();
        $bot->collections()->sync($allowed);

        // Only connections from this bot's own workspace may be attached,
        // whatever the form posted. The same rule the collections picker has.
        $allowedDbs = DbConnection::where('system_id', $bot->system_id)
            ->whereIn('id', $request->input('db_connections', []))
            ->pluck('id')
            ->all();
        $bot->dbConnections()->sync($allowedDbs);

        // A new prompt, model, collection or floor can make every cached
        // answer wrong, so a save starts the cache afresh.
        self::forgetCache($bot->id);

        return redirect()->route('bots.brain', $bot->id)->with('success', 'Behaviour settings saved.');
    }

    public function clearCache(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);
        abort_unless($request->user()->canManageSystem($bot->system_id, 'editor'), 403);

        $dropped = self::forgetCache($bot->id);

        return redirect()->route('bots.brain', $bot->id)
            ->with('success', $dropped === 1 ? 'Cleared 1 cached answer.' : "Cleared {$dropped} cached answers.");
    }

    /** The platform's search as a bot's page names it, without its key. */
    private static function platformSearch(): string
    {
        return match (AppSetting::get('web_search_provider')) {
            'tavily' => 'Tavily',
            'brave' => 'Brave',
            default => 'DuckDuckGo',
        };
    }

    /**
     * The Voice card's preview, when speech is made by a server: the editor's
     * own words in one of the four voices. The audio comes back through here,
     * so the engine's token and the speech key stay on the server. With the
     * browser engine the page speaks for itself and never calls this.
     */
    public function voicePreview(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);
        abort_unless($request->user()->canManageSystem($bot->system_id, 'editor'), 403);

        $validated = $request->validate([
            'voice' => ['required', 'in:en_female,en_male,ms_female,ms_male'],
            'text' => ['required', 'string', 'max:300'],
            // The engine as the card shows it, saved or not.
            'engine' => ['nullable', Rule::in(array_keys(self::voiceEngines()))],
        ], [
            'text.required' => 'Type something to hear.',
            'text.max' => 'A preview is at most 300 characters.',
        ]);

        $engine = ($validated['engine'] ?? 'default') === 'default'
            ? (string) AppSetting::get('speech_engine') : $validated['engine'];
        if ($engine === 'browser' || $engine === '') {
            return response('This engine speaks in the browser; the page plays it itself.', 422)
                ->header('Content-Type', 'text/plain');
        }

        $result = EngineClient::testVoice($validated['voice'], $validated['text'], '', ['speech_engine' => $engine]);

        if (!$result['ok']) {
            return response($result['message'], 502)->header('Content-Type', 'text/plain');
        }

        return response($result['audio'], 200)->header('Content-Type', $result['type']);
    }

    /**
     * The engines a bot may speak with: the install's default, the visitor's
     * browser, and each service the install has set up under Admin Settings,
     * Voice. The default's label says what it currently is.
     *
     * @return array<string, string>
     */
    public static function voiceEngines(): array
    {
        $names = ['browser' => "Visitor's browser", 'server' => 'Speech server', 'azure' => 'Azure Speech'];
        $installEngine = (string) AppSetting::get('speech_engine') ?: 'browser';

        $engines = [
            'default' => 'Default settings by Admin (' . ($names[$installEngine] ?? $names['browser']) . ')',
            'browser' => $names['browser'],
        ];
        if (AppSetting::get('speech_provider_id')) {
            $engines['server'] = $names['server'];
        }
        if (AppSetting::get('azure_speech_region') && AppSetting::get('azure_speech_key')) {
            $engines['azure'] = $names['azure'];
        }

        return $engines;
    }

    private static function forgetCache(string $botId): int
    {
        if (!Schema::hasTable('answer_cache')) {
            return 0;
        }

        return AnswerCache::forget($botId);
    }

    /** @return array{answers: int, hits: int}|null */
    private static function cacheStats(string $botId): ?array
    {
        if (!Schema::hasTable('answer_cache')) {
            return null;
        }

        $rows = AnswerCache::where('bot_id', $botId)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        return ['answers' => (clone $rows)->count(), 'hits' => (int) (clone $rows)->sum('hits')];
    }
}
