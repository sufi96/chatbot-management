# Chatbot Management Hub - Dev Server Starter
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   CHATBOT MANAGEMENT HUB - STARTING DEV ENVIRONMENT      " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ApiDir = Join-Path $ScriptDir "api-engine"
$LaravelDir = Join-Path $ScriptDir "admin-laravel"

function Test-PortOpen {
    param([string]$TargetHost, [int]$Port)
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect($TargetHost, $Port, $null, $null)
        $ok = $async.AsyncWaitHandle.WaitOne(500, $false)
        if ($ok -and $client.Connected) { $client.EndConnect($async); return $true }
        return $false
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Wait-ForPort {
    param([string]$TargetHost, [int]$Port, [int]$TimeoutSeconds = 45)
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (Test-PortOpen -TargetHost $TargetHost -Port $Port) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return $false
}

# ---------------------------------------------------------------
# [1/4] Bootstrap Python FastAPI Streaming Engine
# ---------------------------------------------------------------
Write-Host "`n[1/4] Preparing Python FastAPI Streaming Engine..." -ForegroundColor Yellow
$PythonExe = Join-Path $ApiDir ".venv\Scripts\python.exe"

if (-not (Test-Path $PythonExe)) {
    Write-Host "      Creating virtualenv and installing dependencies..." -ForegroundColor Gray
    python -m venv (Join-Path $ApiDir ".venv")
    & (Join-Path $ApiDir ".venv\Scripts\pip.exe") install -r (Join-Path $ApiDir "requirements.txt")
}

if (-not (Test-Path $PythonExe)) {
    Write-Host "ERROR: Python virtualenv was not created. Is Python 3.10+ on PATH?" -ForegroundColor Red
    exit 1
}

# ---------------------------------------------------------------
# [2/4] Bootstrap Laravel Admin Portal
#       vendor/ .env and the SQLite file are gitignored, so a fresh
#       clone has none of them. Without this block "artisan serve"
#       dies instantly and port 8080 never opens.
# ---------------------------------------------------------------
Write-Host "[2/4] Preparing Laravel 13 Admin Portal..." -ForegroundColor Yellow

if (-not (Test-Path (Join-Path $LaravelDir "vendor\autoload.php"))) {
    Write-Host "      Installing Composer dependencies (first run, may take a few minutes)..." -ForegroundColor Gray
    Push-Location $LaravelDir
    # --prefer-install=dist avoids git checkouts, which fail on Windows for
    # packages containing reserved device names (e.g. phpdotenv's nul.env).
    & composer install --prefer-install=dist --no-interaction
    $composerExit = $LASTEXITCODE
    Pop-Location

    if (-not (Test-Path (Join-Path $LaravelDir "vendor\autoload.php"))) {
        Write-Host ""
        Write-Host "ERROR: composer install failed (exit code $composerExit)." -ForegroundColor Red
        Write-Host "       The admin portal cannot start without vendor/autoload.php." -ForegroundColor Red
        Write-Host "       If the failure mentions api.github.com timing out, your network" -ForegroundColor Red
        Write-Host "       is blocking the GitHub API. Retry, or use a Composer mirror." -ForegroundColor Red
        exit 1
    }
}

$EnvFile = Join-Path $LaravelDir ".env"
if (-not (Test-Path $EnvFile)) {
    Write-Host "      Creating .env from .env.example..." -ForegroundColor Gray
    Copy-Item (Join-Path $LaravelDir ".env.example") $EnvFile
    Push-Location $LaravelDir
    & php artisan key:generate --no-interaction
    Pop-Location
}

$SqliteFile = Join-Path $LaravelDir "database\database.sqlite"
if (-not (Test-Path $SqliteFile)) {
    Write-Host "      Creating SQLite database file..." -ForegroundColor Gray
    New-Item -ItemType File -Path $SqliteFile | Out-Null
}

Push-Location $LaravelDir
& php artisan migrate --seed --no-interaction --force
if ($LASTEXITCODE -ne 0) {
    Pop-Location
    Write-Host "ERROR: Database migration failed. Fix the error above and re-run." -ForegroundColor Red
    exit 1
}
if (-not (Test-Path (Join-Path $LaravelDir "public\storage"))) {
    & php artisan storage:link --no-interaction
}
Pop-Location

# ---------------------------------------------------------------
# [3/4] Launch both services
# ---------------------------------------------------------------
Write-Host "[3/4] Launching services..." -ForegroundColor Yellow
$ApiProcess = Start-Process -FilePath $PythonExe -ArgumentList "-m uvicorn main:app --host 127.0.0.1 --port 8000 --reload" -WorkingDirectory $ApiDir -PassThru
$PhpProcess = Start-Process -FilePath "php" -ArgumentList "artisan serve --host=127.0.0.1 --port=8080" -WorkingDirectory $LaravelDir -PassThru

# ---------------------------------------------------------------
# [4/4] Verify the ports actually accept connections before
#       claiming success. The old script printed "RUNNING" even when
#       both processes had already crashed.
# ---------------------------------------------------------------
Write-Host "[4/4] Waiting for services to accept connections..." -ForegroundColor Yellow
$ApiUp = Wait-ForPort -TargetHost "127.0.0.1" -Port 8000 -TimeoutSeconds 45
$PhpUp = Wait-ForPort -TargetHost "127.0.0.1" -Port 8080 -TimeoutSeconds 45

if (-not ($ApiUp -and $PhpUp)) {
    Write-Host "`n==========================================================" -ForegroundColor Red
    Write-Host "   STARTUP FAILED                                         " -ForegroundColor Red
    Write-Host "==========================================================" -ForegroundColor Red
    if ($ApiUp) { Write-Host "   FastAPI  (8000): UP" -ForegroundColor Green } else { Write-Host "   FastAPI  (8000): DOWN" -ForegroundColor Red }
    if ($PhpUp) { Write-Host "   Laravel  (8080): UP" -ForegroundColor Green } else { Write-Host "   Laravel  (8080): DOWN" -ForegroundColor Red }
    Write-Host "`nCheck the service windows above for the error message." -ForegroundColor Gray
    Write-Host "A port already in use is the other common cause." -ForegroundColor Gray
    if ($ApiProcess -and -not $ApiProcess.HasExited) { Stop-Process -Id $ApiProcess.Id -Force }
    if ($PhpProcess -and -not $PhpProcess.HasExited) { Stop-Process -Id $PhpProcess.Id -Force }
    exit 1
}

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host "   SERVICES ARE RUNNING (both ports verified)             " -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Green
Write-Host "Admin Portal:       http://localhost:8080" -ForegroundColor White
Write-Host "FastAPI Engine:     http://localhost:8000" -ForegroundColor White
Write-Host "Widget Script:      http://localhost:8000/widget.js" -ForegroundColor White
Write-Host "`nLogin: admin@chatbothub.com / password" -ForegroundColor Cyan
Write-Host "`nPress CTRL+C or close this window to terminate services.`n" -ForegroundColor Gray

try {
    while ($true) {
        Start-Sleep -Seconds 1
        if ($ApiProcess.HasExited -or $PhpProcess.HasExited) {
            Write-Host "`nA service exited unexpectedly. Shutting down the other." -ForegroundColor Red
            break
        }
    }
} finally {
    Write-Host "`nStopping services..." -ForegroundColor Yellow
    if ($ApiProcess -and -not $ApiProcess.HasExited) { Stop-Process -Id $ApiProcess.Id -Force }
    if ($PhpProcess -and -not $PhpProcess.HasExited) { Stop-Process -Id $PhpProcess.Id -Force }
    Write-Host "Services stopped cleanly." -ForegroundColor Gray
}
