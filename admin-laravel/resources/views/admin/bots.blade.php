@extends('layouts.app')

@section('page-title', 'Bots')

@section('content')

@php
    $filtered = $workspace !== '' || $search !== '' || $status !== '';
@endphp

<div class="page-head page-head-wide mb-4">
    <div>
        <h1>Bots</h1>
        <p>Every bot in every workspace, deleted ones included. A bot deleted from its workspace waits here until it is restored or erased.</p>
    </div>
</div>

<div class="card mb-3">
    {{-- One GET form, so a filtered view lands in the address bar and can be
         bookmarked or shared. The selects apply as soon as they change. --}}
    <form method="GET" action="{{ route('admin.bots.index') }}" class="list-toolbar" role="search">
        <div class="list-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label for="bots_search" class="visually-hidden">Search bots</label>
            <input type="search" name="q" id="bots_search" class="form-control" value="{{ $search }}"
                   placeholder="Search by name, id or model" autocomplete="off">
        </div>

        <label for="bots_workspace" class="visually-hidden">Workspace</label>
        <select name="workspace" id="bots_workspace" class="form-select list-select" onchange="this.form.submit()">
            <option value="">All workspaces</option>
            @foreach($workspaces as $system)
                <option value="{{ $system->id }}" @selected($workspace === $system->id)>{{ $system->name }}</option>
            @endforeach
        </select>

        <label for="bots_status" class="visually-hidden">Status</label>
        <select name="status" id="bots_status" class="form-select list-select" onchange="this.form.submit()">
            <option value="">Any status</option>
            @foreach(\App\Http\Controllers\AdminBotController::STATUSES as $key => $label)
                <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
            @endforeach
        </select>

        <button type="submit" class="btn btn-outline-secondary">Search</button>
        @if($filtered)
            <a href="{{ route('admin.bots.index') }}" class="btn btn-link list-reset">Clear filters</a>
        @endif
        <span class="chip figure-mono ms-auto">{{ $bots->count() }} {{ $filtered ? 'found' : 'total' }}</span>
    </form>
</div>

@if($bots->isEmpty())
    <div class="card">
        <div class="empty">
            @if($filtered)
                <i class="bi bi-search"></i>
                <h6>No bots match</h6>
                <p>Nothing fits those filters. Try fewer words, another workspace or any status.</p>
            @else
                <i class="bi bi-robot"></i>
                <h6>No bots yet</h6>
                <p>Bots are created from a workspace's Bot profiles page.</p>
            @endif
        </div>
    </div>
@else
    <div class="row g-3">
        @foreach($bots as $bot)
            @php $deleted = $bot->trashed(); @endphp
            <div class="col-12 col-lg-6 col-xxl-4">
                <div class="card h-100 d-flex flex-column {{ $deleted ? 'bot-card-deleted' : '' }}">

                    <div class="p-3 d-flex align-items-start justify-content-between gap-2"
                         style="border-bottom: 1px solid var(--border);">
                        <div class="d-flex align-items-center gap-2.5 min-w-0">
                            @if($bot->bot_avatar_url)
                                <img src="{{ $bot->bot_avatar_url }}" alt="" class="identity identity-lg">
                            @else
                                <span class="identity identity-lg">{{ strtoupper(substr($bot->name, 0, 1)) }}</span>
                            @endif
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate">{{ $bot->name }}</div>
                                <div class="text-muted text-truncate" style="font-size: 0.75rem;">
                                    <i class="bi bi-diagram-3"></i> {{ $bot->system?->name ?? 'No workspace' }}
                                </div>
                                <div class="figure-mono text-muted text-truncate" style="font-size: 0.6875rem;">{{ $bot->id }}</div>
                            </div>
                        </div>

                        <div class="d-flex flex-column align-items-end gap-1 flex-shrink-0">
                            @if($deleted)
                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Deleted</span>
                                <span class="text-muted" style="font-size: 0.6875rem;"
                                      title="{{ $bot->deleted_at->toDayDateTimeString() }}">{{ $bot->deleted_at->diffForHumans() }}</span>
                            @elseif($bot->is_active)
                                <span class="badge bg-success-subtle text-success-emphasis">Active</span>
                            @else
                                <span class="badge bg-danger-subtle text-danger-emphasis">Deactivated</span>
                            @endif
                        </div>
                    </div>

                    @include('bots._card-details', ['bot' => $bot])

                    <div class="card-footer d-flex align-items-center gap-1.5 p-2"
                         style="border-radius: 0 0 var(--r-md) var(--r-md);">
                        @if($deleted)
                            <form action="{{ route('admin.bots.restore', $bot->id) }}" method="POST" class="flex-grow-1 m-0"
                                  data-confirm="Restore this bot?" data-confirm-tone="primary"
                                  data-confirm-subject="{{ $bot->name }}"
                                  data-confirm-detail="{{ $bot->system?->name }}"
                                  data-confirm-message="It goes back to its workspace as it was, and answers again if it was active when deleted."
                                  data-confirm-label="Restore bot">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                                    <i class="bi bi-arrow-counterclockwise"></i> Restore
                                </button>
                            </form>
                            <form action="{{ route('admin.bots.purge', $bot->id) }}" method="POST" class="m-0"
                                  data-confirm="Erase this bot for good?"
                                  data-confirm-subject="{{ $bot->name }}"
                                  data-confirm-detail="{{ $bot->system?->name }}"
                                  data-confirm-message="The bot and every conversation it had are erased. This cannot be undone."
                                  data-confirm-type="{{ $bot->name }}"
                                  data-confirm-label="Delete permanently">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete permanently">
                                    <i class="bi bi-trash3"></i> Delete permanently
                                </button>
                            </form>
                        @else
                            <a href="{{ route('bots.edit', $bot->id) }}" class="btn btn-sm btn-outline-secondary flex-grow-1">
                                <i class="bi bi-gear"></i> Settings
                            </a>
                            <form action="{{ route('admin.bots.destroy', $bot->id) }}" method="POST" class="m-0"
                                  data-confirm="Delete this bot?"
                                  data-confirm-subject="{{ $bot->name }}"
                                  data-confirm-detail="{{ $bot->system?->name }}"
                                  data-confirm-message="It stops answering on every site and leaves its workspace. Its conversations are kept, and it can be restored from this page."
                                  data-confirm-type="{{ $bot->name }}"
                                  data-confirm-label="Delete bot">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete bot" aria-label="Delete bot">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        @endif
                    </div>

                </div>
            </div>
        @endforeach
    </div>
@endif

@endsection
