<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\DbConnection;
use App\Models\KbCollection;
use App\Support\SourceOrder;
use Illuminate\Http\Request;

class BotBrainController extends Controller
{
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
            'retrieval_fallback' => ['required', 'in:say_unknown,answer_anyway'],
            'web_search_max_results' => ['required', 'integer', 'min:1', 'max:10'],
            'web_search_country' => ['nullable', 'string', 'size:2', 'alpha'],
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
        ]);

        $validated['retrieval_enabled'] = $request->boolean('retrieval_enabled');
        $validated['web_search_enabled'] = $request->boolean('web_search_enabled');
        $validated['db_query_enabled'] = $request->boolean('db_query_enabled');
        $validated['intent_enabled'] = $request->boolean('intent_enabled');
        $validated['guard_enabled'] = $request->boolean('guard_enabled');

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

        return redirect()->route('bots.brain', $bot->id)->with('success', 'Brain settings saved.');
    }
}
