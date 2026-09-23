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
            {{ $isEdit ? ($bot->is_platform ? 'The console assistant: the chat widget super admins see in this console. Changes apply as soon as you save.' : 'Changes apply to every site running this bot as soon as you save.') : 'Configuring in workspace ' . $activeSystem->name . '.' }}
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

            {{-- A new bot needs a model before it can exist. After that it is set on
                 the Behaviour tab. --}}
            @unless($isEdit)
                @include('bots._model-endpoint')
            @endunless

            {{-- Appearance. One numbered section per part of the widget, so each
                 setting sits beside the others that change the same thing. --}}
        @php
            $offlineMode = old('offline_mode', $isEdit ? ($bot->offline_mode ?: 'hide') : 'hide');
            $offStyle = $bot->offline_style ?? []; // the offline preview reads its saved pictures from here too
        @endphp
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    <span>Widget appearance</span>
                    {{-- Which state is being designed. Stays in step with the preview's Online / Offline tabs. --}}
                    <div class="nav status-tabs" role="tablist" aria-label="Widget state to edit">
                        <button type="button" class="status-tab active" id="appearanceOnlineTab" data-bs-toggle="tab" data-bs-target="#appearanceOnline"
                                data-sync="online" role="tab" aria-controls="appearanceOnline" aria-selected="true">
                            <span class="status-tab-dot is-on" aria-hidden="true"></span> Online
                        </button>
                        <button type="button" class="status-tab" id="appearanceOfflineTab" data-bs-toggle="tab" data-bs-target="#appearanceOffline"
                                data-sync="offline" role="tab" aria-controls="appearanceOffline" aria-selected="false">
                            <span class="status-tab-dot is-off" aria-hidden="true"></span> Offline
                        </button>
                    </div>
                </div>
                <style>
                    .appearance-section { padding: 1rem; }
                    .appearance-section + .appearance-section,
                    .appearance-section + div > .appearance-section:first-child { border-top: 1px solid var(--border); }
                    .appearance-head { display: flex; align-items: flex-start; gap: 0.625rem; margin-bottom: 0.875rem; }
                    .appearance-step { flex-shrink: 0; width: 22px; height: 22px; border-radius: 50%;
                        border: 1px solid var(--border-strong); display: inline-flex; align-items: center;
                        justify-content: center; font-size: 0.6875rem; font-weight: 600; margin-top: 1px; }
                    .appearance-title { font-size: 0.875rem; font-weight: 600; line-height: 1.3; }
                    .appearance-hint { font-size: 0.75rem; }
                    .appearance-part { font-size: 0.75rem; font-weight: 600; text-transform: uppercase;
                        letter-spacing: 0.04em; margin-bottom: 0.5rem; }
                    .appearance-split { border-top: 1px dashed var(--border); margin-top: 1rem; padding-top: 1rem; }
                </style>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="appearanceOnline" role="tabpanel" aria-labelledby="appearanceOnlineTab" tabindex="0">
                <section class="appearance-section">
                    <div class="appearance-head">
                        <span class="appearance-step">1</span>
                        <div>
                            <div class="appearance-title">Text and position</div>
                            <div class="appearance-hint text-muted">What the chat says when it opens, and which corner it sits in.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6">
                            <label for="widget_title" class="form-label">Header title</label>
                            <input type="text" name="widget_title" id="widget_title" class="form-control"
                                   value="{{ old('widget_title', $bot->widget_title) }}" oninput="updateLivePreview()" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label for="widget_position" class="form-label">Screen position</label>
                            <select name="widget_position" id="widget_position" class="form-select" onchange="updateLivePreview()">
                                <option value="bottom-right" {{ old('widget_position', $bot->widget_position) === 'bottom-right' ? 'selected' : '' }}>Bottom right</option>
                                <option value="bottom-left" {{ old('widget_position', $bot->widget_position) === 'bottom-left' ? 'selected' : '' }}>Bottom left</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="widget_greeting" class="form-label">Opening greeting</label>
                        <input type="text" name="widget_greeting" id="widget_greeting" class="form-control"
                               value="{{ old('widget_greeting', $bot->widget_greeting) }}" oninput="updateLivePreview()">
                    </div>
                </section>
                <section class="appearance-section">
                    <div class="appearance-head">
                        <span class="appearance-step">2</span>
                        <div>
                            <div class="appearance-title">Colours and backgrounds</div>
                            <div class="appearance-hint text-muted">The brand colour, and what fills the header and the conversation behind the messages.</div>
                        </div>
                    </div>
                    <div>
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
                        <div class="form-text">Used for the launcher, the visitor's message bubbles and the send button, and for the header unless you give it a colour of its own.</div>
                    </div>

                    <div class="row g-3 appearance-split">
                        <div class="col-12 col-md-6">
                            <div class="appearance-part text-muted">Header</div>
                            @php $headerMatches = old('header_color_matches', $bot->widget_header_color ? null : '1'); @endphp
                            <label for="widget_header_color" class="form-label">Header colour</label>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <input type="color" name="widget_header_color" id="widget_header_color" class="form-control"
                                       value="{{ old('widget_header_color', $bot->widget_header_color ?: ($bot->widget_primary_color ?: '#1f2937')) }}"
                                       oninput="updateLivePreview()" style="width: 52px;" {{ $headerMatches ? 'disabled' : '' }}>
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="header_color_matches" value="1" id="header_color_matches"
                                           {{ $headerMatches ? 'checked' : '' }} onchange="updateLivePreview()">
                                    <label class="form-check-label" for="header_color_matches" style="font-size: 0.8125rem;">Same as the widget colour</label>
                                </div>
                            </div>

                            <label for="widget_header_text_color" class="form-label mt-3">Text and icon colour</label>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <input type="color" name="widget_header_text_color" id="widget_header_text_color" class="form-control"
                                       value="{{ old('widget_header_text_color', $bot->widget_header_text_color ?: '#FFFFFF') }}"
                                       oninput="updateLivePreview()" style="width: 52px;">
                                @foreach(['#FFFFFF' => 'White', '#F4F4F5' => 'Soft white', '#18181B' => 'Black', '#1f2937' => 'Graphite'] as $hex => $label)
                                    <button type="button" onclick="setHeaderTextColor('{{ $hex }}')" title="{{ $label }}"
                                            style="width: 26px; height: 26px; padding: 0; background-color: {{ $hex }};
                                                   border: 1px solid var(--border-strong); border-radius: var(--r-sm);"></button>
                                @endforeach
                            </div>
                            <div class="form-text">The title and the clear, expand and close icons. Pick a dark one for a light header picture.</div>

                            <label for="header_image_input" class="form-label mt-3 mb-1">Header picture <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="file" name="header_image" id="header_image_input" accept=".png,.jpg,.jpeg,.gif,.svg,.webp"
                                   class="form-control form-control-sm"
                                   onchange="previewBackground(this, 'header')">
                            @if($bot->widget_header_image_url)
                                <div class="mt-2 d-flex align-items-center gap-2 p-2"
                                     style="border: 1px solid var(--border); border-radius: var(--r-sm);">
                                    <img src="{{ $bot->widget_header_image_url }}" alt="Current header picture" class="identity" style="object-fit: cover;">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="remove_header_image" value="1" id="remove_header_image"
                                               onchange="updateLivePreview()">
                                        <label class="form-check-label" for="remove_header_image" style="font-size: 0.75rem;">Remove and use the colour</label>
                                    </div>
                                </div>
                            @endif
                            <div class="form-text">Behind the title, shown as uploaded. If the text is hard to read, lower the opacity or change the text colour.</div>
                            <div class="mt-3">
                                @include('bots._slider', [
                                    'name' => 'header_image_opacity', 'label' => 'Picture opacity',
                                    'min' => 0, 'max' => 100, 'step' => 5, 'decimals' => 0, 'unit' => '%',
                                    'value' => old('header_image_opacity', $bot->widget_header_image_opacity ?? 100),
                                    'ends' => ['Colour only', 'Picture only'],
                                    'hint' => 'Lower it to let the header colour show through the picture.',
                                ])
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="appearance-part text-muted">Conversation</div>
                            <label for="widget_background_color" class="form-label">Background colour</label>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <input type="color" name="widget_background_color" id="widget_background_color" class="form-control"
                                       value="{{ old('widget_background_color', $bot->widget_background_color ?: '#FAFAFA') }}"
                                       oninput="updateLivePreview()" style="width: 52px;">
                                @foreach(['#FAFAFA' => 'Paper', '#FFFFFF' => 'White', '#F1F5F9' => 'Mist', '#FDF6E3' => 'Cream', '#ECFDF5' => 'Mint'] as $hex => $label)
                                    <button type="button" onclick="setBackgroundColor('{{ $hex }}')" title="{{ $label }}"
                                            style="width: 26px; height: 26px; padding: 0; background-color: {{ $hex }};
                                                   border: 1px solid var(--border-strong); border-radius: var(--r-sm);"></button>
                                @endforeach
                            </div>

                            <label for="background_image_input" class="form-label mt-3 mb-1">Background picture <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="file" name="background_image" id="background_image_input" accept=".png,.jpg,.jpeg,.gif,.svg,.webp"
                                   class="form-control form-control-sm"
                                   onchange="previewBackground(this, 'body')">
                            @if($bot->widget_background_image_url)
                                <div class="mt-2 d-flex align-items-center gap-2 p-2"
                                     style="border: 1px solid var(--border); border-radius: var(--r-sm);">
                                    <img src="{{ $bot->widget_background_image_url }}" alt="Current background picture" class="identity" style="object-fit: cover;">
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="remove_background_image" value="1" id="remove_background_image"
                                               onchange="updateLivePreview()">
                                        <label class="form-check-label" for="remove_background_image" style="font-size: 0.75rem;">Remove and use the colour</label>
                                    </div>
                                </div>
                            @endif
                            <div class="form-text">Behind the messages. The colour shows while the picture loads and wherever it is transparent.</div>
                            <div class="mt-3">
                                @include('bots._slider', [
                                    'name' => 'background_image_opacity', 'label' => 'Picture opacity',
                                    'min' => 0, 'max' => 100, 'step' => 5, 'decimals' => 0, 'unit' => '%',
                                    'value' => old('background_image_opacity', $bot->widget_background_image_opacity ?? 100),
                                    'ends' => ['Colour only', 'Picture only'],
                                    'hint' => 'Lower it to soften a busy picture so the messages stay easy to read.',
                                ])
                            </div>
                        </div>
                    </div>
                </section>
                <section class="appearance-section">
                    <div class="appearance-head">
                        <span class="appearance-step">3</span>
                        <div>
                            <div class="appearance-title">Corner button</div>
                            <div class="appearance-hint text-muted">The floating button: how it looks before the chat opens, and once it is open.</div>
                        </div>
                    </div>
                    <div class="row g-3">
                        {{-- Launcher --}}
                        <div class="col-12 col-md-6">
                            <div class="fw-semibold mb-1" style="font-size: 0.8125rem;">Launcher button</div>
                            <p class="text-muted mb-2" style="font-size: 0.75rem;">The floating button before anyone opens the chat.</p>

                            <label for="launcher_icon_input" class="visually-hidden">Launcher image</label>
                            <input type="file" name="launcher_icon" id="launcher_icon_input" accept=".png,.jpg,.jpeg,.gif,.svg,.webp"
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
                                    @foreach(['cutout_circle' => ['Cutout in filled circle', 'The top of the picture rises out of a solid circle'],
                                              'cutout_ring' => ['Cutout in outlined circle', 'The top of the picture rises out of a ring']] as $value => $option)
                                        <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                            <input type="radio" name="launcher_shape" id="launcher_shape_{{ $value }}" value="{{ $value }}"
                                                   {{ old('launcher_shape', $bot->launcher_shape ?? 'circle') === $value ? 'checked' : '' }}
                                                   onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                            <span>
                                                <span class="d-block fw-semibold" style="font-size: 0.78125rem;">{{ $option[0] }}</span>
                                                <span class="text-muted" style="font-size: 0.6875rem;">{{ $option[1] }}</span>
                                            </span>
                                        </label>
                                    @endforeach
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
                            <input type="file" name="close_icon" id="close_icon_input" accept=".png,.jpg,.jpeg,.gif,.svg,.webp"
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
                                    @foreach(['cutout_circle' => ['Cutout in filled circle', 'The top of the picture rises out of a solid circle'],
                                              'cutout_ring' => ['Cutout in outlined circle', 'The top of the picture rises out of a ring']] as $value => $option)
                                        <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                            <input type="radio" name="close_shape" id="close_shape_{{ $value }}" value="{{ $value }}"
                                                   {{ old('close_shape', $bot->close_shape ?? 'circle') === $value ? 'checked' : '' }}
                                                   onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                            <span>
                                                <span class="d-block fw-semibold" style="font-size: 0.78125rem;">{{ $option[0] }}</span>
                                                <span class="text-muted" style="font-size: 0.6875rem;">{{ $option[1] }}</span>
                                            </span>
                                        </label>
                                    @endforeach
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
                    </div>
                </section>
                <section class="appearance-section">
                    <div class="appearance-head">
                        <span class="appearance-step">4</span>
                        <div>
                            <div class="appearance-title">Chat avatar</div>
                            <div class="appearance-hint text-muted">Shown in the chat header and beside each reply.</div>
                        </div>
                    </div>
                    <div class="row g-3">
                        {{-- Avatar --}}
                        <div class="col-12 col-md-6">

                            <label for="bot_avatar_input" class="visually-hidden">Avatar image</label>
                            <input type="file" name="bot_avatar" id="bot_avatar_input" accept=".png,.jpg,.jpeg,.gif,.svg,.webp"
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
                                    @foreach(['cutout_circle' => ['Cutout in filled circle', 'The top of the picture rises out of a solid circle'],
                                              'cutout_ring' => ['Cutout in outlined circle', 'The top of the picture rises out of a ring']] as $value => $option)
                                        <label class="d-flex align-items-start gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;">
                                            <input type="radio" name="avatar_shape" id="avatar_shape_{{ $value }}" value="{{ $value }}"
                                                   {{ old('avatar_shape', $bot->avatar_shape ?? 'circle') === $value ? 'checked' : '' }}
                                                   onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                                            <span>
                                                <span class="d-block fw-semibold" style="font-size: 0.78125rem;">{{ $option[0] }}</span>
                                                <span class="text-muted" style="font-size: 0.6875rem;">{{ $option[1] }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
                    </div>
                    <div class="tab-pane fade" id="appearanceOffline" role="tabpanel" aria-labelledby="appearanceOfflineTab" tabindex="0">
                        @include('bots._offline-appearance')
                    </div>
                </div>
            </div>

            {{-- Danger zone. Forms cannot nest, so the button submits
                 botDeleteForm, which sits after this form, through form="". --}}
            @if($isEdit && !$bot->is_platform && auth()->user()->canManageSystem($bot->system_id, 'system_admin'))
                @php
                    // The bot is only marked, whoever deletes it. A workspace is
                    // told it is gone for good, and for its members it is: the bot,
                    // its conversations and its analytics all disappear. A super
                    // admin still sees all three and can restore it from Bots.
                    $deleteWarning = auth()->user()->isSuperAdmin()
                        ? 'It stops answering on every site and leaves this workspace. Its conversations and analytics are kept, and you can restore it from Bots under Admin settings.'
                        : 'It stops answering on every site and is deleted permanently, with its conversations and analytics. This cannot be undone.';
                @endphp
                <div class="card mb-3 danger-zone">
                    <div class="card-header">Delete bot profile</div>
                    <div class="p-3 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                        <p class="mb-0" style="font-size: 0.8125rem;">{{ $deleteWarning }}</p>
                        <button type="submit" form="botDeleteForm" class="btn btn-danger flex-shrink-0"
                                data-confirm="{{ auth()->user()->isSuperAdmin() ? 'Delete this bot profile?' : 'Delete this bot profile permanently?' }}"
                                data-confirm-subject="{{ $bot->name }}"
                                data-confirm-message="{{ $deleteWarning }}"
                                data-confirm-type="{{ $bot->name }}"
                                data-confirm-label="{{ auth()->user()->isSuperAdmin() ? 'Delete bot' : 'Delete permanently' }}">
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
                                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#pane-preview" data-sync="online"
                                        type="button" role="tab" aria-controls="pane-preview" aria-selected="true">Online</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#pane-offline" data-sync="offline"
                                        type="button" role="tab" aria-controls="pane-offline" aria-selected="false">Offline</button>
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
                            <style>
                                /* The widget's cutout-in-a-circle, for the preview. The picture
                                   sits in a clip whose bottom is the circle's lower half, so it
                                   rises out of the top. Outside a cutout the clip does nothing. */
                                :is(#pane-preview, #pane-offline) .pv-clip { display: contents; }
                                :is(#pane-preview, #pane-offline) .pv-cutout { position: relative; overflow: visible !important;
                                    background: transparent !important; border: none !important;
                                    border-radius: 0 !important; box-shadow: none !important; }
                                :is(#pane-preview, #pane-offline) .pv-cutout::before, :is(#pane-preview, #pane-offline) .pv-cutout-ring::after {
                                    content: ""; position: absolute; inset: 0; border-radius: 50%; pointer-events: none; }
                                :is(#pane-preview, #pane-offline) .pv-cutout::before { background: var(--pv-fill); z-index: 0; }
                                :is(#pane-preview, #pane-offline) .pv-cutout-ring::before { background: transparent; border: 2px solid var(--pv-line); }
                                :is(#pane-preview, #pane-offline) .pv-cutout-ring::after { border: 2px solid var(--pv-line);
                                    clip-path: inset(50% 0 0 0); z-index: 2; }
                                :is(#pane-preview, #pane-offline) .pv-cutout > i { position: relative; z-index: 1; }
                                :is(#pane-preview, #pane-offline) .pv-cutout .pv-clip { display: flex; position: absolute; left: 0; right: 0;
                                    bottom: 0; height: 135%; overflow: hidden; border-radius: 0 0 999px 999px;
                                    align-items: flex-end; justify-content: center; z-index: 1; }
                                :is(#pane-preview, #pane-offline) .pv-cutout .pv-clip img { width: auto !important; height: auto !important;
                                    max-width: 100% !important; max-height: 100% !important; border-radius: 0 !important;
                                    filter: none !important; object-fit: contain !important; }
                            </style>
                            <div class="d-flex flex-column" style="height: 440px; background: #f4f4f5;">
                                <div id="prevHeader" class="p-3 d-flex align-items-center justify-content-between flex-shrink-0"
                                     data-image="{{ $bot->widget_header_image_url }}"
                                     style="background: {{ $bot->widget_primary_color ?: '#1f2937' }}; color: #ffffff;">
                                    <div class="d-flex align-items-center gap-2.5">
                                        <div id="prevAvatarContainer"
                                             class="d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0"
                                             style="width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.22);">
                                            <span class="pv-clip"><img id="prevAvatarImg" src="{{ $bot->bot_avatar_url ?: '' }}" alt=""
                                                 style="{{ $bot->bot_avatar_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;"></span>
                                            <i id="prevAvatarDefault" class="bi bi-robot"
                                               style="{{ $bot->bot_avatar_url ? 'display:none;' : 'display:block;' }} font-size: 1rem; color: #ffffff;"></i>
                                        </div>
                                        <div>
                                            <div id="prevTitle" class="fw-semibold" style="font-size: 0.8125rem; line-height: 1.2;">{{ $bot->widget_title ?: 'AI Assistant' }}</div>
                                            <div style="display: inline-flex; align-items: center; gap: 4px; margin-top: 2px; padding: 2px 7px; border-radius: 999px; font-size: 0.625rem; font-weight: 600; line-height: 1; color: #15803D; background: #DCFCE7;"><span style="width: 5px; height: 5px; border-radius: 50%; background: #16A34A;"></span> Online</div>
                                        </div>
                                    </div>
                                    <span class="d-flex align-items-center gap-2" style="opacity: 0.8;">
                                        <i class="bi bi-trash3" style="font-size: 0.75rem;"></i>
                                        <i class="bi bi-arrows-angle-expand" style="font-size: 0.75rem;"></i>
                                        <i class="bi bi-x-lg" style="font-size: 0.8rem;"></i>
                                    </span>
                                </div>

                                <div id="prevBody" data-image="{{ $bot->widget_background_image_url }}" class="p-3 flex-grow-1 overflow-auto d-flex flex-column gap-2.5"
                                     style="background-color: {{ $bot->widget_background_color ?: '#FAFAFA' }}; background-size: cover; background-position: center;">
                                    <div class="d-flex align-items-start gap-2" style="max-width: 88%;">
                                        <div id="prevMiniAvatar"
                                             class="d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0"
                                             style="width: 24px; height: 24px; border-radius: 50%; margin-top: 2px; color: #fff; background-color: {{ $bot->widget_primary_color ?: '#1f2937' }};">
                                            <span class="pv-clip"><img id="prevMiniAvatarImg" src="{{ $bot->bot_avatar_url ?: '' }}" alt=""
                                                 style="{{ $bot->bot_avatar_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;"></span>
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
                                            <span id="prevThinkingText">Reading your message...</span>
                                        </div>
                                    </div>

                                    <div class="mt-auto align-self-center text-center"
                                         style="margin-bottom: -0.625rem; font-size: 0.625rem; line-height: 1.4; color: #71717a; background: rgba(255,255,255,0.82); border-radius: 999px; padding: 0.1875rem 0.625rem;">
                                        AI can make mistakes. Please verify important information.
                                    </div>
                                </div>

                                <div class="p-2 d-flex flex-wrap align-items-center gap-2 flex-shrink-0"
                                     style="background: #ffffff; border-top: 1px solid #e4e4e7;">
                                    <input type="text" aria-label="Message preview" disabled placeholder="Type a message"
                                           style="flex: 1; min-width: 0; background: #f4f4f5; color: #71717a; border: 1px solid #e4e4e7; border-radius: 6px; padding: 0.375rem 0.625rem; font-size: 0.78125rem;">
                                    <button type="button" id="prevSendBtn" disabled
                                            style="border: none; border-radius: 6px; padding: 0.375rem 0.625rem; color: #fff; background-color: {{ $bot->widget_primary_color ?: '#1f2937' }};">
                                        <i class="bi bi-send" style="font-size: 0.75rem;"></i>
                                    </button>
                                    <div class="w-100 text-center" style="font-size: 0.625rem; line-height: 1.4; color: #a1a1aa;">
                                        Powered by C<sup>4</sup>
                                    </div>
                                </div>
                            </div>

                            <div class="p-3" style="border-top: 1px solid var(--border);">
                                <div class="d-flex align-items-end gap-4" style="min-height: 96px;">
                                    <div class="text-center">
                                        <div class="d-flex align-items-end justify-content-center" style="min-height: 72px;">
                                            <div id="prevLauncherBtn"
                                                 class="d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0"
                                                 style="width: 48px; height: 48px; border-radius: 50%; color: #fff; background-color: {{ $bot->widget_primary_color ?: '#1f2937' }};">
                                                <span class="pv-clip"><img id="prevLauncherImg" src="{{ $bot->launcher_icon_url ?: '' }}" alt=""
                                                     style="{{ $bot->launcher_icon_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;"></span>
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
                                                <span class="pv-clip"><img id="prevCloseImg" src="{{ $bot->close_icon_url ?: '' }}" alt=""
                                                     style="{{ $bot->close_icon_url ? 'display:block;' : 'display:none;' }} width: 100%; height: 100%; object-fit: contain;"></span>
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

                        {{-- Offline: what a visitor sees while the bot is switched off
                             and set to show a message. Follows Widget appearance → Offline. --}}
                        <div class="tab-pane fade" id="pane-offline" role="tabpanel">
                            <div id="prevOfflineHideNote" class="p-3 text-muted" style="font-size: 0.78125rem;" hidden>
                                <i class="bi bi-eye-slash"></i> Set to <strong>Hide the widget</strong>: while the bot is off, visitors see nothing at all.
                                Choose <strong>Show an offline message</strong> under Widget appearance &rarr; Offline to design a notice.
                            </div>
                            <div id="prevOfflineDesign">
                            <div id="prevOfflineStage" class="d-flex flex-column justify-content-end p-3"
                                 style="height: 300px; background: #f4f4f5;">
                                <div id="prevOfflineBox" class="pv-off">
                                    <div id="prevOfflineHeader" class="pv-off-head" data-own="{{ $offStyle['header_image'] ?? '' }}">
                                        <div class="d-flex align-items-center" style="gap: 10px; min-width: 0;">
                                            <div id="prevOfflineAvatar" class="pv-off-avatar">
                                                <img id="prevOfflineAvatarImg" data-own="{{ $offStyle['avatar_image'] ?? '' }}" alt="" style="display: none;">
                                                <span id="prevOfflineAvatarLetter"></span>
                                            </div>
                                            <div style="min-width: 0;">
                                                <span id="prevOfflineTitle" class="pv-off-title"></span>
                                                <div id="prevOfflineSubtitle" class="pv-off-subtitle"></div>
                                            </div>
                                        </div>
                                        <span class="pv-off-badge"><span></span> Offline</span>
                                    </div>
                                    <p id="prevOfflineText" class="pv-off-body" data-own="{{ $offStyle['body_image'] ?? '' }}"></p>
                                    <div id="prevOfflineHours" class="pv-off-foot" data-own="{{ $offStyle['footer_image'] ?? '' }}"></div>
                                </div>
                                {{-- Mirrors the widget's .offline-* styles. --}}
                                <style>
                                    .pv-off { width: 280px; max-width: 100%; background: #fff; border: 1px solid rgba(226,232,240,0.9); border-radius: 16px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(15,23,42,0.15); }
                                    .pv-off [hidden] { display: none !important; }
                                    .pv-off-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 14px 16px 12px; border-bottom: 1px solid #F1F5F9;
                                        background: var(--off-header-image, none) center / cover no-repeat, var(--off-header-bg, #fff); }
                                    .pv-off-avatar { position: relative; flex-shrink: 0; width: 36px; height: 36px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 700;
                                        background: color-mix(in srgb, var(--pv-color) 10%, #fff); color: var(--pv-color);
                                        box-shadow: 0 1px 2px rgba(0,0,0,0.05), 0 0 0 1px color-mix(in srgb, var(--pv-color) 15%, transparent); }
                                    .pv-off-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: inherit; }
                                    .pv-off-avatar.shape-circle { border-radius: 50%; background: var(--pv-color); color: #fff; box-shadow: none; }
                                    .pv-off-avatar.shape-circle-transparent { border-radius: 50%; background: transparent; box-shadow: 0 0 0 2px var(--pv-color); }
                                    .pv-off-avatar.shape-transparent-fit { border-radius: 0; background: transparent; box-shadow: none; }
                                    .pv-off-avatar.shape-transparent-fit img { object-fit: contain; }
                                    .pv-off-avatar::after { content: ""; position: absolute; top: -2px; right: -2px; width: 10px; height: 10px; border-radius: 50%; background: #EF4444; box-shadow: 0 0 0 2px #fff; }
                                    .pv-off-title { display: block; font-size: 13px; font-weight: 700; letter-spacing: -0.01em; color: var(--off-header-text, #0F172A); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
                                    .pv-off-subtitle { font-size: 11px; line-height: 1; color: var(--off-header-text, #0F172A); opacity: 0.65; margin-top: 3px; }
                                    .pv-off-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: #B91C1C; background: #FEF2F2; border: 1px solid rgba(254,202,202,0.8); border-radius: 999px; padding: 4px 10px; flex-shrink: 0; }
                                    .pv-off-badge > span { width: 6px; height: 6px; border-radius: 50%; background: #EF4444; animation: pv-offline-pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
                                    @keyframes pv-offline-pulse { 50% { opacity: 0.5; } }
                                    @media (prefers-reduced-motion: reduce) { .pv-off-badge > span { animation: none; } }
                                    .pv-off-body { margin: 0; font-size: 13px; line-height: 1.625; color: var(--off-body-text, #475569); white-space: pre-line; padding: 12px 16px 14px;
                                        background: var(--off-body-image, none) center / cover no-repeat, var(--off-body-bg, #fff); }
                                    .pv-off-foot { font-size: 11px; color: var(--off-footer-text, #94A3B8); padding: 8px 16px 10px; border-top: 1px solid #F1F5F9;
                                        background: var(--off-footer-image, none) center / cover no-repeat, var(--off-footer-bg, #fff); }
                                </style>
                            </div>
                            <div id="prevOfflineButtons" class="p-3" style="border-top: 1px solid var(--border);">
                                <div class="d-flex align-items-end gap-4" style="min-height: 96px;">
                                    <div class="text-center">
                                        <div class="d-flex align-items-end justify-content-center" style="min-height: 72px;">
                                            <div id="prevOfflineLauncherBtn"
                                                 class="d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0"
                                                 style="width: 48px; height: 48px; border-radius: 50%; color: #fff;">
                                                <span class="pv-clip"><img id="prevOfflineLauncherImg" data-own="{{ $bot->offline_icon_url }}" alt=""
                                                     style="display: none; width: 100%; height: 100%; object-fit: contain;"></span>
                                                <i id="prevOfflineLauncherDefault" class="bi bi-chat-dots" style="font-size: 1.05rem;"></i>
                                            </div>
                                        </div>
                                        <div class="text-muted mt-2" style="font-size: 0.6875rem;">Closed</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="d-flex align-items-end justify-content-center" style="min-height: 72px;">
                                            <div id="prevOfflineCloseBtn"
                                                 class="d-flex align-items-center justify-content-center overflow-hidden flex-shrink-0"
                                                 style="width: 42px; height: 42px; border-radius: 50%; color: #fff;">
                                                <span class="pv-clip"><img id="prevOfflineCloseImg" data-own="{{ $bot->offline_close_icon_url }}" alt=""
                                                     style="display: none; width: 100%; height: 100%; object-fit: contain;"></span>
                                                <i id="prevOfflineCloseDefault" class="bi bi-x-lg" style="font-size: 0.95rem;"></i>
                                            </div>
                                        </div>
                                        <div class="text-muted mt-2" style="font-size: 0.6875rem;">Open</div>
                                    </div>
                                    <p class="text-muted mb-0 align-self-center" style="font-size: 0.75rem;">
                                        The notice opens from the corner button. Empty pictures and shapes follow the online button.
                                    </p>
                                </div>
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

                                    <div>
                                        <div class="kv">
                                            <span class="kv-key">Sites allowed to load it</span>
                                            <span class="kv-val">{{ $bot->system->allowed_origins ?? '*' }}</span>
                                        </div>
                                        <div class="kv">
                                            <span class="kv-key">Style isolation</span>
                                            <span class="kv-val">Shadow DOM</span>
                                        </div>
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

@if($isEdit && !$bot->is_platform && auth()->user()->canManageSystem($bot->system_id, 'system_admin'))
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
                showState(on ? 'online' : 'offline');
            });
        });

        document.querySelectorAll('input[name="offline_mode"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                document.querySelectorAll('[data-offline-message-only]').forEach(function (el) {
                    el.hidden = radio.value !== 'message';
                });
                updateLivePreview();
            });
        });
    })();

    // The settings' Online / Offline switch and the preview's tabs of the same
    // name move together, so the preview always shows the state being edited.
    function showState(state) {
        document.querySelectorAll('[data-sync="' + state + '"]').forEach(function (tab) {
            bootstrap.Tab.getOrCreateInstance(tab).show();
        });
    }
    document.querySelectorAll('[data-sync]').forEach(function (tab) {
        tab.addEventListener('shown.bs.tab', function () { showState(tab.dataset.sync); });
    });
    // A required field in the hidden state's pane would block saving with no
    // visible message; bring its pane forward so the browser can point at it.
    document.getElementById('botForm').addEventListener('invalid', function (e) {
        var pane = e.target.closest('#appearanceOnline, #appearanceOffline');
        if (pane && !pane.classList.contains('active')) showState(pane.id === 'appearanceOnline' ? 'online' : 'offline');
    }, true);
    @if(!$botOn) showState('offline'); @endif

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
    /**
     * Draws a cutout-in-a-circle shape, or clears it when shape is anything
     * else. Returns whether it drew one, so the caller can skip its own rules.
     */
    function applyCutout(el, shape, size, fill, line) {
        el.classList.remove('pv-cutout', 'pv-cutout-circle', 'pv-cutout-ring');
        if (shape !== 'cutout_circle' && shape !== 'cutout_ring') return false;

        el.classList.add('pv-cutout', shape === 'cutout_ring' ? 'pv-cutout-ring' : 'pv-cutout-circle');
        el.style.width = size + 'px';
        el.style.height = size + 'px';
        el.style.setProperty('--pv-fill', fill);
        el.style.setProperty('--pv-line', line);
        el.style.color = shape === 'cutout_ring' ? line : '#ffffff';
        return true;
    }

    function styleCornerButton(btn, img, defaultIcon, shape, size, color) {
        if (!btn) return;

        if (applyCutout(btn, shape, size, color, color)) {
            if (defaultIcon) {
                defaultIcon.style.color = '';
                defaultIcon.style.fontSize = Math.round(size * 0.42) + 'px';
            }
            return;
        }
        btn.style.color = '#fff';

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
        // The header follows the widget colour until it is given its own.
        var headerPicker = document.getElementById('widget_header_color');
        var headerMatches = document.getElementById('header_color_matches').checked;
        headerPicker.disabled = headerMatches;
        if (headerMatches) headerPicker.value = color;
        var headerColor = headerPicker.value || color;

        var header = document.getElementById('prevHeader');
        var headerImage = header.dataset.image;
        var removeHeader = document.getElementById('remove_header_image');
        header.style.backgroundColor = headerColor;
        header.style.color = document.getElementById('widget_header_text_color').value || '#ffffff';
        header.style.backgroundSize = 'cover';
        header.style.backgroundPosition = 'center';
        header.style.backgroundImage = (headerImage && !(removeHeader && removeHeader.checked && !header.dataset.fresh))
            ? pictureLayers(headerImage, headerColor, readRange('header_image_opacity', 100))
            : 'none';

        var body = document.getElementById('prevBody');
        var bodyImage = body.dataset.image;
        var removeBody = document.getElementById('remove_background_image');
        var bodyColor = document.getElementById('widget_background_color').value || '#FAFAFA';
        body.style.backgroundColor = bodyColor;
        body.style.backgroundImage = (bodyImage && !(removeBody && removeBody.checked && !body.dataset.fresh))
            ? pictureLayers(bodyImage, bodyColor, readRange('background_image_opacity', 100)) : 'none';
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

        updateOfflinePreview(title, color, launcherShape, launcherSize, closeShape, closeSize);

        // Apply Avatar Shape & Background styling
        var avatarContainer = document.getElementById('prevAvatarContainer');
        var avatarImg = document.getElementById('prevAvatarImg');
        var miniAvatar = document.getElementById('prevMiniAvatar');
        var miniAvatarImg = document.getElementById('prevMiniAvatarImg');

        var headerCutout = applyCutout(avatarContainer, avatarShape, 38, 'rgba(255,255,255,0.22)', 'rgba(255,255,255,0.75)');
        var miniCutout = applyCutout(miniAvatar, avatarShape, 28, color, color);
        if (headerCutout || miniCutout) {
            return;
        }
        miniAvatar.style.color = avatarShape === 'circle_transparent' ? color : '#fff';

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

    /**
     * A picture at an opacity over its colour, drawn as a wash of the colour
     * on top of it. The widget uses the same rule.
     */
    function pictureLayers(url, color, opacity) {
        var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(color || '');
        var wash = (100 - Number(opacity)) / 100;
        var layers = [];
        if (m && wash > 0) {
            var rgba = 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + wash + ')';
            layers.push('linear-gradient(' + rgba + ', ' + rgba + ')');
        }
        layers.push('url("' + url + '")');
        return layers.join(', ');
    }

    // The opacity sliders come from a shared partial, so they are listened
    // to here rather than given an oninput of their own.
    document.addEventListener('input', function (event) {
        if (/^(header_image_opacity|background_image_opacity|offline_style\[\w+_image_opacity\])$/.test(event.target.id)) {
            updateLivePreview();
        }
    });

    function setHeaderTextColor(hex) {
        document.getElementById('widget_header_text_color').value = hex;
        updateLivePreview();
    }

    function setBackgroundColor(hex) {
        document.getElementById('widget_background_color').value = hex;
        updateLivePreview();
    }

    // A picture picked here replaces the stored one in the preview. Marked
    // fresh, so ticking "Remove" on the old one does not hide the new one.
    function previewBackground(input, target) {
        if (!input.files || !input.files[0]) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            var el = document.getElementById(target === 'header' ? 'prevHeader' : 'prevBody');
            el.dataset.image = e.target.result;
            el.dataset.fresh = '1';
            updateLivePreview();
        };
        reader.readAsDataURL(input.files[0]);
    }

    // The Offline tab, drawn the way the widget draws the notice: anything
    // left empty follows the online look.
    function updateOfflinePreview(title, color, launcherShape, launcherSize, closeShape, closeSize) {
        var mode = getSelectedRadioValue('offline_mode', 'hide');
        document.getElementById('prevOfflineHideNote').hidden = mode === 'message';
        document.getElementById('prevOfflineDesign').hidden = mode !== 'message';

        title = document.getElementById('offline_title').value.trim() || title;
        setText('prevOfflineTitle', title);
        setText('prevOfflineText', document.getElementById('input_offline_message').value
            || "We're offline right now. Please check back later.");
        ['Subtitle', 'Hours'].forEach(function (key) {
            var value = document.getElementById('input_offline_' + key.toLowerCase()).value.trim();
            var el = document.getElementById('prevOffline' + key);
            el.textContent = value;
            el.hidden = !value;
        });

        var box = document.getElementById('prevOfflineBox');
        box.style.setProperty('--pv-color', color);
        [['header', 'prevOfflineHeader'], ['body', 'prevOfflineText'], ['footer', 'prevOfflineHours']].forEach(function (part) {
            var bg = document.getElementById('offline_' + part[0] + '_bg').value;
            var image = ownSrc(document.getElementById(part[1]), 'remove_offline_' + part[0] + '_image');
            var opacity = Number(document.getElementById('offline_style[' + part[0] + '_image_opacity]').value);
            box.style.setProperty('--off-' + part[0] + '-bg', bg);
            box.style.setProperty('--off-' + part[0] + '-text', document.getElementById('offline_' + part[0] + '_text').value);
            box.style.setProperty('--off-' + part[0] + '-image', image ? pictureLayers(image, bg, opacity) : 'none');
        });

        // The avatar shows what its "Show" choice picks; a missing picture falls back to the title's first letter.
        var source = getSelectedRadioValue('offline_style[avatar_source]', 'chat');
        document.querySelectorAll('[data-avatar-source]').forEach(function (el) {
            el.hidden = el.dataset.avatarSource !== source;
        });
        var onlineAvatar = document.getElementById('prevAvatarImg');
        var avatarImg = document.getElementById('prevOfflineAvatarImg');
        var avatarSrc = source === 'image' ? ownSrc(avatarImg, 'remove_offline_avatar')
            : source === 'chat' && onlineAvatar.style.display !== 'none' ? onlineAvatar.getAttribute('src') : '';
        var letter = (source === 'text' && document.getElementById('offline_avatar_emoji').value.trim())
            || (title || '?').trim().charAt(0).toUpperCase();
        if (avatarSrc) avatarImg.src = avatarSrc;
        avatarImg.style.display = avatarSrc ? 'block' : 'none';
        setText('prevOfflineAvatarLetter', avatarSrc ? '' : letter);
        document.getElementById('prevOfflineAvatar').className =
            'pv-off-avatar shape-' + getSelectedRadioValue('offline_style[avatar_shape]', 'rounded').replace(/_/g, '-');

        var position = document.getElementById('offline_position').value || document.getElementById('widget_position').value;
        document.getElementById('prevOfflineStage').style.alignItems = position === 'bottom-left' ? 'flex-start' : 'flex-end';

        paintOfflineButton('Launcher', 'prevLauncherImg', 'remove_offline_icon', 'offline_launcher_shape', launcherShape, launcherSize, color);
        paintOfflineButton('Close', 'prevCloseImg', 'remove_offline_close_icon', 'offline_close_shape', closeShape, closeSize, color);
    }

    function pickColour(id, hex) {
        document.getElementById(id).value = hex;
        updateLivePreview();
    }

    // An offline picture: a fresh upload, else the saved one unless its Remove box is ticked.
    function ownSrc(el, removeId) {
        var remove = document.getElementById(removeId);
        return el.dataset.own && !(remove && remove.checked && !el.dataset.fresh) ? el.dataset.own : '';
    }

    function paintOfflineButton(cap, onlineImgId, removeId, shapeId, onlineShape, size, color) {
        var img = document.getElementById('prevOffline' + cap + 'Img');
        var def = document.getElementById('prevOffline' + cap + 'Default');
        var own = ownSrc(img, removeId);
        var online = document.getElementById(onlineImgId);
        var src = own || (online.style.display !== 'none' ? online.getAttribute('src') : '');

        if (src) img.src = src;
        img.style.display = src ? 'block' : 'none';
        def.style.display = src ? 'none' : 'block';
        styleCornerButton(document.getElementById('prevOffline' + cap + 'Btn'), img, def,
            getSelectedRadioValue(shapeId, '') || onlineShape, size, color);
    }

    function previewOfflineUpload(input, key) {
        if (!input.files || !input.files[0]) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            var img = document.getElementById({launcher: 'prevOfflineLauncherImg', close: 'prevOfflineCloseImg'}[key] || key);
            img.dataset.own = e.target.result;
            img.dataset.fresh = '1';
            updateLivePreview();
        };
        reader.readAsDataURL(input.files[0]);
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

    // The preview walks through the steps the engine reports while a visitor waits
    var thinkingWords = ["Reading your message...", "Understanding intent...", "Consulting the knowledge base...", "Composing a response..."];
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
