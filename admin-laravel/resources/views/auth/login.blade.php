<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - Chatbot Management Hub</title>
    <!-- Modern Typography: Plus Jakarta Sans & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background-color: #0b0f19;
            background-image: 
                radial-gradient(at 10% 20%, rgba(79, 70, 229, 0.15) 0px, transparent 50%),
                radial-gradient(at 90% 80%, rgba(14, 165, 233, 0.12) 0px, transparent 50%);
            color: #f8fafc;
            font-family: 'Inter', -apple-system, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 700;
        }
        .login-card {
            background-color: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .form-control {
            background-color: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border-radius: 12px;
            padding: 0.65rem 1rem;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        .form-control:focus {
            background-color: rgba(15, 23, 42, 0.95);
            border-color: #6366f1;
            color: #ffffff;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.25);
        }
        .form-control::placeholder {
            color: #64748b;
        }
        .btn-brand {
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            border: none;
            color: #ffffff;
            font-weight: 600;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
        }
        .btn-brand:hover {
            background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%);
            color: #ffffff;
            box-shadow: 0 8px 20px -4px rgba(79, 70, 229, 0.4);
            transform: translateY(-1px);
        }
        .quick-role-btn {
            background-color: rgba(255, 255, 255, 0.06);
            color: #cbd5e1;
            border: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 0.75rem;
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.15s ease;
            font-weight: 500;
        }
        .quick-role-btn:hover {
            background-color: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
        }
    </style>
</head>
<body class="h-100 d-flex align-items-center justify-content-center py-4">

    <div class="container" style="max-width: 450px;">
        <!-- Logo Header -->
        <div class="text-center mb-4">
            <div class="d-inline-flex align-items-center justify-content-center rounded-4 shadow-lg mb-2" style="width: 56px; height: 56px; background: linear-gradient(135deg, #4f46e5, #7c3aed);">
                <i class="bi bi-robot text-white fs-2"></i>
            </div>
            <h3 class="fw-bold text-white mb-1" style="letter-spacing: -0.02em;">Chatbot Management Hub</h3>
            <p class="text-secondary small mb-0">Multi-System & RBAC AI Platform</p>
        </div>

        <!-- Login Card -->
        <div class="card login-card p-4 p-sm-5">
            <h5 class="fw-bold text-white mb-3" style="letter-spacing: -0.01em;">Sign in to your account</h5>

            @if($errors->any())
                <div class="alert alert-danger py-2 px-3 small mb-3 rounded-3">
                    {{ $errors->first() }}
                </div>
            @endif

            <form action="{{ route('login') }}" method="POST">
                @csrf
                <div class="mb-3">
                    <label for="email" class="form-label small fw-semibold text-light">Email Address</label>
                    <input type="email" class="form-control" id="email" name="email" value="{{ old('email', 'admin@chatbothub.com') }}" required autofocus placeholder="name@company.com">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label small fw-semibold text-light mb-1">Password</label>
                    <input type="password" class="form-control" id="password" name="password" value="password" required placeholder="••••••••">
                </div>

                <div class="mb-4 form-check">
                    <input type="checkbox" class="form-check-input" id="remember" name="remember" checked>
                    <label class="form-check-label small text-secondary" for="remember">Remember this device</label>
                </div>

                <button type="submit" class="btn btn-brand w-100 mb-3 shadow-sm">
                    <i class="bi bi-box-arrow-in-right me-1.5"></i> Sign In
                </button>
            </form>

            <!-- Quick Demo Credentials for Fast Testing -->
            <div class="pt-3 border-top" style="border-color: rgba(255, 255, 255, 0.1) !important;">
                <small class="text-secondary fw-semibold d-block mb-2 text-uppercase" style="font-size: 0.68rem; letter-spacing: 0.05em;">Quick Test Login Roles:</small>
                <div class="d-flex flex-wrap gap-1.5">
                    <button type="button" class="quick-role-btn" onclick="fillCreds('admin@chatbothub.com', 'password')">
                        👑 Super Admin
                    </button>
                    <button type="button" class="quick-role-btn" onclick="fillCreds('manager@chatbothub.com', 'password')">
                        🛠️ System Manager
                    </button>
                    <button type="button" class="quick-role-btn" onclick="fillCreds('editor@chatbothub.com', 'password')">
                        ✏️ Bot Editor
                    </button>
                    <button type="button" class="quick-role-btn" onclick="fillCreds('viewer@chatbothub.com', 'password')">
                        👁️ Viewer
                    </button>
                </div>
            </div>
        </div>

        <p class="text-center text-secondary small mt-4" style="font-size: 0.75rem;">Chatbot Management Hub &copy; 2026 &bull; Powered by Laravel & FastAPI</p>
    </div>

    <script>
        function fillCreds(email, password) {
            document.getElementById('email').value = email;
            document.getElementById('password').value = password;
        }
    </script>
</body>
</html>
