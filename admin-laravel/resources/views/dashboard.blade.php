@extends('layouts.app')

@section('page-title', 'Dashboard')

@section('content')

@if(!$hasSystems)
    <div class="card">
        <div class="empty">
            <i class="bi bi-diagram-3"></i>
            <h6>No workspace assigned</h6>
            <p>A workspace holds your bot profiles and the people who can edit them. You need one before you can create a chatbot.</p>
            @if(auth()->user()->isSuperAdmin())
                <a href="{{ route('systems.index') }}" class="btn btn-brand">Create a workspace</a>
            @endif
        </div>
    </div>
@else

<div class="page-head mb-4">
    <div>
        <h1>{{ $activeSystem->name }}</h1>
        <p>{{ $activeSystem->description ?: 'Bot profiles, models and embed codes for this workspace.' }}</p>
    </div>

    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('logs.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-chat-left-text"></i> Conversations
        </a>
        @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
            <a href="{{ route('bots.create') }}" class="btn btn-brand">
                <i class="bi bi-plus-lg"></i> New bot profile
            </a>
        @endif
    </div>
</div>

<div class="metrics mb-4">
    <div class="metric">
        <span class="metric-label">Bot profiles</span>
        <span class="metric-figure">{{ number_format($botCount) }}</span>
        <div class="metric-note">In this workspace</div>
    </div>
    <div class="metric">
        <span class="metric-label">Accepting chats</span>
        <span class="metric-figure">
            {{ number_format($activeBotCount) }}
            @if($activeBotCount > 0)<span class="state-dot is-live"></span>@endif
        </span>
        <div class="metric-note">Marked active</div>
    </div>
    <div class="metric">
        <span class="metric-label">Conversations</span>
        <span class="metric-figure">{{ number_format($conversationCount) }}</span>
        <div class="metric-note">Distinct sessions</div>
    </div>
    <div class="metric">
        <span class="metric-label">Messages</span>
        <span class="metric-figure">{{ number_format($messageCount) }}</span>
        <div class="metric-note">Turns recorded</div>
    </div>
</div>

<div class="row g-3">

    <div class="col-12 col-xl-8">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <span>Bot profiles</span>
                <a href="{{ route('bots.index') }}" class="btn btn-sm btn-outline-secondary">
                    View all <i class="bi bi-arrow-right"></i>
                </a>
            </div>

            @if($recentBots->isEmpty())
                <div class="empty">
                    <i class="bi bi-cpu"></i>
                    <h6>No bot profiles yet</h6>
                    <p>A profile holds the model, the system prompt and the widget styling. Create one to get an embed snippet.</p>
                    @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                        <a href="{{ route('bots.create') }}" class="btn btn-brand">New bot profile</a>
                    @endif
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="min-width: 220px;">Profile</th>
                                <th style="min-width: 190px;">Model</th>
                                <th style="width: 100px;">Status</th>
                                <th class="text-end" style="width: 150px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentBots as $bot)
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2.5">
                                            @if($bot->bot_avatar_url)
                                                <img src="{{ $bot->bot_avatar_url }}" alt="" class="identity">
                                            @else
                                                <span class="identity">{{ strtoupper(substr($bot->name, 0, 1)) }}</span>
                                            @endif
                                            <div class="min-w-0">
                                                <div class="fw-semibold text-truncate">{{ $bot->name }}</div>
                                                <div class="figure-mono text-muted" style="font-size: 0.6875rem;">{{ $bot->id }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1.5 mb-0.5">
                                            <span class="chip">{{ $bot->provider_type === 'ollama' ? 'Ollama' : 'Custom API' }}</span>
                                            <span class="figure-mono">{{ $bot->model_name }}</span>
                                        </div>
                                        <div class="figure-mono text-muted text-truncate" style="font-size: 0.6875rem; max-width: 240px;">{{ $bot->base_url }}</div>
                                    </td>
                                    <td>
                                        @if($bot->is_active)
                                            <span class="d-inline-flex align-items-center gap-1.5" style="color: var(--ok);">
                                                <span class="state-dot is-live"></span> Active
                                            </span>
                                        @else
                                            <span class="d-inline-flex align-items-center gap-1.5 text-muted">
                                                <span class="state-dot is-off"></span> Paused
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex align-items-center justify-content-end gap-1.5">
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                    data-bs-toggle="modal" data-bs-target="#embedModal{{ $bot->id }}">
                                                <i class="bi bi-code-slash"></i> Embed
                                            </button>
                                            @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                                                <a href="{{ route('bots.edit', $bot->id) }}" class="btn btn-sm btn-outline-secondary" title="Edit profile">
                                                    <i class="bi bi-sliders"></i>
                                                </a>
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
    </div>

    <div class="col-12 col-xl-4">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center justify-content-between gap-2">
                <span>Latest conversations</span>
                <a href="{{ route('logs.index') }}" class="btn btn-sm btn-outline-secondary">
                    View all <i class="bi bi-arrow-right"></i>
                </a>
            </div>

            @if($recentConversations->isEmpty())
                <div class="empty">
                    <i class="bi bi-chat-left-text"></i>
                    <h6>Nothing recorded yet</h6>
                    <p>Sessions from embedded widgets and the preview sandbox land here as they happen.</p>
                </div>
            @else
                <div>
                    @foreach($recentConversations as $conv)
                        <div class="d-flex align-items-start justify-content-between gap-2 px-3 py-2.5"
                             style="border-bottom: 1px solid var(--border);">
                            <div class="min-w-0">
                                <div class="fw-semibold text-truncate" style="font-size: 0.8125rem;">
                                    {{ $conv->bot->name ?? 'Deleted profile' }}
                                </div>
                                <div class="figure-mono text-muted text-truncate" style="font-size: 0.6875rem;">
                                    {{ $conv->origin ?: 'preview sandbox' }}
                                </div>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <div class="figure-mono" style="font-size: 0.78125rem;">{{ $conv->messages->count() }}</div>
                                <div class="text-muted" style="font-size: 0.6875rem;">{{ $conv->created_at->format('M j, H:i') }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

</div>

{{-- Modals live outside the table: a div is not valid inside tbody. --}}
@foreach($recentBots as $bot)
    @include('bots._embed-modal', ['bot' => $bot, 'apiHost' => $apiHost])
@endforeach

@endif
@endsection

