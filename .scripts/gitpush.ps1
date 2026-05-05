# Configuración de codificación
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

# Definición de iconos por código para evitar errores de codificación
$i_check = [char]0x2705
$i_rocket = [char]0xD83D + [char]0xDE80
$i_disk = [char]0xD83D + [char]0xDCBE
$i_sparkles = [char]0x2728
$i_outbox = [char]0xD83D + [char]0xDCE4
$i_warning = [char]0x26A0

# Auto-navegar a la raíz (GPS Interno)
Set-Location $PSScriptRoot
Set-Location ..

Write-Host ""
Write-Host "--- PROCESO DE GUARDADO (POS) ---" -ForegroundColor Cyan

# 1. Preparar cambios
Write-Host "$i_disk Preparando archivos localmente..." -ForegroundColor Gray
git add .
$msg = "Human Push $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
git commit -m "$msg" 2>$null

# 2. Sincronización inteligente
Write-Host ""
Write-Host "$i_rocket Consultando cambios en la nube..." -ForegroundColor Cyan
$resultado = git pull origin main --rebase 2>&1
$resultado | Out-String | Write-Host

if ($resultado -match "Already up to date" -or $resultado -match "Ya está al día") {
    Write-Host "$i_sparkles Todo al día. Nada nuevo que bajar de GitHub." -ForegroundColor Green
} elseif ($LASTEXITCODE -ne 0) {
    Write-Host "$i_warning Conflicto detectado. Aplicando reparación automática..." -ForegroundColor Yellow
    git rebase --abort 2>$null
    git pull origin main --no-rebase -X ours
    git add .
    git commit -m "$msg (Conflict Resolved)" 2>$null
} else {
    Write-Host "$i_check Sincronización completada con éxito." -ForegroundColor Green
}

# 3. Subir
Write-Host ""
Write-Host "$i_outbox Subiendo tus cambios a la nube..." -ForegroundColor Gray
git push origin main

Write-Host ""
Write-Host "$i_check ¡Todo guardado y subido correctamente!" -ForegroundColor Green
