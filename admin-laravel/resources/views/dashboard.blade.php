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

@php
    $roleLabels = ['super_admin' => 'Super admin', 'system_admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer'];
    $memberCount = (int) $members->sum();
    $origins = trim((string) $activeSystem->allowed_origins);
@endphp

<div class="page-head mb-4">
    <div>
        <h1>{{ $activeSystem->name }}</h1>
        <p>{{ $activeSystem->description ?: 'Bot profiles, knowledge and embed codes for this workspace.' }}</p>
    </div>
</div>

<div class="metrics mb-4">
    <div class="metric">
        <span class="metric-label">Bot profiles</span>
        <span class="metric-figure">
            {{ number_format($bots->count()) }}
            @if($activeBotCount > 0)<span class="state-dot is-live"></span>@endif
        </span>
        <div class="metric-note">{{ number_format($activeBotCount) }} accepting chats</div>
    </div>
    <div class="metric">
        <span class="metric-label">Conversations, 7 days</span>
        <span class="metric-figure">{{ number_format($weekConversations) }}</span>
        <div class="metric-note">
            {{ number_format($weekMessages) }} visitor {{ \Illuminate\Support\Str::plural('message', $weekMessages) }}
            · <a href="{{ route('analytics.index', ['range' => '7d']) }}">Analytics</a>
        </div>
    </div>
    <div class="metric">
        <span class="metric-label">Knowledge sources</span>
        <span class="metric-figure">{{ number_format($readySourceCount) }}</span>
        <div class="metric-note">
            Ready, of {{ number_format($sourceCount) }} in {{ number_format($collectionCount) }} {{ \Illuminate\Support\Str::plural('collection', $collectionCount) }}
            @if($failedSourceCount > 0)
                · <span style="color: var(--danger);">{{ $failedSourceCount }} failed</span>
            @endif
        </div>
    </div>
    <div class="metric">
        <span class="metric-label">Database connections</span>
        <span class="metric-figure">{{ number_format($enabledConnectionCount) }}</span>
        <div class="metric-note">Enabled, of {{ number_format($connectionCount) }}</div>
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

            @if($bots->isEmpty())
                <div class="empty">
                    <i class="bi bi-cpu"></i>
                    <h6>No bot profiles yet</h6>
                    <p>A profile holds the model, the system prompt and the widget styling. Create one under Bot profiles to get an embed snippet.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="min-width: 220px;">Profile</th>
                                <th style="min-width: 190px;">Model</th>
                                <th style="width: 100px;">Status</th>
                                <th class="text-end" style="width: 110px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($bots as $bot)
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
                                        <div class="bot-model mb-0.5">
                                            <span class="chip">{{ $bot->provider?->name ?? 'No provider' }}</span>
                                            <span class="figure-mono bot-model-name">{{ $bot->model_name }}</span>
                                        </div>
                                        <div class="figure-mono text-muted text-truncate" style="font-size: 0.6875rem; max-width: 240px;">{{ $bot->provider && !$bot->provider->isVisibleTo(auth()->user()) ? 'Set by ' . $bot->provider->ownerName() : ($bot->provider?->base_url ?? 'No endpoint set') }}</div>
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
                                        {{-- One button, the actions under it. Fixed positioning
                                             keeps the menu from being clipped by the scrolling table. --}}
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                                    data-bs-toggle="dropdown" aria-expanded="false"
                                                    data-bs-popper-config='{"strategy":"fixed"}'
                                                    aria-label="Actions for {{ $bot->name }}">
                                                Actions
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#embedModal{{ $bot->id }}">
                                                        <i class="bi bi-code-slash"></i> Embed code
                                                    </button>
                                                </li>
                                                @if($canEdit)
                                                    <li>
                                                        <a class="dropdown-item" href="{{ route('bots.edit', $bot->id) }}">
                                                            <i class="bi bi-gear"></i> Settings
                                                        </a>
                                                    </li>
                                                @endif
                                                <li>
                                                    <a class="dropdown-item" href="{{ route('analytics.index', ['bots' => [$bot->id]]) }}">
                                                        <i class="bi bi-bar-chart-line"></i> Analytics
                                                    </a>
                                                </li>
                                            </ul>
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
        <div class="card h-100 d-flex flex-column">
            <div class="card-header">Workspace</div>
            <div class="px-3 py-2">
                <div class="kv">
                    <span class="kv-key">Your role</span>
                    <span class="kv-val">{{ $roleLabels[$role] ?? 'Member' }}</span>
                </div>
                <div class="kv">
                    <span class="kv-key">Members</span>
                    <span class="kv-val">{{ number_format($memberCount) }}</span>
                </div>
                @foreach(['system_admin', 'editor', 'viewer'] as $memberRole)
                    @if(($members[$memberRole] ?? 0) > 0)
                        <div class="kv">
                            <span class="kv-key ps-3">{{ \Illuminate\Support\Str::plural($roleLabels[$memberRole], $members[$memberRole]) }}</span>
                            <span class="kv-val">{{ number_format($members[$memberRole]) }}</span>
                        </div>
                    @endif
                @endforeach
                <div class="kv">
                    <span class="kv-key">Allowed sites</span>
                    <span class="kv-val text-truncate" style="max-width: 200px;" title="{{ $origins ?: '*' }}">
                        {{ $origins === '' || $origins === '*' ? 'Any site' : $origins }}
                    </span>
                </div>
                <div class="kv">
                    <span class="kv-key">Created</span>
                    <span class="kv-val">{{ $activeSystem->created_at?->format('M j, Y') ?? '—' }}</span>
                </div>
            </div>
            <div class="px-3 pb-3 mt-auto d-flex flex-column gap-2">
                <a href="{{ route('analytics.index') }}" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-bar-chart-line"></i> How visitors use these bots
                </a>
                <a href="{{ route('kb.index') }}" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-journal-text"></i> Knowledge base
                </a>
            </div>
        </div>
    </div>

</div>

{{-- Modals live outside the table: a div is not valid inside tbody. --}}
@foreach($bots as $bot)
    @include('bots._embed-modal', ['bot' => $bot, 'apiHost' => $apiHost])
@endforeach

@endif
@endsection
