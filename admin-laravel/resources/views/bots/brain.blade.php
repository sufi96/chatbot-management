@extends('layouts.app')

@section('page-title', 'Brain')

@section('content')
<div style="max-width: 1000px;">

    <div class="page-head mb-4">
        <div>
            <a href="{{ route('bots.edit', $bot->id) }}" class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
                <i class="bi bi-arrow-left"></i> {{ $bot->name }}
            </a>
            <h1>Brain</h1>
            <p>What this bot knows and how it decides what to say.</p>
        </div>
    </div>

    <form action="{{ route('bots.brain.update', $bot->id) }}" method="POST" id="brainForm">
        @csrf
        @method('PUT')

        <div class="card mb-3">
            <div class="card-header">System prompt</div>
            <div class="p-3">
                <div class="d-flex align-items-center gap-1.5 mb-2 flex-wrap">
                    <span class="text-muted" style="font-size: 0.75rem;">Start from</span>
                    <button type="button" onclick="setPromptPreset('support')" class="btn btn-sm btn-outline-secondary">Support</button>
                    <button type="button" onclick="setPromptPreset('sales')" class="btn btn-sm btn-outline-secondary">Sales</button>
                    <button type="button" onclick="setPromptPreset('technical')" class="btn btn-sm btn-outline-secondary">Technical</button>
                </div>

                <label for="system_prompt" class="visually-hidden">System prompt</label>
                <textarea name="system_prompt" id="system_prompt" rows="6" class="form-control font-monospace">{{ old('system_prompt', $bot->system_prompt) }}</textarea>
                <div class="form-text">Sent ahead of every conversation. Retrieved material is appended to it automatically.</div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Knowledge</div>
            <div class="p-3">
                <div class="form-check form-switch d-flex align-items-center gap-2 mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" name="retrieval_enabled" value="1"
                           id="retrieval_enabled" {{ old('retrieval_enabled', $bot->retrieval_enabled) ? 'checked' : '' }}>
                    <label class="form-check-label" for="retrieval_enabled">
                        Search the knowledge base before answering
                    </label>
                </div>
                <div class="form-text">Greetings, thanks and goodbyes never trigger a search, so a hello stays a hello.</div>

                @if($collections->isEmpty())
                    <p class="text-muted mb-0" style="font-size: 0.8125rem;">
                        This workspace has no collections yet.
                        <a href="{{ route('kb.index') }}">Create one</a> before switching retrieval on.
                    </p>
                @else
                    <div class="text-muted mb-2" style="font-size: 0.75rem;">Collections this bot reads</div>
                    <div class="ws-list">
                        @foreach($collections as $collection)
                            <div class="ws-row" style="grid-template-columns: auto minmax(0, 1fr) 110px;">
                                <input class="form-check-input mt-0" type="checkbox" name="collections[]"
                                       value="{{ $collection->id }}" id="col_{{ $collection->id }}"
                                       {{ in_array($collection->id, old('collections', $attached)) ? 'checked' : '' }}>
                                <label for="col_{{ $collection->id }}" class="text-truncate mb-0" style="cursor: pointer;">
                                    {{ $collection->name }}
                                </label>
                                <span class="figure-mono text-muted text-end" style="font-size: 0.75rem;">
                                    {{ $collection->sources_count }} sources
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Retrieval</div>
            <div class="p-3">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-sm-6">
                        <label for="retrieval_mode" class="form-label">Search mode</label>
                        <select name="retrieval_mode" id="retrieval_mode" class="form-select">
                            <option value="hybrid" {{ old('retrieval_mode', $bot->retrieval_mode) === 'hybrid' ? 'selected' : '' }}>Hybrid, meaning and keywords</option>
                            <option value="vector" {{ old('retrieval_mode', $bot->retrieval_mode) === 'vector' ? 'selected' : '' }}>Meaning only</option>
                            <option value="keyword" {{ old('retrieval_mode', $bot->retrieval_mode) === 'keyword' ? 'selected' : '' }}>Keywords only</option>
                        </select>
                        <div class="form-text">Hybrid suits most content. Keywords only helps when exact codes matter.</div>
                    </div>
                    <div class="col-12 col-sm-6">
                        <label for="retrieval_fallback" class="form-label">When nothing relevant is found</label>
                        <select name="retrieval_fallback" id="retrieval_fallback" class="form-select">
                            <option value="say_unknown" {{ old('retrieval_fallback', $bot->retrieval_fallback) === 'say_unknown' ? 'selected' : '' }}>Say the answer is not available</option>
                            <option value="answer_anyway" {{ old('retrieval_fallback', $bot->retrieval_fallback) === 'answer_anyway' ? 'selected' : '' }}>Answer from general knowledge</option>
                        </select>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-6 col-lg-4">
                        <label for="retrieval_top_k" class="form-label">Passages used</label>
                        <input type="number" name="retrieval_top_k" id="retrieval_top_k" class="form-control font-monospace"
                               min="1" max="20" value="{{ old('retrieval_top_k', $bot->retrieval_top_k) }}" required>
                        <div class="form-text">More context, slower answers.</div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <label for="retrieval_candidates" class="form-label">Candidates per branch</label>
                        <input type="number" name="retrieval_candidates" id="retrieval_candidates" class="form-control font-monospace"
                               min="5" max="100" value="{{ old('retrieval_candidates', $bot->retrieval_candidates) }}" required>
                        <div class="form-text">Depth searched before merging.</div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <label for="retrieval_min_score" class="form-label">Relevance floor</label>
                        <input type="number" step="0.001" name="retrieval_min_score" id="retrieval_min_score"
                               class="form-control font-monospace" min="0" max="1"
                               value="{{ old('retrieval_min_score', $bot->retrieval_min_score) }}" required>
                        <div class="form-text">Passages scoring below this are dropped. A top hit scores about 0.016, or 0.033 when both branches agree.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h2 class="h6 mb-0">Web search</h2>
            </div>
            <div class="card-body">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" name="web_search_enabled" value="1"
                           id="web_search_enabled" {{ old('web_search_enabled', $bot->web_search_enabled) ? 'checked' : '' }}>
                    <label class="form-check-label" for="web_search_enabled">Search the web</label>
                </div>
                <div class="form-text">
                    Runs only when the knowledge base returns nothing, so your own documents always win.
                    A bot with retrieval switched off has no knowledge base, so it will search every question.
                    The provider and its key are set in admin settings.
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-6 col-lg-4">
                        <label for="web_search_max_results" class="form-label">Results used</label>
                        <input type="number" name="web_search_max_results" id="web_search_max_results"
                               class="form-control font-monospace" min="1" max="10"
                               value="{{ old('web_search_max_results', $bot->web_search_max_results) }}" required>
                        <div class="form-text">More results cost more and crowd the prompt.</div>
                    </div>
                    <div class="col-6 col-lg-4">
                        <label for="web_search_country" class="form-label">Favour country</label>
                        <input type="text" name="web_search_country" id="web_search_country"
                               class="form-control font-monospace text-uppercase" maxlength="2" placeholder="MY"
                               value="{{ old('web_search_country', $bot->web_search_country) }}">
                        <div class="form-text">Two-letter code. Leave empty for no bias.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">Generation</div>
            <div class="p-3">
                <div class="row g-3">
                    <div class="col-6 col-lg-3">
                        <label for="top_p" class="form-label">Top p</label>
                        <input type="number" step="0.05" min="0" max="1" name="top_p" id="top_p"
                               class="form-control font-monospace" value="{{ old('top_p', $bot->top_p) }}" required>
                        <div class="form-text">Narrows word choice.</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label for="top_k_sampling" class="form-label">Top k</label>
                        <input type="number" min="1" max="200" name="top_k_sampling" id="top_k_sampling"
                               class="form-control font-monospace" value="{{ old('top_k_sampling', $bot->top_k_sampling) }}"
                               placeholder="unset">
                        <div class="form-text">Blank leaves it to the model.</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label for="presence_penalty" class="form-label">Presence penalty</label>
                        <input type="number" step="0.1" min="-2" max="2" name="presence_penalty" id="presence_penalty"
                               class="form-control font-monospace" value="{{ old('presence_penalty', $bot->presence_penalty) }}" required>
                        <div class="form-text">Pushes toward new topics.</div>
                    </div>
                    <div class="col-6 col-lg-3">
                        <label for="frequency_penalty" class="form-label">Frequency penalty</label>
                        <input type="number" step="0.1" min="-2" max="2" name="frequency_penalty" id="frequency_penalty"
                               class="form-control font-monospace" value="{{ old('frequency_penalty', $bot->frequency_penalty) }}" required>
                        <div class="form-text">Discourages repetition.</div>
                    </div>
                </div>

                <div class="mt-3" style="max-width: 320px;">
                    <label for="thinking_level" class="form-label">Thinking level</label>
                    <select name="thinking_level" id="thinking_level" class="form-select">
                        <option value="off" {{ old('thinking_level', $bot->thinking_level) === 'off' ? 'selected' : '' }}>Off</option>
                        <option value="low" {{ old('thinking_level', $bot->thinking_level) === 'low' ? 'selected' : '' }}>Low</option>
                        <option value="medium" {{ old('thinking_level', $bot->thinking_level) === 'medium' ? 'selected' : '' }}>Medium</option>
                        <option value="high" {{ old('thinking_level', $bot->thinking_level) === 'high' ? 'selected' : '' }}>High</option>
                    </select>
                    <div class="form-text">
                        Off stops a hybrid reasoning model such as Qwen3 from thinking at all, which keeps replies
                        short and inside the token limit. Any other level lets it think, and the thinking appears
                        folded above each answer. Only providers that grade reasoning effort tell low, medium and
                        high apart; on a local vLLM server all three simply mean on.
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <span class="text-muted d-none d-sm-inline" style="font-size: 0.75rem;">
                Saving applies to every site running this bot.
            </span>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <a href="{{ route('bots.edit', $bot->id) }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-brand">Save brain settings</button>
            </div>
        </div>
    </form>

</div>
@endsection

@push('scripts')
<script>
    function setPromptPreset(type) {
        var el = document.getElementById('system_prompt');
        if (type === 'support') {
            el.value = "You are a professional customer support assistant. Answer clearly, politely and concisely.";
        } else if (type === 'sales') {
            el.value = "You are a knowledgeable sales concierge. Help customers find products and explain features and pricing.";
        } else if (type === 'technical') {
            el.value = "You are a technical support engineer. Give step-by-step diagnostics and clean code snippets.";
        }
    }
</script>
@endpush
