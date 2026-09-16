<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Systems & Workspaces</h2>
            <p class="text-sm text-slate-500 mt-1">Group your chatbot profiles under parent systems and control allowed domains.</p>
        </div>
        <button onclick="document.getElementById('new-system-modal').classList.remove('hidden')" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-sm transition-colors">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>New System</span>
        </button>
    </div>

    <!-- Systems Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($systems as $sys): ?>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 flex flex-col justify-between hover:border-slate-300 transition-colors">
                <div>
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
                            <i data-lucide="layers" class="w-5 h-5"></i>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                            <?= intval($sys['bot_count'] ?? 0) ?> Bots
                        </span>
                    </div>
                    <h3 class="font-bold text-slate-900 text-base"><?= htmlspecialchars($sys['name']) ?></h3>
                    <p class="text-xs text-slate-500 mt-1 line-clamp-2"><?= htmlspecialchars($sys['description'] ?: 'No description provided.') ?></p>
                    
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <div class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Allowed Origins / Whitelist:</div>
                        <code class="text-xs font-mono bg-slate-50 text-slate-700 px-2 py-1 rounded block truncate" title="<?= htmlspecialchars($sys['allowed_origins']) ?>">
                            <?= htmlspecialchars($sys['allowed_origins'] ?: '*') ?>
                        </code>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-between text-xs">
                    <span class="font-mono text-[11px] text-slate-400">ID: <?= htmlspecialchars($sys['id']) ?></span>
                    <div class="flex items-center gap-2">
                        <form method="POST" action="/systems/<?= urlencode($sys['id']) ?>/delete" onsubmit="return confirm('Are you sure you want to delete this system and all its bots?');">
                            <button type="submit" class="p-1.5 text-slate-400 hover:text-rose-600 rounded-md hover:bg-rose-50 transition-colors" title="Delete System">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Modal for New System -->
    <div id="new-system-modal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h3 class="font-bold text-slate-900 text-base">Create New System Workspace</h3>
                <button onclick="document.getElementById('new-system-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <form method="POST" action="/systems" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">System Name <span class="text-rose-500">*</span></label>
                    <input type="text" name="name" required placeholder="e.g. Corporate Portal, Client E-Shop" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Description</label>
                    <textarea name="description" rows="2" placeholder="Brief note about which applications this system covers..." class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Allowed Origins (CORS Security)</label>
                    <input type="text" name="allowed_origins" value="*" placeholder="e.g. * or https://app.example.com, https://portal.example.com" class="w-full px-3 py-2 rounded-lg border border-slate-300 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <p class="text-[11px] text-slate-500 mt-1">Use <code>*</code> to allow all domains, or comma-separated list of authorized website origins.</p>
                </div>

                <div class="flex justify-end gap-2 pt-3">
                    <button type="button" onclick="document.getElementById('new-system-modal').classList.add('hidden')" class="px-4 py-2 rounded-lg border border-slate-200 text-slate-600 text-xs font-medium hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold shadow-sm">
                        Create System
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
