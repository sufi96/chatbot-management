# Shared secrets between the portal and the engine, for the dev scripts.
#
# The portal calls the engine with ENGINE_ADMIN_TOKEN, which the engine checks
# against ADMIN_API_TOKEN. The engine calls the portal with
# PORTAL_INTERNAL_TOKEN, which must be the same in both files. Left blank, as
# .env.example leaves them, the engine refuses every indexing request and the
# portal refuses every database query, and a bot looks as though it has no
# knowledge base and no database. Dot-source this file, then call
# Sync-DevSecrets.

function Get-EnvValue {
    param([string]$Path, [string]$Key)

    if (-not (Test-Path $Path)) { return "" }

    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -match "^\s*$([regex]::Escape($Key))\s*=(.*)$") {
            return $Matches[1].Trim().Trim('"').Trim("'")
        }
    }

    return ""
}

function Set-EnvValue {
    param([string]$Path, [string]$Key, [string]$Value)

    $lines = New-Object System.Collections.Generic.List[string]
    if (Test-Path $Path) {
        $lines.AddRange([string[]][System.IO.File]::ReadAllLines($Path))
    }

    $pattern = "^\s*$([regex]::Escape($Key))\s*="
    $replaced = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match $pattern) {
            $lines[$i] = "$Key=$Value"
            $replaced = $true
            break
        }
    }
    if (-not $replaced) { $lines.Add("$Key=$Value") }

    # Without a byte order mark: the engine's dotenv reader would take one for
    # part of the first key's name.
    [System.IO.File]::WriteAllLines($Path, $lines, (New-Object System.Text.UTF8Encoding($false)))
}

function New-DevSecret {
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    return (($bytes | ForEach-Object { $_.ToString("x2") }) -join "")
}

# One pair: the portal's key and the engine's key must hold the same value.
function Sync-SecretPair {
    param([string]$PortalEnv, [string]$PortalKey, [string]$EngineEnv, [string]$EngineKey)

    $portal = Get-EnvValue -Path $PortalEnv -Key $PortalKey
    $engine = Get-EnvValue -Path $EngineEnv -Key $EngineKey

    if ($portal -and $portal -eq $engine) { return "unchanged" }

    if ($portal -and $engine) {
        # The portal's value wins: it is the file an operator is likeliest to
        # have set on purpose, and the engine only has to match it.
        Set-EnvValue -Path $EngineEnv -Key $EngineKey -Value $portal
        return "engine brought in line with the portal"
    }

    $value = if ($portal) { $portal } elseif ($engine) { $engine } else { New-DevSecret }
    Set-EnvValue -Path $PortalEnv -Key $PortalKey -Value $value
    Set-EnvValue -Path $EngineEnv -Key $EngineKey -Value $value

    if ($portal -or $engine) { return "copied to the file that was missing it" }
    return "generated"
}

function Sync-DevSecrets {
    param([string]$PortalEnv, [string]$EngineEnv, [string]$EngineEnvExample = "")

    if (-not (Test-Path $EngineEnv)) {
        if ($EngineEnvExample -and (Test-Path $EngineEnvExample)) {
            Copy-Item $EngineEnvExample $EngineEnv
        } else {
            New-Item -ItemType File -Path $EngineEnv | Out-Null
        }
    }

    return [ordered]@{
        "ENGINE_ADMIN_TOKEN / ADMIN_API_TOKEN" =
            Sync-SecretPair -PortalEnv $PortalEnv -PortalKey "ENGINE_ADMIN_TOKEN" -EngineEnv $EngineEnv -EngineKey "ADMIN_API_TOKEN"
        "PORTAL_INTERNAL_TOKEN" =
            Sync-SecretPair -PortalEnv $PortalEnv -PortalKey "PORTAL_INTERNAL_TOKEN" -EngineEnv $EngineEnv -EngineKey "PORTAL_INTERNAL_TOKEN"
    }
}
