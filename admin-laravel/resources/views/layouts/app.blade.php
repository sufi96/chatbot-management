<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }} - Management Console</title>
    {{-- An uploaded icon replaces the .ico too: a browser that prefers the
         ico would otherwise keep showing the console's own mark forever. --}}
    @if(\App\Support\Brand::isCustom('brand_icon_path'))
        <link rel="icon" href="{{ \App\Support\Brand::iconUrl() }}" type="image/png">
    @else
        <link rel="icon" href="{{ asset('brand/favicon.ico') }}" sizes="any">
        <link rel="icon" href="{{ \App\Support\Brand::iconUrl() }}" type="image/png">
    @endif
    <link rel="apple-touch-icon" href="{{ \App\Support\Brand::iconUrl() }}">

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
            @include('layouts._brand')
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

            {{-- Databases live inside this section, on a tab of their own, so
                 the sidebar names the one thing an operator is looking for:
                 what the bots can answer from. --}}
            <a href="{{ route('kb.index') }}" class="sidebar-link {{ request()->routeIs('kb.*') || request()->routeIs('databases.*') ? 'active' : '' }}">
                <i class="bi bi-journal-text"></i>
                <span>Knowledge base</span>
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

            <a href="{{ route('analytics.index') }}" class="sidebar-link {{ request()->routeIs('analytics.*') ? 'active' : '' }}">
                <i class="bi bi-bar-chart-line"></i>
                <span>Analytics</span>
            </a>

            @if(auth()->user()->isSuperAdmin())
                <a href="{{ route('users.index') }}" class="sidebar-link {{ request()->routeIs('users.*') ? 'active' : '' }}">
                    <i class="bi bi-person-gear"></i>
                    <span>Users and roles</span>
                </a>

            @endif

            {{-- Platform settings, one link per category, so a new category is
                 a line in AdminSettingsController::SECTIONS rather than a
                 longer page. A dot marks a category the last save refused. --}}
            @if(auth()->user()->isSuperAdmin())
                @php
                    $onSettings = request()->routeIs('admin.settings');
                    $settingsSection = $onSettings ? (request()->route('section') ?? 'providers') : null;
                    $settingsErrors = $onSettings && $errors->any()
                        ? \App\Http\Controllers\AdminSettingsController::sectionsWithErrors($errors) : [];
                @endphp

                <div class="sidebar-group">Admin Settings</div>

                @foreach(\App\Http\Controllers\AdminSettingsController::SECTIONS as $key => $meta)
                    <a href="{{ route('admin.settings', $key) }}"
                       class="sidebar-link sidebar-link-settings {{ $settingsSection === $key ? 'active' : '' }}">
                        <i class="bi {{ $meta['icon'] }}"></i>
                        <span>{{ $meta['label'] }}</span>
                        @if(in_array($key, $settingsErrors, true))
                            <span class="sidebar-error-dot" title="Has a problem to fix"></span>
                        @endif
                    </a>
                @endforeach

                {{-- Bot profiles above is one workspace; this is all of them,
                     deleted bots included. --}}
                <a href="{{ route('admin.bots.index') }}"
                   class="sidebar-link sidebar-link-settings {{ request()->routeIs('admin.bots.*') ? 'active' : '' }}">
                    <i class="bi bi-robot"></i>
                    <span>Bots</span>
                </a>
            @endif
        </nav>

        @php
            $engineUrl = \App\Services\EngineClient::baseUrl();
            $enginePort = parse_url($engineUrl, PHP_URL_PORT) ?? (parse_url($engineUrl, PHP_URL_SCHEME) === 'https' ? 443 : 80);
        @endphp
        <div class="sidebar-foot">
            <div class="dropup">
                <button class="sidebar-user" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar-chip">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="sidebar-user-text">
                        <span class="text-truncate">{{ auth()->user()->name }}</span>
                        <span class="text-truncate sidebar-user-email">{{ auth()->user()->email }}</span>
                    </span>
                    <i class="bi bi-chevron-expand" style="font-size: 0.75rem; color: var(--text-faint);"></i>
                </button>
                <ul class="dropdown-menu w-100">
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

            <div class="sidebar-status">
                {{-- The engine is asked for /health from the browser, so the badge
                     says what this operator can actually reach. --}}
                <span class="engine-status is-checking" id="engine-status" tabindex="0"
                      data-health-url="{{ $engineUrl }}/health" aria-describedby="engine-status-info">
                    <span class="state-dot"></span>
                    <span class="engine-status-label">Checking</span>
                    <span class="engine-status-pop" id="engine-status-info" role="tooltip">
                        <span class="kv-row"><span>Streaming engine</span><span class="figure-mono">:{{ $enginePort }}</span></span>
                        <span class="kv-row"><span>FastAPI</span><span class="figure-mono">:{{ $enginePort }}</span></span>
                        <span class="kv-row"><span>Database</span><span class="figure-mono" data-engine-db>-</span></span>
                    </span>
                </span>
                <span class="version-badge figure-mono">v{{ config('app.version') }}</span>
            </div>
        </div>
    </aside>

    <div class="content-wrapper">
        <header class="topbar">
            <h1 class="topbar-title">@yield('page-title', 'Console')</h1>

            <div class="d-flex align-items-center gap-2">
                {{-- How the system fits together, for anyone signed in. --}}
                <button type="button" class="icon-button" data-bs-toggle="modal" data-bs-target="#architectureModal"
                        title="How the system works" aria-label="How the system works">
                    <i class="bi bi-info-circle"></i>
                </button>
                <button type="button" class="icon-button" id="theme-toggle"
                        title="Switch between dark and light" aria-label="Switch between dark and light">
                    <i class="bi bi-circle-half"></i>
                </button>
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

    {{-- Every confirmation and notice in the console, asked through
         confirmDialog() / noticeDialog() below or a form's data-confirm,
         rather than the browser's own prompt. --}}
    <div class="modal fade" id="confirmDialog" tabindex="-1" aria-labelledby="confirmDialogTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered confirm-dialog">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="d-flex align-items-start gap-3">
                        <span class="confirm-icon" id="confirmDialogIcon"><i class="bi bi-trash3"></i></span>
                        <div class="min-w-0 flex-grow-1">
                            <h5 class="confirm-title" id="confirmDialogTitle">Are you sure?</h5>
                            <div class="confirm-subject" id="confirmDialogSubject" hidden>
                                <div class="confirm-subject-name" id="confirmDialogSubjectName"></div>
                                <div class="confirm-subject-detail" id="confirmDialogSubjectDetail"></div>
                            </div>
                            <p class="confirm-message" id="confirmDialogMessage"></p>
                            <div class="confirm-type" id="confirmDialogType" hidden>
                                <label class="form-label" for="confirmDialogTypeInput">
                                    Type <strong id="confirmDialogTypeValue"></strong> to confirm.
                                </label>
                                <input type="text" class="form-control" id="confirmDialogTypeInput"
                                       autocomplete="off" autocapitalize="off" spellcheck="false">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" id="confirmDialogCancel" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDialogOk">Delete</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Opened from the info button in the top bar. Only a super admin on an
         Admin settings page, where the live settings are loaded, sees which
         models run each job right now. --}}
    @auth
        @include('partials._architecture-modal', [
            'architectureLive' => auth()->user()->isSuperAdmin() && isset($settings, $providers, $modelRoles),
        ])
    @endauth

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

        (function () {
            var badge = document.getElementById('engine-status');
            if (!badge) return;
            var label = badge.querySelector('.engine-status-label');
            var db = badge.querySelector('[data-engine-db]');

            function show(state, text) {
                badge.className = 'engine-status is-' + state;
                label.textContent = text;
            }

            function check() {
                var ctrl = window.AbortController ? new AbortController() : null;
                var timer = ctrl && setTimeout(function () { ctrl.abort(); }, 5000);
                fetch(badge.dataset.healthUrl, { cache: 'no-store', signal: ctrl && ctrl.signal })
                    .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
                    .then(function (h) {
                        db.textContent = h.database || '-';
                        if (h.status === 'ok') show('online', 'Online');
                        else show('degraded', 'Degraded');
                    })
                    .catch(function () {
                        db.textContent = '-';
                        show('offline', 'Offline');
                    })
                    .finally(function () { if (timer) clearTimeout(timer); });
            }

            check();
            setInterval(function () { if (!document.hidden) check(); }, 30000);
        })();

        // Asks before something happens. Resolves true only when the confirm
        // button was pressed; Cancel, Escape and the backdrop are no.
        //
        // tone: 'danger' (default) for what cannot be taken back, 'primary'
        // for what is merely heavy, 'info' for a notice with one button.
        //
        // typeToConfirm: text that has to be typed exactly before the confirm
        // button unlocks. The promise then resolves with what was typed.
        var dialogTones = {
            danger:  { icon: 'bi-trash3',               button: 'btn-danger' },
            primary: { icon: 'bi-question-circle',      button: 'btn-brand' },
            info:    { icon: 'bi-exclamation-circle',   button: 'btn-brand' },
        };

        function confirmDialog(options) {
            var el = document.getElementById('confirmDialog');
            var ok = document.getElementById('confirmDialogOk');
            var cancel = document.getElementById('confirmDialogCancel');
            var icon = document.getElementById('confirmDialogIcon');
            var subject = document.getElementById('confirmDialogSubject');
            var modal = bootstrap.Modal.getOrCreateInstance(el);
            var toneName = dialogTones[options.tone] ? options.tone : 'danger';
            var tone = dialogTones[toneName];

            document.getElementById('confirmDialogTitle').textContent = options.title || 'Are you sure?';
            document.getElementById('confirmDialogMessage').textContent = options.message || '';
            document.getElementById('confirmDialogSubjectName').textContent = options.subject || '';
            document.getElementById('confirmDialogSubjectDetail').textContent = options.detail || '';
            subject.hidden = !options.subject;

            icon.className = 'confirm-icon is-' + toneName;
            icon.innerHTML = '<i class="bi ' + tone.icon + '"></i>';
            ok.className = 'btn ' + tone.button;
            ok.textContent = options.confirmLabel || (toneName === 'info' ? 'OK' : 'Delete');
            cancel.hidden = toneName === 'info';

            var typeBox = document.getElementById('confirmDialogType');
            var typeInput = document.getElementById('confirmDialogTypeInput');
            var mustType = options.typeToConfirm || '';
            typeBox.hidden = !mustType;
            typeInput.value = '';
            document.getElementById('confirmDialogTypeValue').textContent = mustType;
            ok.disabled = !!mustType;

            return new Promise(function (resolve) {
                var confirmed = false;
                function matches() { return typeInput.value === mustType; }
                function onOk() {
                    if (mustType && !matches()) return;
                    confirmed = true;
                    modal.hide();
                }
                function onType() { ok.disabled = !matches(); }
                function onTypeKey(event) {
                    if (event.key === 'Enter') { event.preventDefault(); onOk(); }
                }
                function onShown() { (mustType ? typeInput : ok).focus(); }
                function onHidden() {
                    ok.removeEventListener('click', onOk);
                    typeInput.removeEventListener('input', onType);
                    typeInput.removeEventListener('keydown', onTypeKey);
                    el.removeEventListener('shown.bs.modal', onShown);
                    el.removeEventListener('hidden.bs.modal', onHidden);
                    ok.disabled = false;
                    resolve(confirmed && mustType ? typeInput.value : confirmed);
                }
                ok.addEventListener('click', onOk);
                if (mustType) {
                    typeInput.addEventListener('input', onType);
                    typeInput.addEventListener('keydown', onTypeKey);
                }
                el.addEventListener('shown.bs.modal', onShown);
                el.addEventListener('hidden.bs.modal', onHidden);
                modal.show();
            });
        }

        // A notice with a single button, in place of window.alert.
        function noticeDialog(options) {
            return confirmDialog(Object.assign({ tone: 'info' }, options));
        }

        // A form, or the button that submits it, carrying data-confirm asks
        // first. The attributes are escaped by Blade, so a name holding a
        // quote no longer breaks the prompt the way an inline confirm() did.
        //   data-confirm          the question (title)
        //   data-confirm-subject  what it acts on, shown boxed
        //   data-confirm-detail   a second, smaller line under the subject
        //   data-confirm-message  what follows from saying yes
        //   data-confirm-label    the confirm button's text
        //   data-confirm-tone     danger (default) or primary
        //   data-confirm-type     text to type before confirming; sent with
        //                         the form as confirm_name
        document.addEventListener('submit', function (event) {
            var form = event.target;
            var submitter = event.submitter || null;
            var source = submitter && submitter.hasAttribute('data-confirm') ? submitter
                : (form.hasAttribute('data-confirm') ? form : null);
            if (!source) return;

            if (form.dataset.confirmed === '1') {
                delete form.dataset.confirmed;
                return;
            }

            event.preventDefault();
            confirmDialog({
                title: source.dataset.confirm,
                subject: source.dataset.confirmSubject,
                detail: source.dataset.confirmDetail,
                message: source.dataset.confirmMessage,
                confirmLabel: source.dataset.confirmLabel,
                tone: source.dataset.confirmTone,
                typeToConfirm: source.dataset.confirmType,
            }).then(function (confirmed) {
                if (!confirmed) return;
                if (typeof confirmed === 'string') {
                    var typed = form.querySelector('input[name="confirm_name"]');
                    if (!typed) {
                        typed = document.createElement('input');
                        typed.type = 'hidden';
                        typed.name = 'confirm_name';
                        form.appendChild(typed);
                    }
                    typed.value = confirmed;
                }
                form.dataset.confirmed = '1';
                if (form.requestSubmit) {
                    form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                } else {
                    if (submitter && submitter.formAction) form.action = submitter.formAction;
                    form.submit();
                }
            });
        }, true);

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
