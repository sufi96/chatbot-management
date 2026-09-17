@extends('layouts.app')

@section('page-title', $isEdit ? 'Edit bot profile' : 'New bot profile')

@section('content')
@php
    $snippet = $isEdit
        ? "<script\n"
            . "  src=\"{$apiHost}/widget.js\"\n"
            . "  data-bot-id=\"{$bot->id}\"\n"
            . "  data-api-host=\"{$apiHost}\"\n"
            . "  defer>\n"
            . "</script>"
        : null;
@endphp

<div class="page-head mb-3">
    <div>
        <a href="{{ route('bots.index') }}" class="d-inline-flex align-items-center gap-1.5 mb-2" style="font-size: 0.8125rem;">
            <i class="bi bi-arrow-left"></i> Bot profiles
        </a>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <h1 class="mb-0">{{ $isEdit ? $bot->name : 'New bot profile' }}</h1>
            @if($isEdit)
                @if($bot->is_active)
                    <span class="d-inline-flex align-items-center gap-1.5" style="color: var(--ok); font-size: 0.78125rem;">
                        <span class="state-dot is-live"></span> Online
                    </span>
                @else
                    <span class="d-inline-flex align-items-center gap-1.5" style="color: var(--danger); font-size: 0.78125rem;">
                        <span class="state-dot" style="background: var(--danger);"></span> Offline
                    </span>
                @endif
            @endif
        </div>
        <p class="mt-1">
            {{ $isEdit ? 'Changes apply to every site running this bot as soon as you save.' : 'Configuring in workspace ' . $activeSystem->name . '.' }}
        </p>
    </div>
</div>

@if($isEdit)
    @include('bots._tabs')
@endif

