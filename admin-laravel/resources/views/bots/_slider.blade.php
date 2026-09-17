{{-- A labelled range input with its value shown beside the label.

     Expects: $name, $label, $min, $max, $step, $value.
     Optional: $decimals (default 2), $hint (one short line), $ends ([left, right]
     words under the track), $offAt (a value read as "Off"), $unit (after the
     number). A saved value outside the range is shown at the nearest end. --}}
@php
    $decimals = $decimals ?? 2;
    $shown = min((float) $max, max((float) $min, (float) $value));
    $format = fn ($v) => (isset($offAt) && (float) $v === (float) $offAt)
        ? 'Off'
        : number_format((float) $v, $decimals) . (isset($unit) ? $unit : '');
@endphp
<div class="slider-field">
    <label for="{{ $name }}" class="form-label slider-label">
        <span>{{ $label }}</span>
        <output class="slider-value" for="{{ $name }}" data-slider-out="{{ $name }}">{{ $format($shown) }}</output>
    </label>
    <input type="range" class="form-range" name="{{ $name }}" id="{{ $name }}"
           min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" value="{{ $shown }}"
           data-slider data-decimals="{{ $decimals }}"
           @isset($offAt) data-off-at="{{ $offAt }}" @endisset
           @isset($unit) data-unit="{{ $unit }}" @endisset>
    @isset($ends)
        <div class="slider-ends"><span>{{ $ends[0] }}</span><span>{{ $ends[1] }}</span></div>
    @endisset
    @isset($hint)
        <div class="form-text">{{ $hint }}</div>
    @endisset
</div>

@once
    @push('scripts')
    <script>
        // Keeps each slider's readout in step with its thumb.
        document.addEventListener('input', function (event) {
            var input = event.target;
            if (!input.matches || !input.matches('[data-slider]')) return;
            var out = document.querySelector('[data-slider-out="' + input.id + '"]');
            if (!out) return;
            var value = Number(input.value);
            out.textContent = input.dataset.offAt !== undefined && value === Number(input.dataset.offAt)
                ? 'Off'
                : value.toFixed(Number(input.dataset.decimals)) + (input.dataset.unit || '');
        });
    </script>
    @endpush
@endonce
