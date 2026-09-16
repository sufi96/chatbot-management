<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Conversations & Transcripts</h2>
            <p class="text-sm text-slate-500 mt-1">Audit user conversations and view responses across all active bots.</p>
        </div>

        <!-- Filter by Bot -->
        <form method="GET" action="/logs" class="flex items-center gap-2">
            <select name="bot_id" onchange="this.form.submit()" class="px-3 py-2 rounded-lg border border-slate-300 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">All Bot Profiles</option>
                <?php foreach ($bots as $b): ?>
                    <option value="<?= htmlspecialchars($b['id']) ?>" <?= ($selectedBot == $b['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- Conversations Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <?php if (empty($conversations)): ?>
            <div class="p-12 text-center text-slate-500">
                <i data-lucide="message-square" class="w-8 h-8 text-slate-300 mx-auto mb-2"></i>
                <p class="text-sm font-medium">No conversation sessions recorded yet.</p>
                <p class="text-xs text-slate-400 mt-0.5">Conversations from external embedded widgets will appear here automatically.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-600">
                    <thead class="bg-slate-50 text-slate-500 font-semibold uppercase tracking-wider border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3.5">Bot & System</th>
                            <th class="px-6 py-3.5">First User Message</th>
                            <th class="px-6 py-3.5">Messages</th>
                            <th class="px-6 py-3.5">Origin Domain</th>
                            <th class="px-6 py-3.5">Time</th>
                            <th class="px-6 py-3.5 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-normal">
                        <?php foreach ($conversations as $conv): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars($conv['bot_name']) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars($conv['system_name']) ?></div>
                                </td>
                                <td class="px-6 py-4 max-w-xs">
                                    <p class="truncate text-slate-800 font-medium">
                                        <?= htmlspecialchars($conv['first_user_msg'] ?: 'No messages yet') ?>
                                    </p>
                                    <span class="text-[10px] text-slate-400 font-mono">Sess: <?= htmlspecialchars($conv['session_id']) ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">
                                        <?= intval($conv['msg_count']) ?> msgs
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="font-mono text-xs text-slate-600">
                                        <?= htmlspecialchars($conv['origin'] ?: 'Direct / Local') ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-slate-400 text-xs">
                                    <?= htmlspecialchars($conv['created_at']) ?>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <button onclick="viewTranscript('<?= htmlspecialchars($conv['id']) ?>', '<?= htmlspecialchars(addslashes($conv['bot_name'])) ?>')" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium text-xs transition-colors">
                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                        <span>Transcript</span>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Transcript Modal -->
    <div id="transcript-modal" class="hidden fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-2xl w-full max-h-[85vh] flex flex-col shadow-2xl overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between bg-slate-50">
                <div>
                    <h3 class="font-bold text-slate-900 text-sm">Conversation Transcript</h3>
                    <p class="text-xs text-slate-500" id="modal-bot-name">Bot</p>
                </div>
                <button onclick="document.getElementById('transcript-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <div class="p-6 overflow-y-auto space-y-4 flex-1 bg-slate-50/50 text-xs" id="transcript-messages">
                <p class="text-slate-400 text-center">Loading transcript...</p>
            </div>

            <div class="px-6 py-3 border-t border-slate-200 bg-white flex justify-end">
                <button onclick="document.getElementById('transcript-modal').classList.add('hidden')" class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    function viewTranscript(convId, botName) {
        document.getElementById('modal-bot-name').textContent = 'Bot: ' + botName;
        var container = document.getElementById('transcript-messages');
        container.innerHTML = '<p class="text-slate-400 text-center">Loading messages...</p>';
        document.getElementById('transcript-modal').classList.remove('hidden');

        fetch('/logs/' + encodeURIComponent(convId) + '/transcript')
            .then(function(res) { return res.json(); })
            .then(function(data) {
                container.innerHTML = '';
                if (!data.messages || data.messages.length === 0) {
                    container.innerHTML = '<p class="text-slate-400 text-center">No messages found for this session.</p>';
                    return;
                }
                data.messages.forEach(function(msg) {
                    var div = document.createElement('div');
                    var isUser = msg.sender === 'user';
                    div.className = 'flex flex-col ' + (isUser ? 'items-end' : 'items-start');

                    var bubble = document.createElement('div');
                    bubble.className = 'p-3 rounded-xl max-w-[85%] ' + (isUser ? 'bg-indigo-600 text-white rounded-br-none' : 'bg-white border border-slate-200 text-slate-800 rounded-bl-none shadow-sm');
                    bubble.textContent = msg.content;

                    var time = document.createElement('span');
                    time.className = 'text-[10px] text-slate-400 mt-1 px-1';
                    time.textContent = (isUser ? 'User' : 'Bot') + ' • ' + (msg.created_at || '');

                    div.appendChild(bubble);
                    div.appendChild(time);
                    container.appendChild(div);
                });
            })
            .catch(function(err) {
                container.innerHTML = '<p class="text-rose-500 text-center">Failed to load transcript.</p>';
            });
    }
</script>
