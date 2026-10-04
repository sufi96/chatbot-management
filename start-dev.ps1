# Chatbot Management Hub - Dev Server Starter
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   CHATBOT MANAGEMENT HUB - STARTING DEV ENVIRONMENT      " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ApiDir = Join-Path $ScriptDir "api-engine"
$LaravelDir = Join-Path $ScriptDir "admin-laravel"
$TtsDir = Join-Path $ScriptDir "tts-server"

. (Join-Path $ScriptDir "dev-ports.ps1")
. (Join-Path $ScriptDir "dev-secrets.ps1")
. (Join-Path $ScriptDir "dev-tts.ps1")

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
# ---------------------------------------------------------------
# [1/6] Clear anything left over from a previous run.
#       A window closed with the X button never runs the shutdown
#       below, and an orphaned worker holds its port and goes on
#       serving the code it started with. Starting from free ports
#       is the only way to be sure this run is the one answering.
# ---------------------------------------------------------------
Write-Host "`n[1/6] Clearing stale services..." -ForegroundColor Yellow
if ((Stop-PortOwners) -and $true) { Write-Host "      Ports $($DevPorts -join ', ') are free." -ForegroundColor Gray }

Write-Host "`n[2/6] Preparing Python FastAPI Streaming Engine..." -ForegroundColor Yellow
$PythonExe = Join-Path $ApiDir ".venv\Scripts\python.exe"
$Requirements = Join-Path $ApiDir "requirements.txt"
$RequirementsStamp = Join-Path $ApiDir ".venv\requirements.sha256"

if (-not (Test-Path $PythonExe)) {
    Write-Host "      Creating virtualenv..." -ForegroundColor Gray
    python -m venv (Join-Path $ApiDir ".venv")
}

if (-not (Test-Path $PythonExe)) {
    Write-Host "ERROR: Python virtualenv was not created. Is Python 3.10+ on PATH?" -ForegroundColor Red
    exit 1
}

# Installing only when the venv is created leaves an existing venv behind
# every package added since. The engine then dies on import, but only after
# the reloader has opened port 8000, so the check below reports success.
# Remembering which requirements.txt was installed catches that on any pull.
$RequirementsHash = (Get-FileHash $Requirements -Algorithm SHA256).Hash
$InstalledHash = if (Test-Path $RequirementsStamp) { (Get-Content $RequirementsStamp -Raw).Trim() } else { "" }

if ($RequirementsHash -ne $InstalledHash) {
    Write-Host "      requirements.txt changed, installing Python dependencies..." -ForegroundColor Gray
    & $PythonExe -m pip install -r $Requirements
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: pip install failed (exit code $LASTEXITCODE). Fix the error above and re-run." -ForegroundColor Red
        exit 1
    }
    Set-Content -Path $RequirementsStamp -Value $RequirementsHash -Encoding ascii
} else {
    Write-Host "      Python dependencies are up to date." -ForegroundColor Gray
}

# ---------------------------------------------------------------
# [2/4] Bootstrap Laravel Admin Portal
#       vendor/ .env and the SQLite file are gitignored, so a fresh
#       clone has none of them. Without this block "artisan serve"
#       dies instantly and port 8080 never opens.
# ---------------------------------------------------------------
Write-Host "[3/6] Preparing Laravel 13 Admin Portal..." -ForegroundColor Yellow

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

# The portal and the engine authenticate each other with shared secrets.
# .env.example leaves them blank, and blank means the engine refuses every
# indexing request and the portal refuses every database query, so a bot looks
# as though it has no knowledge base and no database. Generated once, then kept.
$SecretResults = Sync-DevSecrets -PortalEnv $EnvFile `
    -EngineEnv (Join-Path $ApiDir ".env") `
    -EngineEnvExample (Join-Path $ApiDir ".env.example")
foreach ($entry in $SecretResults.GetEnumerator()) {
    Write-Host "      Secret $($entry.Key): $($entry.Value)" -ForegroundColor Gray
}

