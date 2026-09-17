{{-- A ranked list drawn as bars: one hue, the label and the figure in text
     beside each bar, so nothing is read from colour alone.
     $items: list of ['label' => string, 'value' => int, 'href' => ?string, 'mono' => ?bool, 'tone' => ?string]
     $total: what the percentages are of; the largest value when omitted. --}}
@php
    $max = max(1, collect($items)->max('value') ?? 0);
    $total = $total ?? null;
@endphp
<ul class="an-bars">
    @foreach($items as $item)
        @php
            $share = $total ? $item['value'] / max(1, $total) : null;
            $tip = $item['label'] . ': ' . number_format($item['value']) . ($share !== null ? ' (' . round($share * 100) . '%)' : '');
        @endphp
        <li class="an-bar {{ !empty($item['tone']) ? 'is-' . $item['tone'] : '' }}" data-tip="{{ $tip }}" tabindex="0">
            <div class="an-bar-text">
                @if(!empty($item['href']))
                    <a href="{{ $item['href'] }}" class="an-bar-label {{ !empty($item['mono']) ? 'figure-mono' : '' }}" title="{{ $item['label'] }}">{{ $item['label'] }}</a>
                @else
                    <span class="an-bar-label {{ !empty($item['mono']) ? 'figure-mono' : '' }}" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                @endif
                <span class="an-bar-value figure-mono">
                    {{ number_format($item['value']) }}@if($share !== null)<span class="an-bar-share">{{ round($share * 100) }}%</span>@endif
                </span>
            </div>
            <div class="an-bar-track"><div class="an-bar-fill" style="width: {{ round($item['value'] / $max * 100, 2) }}%"></div></div>
        </li>
    @endforeach
</ul>
