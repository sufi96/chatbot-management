{{--
    The wordmark, in one place, so the sidebar and both login marks cannot
    drift apart.

    Two lines: the short name carries the weight, the badge sits beside it,
    and the long half becomes a tracked-out second line in the accent. The
    badge is flat "C4" rather than a raised digit, because a superscript
    inside a small chip escapes its own painted box at this size.
--}}
<span class="brand-mark">
    <img src="{{ \App\Support\Brand::logoUrl() }}" alt="">
</span>
<span class="brand-text">
    <span class="brand-line">
        <span class="brand-name">{{ config('app.brand_name') }}</span>
        <span class="brand-badge">{{ config('app.brand_tag') }}</span>
    </span>
    <span class="brand-sub">{{ config('app.brand_sub') }}</span>
</span>
