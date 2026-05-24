param(
    [string]$OutputDir = "backups"
)

$ErrorActionPreference = "Stop"

$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupDir = Join-Path (Get-Location) $OutputDir
$backupPath = Join-Path $backupDir "ocstudio_db_$timestamp.sql"

New-Item -ItemType Directory -Force -Path $backupDir | Out-Null

& docker compose exec -T db pg_dump -U docker db > $backupPath

if ($LASTEXITCODE -ne 0) {
    if (Test-Path $backupPath) {
        Remove-Item -LiteralPath $backupPath -Force
    }

    throw "Nie udało się wykonać backupu bazy danych."
}

if ((Get-Item $backupPath).Length -eq 0) {
    Remove-Item -LiteralPath $backupPath -Force
    throw "Backup został przerwany, plik SQL był pusty."
}

Write-Host "Backup zapisany: $backupPath"
