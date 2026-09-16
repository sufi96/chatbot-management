{{--
    Embed snippet modal.
    Expects: $bot (BotProfile with system loaded), $apiHost (string).
    Included once per bot on the list screen, so every id is namespaced by bot id.
--}}
@php
    $snippet = "<script\n"
        . "  src=\"{$apiHost}/widget.js\"\n"
        . "  data-bot-id=\"{$bot->id}\"\n"
        . "  data-api-host=\"{$apiHost}\"\n"
        . "  defer>\n"
        . "</script>";
    $origins = $bot->system->allowed_origins ?? '*';
@endphp

<div class="modal fade" id="embedModal{{ $bot->id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h6 class="modal-title mb-0">Embed {{ $bot->name }}</h6>
                    <span class="text-muted" style="font-size: 0.75rem;">One script tag puts this bot on any page.</span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                    <span class="form-label mb-0">Snippet</span>
                    <button type="button" class="btn btn-sm btn-brand"
                            onclick="copyEmbedSnippet('{{ $bot->id }}')">
                        <i class="bi bi-clipboard" id="embedCopyIcon{{ $bot->id }}"></i>
                        <span id="embedCopyText{{ $bot->id }}">Copy</span>
                    </button>
                </div>

                <pre class="code-block mb-3" id="embedSnippet{{ $bot->id }}">{{ $snippet }}</pre>

                <div class="fw-semibold mb-2" style="font-size: 0.8125rem;">How to use it</div>
                <ol class="ps-3 mb-3" style="font-size: 0.8125rem; line-height: 1.6;">
                    <li class="mb-1">Copy the snippet above.</li>
                    <li class="mb-1">
                        Paste it into your site's HTML, just before the closing <code>&lt;/body&gt;</code> tag.
                        In WordPress that is <code>footer.php</code>; in a plain site it is <code>index.html</code>.
                    </li>
                    <li class="mb-1">
                        Reload the page. The launcher appears in the
                        {{ $bot->widget_position === 'bottom-left' ? 'bottom left' : 'bottom right' }} corner.
                    </li>
                </ol>

                <div class="p-2.5" style="border: 1px solid var(--border); border-radius: var(--r-sm); background: var(--surface-2);">
                    <div class="kv">
                        <span class="kv-key">Sites allowed to load it</span>
                        <span class="kv-val">{{ $origins }}</span>
                    </div>
                    <div class="kv">
                        <span class="kv-key">Accepting chats</span>
                        <span class="kv-val">{{ $bot->is_active ? 'Yes' : 'No, this profile is paused' }}</span>
                    </div>
                    <p class="text-muted mb-0 mt-2" style="font-size: 0.75rem;">
                        {{ $origins === '*'
                            ? 'Any domain may load this widget. Narrow that list in the workspace settings before going live.'
                            : 'Only those domains may load this widget. Add more in the workspace settings.' }}
                        The widget renders in a shadow root, so your site's CSS cannot affect it and its styles cannot leak out.
                    </p>
                </div>
            </div>

            <div class="modal-footer">
                @if(auth()->user()->canManageSystem($bot->system_id, 'editor'))
                    <a href="{{ route('bots.edit', $bot->id) }}" class="btn btn-outline-secondary">
                        <i class="bi bi-sliders"></i> Edit profile
                    </a>
                @endif
                <button type="button" class="btn btn-brand" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>
