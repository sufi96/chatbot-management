# Chatbot Management Hub - Dev Server Starter
Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   🤖 CHATBOT MANAGEMENT HUB - STARTING DEV ENVIRONMENT   " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

# 1. Start Python FastAPI Streaming Engine
Write-Host "`n[1/3] Starting Python FastAPI Streaming Engine (Port 8000)..." -ForegroundColor Yellow
$ApiDir = Join-Path $ScriptDir "api-engine"
$PythonExe = Join-Path $ApiDir ".venv\Scripts\python.exe"

if (-not (Test-Path $PythonExe)) {
    Write-Host "Creating Python virtualenv and installing dependencies..." -ForegroundColor Gray
    python -m venv (Join-Path $ApiDir ".venv")
    & (Join-Path $ApiDir ".venv\Scripts\pip.exe") install -r (Join-Path $ApiDir "requirements.txt")
}

$ApiProcess = Start-Process -FilePath $PythonExe -ArgumentList "-m uvicorn main:app --host 127.0.0.1 --port 8000 --reload" -WorkingDirectory $ApiDir -PassThru

# 2. Start Laravel 13 Admin Portal (Port 8080)
Write-Host "[2/3] Starting Laravel 13 Admin Portal (Port 8080)..." -ForegroundColor Yellow
$LaravelDir = Join-Path $ScriptDir "admin-laravel"
$PhpProcess = Start-Process -FilePath "php" -ArgumentList "artisan serve --host=127.0.0.1 --port=8080" -WorkingDirectory $LaravelDir -PassThru

Start-Sleep -Seconds 2

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host "   ✅ SERVICES ARE RUNNING!                               " -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Green
Write-Host "👉 PHP Admin Portal:       http://localhost:8080" -ForegroundColor White
Write-Host "👉 FastAPI Engine API:     http://localhost:8000" -ForegroundColor White
Write-Host "👉 Widget Script:          http://localhost:8000/widget.js" -ForegroundColor White
Write-Host "👉 External Host Demo:     http://localhost:8080/../demo/index.html" -ForegroundColor White
Write-Host "`nPress CTRL+C or close this window to terminate services.`n" -ForegroundColor Gray

try {
    # Keep script alive and monitor processes
    while ($true) {
        Start-Sleep -Seconds 1
        if ($ApiProcess.HasExited -or $PhpProcess.HasExited) {
            break
        }
    }
} finally {
    Write-Host "`nStopping services..." -ForegroundColor Yellow
    if ($ApiProcess -and -not $ApiProcess.HasExited) { Stop-Process -Id $ApiProcess.Id -Force }
    if ($PhpProcess -and -not $PhpProcess.HasExited) { Stop-Process -Id $PhpProcess.Id -Force }
    Write-Host "Services stopped cleanly." -ForegroundColor Gray
}
