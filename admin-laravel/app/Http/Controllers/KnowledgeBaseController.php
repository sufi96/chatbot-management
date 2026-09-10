<?php

namespace App\Http\Controllers;

use App\Models\KbCollection;
use App\Models\KbSource;
use App\Services\EngineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            'description' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string'],
        ], [
            'body.required' => 'A question needs an answer.',
        ]);

        $source = KbSource::create([
            'id' => 'kbs_' . Str::random(12),
            'collection_id' => $collection->id,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'body' => $validated['body'],
            'status' => 'pending',
        ]);

        EngineClient::indexSource($source->id);

        return redirect()->route('kb.show', $collection->id)
            ->with('success', 'Added. Indexing runs in the background.');
    }

    public function uploadSource(Request $request, string $collectionId)
    {
        $collection = KbCollection::findOrFail($collectionId);
        $this->authorizeEditor($request, $collection->system_id);

        $validated = $request->validate([
            'file' => [
                'required', 'file', 'max:20480',   // kilobytes, so 20 MB
                'mimes:pdf,docx,pptx,xlsx,xls,csv,md,txt,html,htm',
            ],
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [
            'file.mimes' => 'That file type is not supported. Use PDF, Word, PowerPoint, Excel, CSV, Markdown, HTML or plain text.',
            'file.max' => 'Files must be 20 MB or smaller.',
        ]);

        $upload = $validated['file'];
        $path = $upload->store('kb/sources', 'public');

        $source = KbSource::create([
            'id' => 'kbs_' . Str::random(12),
            'collection_id' => $collection->id,
            'type' => 'file',
            // Absent, blank, or whitespace all fall back to the file name.
            'title' => trim($validated['title'] ?? '') ?: $upload->getClientOriginalName(),
            'description' => $validated['description'] ?? null,
            'file_path' => $path,
            'file_mime' => $upload->getClientMimeType(),
            'file_size' => $upload->getSize(),
            'status' => 'pending',
        ]);

        EngineClient::indexSource($source->id);

        return redirect()->route('kb.show', $collection->id)
            ->with('success', 'Uploaded. Extraction and indexing run in the background.');
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
        // An orphaned upload would sit on disk forever otherwise.
        if ($source->file_path) {
            Storage::disk('public')->delete($source->file_path);
        }
        $collectionId = $source->collection_id;
        $source->delete();

        return redirect()->route('kb.show', $collectionId)->with('success', 'Source removed.');
    }

    public function showSource(Request $request, string $sourceId)
    {
        $source = KbSource::with('collection')->findOrFail($sourceId);
        $this->authorizeViewer($request, $source->collection->system_id);

        // Read-only. The engine remains the only writer of this table; a
        // listing does not justify an HTTP hop to fetch it.
        $chunks = DB::table('kb_chunks')
            ->where('source_id', $source->id)
            ->orderBy('ordinal')
            ->get(['id', 'ordinal', 'heading_path', 'char_count', 'content']);

        return view('kb.source', [
            'source' => $source,
            'chunks' => $chunks,
            'canEdit' => $request->user()->canManageSystem(
                $source->collection->system_id, 'editor'),
        ]);
    }

    public function updateSource(Request $request, string $sourceId)
    {
        $source = KbSource::with('collection')->findOrFail($sourceId);
        $this->authorizeEditor($request, $source->collection->system_id);

        $rules = [
            'title' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
        // An uploaded file's text comes from extraction. Editing it here would
        // create a copy that silently diverges from the downloadable original.
        if ($source->type !== 'file') {
            $rules['body'] = ['required', 'string'];
        }
        $validated = $request->validate($rules);

        $source->title = $validated['title'];
        $source->description = $validated['description'] ?? null;
        if ($source->type !== 'file') {
            $source->body = $validated['body'];
        }
        $source->status = 'pending';
        $source->error_message = null;
        $source->save();

        EngineClient::indexSource($source->id);

        return redirect()->route('kb.sources.show', $source->id)
            ->with('success', 'Saved. Re-indexing runs in the background.');
    }

    public function downloadSource(Request $request, string $sourceId)
    {
        $source = KbSource::with('collection')->findOrFail($sourceId);
        $this->authorizeViewer($request, $source->collection->system_id);

        if ($source->type === 'file') {
            abort_unless($source->file_path
                && Storage::disk('public')->exists($source->file_path), 404);

            return Storage::disk('public')->download($source->file_path, $source->title);
        }

        $markdown = "# {$source->title}\n\n";
        if ($source->description) {
            $markdown .= "{$source->description}\n\n";
        }
        $markdown .= (string) $source->body . "\n";

        return response($markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'
                . Str::slug($source->title) . '.md"',
        ]);
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

        // The breadcrumb is part of the indexed text, so it comes back inside
        // the passage. Show it once, as a chip, not twice.
        $results = array_map(function (array $result): array {
            $result['heading_path'] = $result['heading_path'] ?? '';
            $break = strpos($result['content'], "\n\n");
            $hasHeader = str_starts_with($result['content'], 'Section: ')
                || str_starts_with($result['content'], 'About: ');
            if ($hasHeader && $break !== false) {
                $result['content'] = ltrim(substr($result['content'], $break + 2));
            }

            return $result;
        }, $response['results'] ?? []);

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
