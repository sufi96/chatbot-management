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

    private function authorizeEditor(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'editor'), 403);
    }

    private function authorizeViewer(Request $request, string $systemId): void
    {
        abort_unless($request->user()->canManageSystem($systemId, 'viewer'), 403);
    }
}
