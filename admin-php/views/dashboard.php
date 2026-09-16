<div class="space-y-8">
    <!-- Hero / Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Overview Dashboard</h2>
            <p class="text-sm text-slate-500 mt-1">Manage all chatbot profiles across multiple systems and generate 1-line embed codes.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="/systems" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-medium transition-colors shadow-sm">
                <i data-lucide="layers" class="w-4 h-4 text-slate-500"></i>
                <span>Manage Systems</span>
            </a>
            <a href="/bots/create" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-sm transition-colors">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Create Bot Profile</span>
            </a>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                <i data-lucide="layers" class="w-6 h-6"></i>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Systems</p>
                <h3 class="text-2xl font-bold text-slate-900 mt-0.5"><?= $systemCount ?></h3>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                <i data-lucide="bot" class="w-6 h-6"></i>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Bot Profiles</p>
                <h3 class="text-2xl font-bold text-slate-900 mt-0.5"><?= $botCount ?></h3>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0">
                <i data-lucide="check-circle-2" class="w-6 h-6"></i>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Active Bots</p>
                <h3 class="text-2xl font-bold text-slate-900 mt-0.5"><?= $activeBotCount ?></h3>
            </div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center shrink-0">
                <i data-lucide="message-square" class="w-6 h-6"></i>
            </div>
            <div>
                <p class="text-xs font-medium text-slate-500 uppercase tracking-wider">Total Messages</p>
                <h3 class="text-2xl font-bold text-slate-900 mt-0.5"><?= $messageCount ?></h3>
            </div>
        </div>
    </div>

    <!-- Active Bot Profiles Section -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50/50">
            <div>
                <h3 class="font-bold text-slate-900 text-sm">Configured Chatbot Profiles</h3>
                <p class="text-xs text-slate-500">Each bot profile can be customized with its own model, prompt, and embed code.</p>
            </div>
            <a href="/bots" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 flex items-center gap-1">
                View all <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            </a>
        </div>

        <?php if (empty($recentBots)): ?>
            <div class="p-12 text-center">
                <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 mx-auto flex items-center justify-center mb-3">
                    <i data-lucide="bot" class="w-6 h-6"></i>
                </div>
                <h4 class="text-sm font-semibold text-slate-700">No bot profiles configured yet</h4>
                <p class="text-xs text-slate-500 mt-1 mb-4">Create your first chatbot profile to start connecting Ollama or Custom LLMs.</p>
                <a href="/bots/create" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium transition-colors">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i> Create Bot Profile
                </a>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-600">
                    <thead class="bg-slate-50 text-slate-500 font-semibold uppercase tracking-wider border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3.5">Bot Name & Identity</th>
                            <th class="px-6 py-3.5">System / Workspace</th>
                            <th class="px-6 py-3.5">Provider & Model</th>
                            <th class="px-6 py-3.5">Status</th>
                            <th class="px-6 py-3.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-normal">
                        <?php foreach ($recentBots as $bot): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="w-8 h-8 rounded-lg flex items-center justify-center text-white font-bold text-xs shrink-0 shadow-sm" style="background-color: <?= htmlspecialchars($bot['widget_primary_color'] ?: '#4F46E5') ?>">
                                            🤖
                                        </span>
                                        <div>
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars($bot['name']) ?></div>
                                            <div class="text-[11px] text-slate-400 font-mono">ID: <?= htmlspecialchars($bot['id']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-slate-100 text-slate-700 font-medium text-xs">
                                        <i data-lucide="layers" class="w-3 h-3 text-slate-400"></i>
                                        <?= htmlspecialchars($bot['system_name'] ?? 'Unassigned') ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-semibold <?= $bot['provider_type'] === 'ollama' ? 'bg-amber-50 text-amber-700 border border-amber-200' : 'bg-blue-50 text-blue-700 border border-blue-200' ?>">
                                            <?= strtoupper(htmlspecialchars($bot['provider_type'])) ?>
                                        </span>
                                        <span class="font-mono text-slate-800 text-xs ml-1.5"><?= htmlspecialchars($bot['model_name']) ?></span>
                                    </div>
                                    <div class="text-[11px] text-slate-400 truncate max-w-xs mt-0.5"><?= htmlspecialchars($bot['base_url']) ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($bot['is_active']): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium bg-slate-100 text-slate-500">
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="/bots/<?= urlencode($bot['id']) ?>/embed" class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-medium text-xs transition-colors" title="Get Embed Code">
                                            <i data-lucide="code" class="w-3.5 h-3.5"></i>
                                            <span>Embed Code</span>
                                        </a>
                                        <a href="/bots/<?= urlencode($bot['id']) ?>/edit" class="p-1.5 text-slate-400 hover:text-slate-700 rounded-md hover:bg-slate-100 transition-colors" title="Edit Profile">
                                            <i data-lucide="settings" class="w-4 h-4"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick How-It-Works Guide -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-xs">1</div>
            <h4 class="font-bold text-slate-900 text-sm">Create a System Workspace</h4>
            <p class="text-xs text-slate-500 leading-relaxed">Group chatbots by client website, internal portal, or organization with customizable domain whitelists.</p>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-xs">2</div>
            <h4 class="font-bold text-slate-900 text-sm">Configure Model & Persona</h4>
            <p class="text-xs text-slate-500 leading-relaxed">Choose local Ollama or custom OpenAI-compatible endpoints with custom system prompts and colors.</p>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm space-y-2">
            <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-xs">3</div>
            <h4 class="font-bold text-slate-900 text-sm">Paste 1-Line Embed Code</h4>
            <p class="text-xs text-slate-500 leading-relaxed">Copy the generated <code>&lt;script&gt;</code> tag into any HTML, PHP, or React app. Zero backend changes needed!</p>
        </div>
    </div>
</div>
