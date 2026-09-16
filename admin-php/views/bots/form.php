<div class="max-w-6xl mx-auto space-y-8">
    <!-- Top Header -->
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs font-semibold text-indigo-600 mb-1">
                <a href="/bots" class="hover:underline flex items-center gap-1"><i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to Bot Profiles</a>
            </div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">
                <?= $isEdit ? 'Edit Bot Profile: ' . htmlspecialchars($bot['name']) : 'Create New Bot Profile' ?>
            </h2>
            <p class="text-sm text-slate-500 mt-0.5">Configure model provider, system prompt, and custom widget appearance.</p>
        </div>
        <?php if ($isEdit): ?>
            <a href="/bots/<?= urlencode($bot['id']) ?>/embed" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-sm transition-colors">
                <i data-lucide="code" class="w-4 h-4"></i>
                <span>Get Embed Code</span>
            </a>
        <?php endif; ?>
    </div>

    <form method="POST" action="<?= $isEdit ? '/bots/' . urlencode($bot['id']) : '/bots' ?>" id="bot-form" class="space-y-8">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Left 2 Columns: Settings -->
            <div class="lg:col-span-2 space-y-6">

                <!-- 1. Basic Identity & System -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                    <h3 class="font-bold text-slate-900 text-base flex items-center gap-2">
                        <i data-lucide="info" class="w-4 h-4 text-indigo-600"></i>
                        <span>1. System & Identity</span>
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Target System Workspace <span class="text-rose-500">*</span></label>
                            <select name="system_id" required class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white">
                                <?php foreach ($systems as $sys): ?>
                                    <option value="<?= htmlspecialchars($sys['id']) ?>" <?= ($bot['system_id'] == $sys['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sys['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Bot Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="name" id="input-bot-name" required value="<?= htmlspecialchars($bot['name']) ?>" placeholder="e.g. Sales Assistant, HR Bot" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>

                    <div class="flex items-center gap-2 pt-2">
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" <?= $bot['is_active'] ? 'checked' : '' ?> class="sr-only peer">
                            <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                            <span class="ml-2 text-xs font-medium text-slate-700">Active (Accept conversations)</span>
                        </label>
                    </div>
                </div>

                <!-- 2. LLM Provider Configuration -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-slate-900 text-base flex items-center gap-2">
                            <i data-lucide="cpu" class="w-4 h-4 text-indigo-600"></i>
                            <span>2. LLM Provider & Model Endpoint</span>
                        </h3>
                        <span class="text-[11px] px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 font-medium">OpenAI-Compatible Standard</span>
                    </div>

                    <!-- Provider Type Selector -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-2">Provider Type</label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 transition-colors has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50/40">
                                <input type="radio" name="provider_type" value="ollama" <?= ($bot['provider_type'] === 'ollama') ? 'checked' : '' ?> onchange="applyProviderPreset('ollama')" class="text-indigo-600 focus:ring-indigo-500">
                                <div>
                                    <div class="font-semibold text-slate-900 text-xs">Local Ollama</div>
                                    <div class="text-[11px] text-slate-500">http://localhost:11434/v1</div>
                                </div>
                            </label>
                            <label class="flex items-center gap-3 p-3 rounded-xl border border-slate-200 cursor-pointer hover:bg-slate-50 transition-colors has-[:checked]:border-indigo-600 has-[:checked]:bg-indigo-50/40">
                                <input type="radio" name="provider_type" value="custom" <?= ($bot['provider_type'] === 'custom') ? 'checked' : '' ?> onchange="applyProviderPreset('custom')" class="text-indigo-600 focus:ring-indigo-500">
                                <div>
                                    <div class="font-semibold text-slate-900 text-xs">Custom API / Remote</div>
                                    <div class="text-[11px] text-slate-500">Any OpenAI-compatible server</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Base URL & API Key -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Base URL <span class="text-rose-500">*</span></label>
                            <input type="text" name="base_url" id="base_url" required value="<?= htmlspecialchars($bot['base_url']) ?>" placeholder="http://localhost:11434/v1" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <p class="text-[11px] text-slate-400 mt-1">Ollama: <code>http://localhost:11434/v1</code></p>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">API Key (Optional for Ollama)</label>
                            <input type="password" name="api_key" id="api_key" value="<?= htmlspecialchars($bot['api_key'] ?? '') ?>" placeholder="Leave empty for local Ollama" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <p class="text-[11px] text-slate-400 mt-1">Required for OpenAI, Groq, or protected vLLM.</p>
                        </div>
                    </div>

                    <!-- Model Name, Temperature, Max Tokens -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Model Name <span class="text-rose-500">*</span></label>
                            <input type="text" name="model_name" id="model_name" required value="<?= htmlspecialchars($bot['model_name']) ?>" placeholder="llama3.2, mistral, gpt-4o" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Temperature</label>
                            <input type="number" step="0.1" min="0" max="2" name="temperature" value="<?= htmlspecialchars($bot['temperature']) ?>" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Max Tokens</label>
                            <input type="number" step="64" min="64" max="8192" name="max_tokens" value="<?= htmlspecialchars($bot['max_tokens']) ?>" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>

                    <!-- Connection Tester Button -->
                    <div class="pt-2 flex items-center gap-3">
                        <button type="button" onclick="testConnection()" id="btn-test-conn" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition-colors">
                            <i data-lucide="zap" class="w-3.5 h-3.5 text-amber-500"></i>
                            <span>Test Endpoint Connection</span>
                        </button>
                        <span id="test-conn-result" class="text-xs font-medium"></span>
                    </div>
                </div>

                <!-- 3. System Prompt & Persona -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-slate-900 text-base flex items-center gap-2">
                            <i data-lucide="message-square-code" class="w-4 h-4 text-indigo-600"></i>
                            <span>3. System Prompt & Instructions</span>
                        </h3>
                    </div>

                    <!-- Prompt Presets -->
                    <div class="flex flex-wrap gap-2">
                        <span class="text-xs text-slate-400 self-center">Presets:</span>
                        <button type="button" onclick="setPromptPreset('support')" class="text-[11px] px-2.5 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium">Customer Support</button>
                        <button type="button" onclick="setPromptPreset('sales')" class="text-[11px] px-2.5 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium">Sales Assistant</button>
                        <button type="button" onclick="setPromptPreset('technical')" class="text-[11px] px-2.5 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium">Technical Support</button>
                    </div>

                    <div>
                        <textarea name="system_prompt" id="system_prompt" rows="4" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-xs leading-relaxed focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono"><?= htmlspecialchars($bot['system_prompt']) ?></textarea>
                        <p class="text-[11px] text-slate-400 mt-1">This guides the personality, rules, tone, and guardrails of your bot.</p>
                    </div>
                </div>

                <!-- 4. Widget Styling -->
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-4">
                    <h3 class="font-bold text-slate-900 text-base flex items-center gap-2">
                        <i data-lucide="palette" class="w-4 h-4 text-indigo-600"></i>
                        <span>4. Widget Customizer</span>
                    </h3>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Widget Header Title</label>
                            <input type="text" name="widget_title" id="widget_title" value="<?= htmlspecialchars($bot['widget_title']) ?>" oninput="updateLivePreview()" placeholder="e.g. Chat Support" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Widget Position</label>
                            <select name="widget_position" id="widget_position" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-white">
                                <option value="bottom-right" <?= ($bot['widget_position'] === 'bottom-right') ? 'selected' : '' ?>>Bottom Right</option>
                                <option value="bottom-left" <?= ($bot['widget_position'] === 'bottom-left') ? 'selected' : '' ?>>Bottom Left</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Initial Welcome Greeting</label>
                        <input type="text" name="widget_greeting" id="widget_greeting" value="<?= htmlspecialchars($bot['widget_greeting']) ?>" oninput="updateLivePreview()" placeholder="Hello! How can I help you today?" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <!-- Color Picker & Swatches -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Brand Theme Color</label>
                        <div class="flex items-center gap-3">
                            <input type="color" name="widget_primary_color" id="widget_primary_color" value="<?= htmlspecialchars($bot['widget_primary_color'] ?: '#4F46E5') ?>" oninput="updateLivePreview()" class="w-10 h-10 p-0.5 rounded-lg border border-slate-200 cursor-pointer">
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="setPrimaryColor('#4F46E5')" class="w-7 h-7 rounded-full bg-[#4F46E5] shadow-sm"></button>
                                <button type="button" onclick="setPrimaryColor('#0ea5e9')" class="w-7 h-7 rounded-full bg-[#0ea5e9] shadow-sm"></button>
                                <button type="button" onclick="setPrimaryColor('#10b981')" class="w-7 h-7 rounded-full bg-[#10b981] shadow-sm"></button>
                                <button type="button" onclick="setPrimaryColor('#8b5cf6')" class="w-7 h-7 rounded-full bg-[#8b5cf6] shadow-sm"></button>
                                <button type="button" onclick="setPrimaryColor('#f97316')" class="w-7 h-7 rounded-full bg-[#f97316] shadow-sm"></button>
                                <button type="button" onclick="setPrimaryColor('#0f172a')" class="w-7 h-7 rounded-full bg-[#0f172a] shadow-sm"></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="flex items-center justify-end gap-3 pt-4">
                    <a href="/bots" class="px-5 py-2.5 rounded-xl border border-slate-200 text-slate-600 text-sm font-medium hover:bg-slate-50">
                        Cancel
                    </a>
                    <button type="submit" class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold shadow-md shadow-indigo-600/20 transition-colors">
                        <?= $isEdit ? 'Save Changes' : 'Create Bot Profile' ?>
                    </button>
                </div>
            </div>

            <!-- Right Column: Interactive Live Preview -->
            <div class="space-y-4">
                <div class="sticky top-24 space-y-4">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wider text-slate-400">Live Widget Preview</span>
                        <span class="text-[11px] px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-medium">Real-time preview</span>
                    </div>

                    <!-- Mock Widget Box -->
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-xl overflow-hidden flex flex-col h-[480px]">
                        <!-- Preview Header -->
                        <div id="preview-header" class="p-4 text-white flex items-center justify-between" style="background-color: <?= htmlspecialchars($bot['widget_primary_color'] ?: '#4F46E5') ?>">
                            <div class="flex items-center gap-2.5">
                                <div class="w-7 h-7 rounded-full bg-white/20 flex items-center justify-center text-xs">🤖</div>
                                <div>
                                    <div id="preview-title" class="font-semibold text-xs leading-tight"><?= htmlspecialchars($bot['widget_title'] ?: 'AI Assistant') ?></div>
                                    <div class="text-[10px] text-white/80 flex items-center gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Online
                                    </div>
                                </div>
                            </div>
                            <i data-lucide="x" class="w-4 h-4 text-white/80"></i>
                        </div>

                        <!-- Preview Chat Body -->
                        <div class="flex-1 p-4 bg-slate-50 flex flex-col gap-3 overflow-y-auto text-xs">
                            <!-- Bot Greeting -->
                            <div class="flex flex-col items-start max-w-[85%]">
                                <div id="preview-greeting" class="bg-white p-3 rounded-2xl rounded-bl-sm border border-slate-200 text-slate-800 shadow-sm leading-relaxed">
                                    <?= htmlspecialchars($bot['widget_greeting'] ?: 'Hello! How can I help you today?') ?>
                                </div>
                                <span class="text-[10px] text-slate-400 mt-1">Just now</span>
                            </div>

                            <!-- Sample User Reply -->
                            <div class="flex flex-col items-end self-end max-w-[85%]">
                                <div id="preview-user-bubble" class="p-3 rounded-2xl rounded-br-sm text-white shadow-sm leading-relaxed" style="background-color: <?= htmlspecialchars($bot['widget_primary_color'] ?: '#4F46E5') ?>">
                                    Can you help me answer a question?
                                </div>
                                <span class="text-[10px] text-slate-400 mt-1">Just now</span>
                            </div>
                        </div>

                        <!-- Preview Input Footer -->
                        <div class="p-3 bg-white border-t border-slate-100 flex items-center gap-2">
                            <div class="flex-1 bg-slate-100 rounded-lg px-3 py-1.5 text-xs text-slate-400">
                                Type a message...
                            </div>
                            <div id="preview-send-btn" class="w-7 h-7 rounded-lg text-white flex items-center justify-center text-xs" style="background-color: <?= htmlspecialchars($bot['widget_primary_color'] ?: '#4F46E5') ?>">
                                <i data-lucide="send" class="w-3.5 h-3.5"></i>
                            </div>
                        </div>
                    </div>

                    <div class="p-4 rounded-xl bg-indigo-50/50 border border-indigo-100 text-xs text-indigo-900 leading-relaxed">
                        💡 <strong>Tip:</strong> The widget is completely isolated using <em>Shadow DOM</em>, ensuring it looks identical across every external host system.
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    function updateLivePreview() {
        var title = document.getElementById('widget_title').value || 'AI Assistant';
        var greeting = document.getElementById('widget_greeting').value || 'Hello!';
        var color = document.getElementById('widget_primary_color').value || '#4F46E5';

        document.getElementById('preview-title').textContent = title;
        document.getElementById('preview-greeting').textContent = greeting;
        document.getElementById('preview-header').style.backgroundColor = color;
        document.getElementById('preview-user-bubble').style.backgroundColor = color;
        document.getElementById('preview-send-btn').style.backgroundColor = color;
    }

    function setPrimaryColor(hex) {
        document.getElementById('widget_primary_color').value = hex;
        updateLivePreview();
    }

    function applyProviderPreset(type) {
        if (type === 'ollama') {
            document.getElementById('base_url').value = 'http://localhost:11434/v1';
            document.getElementById('api_key').value = '';
            document.getElementById('model_name').value = 'llama3.2';
        } else {
            document.getElementById('base_url').value = 'https://api.openai.com/v1';
            document.getElementById('model_name').value = 'gpt-4o';
        }
    }

    function setPromptPreset(type) {
        var el = document.getElementById('system_prompt');
        if (type === 'support') {
            el.value = "You are a professional customer support AI assistant. Answer user inquiries clearly, politely, and succinctly. If you don't know the answer, politely advise them to contact support.";
        } else if (type === 'sales') {
            el.value = "You are an enthusiastic and knowledgeable sales assistant. Highlight product features, clarify pricing and benefits, and guide prospects toward taking the next step.";
        } else if (type === 'technical') {
            el.value = "You are an expert technical support engineer. Provide step-by-step diagnostic instructions and clean code snippets where applicable.";
        }
    }

    function testConnection() {
        var baseUrl = document.getElementById('base_url').value;
        var apiKey = document.getElementById('api_key').value;
        var modelName = document.getElementById('model_name').value;
        var statusEl = document.getElementById('test-conn-result');
        var btn = document.getElementById('btn-test-conn');

        statusEl.className = 'text-xs font-medium text-slate-500';
        statusEl.textContent = 'Testing connection...';
        btn.disabled = true;

        fetch('http://localhost:8000/api/v1/bot/test-connection', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                base_url: baseUrl,
                api_key: apiKey,
                model_name: modelName
            })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            btn.disabled = false;
            if (data.success) {
                statusEl.className = 'text-xs font-medium text-emerald-600';
                statusEl.textContent = '✅ ' + data.message;
            } else {
                statusEl.className = 'text-xs font-medium text-rose-600';
                statusEl.textContent = '❌ ' + data.message;
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            statusEl.className = 'text-xs font-medium text-rose-600';
            statusEl.textContent = '❌ Could not reach FastAPI engine at localhost:8000';
        });
    }
</script>
