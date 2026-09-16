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
            'description' => 'What the guard blocks, for every bot that has it switched on under Brain. A bot can add topics of its own there.',
        ],
        'chunking' => [
            'label' => 'Chunking', 'icon' => 'bi-scissors',
            'description' => 'How sources are cut into passages, and how much of them reaches the model.',
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

    /** Where Ollama listens on this machine: what a blank embedding link means. */
    public const DEFAULT_EMBEDDING_URL = 'http://localhost:11434/v1';

    private const KEYS = [
        'embedding_provider_id', 'embedding_model',
        'embedding_dimensions', 'chunk_size', 'chunk_overlap',
        'context_char_budget',
        'web_search_provider', 'web_search_tavily_key', 'web_search_brave_key',
        'guard_topics', 'guard_borderline',
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
        $links = ['embedding_provider_id' => 'Embedding'];

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
            'settings' => $settings,
            'providers' => AiProvider::platform()->orderBy('name')->get()
                ->map(fn (AiProvider $provider) => self::providerJson($provider))
                ->values(),
            'modelRoles' => self::MODEL_ROLES,
            'defaultEmbeddingUrl' => self::DEFAULT_EMBEDDING_URL,
            'guardCategories' => self::GUARD_CATEGORIES,
            'guardChosen' => array_filter(explode(',', (string) AppSetting::get('guard_categories'))),
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
            // Read only from the form that has the checkboxes; see below.
            'guard_categories' => ['exclude_unless:guard_categories_present,1', 'nullable', 'array'],
            'guard_categories.*' => ['exclude_unless:guard_categories_present,1', 'string',
                Rule::in(array_keys(self::GUARD_CATEGORIES))],
            'guard_topics' => ['nullable', 'string', 'max:2000'],
            'guard_borderline' => ['nullable', 'in:allow,block'],
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
            AppSetting::put($key, $validated[$key] ?? '');
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
            'label' => $provider->label(),
            'used_by' => self::usageOf($provider->id),
        ];
    }
}
