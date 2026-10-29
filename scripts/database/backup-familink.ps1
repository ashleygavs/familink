# Backs up the FamiLink database (MariaDB) to a zip file.
#   - exports the whole database, checks that the export finished, then zips it
#   - keeps the last N days of backups (30 by default)
#   - writes what happened to backup.log in the backup folder
# The connection (host, port, database, user, password) is read from the project's .env, so no password is stored
# in this script. Run it by hand, or from a scheduled task (see WALKTHROUGH.md, "Back up and restore the database").
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File scripts\database\backup-familink.ps1
param(
    [string]$EnvFile = (Join-Path $PSScriptRoot '..\..\.env'),
    [string]$BackupFolder = (Join-Path ([Environment]::GetFolderPath('MyDocuments')) 'familink-backups'),
    [string]$DumpTool = 'C:\Program Files\MariaDB 11.4\bin\mariadb-dump.exe',
    [int]$KeepDays = 30
)
$ErrorActionPreference = 'Stop'

New-Item -ItemType Directory -Force $BackupFolder | Out-Null
$log = Join-Path $BackupFolder 'backup.log'

function Write-Log([string]$message) {
    Add-Content -Path $log -Value ('{0:yyyy-MM-dd HH:mm:ss}  {1}' -f (Get-Date), $message)
}

$stamp = '{0:yyyy-MM-dd_HHmm}' -f (Get-Date)
$sql = Join-Path $BackupFolder "familink-$stamp.sql"
$zip = Join-Path $BackupFolder "familink-$stamp.zip"

try {
    if (-not (Test-Path $EnvFile)) { throw "The .env file was not found: $EnvFile" }
    if (-not (Test-Path $DumpTool)) { throw "The export tool was not found: $DumpTool" }

    $settings = @{}
    foreach ($line in Get-Content $EnvFile) {
        if ($line -match '^\s*DB_(\w+)\s*=\s*(.*?)\s*$') { $settings[$Matches[1]] = $Matches[2].Trim('"', "'") }
    }
    foreach ($key in 'HOST', 'PORT', 'DATABASE', 'USERNAME') {
        if (-not $settings[$key]) { throw "DB_$key is missing in $EnvFile" }
    }

    $env:MYSQL_PWD = $settings['PASSWORD']
    # The tool prints a harmless certificate warning when the password comes from the environment. That must not
    # count as a failure, so only its exit code is checked.
    $ErrorActionPreference = 'Continue'
    & $DumpTool "--user=$($settings['USERNAME'])" "--host=$($settings['HOST'])" "--port=$($settings['PORT'])" `
        --single-transaction --routines --events --default-character-set=utf8mb4 --databases $settings['DATABASE'] "--result-file=$sql" 2>&1 | Out-Null
    $exitCode = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    if ($exitCode -ne 0) { throw "The export tool stopped with code $exitCode." }

    if (-not (Select-String -Path $sql -Pattern '^-- Dump completed' -Quiet)) { throw 'The export did not finish (no "Dump completed" line).' }
    $tables = @(Select-String -Path $sql -Pattern '^CREATE TABLE ').Count
    if ($tables -lt 1) { throw 'The export has no tables in it.' }

    Compress-Archive -Path $sql -DestinationPath $zip -CompressionLevel Optimal
    Remove-Item $sql -Force

    Get-ChildItem $BackupFolder -Filter 'familink-*.zip' | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$KeepDays) } | Remove-Item -Force

    Write-Log ('OK     {0}  {1:N0} KB  {2} tables' -f (Split-Path -Leaf $zip), ((Get-Item $zip).Length / 1KB), $tables)
}
catch {
    Remove-Item $sql -Force -ErrorAction SilentlyContinue
    Write-Log "FAILED $($_.Exception.Message)"
    exit 1
}
finally {
    $env:MYSQL_PWD = $null
}