# Only SQLite needs a file conjured into existence. On PostgreSQL the database
# must already exist, and creating a stray .sqlite file would just be litter.
$DbConnection = (Select-String -Path (Join-Path $LaravelDir ".env") -Pattern '^DB_CONNECTION=(.+)$' |
                 Select-Object -First 1).Matches.Groups[1].Value
if ($null -eq $DbConnection) { $DbConnection = 'sqlite' }
$DbConnection = $DbConnection.Trim()
Write-Host "      Database driver: $DbConnection" -ForegroundColor Gray

if ($DbConnection -eq 'sqlite') {
    $SqliteFile = Join-Path $LaravelDir "database\database.sqlite"
    if (-not (Test-Path $SqliteFile)) {
        Write-Host "      Creating SQLite database file..." -ForegroundColor Gray
        New-Item -ItemType File -Path $SqliteFile | Out-Null
    }
}

Push-Location $LaravelDir
& php artisan migrate --seed --no-interaction --force
if ($LASTEXITCODE -ne 0) {
    Pop-Location
    Write-Host "ERROR: Database migration failed. Fix the error above and re-run." -ForegroundColor Red
    if ($DbConnection -eq 'pgsql') {
        Write-Host "       On PostgreSQL, check the server is running and that the" -ForegroundColor Red
        Write-Host "       database in DB_DATABASE exists with the vector extension:" -ForegroundColor Red
        Write-Host "         CREATE DATABASE chatbot_hub;" -ForegroundColor Red
        Write-Host "         \c chatbot_hub" -ForegroundColor Red
        Write-Host "         CREATE EXTENSION IF NOT EXISTS vector;" -ForegroundColor Red
    }
    exit 1
}
if (-not (Test-Path (Join-Path $LaravelDir "public\storage"))) {
    & php artisan storage:link --no-interaction
}
Pop-Location

# ---------------------------------------------------------------
# [4/6] Prepare the Malaysian TTS server. Optional: the portal and
#       engine start without it, and the browser voice still works.
# ---------------------------------------------------------------
$TtsPython = $null
if ($env:SKIP_TTS -eq "1") {
    Write-Host "[4/6] Skipping the Malaysian TTS server (SKIP_TTS=1)." -ForegroundColor Yellow
} else {
    Write-Host "[4/6] Preparing Malaysian TTS server..." -ForegroundColor Yellow
    try {
        $TtsPython = Initialize-TtsServer -TtsDir $TtsDir
    } catch {
        Write-Host "      WARNING: $($_.Exception.Message) Starting without the TTS server." -ForegroundColor Yellow
    }
}

# ---------------------------------------------------------------
# [5/6] Launch the services
# ---------------------------------------------------------------
Write-Host "[5/6] Launching services..." -ForegroundColor Yellow
Write-Host "      A console window opens per service. Leave them be." -ForegroundColor Gray
$ApiProcess = Start-Process -FilePath $PythonExe -ArgumentList "-m uvicorn main:app --host 127.0.0.1 --port 8000 --reload" -WorkingDirectory $ApiDir -PassThru
$PhpProcess = Start-Process -FilePath "php" -ArgumentList "artisan serve --host=127.0.0.1 --port=8080" -WorkingDirectory $LaravelDir -PassThru
# No --reload: a reload would drop the loaded voice models.
$TtsProcess = if ($TtsPython) {
    Start-Process -FilePath $TtsPython -ArgumentList "-m uvicorn app:app --host 127.0.0.1 --port $TtsPort" -WorkingDirectory $TtsDir -PassThru
}

# ---------------------------------------------------------------
# [6/6] Verify the ports actually accept connections before
#       claiming success. The old script printed "RUNNING" even when
#       both processes had already crashed.
# ---------------------------------------------------------------
Write-Host "[6/6] Waiting for services to accept connections..." -ForegroundColor Yellow
$ApiUp = Wait-ForPort -TargetHost "127.0.0.1" -Port 8000 -TimeoutSeconds 45
$PhpUp = Wait-ForPort -TargetHost "127.0.0.1" -Port 8080 -TimeoutSeconds 45
$TtsUp = $TtsProcess -and (Wait-ForPort -TargetHost "127.0.0.1" -Port $TtsPort -TimeoutSeconds 60)

