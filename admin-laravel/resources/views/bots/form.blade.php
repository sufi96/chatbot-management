@extends('layouts.app')

@section('content')
<div class="d-flex flex-column gap-4" style="max-width: 1200px;">

    <!-- Breadcrumb & Title -->
    <div class="card border-0 shadow-sm p-4 rounded-4" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #eef2f6 !important;">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div>
                <a href="{{ route('bots.index') }}" class="text-decoration-none small text-primary d-inline-flex align-items-center gap-1.5 mb-1.5 fw-semibold">
                    <i class="bi bi-arrow-left"></i> Back to Bot Profiles
                </a>
                <h4 class="fw-bold mb-1 text-dark" style="letter-spacing: -0.02em;">
                    {{ $isEdit ? 'Edit Bot Profile: ' . $bot->name : 'Create New Bot Profile' }}
                </h4>
                <p class="text-secondary small mb-0">Configuring for workspace: <strong>{{ $activeSystem->name }}</strong></p>
            </div>

            @if($isEdit)
                <a href="{{ route('bots.embed', $bot->id) }}" class="btn btn-sm btn-brand d-inline-flex align-items-center gap-1.5 px-3 py-2 shadow-sm">
                    <i class="bi bi-code-slash"></i> Get Embed Code
                </a>
            @endif
        </div>
    </div>

    <form action="{{ $isEdit ? route('bots.update', $bot->id) : route('bots.store') }}" method="POST" enctype="multipart/form-data" id="botForm">
        @csrf
        @if($isEdit)
            @method('PUT')
        @endif

        <div class="row g-4">
            <!-- Left 8 Columns: Form Settings -->
            <div class="col-12 col-lg-8">

                <!-- 1. Identity & Status -->
                <div class="card border-0 shadow-sm p-4 rounded-4 mb-4">
                    <h6 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                        <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center" style="width: 26px; height: 26px; font-size: 0.8rem;">1</span>
                        <span>Bot Identity & Status</span>
                    </h6>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Bot Profile Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="input_name" class="form-control" value="{{ old('name', $bot->name) }}" placeholder="e.g. Sales Concierge, Support Assistant, Technical Guru" required>
                    </div>

                    <div class="form-check form-switch pt-1 d-flex align-items-center gap-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="is_active" {{ old('is_active', $bot->is_active) ? 'checked' : '' }} style="width: 2.5em; height: 1.3em;">
                        <label class="form-check-label small fw-semibold text-dark" for="is_active">Active & Online (Accepts live conversations via widget & API)</label>
                    </div>
                </div>

                <!-- 2. LLM Provider & Model Endpoint -->
                <div class="card border-0 shadow-sm p-4 rounded-4 mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2">
                            <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center" style="width: 26px; height: 26px; font-size: 0.8rem;">2</span>
                            <span>Model & Endpoint Configuration</span>
                        </h6>
                        <span class="badge bg-light text-secondary border px-2.5 py-1 small">OpenAI-Compatible Standard</span>
                    </div>

                    <!-- Provider Selector -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Provider Type</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="form-check card p-3 h-100 border rounded-3 cursor-pointer" onclick="document.getElementById('provider_ollama').checked = true; applyProviderPreset('ollama');">
                                    <input class="form-check-input" type="radio" name="provider_type" id="provider_ollama" value="ollama" {{ old('provider_type', $bot->provider_type) === 'ollama' ? 'checked' : '' }} onchange="applyProviderPreset('ollama')">
                                    <label class="form-check-label small fw-bold text-dark d-block" for="provider_ollama">
                                        🦙 Local Ollama
                                        <small class="text-muted fw-normal d-block" style="font-size: 0.72rem;">http://localhost:11434/v1</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check card p-3 h-100 border rounded-3 cursor-pointer" onclick="document.getElementById('provider_custom').checked = true; applyProviderPreset('custom');">
                                    <input class="form-check-input" type="radio" name="provider_type" id="provider_custom" value="custom" {{ old('provider_type', $bot->provider_type) === 'custom' ? 'checked' : '' }} onchange="applyProviderPreset('custom')">
                                    <label class="form-check-label small fw-bold text-dark d-block" for="provider_custom">
                                        🌐 Custom API / Remote
                                        <small class="text-muted fw-normal d-block" style="font-size: 0.72rem;">OpenAI, Groq, vLLM, DeepSeek, etc.</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Base URL & API Key -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label small fw-semibold text-dark">Base URL <span class="text-danger">*</span></label>
                            <input type="text" name="base_url" id="base_url" class="form-control font-monospace" value="{{ old('base_url', $bot->base_url) }}" placeholder="http://localhost:11434/v1" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label small fw-semibold text-dark">API Key (Optional for Ollama)</label>
                            <input type="password" name="api_key" id="api_key" class="form-control font-monospace" value="{{ old('api_key', $bot->api_key) }}" placeholder="Leave blank for local Ollama">
                        </div>
                    </div>

                    <!-- Model Name, Temperature, Max Tokens -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-lg-6">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label small fw-semibold text-dark mb-0">Model Name <span class="text-danger">*</span></label>
                                <span id="fetchModelsBadge" class="small fw-semibold" style="font-size: 0.72rem;"></span>
                            </div>
                            <div class="input-group">
                                <input type="text" name="model_name" id="model_name" class="form-control font-monospace" value="{{ old('model_name', $bot->model_name) }}" placeholder="e.g. llama3.2, mistral, gpt-4o" required>
                                
                                <!-- Dropdown Toggle Button -->
                                <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" id="btnModelDropdownToggle" title="Select discovered model">
                                    <span class="visually-hidden">Toggle Dropdown</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-lg py-1 rounded-3" id="modelsDropdownList" style="max-height: 260px; overflow-y: auto; min-width: 250px;">
                                    <li><span class="dropdown-item-text text-muted small"><i class="bi bi-info-circle me-1"></i> Click "Fetch Models" to load</span></li>
                                </ul>

                                <!-- Fetch Models Button -->
                                <button type="button" class="btn btn-brand d-flex align-items-center gap-1.5 px-3" id="btnFetchModels" onclick="fetchModelsFromBaseUrl()" title="Test endpoint and discover models from Base URL">
                                    <i class="bi bi-arrow-repeat" id="iconFetch"></i>
                                    <span id="textFetch">Fetch Models</span>
                                </button>
                            </div>
                            <div class="form-text text-muted mt-1" style="font-size: 0.72rem;">
                                <i class="bi bi-hdd-network me-1"></i> Discovers models & tests endpoint connectivity automatically.
                            </div>
                        </div>
                        <div class="col-6 col-lg-3">
                            <label class="form-label small fw-semibold text-dark">Temperature</label>
                            <input type="number" step="0.1" min="0" max="2" name="temperature" class="form-control" value="{{ old('temperature', $bot->temperature) }}">
                        </div>
                        <div class="col-6 col-lg-3">
                            <label class="form-label small fw-semibold text-dark">Max Tokens</label>
                            <input type="number" step="64" min="64" max="8192" name="max_tokens" class="form-control" value="{{ old('max_tokens', $bot->max_tokens) }}">
                        </div>
                    </div>

                    <!-- Live Connection Test -->
                    <div class="d-flex align-items-center gap-2 pt-3 border-top">
                        <button type="button" onclick="testConnection()" id="btnTestConn" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1.5 px-3 py-1.5" style="font-size: 0.78rem;">
                            <i class="bi bi-lightning-charge-fill text-warning"></i> Test Inference Response
                        </button>
                        <span id="testConnResult" class="small fw-medium"></span>
                    </div>
                </div>

                <!-- 3. System Instructions & Persona -->
                <div class="card border-0 shadow-sm p-4 rounded-4 mb-4">
                    <h6 class="fw-bold text-dark mb-2 d-flex align-items-center gap-2">
                        <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center" style="width: 26px; height: 26px; font-size: 0.8rem;">3</span>
                        <span>System Persona & Instructions</span>
                    </h6>

                    <div class="d-flex align-items-center gap-1.5 mb-2.5">
                        <small class="text-secondary fw-semibold" style="font-size: 0.72rem;">Quick Presets:</small>
                        <button type="button" onclick="setPromptPreset('support')" class="btn btn-sm btn-light border py-0.5 px-2.5 rounded-pill" style="font-size: 0.72rem;">Customer Support</button>
                        <button type="button" onclick="setPromptPreset('sales')" class="btn btn-sm btn-light border py-0.5 px-2.5 rounded-pill" style="font-size: 0.72rem;">Sales Concierge</button>
                        <button type="button" onclick="setPromptPreset('technical')" class="btn btn-sm btn-light border py-0.5 px-2.5 rounded-pill" style="font-size: 0.72rem;">Technical Support</button>
                    </div>

                    <textarea name="system_prompt" id="system_prompt" rows="4" class="form-control font-monospace small" placeholder="You are a helpful, professional AI assistant...">{{ old('system_prompt', $bot->system_prompt) }}</textarea>
                </div>

                <!-- 4. Images & Widget Customizer -->
                <div class="card border-0 shadow-sm p-4 rounded-4 mb-4">
                    <h6 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                        <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center" style="width: 26px; height: 26px; font-size: 0.8rem;">4</span>
                        <span>Branding, Custom Images & Silhouette Fitting</span>
                    </h6>

                    <!-- Custom Images Upload Section -->
                    <div class="row g-3 mb-4 p-3.5 rounded-4 bg-light border">
                        <!-- Launcher Button Image -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold text-dark d-flex align-items-center gap-1.5 mb-1">
                                <i class="bi bi-circle-square text-primary"></i> Floating Launcher Button Icon
                            </label>
                            <small class="text-muted d-block mb-2" style="font-size: 0.72rem;">Shown in the floating button anchored on host site before clicked.</small>
                            <input type="file" name="launcher_icon" id="launcher_icon_input" accept="image/*" class="form-control form-control-sm" onchange="previewUpload(this, 'prevLauncherImg', 'prevLauncherDefault')">

                            @if($bot->launcher_icon_url)
                                <div class="mt-2.5 d-flex align-items-center gap-2 p-2 rounded-3 bg-white border">
                                    <img src="{{ $bot->launcher_icon_url }}" alt="Launcher" class="rounded border" style="width: 34px; height: 34px; object-fit: contain;">
                                    <div class="form-check small mb-0">
                                        <input class="form-check-input" type="checkbox" name="remove_launcher_icon" value="1" id="remove_launcher_icon">
                                        <label class="form-check-label text-danger fw-medium" for="remove_launcher_icon" style="font-size: 0.75rem;">Reset to default icon</label>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-3 pt-2.5 border-top">
                                <label class="form-label small text-secondary fw-semibold mb-2" style="font-size: 0.74rem;">Launcher Shape & Background:</label>
                                <div class="d-flex flex-column gap-1.5">
                                    <label class="d-flex align-items-center gap-2 p-2 rounded-3 border bg-white cursor-pointer hover-bg-light">
                                        <input type="radio" name="launcher_shape" id="launcher_shape_circle" value="circle" {{ old('launcher_shape', $bot->launcher_shape ?? 'circle') === 'circle' ? 'checked' : '' }} onchange="updateLivePreview()" class="form-check-input mt-0">
                                        <div class="small">
                                            <span class="fw-semibold text-dark d-block" style="font-size: 0.78rem;">Standard Filled Circle</span>
                                            <span class="text-muted" style="font-size: 0.7rem;">Primary color background with white icon inside</span>
                                        </div>
                                    </label>

                                    <label class="d-flex align-items-center gap-2 p-2 rounded-3 border bg-white cursor-pointer hover-bg-light">
                                        <input type="radio" name="launcher_shape" id="launcher_shape_circle_transparent" value="circle_transparent" {{ old('launcher_shape', $bot->launcher_shape ?? 'circle') === 'circle_transparent' ? 'checked' : '' }} onchange="updateLivePreview()" class="form-check-input mt-0">
                                        <div class="small">
                                            <span class="fw-semibold text-dark d-block" style="font-size: 0.78rem;">Circle (No Fill / Transparent)</span>
                                            <span class="text-muted" style="font-size: 0.7rem;">Circular outline border with clear transparent interior</span>
                                        </div>
                                    </label>

                                    <label class="d-flex align-items-center gap-2 p-2 rounded-3 border bg-white cursor-pointer hover-bg-light">
                                        <input type="radio" name="launcher_shape" id="launcher_shape_transparent_fit" value="transparent_fit" {{ old('launcher_shape', $bot->launcher_shape ?? 'circle') === 'transparent_fit' ? 'checked' : '' }} onchange="updateLivePreview()" class="form-check-input mt-0">
                                        <div class="small">
                                            <span class="fw-semibold text-primary d-block" style="font-size: 0.78rem;">✨ Cutout Silhouette (Remove Background)</span>
                                            <span class="text-muted" style="font-size: 0.7rem;">Fits natural PNG shape (tall mascot, wide car, logos without container)</span>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Open Chatbox Bot Avatar -->
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-bold text-dark d-flex align-items-center gap-1.5 mb-1">
                                <i class="bi bi-person-badge text-primary"></i> Chatbox Bot Avatar (When Open)
                            </label>
                            <small class="text-muted d-block mb-2" style="font-size: 0.72rem;">Displayed in header banner and next to each response.</small>
                            <input type="file" name="bot_avatar" id="bot_avatar_input" accept="image/*" class="form-control form-control-sm" onchange="previewUpload(this, 'prevAvatarImg', 'prevAvatarDefault', 'prevMiniAvatarImg')">

                            @if($bot->bot_avatar_url)
                                <div class="mt-2.5 d-flex align-items-center gap-2 p-2 rounded-3 bg-white border">
                                    <img src="{{ $bot->bot_avatar_url }}" alt="Avatar" class="rounded border" style="width: 34px; height: 34px; object-fit: contain;">
                                    <div class="form-check small mb-0">
                                        <input class="form-check-input" type="checkbox" name="remove_bot_avatar" value="1" id="remove_bot_avatar">
                                        <label class="form-check-label text-danger fw-medium" for="remove_bot_avatar" style="font-size: 0.75rem;">Reset to default 🤖</label>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-3 pt-2.5 border-top">
                                <label class="form-label small text-secondary fw-semibold mb-2" style="font-size: 0.74rem;">Avatar Shape & Background:</label>
                                <div class="d-flex flex-column gap-1.5">
                                    <label class="d-flex align-items-center gap-2 p-2 rounded-3 border bg-white cursor-pointer hover-bg-light">
                                        <input type="radio" name="avatar_shape" id="avatar_shape_circle" value="circle" {{ old('avatar_shape', $bot->avatar_shape ?? 'circle') === 'circle' ? 'checked' : '' }} onchange="updateLivePreview()" class="form-check-input mt-0">
                                        <div class="small">
                                            <span class="fw-semibold text-dark d-block" style="font-size: 0.78rem;">Standard Circle Badge</span>
                                            <span class="text-muted" style="font-size: 0.7rem;">Contained circular avatar frame</span>
                                        </div>
                                    </label>

                                    <label class="d-flex align-items-center gap-2 p-2 rounded-3 border bg-white cursor-pointer hover-bg-light">
                                        <input type="radio" name="avatar_shape" id="avatar_shape_circle_transparent" value="circle_transparent" {{ old('avatar_shape', $bot->avatar_shape ?? 'circle') === 'circle_transparent' ? 'checked' : '' }} onchange="updateLivePreview()" class="form-check-input mt-0">
                                        <div class="small">
                                            <span class="fw-semibold text-dark d-block" style="font-size: 0.78rem;">Circle (Transparent Inner)</span>
                                            <span class="text-muted" style="font-size: 0.7rem;">Subtle border with transparent background</span>
                                        </div>
                                    </label>

                                    <label class="d-flex align-items-center gap-2 p-2 rounded-3 border bg-white cursor-pointer hover-bg-light">
                                        <input type="radio" name="avatar_shape" id="avatar_shape_transparent_fit" value="transparent_fit" {{ old('avatar_shape', $bot->avatar_shape ?? 'circle') === 'transparent_fit' ? 'checked' : '' }} onchange="updateLivePreview()" class="form-check-input mt-0">
                                        <div class="small">
                                            <span class="fw-semibold text-primary d-block" style="font-size: 0.78rem;">✨ Cutout Silhouette (Remove Background)</span>
                                            <span class="text-muted" style="font-size: 0.7rem;">Allows tall or wide transparent avatars without circular cropping</span>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Header Title & Position -->
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label small fw-semibold text-dark">Widget Header Title</label>
                            <input type="text" name="widget_title" id="widget_title" class="form-control" value="{{ old('widget_title', $bot->widget_title) }}" oninput="updateLivePreview()" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label small fw-semibold text-dark">Screen Position</label>
                            <select name="widget_position" id="widget_position" class="form-select">
                                <option value="bottom-right" {{ old('widget_position', $bot->widget_position) === 'bottom-right' ? 'selected' : '' }}>Bottom Right Corner</option>
                                <option value="bottom-left" {{ old('widget_position', $bot->widget_position) === 'bottom-left' ? 'selected' : '' }}>Bottom Left Corner</option>
                            </select>
                        </div>
                    </div>

                    <!-- Welcome Greeting -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-dark">Initial Welcome Greeting</label>
                        <input type="text" name="widget_greeting" id="widget_greeting" class="form-control" value="{{ old('widget_greeting', $bot->widget_greeting) }}" oninput="updateLivePreview()">
                    </div>

                    <!-- Color Picker -->
                    <div>
                        <label class="form-label small fw-semibold text-dark d-block">Brand Accent Theme Color</label>
                        <div class="d-flex align-items-center gap-2">
                            <input type="color" name="widget_primary_color" id="widget_primary_color" class="form-control form-control-color border-0 rounded-circle cursor-pointer shadow-sm" value="{{ old('widget_primary_color', $bot->widget_primary_color ?: '#4f46e5') }}" oninput="updateLivePreview()" style="width: 36px; height: 36px;">
                            <button type="button" onclick="setColor('#4f46e5')" class="btn btn-sm rounded-circle p-0 shadow-sm border border-2 border-white" style="background-color: #4f46e5; width: 30px; height: 30px;" title="Indigo"></button>
                            <button type="button" onclick="setColor('#0ea5e9')" class="btn btn-sm rounded-circle p-0 shadow-sm border border-2 border-white" style="background-color: #0ea5e9; width: 30px; height: 30px;" title="Sky Blue"></button>
                            <button type="button" onclick="setColor('#10b981')" class="btn btn-sm rounded-circle p-0 shadow-sm border border-2 border-white" style="background-color: #10b981; width: 30px; height: 30px;" title="Emerald"></button>
                            <button type="button" onclick="setColor('#8b5cf6')" class="btn btn-sm rounded-circle p-0 shadow-sm border border-2 border-white" style="background-color: #8b5cf6; width: 30px; height: 30px;" title="Purple"></button>
                            <button type="button" onclick="setColor('#f59e0b')" class="btn btn-sm rounded-circle p-0 shadow-sm border border-2 border-white" style="background-color: #f59e0b; width: 30px; height: 30px;" title="Amber"></button>
                            <button type="button" onclick="setColor('#0f172a')" class="btn btn-sm rounded-circle p-0 shadow-sm border border-2 border-white" style="background-color: #0f172a; width: 30px; height: 30px;" title="Dark Slate"></button>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="d-flex justify-content-end gap-2 pb-4">
                    <a href="{{ route('bots.index') }}" class="btn btn-outline-secondary px-4 py-2">Cancel</a>
                    <button type="submit" class="btn btn-brand px-4 py-2 shadow-sm">
                        <i class="bi bi-check2-circle me-1.5"></i> {{ $isEdit ? 'Save Profile Changes' : 'Create Bot Profile' }}
                    </button>
                </div>
            </div>

            <!-- Right 4 Columns: Interactive Live Preview -->
            <div class="col-12 col-lg-4">
                <div class="sticky-top" style="top: 90px; z-index: 10;">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <small class="text-uppercase fw-bold text-secondary" style="font-size: 0.68rem; letter-spacing: 0.05em;">Interactive Live Preview</small>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0.5 small fw-semibold">
                            <span class="status-pulse-dot" style="width: 5px; height: 5px;"></span> Real-time
                        </span>
                    </div>

                    <!-- Mock Widget Box -->
                    <div class="card shadow-lg overflow-hidden border-0 mb-3" style="height: 520px; border-radius: 22px;">
                        <!-- Header -->
                        <div id="prevHeader" class="p-3 text-white d-flex align-items-center justify-content-between shadow-sm" style="background: linear-gradient(135deg, {{ $bot->widget_primary_color ?: '#4f46e5' }}, #6366f1);">
                            <div class="d-flex align-items-center gap-2.5">
                                <div id="prevAvatarContainer" class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center overflow-hidden border border-white border-opacity-50 flex-shrink-0" style="width: 38px; height: 38px;">
                                    <img id="prevAvatarImg" src="{{ $bot->bot_avatar_url ?: '' }}" alt="Avatar" style="{{ $bot->bot_avatar_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;">
                                    <span id="prevAvatarDefault" style="{{ $bot->bot_avatar_url ? 'display:none;' : 'display:block;' }} font-size: 1.15rem;">🤖</span>
                                </div>
                                <div>
                                    <div id="prevTitle" class="fw-bold small lh-1 mb-1">{{ $bot->widget_title ?: 'AI Assistant' }}</div>
                                    <div class="text-white-50 d-flex align-items-center gap-1" style="font-size: 0.68rem;">
                                        <span class="p-1 rounded-circle bg-success d-inline-block"></span> Online & Thinking
                                    </div>
                                </div>
                            </div>
                            <i class="bi bi-x-lg text-white-50 small"></i>
                        </div>

                        <!-- Chat Area with spacious, beautiful bubbles -->
                        <div class="p-3 bg-light flex-grow-1 overflow-auto d-flex flex-column gap-3">
                            <!-- Bot Message with mini avatar -->
                            <div class="d-flex align-items-start gap-2" style="max-width: 88%;">
                                <div id="prevMiniAvatar" class="rounded-circle d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0 text-white mt-1 shadow-sm" style="width: 28px; height: 28px; background-color: {{ $bot->widget_primary_color ?: '#4f46e5' }}; font-size: 0.75rem;">
                                    <img id="prevMiniAvatarImg" src="{{ $bot->bot_avatar_url ?: '' }}" alt="Bot" style="{{ $bot->bot_avatar_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;">
                                    <span id="prevMiniAvatarDefault" style="{{ $bot->bot_avatar_url ? 'display:none;' : 'display:block;' }}">🤖</span>
                                </div>
                                <div class="d-flex flex-column align-items-start">
                                    <div id="prevGreeting" class="p-3 rounded-4 bg-white text-dark shadow-sm border small lh-base" style="border-top-left-radius: 4px !important;">
                                        {{ $bot->widget_greeting ?: 'Hello! How can I help you today?' }}
                                    </div>
                                    <span class="text-muted mt-1 px-1" style="font-size: 0.65rem;">Just now</span>
                                </div>
                            </div>

                            <!-- Sample User Message -->
                            <div class="d-flex flex-column align-items-end align-self-end" style="max-width: 82%;">
                                <div id="prevUserMsg" class="p-3 rounded-4 text-white shadow-sm small lh-base" style="background-color: {{ $bot->widget_primary_color ?: '#4f46e5' }}; border-top-right-radius: 4px !important;">
                                    Can you tell me more about your features?
                                </div>
                                <span class="text-muted mt-1 px-1" style="font-size: 0.65rem;">Just now</span>
                            </div>

                            <!-- Simulated Thinking Animation Indicator Bubble -->
                            <div class="d-flex align-items-start gap-2" style="max-width: 88%;">
                                <div class="rounded-circle d-flex align-items-center justify-content-center text-white mt-1 shadow-sm" style="width: 28px; height: 28px; background: #6366f1; font-size: 0.75rem;">
                                    ✨
                                </div>
                                <div class="p-2.5 px-3 rounded-4 bg-white text-muted shadow-sm border small d-inline-flex align-items-center gap-2" style="border-top-left-radius: 4px !important; font-size: 0.78rem;">
                                    <span class="spinner-grow spinner-grow-sm text-primary" style="width: 10px; height: 10px;" role="status"></span>
                                    <span class="fst-italic text-secondary" id="prevThinkingText">Thinking...</span>
                                </div>
                            </div>
                        </div>

                        <!-- Footer Input -->
                        <div class="p-2.5 bg-white border-top d-flex align-items-center gap-2">
                            <input type="text" class="form-control form-control-sm border-0 bg-light small py-2 px-3 rounded-3" placeholder="Type a message..." disabled>
                            <button type="button" id="prevSendBtn" class="btn btn-sm btn-primary rounded-3 px-3 py-2" style="background-color: {{ $bot->widget_primary_color ?: '#4f46e5' }}; border: none;" disabled>
                                <i class="bi bi-send-fill" style="font-size: 0.8rem;"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Floating Launcher Preview -->
                    <div class="card p-3 shadow-sm border-0 rounded-4 bg-white">
                        <small class="text-secondary text-uppercase fw-bold d-block mb-2" style="font-size: 0.68rem; letter-spacing: 0.05em;">Floating Launcher Button Preview:</small>
                        <div class="d-flex align-items-center gap-3">
                            <div id="prevLauncherBtn" class="rounded-circle text-white shadow d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0" style="width: 58px; height: 58px; background-color: {{ $bot->widget_primary_color ?: '#4f46e5' }}; cursor: pointer; transition: all 0.2s ease;">
                                <img id="prevLauncherImg" src="{{ $bot->launcher_icon_url ?: '' }}" alt="Launcher" style="{{ $bot->launcher_icon_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;">
                                <i id="prevLauncherDefault" class="bi bi-chat-dots-fill fs-4" style="{{ $bot->launcher_icon_url ? 'display:none;' : 'display:block;' }}"></i>
                            </div>
                            <small class="text-muted" style="font-size: 0.72rem; line-height: 1.45;">
                                Anchored to the bottom corner before user clicks. Custom silhouette shapes will display without background!
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

