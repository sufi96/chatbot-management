<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - Chatbot Hub</title>

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
    <style>
        .signin { display: grid; grid-template-columns: 1fr; min-height: 100dvh; }
        .signin-brand {
            display: none;
            flex-direction: column;
            justify-content: space-between;
            padding: 2.5rem;
            background: var(--bg);
            border-right: 1px solid var(--border);
        }
        .signin-form {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
            background: var(--bg-elev);
        }
        .signin-inner { width: 100%; max-width: 360px; }
        .role-fill {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--r-xs);
            color: var(--text-muted);
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            transition: border-color 0.12s linear, color 0.12s linear;
        }
        .role-fill:hover { border-color: var(--border-strong); color: var(--text); }
        @media (min-width: 992px) {
            .signin { grid-template-columns: 1.1fr 1fr; }
            .signin-brand { display: flex; }
        }
    </style>
</head>
<body>

<div class="signin">
    <section class="signin-brand">
        <div class="d-flex align-items-center gap-2">
            <span class="sidebar-mark"><i class="bi bi-hexagon-fill"></i></span>
            <span style="font-weight: 600; letter-spacing: -0.02em;">Chatbot Hub</span>
        </div>

        <div style="max-width: 34ch;">
            <h2 style="font-size: 1.75rem; line-height: 1.2; margin-bottom: 0.75rem;">
                Run every chatbot from one console.
            </h2>
            <p class="text-muted" style="font-size: 0.875rem; margin: 0;">
                Configure models, personas and widget styling per workspace. Copy one script tag to put a bot on any site.
            </p>
        </div>

        <dl class="mb-0" style="font-size: 0.78125rem;">
            <div class="kv"><dt class="kv-key">Admin portal</dt><dd class="kv-val mb-0">Laravel 13</dd></div>
            <div class="kv"><dt class="kv-key">Streaming engine</dt><dd class="kv-val mb-0">FastAPI :8000</dd></div>
            <div class="kv"><dt class="kv-key">Widget transport</dt><dd class="kv-val mb-0">Server-sent events</dd></div>
        </dl>
    </section>

    <section class="signin-form">
        <div class="signin-inner">
            <div class="d-lg-none d-flex align-items-center gap-2 mb-4">
                <span class="sidebar-mark"><i class="bi bi-hexagon-fill"></i></span>
                <span style="font-weight: 600; letter-spacing: -0.02em;">Chatbot Hub</span>
            </div>

            <h1 style="font-size: 1.25rem; margin-bottom: 0.25rem;">Sign in</h1>
            <p class="text-muted mb-4" style="font-size: 0.8125rem;">Use your workspace account to continue.</p>

            @if($errors->any())
                <div class="alert alert-danger d-flex align-items-start gap-2 mb-3" role="alert">
                    <i class="bi bi-exclamation-triangle"></i>
                    <div>{{ $errors->first() }}</div>
                </div>
            @endif

            <form action="{{ route('login') }}" method="POST" novalidate>
                @csrf

                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="{{ old('email', 'admin@chatbothub.com') }}" required autofocus autocomplete="username">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password"
                           required autocomplete="current-password">
                </div>

                <div class="form-check mb-4">
                    <input type="checkbox" class="form-check-input" id="remember" name="remember" checked>
                    <label class="form-check-label" for="remember">Keep me signed in on this device</label>
                </div>

                <button type="submit" class="btn btn-brand w-100">Sign in</button>
            </form>

            <div class="mt-4 pt-3" style="border-top: 1px solid var(--border);">
                <div class="text-muted mb-2" style="font-size: 0.75rem;">Fill a seeded demo account</div>
                <div class="d-flex flex-wrap gap-1.5">
                    <button type="button" class="role-fill" onclick="fillCreds('admin@chatbothub.com')">Super admin</button>
                    <button type="button" class="role-fill" onclick="fillCreds('manager@chatbothub.com')">System manager</button>
                    <button type="button" class="role-fill" onclick="fillCreds('editor@chatbothub.com')">Editor</button>
                    <button type="button" class="role-fill" onclick="fillCreds('viewer@chatbothub.com')">Viewer</button>
                </div>
            </div>
        </div>
    </section>
</div>

<script>
    function fillCreds(email) {
        document.getElementById('email').value = email;
        document.getElementById('password').value = 'password';
        document.getElementById('password').focus();
    }
</script>
</body>
</html>
