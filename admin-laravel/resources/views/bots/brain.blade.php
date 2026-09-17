@extends('layouts.app')

@section('page-title', 'Brain')

@section('content')
<div style="max-width: 1200px;">

    <div class="page-head mb-3">
        <div>
            <a href="{{ route('bots.edit', $bot->id) }}" class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
                <i class="bi bi-arrow-left"></i> {{ $bot->name }}
            </a>
            <h1>Brain</h1>
            <p>What this bot knows and how it decides what to say.</p>
        </div>

        {{-- The real widget is on this page. Opening it from the head means a
             prompt change can be tried where it was made. --}}
        <button type="button" class="btn btn-outline-primary d-inline-flex align-items-center gap-2"
                onclick="openTestWidget()">
            <i class="bi bi-chat-dots"></i> Open test widget
        </button>
    </div>

    @include('bots._tabs')

    <form action="{{ route('bots.brain.update', $bot->id) }}" method="POST" id="brainForm">
        @csrf
        @method('PUT')

        {{-- Two columns once there is room for them, split by what they
             govern: how the bot speaks on the left, where its answers come
             from on the right. The same grouping as admin settings. --}}
        <div class="row g-3">

            {{-- ============ How it speaks and decides ============ --}}
            <div class="col-12 col-xl-6">

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
                        <textarea name="system_prompt" id="system_prompt" rows="8" class="form-control font-monospace">{{ old('system_prompt', $bot->system_prompt) }}</textarea>
                        <div class="form-text">Sent ahead of every conversation. Retrieved material is appended to it automatically.</div>
                    </div>
                </div>

                @php
                    $sourceLabels = [
                        'documents' => ['Knowledge base', 'The written material attached below.', $bot->retrieval_enabled],
                        'database' => ['Database', 'Live records from a connected database.', $bot->db_query_enabled],
                        'web' => ['Web search', 'A public search of the open internet.', $bot->web_search_enabled],
                    ];
                @endphp

                <div class="card mb-3">
                    <div class="card-header">Answer source order</div>
                    <div class="p-3">
                        <p class="text-muted mb-3" style="font-size: 0.8rem;">
                            Each question goes to these in turn, and the first one with something
                            to say answers it. A source that is switched off is passed over.
                        </p>

                        <input type="hidden" name="source_order" id="source_order"
                               value="{{ old('source_order', $bot->source_order) }}">

                        <ol class="source-order list-unstyled mb-0" id="sourceOrder">
                            @foreach(\App\Support\SourceOrder::toList(old('source_order', $bot->source_order)) as $token)
                                @php([$label, $blurb, $on] = $sourceLabels[$token])
                                <li class="source-order-row d-flex align-items-center gap-2" data-token="{{ $token }}">
                                    <span class="source-order-rank figure-mono"></span>
                                    <span class="flex-grow-1">
                                        <span class="fw-semibold">{{ $label }}</span>
                                        @unless($on)
                                            <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1">switched off</span>
                                        @endunless
                                        <br>
                                        <span class="text-muted" style="font-size: 0.75rem;">{{ $blurb }}</span>
                                    </span>
                                    <span class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-secondary" data-move="up"
                                                aria-label="Move {{ $label }} earlier">
                                            <i class="bi bi-arrow-up"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" data-move="down"
                                                aria-label="Move {{ $label }} later">
                                            <i class="bi bi-arrow-down"></i>
                                        </button>
                                    </span>
                                </li>
                            @endforeach
                        </ol>

                        {{-- With the order rather than inside one source: it changes
                             what every source is asked. --}}
                        <div class="mt-3 pt-3" style="border-top: 1px solid var(--border);">
                            <div class="form-check form-switch d-flex align-items-center gap-2 mb-1">
                                <input class="form-check-input" type="checkbox" role="switch" name="intent_enabled" value="1"
                                       id="intent_enabled" {{ old('intent_enabled', $bot->intent_enabled) ? 'checked' : '' }}>
                                <label class="form-check-label" for="intent_enabled">Understand follow-up questions</label>
                            </div>
                            <div class="form-text">
                                Reads each question with the conversation before it, so "and the warranty?"
                                is searched as the whole question, and small talk is answered without searching.
                                It adds one model call to every question. Which model is set in admin settings.
                            </div>
                        </div>

                        {{-- This governs the end of the cascade, not the knowledge
                             base alone, so it belongs with the order rather than
                             inside one source's settings. --}}
                        <div class="mt-3 pt-3" style="border-top: 1px solid var(--border); max-width: 380px;">
                            <label for="retrieval_fallback" class="form-label">When no source has an answer</label>
                            <select name="retrieval_fallback" id="retrieval_fallback" class="form-select">
                                <option value="say_unknown" {{ old('retrieval_fallback', $bot->retrieval_fallback) === 'say_unknown' ? 'selected' : '' }}>Say the answer is not available</option>
                                <option value="answer_anyway" {{ old('retrieval_fallback', $bot->retrieval_fallback) === 'answer_anyway' ? 'selected' : '' }}>Answer from general knowledge</option>
                            </select>
                            <div class="form-text">A greeting is never treated as a question, so it is answered whatever this says.</div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Safety</div>
                    <div class="p-3">
                        <div class="form-check form-switch d-flex align-items-center gap-2 mb-1">
                            <input class="form-check-input" type="checkbox" role="switch" name="guard_enabled" value="1"
                                   id="guard_enabled" {{ old('guard_enabled', $bot->guard_enabled) ? 'checked' : '' }}>
                            <label class="form-check-label" for="guard_enabled">Check messages and answers for harm</label>
                        </div>
                        <div class="form-text mb-3">
                            A harmful message gets the refusal below and is never searched or answered.
                            A harmful answer has already been sent, so it is flagged in conversations for you to review.
                            If the check cannot run, messages go through as usual. The model is set in admin settings.
                        </div>

                        <label for="guard_refusal" class="form-label">Refusal</label>
                        <textarea name="guard_refusal" id="guard_refusal" rows="2" maxlength="500" class="form-control"
                                  placeholder="Sorry, I can't help with that. Is there something else I can help you with?">{{ old('guard_refusal', $bot->guard_refusal) }}</textarea>
                        <div class="form-text">Leave blank for the default. Write it in the language your visitors use.</div>

                        <label for="guard_topics" class="form-label mt-3">Also block these topics</label>
                        <textarea name="guard_topics" id="guard_topics" rows="3" maxlength="2000"
                                  class="form-control @error('guard_topics') is-invalid @enderror"
                                  placeholder="competitor pricing&#10;legal advice">{{ old('guard_topics', $bot->guard_topics) }}</textarea>
                        @error('guard_topics')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">
                            One per line, in plain words. Added to the categories and topics every guarded bot blocks,
                            which are set
                            @if(auth()->user()->isSuperAdmin())
                                under <a href="{{ route('admin.settings', 'guard') }}">Guard in admin settings</a>.
                            @else
                                by a super admin in admin settings.
                            @endif
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

            </div>

            {{-- ============ Where its answers come from ============ --}}
            <div class="col-12 col-xl-6">

                {{-- Documents and databases are the two stores this bot reads,
                     so they are one card with a tab each rather than two boxes
                     sitting apart. Web search has no store to pick from and
                     keeps its own card below. --}}
                <div class="card mb-3">
                    <div class="card-header p-0">
                        <ul class="nav nav-tabs px-2 pt-2" role="tablist" style="border-bottom: 0;">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-kb"
                                        type="button" role="tab" aria-controls="pane-kb" aria-selected="true">
                                    <i class="bi bi-journal-text"></i> Knowledge base
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-db"
                                        type="button" role="tab" aria-controls="pane-db" aria-selected="false">
                                    <i class="bi bi-database"></i> Databases
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="tab-content">

                        {{-- Knowledge base --}}
                        <div class="tab-pane fade show active" id="pane-kb" role="tabpanel">
                            <div class="p-3">
                                <div class="form-check form-switch d-flex align-items-center gap-2 mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="retrieval_enabled" value="1"
                                           id="retrieval_enabled" {{ old('retrieval_enabled', $bot->retrieval_enabled) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="retrieval_enabled">
                                        Search the knowledge base before answering
                                    </label>
                                </div>
                                <div class="form-text mb-3">Greetings, thanks and goodbyes never trigger a search, so a hello stays a hello.</div>

                                @if($collections->isEmpty())
                                    <p class="text-muted mb-0" style="font-size: 0.8125rem;">
                                        This workspace has no collections yet.
                                        <a href="{{ route('kb.index') }}">Create one</a> before switching retrieval on.
                                    </p>
                                @else
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <span class="text-muted" style="font-size: 0.75rem;">Collections this bot reads</span>
                                        <a href="{{ route('kb.index') }}" class="d-inline-flex align-items-center gap-1" style="font-size: 0.75rem;">
                                            Manage collections <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
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

                                <div class="mt-3 pt-3" style="border-top: 1px solid var(--border);">
                                    <div class="fw-semibold mb-2" style="font-size: 0.8125rem;">How the search runs</div>

                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label for="retrieval_mode" class="form-label">Search mode</label>
                                            <select name="retrieval_mode" id="retrieval_mode" class="form-select">
                                                <option value="hybrid" {{ old('retrieval_mode', $bot->retrieval_mode) === 'hybrid' ? 'selected' : '' }}>Hybrid, meaning and keywords</option>
                                                <option value="vector" {{ old('retrieval_mode', $bot->retrieval_mode) === 'vector' ? 'selected' : '' }}>Meaning only</option>
                                                <option value="keyword" {{ old('retrieval_mode', $bot->retrieval_mode) === 'keyword' ? 'selected' : '' }}>Keywords only</option>
                                            </select>
                                            <div class="form-text">Hybrid suits most content. Keywords only helps when exact codes matter.</div>
                                        </div>
                                        <div class="col-6">
                                            <label for="retrieval_top_k" class="form-label">Passages used</label>
                                            <input type="number" name="retrieval_top_k" id="retrieval_top_k" class="form-control font-monospace"
                                                   min="1" max="20" value="{{ old('retrieval_top_k', $bot->retrieval_top_k) }}" required>
                                            <div class="form-text">More context, slower answers.</div>
                                        </div>
                                        <div class="col-6">
                                            <label for="retrieval_candidates" class="form-label">Candidates per branch</label>
                                            <input type="number" name="retrieval_candidates" id="retrieval_candidates" class="form-control font-monospace"
                                                   min="5" max="100" value="{{ old('retrieval_candidates', $bot->retrieval_candidates) }}" required>
                                            <div class="form-text">Depth searched before merging.</div>
                                        </div>
                                        <div class="col-12">
                                            <label for="retrieval_min_score" class="form-label">Relevance floor</label>
                                            <input type="number" step="0.001" name="retrieval_min_score" id="retrieval_min_score"
                                                   class="form-control font-monospace" min="0" max="1"
                                                   value="{{ old('retrieval_min_score', $bot->retrieval_min_score) }}" required>
                                            <div class="form-text">Passages scoring below this are dropped. An unrelated top hit scores about 0.016, and a genuine one about 0.033 when both branches agree, so keep this above 0.017. Set it lower and every question looks answered, which also stops web search ever running.</div>
                                        </div>
                                        <div class="col-12">
                                            <label for="retrieval_min_similarity" class="form-label">Similarity floor</label>
                                            <input type="number" step="0.01" name="retrieval_min_similarity" id="retrieval_min_similarity"
                                                   class="form-control font-monospace" min="0" max="1"
                                                   value="{{ old('retrieval_min_similarity', $bot->retrieval_min_similarity) }}">
                                            <div class="form-text">When no passage is at least this similar to the question, the knowledge base is treated as having no answer, and the next source in the order is asked. 0.65 suits nomic-embed-text; another embedding model needs its own value, found in the retrieval playground. Not used when a reranker is set. 0 turns it off.</div>
                                        </div>
                                        <div class="col-12">
                                            <label for="rerank_min_score" class="form-label">Reranker floor</label>
                                            <input type="number" step="0.01" name="rerank_min_score" id="rerank_min_score"
                                                   class="form-control font-monospace" min="0" max="1"
                                                   value="{{ old('rerank_min_score', $bot->rerank_min_score) }}">
                                            <div class="form-text">Used instead of the relevance floor when a reranker is set in admin settings. A reranker scores how well a passage answers the question, from 0 to 1. Try values in the retrieval playground before raising this.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Databases --}}
                        <div class="tab-pane fade" id="pane-db" role="tabpanel">
                            <div class="p-3">
                                <div class="form-check form-switch d-flex align-items-center gap-2 mb-2">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           name="db_query_enabled" value="1" id="db_query_enabled"
                                           {{ old('db_query_enabled', $bot->db_query_enabled) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="db_query_enabled">Query the database</label>
                                </div>
                                <div class="form-text mb-3">
                                    Consulted in its turn from the order on the left. If no readable
                                    table can answer the question it is passed over, and if a query
                                    fails the next source gets its turn.
                                </div>

                                @if($dbConnections->isEmpty())
                                    <p class="text-muted mb-0" style="font-size: 0.8125rem;">
                                        This workspace has no database connections yet.
                                        <a href="{{ route('databases.index') }}">Add one</a>, then come back.
                                    </p>
                                @else
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <span class="text-muted" style="font-size: 0.75rem;">Connections this bot may read</span>
                                        <a href="{{ route('databases.index') }}" class="d-inline-flex align-items-center gap-1" style="font-size: 0.75rem;">
                                            Manage connections <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
                                    <div class="ws-list">
                                        @foreach($dbConnections as $connection)
                                            <div class="ws-row" style="grid-template-columns: auto minmax(0, 1fr) auto;">
                                                <input class="form-check-input mt-0" type="checkbox" name="db_connections[]"
                                                       value="{{ $connection->id }}" id="db_{{ $connection->id }}"
                                                       {{ in_array($connection->id, old('db_connections', $attachedDbs)) ? 'checked' : '' }}>
                                                <label for="db_{{ $connection->id }}" class="text-truncate mb-0" style="cursor: pointer;">
                                                    {{ $connection->name }}
                                                </label>
                                                <span class="text-end">
                                                    @if($connection->is_enabled)
                                                        <a href="{{ route('databases.schema', $connection->id) }}"
                                                           style="font-size: 0.75rem;">Schema</a>
                                                    @else
                                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">switched off</span>
                                                    @endif
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="mt-3 pt-3" style="border-top: 1px solid var(--border);">
                                    <div class="fw-semibold mb-2" style="font-size: 0.8125rem;">How the query runs</div>

                                    <div class="row g-3">
                                        <div class="col-6">
                                            <label for="db_max_rows" class="form-label">Rows returned at most</label>
                                            <input type="number" name="db_max_rows" id="db_max_rows" min="1" max="1000"
                                                   class="form-control font-monospace" value="{{ old('db_max_rows', $bot->db_max_rows) }}" required>
                                            <div class="form-text">Every query is capped at this, whatever it asks for.</div>
                                        </div>
                                        <div class="col-6">
                                            <label for="db_query_timeout" class="form-label">Query timeout, seconds</label>
                                            <input type="number" name="db_query_timeout" id="db_query_timeout" min="1" max="120"
                                                   class="form-control font-monospace" value="{{ old('db_query_timeout', $bot->db_query_timeout) }}" required>
                                            <div class="form-text">The visitor is waiting, so keep this short.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Web search</div>
                    <div class="p-3">
                        <div class="form-check form-switch d-flex align-items-center gap-2 mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="web_search_enabled" value="1"
                                   id="web_search_enabled" {{ old('web_search_enabled', $bot->web_search_enabled) ? 'checked' : '' }}>
                            <label class="form-check-label" for="web_search_enabled">Search the web</label>
                        </div>
                        <div class="form-text mb-3">
                            Consulted in the order set on the left. Where it sits after the knowledge
                            base, it runs only when your own documents had nothing.
                            The provider and its key are set in admin settings.
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <label for="web_search_max_results" class="form-label">Results used</label>
                                <input type="number" name="web_search_max_results" id="web_search_max_results"
                                       class="form-control font-monospace" min="1" max="10"
                                       value="{{ old('web_search_max_results', $bot->web_search_max_results) }}" required>
                                <div class="form-text">More results cost more and crowd the prompt.</div>
                            </div>
                            <div class="col-6">
                                <label for="web_search_country" class="form-label">Favour country</label>
                                <input type="text" name="web_search_country" id="web_search_country"
                                       class="form-control font-monospace text-uppercase" maxlength="2" placeholder="MY"
                                       value="{{ old('web_search_country', $bot->web_search_country) }}">
                                <div class="form-text">Two-letter code. Leave empty for no bias.</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        {{-- Same bar as the Profile tab so the two tabs read as one bot's
             settings; only the button names which half it saves. --}}
        @include('bots._save-bar', [
            'formId' => 'brainForm',
            'saveLabel' => 'Save Brain changes',
            'track' => true,
        ])
    </form>

</div>

{{-- The real bot, floating over this page the way it floats over a customer's
     site. It runs the last saved settings, so a prompt change has to be saved
     before the widget will show it. --}}
<script
    src="{{ $apiHost }}/widget.js"
    data-bot-id="{{ $bot->id }}"
    data-api-host="{{ $apiHost }}"
    defer>
</script>
@endsection

@push('scripts')
<script>
    // Opens the real widget this page embeds, from the button in the head.
    function openTestWidget() {
        var host = document.querySelector('chat-widget');
        var launcher = host && host.shadowRoot ? host.shadowRoot.getElementById('chat-launcher') : null;
        if (launcher) {
            launcher.click();
        } else {
            noticeDialog({
                title: 'The widget is not ready',
                message: 'The widget has not finished loading. Check that the streaming engine on port 8000 is running, then reload.',
            });
        }
    }

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

<script>
(function () {
    var list = document.getElementById('sourceOrder');
    var field = document.getElementById('source_order');
    if (!list || !field) { return; }

    function sync() {
        var rows = Array.prototype.slice.call(list.querySelectorAll('.source-order-row'));
        field.value = rows.map(function (row) { return row.dataset.token; }).join(',');
        rows.forEach(function (row, i) {
            row.querySelector('.source-order-rank').textContent = (i + 1) + '.';
            row.querySelector('[data-move="up"]').disabled = i === 0;
            row.querySelector('[data-move="down"]').disabled = i === rows.length - 1;
        });
    }

    list.addEventListener('click', function (event) {
        var button = event.target.closest('[data-move]');
        if (!button) { return; }

        var row = button.closest('.source-order-row');
        if (button.dataset.move === 'up' && row.previousElementSibling) {
            list.insertBefore(row, row.previousElementSibling);
        } else if (button.dataset.move === 'down' && row.nextElementSibling) {
            list.insertBefore(row.nextElementSibling, row);
        }
        sync();
    });

    sync();
})();
</script>

<script>
(function () {
    // A required field in a folded tab cannot be focused, and the browser
    // refuses to submit without saying why. Open the tab it is in first, so
    // the complaint lands somewhere the operator can see it.
    var form = document.getElementById('brainForm');
    if (!form) { return; }

    form.addEventListener('invalid', function (event) {
        var pane = event.target.closest('.tab-pane');
        if (!pane || pane.classList.contains('active')) { return; }

        var trigger = document.querySelector('[data-bs-target="#' + pane.id + '"]');
        if (trigger) { bootstrap.Tab.getOrCreateInstance(trigger).show(); }
    }, true);
})();
</script>
@endpush
