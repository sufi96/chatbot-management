@extends('layouts.app')

@section('page-title', 'Bot profiles')

@section('content')

<div class="page-head mb-4">
    <div>
        <h1>Bot profiles</h1>
        <p>Each profile carries its own model, endpoint, system prompt and widget styling. Workspace: {{ $activeSystem->name }}.</p>
    </div>

    @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
        <a href="{{ route('bots.create') }}" class="btn btn-brand">
            <i class="bi bi-plus-lg"></i> New bot profile
        </a>
    @endif
</div>

@if($bots->isEmpty())
    <div class="card">
        <div class="empty">
            <i class="bi bi-cpu"></i>
            <h6>No bot profiles in this workspace</h6>
            <p>Create a profile to connect Ollama or an OpenAI-compatible endpoint, then copy the embed snippet onto any site.</p>
            @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                <a href="{{ route('bots.create') }}" class="btn btn-brand">New bot profile</a>
            @endif
        </div>
    </div>
@else
    <div class="row g-3">
        @foreach($bots as $bot)
            <div class="col-12 col-lg-6 col-xxl-4">
                <div class="card h-100 d-flex flex-column">

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
                                <div class="figure-mono text-muted text-truncate" style="font-size: 0.6875rem;">{{ $bot->id }}</div>
                            </div>
                        </div>

                        @if($bot->is_active)
                            <span class="d-inline-flex align-items-center gap-1.5 flex-shrink-0"
                                  style="color: var(--ok); font-size: 0.78125rem;">
                                <span class="state-dot is-live"></span> Active
                            </span>
                        @else
                            <span class="d-inline-flex align-items-center gap-1.5 flex-shrink-0 text-muted"
                                  style="font-size: 0.78125rem;">
                                <span class="state-dot is-off"></span> Paused
                            </span>
                        @endif
                    </div>

                    @include('bots._card-details', ['bot' => $bot])

                    <div class="card-footer d-flex align-items-center gap-1.5 p-2"
                         style="border-radius: 0 0 var(--r-md) var(--r-md);">
                        <button type="button" class="btn btn-sm btn-outline-primary flex-grow-1"
                                data-bs-toggle="modal" data-bs-target="#embedModal{{ $bot->id }}">
                            <i class="bi bi-code-slash"></i> Embed code
                        </button>

                        @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                            {{-- Deleting lives at the foot of this settings page, behind
                                 typing the bot's name, not one click from the list. --}}
                            <a href="{{ route('bots.edit', $bot->id) }}" class="btn btn-sm btn-outline-secondary"
                               title="Bot settings" aria-label="Bot settings">
                                <i class="bi bi-gear"></i>
                            </a>
                        @endif
                    </div>

                </div>
            </div>
        @endforeach
    </div>

    {{-- Modals live outside the grid so no card can clip them. --}}
    @foreach($bots as $bot)
        @include('bots._embed-modal', ['bot' => $bot, 'apiHost' => $apiHost])
    @endforeach
@endif

@endsection

