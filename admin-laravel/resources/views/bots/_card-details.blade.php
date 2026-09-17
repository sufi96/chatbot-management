{{-- What a bot card lists: its endpoint, model and prompt. Shared by the
     workspace's Bot profiles page and the admin Bots page. --}}
<div class="px-3 py-2">
    <div class="kv">
        <span class="kv-key">Provider</span>
        <span class="kv-val text-truncate" style="max-width: 190px;">{{ $bot->provider?->name ?? 'None set' }}</span>
    </div>
    <div class="kv">
        <span class="kv-key">Model</span>
        <span class="kv-val text-truncate" style="max-width: 190px;">{{ $bot->model_name }}</span>
    </div>
    <div class="kv">
        <span class="kv-key">Endpoint</span>
        @php
            $endpoint = $bot->provider && !$bot->provider->isVisibleTo(auth()->user())
                ? 'Set by ' . $bot->provider->ownerName()
                : ($bot->provider?->base_url ?? '—');
        @endphp
        <span class="kv-val text-truncate" style="max-width: 190px;" title="{{ $endpoint }}">{{ $endpoint }}</span>
    </div>
    <div class="kv">
        <span class="kv-key">Launcher shape</span>
        <span class="kv-val">
            {{ $bot->launcher_shape === 'transparent_fit' ? 'Cutout fit' : ($bot->launcher_shape === 'circle_transparent' ? 'Outlined circle' : 'Filled circle') }}
        </span>
    </div>
</div>

<div class="px-3 pb-3 flex-grow-1">
    <div class="text-muted mb-1" style="font-size: 0.75rem;">System prompt</div>
    <p class="text-muted mb-0 truncate-1" style="font-size: 0.78125rem; line-height: 1.5;">
        {{ \Illuminate\Support\Str::limit($bot->system_prompt, 120) }}
    </p>
</div>
