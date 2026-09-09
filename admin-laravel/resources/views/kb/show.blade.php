@extends('layouts.app')

@section('page-title', 'Knowledge base')

@section('content')
@php $canEdit = auth()->user()->canManageSystem($collection->system_id, 'editor'); @endphp

<div class="page-head mb-4">
    <div>
        <a href="{{ route('kb.index') }}" class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
            <i class="bi bi-arrow-left"></i> Knowledge base
        </a>
        <h1>{{ $collection->name }}</h1>
        <p>{{ $collection->description ?: 'Everything a bot reading this collection can draw on.' }}</p>
    </div>

    @if($canEdit)
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addQaModal">
                <i class="bi bi-patch-question"></i> Add Q and A
            </button>
            <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#addTextModal">
                <i class="bi bi-plus-lg"></i> Add text
            </button>
        </div>
    @endif
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between gap-2">
        <span>Sources</span>
        <span class="chip figure-mono">{{ $collection->sources->count() }} total</span>
    </div>

    @if($collection->sources->isEmpty())
        <div class="empty">
            <i class="bi bi-file-text"></i>
            <h6>Nothing in this collection yet</h6>
            <p>Paste a policy, or add a question with the answer you want given.</p>
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 280px;">Source</th>
                        <th style="width: 90px;">Type</th>
                        <th style="width: 130px;">Status</th>
                        <th style="width: 90px;">Chunks</th>
                        <th class="text-end" style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($collection->sources as $source)
                        <tr>
                            <td>
                                <div class="fw-semibold text-truncate" style="max-width: 420px;">{{ $source->title }}</div>
                                @if($source->status === 'error')
                                    <div style="color: var(--danger); font-size: 0.75rem;">{{ $source->error_message }}</div>
                                @else
                                    <div class="text-muted text-truncate" style="max-width: 420px; font-size: 0.75rem;">
                                        {{ \Illuminate\Support\Str::limit($source->body, 90) }}
                                    </div>
                                @endif
                            </td>
                            <td><span class="chip">{{ $source->type === 'qa' ? 'Q and A' : 'Text' }}</span></td>
                            <td>
                                @if($source->status === 'ready')
                                    <span class="d-inline-flex align-items-center gap-1.5" style="color: var(--ok);">
                                        <span class="state-dot is-live"></span> Indexed
                                    </span>
                                @elseif($source->status === 'error')
                                    <span style="color: var(--danger);">Failed</span>
                                @else
                                    <span class="text-muted">{{ ucfirst($source->status) }}</span>
                                @endif
                            </td>
                            <td><span class="figure-mono">{{ $source->chunk_count }}</span></td>
                            <td class="text-end">
                                @if($canEdit)
                                    <div class="d-flex align-items-center justify-content-end gap-1.5">
                                        <form action="{{ route('kb.sources.reindex', $source->id) }}" method="POST" class="d-inline m-0">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Index again">
                                                <i class="bi bi-arrow-repeat"></i>
                                            </button>
                                        </form>
                                        <form action="{{ route('kb.sources.destroy', $source->id) }}" method="POST"
                                              onsubmit="return confirm('Remove this source?');" class="d-inline m-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if($canEdit)
    <div class="modal fade" id="addTextModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">Add text</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('kb.sources.store', $collection->id) }}" method="POST">
                    @csrf
                    <input type="hidden" name="type" value="text">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="text_title" class="form-label">Title <span style="color: var(--danger);">*</span></label>
                            <input type="text" id="text_title" name="title" class="form-control"
                                   placeholder="Refund policy" required>
                            <div class="form-text">Shown to the bot as the source name when it cites this.</div>
                        </div>
                        <div class="mb-0">
                            <label for="text_body" class="form-label">Content <span style="color: var(--danger);">*</span></label>
                            <textarea id="text_body" name="body" class="form-control" rows="10" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Add and index</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addQaModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h6 class="modal-title mb-0">Add a question and answer</h6>
                        <span class="text-muted" style="font-size: 0.75rem;">Kept whole, so the answer is returned exactly as written.</span>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('kb.sources.store', $collection->id) }}" method="POST">
                    @csrf
                    <input type="hidden" name="type" value="qa">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="qa_title" class="form-label">Question <span style="color: var(--danger);">*</span></label>
                            <input type="text" id="qa_title" name="title" class="form-control"
                                   placeholder="How long do refunds take?" required>
                        </div>
                        <div class="mb-0">
                            <label for="qa_body" class="form-label">Answer <span style="color: var(--danger);">*</span></label>
                            <textarea id="qa_body" name="body" class="form-control" rows="6" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Add and index</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

@endsection
