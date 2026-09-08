<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Chatbot Hub' }} - Management Portal</title>

    <!-- Google Fonts: Plus Jakarta Sans & Inter for World-Class Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Local Pre-bundled Bootstrap 5 & CDN Bootstrap Icons -->
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        :root {
            --font-sans: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            --sidebar-bg: #090d16;
            --sidebar-surface: #111827;
            --sidebar-border: rgba(255, 255, 255, 0.08);
            --sidebar-hover: rgba(255, 255, 255, 0.06);
            --sidebar-active: rgba(99, 102, 241, 0.16);
            --sidebar-text: #94a3b8;
            --sidebar-text-active: #ffffff;
            --brand-primary: #4f46e5;
            --brand-primary-hover: #4338ca;
            --surface-bg: #f8fafc;
            --card-border: #eef2f6;
            --card-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.05), 0 2px 6px -1px rgba(15, 23, 42, 0.02);
            --card-shadow-hover: 0 14px 30px -4px rgba(15, 23, 42, 0.1), 0 4px 10px -2px rgba(15, 23, 42, 0.04);
        }

        * {
            box-sizing: border-box;
        }

        /* Prevent button text wrapping into multi-line chaos */
        .btn-nowrap {
            white-space: nowrap !important;
            flex-shrink: 0;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--surface-bg);
            color: #0f172a;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            letter-spacing: -0.011em;
        }

        /* Sidebar Styles */
        .sidebar {
            width: 270px;
            background-color: var(--sidebar-bg);
            color: var(--sidebar-text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--sidebar-border);
            flex-shrink: 0;
            z-index: 110;
        }

        .sidebar-brand {
            height: 70px;
            padding: 0 1.35rem;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            border-bottom: 1px solid var(--sidebar-border);
            color: #ffffff;
            font-weight: 700;
            font-size: 1.1rem;
            text-decoration: none;
            letter-spacing: -0.02em;
        }

        .sidebar-brand-icon {
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #d946ef 100%);
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-size: 1.25rem;
            box-shadow: 0 6px 18px rgba(99, 102, 241, 0.4);
            transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .sidebar-brand:hover .sidebar-brand-icon {
            transform: scale(1.06) rotate(-3deg);
        }

        .sidebar-nav {
            padding: 1rem 0.75rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.7rem 0.95rem;
            border-radius: 10px;
            color: #94a3b8;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        .sidebar-link i {
            font-size: 1.15rem;
            transition: transform 0.2s ease;
        }

        .sidebar-link:hover {
            background-color: var(--sidebar-hover);
            color: #ffffff;
            transform: translateX(2px);
        }

        .sidebar-link:hover i {
            transform: scale(1.1);
        }

        .sidebar-link.active {
            background: linear-gradient(90deg, rgba(99, 102, 241, 0.2) 0%, rgba(99, 102, 241, 0.08) 100%);
            color: #a5b4fc;
            font-weight: 600;
            border-left: 3px solid #6366f1;
            box-shadow: inset 0 0 12px rgba(99, 102, 241, 0.12);
        }

        .sidebar-link.active i {
            color: #818cf8;
        }

        .sidebar-section-title {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            font-weight: 700;
            padding: 1rem 0.95rem 0.4rem 0.95rem;
        }

        /* Main Content Wrapper */
        .content-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            overflow-y: auto;
        }

        .top-navbar {
            height: 70px;
            background-color: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid #e2e8f0;
            padding: 0 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        /* Modern Elevated Card Architecture */
        .card {
            border: 1px solid var(--card-border);
            border-radius: 16px;
            background-color: #ffffff;
            box-shadow: var(--card-shadow);
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.2s ease;
        }

        .card-interactive:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
            border-color: #cbd5e1;
        }

        .card-header {
            background-color: #ffffff;
            border-bottom: 1px solid #f1f5f9;
            padding: 1.15rem 1.45rem;
            font-weight: 600;
            border-top-left-radius: 16px !important;
            border-top-right-radius: 16px !important;
        }

        /* Buttons & Badges */
        .btn-brand {
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            color: #ffffff;
            font-weight: 600;
            border: none;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-brand:hover {
            background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
        }

        .btn-brand:active {
            transform: translateY(0);
        }

        .badge-role {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.38em 0.72em;
            border-radius: 8px;
        }

        /* Subtle badge palettes for clean modern contrast */
        .bg-primary-subtle { background-color: #e0e7ff !important; color: #4338ca !important; }
        .bg-success-subtle { background-color: #dcfce7 !important; color: #15803d !important; }
        .bg-danger-subtle { background-color: #fee2e2 !important; color: #b91c1c !important; }
        .bg-warning-subtle { background-color: #fef3c7 !important; color: #b45309 !important; }
        .bg-info-subtle { background-color: #e0f2fe !important; color: #0369a1 !important; }
        .bg-secondary-subtle { background-color: #f1f5f9 !important; color: #475569 !important; }

        .border-primary-subtle { border-color: #c7d2fe !important; }
        .border-success-subtle { border-color: #bbf7d0 !important; }
        .border-danger-subtle { border-color: #fecaca !important; }
        .border-warning-subtle { border-color: #fde68a !important; }
        .border-info-subtle { border-color: #bae6fd !important; }
        .border-secondary-subtle { border-color: #e2e8f0 !important; }

        /* Modern Tables */
        .table th {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748b;
            background-color: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding-top: 0.85rem;
            padding-bottom: 0.85rem;
        }

        .table td {
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            padding-top: 0.95rem;
            padding-bottom: 0.95rem;
        }

        .table-hover tbody tr {
            transition: background-color 0.15s ease;
        }

        .table-hover tbody tr:hover {
            background-color: #f8fafc;
        }

        .form-control, .form-select {
            border-color: #cbd5e1;
            border-radius: 10px;
            font-size: 0.875rem;
            padding: 0.6rem 0.85rem;
            transition: all 0.15s ease-in-out;
        }

        .form-control:focus, .form-select:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }

        .btn {
            border-radius: 10px;
            font-weight: 500;
            letter-spacing: -0.01em;
            transition: all 0.15s ease;
        }

        /* Pulse Dot for Active Status */
        .status-pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #10b981;
            display: inline-block;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse-ring 2s infinite;
        }

        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6); }
            70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        /* Custom Scrollbars */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body class="h-100 d-flex flex-column flex-md-row">

    <!-- Sidebar Navigation -->
    <aside class="sidebar">
        <!-- Logo -->
        <a href="{{ route('dashboard') }}" class="sidebar-brand">
            <div class="sidebar-brand-icon">
                <i class="bi bi-robot"></i>
            </div>
            <div>
                <div class="lh-1">Chatbot Hub</div>
                <small class="text-secondary fw-normal" style="font-size: 0.72rem;">Laravel 13 Management</small>
            </div>
        </a>

        <!-- System Switcher Widget in Sidebar -->
        @if(isset($activeSystem))
            <div class="p-3 my-2 mx-2.5 rounded-3" style="background: rgba(255, 255, 255, 0.04); border: 1px solid rgba(255, 255, 255, 0.08);">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <small class="text-secondary fw-bold text-uppercase" style="font-size: 0.65rem; letter-spacing: 0.06em;">Workspace</small>
                    @if($currentSystemRole === 'super_admin')
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle badge-role py-0.5 px-2">Super Admin</span>
                    @elseif($currentSystemRole === 'system_admin')
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle badge-role py-0.5 px-2">Admin</span>
                    @elseif($currentSystemRole === 'editor')
                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle badge-role py-0.5 px-2">Editor</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle badge-role py-0.5 px-2">Viewer</span>
                    @endif
                </div>

                <div class="dropdown">
                    <button class="btn btn-sm text-start w-100 d-flex align-items-center justify-content-between text-truncate py-1.5 px-2.5 border-0 rounded-2" style="background: rgba(0, 0, 0, 0.35); transition: background 0.15s ease;" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="text-truncate fw-semibold text-light small d-flex align-items-center gap-1.5">
                            <i class="bi bi-layers-fill text-primary" style="font-size: 0.85rem;"></i>
                            <span class="text-truncate">{{ $activeSystem->name }}</span>
                        </span>
                        <i class="bi bi-chevron-down ms-1 text-secondary" style="font-size: 0.72rem;"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark shadow-lg w-100 py-1.5" style="border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; background-color: #111827;">
                        <li><h6 class="dropdown-header text-uppercase text-secondary" style="font-size: 0.65rem; letter-spacing: 0.05em;">Switch Workspace</h6></li>
                        @foreach($userSystems as $sys)
                            <li>
                                <form action="{{ route('systems.switch') }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="system_id" value="{{ $sys->id }}">
                                    <button type="submit" class="dropdown-item d-flex align-items-center justify-content-between small py-2 px-3 {{ $sys->id === $activeSystem->id ? 'active fw-semibold' : '' }}" style="{{ $sys->id === $activeSystem->id ? 'background-color: rgba(99, 102, 241, 0.25); color: #c7d2fe;' : '' }}">
                                        <span class="text-truncate">{{ $sys->name }}</span>
                                        @if($sys->id === $activeSystem->id)
                                            <i class="bi bi-check-circle-fill text-primary ms-2" style="font-size: 0.85rem;"></i>
                                        @endif
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <!-- Nav Links -->
        <nav class="sidebar-nav">
            <div class="sidebar-section-title">Main Navigation</div>

            <a href="{{ route('dashboard') }}" class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-grid-1x2"></i>
                <span>Dashboard</span>
            </a>

            <a href="{{ route('systems.index') }}" class="sidebar-link {{ request()->routeIs('systems.*') ? 'active' : '' }}">
                <i class="bi bi-layers"></i>
                <span>Systems & Workspaces</span>
            </a>

            <a href="{{ route('bots.index') }}" class="sidebar-link {{ request()->routeIs('bots.*') ? 'active' : '' }}">
                <i class="bi bi-robot"></i>
                <span>Bot Profiles</span>
            </a>

            <a href="{{ route('logs.index') }}" class="sidebar-link {{ request()->routeIs('logs.*') ? 'active' : '' }}">
                <i class="bi bi-chat-left-dots"></i>
                <span>Conversations & Logs</span>
            </a>

            @if(auth()->user()->isSuperAdmin())
                <div class="sidebar-section-title mt-2">Administration</div>

                <a href="{{ route('users.index') }}" class="sidebar-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                    <i class="bi bi-people"></i>
                    <span>Users & RBAC</span>
                </a>
            @endif
        </nav>

        <!-- Sidebar Footer Status -->
        <div class="p-3 border-top border-dark text-secondary" style="font-size: 0.75rem; background-color: #0b1120;">
            <div class="d-flex align-items-center justify-content-between mb-1">
                <span class="d-flex align-items-center gap-1">
                    <span class="p-1 rounded-circle bg-success d-inline-block"></span>
                    <span>FastAPI Engine</span>
                </span>
                <span class="font-monospace text-light">:8000</span>
            </div>
            <div class="d-flex align-items-center justify-content-between">
                <span>Laravel Framework</span>
                <span class="text-light fw-medium">v13</span>
            </div>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="content-wrapper">
        <!-- Top Navbar -->
        <header class="top-navbar">
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-light text-primary border px-2.5 py-1.5 fw-semibold" style="font-size: 0.75rem;">
                    <i class="bi bi-hdd-network me-1"></i> LLM Engine: Ollama / Custom API
                </span>
            </div>

            <div class="d-flex align-items-center gap-3">
                <!-- User Profile Dropdown -->
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-2 border-0 py-1 px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold" style="width: 32px; height: 32px; font-size: 0.85rem;">
                            {{ substr(auth()->user()->name, 0, 1) }}
                        </div>
                        <div class="text-start d-none d-sm-block">
                            <div class="fw-semibold text-dark lh-1 small">{{ auth()->user()->name }}</div>
                            <small class="text-muted" style="font-size: 0.7rem;">{{ auth()->user()->isSuperAdmin() ? 'Super Admin' : 'User' }}</small>
                        </div>
                        <i class="bi bi-chevron-down ms-1 text-muted" style="font-size: 0.75rem;"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li class="px-3 py-1">
                            <small class="text-muted d-block" style="font-size: 0.7rem;">Signed in as</small>
                            <span class="fw-semibold small text-truncate d-block">{{ auth()->user()->email }}</span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form action="{{ route('logout') }}" method="POST">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger small">
                                    <i class="bi bi-box-arrow-right me-2"></i> Sign Out
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Flash Alerts -->
        <div class="container-fluid px-4 pt-3">
            @if(session('success'))
                <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm py-2 px-3 mb-3" role="alert">
                    <i class="bi bi-check-circle-fill text-success fs-5"></i>
                    <div class="small fw-medium">{{ session('success') }}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 shadow-sm py-2 px-3 mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle-fill text-danger fs-5"></i>
                    <div class="small fw-medium">{{ session('error') }}</div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger alert-dismissible fade show shadow-sm py-2 px-3 mb-3" role="alert">
                    <div class="fw-semibold small mb-1">Please correct the following errors:</div>
                    <ul class="mb-0 small ps-3">
                        @foreach($errors->all() as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
        </div>

        <!-- Page Body Content -->
        <main class="container-fluid px-4 py-3 flex-grow-1">
            @yield('content')
        </main>
    </div>

    <!-- Local Bootstrap Bundle JS -->
    <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    @stack('scripts')
</body>
</html>
