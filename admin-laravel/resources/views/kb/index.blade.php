@extends('layouts.app')

@section('page-title', 'Knowledge base')

@section('content')

<div class="page-head mb-4">
    <div>
        <h1>Knowledge base</h1>
        <p>Content the bots in {{ $activeSystem->name }} can answer from. A collection is a group of related material; each bot chooses which collections it reads.</p>
    </div>

    @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('kb.playground') }}" class="btn btn-outline-secondary">
                <i class="bi bi-search"></i> Playground
            </a>
            <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newCollectionModal">
                <i class="bi bi-plus-lg"></i> New collection
            </button>
        </div>
    @endif
</div>

<div class="card">
    @if($collections->isEmpty())
        <div class="empty">
            <i class="bi bi-journal-text"></i>
            <h6>No collections yet</h6>
            <p>Create one, add your policies or product notes, and point a bot at it.</p>
            @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#newCollectionModal">New collection</button>
            @endif
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th style="min-width: 260px;">Collection</th>
                        <th style="width: 100px;">Sources</th>
                        <th class="text-end" style="width: 160px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($collections as $collection)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $collection->name }}</div>
                                <div class="text-muted" style="font-size: 0.75rem;">
                                    {{ $collection->description ?: 'No description.' }}
                                </div>
                            </td>
                            <td><span class="figure-mono">{{ $collection->sources_count }}</span></td>
                            <td class="text-end">
                                <div class="d-flex align-items-center justify-content-end gap-1.5">
                                    <a href="{{ route('kb.show', $collection->id) }}" class="btn btn-sm btn-outline-primary">Open</a>
                                    @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                                        <form action="{{ route('kb.destroy', $collection->id) }}" method="POST"
                                              onsubmit="return confirm('Delete {{ $collection->name }} and everything in it?');"
                                              class="d-inline m-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete collection">
                                                <i class="bi bi-trash3"></i>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

@if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
    <div class="modal fade" id="newCollectionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">New collection</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="{{ route('kb.store') }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="kbc_name" class="form-label">Name <span style="color: var(--danger);">*</span></label>
                            <input type="text" id="kbc_name" name="name" class="form-control"
                                   placeholder="Refund policy, Product notes" required>
                        </div>
                        <div class="mb-0">
                            <label for="kbc_desc" class="form-label">Description</label>
                            <textarea id="kbc_desc" name="description" class="form-control" rows="2"
                                      placeholder="What this collection covers."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand">Create collection</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

@endsection
