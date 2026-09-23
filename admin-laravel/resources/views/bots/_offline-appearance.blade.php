{{-- Widget appearance → Offline. The same four sections as Online, for the
     notice a visitor sees while the bot is switched off. Anything left empty
     follows the online look. Expects $bot, $isEdit, $offlineMode and $offStyle. --}}
@php
    $offOld = fn ($key, $default = null) => old("offline_style.$key", $offStyle[$key] ?? $default);
    $offParts = [
        'header' => ['Header', 'The bot name and the line under it.', '#FFFFFF', '#0F172A', 'prevOfflineHeader'],
        'body' => ['Message', 'The notice itself.', '#FFFFFF', '#475569', 'prevOfflineText'],
        'footer' => ['Footer', 'The small note at the bottom.', '#FFFFFF', '#94A3B8', 'prevOfflineHours'],
    ];
    $bgSwatches = ['#FFFFFF' => 'White', '#FAFAFA' => 'Paper', '#F1F5F9' => 'Mist', '#FDF6E3' => 'Cream', '#FEF2F2' => 'Rose', '#1F2937' => 'Graphite'];
    $textSwatches = ['#0F172A' => 'Ink', '#475569' => 'Slate', '#94A3B8' => 'Soft grey', '#FFFFFF' => 'White'];
    $cornerShapes = [
        '' => ['Same as online', 'Keeps the online button\'s shape'],
        'circle' => ['Filled circle', 'Solid colour behind the icon'],
        'circle_transparent' => ['Outlined circle', 'Ring border, transparent inside'],
        'transparent_fit' => ['Cutout silhouette', 'Follows the PNG outline, no container'],
        'cutout_circle' => ['Cutout in filled circle', 'The top of the picture rises out of a solid circle'],
        'cutout_ring' => ['Cutout in outlined circle', 'The top of the picture rises out of a ring'],
    ];
    $avatarSource = $offOld('avatar_source', !empty($offStyle['avatar_image']) ? 'image' : (!empty($offStyle['avatar_emoji']) ? 'text' : 'chat'));
    $optionCard = 'd-flex align-items-start gap-2 p-2';
    $optionStyle = 'border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer;';
@endphp