</div>

@push('scripts')
<script>
    function getSelectedRadioValue(name, defaultValue) {
        var el = document.querySelector('input[name="' + name + '"]:checked');
        return el ? el.value : defaultValue;
    }

    function updateLivePreview() {
        var title = document.getElementById('widget_title').value || 'AI Assistant';
        var greeting = document.getElementById('widget_greeting').value || 'Hello!';
        var color = document.getElementById('widget_primary_color').value || '#4f46e5';

        var launcherShape = getSelectedRadioValue('launcher_shape', 'circle');
        var avatarShape = getSelectedRadioValue('avatar_shape', 'circle');

        document.getElementById('prevTitle').textContent = title;
        document.getElementById('prevGreeting').textContent = greeting;
        document.getElementById('prevHeader').style.background = 'linear-gradient(135deg, ' + color + ', #6366f1)';
        document.getElementById('prevUserMsg').style.backgroundColor = color;
        document.getElementById('prevSendBtn').style.backgroundColor = color;

        // Apply Launcher Shape & Background styling
        var launcherBtn = document.getElementById('prevLauncherBtn');
        var launcherImg = document.getElementById('prevLauncherImg');
        var launcherDefault = document.getElementById('prevLauncherDefault');

        if (launcherShape === 'transparent_fit') {
            launcherBtn.style.backgroundColor = 'transparent';
            launcherBtn.style.boxShadow = 'none';
            launcherBtn.style.borderRadius = '0';
            launcherBtn.style.width = 'auto';
            launcherBtn.style.height = 'auto';
            launcherBtn.style.overflow = 'visible';
            launcherBtn.style.border = 'none';
            launcherImg.style.maxHeight = '65px';
            launcherImg.style.maxWidth = '85px';
            launcherImg.style.width = 'auto';
            launcherImg.style.height = 'auto';
            launcherImg.style.filter = 'drop-shadow(0 4px 12px rgba(0,0,0,0.25))';
            launcherImg.style.borderRadius = '0';
            if (launcherDefault) launcherDefault.style.color = color;
        } else if (launcherShape === 'circle_transparent') {
            launcherBtn.style.backgroundColor = 'transparent';
            launcherBtn.style.boxShadow = '0 4px 14px rgba(0,0,0,0.12)';
            launcherBtn.style.borderRadius = '50%';
            launcherBtn.style.width = '58px';
            launcherBtn.style.height = '58px';
            launcherBtn.style.overflow = 'hidden';
            launcherBtn.style.border = '2px solid ' + color;
            launcherImg.style.maxHeight = '100%';
            launcherImg.style.maxWidth = '100%';
            launcherImg.style.width = '100%';
            launcherImg.style.height = '100%';
            launcherImg.style.filter = 'none';
            launcherImg.style.borderRadius = '50%';
            if (launcherDefault) launcherDefault.style.color = color;
        } else {
            // Standard circle contained
            launcherBtn.style.backgroundColor = color;
            launcherBtn.style.boxShadow = '0 6px 18px rgba(0,0,0,0.2)';
            launcherBtn.style.borderRadius = '50%';
            launcherBtn.style.width = '58px';
            launcherBtn.style.height = '58px';
            launcherBtn.style.overflow = 'hidden';
            launcherBtn.style.border = 'none';
            launcherImg.style.maxHeight = '100%';
            launcherImg.style.maxWidth = '100%';
            launcherImg.style.width = '100%';
            launcherImg.style.height = '100%';
            launcherImg.style.filter = 'none';
            launcherImg.style.borderRadius = '50%';
            if (launcherDefault) launcherDefault.style.color = '#ffffff';
        }

        // Apply Avatar Shape & Background styling
        var avatarContainer = document.getElementById('prevAvatarContainer');
        var avatarImg = document.getElementById('prevAvatarImg');
        var miniAvatar = document.getElementById('prevMiniAvatar');
        var miniAvatarImg = document.getElementById('prevMiniAvatarImg');

        if (avatarShape === 'transparent_fit') {
            avatarContainer.style.background = 'transparent';
            avatarContainer.style.border = 'none';
            avatarContainer.style.borderRadius = '0';
            avatarContainer.style.width = 'auto';
            avatarContainer.style.height = 'auto';
            avatarContainer.style.overflow = 'visible';
            avatarImg.style.maxHeight = '42px';
            avatarImg.style.maxWidth = '54px';
            avatarImg.style.width = 'auto';
            avatarImg.style.height = 'auto';
            avatarImg.style.filter = 'drop-shadow(0 2px 6px rgba(0,0,0,0.22))';
            avatarImg.style.borderRadius = '0';

            miniAvatar.style.background = 'transparent';
            miniAvatar.style.border = 'none';
            miniAvatar.style.borderRadius = '0';
            miniAvatar.style.width = 'auto';
            miniAvatar.style.height = 'auto';
            miniAvatar.style.overflow = 'visible';
            if (miniAvatarImg) {
                miniAvatarImg.style.maxHeight = '34px';
                miniAvatarImg.style.maxWidth = '42px';
                miniAvatarImg.style.width = 'auto';
                miniAvatarImg.style.height = 'auto';
                miniAvatarImg.style.filter = 'drop-shadow(0 1px 4px rgba(0,0,0,0.18))';
                miniAvatarImg.style.borderRadius = '0';
            }
        } else if (avatarShape === 'circle_transparent') {
            avatarContainer.style.background = 'transparent';
            avatarContainer.style.border = '1.5px solid rgba(255,255,255,0.6)';
            avatarContainer.style.borderRadius = '50%';
            avatarContainer.style.width = '38px';
            avatarContainer.style.height = '38px';
            avatarContainer.style.overflow = 'hidden';
            avatarImg.style.maxHeight = '100%';
            avatarImg.style.maxWidth = '100%';
            avatarImg.style.width = '100%';
            avatarImg.style.height = '100%';
            avatarImg.style.filter = 'none';
            avatarImg.style.borderRadius = '50%';

            miniAvatar.style.background = 'transparent';
            miniAvatar.style.border = '1.5px solid ' + color;
            miniAvatar.style.borderRadius = '50%';
            miniAvatar.style.width = '28px';
            miniAvatar.style.height = '28px';
            miniAvatar.style.overflow = 'hidden';
            if (miniAvatarImg) {
                miniAvatarImg.style.maxHeight = '100%';
                miniAvatarImg.style.maxWidth = '100%';
                miniAvatarImg.style.width = '100%';
                miniAvatarImg.style.height = '100%';
                miniAvatarImg.style.filter = 'none';
                miniAvatarImg.style.borderRadius = '50%';
            }
        } else {
            // Standard circle contained
            avatarContainer.style.background = 'rgba(255,255,255,0.25)';
            avatarContainer.style.border = '1px solid rgba(255,255,255,0.5)';
            avatarContainer.style.borderRadius = '50%';
            avatarContainer.style.width = '38px';
            avatarContainer.style.height = '38px';
            avatarContainer.style.overflow = 'hidden';
            avatarImg.style.maxHeight = '100%';
            avatarImg.style.maxWidth = '100%';
            avatarImg.style.width = '100%';
            avatarImg.style.height = '100%';
            avatarImg.style.filter = 'none';
            avatarImg.style.borderRadius = '50%';

            miniAvatar.style.background = color;
            miniAvatar.style.border = 'none';
            miniAvatar.style.borderRadius = '50%';
            miniAvatar.style.width = '28px';
            miniAvatar.style.height = '28px';
            miniAvatar.style.overflow = 'hidden';
            if (miniAvatarImg) {
                miniAvatarImg.style.maxHeight = '100%';
                miniAvatarImg.style.maxWidth = '100%';
                miniAvatarImg.style.width = '100%';
                miniAvatarImg.style.height = '100%';
                miniAvatarImg.style.filter = 'none';
                miniAvatarImg.style.borderRadius = '50%';
            }
        }
    }

    function setColor(hex) {
        document.getElementById('widget_primary_color').value = hex;
        updateLivePreview();
    }

    function previewUpload(input, imgId, defaultIconId, miniImgId) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var img = document.getElementById(imgId);
                var def = defaultIconId ? document.getElementById(defaultIconId) : null;
                if (img) {
                    img.src = e.target.result;
                    img.style.display = 'block';
                }
                if (def) def.style.display = 'none';

                if (miniImgId) {
                    var miniImg = document.getElementById(miniImgId);
                    var miniDef = document.getElementById('prevMiniAvatarDefault');
                    if (miniImg) {
                        miniImg.src = e.target.result;
                        miniImg.style.display = 'block';
                    }
                    if (miniDef) miniDef.style.display = 'none';
                }

                updateLivePreview();
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    function selectDiscoveredModel(modelName) {
        var input = document.getElementById('model_name');
        input.value = modelName;
        
        // Highlight active item in dropdown
        var items = document.querySelectorAll('#modelsDropdownList .dropdown-item');
        items.forEach(function(el) {
            var isCurrent = el.getAttribute('data-model') === modelName;
            el.classList.toggle('active', isCurrent);
            var check = el.querySelector('.model-check');
            if (check) check.classList.toggle('d-none', !isCurrent);
        });

        var badge = document.getElementById('fetchModelsBadge');
        if (badge) {
            badge.className = 'small fw-semibold text-success';
            badge.innerHTML = '<i class="bi bi-check2-circle"></i> Selected: ' + modelName;
        }
    }

    function fetchModelsFromBaseUrl() {
        var baseUrl = document.getElementById('base_url').value.trim();
        var apiKey = document.getElementById('api_key').value.trim();
        var currentModel = document.getElementById('model_name').value.trim();
        var btn = document.getElementById('btnFetchModels');
        var icon = document.getElementById('iconFetch');
        var text = document.getElementById('textFetch');
        var badge = document.getElementById('fetchModelsBadge');
        var dropdownList = document.getElementById('modelsDropdownList');
        var testConnResult = document.getElementById('testConnResult');

        if (!baseUrl) {
            badge.className = 'small fw-semibold text-danger';
            badge.innerHTML = '<i class="bi bi-exclamation-circle"></i> Base URL is required';
            return;
        }

        btn.disabled = true;
        icon.className = 'spinner-border spinner-border-sm';
        text.textContent = 'Fetching...';
        badge.className = 'small fw-semibold text-secondary';
        badge.innerHTML = '<i class="bi bi-hourglass-split"></i> Querying endpoint...';

        fetch('http://localhost:8000/api/v1/bot/fetch-models', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                base_url: baseUrl,
                api_key: apiKey
            })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            icon.className = 'bi bi-arrow-repeat';
            text.textContent = 'Fetch Models';

            if (data.success && data.models && data.models.length > 0) {
                dropdownList.innerHTML = '';

                var header = document.createElement('li');
                header.innerHTML = '<h6 class="dropdown-header small text-uppercase fw-bold text-muted py-1" style="font-size: 0.68rem;"><i class="bi bi-hdd-network me-1"></i> Available Models (' + data.count + ')</h6>';
                dropdownList.appendChild(header);

                data.models.forEach(function(model) {
                    var isSelected = (model === currentModel) || (!currentModel && data.models.indexOf(model) === 0);
                    var li = document.createElement('li');
                    li.innerHTML = '<a class="dropdown-item font-monospace small d-flex align-items-center justify-content-between py-1.5 ' + (isSelected ? 'active' : '') + '" href="javascript:void(0)" data-model="' + model + '" onclick="selectDiscoveredModel(\'' + model + '\')">' +
                        '<span>' + model + '</span>' +
                        '<i class="bi bi-check2 model-check ' + (isSelected ? '' : 'd-none') + '"></i>' +
                    '</a>';
                    dropdownList.appendChild(li);
                });

                if (!currentModel || currentModel === 'llama3.2') {
                    selectDiscoveredModel(data.models[0]);
                } else if (data.models.includes(currentModel)) {
                    selectDiscoveredModel(currentModel);
                }

                badge.className = 'small fw-semibold text-success';
                badge.innerHTML = '<i class="bi bi-check-circle-fill"></i> ' + data.count + ' model(s) found';

                if (testConnResult) {
                    testConnResult.className = 'small fw-medium text-success';
                    testConnResult.innerHTML = '✅ Base URL connected (' + data.count + ' model(s) available)';
                }

                var toggleBtn = document.getElementById('btnModelDropdownToggle');
                var bsDropdown = bootstrap.Dropdown.getOrCreateInstance(toggleBtn);
                bsDropdown.show();
            } else {
                badge.className = 'small fw-semibold text-danger';
                badge.innerHTML = '❌ ' + (data.message || 'No models returned');

                dropdownList.innerHTML = '<li><span class="dropdown-item-text text-danger small"><i class="bi bi-exclamation-triangle me-1"></i> ' + (data.message || 'No models found') + '</span></li>';

                if (testConnResult) {
                    testConnResult.className = 'small fw-medium text-danger';
                    testConnResult.innerHTML = '❌ ' + (data.message || 'Connection failed');
                }
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            icon.className = 'bi bi-arrow-repeat';
            text.textContent = 'Fetch Models';

            badge.className = 'small fw-semibold text-danger';
            badge.innerHTML = '❌ FastAPI engine unreachable';

            dropdownList.innerHTML = '<li><span class="dropdown-item-text text-danger small"><i class="bi bi-x-circle me-1"></i> FastAPI engine unreachable</span></li>';

            if (testConnResult) {
                testConnResult.className = 'small fw-medium text-danger';
                testConnResult.textContent = '❌ FastAPI engine on port 8000 unreachable';
            }
        });
    }

    function applyProviderPreset(type) {
        if (type === 'ollama') {
            document.getElementById('base_url').value = 'http://localhost:11434/v1';
            document.getElementById('api_key').value = '';
            fetchModelsFromBaseUrl();
        } else {
            document.getElementById('base_url').value = 'https://api.openai.com/v1';
            document.getElementById('model_name').value = 'gpt-4o-mini';
            var badge = document.getElementById('fetchModelsBadge');
            if (badge) {
                badge.className = 'small fw-semibold text-secondary';
                badge.innerHTML = 'Enter API key & click Fetch Models';
            }
        }
    }

    function setPromptPreset(type) {
        var el = document.getElementById('system_prompt');
        if (type === 'support') {
            el.value = "You are a professional customer support AI assistant. Answer user questions clearly, politely, and concisely.";
        } else if (type === 'sales') {
            el.value = "You are an enthusiastic and knowledgeable sales concierge. Help customers find products and guide them through features and pricing.";
        } else if (type === 'technical') {
            el.value = "You are an expert technical support engineer. Provide step-by-step diagnostic instructions and clean code snippets.";
        }
    }

    function testConnection() {
        var baseUrl = document.getElementById('base_url').value.trim();
        var apiKey = document.getElementById('api_key').value.trim();
        var modelName = document.getElementById('model_name').value.trim();
        var statusEl = document.getElementById('testConnResult');
        var btn = document.getElementById('btnTestConn');

        if (!baseUrl) {
            statusEl.className = 'small fw-medium text-danger';
            statusEl.textContent = '❌ Base URL is required';
            return;
        }

        if (!modelName) {
            statusEl.className = 'small fw-medium text-warning text-dark';
            statusEl.textContent = '⚠️ Please select or fetch a Model Name first.';
            return;
        }

        statusEl.className = 'small fw-medium text-secondary';
        statusEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing inference response (model may be loading)...';
        btn.disabled = true;

        fetch('http://localhost:8000/api/v1/bot/test-connection', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                base_url: baseUrl,
                api_key: apiKey,
                model_name: modelName
            })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                statusEl.className = 'small fw-medium text-success';
                statusEl.textContent = '✅ ' + data.message;
            } else {
                statusEl.className = 'small fw-medium text-danger';
                statusEl.textContent = '❌ ' + data.message;
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            statusEl.className = 'small fw-medium text-danger';
            statusEl.textContent = '❌ Could not connect to FastAPI engine on port 8000';
        });
    }

    // Dynamic Thinking Indicator rotation for live preview demo
    var thinkingWords = ["Thinking...", "Analyzing...", "Drafting response...", "Almost ready..."];
    var thinkingIndex = 0;
    setInterval(function() {
        var el = document.getElementById('prevThinkingText');
        if (el) {
            thinkingIndex = (thinkingIndex + 1) % thinkingWords.length;
            el.textContent = thinkingWords[thinkingIndex];
        }
    }, 2400);

    document.addEventListener('DOMContentLoaded', function() {
        updateLivePreview();
    });
</script>
@endpush
@endsection
