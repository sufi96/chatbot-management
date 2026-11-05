<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PicksProviders;
use App\Models\AnswerCache;
use App\Models\BotProfile;
use App\Models\Connector;
use App\Models\ConnectorTool;
use App\Models\DbConnection;
use App\Models\KbCollection;
use App\Models\Skill;
use App\Models\WebSearchKey;
use App\Services\EngineClient;
use App\Support\SourceOrder;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;

class BotBrainController extends Controller
{
    use PicksProviders;

    /** The address Behaviour had before the tabs: its own tab now. */
    public function behaviour(string $id)
    {
        return redirect()->route('bots.settings', [$id, 'behaviour']);
    }

    public function edit(Request $request, string $id, string $section)
    {
        $bot = BotProfile::with(['collections', 'dbConnections'])->findOrFail($id);
        abort_unless($request->user()->canManageSystem($bot->system_id, 'editor'), 403);

        return view('bots.brain', [
            'bot' => $bot,
            // Which tab's cards this page shows; see SECTION_FIELDS.
            'section' => $section,
            'tabName' => self::TAB_NAMES[$section],
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
            // The switched-on operations this bot could be given, by connector.
            'connectors' => Connector::where('system_id', $bot->system_id)
                ->with(['tools' => fn ($q) => $q->where('enabled', true)->orderBy('name')])
                ->orderBy('name')->get()
                ->filter(fn (Connector $c) => $c->tools->isNotEmpty())->values(),
            'attachedTools' => $bot->tools()->pluck('connector_tools.id')->all(),
            'skills' => Skill::where('system_id', $bot->system_id)->orderBy('name')->get(),
            'attachedSkills' => $bot->skills()->pluck('skills.id')->all(),
            // What the bot has now, to say which tools a skill still needs.
            'toolNames' => $bot->tools()->where('enabled', true)->pluck('name')->all(),
            'platformSearch' => self::platformSearch(),
            'platformLent' => AppSetting::get('web_search_lending') !== 'none',
            'speechEngine' => AppSetting::get('speech_engine'),
            'voiceEngines' => self::voiceEngines(),
            'listenEngine' => AppSetting::get('transcribe_engine'),
            // The real widget rides along on this page too, so a change to the
            // prompt or the sources can be tried without going anywhere.
            'apiHost' => env('API_HOST_URL', 'http://localhost:8000'),
            // Lets this page's widget show each tool call and loaded skill.
            'traceToken' => EngineClient::traceToken($bot->id),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);
        abort_unless($request->user()->canManageSystem($bot->system_id, 'editor'), 403);

        $section = $this->section($request);

        $rules = [
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
            'agentic_max_calls' => ['sometimes', 'integer', 'min:1', 'max:5'],
            'tools' => ['nullable', 'array'],
            'tools.*' => ['string'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['string'],
            'builtin_tools' => ['nullable', 'array'],
            'builtin_tools.*' => [Rule::in(array_keys(BotProfile::BUILTIN_TOOLS))],
            // Files & visuals: ranges match the engine's clamps (files/settings.py).
            'files_in_types' => ['nullable', 'array'],
            'files_in_types.*' => [Rule::in(BotProfile::FILE_IN_TYPES)],
            'files_max_mb' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'files_per_message' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'files_daily' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'files_out_formats' => ['nullable', 'array'],
            'files_out_formats.*' => [Rule::in(BotProfile::FILE_OUT_FORMATS)],
            'files_out_mode' => ['sometimes', 'in:on_request,own_initiative'],
            'files_retention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ];
        $messages = [
            'web_search_key_id.required_if' => 'Choose one of this workspace\'s keys, or add one.',
            'web_search_key_id.exists' => 'Choose one of this workspace\'s keys.',
            'voice_engine.in' => 'That voice engine is not set up. A super admin sets it up under Voice in admin settings.',
        ];
        // A tab's save checks only that tab's fields; the others are not on its form.
        $validated = $request->validate(self::rulesFor($rules, $section), $messages);

        // A key is kept only while it is the one in use.
        if (array_key_exists('web_search_mode', $validated) && $validated['web_search_mode'] !== 'own') {
            $validated['web_search_key_id'] = null;
        }

        $in = fn (string $tab) => $section === null || $section === $tab;

        // An unticked switch sends nothing, so a switch is read only from the
        // tab that shows it. Another tab's switches stay as they are.
        if ($in('brain')) {
            foreach (['retrieval_enabled', 'web_search_enabled', 'db_query_enabled', 'intent_enabled', 'combine_sources'] as $switch) {
                $validated[$switch] = $request->boolean($switch);
            }
        }
        if ($in('safety')) {
            $validated['guard_enabled'] = $request->boolean('guard_enabled');
        }
        // Without a tab: only the form that has these switches may turn them
        // off, and an older client leaves them as they are.
        if ($section === 'brain' || ($section === null && $request->has('upgrades_present'))) {
            $validated['cache_enabled'] = $request->boolean('cache_enabled');
        }
        if ($section === 'safety' || ($section === null && $request->has('upgrades_present'))) {
            $validated['grounding_check'] = $request->boolean('grounding_check');
        }
        if ($section === 'behaviour' || ($section === null && $request->has('upgrades_present'))) {
            $validated['voice_output'] = $request->boolean('voice_output');
            $validated['voice_autoplay'] = $request->boolean('voice_autoplay');
            $validated['voice_input'] = $request->boolean('voice_input');
        }

        // Only the form with the Connectors tab may change it; an older client
        // leaves the switch and the tools as they were.
        $tools = null;
        $skills = null;
        if ($section === 'connectors' || ($section === null && $request->has('agentic_present'))) {
            $validated['agentic_enabled'] = $request->boolean('agentic_enabled');
            $tools = $this->agenticTools($bot, (array) $request->input('tools', []));
            $skills = $this->agenticSkills($bot, (array) $request->input('skills', []));
            $validated['builtin_tools'] = array_values(array_unique($validated['builtin_tools'] ?? []));
        }

        // Only the form with the Files & vision card may change them; an older
        // client leaves them as they were.
        if ($section === 'files' || ($section === null && $request->has('files_present'))) {
            $current = $bot->fileSettings();
            $validated['file_settings'] = [
                'in_enabled' => $request->boolean('files_in_enabled'),
                'in_types' => array_values(array_intersect(BotProfile::FILE_IN_TYPES, (array) $request->input('files_in_types', []))),
                'max_mb' => (int) ($validated['files_max_mb'] ?? $current['max_mb']),
                'per_message' => (int) ($validated['files_per_message'] ?? $current['per_message']),
                'daily' => (int) ($validated['files_daily'] ?? $current['daily']),
                'out_enabled' => $request->boolean('files_out_enabled'),
                'out_formats' => array_values(array_intersect(BotProfile::FILE_OUT_FORMATS, (array) $request->input('files_out_formats', []))),
                'out_mode' => $validated['files_out_mode'] ?? $current['out_mode'],
                'visuals' => $request->boolean('visuals_enabled'),
                'retention_days' => (int) ($validated['files_retention_days'] ?? $current['retention_days']),
            ];
        }
        unset($validated['files_in_types'], $validated['files_max_mb'], $validated['files_per_message'],
            $validated['files_daily'], $validated['files_out_formats'], $validated['files_out_mode'],
            $validated['files_retention_days']);

        if ($in('brain')) {
            // Normalised rather than refused. A hand made submission must not be
            // able to leave a bot with a source it can never reach.
            $validated['source_order'] = SourceOrder::normalise($request->input('source_order'));
        }

        // So that my and MY are the same setting rather than two.
        if (!empty($validated['web_search_country'])) {
            $validated['web_search_country'] = strtoupper($validated['web_search_country']);
        }
        unset($validated['collections'], $validated['db_connections'], $validated['tools'], $validated['skills']);
        $bot->update($validated);

        if ($tools !== null) {
            $bot->tools()->sync($tools);
            $bot->skills()->sync($skills);
        }

        if ($in('brain')) {
            // Only collections from this bot's own workspace may be attached,
            // whatever the form posted.
            $bot->collections()->sync(KbCollection::where('system_id', $bot->system_id)
                ->whereIn('id', $request->input('collections', []))->pluck('id')->all());

            // Only connections from this bot's own workspace may be attached,
            // whatever the form posted. The same rule the collections picker has.
            $bot->dbConnections()->sync(DbConnection::where('system_id', $bot->system_id)
                ->whereIn('id', $request->input('db_connections', []))->pluck('id')->all());
        }

        // A new prompt, model, collection or floor can make every cached
        // answer wrong, so a save starts the cache afresh. Safety changes no
        // stored answer: the guard runs before the cache is looked in.
        if ($section !== 'safety') {
            self::forgetCache($bot->id);
        }

        if ($section !== null) {
            return redirect()->route('bots.settings', [$bot->id, $section])
                ->with('success', self::TAB_NAMES[$section] . ' saved.');
        }

        return redirect()->route('bots.brain', $bot->id)->with('success', 'Behaviour settings saved.');
    }

    /** The bot's settings tabs after Appearance, by the address each has. */
    public const TAB_NAMES = [
        'behaviour' => 'Behaviour',
        'brain' => 'Brain',
        'connectors' => 'Connectors',
        'files' => 'Files & vision',
        'safety' => 'Safety',
    ];

    /**
     * Which fields each tab's form holds. A field in a list is validated only
     * when that tab saves; one ending in .* goes with its array.
     */
    public const SECTION_FIELDS = [
        'behaviour' => ['provider_id', 'model_name', 'system_prompt', 'temperature', 'max_tokens', 'top_p',
            'top_k_sampling', 'presence_penalty', 'frequency_penalty', 'thinking_level',
            'voice_gender', 'voice_language', 'voice_engine'],
        'brain' => ['retrieval_mode', 'retrieval_top_k', 'retrieval_candidates', 'retrieval_min_score',
            'rerank_min_score', 'retrieval_min_similarity', 'retrieval_fallback', 'web_search_max_results',
            'web_search_country', 'collections', 'db_max_rows', 'db_query_timeout', 'source_order',
            'db_connections', 'retrieval_keyword_weight', 'query_expansion', 'context_neighbours',
            'cache_min_similarity', 'cache_ttl_hours', 'web_search_mode', 'web_search_key_id'],
        'connectors' => ['agentic_max_calls', 'tools', 'skills', 'builtin_tools'],
        'files' => ['files_in_types', 'files_max_mb', 'files_per_message', 'files_daily', 'files_out_formats',
            'files_out_mode', 'files_retention_days'],
        'safety' => ['guard_refusal', 'guard_topics'],
    ];

    /** The tab a save came from, or null for a form that predates the tabs. */
    private function section(Request $request): ?string
    {
        $section = $request->input('section');
        if ($section === null || $section === '') {
            return null;
        }
        abort_unless(array_key_exists($section, self::TAB_NAMES), 404);

        return $section;
    }

    /** The rules for one tab's fields, or all of them without a tab. */
    private static function rulesFor(array $rules, ?string $section): array
    {
        if ($section === null) {
            return $rules;
        }
        $fields = self::SECTION_FIELDS[$section];

        return array_filter($rules, fn ($key) => in_array(explode('.', $key)[0], $fields, true), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The tool ids a bot may be given: switched on, on a connector in its own
     * workspace, and no two with one name, since the model calls a tool by
     * its name. Refused rather than filtered, so the editor learns why.
     */
    private function agenticTools(BotProfile $bot, array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (!$ids) {
            return [];
        }

        $tools = ConnectorTool::whereIn('id', $ids)
            ->where('enabled', true)
            ->whereHas('connector', fn ($q) => $q->where('system_id', $bot->system_id))
            ->get();

        if ($tools->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'tools' => 'Choose only switched-on operations from this workspace\'s connectors.']);
        }

        $reserved = $tools->pluck('name')->first(fn ($name) => in_array($name, BotProfile::RESERVED_TOOL_NAMES, true));
        if ($reserved !== null) {
            throw ValidationException::withMessages([
                'tools' => "{$reserved} is a name C⁴ uses itself. Rename the operation or leave it off."]);
        }

        $clash = $tools->countBy('name')->filter(fn ($n) => $n > 1)->keys()->first();
        if ($clash !== null) {
            throw ValidationException::withMessages([
                'tools' => "Two tools are both called {$clash}. Enable only one."]);
        }

        return $tools->pluck('id')->all();
    }

    /**
     * Skill ids from the bot's own workspace. A built-in is copied into the
     * workspace first, so its text is the workspace's to read and change.
     * One whose tools the bot lacks may still be linked; the card says what
     * it needs, and the engine offers it only once the bot has them.
     */
    private function agenticSkills(BotProfile $bot, array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (!$ids) {
            return [];
        }

        $found = Skill::whereIn('id', $ids)->where('system_id', $bot->system_id)->pluck('id')->all();
        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages(['skills' => 'Choose only skills from this workspace.']);
        }

        return $found;
    }

    public function clearCache(Request $request, string $id)
    {
        $bot = BotProfile::findOrFail($id);
        abort_unless($request->user()->canManageSystem($bot->system_id, 'editor'), 403);

        $dropped = self::forgetCache($bot->id);

        return redirect()->route('bots.settings', [$bot->id, 'brain'])
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
