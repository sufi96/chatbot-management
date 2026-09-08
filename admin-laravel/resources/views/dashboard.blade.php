@extends('layouts.app')

@section('content')
<div class="d-flex flex-column gap-4">

    <!-- Hero Header Banner -->
    <div class="card border-0 shadow-sm p-4 p-lg-4 rounded-4" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #eef2f6 !important;">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div class="pe-lg-3">
                <div class="d-flex align-items-center gap-2 mb-1.5 flex-wrap">
                    <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">
                        Welcome back, {{ auth()->user()->name }} 👋
                    </h4>
                    @if($hasSystems)
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1 small fw-semibold d-inline-flex align-items-center gap-1 btn-nowrap">
                            <i class="bi bi-layers-fill"></i> {{ $activeSystem->name }}
                        </span>
                    @endif
                </div>
                <p class="text-secondary small mb-0" style="line-height: 1.55;">
                    @if($hasSystems)
                        {{ $activeSystem->description ?: 'Manage chatbot profiles, models, and embed codes for this system workspace.' }}
                    @else
                        No systems assigned yet. Create a system workspace to get started.
                    @endif
                </p>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap flex-sm-nowrap flex-shrink-0">
                @if(auth()->user()->isSuperAdmin())
                    <a href="{{ route('systems.index') }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5 px-3 py-2 btn-nowrap rounded-3">
                        <i class="bi bi-layers"></i> Systems ({{ $systemCount }})
                    </a>
                @endif

                <a href="{{ route('logs.index') }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5 px-3 py-2 btn-nowrap rounded-3">
                    <i class="bi bi-chat-left-dots"></i> View Logs
                </a>

                @if($hasSystems && auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                    <a href="{{ route('bots.create') }}" class="btn btn-sm btn-brand d-inline-flex align-items-center gap-1.5 px-3.5 py-2 btn-nowrap rounded-3">
                        <i class="bi bi-plus-lg"></i> Create Bot Profile
                    </a>
                @endif
            </div>
        </div>
    </div>

    @if(!$hasSystems)
        <div class="card p-5 text-center my-4 border-0 shadow-sm rounded-4">
            <div class="rounded-circle bg-light d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 72px; height: 72px;">
                <i class="bi bi-layers text-muted fs-2"></i>
            </div>
            <h5 class="fw-bold text-dark mb-1">No Systems Available</h5>
            <p class="text-muted small mb-4" style="max-width: 400px; margin: 0 auto;">You need to create or be assigned to a system workspace before creating chatbot profiles.</p>
            @if(auth()->user()->isSuperAdmin())
                <a href="{{ route('systems.index') }}" class="btn btn-brand btn-sm mx-auto px-4 py-2">Create Your First System</a>
            @endif
        </div>
    @else
        <!-- Metrics Cards -->
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="card card-interactive p-3.5 h-100 border-0 rounded-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; background: rgba(99, 102, 241, 0.1); color: #4f46e5;">
                            <i class="bi bi-robot fs-3"></i>
                        </div>
                        <div>
                            <span class="text-secondary text-uppercase fw-bold d-block" style="font-size: 0.65rem; letter-spacing: 0.05em;">Total Bots</span>
                            <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">{{ $botCount }}</h3>
                            <small class="text-muted" style="font-size: 0.72rem;">Configured in workspace</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-lg-3">
                <div class="card card-interactive p-3.5 h-100 border-0 rounded-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; background: rgba(16, 185, 129, 0.1); color: #10b981;">
                            <i class="bi bi-check-circle fs-3"></i>
                        </div>
                        <div>
                            <span class="text-secondary text-uppercase fw-bold d-block" style="font-size: 0.65rem; letter-spacing: 0.05em;">Active Status</span>
                            <div class="d-flex align-items-center gap-1.5">
                                <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">{{ $activeBotCount }}</h3>
                                <span class="status-pulse-dot ms-1"></span>
                            </div>
                            <small class="text-muted" style="font-size: 0.72rem;">Online & accepting chats</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-lg-3">
                <div class="card card-interactive p-3.5 h-100 border-0 rounded-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; background: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                            <i class="bi bi-chat-dots fs-3"></i>
                        </div>
                        <div>
                            <span class="text-secondary text-uppercase fw-bold d-block" style="font-size: 0.65rem; letter-spacing: 0.05em;">Conversations</span>
                            <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">{{ $conversationCount }}</h3>
                            <small class="text-muted" style="font-size: 0.72rem;">Unique chat sessions</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-lg-3">
                <div class="card card-interactive p-3.5 h-100 border-0 rounded-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; background: rgba(14, 165, 233, 0.1); color: #0284c7;">
                            <i class="bi bi-chat-text fs-3"></i>
                        </div>
                        <div>
                            <span class="text-secondary text-uppercase fw-bold d-block" style="font-size: 0.65rem; letter-spacing: 0.05em;">Total Messages</span>
                            <h3 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">{{ $messageCount }}</h3>
                            <small class="text-muted" style="font-size: 0.72rem;">Inference turns logged</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bot Profiles Table Card -->
        <div class="card border-0 rounded-4 shadow-sm">
            <div class="card-header bg-white d-flex align-items-center justify-content-between py-3 px-4 border-bottom">
                <div>
                    <h6 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
                        <span>Chatbot Profiles in {{ $activeSystem->name }}</span>
                        <span class="badge bg-light text-secondary border px-2 py-0.5" style="font-size: 0.72rem;">{{ $recentBots->count() }} profiles</span>
                    </h6>
                    <small class="text-muted" style="font-size: 0.75rem;">Each profile encapsulates dedicated LLM model parameters, system persona, and widget appearance.</small>
                </div>
                <a href="{{ route('bots.index') }}" class="btn btn-sm btn-outline-secondary small py-1 px-3 rounded-pill">
                    View All <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>

            @if($recentBots->isEmpty())
                <div class="card-body p-5 text-center text-muted">
                    <div class="rounded-circle bg-light d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 56px; height: 56px;">
                        <i class="bi bi-robot text-secondary fs-3"></i>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">No bot profiles configured in this system yet</h6>
                    <p class="text-muted small mb-3">Create your first chatbot profile to generate the 1-line embed snippet.</p>
                    @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                        <a href="{{ route('bots.create') }}" class="btn btn-brand btn-sm px-3.5 py-2">
                            <i class="bi bi-plus-lg me-1"></i> Create Bot Profile
                        </a>
                    @endif
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-secondary text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                            <tr>
                                <th class="ps-4 py-3" style="min-width: 220px;">Bot Name</th>
                                <th class="py-3" style="min-width: 200px;">Provider & Model</th>
                                <th class="py-3" style="width: 150px; white-space: nowrap;">Appearance</th>
                                <th class="py-3" style="width: 120px; white-space: nowrap;">Status</th>
                                <th class="text-end pe-4 py-3" style="width: 160px; white-space: nowrap;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentBots as $bot)
                                <tr>
                                    <td class="ps-4 py-3.5">
                                        <div class="d-flex align-items-center gap-3">
                                            @if($bot->bot_avatar_url)
                                                <img src="{{ $bot->bot_avatar_url }}" alt="{{ $bot->name }}" class="rounded-3 shadow-sm border" style="width: 40px; height: 40px; object-fit: cover;">
                                            @else
                                                <div class="rounded-3 d-flex align-items-center justify-content-center text-white fw-bold shadow-sm" style="width: 40px; height: 40px; background-color: {{ $bot->widget_primary_color ?: '#4f46e5' }}; font-size: 1.1rem;">
                                                    🤖
                                                </div>
                                            @endif
                                            <div>
                                                <div class="fw-semibold text-dark">{{ $bot->name }}</div>
                                                <small class="text-muted font-monospace" style="font-size: 0.7rem;">ID: {{ $bot->id }}</small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1.5 mb-1">
                                            @if($bot->provider_type === 'ollama')
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle text-uppercase px-2 py-0.5 rounded-pill btn-nowrap" style="font-size: 0.65rem;">
                                                    Ollama
                                                </span>
                                            @else
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle text-uppercase px-2 py-0.5 rounded-pill btn-nowrap" style="font-size: 0.65rem;">
                                                    Custom API
                                                </span>
                                            @endif
                                            <span class="fw-semibold text-dark font-monospace small btn-nowrap">{{ $bot->model_name }}</span>
                                        </div>
                                        <small class="text-muted text-truncate d-block font-monospace" style="max-width: 240px; font-size: 0.72rem;">{{ $bot->base_url }}</small>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2 text-nowrap">
                                            <span class="d-inline-block rounded-circle border shadow-sm flex-shrink-0" style="width: 18px; height: 18px; background-color: {{ $bot->widget_primary_color ?: '#4f46e5' }};" title="Theme color"></span>
                                            <span class="small text-secondary btn-nowrap" style="font-size: 0.75rem;">
                                                {{ $bot->launcher_shape === 'transparent_fit' ? 'Cutout Shape' : ($bot->launcher_shape === 'circle_transparent' ? 'No-BG Circle' : 'Circle') }}
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        @if($bot->is_active)
                                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1.5 rounded-pill small fw-semibold d-inline-flex align-items-center gap-1.5 btn-nowrap">
                                                <span class="status-pulse-dot" style="width: 6px; height: 6px;"></span> Active
                                            </span>
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2.5 py-1.5 rounded-pill small fw-semibold btn-nowrap">
                                                Inactive
                                            </span>
                                        @endif
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="d-flex align-items-center justify-content-end gap-1.5 flex-nowrap">
                                            <a href="{{ route('bots.embed', $bot->id) }}" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1.5 px-3 py-1.5 rounded-3 btn-nowrap" style="font-size: 0.78rem;">
                                                <i class="bi bi-code-slash"></i> Embed
                                            </a>
                                            @if(auth()->user()->canManageSystem($activeSystem->id, 'editor'))
                                                <a href="{{ route('bots.edit', $bot->id) }}" class="btn btn-sm btn-outline-secondary px-2.5 py-1.5 rounded-3 btn-nowrap" title="Edit Profile Settings" style="font-size: 0.78rem;">
                                                    <i class="bi bi-gear"></i>
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

        <!-- 3-Step Integration Architecture Cards -->
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="card card-interactive p-4 h-100 border-0 rounded-4">
                    <div class="d-flex align-items-center gap-2 mb-2.5">
                        <span class="badge rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 28px; height: 28px; font-size: 0.82rem; background: linear-gradient(135deg, #4f46e5, #6366f1); color: #ffffff;">1</span>
                        <h6 class="fw-bold mb-0 text-dark small">Workspace & RBAC Access</h6>
                    </div>
                    <p class="text-secondary small mb-0" style="font-size: 0.78rem; line-height: 1.55;">
                        Group chatbots inside workspaces. Granular roles (Admin, Editor, Viewer) guarantee security and strict system isolation.
                    </p>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card card-interactive p-4 h-100 border-0 rounded-4">
                    <div class="d-flex align-items-center gap-2 mb-2.5">
                        <span class="badge rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 28px; height: 28px; font-size: 0.82rem; background: linear-gradient(135deg, #8b5cf6, #a855f7); color: #ffffff;">2</span>
                        <h6 class="fw-bold mb-0 text-dark small">Zero-Latency Streaming Engine</h6>
                    </div>
                    <p class="text-secondary small mb-0" style="font-size: 0.78rem; line-height: 1.55;">
                        FastAPI powers high-performance asynchronous SSE streaming directly from local Ollama or OpenAI endpoints.
                    </p>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="card card-interactive p-4 h-100 border-0 rounded-4">
                    <div class="d-flex align-items-center gap-2 mb-2.5">
                        <span class="badge rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 28px; height: 28px; font-size: 0.82rem; background: linear-gradient(135deg, #0ea5e9, #06b6d4); color: #ffffff;">3</span>
                        <h6 class="fw-bold mb-0 text-dark small">1-Line Universal Embed</h6>
                    </div>
                    <p class="text-secondary small mb-0" style="font-size: 0.78rem; line-height: 1.55;">
                        Drop the generated <code>&lt;script&gt;</code> tag into any HTML, WordPress, PHP, or React app. Shadow DOM isolates all styling automatically.
                    </p>
                </div>
            </div>
        </div>
    @endif

</div>
@endsection