<section class="appearance-section">
    <div class="appearance-head">
        <span class="appearance-step">1</span>
        <div>
            <div class="appearance-title">Text and position</div>
            <div class="appearance-hint text-muted">What visitors see while the bot is switched off. Every field takes emoji.</div>
        </div>
    </div>

    <div class="row g-2 mb-3" role="radiogroup" aria-label="While offline">
        @foreach(['hide' => ['Hide the widget', 'Visitors see nothing at all'], 'message' => ['Show an offline notice', 'The corner button opens this notice instead of the chat']] as $value => $option)
            <div class="col-12 col-sm-6">
                <label class="{{ $optionCard }} h-100" style="{{ $optionStyle }}">
                    <input type="radio" name="offline_mode" value="{{ $value }}" id="offline_mode_{{ $value }}"
                           class="form-check-input mt-0 flex-shrink-0" {{ $offlineMode === $value ? 'checked' : '' }}>
                    <span>
                        <span class="d-block fw-semibold" style="font-size: 0.78125rem;">{{ $option[0] }}</span>
                        <span class="text-muted" style="font-size: 0.6875rem;">{{ $option[1] }}</span>
                    </span>
                </label>
            </div>
        @endforeach
    </div>

    <div data-offline-message-only @if($offlineMode !== 'message') hidden @endif>
        <div class="row g-3 mb-3">
            <div class="col-12 col-sm-6">
                <label for="offline_title" class="form-label">Header title</label>
                <input type="text" name="offline_style[title]" id="offline_title" class="form-control" maxlength="100"
                       value="{{ $offOld('title') }}" placeholder="{{ $bot->widget_title ?: 'Same as online' }}" oninput="updateLivePreview()">
                <div class="form-text">Empty uses the online title.</div>
            </div>
            <div class="col-12 col-sm-6">
                <label for="offline_position" class="form-label">Screen position</label>
                <select name="offline_style[position]" id="offline_position" class="form-select" onchange="updateLivePreview()">
                    @foreach(['' => 'Same as online', 'bottom-right' => 'Bottom right', 'bottom-left' => 'Bottom left'] as $value => $name)
                        <option value="{{ $value }}" {{ (string) $offOld('position', '') === $value ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <label for="input_offline_subtitle" class="form-label">Line under the title</label>
                <input type="text" name="offline_subtitle" id="input_offline_subtitle" class="form-control" maxlength="120" oninput="updateLivePreview()"
                       placeholder="Typical reply in 4 hours" value="{{ old('offline_subtitle', $isEdit ? $bot->offline_subtitle : '') }}">
            </div>
            <div class="col-12">
                <label for="input_offline_message" class="form-label">Message</label>
                <textarea name="offline_message" id="input_offline_message" class="form-control" rows="3" maxlength="1000" oninput="updateLivePreview()"
                          placeholder="We're offline right now. Please check back later.">{{ old('offline_message', $isEdit ? $bot->offline_message : '') }}</textarea>
            </div>
            <div class="col-12">
                <label for="input_offline_hours" class="form-label">Footer note</label>
                <input type="text" name="offline_hours" id="input_offline_hours" class="form-control" maxlength="160" oninput="updateLivePreview()"
                       placeholder="🕒 Business hours: Mon–Fri, 9am–6pm" value="{{ old('offline_hours', $isEdit ? $bot->offline_hours : '') }}">
                <div class="form-text">Leave the line under the title or the footer note empty to hide it.</div>
            </div>
        </div>
    </div>
</section>

<div data-offline-message-only @if($offlineMode !== 'message') hidden @endif>
    <section class="appearance-section">
        <div class="appearance-head">
            <span class="appearance-step">2</span>
            <div>
                <div class="appearance-title">Colours and backgrounds</div>
                <div class="appearance-hint text-muted">What fills each part of the notice, and the colour of its text.</div>
            </div>
        </div>
        <div class="row g-3">
            @foreach($offParts as $part => [$partName, $partHint, $bgDefault, $textDefault, $partPreview])
                <div class="col-12 col-md-6 {{ $loop->index > 1 ? 'appearance-split' : '' }}">
                    <div class="appearance-part text-muted">{{ $partName }}</div>
                    <p class="text-muted mb-2" style="font-size: 0.75rem;">{{ $partHint }}</p>

                    <label for="offline_{{ $part }}_bg" class="form-label">Background colour</label>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <input type="color" name="offline_style[{{ $part }}_bg]" id="offline_{{ $part }}_bg" class="form-control"
                               value="{{ $offOld("{$part}_bg", $bgDefault) }}" oninput="updateLivePreview()" style="width: 52px;">
                        @foreach($bgSwatches as $hex => $label)
                            <button type="button" onclick="pickColour('offline_{{ $part }}_bg', '{{ $hex }}')" title="{{ $label }}" aria-label="{{ $partName }} background: {{ $label }}"
                                    style="width: 26px; height: 26px; padding: 0; background-color: {{ $hex }}; border: 1px solid var(--border-strong); border-radius: var(--r-sm);"></button>
                        @endforeach
                    </div>

                    <label for="offline_{{ $part }}_text" class="form-label mt-3">Text colour</label>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <input type="color" name="offline_style[{{ $part }}_text]" id="offline_{{ $part }}_text" class="form-control"
                               value="{{ $offOld("{$part}_text", $textDefault) }}" oninput="updateLivePreview()" style="width: 52px;">
                        @foreach($textSwatches as $hex => $label)
                            <button type="button" onclick="pickColour('offline_{{ $part }}_text', '{{ $hex }}')" title="{{ $label }}" aria-label="{{ $partName }} text: {{ $label }}"
                                    style="width: 26px; height: 26px; padding: 0; background-color: {{ $hex }}; border: 1px solid var(--border-strong); border-radius: var(--r-sm);"></button>
                        @endforeach
                    </div>

                    <label for="offline_{{ $part }}_image_input" class="form-label mt-3 mb-1">{{ $partName }} picture <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="file" name="offline_{{ $part }}_image" id="offline_{{ $part }}_image_input" accept=".png,.jpg,.jpeg,.gif,.svg,.webp"
                           class="form-control form-control-sm" onchange="previewOfflineUpload(this, '{{ $partPreview }}')">
                    @if(!empty($offStyle["{$part}_image"]))
                        <div class="mt-2 d-flex align-items-center gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm);">
                            <img src="{{ $offStyle["{$part}_image"] }}" alt="Current {{ strtolower($partName) }} picture" class="identity" style="object-fit: cover;">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="remove_offline_{{ $part }}_image" value="1" id="remove_offline_{{ $part }}_image" onchange="updateLivePreview()">
                                <label class="form-check-label" for="remove_offline_{{ $part }}_image" style="font-size: 0.75rem;">Remove and use the colour</label>
                            </div>
                        </div>
                    @endif
                    <div class="mt-3">
                        @include('bots._slider', [
                            'name' => "offline_style[{$part}_image_opacity]", 'label' => 'Picture opacity',
                            'min' => 0, 'max' => 100, 'step' => 5, 'decimals' => 0, 'unit' => '%',
                            'value' => $offOld("{$part}_image_opacity", 100),
                            'ends' => ['Colour only', 'Picture only'],
                        ])
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="appearance-section">
        <div class="appearance-head">
            <span class="appearance-step">3</span>
            <div>
                <div class="appearance-title">Corner button</div>
                <div class="appearance-hint text-muted">The floating button while offline. Leave a picture empty to keep the online one.</div>
            </div>
        </div>
        <div class="row g-3">
            @foreach([
                'launcher' => ['Launcher button', 'Before anyone opens the notice.', 'offline_icon', 'offline_icon_url', 'offline_launcher_shape', 'remove_offline_icon'],
                'close' => ['Close button', 'Once the notice is open.', 'offline_close_icon', 'offline_close_icon_url', 'offline_close_shape', 'remove_offline_close_icon'],
            ] as $key => [$btnName, $btnHint, $field, $column, $shapeField, $removeField])
                <div class="col-12 col-md-6">
                    <div class="fw-semibold mb-1" style="font-size: 0.8125rem;">{{ $btnName }}</div>
                    <p class="text-muted mb-2" style="font-size: 0.75rem;">{{ $btnHint }}</p>

                    <label for="{{ $field }}_input" class="visually-hidden">Offline {{ strtolower($btnName) }} image</label>
                    <input type="file" name="{{ $field }}" id="{{ $field }}_input" accept=".png,.jpg,.jpeg,.gif,.svg,.webp"
                           class="form-control form-control-sm" onchange="previewOfflineUpload(this, '{{ $key }}')">
                    @if($bot->$column)
                        <div class="mt-2 d-flex align-items-center gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm);">
                            <img src="{{ $bot->$column }}" alt="Current offline {{ strtolower($btnName) }} image" class="identity">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="{{ $removeField }}" value="1" id="{{ $removeField }}" onchange="updateLivePreview()">
                                <label class="form-check-label" for="{{ $removeField }}" style="font-size: 0.75rem;">Remove and use the online picture</label>
                            </div>
                        </div>
                    @endif

                    <div class="mt-3">
                        <div class="text-muted mb-1.5" style="font-size: 0.75rem;">Shape</div>
                        <div class="d-flex flex-column gap-1.5">
                            @foreach($cornerShapes as $value => $option)
                                <label class="{{ $optionCard }}" style="{{ $optionStyle }}">
                                    <input type="radio" name="{{ $shapeField }}" id="{{ $shapeField }}_{{ $value ?: 'online' }}" value="{{ $value }}"
                                           {{ (string) old($shapeField, $bot->$shapeField) === $value ? 'checked' : '' }}
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
            @endforeach
        </div>
    </section>

    <section class="appearance-section">
        <div class="appearance-head">
            <span class="appearance-step">4</span>
            <div>
                <div class="appearance-title">Notice avatar</div>
                <div class="appearance-hint text-muted">Beside the title at the top of the notice.</div>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <div class="text-muted mb-1.5" style="font-size: 0.75rem;">Show</div>
                <div class="d-flex flex-column gap-1.5" role="radiogroup" aria-label="Notice avatar">
                    @foreach([
                        'chat' => ['The chat avatar', 'The online avatar, or the title\'s first letter if there is none'],
                        'image' => ['A picture of its own', 'Upload one just for the notice (GIFs work)'],
                        'text' => ['An emoji or letter', 'Type it below'],
                    ] as $value => $option)
                        <label class="{{ $optionCard }}" style="{{ $optionStyle }}">
                            <input type="radio" name="offline_style[avatar_source]" id="offline_avatar_source_{{ $value }}" value="{{ $value }}"
                                   {{ $avatarSource === $value ? 'checked' : '' }} onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                            <span>
                                <span class="d-block fw-semibold" style="font-size: 0.78125rem;">{{ $option[0] }}</span>
                                <span class="text-muted" style="font-size: 0.6875rem;">{{ $option[1] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-3" data-avatar-source="image" @if($avatarSource !== 'image') hidden @endif>
                    <label for="offline_avatar_input" class="form-label mb-1">Picture</label>
                    <input type="file" name="offline_avatar" id="offline_avatar_input" accept=".png,.jpg,.jpeg,.gif,.svg,.webp"
                           class="form-control form-control-sm" onchange="previewOfflineUpload(this, 'prevOfflineAvatarImg')">
                    @if(!empty($offStyle['avatar_image']))
                        <div class="mt-2 d-flex align-items-center gap-2 p-2" style="border: 1px solid var(--border); border-radius: var(--r-sm);">
                            <img src="{{ $offStyle['avatar_image'] }}" alt="Current notice avatar" class="identity">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="remove_offline_avatar" value="1" id="remove_offline_avatar" onchange="updateLivePreview()">
                                <label class="form-check-label" for="remove_offline_avatar" style="font-size: 0.75rem;">Remove the picture</label>
                            </div>
                        </div>
                    @endif
                </div>
                <div class="mt-3" data-avatar-source="text" @if($avatarSource !== 'text') hidden @endif>
                    <label for="offline_avatar_emoji" class="form-label mb-1">Emoji or letter</label>
                    <input type="text" name="offline_style[avatar_emoji]" id="offline_avatar_emoji" class="form-control" maxlength="16" style="max-width: 10rem;"
                           placeholder="⚡" value="{{ $offOld('avatar_emoji') }}" oninput="updateLivePreview()">
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="text-muted mb-1.5" style="font-size: 0.75rem;">Shape</div>
                <div class="d-flex flex-column gap-1.5">
                    @foreach([
                        'rounded' => ['Rounded square', 'Soft tint of the widget colour'],
                        'circle' => ['Filled circle', 'Solid widget colour'],
                        'circle_transparent' => ['Outlined circle', 'Ring border, no fill'],
                        'transparent_fit' => ['Cutout silhouette', 'The picture alone, no container'],
                    ] as $value => $option)
                        <label class="{{ $optionCard }}" style="{{ $optionStyle }}">
                            <input type="radio" name="offline_style[avatar_shape]" id="offline_avatar_shape_{{ $value }}" value="{{ $value }}"
                                   {{ $offOld('avatar_shape', 'rounded') === $value ? 'checked' : '' }} onchange="updateLivePreview()" class="form-check-input mt-0 flex-shrink-0">
                            <span>
                                <span class="d-block fw-semibold" style="font-size: 0.78125rem;">{{ $option[0] }}</span>
                                <span class="text-muted" style="font-size: 0.6875rem;">{{ $option[1] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
</div>
