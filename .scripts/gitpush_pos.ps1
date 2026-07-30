# Configuracion de codificacion
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

# Definicion de iconos por codigo para evitar errores de codificacion
$i_check    = [char]0x2705
$i_rocket   = [char]0xD83D + [char]0xDE80
$i_disk     = [char]0xD83D + [char]0xDCBE
$i_sparkles = [char]0x2728
$i_outbox   = [char]0xD83D + [char]0xDCE4
$i_warning  = [char]0x26A0
$i_excl     = [char]0x00A1

# Auto-navegar a la raiz del repo (sube un nivel desde .scripts/)
Set-Location $PSScriptRoot
Set-Location ..

# Verificar archivos vacios antes de agregar
$cambios = git status --porcelain
if ($cambios) {
    $emptyFiles = @()
    foreach ($line in ($cambios -split "`r?`n")) {
        if ([string]::IsNullOrWhiteSpace($line)) { continue }
        $file = $line.Substring(3).Trim().Trim('"')
        if (Test-Path -LiteralPath $file) {
            $item = Get-Item $file
            if ($item.PSIsContainer -ne $true -and $item.Length -eq 0) {
                $emptyFiles += $file
            }
        }
    }

    if ($emptyFiles.Count -gt 0) {
        Write-Host ""
        Write-Host "$i_warning ADVERTENCIA: Se detectaron archivos VACIOS (0 bytes) que estan modificados o son nuevos:" -ForegroundColor Yellow
        foreach ($ef in $emptyFiles) {
            Write-Host "   -> $ef" -ForegroundColor Red
        }
        Write-Host ""
        $confirmacion = Read-Host "Estas seguro de que deseas continuar con el push de estos archivos vacios? (S/N)"
        if ($confirmacion -notmatch "^[Ss]$") {
            Write-Host "Operacion cancelada. Por favor revisa tus archivos." -ForegroundColor Yellow
            exit
        }
    }
}

Write-Host ""
Write-Host "--- PROCESO DE GUARDADO (POS) ---" -ForegroundColor Cyan

# 1. Preparar cambios
Write-Host "$i_disk Preparando archivos localmente..." -ForegroundColor Gray
git add .
$msg = "Human Push $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
git commit -m "$msg" 2>$null

# 2. Sincronizacion inteligente
Write-Host ""
Write-Host "$i_rocket Consultando cambios en la nube..." -ForegroundColor Cyan
$resultado = (git pull origin main --rebase 2>$null)
$resultado | Out-String | Write-Host

if ($resultado -match "Already up to date" -or $resultado -match "Ya esta al dia") {
    Write-Host "$i_sparkles Todo al dia. Nada nuevo que bajar de GitHub." -ForegroundColor Green
}
elseif ($LASTEXITCODE -ne 0) {
    Write-Host "$i_warning Conflicto detectado. Aplicando reparacion automatica..." -ForegroundColor Yellow
    git rebase --abort 2>$null
    git pull origin main --no-rebase -X ours
    git add .
    git commit -m "$msg (Conflict Resolved)" 2>$null
}
else {
    Write-Host "$i_check Sincronizacion completada con exito." -ForegroundColor Green
}

# 3. Subir
Write-Host ""
Write-Host "$i_outbox Subiendo tus cambios a la nube..." -ForegroundColor Gray
git push origin main

Write-Host ""
Write-Host "$i_check ${i_excl}Todo guardado y subido correctamente!" -ForegroundColor Green
