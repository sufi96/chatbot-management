# Shared port and process handling for the dev scripts.
#
# Two of our services outlive a naive Stop-Process. "uvicorn --reload" forks a
# worker, and "artisan serve" spawns the php -S process that actually listens.
# Killing the parent orphans the child, which keeps holding the port, so a
# later run finds the port taken and silently serves stale code. Everything
# here works from the port inwards rather than from a remembered PID outwards.

$DevPorts = @(8000, 8080)

function Get-PortOwners {
    param([int]$Port)

    $conns = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    if (-not $conns) { return @() }
    return @($conns.OwningProcess | Select-Object -Unique | Where-Object { $_ -gt 4 })
}

function Stop-ProcessTree {
    param([int]$ProcessId)

    # /T takes the children with it, which is the whole point here.
    & taskkill.exe /PID $ProcessId /T /F 2>$null | Out-Null
}

function Stop-PortOwners {
    param([int[]]$Ports = $DevPorts, [int]$Attempts = 4)

    $allClear = $true

    foreach ($Port in $Ports) {
        for ($try = 1; $try -le $Attempts; $try++) {
            $owners = Get-PortOwners -Port $Port
            if ($owners.Count -eq 0) { break }

            foreach ($ownerId in $owners) {
                $proc = Get-Process -Id $ownerId -ErrorAction SilentlyContinue
                $name = if ($proc) { $proc.ProcessName } else { 'unknown' }
                Write-Host "      Releasing port $Port from $name (PID $ownerId)" -ForegroundColor Gray
                Stop-ProcessTree -ProcessId $ownerId
            }

            # An orphaned worker only becomes visible once its parent is gone,
            # so look again rather than trusting one pass.
            Start-Sleep -Milliseconds 700
        }

        if ((Get-PortOwners -Port $Port).Count -gt 0) {
            Write-Host "      WARNING: port $Port is still held. Close it by hand." -ForegroundColor Yellow
            $allClear = $false
        }
    }

    return $allClear
}
