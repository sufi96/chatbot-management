# Frees every port the dev environment uses, whatever state it was left in.
# Closing a console window with the X button can skip a PowerShell finally
# block entirely, so this exists for the times shutdown never ran.

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
. (Join-Path $ScriptDir "dev-ports.ps1")

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   CHATBOT MANAGEMENT HUB - STOPPING DEV ENVIRONMENT      " -ForegroundColor Cyan
Write-Host "==========================================================" -ForegroundColor Cyan

$busy = @($DevPorts | Where-Object { (Get-PortOwners -Port $_).Count -gt 0 })
if ($busy.Count -eq 0) {
    Write-Host "`nNothing to stop. Ports $($DevPorts -join ', ') are already free." -ForegroundColor Green
    exit 0
}

Write-Host "`nStopping services on port $($busy -join ', ')..." -ForegroundColor Yellow
if (Stop-PortOwners) {
    Write-Host "`nAll ports released." -ForegroundColor Green
    exit 0
}

Write-Host "`nSome ports could not be released." -ForegroundColor Red
exit 1
