@extends('layouts.app')

@section('page-title', 'Retrieval playground')

@section('content')
<div style="max-width: 1100px;">

    <div class="page-head mb-4">
        <div>
            <a href="{{ route('kb.index') }}" class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
                <i class="bi bi-arrow-left"></i> Knowledge base
            </a>
            <h1>Retrieval playground</h1>
            <p>Ask what a bot would ask and see exactly which passages come back. When an answer is wrong, this tells you whether the right material was found and ignored, or never found at all.</p>
        </div>
    </div>

    <form action="{{ route('kb.playground.run') }}" method="POST">
        @csrf

        <div class="card mb-3">
            <div class="p-3">
                <label for="query" class="form-label">Question</label>
                <div class="input-group">
                    <input type="text" name="query" id="query" class="form-control"
                           value="{{ old('query', $query) }}"
                           placeholder="How long do refunds take?" required>
                    <button type="submit" class="btn btn-brand">
                        <i class="bi bi-search"></i> Search
                    </button>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-12 col-lg-5">
                <div class="card h-100">
                    <div class="card-header">Collections searched</div>
                    <div class="p-3">
                        @if($collections->isEmpty())
                            <p class="text-muted mb-0" style="font-size: 0.8125rem;">
                                No collections in this workspace yet.
                            </p>
                        @else
                            <div class="ws-list">
                                @foreach($collections as $collection)
                                    <div class="ws-row" style="grid-template-columns: auto minmax(0, 1fr) 110px;">
                                        <input class="form-check-input mt-0" type="checkbox" name="collections[]"
                                               value="{{ $collection->id }}" id="pg_{{ $collection->id }}"
                                               {{ in_array($collection->id, $selected) || empty($selected) ? 'checked' : '' }}>
                                        <label for="pg_{{ $collection->id }}" class="text-truncate mb-0" style="cursor: pointer;">
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
            </div>

            <div class="col-12 col-lg-7">
                <div class="card h-100">
                    <div class="card-header">Settings to try</div>
                    <div class="p-3">
                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label for="mode" class="form-label">Search mode</label>
                                <select name="mode" id="mode" class="form-select">
                                    <option value="hybrid" {{ $settings['mode'] === 'hybrid' ? 'selected' : '' }}>Hybrid</option>
                                    <option value="vector" {{ $settings['mode'] === 'vector' ? 'selected' : '' }}>Meaning only</option>
                                    <option value="keyword" {{ $settings['mode'] === 'keyword' ? 'selected' : '' }}>Keywords only</option>
                                </select>
                            </div>
                            <div class="col-6 col-sm-3">
                                <label for="top_k" class="form-label">Passages</label>
                                <input type="number" name="top_k" id="top_k" class="form-control font-monospace"
                                       min="1" max="20" value="{{ $settings['top_k'] }}" required>
                            </div>
                            <div class="col-6 col-sm-3">
                                <label for="candidates" class="form-label">Candidates</label>
                                <input type="number" name="candidates" id="candidates" class="form-control font-monospace"
                                       min="5" max="100" value="{{ $settings['candidates'] }}" required>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="min_score" class="form-label">Relevance floor</label>
                                <input type="number" step="0.001" name="min_score" id="min_score"
                                       class="form-control font-monospace" min="0" max="1"
                                       value="{{ $settings['min_score'] }}" required>
                                <div class="form-text">A top hit scores about 0.016, or 0.033 when both branches agree.</div>
                            </div>
                        </div>
                        <div class="form-text mt-2">
                            These are not saved. Once a combination works, set it on the bot's Brain page.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    @if($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    @if($results !== null && !$error)
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <span>Results</span>
                <span class="chip figure-mono">{{ count($results) }} passages</span>
            </div>

            @if(empty($results))
                <div class="empty">
                    <i class="bi bi-search"></i>
                    <h6>Nothing matched</h6>
                    <p>No passage cleared the relevance floor. Lower it, widen the candidates, or add material that answers this question.</p>
                </div>
            @else
                <div>
                    @foreach($results as $i => $result)
                        <div class="p-3" style="{{ !$loop->last ? 'border-bottom: 1px solid var(--border);' : '' }}">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-1.5">
                                <span class="fw-semibold" style="font-size: 0.8125rem;">
                                    [{{ $i + 1 }}] {{ $titles[$result['source_id']] ?? 'Untitled' }}
                                </span>
                                <span class="chip figure-mono">score {{ number_format($result['score'], 4) }}</span>
                            </div>
                            <p class="text-muted mb-0" style="font-size: 0.78125rem; line-height: 1.6;">
                                {{ $result['content'] }}
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

</div>
@endsection
