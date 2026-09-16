<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'Chatbot Management Hub') ?></title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#eef2ff',
                            100: '#e0e7ff',
                            500: '#6366f1',
                            600: '#4f46e5',
                            700: '#4338ca',
                            900: '#312e81',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="h-full font-sans antialiased text-slate-800 flex flex-col md:flex-row">

    <!-- Sidebar Navigation -->
    <aside class="w-full md:w-64 bg-slate-900 text-slate-200 flex flex-col shrink-0 border-r border-slate-800">
        <!-- Logo & Branding -->
        <div class="h-16 flex items-center px-6 gap-3 border-b border-slate-800">
            <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-md shadow-indigo-500/20">
                <i data-lucide="bot" class="w-5 h-5"></i>
            </div>
            <div>
                <h1 class="font-bold text-white text-base tracking-tight leading-tight">Chatbot Hub</h1>
                <span class="text-[11px] font-medium text-slate-400 uppercase tracking-wider">Multi-System Admin</span>
            </div>
        </div>

        <!-- Navigation Links -->
        <nav class="flex-1 px-3 py-4 space-y-1">
            <?php 
                $uri = $_SERVER['REQUEST_URI'];
                function navActive($path, $uri) {
                    if ($path === '/' && ($uri === '/' || $uri === '')) return 'bg-indigo-600/10 text-indigo-400 font-semibold border-l-2 border-indigo-500';
                    if ($path !== '/' && strpos($uri, $path) === 0) return 'bg-indigo-600/10 text-indigo-400 font-semibold border-l-2 border-indigo-500';
                    return 'text-slate-300 hover:bg-slate-800/60 hover:text-white';
                }
            ?>
            <a href="/" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors <?= navActive('/', $uri) ?>">
                <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                <span>Dashboard</span>
            </a>
            <a href="/systems" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors <?= navActive('/systems', $uri) ?>">
                <i data-lucide="layers" class="w-4 h-4"></i>
                <span>Systems / Workspaces</span>
            </a>
            <a href="/bots" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors <?= navActive('/bots', $uri) ?>">
                <i data-lucide="bot" class="w-4 h-4"></i>
                <span>Bot Profiles</span>
            </a>
            <a href="/logs" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-colors <?= navActive('/logs', $uri) ?>">
                <i data-lucide="message-square" class="w-4 h-4"></i>
                <span>Conversations & Logs</span>
            </a>
        </nav>

        <!-- Environment & Status Footer -->
        <div class="p-4 border-t border-slate-800 text-xs text-slate-400">
            <div class="flex items-center justify-between mb-2">
                <span class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>FastAPI Engine</span>
                </span>
                <span class="font-mono text-[11px] text-slate-300">:8000</span>
            </div>
            <div class="flex items-center justify-between">
                <span>PHP Admin</span>
                <span class="font-mono text-[11px] text-slate-300">PHP 8.5</span>
            </div>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
        <!-- Topbar -->
        <header class="h-16 bg-white border-b border-slate-200 px-6 flex items-center justify-between sticky top-0 z-10">
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 border border-indigo-100">
                    Ollama & Custom OpenAI-Compatible
                </span>
            </div>
            <div class="flex items-center gap-3">
                <a href="/bots/create" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium shadow-sm transition-colors">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                    <span>New Bot Profile</span>
                </a>
            </div>
        </header>

        <!-- Flash Messages -->
        <?php if (!empty($_GET['success'])): ?>
            <div class="mx-6 mt-4 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center gap-3 shadow-sm">
                <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 shrink-0"></i>
                <span><?= htmlspecialchars($_GET['success']) ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($_GET['error'])): ?>
            <div class="mx-6 mt-4 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-center gap-3 shadow-sm">
                <i data-lucide="alert-circle" class="w-5 h-5 text-rose-600 shrink-0"></i>
                <span><?= htmlspecialchars($_GET['error']) ?></span>
            </div>
        <?php endif; ?>

        <!-- Page Body Content -->
        <main class="p-6 md:p-8 flex-1">
            <?= $content ?? '' ?>
        </main>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
