#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding()]
param([string]$InstallPath='C:\Platzhirsch',[Parameter(Mandatory)][string]$Destination)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$dest=[IO.Path]::GetFullPath($Destination);$root=[IO.Path]::GetFullPath($InstallPath)
if($dest.StartsWith($root,[StringComparison]::OrdinalIgnoreCase)){throw 'Backupziel muss ausserhalb der Installation liegen.'}
$state=Get-Content "$root\installation.json" -Raw|ConvertFrom-Json
if($state.product -ne 'Platzhirsch'){throw 'Keine Platzhirsch-Installation.'}
$target=Join-Path $dest ('platzhirsch-'+(Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Path $target|Out-Null
# Encrypted external storage is required. All exports and keys are sensitive.
& icacls.exe $target /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F'|Out-Null
if($LASTEXITCODE -ne 0){throw 'Backupziel konnte nicht geschuetzt werden.'}
$options=Join-Path $root ('backup-client-'+[guid]::NewGuid().ToString('N')+'.cnf')
[IO.File]::WriteAllText($options,"[client]`nuser=root`npassword=$($state.rootPassword)`nhost=127.0.0.1`nport=$($state.databasePort)`n",(New-Object Text.UTF8Encoding($false)))
& icacls.exe $options /inheritance:r /grant:r '*S-1-5-18:F' '*S-1-5-32-544:F'|Out-Null
if($LASTEXITCODE -ne 0){throw 'Temporare Zugangsdaten konnten nicht geschuetzt werden.'}
try {
    $mysql=Join-Path $root 'runtime\mysql\bin\mysql.exe';$dump=Join-Path $root 'runtime\mysql\bin\mysqldump.exe'
    $databases=& $mysql "--defaults-extra-file=$options" --batch --skip-column-names -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='platzhirsch_platform' OR SCHEMA_NAME REGEXP '^ph_t_[a-f0-9]{24}$'"
    if($LASTEXITCODE -ne 0){throw 'Datenbankliste konnte nicht gelesen werden.'}
    foreach($database in $databases){
        if($database -notmatch '^(platzhirsch_platform|ph_t_[a-f0-9]{24})$'){throw 'Ungueltiger Datenbankname.'}
        & $dump "--defaults-extra-file=$options" --single-transaction --routines --events --hex-blob --set-gtid-purged=OFF --no-tablespaces "--result-file=$target\$database.sql" --databases $database
        if($LASTEXITCODE -ne 0){throw "Backup fehlgeschlagen: $database"}
    }
    Copy-Item "$root\app\.env" "$target\app.env"
    Copy-Item "$root\installation.json" "$target\installation.json"
    Copy-Item "$root\app\storage\app\private\provision.json" "$target\provision.json"
    Get-ChildItem $target -File|Get-FileHash -Algorithm SHA256|Select-Object @{N='file';E={Split-Path $_.Path -Leaf}},Hash|ConvertTo-Json|Set-Content "$target\checksums.json" -Encoding UTF8
    Write-Host "Backup erstellt: $target"
    Write-Host 'Noch kein Wiederherstellungsnachweis. Restore in isolierter Testumgebung pruefen. Datenbanken sind einzeln transaktionskonsistent, nicht gemeinsam zeitpunktkonsistent.'
} finally {Remove-Item -LiteralPath $options -Force}
