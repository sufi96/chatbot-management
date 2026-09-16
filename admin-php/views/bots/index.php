<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Bot Profiles</h2>
            <p class="text-sm text-slate-500 mt-1">Configure individual AI personas, LLM providers, and embed widgets.</p>
        </div>
        <a href="/bots/create" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-sm transition-colors">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Create Bot Profile</span>
        </a>
    </div>

    <!-- Bots Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($bots as $bot): ?>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden flex flex-col justify-between hover:shadow-md transition-shadow">
                <div class="p-6">
                    <!-- Top Bar: Avatar, Title, Status -->
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center text-white text-xl font-bold shadow-sm" style="background-color: <?= htmlspecialchars($bot['widget_primary_color'] ?: '#4F46E5') ?>">
                                🤖
                            </div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-base leading-tight"><?= htmlspecialchars($bot['name']) ?></h3>
                                <span class="inline-flex items-center gap-1 text-xs text-slate-500 mt-0.5">
                                    <i data-lucide="layers" class="w-3 h-3 text-slate-400"></i>
                                    <?= htmlspecialchars($bot['system_name'] ?? 'Unassigned') ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($bot['is_active']): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Active
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-500">
                                Inactive
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Bot Model Specs -->
                    <div class="space-y-2.5 bg-slate-50 rounded-xl p-3.5 border border-slate-100 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500 font-medium">Provider:</span>
                            <span class="font-semibold uppercase px-2 py-0.5 rounded text-[11px] <?= $bot['provider_type'] === 'ollama' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' ?>">
                                <?= htmlspecialchars($bot['provider_type']) ?>
                            </span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500 font-medium">Model:</span>
                            <span class="font-mono font-semibold text-slate-800"><?= htmlspecialchars($bot['model_name']) ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500 font-medium">Endpoint:</span>
                            <span class="font-mono text-[11px] text-slate-600 truncate max-w-[180px]" title="<?= htmlspecialchars($bot['base_url']) ?>">
                                <?= htmlspecialchars($bot['base_url']) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Prompt Preview -->
                    <div class="mt-4">
                        <div class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">System Prompt:</div>
                        <p class="text-xs text-slate-600 line-clamp-2 italic bg-white p-2 rounded border border-slate-100">
                            "<?= htmlspecialchars($bot['system_prompt']) ?>"
                        </p>
                    </div>
                </div>

                <!-- Card Actions -->
                <div class="px-6 py-4 bg-slate-50/70 border-t border-slate-100 flex items-center justify-between gap-2">
                    <a href="/bots/<?= urlencode($bot['id']) ?>/embed" class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs transition-colors shadow-sm">
                        <i data-lucide="code" class="w-4 h-4"></i>
                        <span>Get Embed Code</span>
                    </a>
                    <a href="/bots/<?= urlencode($bot['id']) ?>/edit" class="px-3 py-2 rounded-lg border border-slate-200 hover:bg-white text-slate-700 font-medium text-xs transition-colors">
                        Edit
                    </a>
                    <form method="POST" action="/bots/<?= urlencode($bot['id']) ?>/delete" onsubmit="return confirm('Delete this bot profile?');">
                        <button type="submit" class="p-2 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition-colors" title="Delete">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