<form action="{{ $isEdit ? route('bots.update', $bot->id) : route('bots.store') }}" method="POST" enctype="multipart/form-data" id="botForm">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    {{-- A grid rather than a row so the power card can sit above the
         preview on wide screens and above the settings on narrow ones,
         without rendering it twice. --}}
    <div class="bot-editor">

        {{-- Power: the one control that decides whether the bot answers at
             all, so it gets its own card above the preview instead of a small
             switch. It is a form field like the rest and applies on save.
             Not sticky: only the preview follows the scroll. --}}
        @php $botOn = (bool) old('is_active', $isEdit ? $bot->is_active : true); @endphp
        <div class="card bot-power bot-editor-power {{ $botOn ? 'is-on' : 'is-off' }}" id="botPower"
             data-saved="{{ $botOn ? '1' : '0' }}">
            <div class="p-3 h-100 d-flex flex-column justify-content-center gap-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="bot-power-icon"><i class="bi bi-power"></i></span>
                    <div>
                        <div class="bot-power-title">
                            Bot is <span data-power-label>{{ $botOn ? 'Online' : 'Offline' }}</span>
                        </div>
                        <div class="bot-power-desc" data-power-desc>
                            {{ $botOn ? 'Accepting live conversations through the widget and API.' : 'The widget and API will not accept conversations.' }}
                        </div>
                        <div class="bot-power-pending" data-power-pending hidden>
                            <i class="bi bi-exclamation-circle"></i> Not saved yet. Save changes to apply.
                        </div>
                    </div>
                </div>

                <div class="bot-power-toggle" role="radiogroup" aria-label="Bot power">
                    <input type="radio" class="visually-hidden" name="is_active" value="1" id="is_active_on" {{ $botOn ? 'checked' : '' }}>
                    <label for="is_active_on" class="bot-power-opt is-opt-on">
                        <i class="bi bi-play-fill"></i> On
                    </label>
                    <input type="radio" class="visually-hidden" name="is_active" value="0" id="is_active_off" {{ $botOn ? '' : 'checked' }}>
                    <label for="is_active_off" class="bot-power-opt is-opt-off">
                        <i class="bi bi-pause-fill"></i> Off
                    </label>
                </div>
            </div>
        </div>

        {{-- Identity shares the first row with the power card, so the two
             stretch to one height. --}}
        <div class="card bot-editor-identity">
            <div class="card-header">Identity</div>
            <div class="p-3">
                <div>
                    <label for="input_name" class="form-label">
                        Profile name <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="text" name="name" id="input_name" class="form-control"
                           value="{{ old('name', $bot->name) }}"
                           placeholder="Sales concierge, Support assistant, Billing triage" required>
                </div>
            </div>
        </div>

        {{-- ===================== Settings column ===================== --}}
        <div class="bot-editor-main">

            {{-- Model and endpoint --}}
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-2">
                    <span>Model and endpoint</span>
                    <button type="button" onclick="testConnection()" id="btnTestConn" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-plug"></i> Test inference
                    </button>
                </div>
                <div class="p-3">
                    {{-- The test's outcome. The button lives in the header, and
                         this line only takes space once there is something to say. --}}
                    <div id="testConnResult" class="test-conn-result"></div>

                    <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                        <label for="providerTrigger" class="form-label mb-0">
                            Provider <span style="color: var(--danger);">*</span>
                        </label>
                        <button type="button" class="btn btn-sm provider-new" onclick="openProviderModal('new')">
                            <i class="bi bi-plus-lg"></i> New provider
                        </button>
                    </div>
                    {{-- The select is what the form submits and what the script reads;
                         the picker is how it is shown: the name on one line, then whose
                         it is, the URL, the key and the bots on it, wrapping rather than
                         cutting anything off. --}}
                    <div class="provider-field">
                        <div class="dropdown provider-picker">
                            <button type="button" class="provider-trigger" id="providerTrigger"
                                    data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="listbox"
                                    @if($providers->isEmpty()) disabled @endif>
                                <span class="provider-trigger-body" id="providerTriggerBody"></span>
                                <i class="bi bi-chevron-expand provider-trigger-caret"></i>
                            </button>
                            <div class="dropdown-menu provider-menu">
                                <div class="model-menu-filter" id="providerFilterWrap" hidden>
                                    <input type="search" class="form-control form-control-sm" id="providerFilter"
                                           placeholder="Filter by name, URL or workspace" aria-label="Filter providers"
                                           autocomplete="off">
                                </div>
                                <div class="provider-menu-list" id="providerMenuList" role="listbox"></div>
                            </div>
                        </div>
                        <select name="provider_id" id="provider_id" class="visually-hidden" tabindex="-1" aria-hidden="true"
                                onchange="onProviderChange()"
                                @if($providers->isEmpty()) disabled @endif>
                            @if($providers->isEmpty())
                                <option value="">No providers yet — add one</option>
                            @endif
                            {{-- Grouped by owner, and the owner repeated in each label so a
                                 closed select still tells two same-named endpoints apart.
                                 A locked entry is one a super admin set that this user
                                 cannot pick, so it carries no URL. No entry carries its key:
                                 only whether it has one. --}}
                            @foreach($providers->groupBy(fn ($p) => $p->system_id ?? '') as $ownerId => $group)
                                <optgroup label="{{ $group->first()->ownerName() }}" data-system-id="{{ $ownerId }}">
                                    @foreach($group as $provider)
                                        @php
                                            $locked = (bool) $provider->getAttribute('locked');
                                            $editable = !$locked && $provider->system_id !== null;
                                        @endphp
                                        <option value="{{ $provider->id }}"
                                                data-name="{{ $provider->name }}"
                                                data-owner="{{ $provider->ownerName() }}"
                                                data-editable="{{ $editable ? '1' : '0' }}"
                                                data-own="{{ $provider->system_id === $providerSystemId ? '1' : '0' }}"
                                                data-scope="{{ $provider->system_id === null ? 'platform' : ($provider->system_id === $providerSystemId ? 'own' : 'other') }}"
                                                data-bots="{{ $provider->bots_count ?? 0 }}"
                                                @if($locked)
                                                    data-locked="1"
                                                @else
                                                    data-base-url="{{ $provider->base_url }}"
                                                    data-has-key="{{ $provider->api_key ? '1' : '0' }}"
                                                @endif
                                                @selected(old('provider_id', $bot->provider_id) === $provider->id)>
                                            {{ $locked ? $provider->name : $provider->label() }} · {{ $provider->ownerName() }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        {{-- Acts on the selected provider. One this user cannot change
                             shows why instead of two dead buttons. --}}
                        <div class="provider-toolbar">
                            <div class="provider-toolbar-actions" id="providerActions">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="btnEditProvider"
                                        onclick="openProviderModal('edit')">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger" id="btnDeleteProvider"
                                        onclick="deleteProvider()">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                            <span class="provider-toolbar-note" id="providerLockedNote" hidden>
                                <i class="bi bi-lock"></i> Managed in Admin Settings
                            </span>
                        </div>
                    </div>
                    <div class="form-text mb-3" id="providerHint">
                        One saved endpoint, shared by every bot pointing at it. Edit it once when the
                        machine or the key changes.
                    </div>

                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-1">
                            <label for="model_name" class="form-label mb-0">
                                Model <span style="color: var(--danger);">*</span>
                            </label>
                            <span id="fetchModelsBadge" style="font-size: 0.6875rem;"></span>
                        </div>
                        <div class="input-group">
                            <input type="text" name="model_name" id="model_name" class="form-control font-monospace"
                                   value="{{ old('model_name', $bot->model_name) }}"
                                   placeholder="llama3.2" required>
                            <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split"
                                    data-bs-toggle="dropdown" aria-expanded="false" id="btnModelDropdownToggle"
                                    title="Pick a discovered model">
                                <span class="visually-hidden">Show discovered models</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" id="modelsDropdownList"
                                style="max-height: 260px; overflow-y: auto; min-width: 250px;">
                                <li><span class="dropdown-item-text text-muted" style="font-size: 0.78125rem;">Fetch models to load the list</span></li>
                            </ul>
                            <button type="button" class="btn btn-brand" id="btnFetchModels"
                                    onclick="fetchModelsFromBaseUrl()" title="Query the endpoint for available models">
                                <i class="bi bi-arrow-repeat" id="iconFetch"></i>
                                <span id="textFetch">Fetch models</span>
                            </button>
                        </div>
                        <div class="form-text">Queries the base URL and confirms the endpoint answers.</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            {{-- 0 to 1, the range where a change is felt. A value saved
                                 above 1 before the cap shows at 1 and saves as 1. --}}
                            @php $temperature = min(1, max(0, (float) old('temperature', $bot->temperature ?? 0.7))); @endphp
                            <label for="temperature" class="form-label d-flex align-items-center justify-content-between mb-1">
                                <span>Temperature</span>
                                <span class="figure-mono text-muted" id="temperatureOut">{{ number_format($temperature, 2) }}</span>
                            </label>
                            <input type="range" class="form-range" name="temperature" id="temperature"
                                   min="0" max="1" step="0.05" value="{{ $temperature }}"
                                   oninput="document.getElementById('temperatureOut').textContent = Number(this.value).toFixed(2)">
                            <div class="form-text d-flex justify-content-between">
                                <span>Predictable</span><span>Creative</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <label for="max_tokens" class="form-label">Max tokens</label>
                            <input type="number" step="64" min="64" max="8192" name="max_tokens" id="max_tokens"
                                   class="form-control font-monospace" value="{{ old('max_tokens', $bot->max_tokens) }}">
                            <div class="form-text">Ceiling on one reply.</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Appearance --}}
            <div class="card mb-3">
                <div class="card-header">Widget appearance</div>
                <div class="p-3">

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6">
                            <label for="widget_title" class="form-label">Header title</label>
                            <input type="text" name="widget_title" id="widget_title" class="form-control"
                                   value="{{ old('widget_title', $bot->widget_title) }}" oninput="updateLivePreview()" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="widget_position" class="form-label">Screen position</label>
                            <select name="widget_position" id="widget_position" class="form-select">
                                <option value="bottom-right" {{ old('widget_position', $bot->widget_position) === 'bottom-right' ? 'selected' : '' }}>Bottom right</option>
                                <option value="bottom-left" {{ old('widget_position', $bot->widget_position) === 'bottom-left' ? 'selected' : '' }}>Bottom left</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="widget_greeting" class="form-label">Opening greeting</label>
                        <input type="text" name="widget_greeting" id="widget_greeting" class="form-control"
                               value="{{ old('widget_greeting', $bot->widget_greeting) }}" oninput="updateLivePreview()">
                    </div>

                    <div class="mb-3">
                        <label for="widget_primary_color" class="form-label">Widget colour</label>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <input type="color" name="widget_primary_color" id="widget_primary_color" class="form-control"
                                   value="{{ old('widget_primary_color', $bot->widget_primary_color ?: '#1f2937') }}"
                                   oninput="updateLivePreview()" style="width: 52px;">
                            @foreach(['#1f2937' => 'Graphite', '#0ea5e9' => 'Sky', '#10b981' => 'Emerald', '#e0a03a' => 'Amber', '#dc5b4a' => 'Rust'] as $hex => $label)
                                <button type="button" onclick="setColor('{{ $hex }}')" title="{{ $label }}"
                                        style="width: 26px; height: 26px; padding: 0; background-color: {{ $hex }};
                                               border: 1px solid var(--border-strong); border-radius: var(--r-sm);"></button>
                            @endforeach
                        </div>
                        <div class="form-text">Used for the launcher, the header and outgoing message bubbles.</div>
                    </div>

                    <div class="row g-3" style="border-top: 1px solid var(--border); padding-top: 1rem;">

                        {{-- Launcher --}}
                        <div class="col-12 col-md-6">
                            <div class="fw-semibold mb-1" style="font-size: 0.8125rem;">Launcher button</div>
                            <p class="text-muted mb-2" style="font-size: 0.75rem;">The floating button before anyone opens the chat.</p>

                            <label for="launcher_icon_input" class="visually-hidden">Launcher image</label>
                            <input type="file" name="launcher_icon" id="launcher_icon_input" accept="image/*"
                                   class="form-control form-control-sm"
                                   onchange="previewUpload(this, 'prevLauncherImg', 'prevLauncherDefault')">

                            @if($bot->launcher_icon_url)
                                <div class="mt-2 d-flex align-items-center gap-2 p-2"
                                     style="border: 1px solid var(--border); border-radius: var(--r-sm);">
                                    <img src="{{ $bot->launcher_icon_url }}" alt="Current launcher image" class="identity">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="remove_launcher_icon" value="1" id="remove_launcher_icon">
                                        <label class="form-check-label" for="remove_launcher_icon" style="font-size: 0.75rem;">Remove and use the default</label>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-3">
                                <div class="text-muted mb-1.5" style="font-size: 0.75rem;">Shape</div>
                                <div class="d-flex flex-column gap-1.5">
                                    <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                        <input type="radio" name="launcher_shape" id="launcher_shape_circle" value="circle"
                                               {{ old('launcher_shape', $bot->launcher_shape ?? 'circle') === 'circle' ? 'checked' : '' }}
                                               onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                        <span>
                                            <span class="d-block fw-semibold" style="font-size: 0.78125rem;">Filled circle</span>
                                            <span class="text-muted" style="font-size: 0.6875rem;">Solid colour behind the icon</span>
                                        </span>
                                    </label>

                                    <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                        <input type="radio" name="launcher_shape" id="launcher_shape_circle_transparent" value="circle_transparent"
                                               {{ old('launcher_shape', $bot->launcher_shape ?? 'circle') === 'circle_transparent' ? 'checked' : '' }}
                                               onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                        <span>
                                            <span class="d-block fw-semibold" style="font-size: 0.78125rem;">Outlined circle</span>
                                            <span class="text-muted" style="font-size: 0.6875rem;">Ring border, transparent inside</span>
                                        </span>
                                    </label>

                                    <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                        <input type="radio" name="launcher_shape" id="launcher_shape_transparent_fit" value="transparent_fit"
                                               {{ old('launcher_shape', $bot->launcher_shape ?? 'circle') === 'transparent_fit' ? 'checked' : '' }}
                                               onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                        <span>
                                            <span class="d-block fw-semibold" style="font-size: 0.78125rem;">Cutout silhouette</span>
                                            <span class="text-muted" style="font-size: 0.6875rem;">Follows the PNG outline, no container</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="mt-3">
                                <label for="launcher_size" class="form-label d-flex align-items-center justify-content-between mb-1">
                                    <span>Size</span>
                                    <span class="figure-mono text-muted" id="launcherSizeOut">{{ old('launcher_size', $bot->launcher_size ?? 60) }}px</span>
                                </label>
                                <input type="range" class="form-range" name="launcher_size" id="launcher_size"
                                       min="40" max="160" step="4"
                                       value="{{ old('launcher_size', $bot->launcher_size ?? 60) }}"
                                       oninput="updateLivePreview()">
                                <div class="form-text">
                                    Height of the button. A cutout image is given this height and may run up to 1.4 times as wide,
                                    so a tall picture of a person stays tall instead of shrinking to fit a square.
                                </div>
                            </div>
                        </div>

                        {{-- Close button --}}
                        <div class="col-12 col-md-6">
                            <div class="fw-semibold mb-1" style="font-size: 0.8125rem;">Close button</div>
                            <p class="text-muted mb-2" style="font-size: 0.75rem;">The same button once the chat is open. Leave it empty for a plain cross.</p>

                            <label for="close_icon_input" class="visually-hidden">Close image</label>
                            <input type="file" name="close_icon" id="close_icon_input" accept="image/*"
                                   class="form-control form-control-sm"
                                   onchange="previewUpload(this, 'prevCloseImg', 'prevCloseDefault')">

                            @if($bot->close_icon_url)
                                <div class="mt-2 d-flex align-items-center gap-2 p-2"
                                     style="border: 1px solid var(--border); border-radius: var(--r-sm);">
                                    <img src="{{ $bot->close_icon_url }}" alt="Current close image" class="identity">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="remove_close_icon" value="1" id="remove_close_icon">
                                        <label class="form-check-label" for="remove_close_icon" style="font-size: 0.75rem;">Remove and use the default</label>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-3">
                                <div class="text-muted mb-1.5" style="font-size: 0.75rem;">Shape</div>
                                <div class="d-flex flex-column gap-1.5">
                                    <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                        <input type="radio" name="close_shape" id="close_shape_circle" value="circle"
                                               {{ old('close_shape', $bot->close_shape ?? 'circle') === 'circle' ? 'checked' : '' }}
                                               onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                        <span>
                                            <span class="d-block fw-semibold" style="font-size: 0.78125rem;">Filled circle</span>
                                            <span class="text-muted" style="font-size: 0.6875rem;">Solid colour behind the icon</span>
                                        </span>
                                    </label>

                                    <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                        <input type="radio" name="close_shape" id="close_shape_circle_transparent" value="circle_transparent"
                                               {{ old('close_shape', $bot->close_shape ?? 'circle') === 'circle_transparent' ? 'checked' : '' }}
                                               onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                        <span>
                                            <span class="d-block fw-semibold" style="font-size: 0.78125rem;">Outlined circle</span>
                                            <span class="text-muted" style="font-size: 0.6875rem;">Ring border, transparent inside</span>
                                        </span>
                                    </label>

                                    <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                        <input type="radio" name="close_shape" id="close_shape_transparent_fit" value="transparent_fit"
                                               {{ old('close_shape', $bot->close_shape ?? 'circle') === 'transparent_fit' ? 'checked' : '' }}
                                               onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                        <span>
                                            <span class="d-block fw-semibold" style="font-size: 0.78125rem;">Cutout silhouette</span>
                                            <span class="text-muted" style="font-size: 0.6875rem;">Follows the PNG outline, no container</span>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="mt-3">
                                <label for="close_size" class="form-label d-flex align-items-center justify-content-between mb-1">
                                    <span>Size</span>
                                    <span class="figure-mono text-muted" id="closeSizeOut">{{ old('close_size', $bot->close_size ?? 52) }}px</span>
                                </label>
                                <input type="range" class="form-range" name="close_size" id="close_size"
                                       min="32" max="120" step="4"
                                       value="{{ old('close_size', $bot->close_size ?? 52) }}"
                                       oninput="updateLivePreview()">
                                <div class="form-text">Usually a little smaller than the launcher, so closing feels lighter than opening.</div>
                            </div>
                        </div>

                        {{-- Avatar --}}
                        <div class="col-12 col-md-6">
                            <div class="fw-semibold mb-1" style="font-size: 0.8125rem;">Chat avatar</div>
                            <p class="text-muted mb-2" style="font-size: 0.75rem;">Shown in the chat header and beside each reply.</p>

                            <label for="bot_avatar_input" class="visually-hidden">Avatar image</label>
                            <input type="file" name="bot_avatar" id="bot_avatar_input" accept="image/*"
                                   class="form-control form-control-sm"
                                   onchange="previewUpload(this, 'prevAvatarImg', 'prevAvatarDefault', 'prevMiniAvatarImg')">

                            @if($bot->bot_avatar_url)
                                <div class="mt-2 d-flex align-items-center gap-2 p-2"
                                     style="border: 1px solid var(--border); border-radius: var(--r-sm);">
                                    <img src="{{ $bot->bot_avatar_url }}" alt="Current avatar" class="identity">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="remove_bot_avatar" value="1" id="remove_bot_avatar">
                                        <label class="form-check-label" for="remove_bot_avatar" style="font-size: 0.75rem;">Remove and use the default</label>
                                    </div>
                                </div>
                            @endif

                            <div class="mt-3">
                                <div class="text-muted mb-1.5" style="font-size: 0.75rem;">Shape</div>
                                <div class="d-flex flex-column gap-1.5">
                                    <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                        <input type="radio" name="avatar_shape" id="avatar_shape_circle" value="circle"
                                               {{ old('avatar_shape', $bot->avatar_shape ?? 'circle') === 'circle' ? 'checked' : '' }}
                                               onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                        <span>
                                            <span class="d-block fw-semibold" style="font-size: 0.78125rem;">Circle badge</span>
                                            <span class="text-muted" style="font-size: 0.6875rem;">Cropped to a circular frame</span>
                                        </span>
                                    </label>

                                    <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                        <input type="radio" name="avatar_shape" id="avatar_shape_circle_transparent" value="circle_transparent"
                                               {{ old('avatar_shape', $bot->avatar_shape ?? 'circle') === 'circle_transparent' ? 'checked' : '' }}
                                               onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                        <span>
                                            <span class="d-block fw-semibold" style="font-size: 0.78125rem;">Outlined circle</span>
                                            <span class="text-muted" style="font-size: 0.6875rem;">Hairline border, no fill</span>
                                        </span>
                                    </label>

                                    <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                        <input type="radio" name="avatar_shape" id="avatar_shape_transparent_fit" value="transparent_fit"
                                               {{ old('avatar_shape', $bot->avatar_shape ?? 'circle') === 'transparent_fit' ? 'checked' : '' }}
                                               onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                        <span>
                                            <span class="d-block fw-semibold" style="font-size: 0.78125rem;">Cutout silhouette</span>
                                            <span class="text-muted" style="font-size: 0.6875rem;">Tall or wide art without cropping</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            {{-- Danger zone. Forms cannot nest, so the button submits
                 botDeleteForm, which sits after this form, through form="". --}}
            @if($isEdit && auth()->user()->canManageSystem($bot->system_id, 'system_admin'))
                <div class="card mb-3 danger-zone">
                    <div class="card-header">Delete bot profile</div>
                    <div class="p-3 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                        <p class="mb-0" style="font-size: 0.8125rem;">
                            It stops answering on every site and leaves this workspace. Its
                            conversations are kept, and only a super admin can restore it.
                        </p>
                        <button type="submit" form="botDeleteForm" class="btn btn-danger flex-shrink-0"
                                data-confirm="Delete this bot profile?"
                                data-confirm-subject="{{ $bot->name }}"
                                data-confirm-message="It stops answering on every site and leaves this workspace. Its conversations are kept, and only a super admin can restore it."
                                data-confirm-type="{{ $bot->name }}"
                                data-confirm-label="Delete bot">
                            <i class="bi bi-trash3"></i> Delete bot profile
                        </button>
                    </div>
                </div>
            @endif

            {{-- Sticky action bar: the form is long, so Save follows you down it.
                 A new bot has nothing to compare against, so its bar always shows. --}}
            @include('bots._save-bar', [
                'formId' => 'botForm',
                'saveLabel' => $isEdit ? 'Save Profile changes' : 'Create bot profile',
                'track' => $isEdit,
                'hint' => 'You can change all of this later.',
            ])
        </div>

        {{-- ===================== Output column =====================
             Preview and embed are both read-only outputs, so tabbing them is
             safe: no required input ever ends up in a hidden pane.
             ========================================================== --}}
        <div class="bot-editor-output">
            <div class="sticky-top" style="top: 72px; z-index: 10;">
                <div class="card overflow-hidden">
                    <div class="card-header p-0">
                        <ul class="nav nav-tabs px-2 pt-2" role="tablist" style="border-bottom: 0;">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-preview"
                                        type="button" role="tab" aria-controls="pane-preview" aria-selected="true">Preview</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-embed"
                                        type="button" role="tab" aria-controls="pane-embed" aria-selected="false">Embed</button>
                            </li>
                        </ul>
                    </div>

                    <div class="tab-content">

                        {{-- Preview: reflects what you are typing, before you save.
                             It renders the customer-facing widget, so it keeps the
                             bot's own brand colour rather than the console palette. --}}
                        <div class="tab-pane fade show active" id="pane-preview" role="tabpanel">
                            <div class="d-flex flex-column" style="height: 420px; background: #f4f4f5;">
                                <div id="prevHeader" class="p-3 d-flex align-items-center justify-content-between flex-shrink-0"
                                     style="background: {{ $bot->widget_primary_color ?: '#1f2937' }}; color: #ffffff;">
                                    <div class="d-flex align-items-center gap-2.5">
                                        <div id="prevAvatarContainer"
                                             class="d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0"
                                             style="width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.22);">
                                            <img id="prevAvatarImg" src="{{ $bot->bot_avatar_url ?: '' }}" alt=""
                                                 style="{{ $bot->bot_avatar_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;">
                                            <i id="prevAvatarDefault" class="bi bi-robot"
                                               style="{{ $bot->bot_avatar_url ? 'display:none;' : 'display:block;' }} font-size: 1rem; color: #ffffff;"></i>
                                        </div>
                                        <div>
                                            <div id="prevTitle" class="fw-semibold" style="font-size: 0.8125rem; line-height: 1.2;">{{ $bot->widget_title ?: 'AI Assistant' }}</div>
                                            <div style="font-size: 0.6875rem; opacity: 0.75;">Online</div>
                                        </div>
                                    </div>
                                    <i class="bi bi-x-lg" style="font-size: 0.8rem; opacity: 0.7;"></i>
                                </div>

                                <div class="p-3 flex-grow-1 overflow-auto d-flex flex-column gap-2.5">
                                    <div class="d-flex align-items-start gap-2" style="max-width: 88%;">
                                        <div id="prevMiniAvatar"
                                             class="d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0"
                                             style="width: 24px; height: 24px; border-radius: 50%; margin-top: 2px; color: #fff; background-color: {{ $bot->widget_primary_color ?: '#1f2937' }};">
                                            <img id="prevMiniAvatarImg" src="{{ $bot->bot_avatar_url ?: '' }}" alt=""
                                                 style="{{ $bot->bot_avatar_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;">
                                            <i id="prevMiniAvatarDefault" class="bi bi-robot"
                                               style="{{ $bot->bot_avatar_url ? 'display:none;' : 'display:block;' }} font-size: 0.7rem;"></i>
                                        </div>
                                        <div id="prevGreeting"
                                             style="background: #ffffff; color: #18181b; border: 1px solid #e4e4e7; border-radius: 10px 10px 10px 3px; padding: 0.5rem 0.75rem; font-size: 0.78125rem; line-height: 1.5;">
                                            {{ $bot->widget_greeting ?: 'Hello! How can I help you today?' }}
                                        </div>
                                    </div>

                                    <div class="align-self-end" style="max-width: 82%;">
                                        <div id="prevUserMsg"
                                             style="background-color: {{ $bot->widget_primary_color ?: '#1f2937' }}; color: #ffffff; border-radius: 10px 10px 3px 10px; padding: 0.5rem 0.75rem; font-size: 0.78125rem; line-height: 1.5;">
                                            Can you tell me more about your pricing?
                                        </div>
                                    </div>

                                    <div class="d-flex align-items-center gap-2" style="max-width: 88%;">
                                        <div style="width: 24px; height: 24px; flex-shrink: 0;"></div>
                                        <div style="background: #ffffff; color: #71717a; border: 1px solid #e4e4e7; border-radius: 10px 10px 10px 3px; padding: 0.4375rem 0.75rem; font-size: 0.75rem;">
                                            <span id="prevThinkingText">Thinking...</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="p-2 d-flex align-items-center gap-2 flex-shrink-0"
                                     style="background: #ffffff; border-top: 1px solid #e4e4e7;">
                                    <input type="text" aria-label="Message preview" disabled placeholder="Type a message"
                                           style="flex: 1; min-width: 0; background: #f4f4f5; color: #71717a; border: 1px solid #e4e4e7; border-radius: 6px; padding: 0.375rem 0.625rem; font-size: 0.78125rem;">
                                    <button type="button" id="prevSendBtn" disabled
                                            style="border: none; border-radius: 6px; padding: 0.375rem 0.625rem; color: #fff; background-color: {{ $bot->widget_primary_color ?: '#1f2937' }};">
                                        <i class="bi bi-send" style="font-size: 0.75rem;"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="p-3" style="border-top: 1px solid var(--border);">
                                <div class="d-flex align-items-end gap-4" style="min-height: 96px;">
                                    <div class="text-center">
                                        <div class="d-flex align-items-end justify-content-center" style="min-height: 72px;">
                                            <div id="prevLauncherBtn"
                                                 class="d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0"
                                                 style="width: 48px; height: 48px; border-radius: 50%; color: #fff; background-color: {{ $bot->widget_primary_color ?: '#1f2937' }};">
                                                <img id="prevLauncherImg" src="{{ $bot->launcher_icon_url ?: '' }}" alt=""
                                                     style="{{ $bot->launcher_icon_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;">
                                                <i id="prevLauncherDefault" class="bi bi-chat-dots"
                                                   style="{{ $bot->launcher_icon_url ? 'display:none;' : 'display:block;' }} font-size: 1.05rem;"></i>
                                            </div>
                                        </div>
                                        <div class="text-muted mt-2" style="font-size: 0.6875rem;">Closed</div>
                                    </div>

                                    <div class="text-center">
                                        <div class="d-flex align-items-end justify-content-center" style="min-height: 72px;">
                                            <div id="prevCloseBtn"
                                                 class="d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0"
                                                 style="width: 42px; height: 42px; border-radius: 50%; color: #fff; background-color: {{ $bot->widget_primary_color ?: '#1f2937' }};">
                                                <img id="prevCloseImg" src="{{ $bot->close_icon_url ?: '' }}" alt=""
                                                     style="{{ $bot->close_icon_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;">
                                                <i id="prevCloseDefault" class="bi bi-x-lg"
                                                   style="{{ $bot->close_icon_url ? 'display:none;' : 'display:block;' }} font-size: 0.95rem;"></i>
                                            </div>
                                        </div>
                                        <div class="text-muted mt-2" style="font-size: 0.6875rem;">Open</div>
                                    </div>

                                    <p class="text-muted mb-0 align-self-center" style="font-size: 0.75rem;">
                                        The two states of the corner button, at the sizes you set. This follows the fields on the left, before you save.
                                    </p>
                                </div>
                            </div>
                        </div>

                        {{-- Embed --}}
                        <div class="tab-pane fade" id="pane-embed" role="tabpanel">
                            @if($isEdit)
                                <div class="p-3">
                                    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                        <span class="form-label mb-0">Snippet</span>
                                        <button type="button" class="btn btn-sm btn-brand" onclick="copyEmbedSnippet('{{ $bot->id }}')">
                                            <i class="bi bi-clipboard" id="embedCopyIcon{{ $bot->id }}"></i>
                                            <span id="embedCopyText{{ $bot->id }}">Copy</span>
                                        </button>
                                    </div>

                                    <pre class="code-block mb-3" id="embedSnippet{{ $bot->id }}">{{ $snippet }}</pre>

                                    <div class="fw-semibold mb-2" style="font-size: 0.8125rem;">How to use it</div>
                                    <ol class="ps-3 mb-3" style="font-size: 0.78125rem; line-height: 1.6;">
                                        <li class="mb-1">Copy the snippet.</li>
                                        <li class="mb-1">Paste it before the closing <code>&lt;/body&gt;</code> tag of your site.</li>
                                        <li class="mb-1">Reload. The launcher appears in the corner you chose.</li>
                                    </ol>

                                    <div class="mb-3">
                                        <div class="kv">
                                            <span class="kv-key">Sites allowed to load it</span>
                                            <span class="kv-val">{{ $bot->system->allowed_origins ?? '*' }}</span>
                                        </div>
                                        <div class="kv">
                                            <span class="kv-key">Style isolation</span>
                                            <span class="kv-val">Shadow DOM</span>
                                        </div>
                                    </div>

                                    <div class="p-2.5" style="border: 1px solid var(--border); border-radius: var(--r-sm); background: var(--surface-2);">
                                        <div class="fw-semibold mb-1" style="font-size: 0.78125rem;">This page is running the real widget</div>
                                        <p class="text-muted mb-2" style="font-size: 0.75rem;">
                                            It uses the last saved settings, not the unsaved ones on the left, and talks to the live model.
                                        </p>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="openTestWidget()">
                                            <i class="bi bi-chat-dots"></i> Open test widget
                                        </button>
                                    </div>
                                </div>
                            @else
                                <div class="empty">
                                    <i class="bi bi-code-slash"></i>
                                    <h6>No snippet yet</h6>
                                    <p>Create the profile first. Its embed snippet appears here as soon as it has an ID.</p>
                                </div>
                            @endif
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>
</form>

@if($isEdit && auth()->user()->canManageSystem($bot->system_id, 'system_admin'))
    {{-- confirm_name is filled in by the confirm dialog from what was typed. --}}
    <form action="{{ route('bots.destroy', $bot->id) }}" method="POST" id="botDeleteForm" class="d-none">
        @csrf
        @method('DELETE')
    </form>
@endif

@if($isEdit)
    {{-- The real bot, embedded here so the test widget is the genuine article. --}}
    <script
        src="{{ $apiHost }}/widget.js"
        data-bot-id="{{ $bot->id }}"
        data-api-host="{{ $apiHost }}"
        defer>
    </script>
@endif

{{-- The provider editor. A modal rather than a page of its own, so the half-filled
     bot form behind it survives adding an endpoint. --}}
<div class="modal fade" id="providerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="providerModalTitle">New provider</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="providerEditId">

                <div class="mb-3">
                    <label for="providerName" class="form-label">
                        Name <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="text" id="providerName" class="form-control"
                           placeholder="Office PC" maxlength="255">
                    <div class="form-text">What you will recognise it by in the list.</div>
                </div>

                <div class="mb-3">
                    <label for="providerBaseUrl" class="form-label">
                        Base URL <span style="color: var(--danger);">*</span>
                    </label>
                    <input type="text" id="providerBaseUrl" class="form-control font-monospace"
                           placeholder="http://localhost:11434/v1" maxlength="500">
                </div>

                {{-- The saved key is never sent to the page. On an edit the box starts
                     empty: blank keeps the saved key, a new one replaces it, and
                     removing it is a separate tick. --}}
                <div class="mb-3">
                    <label for="providerApiKey" class="form-label">API key</label>
                    <input type="password" id="providerApiKey" class="form-control font-monospace"
                           placeholder="Not needed for local Ollama" maxlength="500" autocomplete="new-password">
                    <div class="form-text" id="providerApiKeyHelp">Leave blank for a local endpoint.</div>
                    <div class="form-check mt-2" id="providerClearKeyWrap" hidden>
                        <input class="form-check-input" type="checkbox" id="providerClearKey">
                        <label class="form-check-label" for="providerClearKey">Remove the saved key</label>
                    </div>
                </div>

                <div id="providerModalResult" class="small fw-medium"></div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" id="btnTestProvider"
                        onclick="testProviderDraft()">
                    <i class="bi bi-plug"></i> Test
                </button>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-brand" id="btnSaveProvider" onclick="saveProvider()">
                        Save provider
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    // Power card: reflect the chosen state straight away and flag it as
    // unsaved, since it only takes effect when the form is saved.
    (function () {
        var card = document.getElementById('botPower');
        if (!card) return;
        var label = card.querySelector('[data-power-label]');
        var desc = card.querySelector('[data-power-desc]');
        var pending = card.querySelector('[data-power-pending]');

        card.querySelectorAll('input[name="is_active"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var on = radio.value === '1';
                card.classList.toggle('is-on', on);
                card.classList.toggle('is-off', !on);
                label.textContent = on ? 'Online' : 'Offline';
                desc.textContent = on
                    ? 'Accepting live conversations through the widget and API.'
                    : 'The widget and API will not accept conversations.';
                pending.hidden = radio.value === card.dataset.saved;
            });
        });
    })();

    // Opens the real widget that this page embeds, not the mock preview.
    function openTestWidget() {
        var host = document.querySelector('chat-widget');
        var launcher = host && host.shadowRoot ? host.shadowRoot.getElementById('chat-launcher') : null;
        if (launcher) {
            launcher.click();
        } else {
            noticeDialog({
                title: 'The widget is not ready',
                message: 'The widget has not finished loading. Check that the streaming engine on port 8000 is running, then reload.',
            });
        }
    }

    function getSelectedRadioValue(name, defaultValue) {
        var el = document.querySelector('input[name="' + name + '"]:checked');
        return el ? el.value : defaultValue;
    }

    function readRange(id, fallback) {
        var el = document.getElementById(id);
        return el && el.value ? el.value : fallback;
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    /**
     * Draws one corner-button state in the preview.
     *
     * A cutout keeps its natural proportions: it is given the chosen height and
     * allowed up to 1.4x that in width, which is what stops a tall image such as
     * a person from being squashed into a square. The widget applies the same
     * rule, so the preview and the live launcher agree.
     */
    function styleCornerButton(btn, img, defaultIcon, shape, size, color) {
        if (!btn) return;

        if (shape === 'transparent_fit') {
            btn.style.backgroundColor = 'transparent';
            btn.style.boxShadow = 'none';
            btn.style.borderRadius = '0';
            btn.style.width = 'auto';
            btn.style.height = 'auto';
            btn.style.overflow = 'visible';
            btn.style.border = 'none';
            if (img) {
                img.style.maxHeight = size + 'px';
                img.style.maxWidth = Math.round(size * 1.4) + 'px';
                img.style.width = 'auto';
                img.style.height = 'auto';
                img.style.borderRadius = '0';
                img.style.filter = 'drop-shadow(0 4px 12px rgba(0,0,0,0.25))';
            }
            if (defaultIcon) {
                defaultIcon.style.color = color;
                defaultIcon.style.fontSize = Math.round(size * 0.45) + 'px';
            }
            return;
        }

        btn.style.width = size + 'px';
        btn.style.height = size + 'px';
        btn.style.borderRadius = '50%';
        btn.style.overflow = 'hidden';

        if (shape === 'circle_transparent') {
            btn.style.backgroundColor = 'transparent';
            btn.style.boxShadow = '0 4px 14px rgba(15,23,42,0.12)';
            btn.style.border = '2px solid ' + color;
            if (defaultIcon) defaultIcon.style.color = color;
        } else {
            btn.style.backgroundColor = color;
            btn.style.boxShadow = '0 4px 14px rgba(15,23,42,0.18)';
            btn.style.border = 'none';
            if (defaultIcon) defaultIcon.style.color = '#ffffff';
        }

        if (img) {
            img.style.maxHeight = '100%';
            img.style.maxWidth = '100%';
            img.style.width = '100%';
            img.style.height = '100%';
            img.style.borderRadius = '50%';
            img.style.filter = 'none';
        }
        if (defaultIcon) defaultIcon.style.fontSize = Math.round(size * 0.42) + 'px';
    }

    function updateLivePreview() {
        var title = document.getElementById('widget_title').value || 'AI Assistant';
        var greeting = document.getElementById('widget_greeting').value || 'Hello!';
        var color = document.getElementById('widget_primary_color').value || '#1f2937';

        var launcherShape = getSelectedRadioValue('launcher_shape', 'circle');
        var avatarShape = getSelectedRadioValue('avatar_shape', 'circle');

        document.getElementById('prevTitle').textContent = title;
        document.getElementById('prevGreeting').textContent = greeting;
        document.getElementById('prevHeader').style.background = color;
        document.getElementById('prevUserMsg').style.backgroundColor = color;
        document.getElementById('prevSendBtn').style.backgroundColor = color;

        // Both corner-button states share the same rules, so one helper draws
        // each of them at the size the sliders ask for.
        var closeShape = getSelectedRadioValue('close_shape', 'circle');
        var launcherSize = parseInt(readRange('launcher_size', 60), 10);
        var closeSize = parseInt(readRange('close_size', 52), 10);

        setText('launcherSizeOut', launcherSize + 'px');
        setText('closeSizeOut', closeSize + 'px');

        styleCornerButton(
            document.getElementById('prevLauncherBtn'),
            document.getElementById('prevLauncherImg'),
            document.getElementById('prevLauncherDefault'),
            launcherShape, launcherSize, color
        );

        styleCornerButton(
            document.getElementById('prevCloseBtn'),
            document.getElementById('prevCloseImg'),
            document.getElementById('prevCloseDefault'),
            closeShape, closeSize, color
        );

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
            badge.className = 'text-success';
            badge.innerHTML = '<i class="bi bi-check2-circle"></i> Selected: ' + modelName;
        }
    }

    function fetchModelsFromBaseUrl() {
        var provider = selectedProviderOption();
        var currentModel = document.getElementById('model_name').value.trim();
        var btn = document.getElementById('btnFetchModels');
        var icon = document.getElementById('iconFetch');
        var text = document.getElementById('textFetch');
        var badge = document.getElementById('fetchModelsBadge');
        var dropdownList = document.getElementById('modelsDropdownList');
        var testConnResult = document.getElementById('testConnResult');

        if (!provider || !provider.value) {
            badge.className = 'text-danger';
            badge.innerHTML = '<i class="bi bi-exclamation-circle"></i> Choose a provider first';
            return;
        }

        btn.disabled = true;
        icon.className = 'spinner-border spinner-border-sm';
        text.textContent = 'Fetching...';
        badge.className = 'text-muted';
        badge.innerHTML = '<i class="bi bi-hourglass-split"></i> Querying endpoint...';

        fetch(providerRoutes.models, {
            method: 'POST',
            headers: providerHeaders(),
            body: JSON.stringify({ provider_id: provider.value, bot_id: providerRoutes.botId })
        })
        .then(providerJson)
        .then(function(data) {
            btn.disabled = false;
            icon.className = 'bi bi-arrow-repeat';
            text.textContent = 'Fetch models';

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

                badge.className = 'text-success';
                badge.innerHTML = '<i class="bi bi-check-circle-fill"></i> ' + data.count + ' model(s) found';

                if (testConnResult) {
                    testConnResult.className = 'test-conn-result text-success';
                    testConnResult.innerHTML = '<i class="bi bi-check2-circle"></i> Endpoint reachable, ' + data.count + ' model(s) available';
                }

                var toggleBtn = document.getElementById('btnModelDropdownToggle');
                var bsDropdown = bootstrap.Dropdown.getOrCreateInstance(toggleBtn);
                bsDropdown.show();
            } else {
                badge.className = 'text-danger';
                badge.innerHTML = '<i class="bi bi-exclamation-circle"></i> ' + (data.message || 'No models returned');

                dropdownList.innerHTML = '<li><span class="dropdown-item-text text-danger small"><i class="bi bi-exclamation-triangle me-1"></i> ' + (data.message || 'No models found') + '</span></li>';

                if (testConnResult) {
                    testConnResult.className = 'test-conn-result text-danger';
                    testConnResult.innerHTML = '<i class="bi bi-exclamation-circle"></i> ' + (data.message || 'Connection failed');
                }
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            icon.className = 'bi bi-arrow-repeat';
            text.textContent = 'Fetch models';

            badge.className = 'text-danger';
            badge.innerHTML = '<i class="bi bi-exclamation-circle"></i> Could not list models';

            dropdownList.innerHTML = '<li><span class="dropdown-item-text text-danger small"><i class="bi bi-x-circle me-1"></i> Could not list models</span></li>';

            if (testConnResult) {
                testConnResult.className = 'test-conn-result text-danger';
                testConnResult.textContent = 'Could not list models: ' + err.message;
            }
        });
    }

    // ---- Providers -------------------------------------------------------
    //
    // The select is the source of truth for which endpoint this bot talks to.
    // No key ever reaches this page: Fetch models and Test inference name the
    // selected provider, and the portal looks its key up and calls the engine.

    var providerRoutes = {
        store: '{{ route('providers.store') }}',
        base: '{{ url('/providers') }}',
        models: '{{ route('providers.models') }}',
        test: '{{ route('providers.test') }}',
        systemId: @json($providerSystemId),
        // Lets a bot's editor test the provider it already uses, even one a
        // super admin set from outside their reach.
        botId: @json($isEdit ? $bot->id : null),
    };

    // Reads a JSON answer, turning a refusal into an error with its message.
    function providerJson(res) {
        return res.json().catch(function () { return {}; }).then(function (data) {
            if (!res.ok && data && data.success === undefined) {
                throw new Error(firstProviderError(data));
            }
            return data;
        });
    }

    function selectedProviderOption() {
        var select = document.getElementById('provider_id');
        return select && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
    }

    function onProviderChange() {
        var option = selectedProviderOption();
        var hasProvider = !!(option && option.value);

        // Platform providers are edited in Admin Settings, and one set from
        // above is not this user's to change.
        var editable = hasProvider && option.dataset.editable === '1';
        document.getElementById('providerActions').hidden = !editable;
        document.getElementById('providerLockedNote').hidden = !hasProvider || editable;

        renderProviderPicker();
    }

    // ---- Provider picker ---------------------------------------------------
    //
    // Drawn from the select every time it changes, so adding, editing and
    // deleting only ever touch the select.

    function providerMeta(option) {
        if (option.dataset.locked === '1') {
            return ['Set by a super admin'];
        }
        var bots = parseInt(option.dataset.bots || '0', 10);
        return [
            option.dataset.hasKey === '1' ? 'Key saved' : 'No key',
            bots === 0 ? 'No bots yet' : bots + (bots === 1 ? ' bot' : ' bots'),
        ];
    }

    function providerOwnerBadge(label, scope) {
        var badge = document.createElement('span');
        badge.className = 'provider-owner-badge is-' + (scope || 'other');
        badge.innerHTML = scope === 'platform'
            ? '<i class="bi bi-globe2"></i> '
            : '<i class="bi bi-diagram-3"></i> ';
        badge.appendChild(document.createTextNode(label));
        return badge;
    }

    function providerEntry(option, withOwner) {
        var wrap = document.createElement('span');
        wrap.className = 'provider-entry';

        var name = document.createElement('span');
        name.className = 'provider-entry-name';
        name.textContent = option.dataset.name;
        wrap.appendChild(name);

        // Whose it is leads the second line as a badge coloured by scope: this
        // workspace, another workspace or the platform. A long name wraps
        // inside the badge rather than being cut off.
        var sub = document.createElement('span');
        sub.className = 'provider-entry-sub';
        if (withOwner) {
            sub.appendChild(providerOwnerBadge(option.dataset.owner, option.dataset.scope));
        }
        if (option.dataset.baseUrl) {
            var url = document.createElement('span');
            url.className = 'provider-entry-url';
            url.textContent = option.dataset.baseUrl;
            sub.appendChild(url);
        }
        providerMeta(option).forEach(function (text) {
            var item = document.createElement('span');
            item.className = 'provider-entry-meta';
            item.textContent = text;
            sub.appendChild(item);
        });
        wrap.appendChild(sub);

        return wrap;
    }

    function renderProviderPicker() {
        var select = document.getElementById('provider_id');
        var trigger = document.getElementById('providerTrigger');
        var body = document.getElementById('providerTriggerBody');
        var list = document.getElementById('providerMenuList');
        var filterWrap = document.getElementById('providerFilterWrap');
        var filter = document.getElementById('providerFilter');
        if (!select || !trigger) return;

        var selected = selectedProviderOption();
        body.innerHTML = '';
        if (selected && selected.value) {
            body.appendChild(providerEntry(selected, true));
        } else {
            var empty = document.createElement('span');
            empty.className = 'provider-entry-empty';
            empty.textContent = 'No providers yet. Add one with New.';
            body.appendChild(empty);
        }
        trigger.disabled = select.disabled;

        var query = filter.value.trim().toLowerCase();
        var total = select.querySelectorAll('option[value]:not([value=""])').length;
        filterWrap.hidden = total < 6;

        list.innerHTML = '';
        Array.prototype.forEach.call(select.querySelectorAll('optgroup'), function (group) {
            var matches = Array.prototype.filter.call(group.querySelectorAll('option'), function (option) {
                var haystack = [option.dataset.name, option.dataset.owner, option.dataset.baseUrl || ''].join(' ').toLowerCase();
                return !query || haystack.indexOf(query) !== -1;
            });
            if (!matches.length) return;

            var header = document.createElement('div');
            header.className = 'dropdown-header provider-menu-header';
            header.appendChild(providerOwnerBadge(group.label, matches[0].dataset.scope));
            list.appendChild(header);

            matches.forEach(function (option) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'dropdown-item provider-item' + (option.selected ? ' active' : '');
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
                item.appendChild(providerEntry(option, false));
                item.addEventListener('click', function () {
                    select.value = option.value;
                    onProviderChange();
                });
                list.appendChild(item);
            });
        });

        if (!list.children.length) {
            var none = document.createElement('div');
            none.className = 'provider-menu-none';
            none.textContent = query ? 'Nothing matches that filter.' : 'No providers yet.';
            list.appendChild(none);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var filter = document.getElementById('providerFilter');
        var trigger = document.getElementById('providerTrigger');
        if (!filter || !trigger) return;

        filter.addEventListener('input', renderProviderPicker);
        filter.addEventListener('click', function (event) { event.stopPropagation(); });
        trigger.addEventListener('shown.bs.dropdown', function () {
            if (!document.getElementById('providerFilterWrap').hidden) filter.focus();
        });
        trigger.addEventListener('hidden.bs.dropdown', function () {
            if (filter.value) { filter.value = ''; renderProviderPicker(); }
        });
    });

    function openProviderModal(mode) {
        var result = document.getElementById('providerModalResult');
        result.className = 'small fw-medium';
        result.textContent = '';

        if (mode === 'edit') {
            var option = selectedProviderOption();
            if (!option || !option.value) { return; }

            document.getElementById('providerModalTitle').textContent = 'Edit provider';
            document.getElementById('providerEditId').value = option.value;
            document.getElementById('providerName').value = option.dataset.name || '';
            document.getElementById('providerBaseUrl').value = option.dataset.baseUrl || '';
            setProviderKeyField(option.dataset.hasKey === '1');
        } else {
            document.getElementById('providerModalTitle').textContent = 'New provider';
            document.getElementById('providerEditId').value = '';
            document.getElementById('providerName').value = '';
            document.getElementById('providerBaseUrl').value = 'http://localhost:11434/v1';
            setProviderKeyField(false);
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('providerModal')).show();
    }

    // The key box always starts empty. With a key already saved it says so,
    // and offers to remove it.
    function setProviderKeyField(hasSavedKey) {
        var input = document.getElementById('providerApiKey');
        input.value = '';
        input.placeholder = hasSavedKey ? '•••••••• saved, type to replace' : 'Not needed for local Ollama';
        document.getElementById('providerApiKeyHelp').textContent = hasSavedKey
            ? 'The saved key is never shown. Leave blank to keep it.'
            : 'Leave blank for a local endpoint.';
        document.getElementById('providerClearKey').checked = false;
        document.getElementById('providerClearKeyWrap').hidden = !hasSavedKey;
    }

    function providerHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        };
    }

    function saveProvider() {
        var id = document.getElementById('providerEditId').value;
        var name = document.getElementById('providerName').value.trim();
        var baseUrl = document.getElementById('providerBaseUrl').value.trim();
        var apiKey = document.getElementById('providerApiKey').value.trim();
        var result = document.getElementById('providerModalResult');
        var btn = document.getElementById('btnSaveProvider');

        if (!name || !baseUrl) {
            result.className = 'small fw-medium text-danger';
            result.textContent = 'A name and a base URL are both needed.';
            return;
        }

        btn.disabled = true;
        result.className = 'small fw-medium text-secondary';
        result.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

        fetch(id ? providerRoutes.base + '/' + id : providerRoutes.store, {
            method: id ? 'PUT' : 'POST',
            headers: providerHeaders(),
            body: JSON.stringify({
                system_id: providerRoutes.systemId, name: name, base_url: baseUrl, api_key: apiKey,
                clear_api_key: !!id && document.getElementById('providerClearKey').checked
            })
        })
        .then(function(res) {
            return res.json().then(function(data) { return { ok: res.ok, data: data }; });
        })
        .then(function(payload) {
            btn.disabled = false;

            if (!payload.ok) {
                result.className = 'small fw-medium text-danger';
                result.textContent = firstProviderError(payload.data);
                return;
            }

            applySavedProvider(payload.data.provider);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('providerModal')).hide();

            var hint = document.getElementById('providerHint');
            hint.className = 'form-text mb-3 text-success';
            hint.textContent = payload.data.message;
        })
        .catch(function(error) {
            btn.disabled = false;
            result.className = 'small fw-medium text-danger';
            result.textContent = 'Could not save: ' + error.message;
        });
    }

    // Puts the saved provider into the select and selects it, so a new endpoint
    // is in use the moment the modal closes.
    function applySavedProvider(provider) {
        var select = document.getElementById('provider_id');
        var option = select.querySelector('option[value="' + provider.id + '"]');

        if (!option) {
            var placeholder = select.querySelector('option[value=""]');
            if (placeholder) { placeholder.remove(); }

            var group = select.querySelector('optgroup[data-system-id="' + provider.system_id + '"]');
            if (!group) {
                group = document.createElement('optgroup');
                group.label = provider.owner;
                group.dataset.systemId = provider.system_id;
                select.insertBefore(group, select.firstChild);
            }

            option = document.createElement('option');
            option.value = provider.id;
            group.appendChild(option);
        }

        option.textContent = provider.label + ' · ' + provider.owner;
        option.dataset.name = provider.name;
        option.dataset.owner = provider.owner;
        option.dataset.editable = '1';
        option.dataset.own = provider.system_id === providerRoutes.systemId ? '1' : '0';
        option.dataset.scope = provider.system_id === null ? 'platform'
            : (option.dataset.own === '1' ? 'own' : 'other');
        option.dataset.bots = option.dataset.bots || '0';
        option.dataset.baseUrl = provider.base_url;
        option.dataset.hasKey = provider.has_key ? '1' : '0';

        select.disabled = false;
        select.value = provider.id;
        onProviderChange();
    }

    function deleteProvider() {
        var option = selectedProviderOption();
        if (!option || !option.value) { return; }

        var bots = parseInt(option.dataset.bots || '0', 10);
        confirmDialog({
            title: 'Delete this provider?',
            subject: option.dataset.name,
            detail: [option.dataset.baseUrl, option.dataset.owner].filter(Boolean).join(' · '),
            message: bots > 0
                ? 'It is still used by ' + bots + (bots === 1 ? ' bot' : ' bots') + ', so the delete will be refused until they point elsewhere.'
                : 'No bot uses it. This cannot be undone.',
            confirmLabel: 'Delete provider',
        }).then(function (confirmed) {
            if (confirmed) removeProvider(option);
        });
    }

    function removeProvider(option) {
        var hint = document.getElementById('providerHint');

        fetch(providerRoutes.base + '/' + option.value, {
            method: 'DELETE',
            headers: providerHeaders()
        })
        .then(function(res) {
            return res.json().then(function(data) { return { ok: res.ok, data: data }; });
        })
        .then(function(payload) {
            if (!payload.ok) {
                hint.className = 'form-text mb-3 text-danger';
                hint.textContent = payload.data.message || 'Could not delete that provider.';
                return;
            }

            var group = option.parentNode;
            option.remove();
            if (group.tagName === 'OPTGROUP' && !group.children.length) { group.remove(); }
            hint.className = 'form-text mb-3 text-success';
            hint.textContent = payload.data.message;

            var select = document.getElementById('provider_id');
            if (select.options.length === 0) {
                var placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'No providers yet, add one';
                select.appendChild(placeholder);
                select.disabled = true;
            }
            onProviderChange();
        })
        .catch(function(error) {
            hint.className = 'form-text mb-3 text-danger';
            hint.textContent = 'Could not delete: ' + error.message;
        });
    }

    // Checks what is typed in the modal, before any of it is saved.
    function testProviderDraft() {
        var baseUrl = document.getElementById('providerBaseUrl').value.trim();
        var apiKey = document.getElementById('providerApiKey').value.trim();
        var editId = document.getElementById('providerEditId').value;
        var clearKey = !!editId && document.getElementById('providerClearKey').checked;
        var result = document.getElementById('providerModalResult');
        var btn = document.getElementById('btnTestProvider');

        if (!baseUrl) {
            result.className = 'small fw-medium text-danger';
            result.textContent = 'Enter a base URL to test.';
            return;
        }

        btn.disabled = true;
        result.className = 'small fw-medium text-secondary';
        result.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Querying endpoint...';

        // An edit with the key box blank is tested with the saved key, as it
        // would be saved; ticking Remove tests it with none.
        fetch(providerRoutes.models, {
            method: 'POST',
            headers: providerHeaders(),
            body: JSON.stringify({
                system_id: providerRoutes.systemId, base_url: baseUrl, api_key: apiKey,
                provider_id: clearKey ? null : (editId || null)
            })
        })
        .then(providerJson)
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                result.className = 'small fw-medium text-success';
                result.textContent = 'Reachable. ' + data.count + ' model(s) available.';
            } else {
                result.className = 'small fw-medium text-danger';
                result.textContent = data.message || 'The endpoint did not answer.';
            }
        })
        .catch(function(error) {
            btn.disabled = false;
            result.className = 'small fw-medium text-danger';
            result.textContent = 'Could not reach it: ' + error.message;
        });
    }

    function firstProviderError(data) {
        if (data && data.errors) {
            for (var field in data.errors) {
                return data.errors[field][0];
            }
        }
        return (data && data.message) || 'Could not save that provider.';
    }

    document.addEventListener('DOMContentLoaded', onProviderChange);

    function testConnection() {
        var provider = selectedProviderOption();
        var modelName = document.getElementById('model_name').value.trim();
        var statusEl = document.getElementById('testConnResult');
        var btn = document.getElementById('btnTestConn');

        if (!provider || !provider.value) {
            statusEl.className = 'test-conn-result fw-medium text-danger';
            statusEl.textContent = 'Choose a provider first.';
            return;
        }

        if (!modelName) {
            statusEl.className = 'test-conn-result fw-medium text-warning text-dark';
            statusEl.textContent = 'Choose a model first, or fetch the list.';
            return;
        }

        statusEl.className = 'test-conn-result fw-medium text-secondary';
        statusEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Testing inference response (model may be loading)...';
        btn.disabled = true;

        fetch(providerRoutes.test, {
            method: 'POST',
            headers: providerHeaders(),
            body: JSON.stringify({ provider_id: provider.value, bot_id: providerRoutes.botId, model_name: modelName })
        })
        .then(providerJson)
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                statusEl.className = 'test-conn-result fw-medium text-success';
                statusEl.textContent = data.message;
            } else {
                statusEl.className = 'test-conn-result fw-medium text-danger';
                statusEl.textContent = data.message;
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            statusEl.className = 'test-conn-result fw-medium text-danger';
            statusEl.textContent = 'Could not run the test: ' + err.message;
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
