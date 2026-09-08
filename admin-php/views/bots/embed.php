<div class="max-w-4xl mx-auto space-y-8">
    <!-- Header -->
    <div>
        <div class="flex items-center gap-2 text-xs font-semibold text-indigo-600 mb-1">
            <a href="/bots" class="hover:underline flex items-center gap-1"><i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to Bot Profiles</a>
        </div>
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Embed Code: <?= htmlspecialchars($bot['name']) ?></h2>
                <p class="text-sm text-slate-500 mt-1">Copy the embed code below to add this chatbot to any website with zero backend code changes.</p>
            </div>
            <a href="/bots/<?= urlencode($bot['id']) ?>/edit" class="px-3.5 py-1.5 rounded-lg border border-slate-200 text-slate-700 text-xs font-medium hover:bg-slate-50 transition-colors">
                Edit Settings
            </a>
        </div>
    </div>

    <!-- The 1-Line Embed Code Card -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                <h3 class="font-bold text-slate-900 text-sm">Universal Embed Snippet</h3>
            </div>
            <button onclick="copySnippet()" id="btn-copy" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-sm transition-all">
                <i data-lucide="copy" class="w-4 h-4"></i>
                <span id="copy-text">Copy Embed Code</span>
            </button>
        </div>

        <?php 
            $scriptSnippet = '<script' . "\n"
                . '  src="' . htmlspecialchars($apiHost) . '/widget.js"' . "\n"
                . '  data-bot-id="' . htmlspecialchars($bot['id']) . '"' . "\n"
                . '  data-api-host="' . htmlspecialchars($apiHost) . '"' . "\n"
                . '  defer>' . "\n"
                . '</script>';
        ?>

        <div class="relative">
            <pre id="code-snippet" class="p-4 bg-slate-900 text-indigo-300 rounded-xl font-mono text-xs overflow-x-auto border border-slate-800 leading-relaxed"><?= htmlspecialchars($scriptSnippet) ?></pre>
        </div>

        <p class="text-xs text-slate-500 flex items-center gap-1.5">
            <i data-lucide="shield-check" class="w-4 h-4 text-emerald-600"></i>
            <span>Allowed Origins for parent system <strong><?= htmlspecialchars($bot['system_name'] ?? '') ?></strong>: 
                <code class="font-mono text-[11px] bg-slate-100 text-slate-700 px-1.5 py-0.5 rounded"><?= htmlspecialchars($bot['allowed_origins'] ?: '*') ?></code>
            </span>
        </p>
    </div>

    <!-- Integration Instructions Tabs -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
        <h3 class="font-bold text-slate-900 text-base">Quick Installation Instructions</h3>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Option 1: Plain HTML / PHP -->
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-100 space-y-2">
                <div class="flex items-center gap-2 font-semibold text-xs text-slate-800">
                    <span class="w-6 h-6 rounded-md bg-indigo-100 text-indigo-700 flex items-center justify-center text-xs font-bold">1</span>
                    <span>HTML / PHP / WordPress</span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Paste the snippet directly into your layout template (e.g. <code>footer.php</code>, <code>index.html</code>, or before the closing <code>&lt;/body&gt;</code> tag).
                </p>
            </div>

            <!-- Option 2: React / Next.js -->
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-100 space-y-2">
                <div class="flex items-center gap-2 font-semibold text-xs text-slate-800">
                    <span class="w-6 h-6 rounded-md bg-indigo-100 text-indigo-700 flex items-center justify-center text-xs font-bold">2</span>
                    <span>React / Next.js</span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Use Next.js <code>&lt;Script&gt;</code> in your root layout:
                    <code class="block font-mono text-[11px] bg-white p-1 rounded border border-slate-200 mt-1">
                        &lt;Script src="<?= htmlspecialchars($apiHost) ?>/widget.js" data-bot-id="<?= htmlspecialchars($bot['id']) ?>" strategy="lazyOnload" /&gt;
                    </code>
                </p>
            </div>
        </div>
    </div>

    <!-- Live Test Sandbox -->
    <div class="p-6 rounded-2xl bg-indigo-50/50 border border-indigo-100 flex items-center justify-between">
        <div>
            <h4 class="font-bold text-indigo-950 text-sm">Testing this Bot right now?</h4>
            <p class="text-xs text-indigo-800 mt-0.5">The floating widget for this bot has been loaded at the bottom-right of this screen. Click it to test your live Ollama/Custom model!</p>
        </div>
        <span class="text-2xl">👉</span>
    </div>
</div>

<!-- Embed this bot right here for instant testing! -->
<script 
    src="<?= htmlspecialchars($apiHost) ?>/widget.js" 
    data-bot-id="<?= htmlspecialchars($bot['id']) ?>" 
    data-api-host="<?= htmlspecialchars($apiHost) ?>" 
    defer>
</script>

<script>
    function copySnippet() {
        var code = document.getElementById('code-snippet').textContent;
        navigator.clipboard.writeText(code).then(function() {
            var copyText = document.getElementById('copy-text');
            var btn = document.getElementById('btn-copy');
            copyText.textContent = 'Copied to Clipboard!';
            btn.classList.remove('bg-indigo-600', 'hover:bg-indigo-700');
            btn.classList.add('bg-emerald-600');

            setTimeout(function() {
                copyText.textContent = 'Copy Embed Code';
                btn.classList.add('bg-indigo-600', 'hover:bg-indigo-700');
                btn.classList.remove('bg-emerald-600');
            }, 2500);
        });
    }
</script>
