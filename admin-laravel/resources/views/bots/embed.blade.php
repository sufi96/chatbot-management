@extends('layouts.app')

@section('content')
<div class="d-flex flex-column gap-4" style="max-width: 1050px;">

    <!-- Breadcrumb & Header -->
    <div class="card border-0 shadow-sm p-4 rounded-4" style="background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid #eef2f6 !important;">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div>
                <a href="{{ route('bots.index') }}" class="text-decoration-none small text-primary d-inline-flex align-items-center gap-1.5 mb-1.5 fw-semibold">
                    <i class="bi bi-arrow-left"></i> Back to Bot Profiles
                </a>
                <div class="d-flex align-items-center gap-2">
                    <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">Universal Embed Code: {{ $bot->name }}</h4>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1 small fw-semibold">
                        ID: {{ $bot->id }}
                    </span>
                </div>
                <p class="text-secondary small mb-0 mt-1">Embed this chatbot into any external web system, portal, or website with zero backend code changes.</p>
            </div>
            @if(auth()->user()->canManageSystem($bot->system_id, 'editor'))
                <a href="{{ route('bots.edit', $bot->id) }}" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1.5 px-3 py-2 rounded-3">
                    <i class="bi bi-gear"></i> Edit Bot Settings
                </a>
            @endif
        </div>
    </div>

    <!-- The 1-Line Embed Code Card with Terminal Window Aesthetic -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-dark d-flex align-items-center justify-content-between py-3 px-4" style="background-color: #0f172a !important; border-bottom: 1px solid #1e293b;">
            <div class="d-flex align-items-center gap-2">
                <div class="d-flex gap-1.5">
                    <span class="rounded-circle d-inline-block" style="width: 11px; height: 11px; background-color: #ef4444;"></span>
                    <span class="rounded-circle d-inline-block" style="width: 11px; height: 11px; background-color: #f59e0b;"></span>
                    <span class="rounded-circle d-inline-block" style="width: 11px; height: 11px; background-color: #10b981;"></span>
                </div>
                <span class="text-secondary font-monospace ms-2" style="font-size: 0.75rem;">embed-snippet.html</span>
            </div>
            <button type="button" onclick="copySnippet()" id="btnCopy" class="btn btn-sm btn-brand d-flex align-items-center gap-1.5 px-3 py-1.5 shadow-sm rounded-3">
                <i class="bi bi-clipboard" id="copyIcon"></i>
                <span id="copyText">Copy Embed Snippet</span>
            </button>
        </div>

        @php
            $snippet = "<script\n"
                . "  src=\"{$apiHost}/widget.js\"\n"
                . "  data-bot-id=\"{$bot->id}\"\n"
                . "  data-api-host=\"{$apiHost}\"\n"
                . "  defer>\n"
                . "</script>";
        @endphp

        <div class="p-4" style="background-color: #090d16;">
            <pre id="codeSnippet" class="font-monospace small mb-0 text-light" style="line-height: 1.7; font-size: 0.88rem;">{{ $snippet }}</pre>
        </div>

        <div class="p-3.5 px-4 bg-light border-top d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2 small text-secondary">
                <i class="bi bi-shield-check text-success fs-5"></i>
                <span>CORS Allowed Origins for workspace <strong>{{ $bot->system->name ?? '' }}</strong>:
                    <code class="px-2 py-0.5 rounded bg-white border text-dark font-monospace">{{ $bot->system->allowed_origins ?: '*' }}</code>
                </span>
            </div>
            <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 rounded-pill small fw-semibold">
                Shadow DOM Isolated
            </span>
        </div>
    </div>

    <!-- Multi-Framework Integration Guides -->
    <div class="card border-0 shadow-sm rounded-4 p-4">
        <h6 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
            <i class="bi bi-code-square text-primary"></i> Framework Installation Recipes
        </h6>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="p-3.5 rounded-4 bg-light border h-100">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width: 24px; height: 24px; font-size: 0.75rem; background-color: #4f46e5;">1</span>
                        <h6 class="fw-bold text-dark mb-0 small">HTML / WordPress / PHP</h6>
                    </div>
                    <p class="text-secondary small mb-2" style="font-size: 0.78rem; line-height: 1.5;">
                        Paste the snippet directly before the closing <code>&lt;/body&gt;</code> tag in your layout template (e.g. <code>footer.php</code>, <code>index.html</code>).
                    </p>
                    <code class="d-block p-2 bg-white border rounded-3 font-monospace small text-dark" style="font-size: 0.7rem;">
                        &lt;!-- In footer.php before &lt;/body&gt; --&gt;<br>
                        &lt;script src="{{ $apiHost }}/widget.js" data-bot-id="{{ $bot->id }}" defer&gt;&lt;/script&gt;
                    </code>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="p-3.5 rounded-4 bg-light border h-100">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width: 24px; height: 24px; font-size: 0.75rem; background-color: #0ea5e9;">2</span>
                        <h6 class="fw-bold text-dark mb-0 small">React / Next.js (App Router)</h6>
                    </div>
                    <p class="text-secondary small mb-2" style="font-size: 0.78rem; line-height: 1.5;">
                        In <code>app/layout.tsx</code> or <code>pages/_document.js</code>, import the Script component:
                    </p>
                    <code class="d-block p-2 bg-white border rounded-3 font-monospace small text-dark" style="font-size: 0.7rem;">
                        import Script from 'next/script'<br>
                        &lt;Script src="{{ $apiHost }}/widget.js" data-bot-id="{{ $bot->id }}" strategy="lazyOnload" /&gt;
                    </code>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="p-3.5 rounded-4 bg-light border h-100">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" style="width: 24px; height: 24px; font-size: 0.75rem; background-color: #10b981;">3</span>
                        <h6 class="fw-bold text-dark mb-0 small">Vue.js / Nuxt / Svelte</h6>
                    </div>
                    <p class="text-secondary small mb-2" style="font-size: 0.78rem; line-height: 1.5;">
                        Add to <code>index.html</code> or dynamically mount in your root mounted hook:
                    </p>
                    <code class="d-block p-2 bg-white border rounded-3 font-monospace small text-dark" style="font-size: 0.7rem;">
                        const s = document.createElement('script');<br>
                        s.src = '{{ $apiHost }}/widget.js';<br>
                        s.dataset.botId = '{{ $bot->id }}';<br>
                        document.body.appendChild(s);
                    </code>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Interactive Sandbox Notice -->
    <div class="card border-0 shadow-sm rounded-4 p-4" style="background: linear-gradient(135deg, rgba(79, 70, 229, 0.08) 0%, rgba(99, 102, 241, 0.03) 100%); border: 1px solid rgba(79, 70, 229, 0.2) !important;">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center shadow-sm" style="width: 46px; height: 46px; background-color: #4f46e5; color: #ffffff;">
                    <i class="bi bi-play-circle-fill fs-4"></i>
                </div>
                <div>
                    <h6 class="fw-bold text-primary mb-0.5">Interactive Sandbox Active on this Screen</h6>
                    <small class="text-secondary">The floating chat bubble at the bottom-right is loaded directly with <strong>{{ $bot->name }}</strong>. Click it to test your live Ollama or custom model now!</small>
                </div>
            </div>
            <button type="button" class="btn btn-sm btn-primary px-3 py-2 rounded-pill shadow-sm" onclick="var w = document.querySelector('chat-widget'); if(w) { var shadow = w.shadowRoot; var btn = shadow ? shadow.getElementById('chat-launcher') : null; if(btn) btn.click(); }">
                <i class="bi bi-chat-dots-fill me-1"></i> Open Test Widget 👉
            </button>
        </div>
    </div>

</div>

<!-- Embed this bot right here on this page for live sandbox testing -->
<script 
    src="{{ $apiHost }}/widget.js" 
    data-bot-id="{{ $bot->id }}" 
    data-api-host="{{ $apiHost }}" 
    defer>
</script>

@push('scripts')
<script>
    function copySnippet() {
        var text = document.getElementById('codeSnippet').textContent;
        navigator.clipboard.writeText(text).then(function() {
            var btn = document.getElementById('btnCopy');
            var icon = document.getElementById('copyIcon');
            var textEl = document.getElementById('copyText');

            btn.classList.replace('btn-brand', 'btn-success');
            icon.classList.replace('bi-clipboard', 'bi-check2-circle');
            textEl.textContent = 'Copied to Clipboard!';

            setTimeout(function() {
                btn.classList.replace('btn-success', 'btn-brand');
                icon.classList.replace('bi-check2-circle', 'bi-clipboard');
                textEl.textContent = 'Copy Embed Snippet';
            }, 2500);
        });
    }
</script>
@endpush
@endsection
