@extends('layouts.app')

@section('content')
<div class="d-flex flex-column gap-4">

    <!-- Header & Action Bar -->
    <div class="card border-0 shadow-sm p-4 rounded-4" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #eef2f6 !important;">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-1">
                    <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">Bot Profiles</h4>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1 small fw-semibold">
                        <i class="bi bi-layers-fill me-1"></i> {{ $activeSystem->name }}
                    </span>
                </div>
                <p class="text-secondary small mb-0">Chatbots configured in workspace: <strong>{{ $activeSystem->name }}</strong>. Each bot has dedicated model configurations and embed scripts.</p>
            </div>

            @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                <a href="{{ route('bots.create') }}" class="btn btn-sm btn-brand d-flex align-items-center gap-1.5 px-3.5 py-2">
                    <i class="bi bi-plus-lg"></i> Create Bot Profile
                </a>
            @endif
        </div>
    </div>

    <!-- Bots Grid -->
    <div class="row g-4">
        @forelse($bots as $bot)
            <div class="col-12 col-lg-6 col-xxl-4">
                <div class="card card-interactive h-100 border-0 rounded-4 d-flex flex-column justify-content-between overflow-hidden">
                    <div class="p-4">
                        <!-- Top Row: Avatar & Status -->
                        <div class="d-flex align-items-start justify-content-between mb-3">
                            <div class="d-flex align-items-center gap-3">
                                @if($bot->bot_avatar_url)
                                    <img src="{{ $bot->bot_avatar_url }}" alt="{{ $bot->name }}" class="rounded-3 shadow-sm border" style="width: 48px; height: 48px; object-fit: contain; background-color: #f8fafc;">
                                @else
                                    <div class="rounded-3 d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width: 48px; height: 48px; background-color: {{ $bot->widget_primary_color ?: '#4f46e5' }}; font-size: 1.35rem;">
                                        🤖
                                    </div>
                                @endif
                                <div>
                                    <h6 class="fw-bold text-dark mb-0.5" style="letter-spacing: -0.01em;">{{ $bot->name }}</h6>
                                    <div class="d-flex align-items-center gap-1.5">
                                        <small class="text-muted font-monospace" style="font-size: 0.7rem;">ID: {{ $bot->id }}</small>
                                        @if($bot->launcher_icon_url)
                                            <span class="badge bg-light text-primary border px-1.5 py-0.5" style="font-size: 0.62rem;" title="Custom Launcher Icon Uploaded">
                                                <i class="bi bi-image"></i> Icon
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            @if($bot->is_active)
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 rounded-pill small fw-semibold d-inline-flex align-items-center gap-1.5 btn-nowrap">
                                    <span class="status-pulse-dot" style="width: 6px; height: 6px;"></span> Active
                                </span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1.5 rounded-pill small fw-semibold btn-nowrap">
                                    Inactive
                                </span>
                            @endif
                        </div>

                        <!-- Specs Box -->
                        <div class="p-3 rounded-3 bg-light border small mb-3">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-muted" style="font-size: 0.78rem;">Provider:</span>
                                @if($bot->provider_type === 'ollama')
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle text-uppercase px-2 py-0.5 rounded-pill btn-nowrap" style="font-size: 0.68rem;">
                                        Local Ollama
                                    </span>
                                @else
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle text-uppercase px-2 py-0.5 rounded-pill btn-nowrap" style="font-size: 0.68rem;">
                                        Custom API
                                    </span>
                                @endif
                            </div>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-muted" style="font-size: 0.78rem;">Model:</span>
                                <span class="font-monospace fw-semibold text-dark btn-nowrap" style="font-size: 0.78rem;">{{ $bot->model_name }}</span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-muted" style="font-size: 0.78rem;">Shape Fitting:</span>
                                <span class="badge bg-white text-secondary border px-2 py-0.5 btn-nowrap" style="font-size: 0.68rem;">
                                    {{ $bot->launcher_shape === 'transparent_fit' ? 'Cutout Fit' : ($bot->launcher_shape === 'circle_transparent' ? 'No-BG Circle' : 'Standard Circle') }}
                                </span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="text-muted" style="font-size: 0.78rem;">Endpoint:</span>
                                <code class="text-truncate font-monospace" style="max-width: 170px; font-size: 0.72rem;" title="{{ $bot->base_url }}">{{ $bot->base_url }}</code>
                            </div>
                        </div>

                        <!-- System Persona Prompt Snippet -->
                        <div>
                            <small class="text-secondary text-uppercase fw-bold d-block mb-1.5" style="font-size: 0.66rem; letter-spacing: 0.05em;">System Persona Prompt:</small>
                            <p class="text-muted small fst-italic mb-0 bg-white p-2.5 rounded-3 border" style="font-size: 0.76rem; min-height: 48px; line-height: 1.45;">
                                "{{ \Illuminate\Support\Str::limit($bot->system_prompt, 100) }}"
                            </p>
                        </div>
                    </div>

                    <!-- Footer Actions -->
                    <div class="p-3 px-4 border-top bg-light bg-opacity-50 d-flex align-items-center justify-content-between gap-2 flex-wrap flex-sm-nowrap">
                        <a href="{{ route('bots.embed', $bot->id) }}" class="btn btn-sm btn-brand flex-grow-1 d-inline-flex align-items-center justify-content-center gap-1.5 py-1.5 px-3 rounded-3 btn-nowrap" style="font-size: 0.8rem;">
                            <i class="bi bi-code-slash"></i> Embed Code
                        </a>

                        <div class="d-flex align-items-center gap-1.5 flex-nowrap">
                            @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                                <a href="{{ route('bots.edit', $bot->id) }}" class="btn btn-sm btn-outline-secondary py-1.5 px-2.5 rounded-3 btn-nowrap" title="Edit Profile & Images" style="font-size: 0.8rem;">
                                    <i class="bi bi-gear"></i>
                                </a>
                            @endif

                            @if(auth()->user()->canManageSystem($activeSystem->id, 'system_admin'))
                                <form action="{{ route('bots.destroy', $bot->id) }}" method="POST" onsubmit="return confirm('Delete bot profile [{{ $bot->name }}]?');" class="d-inline m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-1.5 px-2.5 rounded-3 btn-nowrap" title="Delete Profile" style="font-size: 0.8rem;">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card p-5 text-center text-muted border-0 shadow-sm rounded-4">
                    <div class="rounded-circle bg-light d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 68px; height: 68px;">
                        <i class="bi bi-robot fs-2 text-secondary"></i>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">No bot profiles in this system yet</h6>
                    <p class="small text-muted mb-4" style="max-width: 400px; margin: 0 auto;">Create your first chatbot profile to connect Ollama or a custom provider and get the embed code.</p>
                    @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                        <a href="{{ route('bots.create') }}" class="btn btn-brand btn-sm mx-auto px-4 py-2">
                            <i class="bi bi-plus-lg me-1"></i> Create Bot Profile
                        </a>
                    @endif
                </div>
            </div>
        @endforelse
    </div>

</div>
@endsection
