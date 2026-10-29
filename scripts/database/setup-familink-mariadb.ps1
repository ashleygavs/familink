# Sets up the FamiLink database on a MariaDB 11.4 server. Run it once, in your own PowerShell.
# It asks for the MariaDB root password, which is never saved or printed.
#   1. creates the databases familink and familink_test
#   2. creates a limited app user (familink_app) with a random password, saved to a private file in your user
#      profile, never in the project. Copy those DB_ lines into .env.
#   3. optionally imports an export of the database, and compares every table's row count with the old server
#
# Example, moving the data from XAMPP's MySQL (port 3306) to a new MariaDB on port 3307:
#   powershell -NoProfile -ExecutionPolicy Bypass -File scripts\database\setup-familink-mariadb.ps1 `
#       -Port 3307 -Dump C:\path\to\familink.sql -CompareWithPort 3306
param(
    [int]$Port = 3306,
    [string]$Dump = '',
    [int]$CompareWithPort = 0,
    [string]$AppUser = 'familink_app',
    [string]$ClientPath = 'C:\Program Files\MariaDB 11.4\bin\mysql.exe',
    [string]$OldClientPath = 'C:\xampp\mysql\bin\mysql.exe',
    [string]$SecretDir = (Join-Path $env:USERPROFILE '.familink-mariadb')
)
$ErrorActionPreference = 'Stop'

if (-not (Test-Path $ClientPath)) { throw "The MariaDB client was not found: $ClientPath" }
if ($Dump -ne '' -and -not (Test-Path $Dump)) { throw "Export not found: $Dump" }
if ($CompareWithPort -ne 0 -and -not (Test-Path $OldClientPath)) { throw "The old server's client was not found: $OldClientPath" }

$secure = Read-Host 'MariaDB root password (typing is hidden)' -AsSecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
$rootPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)

# The addresses are written as --host=... and not -h..., because Windows PowerShell 5.1 splits "-h127.0.0.1" at the dots.
function New-Sql([string]$query) {
    $env:MYSQL_PWD = $rootPassword
    & $ClientPath --user=root '--host=127.0.0.1' "--port=$Port" --default-character-set=utf8mb4 -N -B -e $query
    if ($LASTEXITCODE -ne 0) { throw "The new server refused: $query" }
}

function Old-Sql([string]$query) {
    $env:MYSQL_PWD = ''
    & $OldClientPath --user=root '--host=127.0.0.1' "--port=$CompareWithPort" --default-character-set=utf8mb4 -N -B -e $query
    if ($LASTEXITCODE -ne 0) { throw "The old server refused: $query" }
}

try {
    Write-Host "Connected to: $(New-Sql 'select version();')" -ForegroundColor Green

    New-Sql 'CREATE DATABASE IF NOT EXISTS familink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' | Out-Null
    New-Sql 'CREATE DATABASE IF NOT EXISTS familink_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' | Out-Null
    Write-Host 'Databases familink and familink_test are ready.'

    $chars = [char[]]'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'
    $bytes = New-Object byte[] 28
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $appPassword = -join ($bytes | ForEach-Object { $chars[$_ % $chars.Length] })

    foreach ($hostName in 'localhost', '127.0.0.1', '::1') {
        New-Sql "CREATE USER IF NOT EXISTS '$AppUser'@'$hostName' IDENTIFIED BY '$appPassword';" | Out-Null
        New-Sql "ALTER USER '$AppUser'@'$hostName' IDENTIFIED BY '$appPassword';" | Out-Null
        New-Sql "GRANT ALL PRIVILEGES ON familink.* TO '$AppUser'@'$hostName';" | Out-Null
        New-Sql "GRANT ALL PRIVILEGES ON familink_test.* TO '$AppUser'@'$hostName';" | Out-Null
    }
    New-Sql 'FLUSH PRIVILEGES;' | Out-Null
    Write-Host "App user $AppUser created (rights to familink and familink_test only)."

    New-Item -ItemType Directory -Force $SecretDir | Out-Null
    $secretFile = Join-Path $SecretDir 'app-user.env'
    Set-Content -Path $secretFile -Value @(
        'DB_CONNECTION=mariadb', 'DB_HOST=127.0.0.1', "DB_PORT=$Port", 'DB_DATABASE=familink', "DB_USERNAME=$AppUser", "DB_PASSWORD=$appPassword"
    ) -Encoding ascii
    icacls $secretFile /inheritance:r /grant:r "$($env:USERNAME):(F)" | Out-Null
    Write-Host "App credentials saved privately to $secretFile. Copy those lines into .env."

    if ($Dump -ne '') {
        $existing = [int](New-Sql "select count(*) from information_schema.tables where table_schema='familink';")
        if ($existing -ne 0) { throw "The familink database already has $existing tables, so nothing was imported." }

        Write-Host 'Importing the export...'
        $env:MYSQL_PWD = $rootPassword
        cmd /c "`"$ClientPath`" --user=root --host=127.0.0.1 --port=$Port --default-character-set=utf8mb4 < `"$Dump`""
        if ($LASTEXITCODE -ne 0) { throw 'The import failed.' }

        if ($CompareWithPort -ne 0) {
            $tables = New-Sql "select table_name from information_schema.tables where table_schema='familink' order by table_name;"
            $bad = 0
            Write-Host ''
            Write-Host ('{0,-34} {1,8} {2,8}' -f 'table', 'old', 'new')
            foreach ($table in $tables) {
                $old = [int](Old-Sql "select count(*) from familink.``$table``;")
                $new = [int](New-Sql "select count(*) from familink.``$table``;")
                $flag = if ($old -eq $new) { '' } else { '  <-- DIFFERENT'; $bad++ }
                Write-Host ('{0,-34} {1,8} {2,8}{3}' -f $table, $old, $new, $flag)
            }
            Write-Host ''
            if ($bad -eq 0) { Write-Host "All $(@($tables).Count) tables match the old server." -ForegroundColor Green }
            else { Write-Host "$bad table(s) differ." -ForegroundColor Red }
        }
    }
}
finally {
    $env:MYSQL_PWD = $null
    $rootPassword = $null
}
