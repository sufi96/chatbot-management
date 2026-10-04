#!/usr/bin/env bash
# Chatbot Management Hub - Dev Server Starter (Linux/macOS twin of start-dev.ps1)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API="$ROOT/api-engine"
PORTAL="$ROOT/admin-laravel"
TTS="$ROOT/tts-server"
PORTS=(8000 8080 5051)   # 5051: the Malaysian TTS server

say()  { printf '\033[33m%s\033[0m\n' "$*"; }
info() { printf '      %s\n' "$*"; }
die()  { printf '\033[31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------
# [1/6] Free the ports. An orphaned uvicorn worker or php -S keeps
#       serving old code, so start from nothing listening.
# ---------------------------------------------------------------
say "[1/6] Clearing stale services..."
for p in "${PORTS[@]}"; do fuser -k "$p/tcp" >/dev/null 2>&1 || true; done

# ---------------------------------------------------------------
# [2/6] Python engine. The engine's dependencies do not install on
#       Python 3.14 yet, so pick the newest interpreter below that,
#       or have uv fetch 3.12.
# ---------------------------------------------------------------
say "[2/6] Preparing Python FastAPI Streaming Engine..."
PY="$API/.venv/bin/python"
if [ ! -x "$PY" ]; then
    info "Creating virtualenv..."
    made=""
    for v in 3.13 3.12 3.11 3.10; do
        if command -v "python$v" >/dev/null; then "python$v" -m venv "$API/.venv" && made=1 && break; fi
    done
    if [ -z "$made" ]; then
        command -v uv >/dev/null || die "Need Python 3.10-3.13 (3.14 is too new) or uv (pip install --user uv)."
        uv venv -q --seed --python 3.12 "$API/.venv"
    fi
fi

# Reinstall whenever requirements.txt changes, not only on a fresh venv:
# a missing package crashes the engine after the port is already open.
STAMP="$API/.venv/requirements.sha256"
HASH="$(sha256sum "$API/requirements.txt" | cut -d' ' -f1)"
if [ "$HASH" != "$(cat "$STAMP" 2>/dev/null)" ]; then
    info "requirements.txt changed, installing Python dependencies..."
    "$PY" -m pip --version >/dev/null 2>&1 || "$PY" -m ensurepip >/dev/null   # unseeded uv venvs lack pip
    "$PY" -m pip install -r "$API/requirements.txt"
    echo "$HASH" > "$STAMP"
else
    info "Python dependencies are up to date."
fi

# ---------------------------------------------------------------
# [3/6] Laravel portal: vendor/, .env, shared secrets, database.
# ---------------------------------------------------------------
say "[3/6] Preparing Laravel 13 Admin Portal..."
cd "$PORTAL"
[ -f vendor/autoload.php ] || { info "Installing Composer dependencies..."; composer install --prefer-install=dist --no-interaction; }
if [ ! -f .env ]; then
    info "Creating .env from .env.example..."
    cp .env.example .env
    php artisan key:generate --no-interaction
fi
[ -f "$API/.env" ] || cp "$API/.env.example" "$API/.env"

get_env() { sed -n "s/^[[:space:]]*$2[[:space:]]*=[[:space:]]*//p" "$1" | head -1 | tr -d "\"'"; }
set_env() {
    if grep -q "^[[:space:]]*$2[[:space:]]*=" "$1"; then sed -i "s|^[[:space:]]*$2[[:space:]]*=.*|$2=$3|" "$1"
    else echo "$2=$3" >> "$1"; fi
}
# Same rules as dev-secrets.ps1: the portal's value wins, otherwise copy
# whichever exists, otherwise generate one. Blank means the engine refuses
# indexing and the portal refuses database queries.
sync_pair() {
    local pv ev val
    pv="$(get_env .env "$1")"; ev="$(get_env "$API/.env" "$2")"
    [ -n "$pv" ] && [ "$pv" = "$ev" ] && return
    val="${pv:-${ev:-$(openssl rand -hex 32)}}"
    set_env .env "$1" "$val"; set_env "$API/.env" "$2" "$val"
    info "Secret $1 synced."
}
sync_pair ENGINE_ADMIN_TOKEN ADMIN_API_TOKEN
sync_pair PORTAL_INTERNAL_TOKEN PORTAL_INTERNAL_TOKEN

DB="$(get_env .env DB_CONNECTION)"; DB="${DB:-sqlite}"
info "Database driver: $DB"
[ "$DB" = sqlite ] && touch database/database.sqlite
php artisan migrate --seed --no-interaction --force || die "Database migration failed. Fix the error above and re-run."
[ -e public/storage ] || php artisan storage:link --no-interaction

# ---------------------------------------------------------------
# [4/6] Malaysian TTS server, in its own venv so its PyTorch and Malaya
#       pins never touch the engine's. Optional: a failure here starts
#       the rest without it. TTS_TORCH picks the PyTorch build: cpu
#       (default) or cu128 for an NVIDIA GPU. SKIP_TTS=1 leaves it out.
# ---------------------------------------------------------------
TTS_PY=""
prepare_tts() {
    local py="$TTS/.venv/bin/python" torch="${TTS_TORCH:-cpu}" stamp hash
    if [ ! -x "$py" ]; then info "Creating TTS virtualenv..."; "$PY" -m venv "$TTS/.venv" || return 1; fi
    if [ "$torch" != "$(cat "$TTS/.venv/torch-index.txt" 2>/dev/null)" ]; then
        info "Installing PyTorch ($torch); the first time takes a few minutes..."
        "$py" -m pip install --force-reinstall torch torchaudio --index-url "https://download.pytorch.org/whl/$torch" || return 1
        echo "$torch" > "$TTS/.venv/torch-index.txt"; rm -f "$TTS/.venv/requirements.sha256"
    fi
    stamp="$TTS/.venv/requirements.sha256"; hash="$(sha256sum "$TTS/requirements.txt" | cut -d' ' -f1)"
    if [ "$hash" != "$(cat "$stamp" 2>/dev/null)" ]; then
        info "tts-server/requirements.txt changed, installing..."
        # Hold PyTorch to the build just installed, so no package swaps it.
        "$py" -m pip freeze | grep -E '^(torch|torchaudio)==' > "$TTS/.venv/torch-constraints.txt"
        "$py" -m pip install -r "$TTS/requirements.txt" -c "$TTS/.venv/torch-constraints.txt" \
            --extra-index-url "https://download.pytorch.org/whl/$torch" || return 1
        echo "$hash" > "$stamp"
    else
        info "TTS dependencies are up to date (PyTorch: $torch)."
    fi
    TTS_PY="$py"
}
if [ "${SKIP_TTS:-}" = 1 ]; then
    say "[4/6] Skipping the Malaysian TTS server (SKIP_TTS=1)."
else
    say "[4/6] Preparing Malaysian TTS server..."
    prepare_tts || { TTS_PY=""; info "WARNING: TTS setup failed (see above). Starting without it."; }
fi

# ---------------------------------------------------------------
# [5/6] Launch. Ctrl+C or closing the terminal takes down the
#       whole process group, reloader workers included.
# ---------------------------------------------------------------
say "[5/6] Launching services..."
trap 'trap - EXIT INT TERM HUP; echo; say "Stopping services..."; kill 0 2>/dev/null' EXIT INT TERM HUP
(cd "$API" && exec "$PY" -m uvicorn main:app --host 127.0.0.1 --port 8000 --reload) &
API_PID=$!
(cd "$PORTAL" && exec php artisan serve --host=127.0.0.1 --port=8080) &
PHP_PID=$!
# No --reload: a reload would drop the loaded voice models.
if [ -n "$TTS_PY" ]; then (cd "$TTS" && exec "$TTS_PY" -m uvicorn app:app --host 127.0.0.1 --port 5051) & fi

# ---------------------------------------------------------------
# [6/6] Only claim success once both ports accept connections.
# ---------------------------------------------------------------
say "[6/6] Waiting for services to accept connections..."
up() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }
for _ in $(seq 90); do up 8000 && up 8080 && break; sleep 0.5; done
up 8000 && up 8080 || die "Startup failed. FastAPI(8000): $(up 8000 && echo UP || echo DOWN), Laravel(8080): $(up 8080 && echo UP || echo DOWN). See the output above."

if [ -n "$TTS_PY" ]; then for _ in $(seq 120); do up 5051 && break; sleep 0.5; done; fi

printf '\n\033[32m   SERVICES ARE RUNNING (ports verified)\033[0m\n'
echo "Admin Portal:   http://localhost:8080"
echo "FastAPI Engine: http://localhost:8000"
echo "Widget Script:  http://localhost:8000/widget.js"
if [ -n "$TTS_PY" ]; then
    if up 5051; then echo "Malaysian TTS:  http://localhost:5051  (voices and settings)"
    else echo "Malaysian TTS:  DOWN - see the output above."; fi
fi
echo "Login: admin@chatbothub.com / password"
echo "Press Ctrl+C to stop all services."
command -v xdg-open >/dev/null && xdg-open http://localhost:8080 >/dev/null 2>&1 || true

wait -n "$API_PID" "$PHP_PID"   # either dying stops everything via the trap; the TTS server is optional
say "A service exited unexpectedly."
