<?php

namespace App\Http\Controllers;

use App\Models\BotProfile;
use App\Models\KbCollection;
use Illuminate\Http\Request;

class BotBrainController extends Controller
{
    public function edit(Request $request, string $id)
    {
        $bot = BotProfile::with('collections')->findOrFail($id);
        abort_unless($request->user()->canManageSystem($bot->system_id, 'editor'), 403);

        return view('bots.brain', [
            'bot' => $bot,
            'collections' => KbCollection::withCount('sources')
                ->where('system_id', $bot->system_id)
                ->orderBy('name')
                ->get(),
            'attached' => $bot->collections->pluck('id')->all(),
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
            'retrieval_fallback' => ['required', 'in:say_unknown,answer_anyway'],
            'top_p' => ['required', 'numeric', 'min:0', 'max:1'],
            'top_k_sampling' => ['nullable', 'integer', 'min:1', 'max:200'],
            'presence_penalty' => ['required', 'numeric', 'min:-2', 'max:2'],
            'frequency_penalty' => ['required', 'numeric', 'min:-2', 'max:2'],
            'thinking_level' => ['required', 'in:off,low,medium,high'],
            'collections' => ['nullable', 'array'],
            'collections.*' => ['string'],
        ]);

        $validated['retrieval_enabled'] = $request->boolean('retrieval_enabled');
        unset($validated['collections']);
        $bot->update($validated);

        // Only collections from this bot's own workspace may be attached, whatever
        // the form posted.
        $allowed = KbCollection::where('system_id', $bot->system_id)
            ->whereIn('id', $request->input('collections', []))
            ->pluck('id')
            ->all();
        $bot->collections()->sync($allowed);

        return redirect()->route('bots.brain', $bot->id)->with('success', 'Brain settings saved.');
    }
}
