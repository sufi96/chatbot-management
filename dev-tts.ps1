# The Malaysian TTS server for the dev scripts: its own virtualenv, so its
# PyTorch, transformers and Malaya pins never touch the engine's.
#
# PyTorch is installed first, from the index $env:TTS_TORCH names:
#   cpu (default)   any machine; VITS voices are quick, F5 is slow
#   cu128           an NVIDIA GPU with driver 570+, for F5-TTS
# Changing it later reinstalls PyTorch on the next start.
# Set $env:SKIP_TTS = "1" to start without the TTS server.

$TtsPort = 5051

function Initialize-TtsServer {
    param([string]$TtsDir)

    $python = Join-Path $TtsDir ".venv\Scripts\python.exe"
    $requirements = Join-Path $TtsDir "requirements.txt"
    $stamp = Join-Path $TtsDir ".venv\requirements.sha256"
    $torchStamp = Join-Path $TtsDir ".venv\torch-index.txt"
    $torch = if ($env:TTS_TORCH) { $env:TTS_TORCH.Trim().ToLower() } else { "cpu" }

    if (-not (Test-Path $python)) {
        Write-Host "      Creating TTS virtualenv..." -ForegroundColor Gray
        python -m venv (Join-Path $TtsDir ".venv")
        if (-not (Test-Path $python)) { throw "Python virtualenv was not created." }
    }

    $installedTorch = if (Test-Path $torchStamp) { (Get-Content $torchStamp -Raw).Trim() } else { "" }
    if ($installedTorch -ne $torch) {
        Write-Host "      Installing PyTorch ($torch); the first time takes a few minutes..." -ForegroundColor Gray
        & $python -m pip install --upgrade pip --quiet
        & $python -m pip install --force-reinstall torch torchaudio --index-url "https://download.pytorch.org/whl/$torch"
        if ($LASTEXITCODE -ne 0) { throw "PyTorch install failed (exit code $LASTEXITCODE)." }
        Set-Content -Path $torchStamp -Value $torch -Encoding ascii
        Remove-Item $stamp -ErrorAction SilentlyContinue
    }

    $hash = (Get-FileHash $requirements -Algorithm SHA256).Hash
    $installed = if (Test-Path $stamp) { (Get-Content $stamp -Raw).Trim() } else { "" }
    if ($hash -ne $installed) {
        Write-Host "      tts-server/requirements.txt changed, installing..." -ForegroundColor Gray
        # Hold PyTorch to the build just installed: a package asking for
        # another version would otherwise swap a CUDA build for PyPI's CPU one.
        $constraints = Join-Path $TtsDir ".venv\torch-constraints.txt"
        & $python -m pip freeze | Select-String -Pattern '^(torch|torchaudio)==' |
            ForEach-Object { $_.Line } | Set-Content -Path $constraints -Encoding ascii
        & $python -m pip install -r $requirements -c $constraints `
            --extra-index-url "https://download.pytorch.org/whl/$torch"
        if ($LASTEXITCODE -ne 0) { throw "pip install failed (exit code $LASTEXITCODE)." }
        Set-Content -Path $stamp -Value $hash -Encoding ascii
    } else {
        Write-Host "      TTS dependencies are up to date (PyTorch: $torch)." -ForegroundColor Gray
    }

    return $python
}
