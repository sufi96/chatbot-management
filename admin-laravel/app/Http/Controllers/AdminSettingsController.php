<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\EngineClient;
use App\Support\Brand;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    private const KEYS = [
        'embedding_base_url', 'embedding_api_key', 'embedding_model',
        'embedding_dimensions', 'chunk_size', 'chunk_overlap',
        'context_char_budget',
        'web_search_provider', 'web_search_tavily_key', 'web_search_brave_key',
    ];

    /**
     * Every job a model does besides answering, in the order the screen lists
     * them. api-engine/roles.py holds the same names and decides what blank
     * falls back to; keep the two in step.
     */
    public const MODEL_ROLES = [
        'intent' => [
            'label' => 'Intent',
            'job' => 'Reads each message with the recent conversation, decides whether it needs facts, and rewrites a follow-up into a question that stands on its own.',
            'blank' => "each bot's own model",
            'placeholder' => 'qwen3.5:4b',
        ],
        'sql' => [
            'label' => 'SQL',
            'job' => 'Writes the query, and decides whether any table can answer a question at all. Small models are markedly weaker at SQL than at conversation.',
            'blank' => "each bot's own model",
            'placeholder' => 'qwen3-coder:30b',
        ],
        'rerank' => [
            'label' => 'Reranker',
            'job' => 'Scores each retrieved passage against the question. Needs an endpoint that serves /v1/rerank, such as vLLM or llama.cpp. Ollama does not.',
            'blank' => 'reranking is skipped',
            'placeholder' => 'bge-reranker-v2-m3',
        ],
        'guard' => [
            'label' => 'Guard',
            'job' => 'Checks what visitors send, and what bots answer, for harmful content.',
            'blank' => "each bot's own model",
            'placeholder' => 'qwen3guard-gen:0.6b',
        ],
        'vision' => [
            'label' => 'Vision',
            'job' => 'Reads uploaded images, and scanned PDFs with no text layer, page by page up to 40 pages. Needs a model that accepts images. Re-index a source after setting this.',
            'blank' => 'scans and images cannot be read',
            'placeholder' => 'qwen3-vl:8b',
        ],
    ];

    /** The stored keys, each role's three fields included. */
    private static function keys(): array
    {
        $keys = self::KEYS;

        foreach (array_keys(self::MODEL_ROLES) as $role) {
            array_push($keys, "{$role}_model_base_url", "{$role}_model_api_key", "{$role}_model_name");
        }

        return $keys;
    }

    private static function modelRoleRules(): array
    {
        $rules = [];

        foreach (array_keys(self::MODEL_ROLES) as $role) {
            $rules["{$role}_model_base_url"] = ['nullable', 'string', 'max:500'];
            $rules["{$role}_model_api_key"] = ['nullable', 'string', 'max:500'];
            $rules["{$role}_model_name"] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    public function edit()
    {
        $settings = [];
        foreach (self::keys() as $key) {
            $settings[$key] = AppSetting::get($key);
        }

        return view('admin.settings', [
            'settings' => $settings,
            'modelRoles' => self::MODEL_ROLES,
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
        $validated = $request->validate(array_merge([
            'embedding_base_url' => ['required', 'string', 'max:500'],
            'embedding_api_key' => ['nullable', 'string', 'max:500'],
            'embedding_model' => ['required', 'string', 'max:120'],
            'embedding_dimensions' => ['required', 'integer', 'min:64', 'max:4096'],
            'chunk_size' => ['required', 'integer', 'min:400', 'max:8000'],
            'chunk_overlap' => ['required', 'integer', 'min:0', 'lt:chunk_size'],
            'context_char_budget' => ['required', 'integer', 'min:1000', 'max:20000'],
            'web_search_provider' => ['required', 'in:duckduckgo,tavily,brave'],
            'web_search_tavily_key' => ['nullable', 'string', 'max:200'],
            'web_search_brave_key' => ['nullable', 'string', 'max:200'],
            // No SVG. One served from our own origin runs its own script for
            // anyone who opens it directly, and super admin only is not a
            // good enough reason to leave that open.
            'brand_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'brand_icon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
        ], self::modelRoleRules()), [
            'chunk_overlap.lt' => 'Overlap must be smaller than the chunk size.',
            'brand_logo.mimes' => 'The logo must be a PNG, JPG or WebP image.',
            'brand_icon.mimes' => 'The icon must be a PNG, JPG or WebP image.',
        ]);

        foreach (self::keys() as $key) {
            AppSetting::put($key, $validated[$key] ?? '');
        }

        // Deliberately not in KEYS. That loop writes every key it knows on
        // every save, so a logo swept into it would vanish the next time
        // anybody changed the chunk size.
        $this->storeMark($request, 'brand_logo', 'brand_logo_path');
        $this->storeMark($request, 'brand_icon', 'brand_icon_path');

        return redirect()->route('admin.settings')->with('success', 'Settings saved.');
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
            'embedding_base_url' => ['required', 'string'],
            'embedding_api_key' => ['nullable', 'string'],
            'embedding_model' => ['required', 'string'],
        ]);

        return response()->json(EngineClient::testEmbedding(
            $validated['embedding_base_url'],
            $validated['embedding_api_key'] ?? '',
            $validated['embedding_model'],
        ));
    }

    public function models(Request $request)
    {
        $validated = $request->validate([
            'embedding_base_url' => ['required', 'string'],
            'embedding_api_key' => ['nullable', 'string'],
        ]);

        return response()->json(EngineClient::listEmbeddingModels(
            $validated['embedding_base_url'],
            $validated['embedding_api_key'] ?? '',
        ));
    }
}
