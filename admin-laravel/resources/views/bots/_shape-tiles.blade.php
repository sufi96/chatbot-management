{{-- A shape picker: five small tiles with a drawing each, and one line under
     them saying what the chosen shape does. Used for the launcher, the close
     button and the chat avatar, which share the five shapes.

     Expects: $field (the radio name), $default, and optionally $hints, a
     value => sentence map that replaces the standard sentences. --}}
@php
    $shapeOptions = [
        'circle' => ['Filled', 'Solid colour behind the icon.',
            '<circle cx="16" cy="16" r="13" fill="currentColor"/><circle cx="16" cy="16" r="5" fill="var(--surface)"/>'],
        'circle_transparent' => ['Outlined', 'Ring border, transparent inside.',
            '<circle cx="16" cy="16" r="12" fill="none" stroke="currentColor" stroke-width="2.5"/><circle cx="16" cy="16" r="5" fill="currentColor"/>'],
        'transparent_fit' => ['Cutout', 'Follows the PNG outline, with no container.',
            '<circle cx="16" cy="9" r="5" fill="currentColor"/><path d="M6 30c0-7 4.5-11 10-11s10 4 10 11z" fill="currentColor"/>'],
        'cutout_circle' => ['Cutout + circle', 'The top of the picture rises out of a solid circle.',
            '<circle cx="16" cy="19" r="12" fill="currentColor" opacity="0.45"/><circle cx="16" cy="8" r="5" fill="currentColor"/><path d="M8 31c0-7 3.6-10 8-10s8 3 8 10z" fill="currentColor"/>'],
        'cutout_ring' => ['Cutout + ring', 'The top of the picture rises out of a ring.',
            '<circle cx="16" cy="19" r="11" fill="none" stroke="currentColor" stroke-width="2.5" opacity="0.6"/><circle cx="16" cy="8" r="5" fill="currentColor"/><path d="M8 31c0-7 3.6-10 8-10s8 3 8 10z" fill="currentColor"/>'],
    ];
    foreach (($hints ?? []) as $value => $sentence) {
        $shapeOptions[$value][1] = $sentence;
    }
    $chosenShape = old($field, $bot->{$field} ?? $default);
@endphp

@once
    <style>
        .shape-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(84px, 1fr)); gap: 0.375rem; }
        .shape-tile { display: flex; flex-direction: column; align-items: center; gap: 0.25rem; padding: 0.5rem 0.25rem;
            border: 1px solid var(--border); border-radius: var(--r-sm); cursor: pointer; text-align: center;
            font-size: 0.71875rem; color: var(--text-muted); line-height: 1.2; }
        .shape-tile:hover { border-color: var(--border-strong); }
        .shape-tile:has(input:checked) { border-color: var(--accent); color: var(--text); background: var(--accent-soft);
            box-shadow: 0 0 0 1px var(--accent) inset; }
        .shape-tile:has(input:focus-visible) { outline: 2px solid var(--accent); outline-offset: 2px; }
    </style>
@endonce

<div class="text-muted mb-1.5" style="font-size: 0.75rem;">Shape</div>
<div class="shape-grid" role="radiogroup" aria-label="Shape">
    @foreach($shapeOptions as $value => [$label, $sentence, $drawing])
        <label class="shape-tile" title="{{ $sentence }}">
            <input type="radio" name="{{ $field }}" id="{{ $field }}_{{ $value }}" value="{{ $value }}" data-hint="{{ $sentence }}"
                   @checked($chosenShape === $value) class="visually-hidden"
                   onchange="updateLivePreview(); document.getElementById('{{ $field }}_hint').textContent = this.dataset.hint;">
            <svg viewBox="0 0 32 32" width="28" height="28" aria-hidden="true">{!! $drawing !!}</svg>
            <span>{{ $label }}</span>
        </label>
    @endforeach
</div>
<div class="form-text" id="{{ $field }}_hint">{{ $shapeOptions[$chosenShape][1] ?? '' }}</div>
