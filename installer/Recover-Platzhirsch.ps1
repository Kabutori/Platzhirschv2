#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding(SupportsShouldProcess,ConfirmImpact='High')]
param(
    [ValidateSet('Backup','Restore','NewMachine','Verify')][string]$Mode='Backup',
    [ValidatePattern('^[A-Za-z]:\\[A-Za-z0-9_-]+(?:\\[A-Za-z0-9_-]+)*$')][string]$InstallPath='C:\Platzhirsch',
    [Parameter(Mandatory)][string]$Destination,
    [string]$TargetMapping,
    [switch]$SourceOffline,
    [switch]$Retry
)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
Import-Module WebAdministration
$root=[IO.Path]::GetFullPath($InstallPath).TrimEnd('\')
$state=Get-Content "$root\installation.json" -Raw|ConvertFrom-Json
if($state.product -ne 'Platzhirsch' -or -not $state.completed){throw 'Abgeschlossene Zielinstallation erforderlich.'}
$Destination=[IO.Path]::GetFullPath($Destination).TrimEnd('\')
if($Destination -eq $root -or $Destination.StartsWith($root+'\',[StringComparison]::OrdinalIgnoreCase) -or $root.StartsWith($Destination+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Installation und Sicherungsziel muessen getrennt sein.'}
. "$PSScriptRoot\Operations.Common.ps1"
$mutex=New-Object Threading.Mutex($false,'Global\PlatzhirschMaintenance');$acquired=$false
try {
    try{$acquired=$mutex.WaitOne(0)}catch [Threading.AbandonedMutexException]{$acquired=$true}
    if(-not $acquired){throw 'Eine Betriebsoperation laeuft bereits.'}
    Assert-OperationsPath $root
    if($Mode -ne 'Backup'){$manifest=Verify-OperationsBackup $Destination}
    if($Mode -eq 'Verify'){Invoke-DatabaseOperation 'verify' "$Destination\databases";Write-Host 'Sicherung vollstaendig und unveraendert.';return}
    if($Retry){
        if($Mode -notin @('Restore','NewMachine')){throw 'Retry gilt nur fuer eine Wiederherstellung.'}
        $active=@(Get-CimInstance Win32_Process|Where-Object {$_.ExecutablePath -and $_.ExecutablePath.StartsWith($root+'\runtime\php\',[StringComparison]::OrdinalIgnoreCase)})
        if($active.Count -ne 0){throw 'Vorherige PHP-Arbeit laeuft noch. Retry spaeter wiederholen.'}
        $pending=Get-Content "$root\maintenance.json" -Raw|ConvertFrom-Json
        if($pending.phase -ne 'restoring' -or $pending.source -ne $Destination -or $pending.mode -ne $Mode){throw 'Retry passt nicht zum vorhandenen Wartungsjournal.'}
        if($pending.archiveHash -ne (Get-FileHash "$Destination\recovery.json").Hash){throw 'Retry-Sicherung wurde geaendert.'}
    }
    if($Mode -eq 'NewMachine'){
        if(-not $SourceOffline){throw 'Originalinstallation muss abgeschaltet sein; mit -SourceOffline bestaetigen.'}
        if($manifest.version -ne $state.version){throw 'Zuerst dieselbe Paketversion auf dem Zielrechner installieren.'}
        if(-not $TargetMapping -or -not(Test-Path $TargetMapping)){throw 'Explizite Zuordnung aller Datenbankziele erforderlich.'}
        $TargetMapping=[IO.Path]::GetFullPath($TargetMapping)
        # The local target must be the newly installed instance, never an arbitrary platform server.
        $map=Get-Content $TargetMapping -Raw|ConvertFrom-Json
        if($map.local.host -ne '127.0.0.1' -or $map.local.port -ne $state.databasePort -or $map.local.username -ne 'root' -or $map.local.password -ne $state.rootPassword){throw 'Lokales Recovery-Ziel passt nicht zur neuen Installation.'}
        if($Retry){
            if($pending.mappingHash -ne (Get-FileHash $TargetMapping).Hash){throw 'Retry-Zielzuordnung wurde geaendert.'}
        }else{
            $status=Invoke-RestMethod "http://127.0.0.1:$($state.port)/api/bootstrap-status"
            if($status.bootstrapped){throw 'NewMachine erfordert eine frische Zielinstallation ohne eingerichteten Administrator.'}
        }
    }
    if(-not $PSCmdlet.ShouldProcess($root,"$Mode mit koordinierter Wartungsunterbrechung ausfuehren")){return}
    if(-not $Retry){Suspend-Operations}
    if($Mode -eq 'Backup'){
        Save-OperationsBackup $Destination
        Set-OperationPhase 'backed-up' $Destination
        Resume-Operations
        return
    }
    if($Retry){$before=$pending.backup}else{
        $before=Join-Path (Split-Path $Destination -Parent) ('before-restore-'+[Guid]::NewGuid().ToString('N'))
        Save-OperationsBackup $before
        $pending=Get-Content "$root\maintenance.json" -Raw|ConvertFrom-Json
        $pending|Add-Member -NotePropertyName source -NotePropertyValue $Destination
        $pending|Add-Member -NotePropertyName mode -NotePropertyValue $Mode
        $pending|Add-Member -NotePropertyName archiveHash -NotePropertyValue (Get-FileHash "$Destination\recovery.json").Hash
        $mappingHash=if($TargetMapping){(Get-FileHash $TargetMapping).Hash}else{''}
        $pending|Add-Member -NotePropertyName mappingHash -NotePropertyValue $mappingHash
        Write-JsonFile "$root\maintenance.json" $pending
    }
    Set-OperationPhase 'restoring' $before
    if($Mode -eq 'Restore'){
        Restore-OperationsBackup $Destination
        $state=Get-Content "$root\installation.json" -Raw|ConvertFrom-Json
    } else {
        # Keep destination OS/service credentials, paths and HTTP bindings. Carry application encryption key and settings.
        $currentEnv=Get-Content "$root\app\.env"
        $savedEnv=Get-Content "$Destination\files\app\.env"
        $keep='^(DB_|PROVISION_DB_|APP_URL=|SESSION_SECURE_COOKIE=)'
        $merged=@($savedEnv|Where-Object {$_ -notmatch $keep})+@($currentEnv|Where-Object {$_ -match $keep})
        Copy-OperationsTree "$Destination\files\app" "$root\app" -Mirror
        [IO.File]::WriteAllLines("$root\app\.env",[string[]]$merged,$utf8)
        $savedState=Get-Content "$Destination\files\installation.json" -Raw|ConvertFrom-Json
        $state.appKey=$savedState.appKey;Write-JsonFile "$root\installation.json" $state
        Write-JsonFile "$root\app\storage\app\private\provision.json" @{username='ph_provision';password=$state.provisionPassword}
        Remove-Item "$root\app\bootstrap\cache\config.php" -Force -ErrorAction SilentlyContinue
        Repair-OperationsAcl
        New-Item -ItemType Directory "$root\operations-private" -Force|Out-Null
        Protect-OperationsPath "$root\operations-private"
        Invoke-DatabaseOperation 'restore' "$Destination\databases" $TargetMapping -RetryRecovery:$Retry
        & "$root\runtime\php\php.exe" "$root\app\artisan" config:cache
        if($LASTEXITCODE -ne 0){throw 'Konfiguration konnte nicht erzeugt werden.'}
    }
    Set-OperationPhase 'verified' $before
    Resume-Operations
    Write-Host "Wiederherstellung erfolgreich. Stand davor: $before"
    if($Mode -eq 'NewMachine'){Write-Host 'Ziel bleibt an seine neuen IIS-Bindungen gebunden. DNS, Zertifikat und oeffentliche Freigabe separat pruefen.'}
} finally {if($acquired){$mutex.ReleaseMutex()};$mutex.Dispose()}
