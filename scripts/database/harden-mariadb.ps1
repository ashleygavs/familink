# Run this in a PowerShell opened with "Run as administrator".
#   1. makes MariaDB (and XAMPP's MySQL, when it is installed as a service) answer only this computer
#   2. disables the Oracle MySQL 8.0 service when it exists, so it can never take port 3306
#   3. removes the leftover "test" database from MariaDB (asks for the root password)
# Each settings file is backed up first, and put back if a restart does not work.
#
#   powershell -NoProfile -ExecutionPolicy Bypass -File scripts\database\harden-mariadb.ps1 -MariaDbPort 3307
#Requires -RunAsAdministrator
param(
    [string]$MariaDbIni = 'C:\Program Files\MariaDB 11.4\data\my.ini',
    [string]$MariaDbService = 'MariaDB',
    [int]$MariaDbPort = 3306,
    [string]$XamppIni = 'C:\xampp\mysql\bin\my.ini',
    [string]$XamppService = 'mysql',
    [int]$XamppPort = 3306,
    [string]$ClientPath = 'C:\Program Files\MariaDB 11.4\bin\mysql.exe'
)
$ErrorActionPreference = 'Stop'

function Set-LocalOnly([string]$ini, [string]$service, [int]$port) {
    Write-Host "`n== $service (port $port) =="
    $lines = Get-Content $ini
    if ($lines -match '^\s*bind-address\s*=') {
        Write-Host 'bind-address is already set, leaving it.'
        return
    }

    $backup = "$ini.before-bind-address-{0:yyyyMMdd-HHmmss}" -f (Get-Date)
    Copy-Item $ini $backup
    Write-Host "Backed up the settings file to $backup"

    $added = $false
    $new = foreach ($line in $lines) {
        $line
        if (-not $added -and $line -match '^\s*\[mysqld\]\s*$') { 'bind-address=127.0.0.1'; $added = $true }
    }
    if (-not $added) { throw "No [mysqld] section found in $ini, nothing changed." }
    [IO.File]::WriteAllLines($ini, $new, (New-Object Text.UTF8Encoding $false))

    Restart-Service $service -Force
    Start-Sleep -Seconds 10

    $listening = @(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)
    $running = (Get-Service $service).Status -eq 'Running'
    $onlyLocal = $listening.Count -gt 0 -and -not ($listening | Where-Object { $_.LocalAddress -notin '127.0.0.1' })

    if ($running -and $onlyLocal) {
        Write-Host "OK: $service is running and answers only 127.0.0.1 on port $port." -ForegroundColor Green
    }
    else {
        Write-Host 'Something is wrong, putting the old settings back.' -ForegroundColor Red
        Copy-Item $backup $ini -Force
        Restart-Service $service -Force
        throw "$service did not start cleanly with bind-address. The old settings were restored."
    }
}

if ((Test-Path $MariaDbIni) -and (Get-Service $MariaDbService -ErrorAction SilentlyContinue)) {
    Set-LocalOnly $MariaDbIni $MariaDbService $MariaDbPort
}
else { Write-Host "MariaDB was not found ($MariaDbIni / service $MariaDbService), skipping it." }

if ((Test-Path $XamppIni) -and (Get-Service $XamppService -ErrorAction SilentlyContinue)) {
    Set-LocalOnly $XamppIni $XamppService $XamppPort
}
else { Write-Host "XAMPP's MySQL is not installed as a service here, skipping it." }

Write-Host "`n== Oracle MySQL 8.0 service =="
if (Get-Service MySQL80 -ErrorAction SilentlyContinue) {
    Set-Service MySQL80 -StartupType Disabled
    Write-Host 'MySQL80 is now disabled. To use it again: Set-Service MySQL80 -StartupType Manual' -ForegroundColor Green
}
else { Write-Host 'Not installed, nothing to do.' }

Write-Host "`n== Leftover test database in MariaDB =="
$secure = Read-Host 'MariaDB root password (typing is hidden)' -AsSecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$env:MYSQL_PWD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
try {
    $ErrorActionPreference = 'Continue'
    & $ClientPath --user=root '--host=127.0.0.1' "--port=$MariaDbPort" -e 'DROP DATABASE IF EXISTS test; SELECT user, host FROM mysql.user ORDER BY user, host; SHOW DATABASES;' 2>&1 |
        Where-Object { $_ -notmatch '^WARNING' }
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB refused the command (wrong password?). The test database was not removed.' }
}
finally {
    $env:MYSQL_PWD = $null
}

Write-Host "`nAll done." -ForegroundColor Green