if (-not ($ApiUp -and $PhpUp)) {
    Write-Host "`n==========================================================" -ForegroundColor Red
    Write-Host "   STARTUP FAILED                                         " -ForegroundColor Red
    Write-Host "==========================================================" -ForegroundColor Red
    if ($ApiUp) { Write-Host "   FastAPI  (8000): UP" -ForegroundColor Green } else { Write-Host "   FastAPI  (8000): DOWN" -ForegroundColor Red }
    if ($PhpUp) { Write-Host "   Laravel  (8080): UP" -ForegroundColor Green } else { Write-Host "   Laravel  (8080): DOWN" -ForegroundColor Red }
    Write-Host "`nCheck the service windows above for the error message." -ForegroundColor Gray
    Write-Host "A port already in use is the other common cause." -ForegroundColor Gray
    if ($ApiProcess -and -not $ApiProcess.HasExited) { Stop-ProcessTree -ProcessId $ApiProcess.Id }
    if ($PhpProcess -and -not $PhpProcess.HasExited) { Stop-ProcessTree -ProcessId $PhpProcess.Id }
    if ($TtsProcess -and -not $TtsProcess.HasExited) { Stop-ProcessTree -ProcessId $TtsProcess.Id }
    Stop-PortOwners | Out-Null
    exit 1
}

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host "   SERVICES ARE RUNNING (ports verified)                  " -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Green
Write-Host "Admin Portal:       http://localhost:8080" -ForegroundColor White
Write-Host "FastAPI Engine:     http://localhost:8000" -ForegroundColor White
Write-Host "Widget Script:      http://localhost:8000/widget.js" -ForegroundColor White
if ($TtsUp) {
    Write-Host "Malaysian TTS:      http://localhost:$TtsPort  (voices and settings)" -ForegroundColor White
} elseif ($TtsProcess) {
    Write-Host "Malaysian TTS:      DOWN - see its window. Voice falls back to whatever Admin Settings choose." -ForegroundColor Yellow
}
Write-Host "`nLogin: admin@chatbothub.com / password" -ForegroundColor Cyan
Write-Host "`nPress CTRL+C in this window to stop all services." -ForegroundColor Gray
Write-Host "If you close this window instead, run stop-dev.bat to free the ports.`n" -ForegroundColor Gray

Write-Host "Opening the admin portal..." -ForegroundColor Gray
Start-Process "http://localhost:8080"

try {
    while ($true) {
        Start-Sleep -Seconds 1
        if ($ApiProcess.HasExited -or $PhpProcess.HasExited) {
            Write-Host "`nA service exited unexpectedly. Shutting down the other." -ForegroundColor Red
            break
        }
        # The TTS server is optional: losing it is reported, not fatal.
        if ($TtsProcess -and $TtsUp -and $TtsProcess.HasExited) {
            Write-Host "`nThe Malaysian TTS server stopped; the portal and engine keep running." -ForegroundColor Yellow
            $TtsUp = $false
        }
    }
} finally {
    Write-Host "`nStopping services..." -ForegroundColor Yellow
    if ($ApiProcess -and -not $ApiProcess.HasExited) { Stop-ProcessTree -ProcessId $ApiProcess.Id }
    if ($PhpProcess -and -not $PhpProcess.HasExited) { Stop-ProcessTree -ProcessId $PhpProcess.Id }
    if ($TtsProcess -and -not $TtsProcess.HasExited) { Stop-ProcessTree -ProcessId $TtsProcess.Id }
    # Killing a parent orphans the worker that actually holds the port, so the
    # ports get the last word rather than the process handles we remembered.
    Stop-PortOwners | Out-Null
    Write-Host "Services stopped and ports $($DevPorts -join ', ') released." -ForegroundColor Gray
}
