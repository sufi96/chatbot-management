@extends('layouts.app')

@section('page-title', 'Behaviour')

@section('content')
<div style="max-width: 1200px;">

    <div class="page-head mb-3">
        <div>
            <a href="{{ route('bots.edit', $bot->id) }}" class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
                <i class="bi bi-arrow-left"></i> {{ $bot->name }}
            </a>
            <h1>Behaviour</h1>
            <p>What this bot knows and how it decides what to say.</p>
        </div>
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
                    <div class="card-header">Generation</div>
                    <div class="p-3">
                        {{-- Thinking first: it changes speed and length more than any
                             sampling setting below. One short line per level. One-line
                             php directives, since Blade misreads a block form in a file
                             that already uses the one-line form. --}}
                        @php($thinking = old('thinking_level', $bot->thinking_level ?: 'off'))
                        @php($thinkingHints = ['off' => 'Answers straight away. Fastest.', 'low' => 'Thinks briefly first.', 'medium' => 'Thinks through harder questions.', 'high' => 'Thinks longest. Slowest, most careful.'])
                        <div class="mb-3">
                            <div class="form-label" id="thinkingLabel">Thinking level</div>
                            <div class="segmented" role="radiogroup" aria-labelledby="thinkingLabel">
                                    <input type="radio" class="visually-hidden" name="thinking_level" id="thinking_off" value="off"
                                           data-hint="Answers straight away. Fastest." @checked($thinking === 'off')>
                                    <label for="thinking_off" class="segmented-opt"><i class="bi bi-lightning-charge"></i> Off</label>
                                    <input type="radio" class="visually-hidden" name="thinking_level" id="thinking_low" value="low"
                                           data-hint="Thinks briefly first." @checked($thinking === 'low')>
                                    <label for="thinking_low" class="segmented-opt"><i class="bi bi-lightbulb"></i> Low</label>
                                    <input type="radio" class="visually-hidden" name="thinking_level" id="thinking_medium" value="medium"
                                           data-hint="Thinks through harder questions." @checked($thinking === 'medium')>
                                    <label for="thinking_medium" class="segmented-opt"><i class="bi bi-lightbulb-fill"></i> Medium</label>
                                    <input type="radio" class="visually-hidden" name="thinking_level" id="thinking_high" value="high"
                                           data-hint="Thinks longest. Slowest, most careful." @checked($thinking === 'high')>
                                    <label for="thinking_high" class="segmented-opt"><i class="bi bi-stars"></i> High</label>
                            </div>
                            <div class="form-text" id="thinkingHint">{{ $thinkingHints[$thinking] ?? $thinkingHints['off'] }}</div>
                            <div class="form-text text-faint">Thinking shows folded above each answer. Some providers treat Low, Medium and High alike.</div>
                        </div>

                        <div class="row g-3 pt-1 mt-2" style="border-top: 1px solid var(--border);">
                            <div class="col-12 col-sm-6">
                                {{-- 0 to 1, the range where a change is felt. A value saved
                                     above 1 before the cap shows at 1 and saves as 1. --}}
                                @include('bots._slider', [
                                    'name' => 'temperature', 'label' => 'Temperature',
                                    'min' => 0, 'max' => 1, 'step' => 0.05,
                                    'value' => old('temperature', $bot->temperature ?? 0.7),
                                    'ends' => ['Predictable', 'Creative'],
                                ])
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="max_tokens" class="form-label slider-label"><span>Max tokens</span></label>
                                <input type="number" step="64" min="64" max="8192" name="max_tokens" id="max_tokens"
                                       class="form-control form-control-sm font-monospace" style="max-width: 140px;"
                                       value="{{ old('max_tokens', $bot->max_tokens ?? 1024) }}">
                                <div class="form-text">Ceiling on one reply.</div>
                            </div>
                            <div class="col-12 col-sm-6">
                                @include('bots._slider', [
                                    'name' => 'top_p', 'label' => 'Top p',
                                    'min' => 0, 'max' => 1, 'step' => 0.05,
                                    'value' => old('top_p', $bot->top_p ?? 1),
                                    'ends' => ['Focused', 'Varied'],
                                ])
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="top_k_sampling" class="form-label slider-label">
                                    <span>Top k</span>
                                    <span class="text-faint" style="font-size: 0.75rem;">optional</span>
                                </label>
                                <input type="number" min="1" max="200" name="top_k_sampling" id="top_k_sampling"
                                       class="form-control form-control-sm font-monospace" style="max-width: 140px;"
                                       value="{{ old('top_k_sampling', $bot->top_k_sampling) }}" placeholder="Auto">
                                <div class="form-text">Blank leaves it to the model.</div>
                            </div>
                            <div class="col-12 col-sm-6">
                                @include('bots._slider', [
                                    'name' => 'presence_penalty', 'label' => 'Presence penalty',
                                    'min' => -2, 'max' => 2, 'step' => 0.1, 'decimals' => 1,
                                    'value' => old('presence_penalty', $bot->presence_penalty ?? 0),
                                    'ends' => ['Stay on topic', 'New topics'],
                                ])
                            </div>
                            <div class="col-12 col-sm-6">
                                @include('bots._slider', [
                                    'name' => 'frequency_penalty', 'label' => 'Frequency penalty',
                                    'min' => -2, 'max' => 2, 'step' => 0.1, 'decimals' => 1,
                                    'value' => old('frequency_penalty', $bot->frequency_penalty ?? 0),
                                    'ends' => ['Allow repeats', 'Avoid repeats'],
                                ])
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Answer sources</div>
                    <div class="p-3">
                        @php($combine = (bool) old('combine_sources', $bot->combine_sources))
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="radio" name="combine_sources" value="1"
                                   id="combine_sources_on" {{ $combine ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="combine_sources_on">Combined</label>
                            <div class="form-text mt-0">
                                The knowledge base and database are asked together and the answer draws on both,
                                so "is my order still inside the return window?" gets the policy and the order.
                                Web search is asked only when both have nothing. Every question runs a database
                                query, and each source gets half the context budget.
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="radio" name="combine_sources" value="0"
                                   id="combine_sources_off" {{ $combine ? '' : 'checked' }}>
                            <label class="form-check-label fw-semibold" for="combine_sources_off">Source order</label>
                            <div class="form-text mt-0">
                                Each question goes to the sources below in turn, and the first one with something
                                to say answers it. Cheaper and faster, but a question needing two sources gets one.
                            </div>
                        </div>

                        <p class="text-muted small mb-2" id="sourceOrderNote">
                            A source that is switched off is passed over.
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
                    <div class="card-header">Web search</div>
                    <div class="p-3">
                        <div class="form-check form-switch d-flex align-items-center gap-2 mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="web_search_enabled" value="1"
                                   id="web_search_enabled" {{ old('web_search_enabled', $bot->web_search_enabled) ? 'checked' : '' }}>
                            <label class="form-check-label" for="web_search_enabled">Search the web</label>
                        </div>
                        <div class="form-text mb-3">
                            Asked only when the knowledge base and database both had nothing when
                            combined, or at its place in the source order otherwise.
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

            </div>

            {{-- ============ Where its answers come from ============ --}}
            <div class="col-12 col-xl-6">

                @include('bots._model-endpoint')

                {{-- Documents and databases are the two stores this bot reads,
                     so they are one card with a tab each rather than two boxes
                     sitting apart. Web search has no store to pick from and
                     keeps its own card below. --}}
                <div class="card mb-3">
                    <div class="card-header p-0">
                        <div class="px-3 pt-2 d-flex align-items-center gap-1.5"><i class="bi bi-diagram-2"></i> Brain</div>
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
                                            @include('bots._slider', [
                                                'name' => 'retrieval_top_k', 'label' => 'Passages used',
                                                'min' => 1, 'max' => 20, 'step' => 1, 'decimals' => 0,
                                                'value' => old('retrieval_top_k', $bot->retrieval_top_k),
                                                'hint' => 'More context, slower answers.',
                                            ])
                                        </div>
                                        <div class="col-6">
                                            @include('bots._slider', [
                                                'name' => 'retrieval_candidates', 'label' => 'Candidates per branch',
                                                'min' => 5, 'max' => 100, 'step' => 5, 'decimals' => 0,
                                                'value' => old('retrieval_candidates', $bot->retrieval_candidates),
                                                'hint' => 'How deep each search looks.',
                                            ])
                                        </div>

                                        {{-- The floors. Short hints here; the playground shows real
                                             scores to tune against. Relevance scores sit near 0.016
                                             for an unrelated hit and 0.033 for a good one, so its
                                             track stops at 0.1 unless a saved value is higher. --}}
                                        <div class="col-12">
                                            @php($relevance = (float) old('retrieval_min_score', $bot->retrieval_min_score))
                                            @include('bots._slider', [
                                                'name' => 'retrieval_min_score', 'label' => 'Relevance floor',
                                                'min' => 0, 'max' => max(0.1, $relevance), 'step' => 0.001, 'decimals' => 3,
                                                'value' => $relevance,
                                                'ends' => ['Keeps more', 'Stricter'],
                                                'hint' => 'Drops weak passages. Keep it above 0.017; 0.020 suits most bots.',
                                            ])
                                        </div>
                                        <div class="col-12 col-md-6">
                                            @include('bots._slider', [
                                                'name' => 'retrieval_min_similarity', 'label' => 'Similarity floor',
                                                'min' => 0, 'max' => 1, 'step' => 0.01, 'offAt' => 0,
                                                'value' => old('retrieval_min_similarity', $bot->retrieval_min_similarity),
                                                'hint' => 'Below this, ask the next source. 0.65 suits nomic-embed-text.',
                                            ])
                                        </div>
                                        <div class="col-12 col-md-6">
                                            @include('bots._slider', [
                                                'name' => 'rerank_min_score', 'label' => 'Reranker floor',
                                                'min' => 0, 'max' => 1, 'step' => 0.01,
                                                'value' => old('rerank_min_score', $bot->rerank_min_score),
                                                'hint' => 'Replaces the floors above when a reranker is set.',
                                            ])
                                        </div>
                                        <div class="col-12">
                                            <div class="form-text">
                                                <i class="bi bi-lightbulb"></i>
                                                Not sure? Try values in the <a href="{{ route('kb.playground') }}">retrieval playground</a> first.
                                            </div>
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

            </div>
        </div>

        {{-- Same bar as the Profile tab so the two tabs read as one bot's
             settings; only the button names which half it saves. --}}
        @include('bots._save-bar', [
            'formId' => 'brainForm',
            'saveLabel' => 'Save behaviour changes',
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
    // Thinking level: one line that follows the chosen level.
    document.querySelectorAll('input[name="thinking_level"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.getElementById('thinkingHint').textContent = radio.dataset.hint;
        });
    });

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

    // The order only decides who answers in Source order mode, so it is shown
    // only there. Still submitted either way, so switching back keeps it.
    var note = document.getElementById('sourceOrderNote');
    function showOrder() {
        var ordered = document.getElementById('combine_sources_off').checked;
        list.hidden = !ordered;
        note.hidden = !ordered;
    }
    document.querySelectorAll('input[name="combine_sources"]').forEach(function (radio) {
        radio.addEventListener('change', showOrder);
    });
    showOrder();
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
