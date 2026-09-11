<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\EngineClient;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    private const KEYS = [
        'embedding_base_url', 'embedding_api_key', 'embedding_model',
        'embedding_dimensions', 'vector_driver', 'chunk_size', 'chunk_overlap',
        'context_char_budget',
        'web_search_provider', 'web_search_tavily_key', 'web_search_brave_key',
    ];

    public function edit()
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $settings[$key] = AppSetting::get($key);
        }

        return view('admin.settings', ['settings' => $settings]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'embedding_base_url' => ['required', 'string', 'max:500'],
            'embedding_api_key' => ['nullable', 'string', 'max:500'],
            'embedding_model' => ['required', 'string', 'max:120'],
            'embedding_dimensions' => ['required', 'integer', 'min:64', 'max:4096'],
            'vector_driver' => ['required', 'in:pgvector,sqlite'],
            'chunk_size' => ['required', 'integer', 'min:400', 'max:8000'],
            'chunk_overlap' => ['required', 'integer', 'min:0', 'lt:chunk_size'],
            'context_char_budget' => ['required', 'integer', 'min:1000', 'max:20000'],
            'web_search_provider' => ['required', 'in:duckduckgo,tavily,brave'],
            'web_search_tavily_key' => ['nullable', 'string', 'max:200'],
            'web_search_brave_key' => ['nullable', 'string', 'max:200'],
        ], [
            'chunk_overlap.lt' => 'Overlap must be smaller than the chunk size.',
        ]);

        foreach (self::KEYS as $key) {
            AppSetting::put($key, $validated[$key] ?? '');
        }

        return redirect()->route('admin.settings')->with('success', 'Settings saved.');
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
