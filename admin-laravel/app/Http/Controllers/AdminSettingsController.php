<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Services\EngineClient;
use App\Support\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class AdminSettingsController extends Controller
{
    /**
     * The categories, in the order the sidebar lists them under Admin
     * Settings. Each is a page of its own; the form behind them is one.
     */
    public const SECTIONS = [
        'providers' => [
            'label' => 'Providers', 'icon' => 'bi-hdd-network',
            'description' => 'The endpoints model jobs run on. Save a machine or a hosted key once, then pick it for any job. Bots keep their own providers, per workspace.',
        ],
        'models' => [
            'label' => 'Models', 'icon' => 'bi-boxes',
            'description' => 'Which model does each job. Pick a provider, then search it for the model: a list that comes back also proves the provider answers.',
        ],
        'guard' => [
            'label' => 'Guard', 'icon' => 'bi-shield-check',
            'description' => 'What the guard blocks, for every bot that has it switched on under Behaviour. A bot can add topics of its own there.',
        ],
        'security' => [
            'label' => 'Security', 'icon' => 'bi-shield-lock',
            'description' => 'Defences against prompt injection and prompt leaks, for every bot. They need no model and run whether or not a bot has the guard on.',
        ],
        'chunking' => [
            'label' => 'Chunking and search', 'icon' => 'bi-scissors',
            'description' => 'How sources are cut into passages, how keywords are ranked, and how much of them reaches the model.',
        ],
        'voice' => [
            'label' => 'Voice', 'icon' => 'bi-soundwave',
            'description' => 'How bots read answers aloud and hear spoken questions, for every bot that has voice switched on under Behaviour.',
        ],
        'web-search' => [
            'label' => 'Web search', 'icon' => 'bi-globe2',
            'description' => "Where a bot looks when its answer source order reaches the web.",
        ],
        'branding' => [
            'label' => 'Branding', 'icon' => 'bi-palette',
            'description' => 'Your own mark, in place of the one the console ships with.',
        ],
        'maintenance' => [
            'label' => 'Maintenance', 'icon' => 'bi-tools',
            'description' => 'How the system fits together, and work to run after changing how content is embedded.',
        ],
    ];

    /**
     * The sidebar's two entries. Each opens a page whose categories are tabs,
     * so the sidebar stays short however many categories there are. The
     * first category of a group is where its sidebar link goes.
     */
    public const GROUPS = [
        'ai' => [
            'label' => 'AI and answering', 'icon' => 'bi-sliders2',
            'sections' => ['providers', 'models', 'guard', 'security', 'chunking', 'voice', 'web-search'],
        ],
        'console' => [
            'label' => 'Console', 'icon' => 'bi-gear',
            'sections' => ['branding', 'maintenance'],
        ],
    ];

    /** The group a category belongs to. */
    public static function groupOf(string $section): string
    {
        foreach (self::GROUPS as $key => $group) {
            if (in_array($section, $group['sections'], true)) {
                return $key;
            }
        }

        return array_key_first(self::GROUPS);
    }

    /** Where Ollama listens on this machine: what a blank embedding link means. */
    public const DEFAULT_EMBEDDING_URL = 'http://localhost:11434/v1';

    private const KEYS = [
        'embedding_provider_id', 'embedding_model',
        'embedding_dimensions', 'chunk_size', 'chunk_overlap',
        'context_char_budget',
        'web_search_provider', 'web_search_tavily_key', 'web_search_brave_key', 'web_search_lending',
        'guard_topics', 'guard_borderline',
        'keyword_engine', 'contextual_chunks', 'web_search_lending',
        'history_source', 'injection_shield', 'injection_shield_sources', 'leak_guard',
        'speech_engine', 'speech_provider_id', 'speech_model', 'azure_speech_region', 'azure_speech_key',
        'voice_en_female', 'voice_en_male', 'voice_ms_female', 'voice_ms_male',
        'transcribe_engine', 'transcribe_provider_id', 'transcribe_model',
    ];

    /**
     * Voices offered for each of the four, by the name Microsoft gives them:
     * the same names in Azure Speech and in the openai-edge-tts container.
     * Malay has one female and one male voice; Indonesian, which a Malay
     * listener follows, is offered beside them. Another speech server names
     * its voices its own way, so any name can still be typed.
     */
    public const VOICE_CATALOGUE = [
        'en_female' => [
            'en-US-AvaNeural' => 'Ava · American', 'en-US-AvaMultilingualNeural' => 'Ava, multilingual · American',
            'en-US-EmmaNeural' => 'Emma · American', 'en-US-JennyNeural' => 'Jenny · American',
            'en-US-AriaNeural' => 'Aria · American', 'en-US-MichelleNeural' => 'Michelle · American',
            'en-GB-SoniaNeural' => 'Sonia · British', 'en-GB-LibbyNeural' => 'Libby · British',
            'en-SG-LunaNeural' => 'Luna · Singaporean', 'en-AU-NatashaNeural' => 'Natasha · Australian',
            'en-IN-NeerjaNeural' => 'Neerja · Indian',
        ],
        'en_male' => [
            'en-US-AndrewNeural' => 'Andrew · American', 'en-US-AndrewMultilingualNeural' => 'Andrew, multilingual · American',
            'en-US-BrianNeural' => 'Brian · American', 'en-US-GuyNeural' => 'Guy · American',
            'en-US-ChristopherNeural' => 'Christopher · American', 'en-US-EricNeural' => 'Eric · American',
            'en-GB-RyanNeural' => 'Ryan · British', 'en-GB-ThomasNeural' => 'Thomas · British',
            'en-SG-WayneNeural' => 'Wayne · Singaporean', 'en-IN-PrabhatNeural' => 'Prabhat · Indian',
        ],
        'ms_female' => [
            'ms-MY-YasminNeural' => 'Yasmin · Malaysian', 'id-ID-GadisNeural' => 'Gadis · Indonesian',
        ],
        'ms_male' => [
            'ms-MY-OsmanNeural' => 'Osman · Malaysian', 'id-ID-ArdiNeural' => 'Ardi · Indonesian',
        ],
    ];

    /** Settings that are one of a fixed set of options, never blank. */
    private const CHOICE_KEYS = [
        'keyword_engine', 'contextual_chunks', 'web_search_lending',
        'history_source', 'injection_shield', 'injection_shield_sources', 'leak_guard',
        'speech_engine', 'transcribe_engine', 'speech_model', 'transcribe_model',
        'voice_en_female', 'voice_en_male', 'voice_ms_female', 'voice_ms_male',
    ];

    /**
     * The harms the guard can block. api-engine/guard.py holds the same keys,
     * tells a stand-in model about the ones switched on, and maps a dedicated
     * guard model's own category names onto them. Keep the two in step.
     */
    public const GUARD_CATEGORIES = [
        'violence' => ['label' => 'Violence and weapons', 'hint' => 'Threats, attacks, making weapons'],
        'illegal' => ['label' => 'Illegal activity', 'hint' => 'Drugs, fraud, hacking, theft'],
        'sexual' => ['label' => 'Sexual content', 'hint' => 'Explicit material of any kind'],
        'self_harm' => ['label' => 'Self-harm', 'hint' => 'Suicide and self-injury'],
        'hate' => ['label' => 'Hate and harassment', 'hint' => 'Discrimination, abuse, unethical acts'],
        'personal_data' => ['label' => 'Personal data', 'hint' => "Exposing someone's private details"],
        'jailbreak' => ['label' => 'Instruction override', 'hint' => 'Attempts to make the bot ignore its rules'],
        'political' => ['label' => 'Political topics', 'hint' => 'Elections, parties, contested issues'],
        'copyright' => ['label' => 'Copyright', 'hint' => 'Reproducing protected works'],
    ];

    /**
     * Every job a model does besides answering, in the order the screen lists
     * them. api-engine/roles.py holds the same names and decides what blank
     * falls back to; keep the two in step.
     */
    public const MODEL_ROLES = [
        'intent' => [
            'icon' => 'bi-signpost-split',
            'label' => 'Intent',
            'job' => 'Reads each message with the recent conversation, decides whether it needs facts, and rewrites a follow-up into a question that stands on its own.',
            'blank' => "Bot's main model",
            'placeholder' => 'qwen3.5:4b',
        ],
        'sql' => [
            'icon' => 'bi-database',
            'label' => 'SQL',
            'job' => 'Writes the query, and decides whether any table can answer a question at all. Small models are markedly weaker at SQL than at conversation.',
            'blank' => "Bot's main model",
            'placeholder' => 'qwen3-coder:30b',
        ],
        'rerank' => [
            'icon' => 'bi-sort-down',
            'label' => 'Reranker',
            'job' => 'Scores each retrieved passage against the question. Needs an endpoint that serves /v1/rerank, such as vLLM or llama.cpp. Ollama does not.',
            'blank' => 'Off: no reranking',
            'placeholder' => 'bge-reranker-v2-m3',
        ],
        'guard' => [
            'icon' => 'bi-shield-check',
            'label' => 'Guard',
            'job' => 'Checks what visitors send, and what bots answer, for harmful content.',
            'blank' => "Bot's main model",
            'placeholder' => 'qwen3guard-gen:0.6b',
        ],
        'vision' => [
            'icon' => 'bi-eye',
            'label' => 'Vision',
            'job' => 'Reads uploaded images, and scanned PDFs with no text layer, page by page up to 40 pages. Needs a model that accepts images; by default, the main model of a bot that reads the collection. Re-index a source after setting this.',
            'blank' => "Bot's main model",
            'placeholder' => 'qwen3-vl:8b',
        ],
        'expand' => [
            'icon' => 'bi-arrows-angle-expand',
            'label' => 'Query expansion',
            'job' => 'Rewrites a question a few other ways (multi-query) or writes the passage that would answer it (HyDE), so the knowledge base is searched with words a document would use. Only for bots that switch it on under Behaviour.',
            'blank' => "Bot's main model",
            'placeholder' => 'qwen3.5:4b',
        ],
        'verify' => [
            'icon' => 'bi-patch-check',
            'label' => 'Answer check',
            'job' => 'Reads a finished answer beside the material it was given and flags any claim the material does not support. Only for bots that switch the grounding check on.',
            'blank' => "Bot's main model",
            'placeholder' => 'qwen3.5:4b',
        ],
        'context' => [
            'icon' => 'bi-card-text',
            'label' => 'Chunk context',
            'job' => 'Writes one sentence placing each chunk in its document while indexing, when contextual chunks are on under Chunking and search. One call per chunk; re-index after changing it.',
            'blank' => "Main model of a bot reading the collection",
            'placeholder' => 'qwen3.5:4b',
        ],
    ];

    /** The stored keys, each role's provider link and model included. */
    private static function keys(): array
    {
        $keys = self::KEYS;

        foreach (array_keys(self::MODEL_ROLES) as $role) {
            array_push($keys, "{$role}_model_provider_id", "{$role}_model_name");
        }

        return $keys;
    }

    /**
     * Every setting that links a platform provider, with the job it names.
     * What a refused delete lists, so the operator knows what to move first.
     */
    public static function providerLinks(): array
    {
        $links = ['embedding_provider_id' => 'Embedding', 'speech_provider_id' => 'Speech',
            'transcribe_provider_id' => 'Transcription'];

        foreach (self::MODEL_ROLES as $role => $meta) {
            $links["{$role}_model_provider_id"] = $meta['label'];
        }

        return $links;
    }

    /** The jobs whose saved setting runs on this provider. */
    public static function usageOf(string $providerId): array
    {
        $jobs = [];

        foreach (self::providerLinks() as $key => $label) {
            if (AppSetting::get($key) === $providerId) {
                $jobs[] = $label;
            }
        }

        return $jobs;
    }

    /**
     * The categories holding a field that failed validation, in sidebar order.
     * Takes the view's error bag or a validator's; both answer keys().
     */
    public static function sectionsWithErrors($errors): array
    {
        $failed = [];

        foreach ($errors->keys() as $field) {
            $failed[self::sectionFor($field)] = true;
        }

        return array_values(array_filter(array_keys(self::SECTIONS), fn ($key) => isset($failed[$key])));
    }

    private static function sectionFor(string $field): string
    {
        return match (true) {
            str_starts_with($field, 'embedding_'), str_contains($field, '_model_') => 'models',
            str_starts_with($field, 'guard_') => 'guard',
            str_starts_with($field, 'speech_'), str_starts_with($field, 'azure_speech_'),
            str_starts_with($field, 'voice_'), str_starts_with($field, 'transcribe_') => 'voice',
            in_array($field, ['history_source', 'injection_shield', 'injection_shield_sources', 'leak_guard'], true) => 'security',
            str_starts_with($field, 'web_search_') => 'web-search',
            str_starts_with($field, 'brand_') => 'branding',
            default => 'chunking',
        };
    }

    /**
     * A provider Admin Settings may link: platform rows only. A workspace's
     * provider carries that workspace's key, and is not ours to spend.
     */
    private static function platformProviderRule(): Exists
    {
        return Rule::exists('ai_providers', 'id')->whereNull('system_id');
    }

    private static function modelRoleRules(): array
    {
        $rules = [];

        foreach (array_keys(self::MODEL_ROLES) as $role) {
            // Both halves, or neither: the engine calls nothing with only one.
            $rules["{$role}_model_provider_id"] = ['nullable', 'string',
                "required_with:{$role}_model_name", self::platformProviderRule()];
            $rules["{$role}_model_name"] = ['nullable', 'string', 'max:255',
                "required_with:{$role}_model_provider_id"];
        }

        return $rules;
    }

    private static function modelRoleMessages(): array
    {
        $messages = [];

        foreach (self::MODEL_ROLES as $role => $meta) {
            $messages["{$role}_model_provider_id.required_with"] = "{$meta['label']} needs a provider to run its model on.";
            $messages["{$role}_model_provider_id.exists"] = "Choose one of the providers listed for {$meta['label']}.";
            $messages["{$role}_model_name.required_with"] = "{$meta['label']} needs a model as well as a provider.";
        }

        return $messages;
    }

    public function edit(string $section = 'providers')
    {
        $settings = [];
        foreach (self::keys() as $key) {
            $settings[$key] = AppSetting::get($key);
        }

        return view('admin.settings', [
            'section' => $section,
            'sections' => self::SECTIONS,
            'group' => self::groupOf($section),
            'groups' => self::GROUPS,
            'settings' => $settings,
            'providers' => AiProvider::platform()->orderBy('name')->get()
                ->map(fn (AiProvider $provider) => self::providerJson($provider))
                ->values(),
            'modelRoles' => self::MODEL_ROLES,
            'defaultEmbeddingUrl' => self::DEFAULT_EMBEDDING_URL,
            'guardCategories' => self::GUARD_CATEGORIES,
            'guardChosen' => array_filter(explode(',', (string) AppSetting::get('guard_categories'))),
            'voiceCatalogue' => self::VOICE_CATALOGUE,
            // Where the browser fetches the widget's voice helpers, so the
            // preview picks a device voice as the widget will.
            'apiHost' => env('API_HOST_URL', 'http://localhost:8000'),
            // Resolved here rather than in the view, so the card and the
            // sidebar cannot disagree about which mark is in use.
            'logoUrl' => Brand::logoUrl(),
            'iconUrl' => Brand::iconUrl(),
            'logoIsCustom' => Brand::isCustom('brand_logo_path'),
            'iconIsCustom' => Brand::isCustom('brand_icon_path'),
        ]);
    }

    public function update(Request $request)
    {
        $section = array_key_exists((string) $request->input('section'), self::SECTIONS)
            ? $request->input('section') : 'providers';

        $validator = Validator::make($request->all(), array_merge([
            'embedding_provider_id' => ['nullable', 'string', self::platformProviderRule()],
            'embedding_model' => ['required', 'string', 'max:120'],
            'embedding_dimensions' => ['required', 'integer', 'min:64', 'max:4096'],
            'chunk_size' => ['required', 'integer', 'min:400', 'max:8000'],
            'chunk_overlap' => ['required', 'integer', 'min:0', 'lt:chunk_size'],
            'context_char_budget' => ['required', 'integer', 'min:1000', 'max:20000'],
            'web_search_provider' => ['required', 'in:duckduckgo,tavily,brave'],
            'web_search_tavily_key' => ['nullable', 'string', 'max:200'],
            'web_search_brave_key' => ['nullable', 'string', 'max:200'],
            'web_search_lending' => ['sometimes', 'in:all,none'],
            'speech_engine' => ['sometimes', 'in:browser,server,azure'],
            'speech_provider_id' => ['nullable', 'string', 'required_if:speech_engine,server', self::platformProviderRule()],
            'speech_model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'azure_speech_region' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9]+$/', 'required_if:speech_engine,azure'],
            'azure_speech_key' => ['nullable', 'string', 'max:200', 'required_if:speech_engine,azure'],
            'voice_en_female' => ['sometimes', 'nullable', 'string', 'max:120'],
            'voice_en_male' => ['sometimes', 'nullable', 'string', 'max:120'],
            'voice_ms_female' => ['sometimes', 'nullable', 'string', 'max:120'],
            'voice_ms_male' => ['sometimes', 'nullable', 'string', 'max:120'],
            'transcribe_engine' => ['sometimes', 'in:browser,server'],
            'transcribe_provider_id' => ['nullable', 'string', 'required_if:transcribe_engine,server', self::platformProviderRule()],
            'transcribe_model' => ['sometimes', 'nullable', 'string', 'max:120'],
            // Read only from the form that has the checkboxes; see below.
            'guard_categories' => ['exclude_unless:guard_categories_present,1', 'nullable', 'array'],
            'guard_categories.*' => ['exclude_unless:guard_categories_present,1', 'string',
                Rule::in(array_keys(self::GUARD_CATEGORIES))],
            'guard_topics' => ['nullable', 'string', 'max:2000'],
            'guard_borderline' => ['nullable', 'in:allow,block'],
            'keyword_engine' => ['sometimes', 'in:bm25,postgres'],
            'contextual_chunks' => ['sometimes', 'in:off,on'],
            'history_source' => ['sometimes', 'in:server,client'],
            'injection_shield' => ['sometimes', 'in:off,flag,block'],
            'injection_shield_sources' => ['sometimes', 'in:off,drop'],
            'leak_guard' => ['sometimes', 'in:off,on'],
            // No SVG. One served from our own origin runs its own script for
            // anyone who opens it directly, and super admin only is not a
            // good enough reason to leave that open.
            'brand_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'brand_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ], self::modelRoleRules()), array_merge([
            'embedding_provider_id.exists' => 'Choose one of the providers listed for Embedding.',
            'embedding_model.required' => 'Embedding needs a model.',
            'chunk_overlap.lt' => 'Overlap must be smaller than the chunk size.',
            'brand_logo.mimes' => 'The logo must be a PNG, JPG or WebP image.',
            'brand_icon.mimes' => 'The icon must be a PNG, JPG or WebP image.',
            'speech_provider_id.required_if' => 'A speech server needs a provider to run on.',
            'transcribe_provider_id.required_if' => 'A transcription server needs a provider to run on.',
            'azure_speech_region.required_if' => 'Azure Speech needs the region of your Speech resource, such as southeastasia.',
            'azure_speech_region.regex' => 'The region is one word, such as southeastasia.',
            'azure_speech_key.required_if' => 'Azure Speech needs a key from your Speech resource.',
        ], self::modelRoleMessages()));

        // Back to the first category with a problem, not the one Save was
        // pressed on: an error on a page nobody is looking at gets no fix.
        if ($validator->fails()) {
            $first = self::sectionsWithErrors($validator->errors())[0] ?? $section;

            return redirect()->route('admin.settings', $first)
                ->withErrors($validator)->withInput();
        }

        $validated = $validator->validated();

        foreach (self::keys() as $key) {
            // A choice the form did not send keeps what it was. Blank is not
            // one of its options, and a client written before it existed
            // must not switch a defence off by saving something else.
            $fallback = in_array($key, self::CHOICE_KEYS, true) ? AppSetting::get($key) : '';
            AppSetting::put($key, $validated[$key] ?? $fallback);
        }

        AppSetting::put('guard_borderline', $validated['guard_borderline'] ?? 'allow');

        // Unticked checkboxes send nothing, so an absent list means none only
        // when the form that has the checkboxes sent it. A client that never
        // knew about categories must not switch every one of them off.
        if ($request->boolean('guard_categories_present')) {
            AppSetting::put('guard_categories', implode(',', array_values(array_intersect(
                array_keys(self::GUARD_CATEGORIES), $validated['guard_categories'] ?? []))));
        }

        // Deliberately not in KEYS. That loop writes every key it knows on
        // every save, so a logo swept into it would vanish the next time
        // anybody changed the chunk size.
        $this->storeMark($request, 'brand_logo', 'brand_logo_path');
        $this->storeMark($request, 'brand_icon', 'brand_icon_path');

        return redirect()->route('admin.settings', $section)->with('success', 'Settings saved.');
    }

    /**
     * One uploaded mark, or a revert to the built-in one.
     *
     * A save that mentions neither leaves the stored mark alone, which is what
     * makes it safe for the settings form to be saved by somebody who came to
     * change something else entirely.
     */
    private function storeMark(Request $request, string $field, string $key): void
    {
        if ($request->boolean($field . '_revert')) {
            AppSetting::put($key, '');

            return;
        }

        if (!$request->hasFile($field)) {
            return;
        }

        AppSetting::put($key, $request->file($field)->store('brand', 'public'));
    }

    /**
     * A sample of one voice, as the saved settings make it, played on the
     * Voice page. The audio comes back through here so the engine's token and
     * the speech key stay on the server.
     */
    public function voiceTest(Request $request)
    {
        $validated = $request->validate([
            'voice' => ['required', 'in:en_female,en_male,ms_female,ms_male'],
            'text' => ['nullable', 'string', 'max:300'],
            'voice_name' => ['nullable', 'string', 'max:120'],
            // The form as it is now, so a choice can be heard before saving.
            'speech_engine' => ['nullable', 'in:browser,server,azure'],
            'speech_provider_id' => ['nullable', 'string', self::platformProviderRule()],
            'speech_model' => ['nullable', 'string', 'max:120'],
            'azure_speech_region' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9]*$/'],
            'azure_speech_key' => ['nullable', 'string', 'max:200'],
        ]);

        $engine = (string) ($validated['speech_engine'] ?? '');
        if ($engine === 'server' && empty($validated['speech_provider_id'])) {
            return response('Choose the provider your speech server runs on, then press play.', 422)
                ->header('Content-Type', 'text/plain');
        }

        $unsaved = ['speech_engine' => $engine, 'speech_model' => (string) ($validated['speech_model'] ?? '')];
        if ($engine === 'server') {
            $provider = AiProvider::platform()->findOrFail($validated['speech_provider_id']);
            $unsaved['speech_base_url'] = $provider->base_url;
            $unsaved['speech_api_key'] = (string) $provider->api_key;
        }
        if ($engine === 'azure') {
            $unsaved['azure_speech_region'] = (string) ($validated['azure_speech_region'] ?? '');
            $unsaved['azure_speech_key'] = (string) ($validated['azure_speech_key'] ?? '');
        }

        $result = EngineClient::testVoice($validated['voice'], (string) ($validated['text'] ?? ''),
            (string) ($validated['voice_name'] ?? ''), $unsaved);

        if (!$result['ok']) {
            return response($result['message'], 502)->header('Content-Type', 'text/plain');
        }

        return response($result['audio'], 200)->header('Content-Type', $result['type']);
    }

    public function reindex(Request $request)
    {
        $dimensions = (int) AppSetting::get('embedding_dimensions');
        $result = EngineClient::reindexAll($dimensions);

        if (($result['status'] ?? '') !== 'accepted') {
            return back()->with('error', $result['message'] ?? 'Re-indexing could not be started.');
        }

        return back()->with('success',
            'Re-indexing started for ' . ($result['sources'] ?? 0) . ' sources. Watch their status in the knowledge base.');
    }

    public function test(Request $request)
    {
        $validated = $request->validate([
            'provider_id' => ['nullable', 'string', self::platformProviderRule()],
            'embedding_model' => ['required', 'string'],
        ]);

        [$baseUrl, $apiKey] = self::embeddingEndpoint($validated['provider_id'] ?? null);

        return response()->json(EngineClient::testEmbedding($baseUrl, $apiKey, $validated['embedding_model']));
    }

    /**
     * What a provider publishes, for the model picker.
     *
     * A saved provider is looked up here, so the picker sends only its id. The
     * provider modal lists a draft instead, which is how Test proves an
     * endpoint answers before anyone saves it.
     */
    public function models(Request $request)
    {
        $validated = $request->validate([
            'provider_id' => ['nullable', 'required_without:base_url', 'string', self::platformProviderRule()],
            'base_url' => ['nullable', 'required_without:provider_id', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ], [
            'provider_id.required_without' => 'Choose a provider first.',
            'provider_id.exists' => 'That provider no longer exists. Reload the page.',
            'base_url.required_without' => 'Enter a base URL first.',
        ]);

        if (!empty($validated['provider_id'])) {
            $provider = AiProvider::platform()->findOrFail($validated['provider_id']);
            [$baseUrl, $apiKey] = [$provider->base_url, (string) $provider->api_key];
        } else {
            [$baseUrl, $apiKey] = [$validated['base_url'], (string) ($validated['api_key'] ?? '')];
        }

        return response()->json(EngineClient::listModels($baseUrl, $apiKey));
    }

    /** @return array{0: string, 1: string} */
    private static function embeddingEndpoint(?string $providerId): array
    {
        if (!$providerId) {
            return [self::DEFAULT_EMBEDDING_URL, ''];
        }

        $provider = AiProvider::platform()->findOrFail($providerId);

        return [$provider->base_url, (string) $provider->api_key];
    }

    /** What the providers list, the pickers and the modal all read. */
    public static function providerJson(AiProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'base_url' => $provider->base_url,
            'api_key' => $provider->api_key,
            'merge_system_prompt' => (bool) $provider->merge_system_prompt,
            'label' => $provider->label(),
            'used_by' => self::usageOf($provider->id),
        ];
    }
}
