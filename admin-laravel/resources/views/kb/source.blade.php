@extends('layouts.app')

@section('page-title', 'Knowledge base')

@section('content')
<div style="max-width: 1100px;">

    <div class="page-head mb-4">
        <div class="min-w-0">
            <a href="{{ route('kb.show', $source->collection_id) }}"
               class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
                <i class="bi bi-arrow-left"></i> {{ $source->collection->name }}
            </a>
            <h1 class="truncate-1">{{ $source->title }}</h1>
            <p>
                <span class="chip">
                    @if($source->type === 'qa') Q and A
                    @elseif($source->type === 'file') File
                    @else Text @endif
                </span>
                <span class="chip figure-mono">{{ $source->chunk_count }} passages</span>
                @if($source->status === 'ready')
                    <span class="chip" style="color: var(--ok);">Indexed</span>
                @elseif($source->status === 'error')
                    <span class="chip" style="color: var(--danger);">Failed</span>
                @else
                    <span class="chip">{{ ucfirst($source->status) }}</span>
                @endif
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('kb.sources.download', $source->id) }}" class="btn btn-outline-secondary">
                <i class="bi bi-download"></i> Download
            </a>
            @if($canEdit)
                <form action="{{ route('kb.sources.reindex', $source->id) }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-repeat"></i> Re-index
                    </button>
                </form>
            @endif
        </div>
    </div>

    @if($source->status === 'error' && $source->error_message)
        <div class="alert alert-danger">{{ $source->error_message }}</div>
    @endif

    <form action="{{ route('kb.sources.update', $source->id) }}" method="POST" class="mb-4">
        @csrf
        @method('PUT')
        <div class="card">
            <div class="card-header">Source</div>
            <div class="p-3">
                <div class="mb-3">
                    <label for="title" class="form-label">
                        {{ $source->type === 'qa' ? 'Question' : 'Title' }}
                    </label>
                    <input type="text" name="title" id="title" class="form-control"
                           maxlength="500" value="{{ old('title', $source->title) }}"
                           {{ $canEdit ? '' : 'disabled' }} required>
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">What is this about?</label>
                    <input type="text" name="description" id="description" class="form-control"
                           maxlength="1000" value="{{ old('description', $source->description) }}"
                           placeholder="Returns, warranty and shipping terms for retail customers"
                           {{ $canEdit ? '' : 'disabled' }}>
                    <div class="form-text">One line. It travels with every passage below.</div>
                </div>

                @if($source->type === 'file')
                    <div class="metrics">
                        <div><span class="text-muted">File</span><div class="figure-mono">{{ $source->title }}</div></div>
                        <div><span class="text-muted">Type</span><div class="figure-mono">{{ $source->file_mime }}</div></div>
                        <div><span class="text-muted">Size</span><div class="figure-mono">{{ number_format(($source->file_size ?? 0) / 1024) }} KB</div></div>
                    </div>
                    <div class="form-text mt-2">
                        The text comes from the file itself. Download it, change it, and upload it again to replace the wording.
                    </div>
                @else
                    <div class="mb-0">
                        <label for="body" class="form-label">
                            {{ $source->type === 'qa' ? 'Answer' : 'Content' }}
                        </label>
                        <textarea name="body" id="body" class="form-control" rows="14"
                                  {{ $canEdit ? '' : 'disabled' }} required>{{ old('body', $source->body) }}</textarea>
                    </div>
                @endif
            </div>
        </div>

        @if($canEdit)
            <div class="form-actions">
                <span class="text-muted d-none d-sm-inline" style="font-size: 0.75rem;">Saving re-indexes this source.</span>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <button type="submit" class="btn btn-brand">Save and re-index</button>
                </div>
            </div>
        @endif
    </form>

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <span>Passages</span>
            <span class="chip figure-mono">{{ count($chunks) }}</span>
        </div>

        @if(count($chunks) === 0)
            <div class="empty">
                <i class="bi bi-layers"></i>
                <h6>Nothing indexed yet</h6>
                <p>This source has produced no passages. If its status is failed, the message above says why.</p>
            </div>
        @else
            <div>
                @foreach($chunks as $chunk)
                    <div class="p-3" style="{{ !$loop->last ? 'border-bottom: 1px solid var(--border);' : '' }}">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-1.5">
                            <span class="fw-semibold figure-mono" style="font-size: 0.8125rem;">#{{ $chunk->ordinal }}</span>
                            <span class="chip figure-mono">{{ $chunk->char_count }} chars</span>
                        </div>
                        @if(!empty($chunk->heading_path))
                            <div class="mb-1"><span class="chip">{{ $chunk->heading_path }}</span></div>
                        @endif
                        <pre class="text-muted mb-0" style="font-size: 0.78125rem; line-height: 1.6; white-space: pre-wrap; word-break: break-word;">{{ $chunk->content }}</pre>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</div>
@endsection
