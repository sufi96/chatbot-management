<?php

namespace App\Http\Controllers;

use App\Models\KbCollection;
use App\Models\KbSource;
use App\Services\EngineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KnowledgeBaseController extends Controller
{
    public function index(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        if (!$activeSystem) {
            return redirect()->route('systems.index')
                ->with('error', 'Select a workspace first.');
        }

        return view('kb.index', [
            'activeSystem' => $activeSystem,
            'collections' => KbCollection::withCount('sources')
                ->where('system_id', $activeSystem->id)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        KbCollection::create([
            'id' => 'kbc_' . Str::random(12),
            'system_id' => $activeSystem->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return redirect()->route('kb.index')->with('success', 'Collection created.');
    }

    public function show(Request $request, string $id)
    {
        $collection = KbCollection::with('sources')->findOrFail($id);
        $this->authorizeViewer($request, $collection->system_id);

        return view('kb.show', ['collection' => $collection]);
    }

    public function destroy(Request $request, string $id)
    {
        $collection = KbCollection::with('sources')->findOrFail($id);
        $this->authorizeEditor($request, $collection->system_id);

        foreach ($collection->sources as $source) {
            EngineClient::deleteChunks($source->id);
        }
        $collection->delete();

        return redirect()->route('kb.index')->with('success', 'Collection deleted.');
    }

    public function storeSource(Request $request, string $collectionId)
    {
        $collection = KbCollection::findOrFail($collectionId);
        $this->authorizeEditor($request, $collection->system_id);

        $validated = $request->validate([
            'type' => ['required', 'in:text,qa'],
            'title' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string'],
        ], [
            'body.required' => 'A question needs an answer.',
        ]);

        $source = KbSource::create([
            'id' => 'kbs_' . Str::random(12),
            'collection_id' => $collection->id,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => 'pending',
        ]);

        EngineClient::indexSource($source->id);

        return redirect()->route('kb.show', $collection->id)
            ->with('success', 'Added. Indexing runs in the background.');
    }

    public function reindexSource(Request $request, string $sourceId)
    {
        $source = KbSource::with('collection')->findOrFail($sourceId);
        $this->authorizeEditor($request, $source->collection->system_id);

        $source->update(['status' => 'pending', 'error_message' => null]);
        EngineClient::indexSource($source->id);

        return back()->with('success', 'Re-indexing started.');
    }

    public function destroySource(Request $request, string $sourceId)
    {
        $source = KbSource::with('collection')->findOrFail($sourceId);
        $this->authorizeEditor($request, $source->collection->system_id);

        EngineClient::deleteChunks($source->id);
        $collectionId = $source->collection_id;
        $source->delete();

        return redirect()->route('kb.show', $collectionId)->with('success', 'Source removed.');
    }

    public function playground(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        return view('kb.playground', [
            'activeSystem' => $activeSystem,
            'collections' => KbCollection::withCount('sources')
                ->where('system_id', $activeSystem->id)->orderBy('name')->get(),
            'results' => null,
            'titles' => collect(),
            'error' => null,
            'query' => '',
            'selected' => [],
            'settings' => ['mode' => 'hybrid', 'top_k' => 5, 'candidates' => 30, 'min_score' => 0],
        ]);
    }

    public function runPlayground(Request $request)
    {
        $activeSystem = view()->shared('activeSystem');
        $this->authorizeEditor($request, $activeSystem->id);

        $validated = $request->validate([
            'query' => ['required', 'string', 'max:2000'],
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string'],
            'mode' => ['required', 'in:hybrid,vector,keyword'],
            'top_k' => ['required', 'integer', 'min:1', 'max:20'],
            'candidates' => ['required', 'integer', 'min:5', 'max:100'],
            'min_score' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        // Whatever the form posted, only this workspace's collections are searched.
        $allowed = KbCollection::where('system_id', $activeSystem->id)
            ->whereIn('id', $request->input('collections', []))
            ->pluck('id')->all();

        $response = EngineClient::search(
            $allowed, $validated['query'], $validated['mode'],
            (int) $validated['top_k'], (int) $validated['candidates'],
            (float) $validated['min_score'],
        );

        $results = $response['results'] ?? [];

        return view('kb.playground', [
            'activeSystem' => $activeSystem,
            'collections' => KbCollection::withCount('sources')
                ->where('system_id', $activeSystem->id)->orderBy('name')->get(),
            'results' => $results,
            'titles' => KbSource::whereIn('id', array_column($results, 'source_id'))
                ->pluck('title', 'id'),
            'error' => $response['error'] ?? null,
            'query' => $validated['query'],
            'selected' => $allowed,
            'settings' => [
                'mode' => $validated['mode'],
                'top_k' => (int) $validated['top_k'],
                'candidates' => (int) $validated['candidates'],
                'min_score' => (float) $validated['min_score'],
            ],
        ]);
    }

    private function authorizeEditor(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }

    private function authorizeViewer(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'viewer'), 403);
    }
}
