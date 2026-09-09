<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Chatbot Hub' }} - Management Console</title>

    {{-- Theme resolves before first paint so the console never flashes light. --}}
    <script>
        (function () {
            try {
                var t = localStorage.getItem('console-theme');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) { /* private mode: keep the dark default */ }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/console.css') }}">
</head>
<body class="d-flex flex-md-row">

    <aside class="sidebar">
        <a href="{{ route('dashboard') }}" class="sidebar-brand">
            <span class="sidebar-mark"><i class="bi bi-hexagon-fill"></i></span>
            <span>Chatbot Hub</span>
        </a>

        @if(isset($activeSystem))
            <div class="workspace-switch">
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <span class="ws-label">Workspace</span>
                    @if($currentSystemRole === 'super_admin')
                        <span class="badge badge-role bg-danger-subtle">Super admin</span>
                    @elseif($currentSystemRole === 'system_admin')
                        <span class="badge badge-role bg-primary-subtle">Admin</span>
                    @elseif($currentSystemRole === 'editor')
                        <span class="badge badge-role bg-warning-subtle">Editor</span>
                    @else
                        <span class="badge badge-role bg-secondary-subtle">Viewer</span>
                    @endif
                </div>

                <div class="dropdown">
                    <button class="ws-button" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="text-truncate">{{ $activeSystem->name }}</span>
                        <i class="bi bi-chevron-expand" style="font-size: 0.75rem; color: var(--text-faint);"></i>
                    </button>
                    <ul class="dropdown-menu w-100">
                        <li><h6 class="dropdown-header">Switch workspace</h6></li>
                        @foreach($userSystems as $sys)
                            <li>
                                <form action="{{ route('systems.switch') }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="system_id" value="{{ $sys->id }}">
                                    <button type="submit" class="dropdown-item d-flex align-items-center justify-content-between gap-2 {{ $sys->id === $activeSystem->id ? 'active' : '' }}">
                                        <span class="text-truncate">{{ $sys->name }}</span>
                                        @if($sys->id === $activeSystem->id)
                                            <i class="bi bi-check2"></i>
                                        @endif
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <nav class="sidebar-nav">
            <div class="sidebar-group">Operate</div>

            <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i>
                <span>Dashboard</span>
            </a>

            <a href="{{ route('bots.index') }}" class="sidebar-link {{ request()->routeIs('bots.*') ? 'active' : '' }}">
                <i class="bi bi-cpu"></i>
                <span>Bot profiles</span>
            </a>

            <a href="{{ route('logs.index') }}" class="sidebar-link {{ request()->routeIs('logs.*') ? 'active' : '' }}">
                <i class="bi bi-chat-left-text"></i>
                <span>Conversations</span>
            </a>

            <div class="sidebar-group">Administer</div>

            <a href="{{ route('systems.index') }}" class="sidebar-link {{ request()->routeIs('systems.*') ? 'active' : '' }}">
                <i class="bi bi-diagram-3"></i>
                <span>Workspaces</span>
            </a>

            @if(auth()->user()->isSuperAdmin())
                <a href="{{ route('users.index') }}" class="sidebar-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                    <i class="bi bi-person-gear"></i>
                    <span>Users and roles</span>
                </a>
            @endif
        </nav>

        <div class="sidebar-foot d-flex align-items-center justify-content-between gap-2">
            <span class="d-inline-flex align-items-center gap-2">
                <span class="state-dot is-live"></span>
                <span>Streaming engine</span>
            </span>
            <span class="figure-mono">:8000</span>
        </div>
    </aside>

    <div class="content-wrapper">
        <header class="topbar">
            <h1 class="topbar-title">@yield('page-title', 'Console')</h1>

            <div class="d-flex align-items-center gap-2">
                <button type="button" class="icon-button" id="theme-toggle"
                        title="Switch between dark and light" aria-label="Switch between dark and light">
                    <i class="bi bi-circle-half"></i>
                </button>

                <div class="dropdown">
                    <button class="btn btn-outline-secondary d-flex align-items-center gap-2 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar-chip">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                        <span class="d-none d-sm-inline text-truncate" style="max-width: 160px;">{{ auth()->user()->name }}</span>
                        <i class="bi bi-chevron-down" style="font-size: 0.7rem; color: var(--text-faint);"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="min-width: 220px;">
                        <li class="px-2 py-1">
                            <div class="text-muted" style="font-size: 0.6875rem;">Signed in as</div>
                            <div class="text-truncate" style="font-size: 0.8125rem;">{{ auth()->user()->email }}</div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="{{ route('logout') }}" method="POST">
                                @csrf
                                <button type="submit" class="dropdown-item d-flex align-items-center gap-2" style="color: var(--danger);">
                                    <i class="bi bi-box-arrow-right"></i> Sign out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="page">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
                    <i class="bi bi-check-circle"></i>
                    <div>{{ session('success') }}</div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Dismiss"></button>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <div>{{ session('error') }}</div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Dismiss"></button>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                    <div class="fw-semibold mb-1">Please correct the following:</div>
                    <ul class="mb-0 ps-3">
                        @foreach($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
                </div>
            @endif

            @yield('content')
        </main>
    </div>

    <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script>
        (function () {
            var root = document.documentElement;
            var button = document.getElementById('theme-toggle');
            if (!button) return;
            button.addEventListener('click', function () {
                var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
                root.setAttribute('data-theme', next);
                try { localStorage.setItem('console-theme', next); } catch (e) { /* not persisted */ }
            });
        })();

        // Used by the embed modal, which appears on several screens.
        function copyEmbedSnippet(botId) {
            var source = document.getElementById('embedSnippet' + botId);
            var icon = document.getElementById('embedCopyIcon' + botId);
            var label = document.getElementById('embedCopyText' + botId);
            if (!source) return;

            navigator.clipboard.writeText(source.textContent).then(function () {
                if (icon) icon.className = 'bi bi-check2';
                if (label) label.textContent = 'Copied';
                setTimeout(function () {
                    if (icon) icon.className = 'bi bi-clipboard';
                    if (label) label.textContent = 'Copy';
                }, 2000);
            }).catch(function () {
                if (label) label.textContent = 'Press Ctrl+C';
            });
        }
    </script>
    @stack('scripts')
</body>
</html>
