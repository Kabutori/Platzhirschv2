#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding(SupportsShouldProcess,ConfirmImpact='High')]
param(
    [ValidateSet('Update','Rollback','Status','Resume')][string]$Mode='Update',
    [ValidatePattern('^[A-Za-z]:\\[A-Za-z0-9_-]+(?:\\[A-Za-z0-9_-]+)*$')][string]$InstallPath='C:\Platzhirsch',
    [string]$PackagePath=(Split-Path $PSScriptRoot -Parent),
    [string]$BackupRoot='C:\Platzhirsch-Backups',
    [string]$BackupPath
)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
Import-Module WebAdministration
$root=[IO.Path]::GetFullPath($InstallPath).TrimEnd('\')
$state=Get-Content "$root\installation.json" -Raw|ConvertFrom-Json
if($state.product -ne 'Platzhirsch' -or -not $state.completed){throw 'Abgeschlossene Installation erforderlich.'}
. "$PSScriptRoot\Operations.Common.ps1"
$mutex=New-Object Threading.Mutex($false,'Global\PlatzhirschMaintenance');$acquired=$false
try {
    try{$acquired=$mutex.WaitOne(0)}catch [Threading.AbandonedMutexException]{$acquired=$true}
    if(-not $acquired){throw 'Eine Betriebsoperation laeuft bereits.'}
    Assert-OperationsPath $root
    if($Mode -eq 'Status'){
        if(Test-Path "$root\maintenance.json"){Get-Content "$root\maintenance.json"}else{Write-Host "Version $($state.version): keine unterbrochene Operation."};return
    }
    if($Mode -eq 'Resume'){
        $journal=Get-Content "$root\maintenance.json" -Raw|ConvertFrom-Json
        if($journal.phase -eq 'stopping'){
            $active=@(Get-CimInstance Win32_Process|Where-Object {$_.ExecutablePath -and $_.ExecutablePath.StartsWith($root+'\runtime\php\',[StringComparison]::OrdinalIgnoreCase)})
            if($active.Count -ne 0){throw 'PHP-Arbeit laeuft noch. Spaeter erneut Resume aufrufen.'}
            Set-OperationPhase 'stopped'
            $journal.phase='stopped'
        }
        if($journal.phase -notin @('stopped','backed-up','verified','rolled-back')){throw 'Nicht abgeschlossene Datenaenderung: zuerst Rollback verwenden.'}
        if($PSCmdlet.ShouldProcess($root,'Geprueften Stand aus Wartung wieder starten')){Resume-Operations};return
    }
    if($Mode -eq 'Rollback'){
        if(-not $BackupPath){$BackupPath=(Get-Content "$root\maintenance.json" -Raw|ConvertFrom-Json).backup}
        $BackupPath=[IO.Path]::GetFullPath($BackupPath).TrimEnd('\')
        $null=Verify-OperationsBackup $BackupPath
        if(-not $PSCmdlet.ShouldProcess($root,'Code und alle gesicherten Datenbanken auf den Sicherungszeitpunkt zuruecksetzen')){return}
        if(-not(Test-Path "$root\maintenance.json")){
            Suspend-Operations
            $before=Join-Path ([IO.Path]::GetFullPath($BackupRoot)) ('before-rollback-'+[Guid]::NewGuid().ToString('N'))
            Save-OperationsBackup $before
        }
        Set-OperationPhase 'rolling-back' $BackupPath
        Restore-OperationsBackup $BackupPath
        $state=Get-Content "$root\installation.json" -Raw|ConvertFrom-Json
        Set-OperationPhase 'rolled-back' $BackupPath
        Resume-Operations
        return
    }
    $PackagePath=[IO.Path]::GetFullPath($PackagePath).TrimEnd('\')
    Assert-OperationsPath $PackagePath
    $manifest=Get-Content "$PackagePath\release-manifest.json" -Raw|ConvertFrom-Json
    if($manifest.version -eq $state.version){throw 'Diese Version ist bereits installiert.'}
    # First updater supports application upgrades within 0.1; runtime/MSI upgrades need their own tested adapter.
    if($manifest.version -notmatch '^0\.1\.0(?:-|$)' -or $state.version -notmatch '^0\.1\.0(?:-|$)'){throw 'Dieser Updater unterstuetzt die 0.1-Anwendungsreihe.'}
    $seen=@{}
    foreach($file in $manifest.files){
        $full=[IO.Path]::GetFullPath((Join-Path $PackagePath $file.path))
        if(-not $full.StartsWith($PackagePath+'\',[StringComparison]::OrdinalIgnoreCase) -or $seen.ContainsKey($full)){throw 'Ungueltiger Release-Pfad.'}
        $seen[$full]=$true
        if(-not(Test-Path -LiteralPath $full -PathType Leaf) -or (Get-FileHash $full -Algorithm SHA256).Hash -ne $file.sha256){throw 'Paketpruefsumme stimmt nicht.'}
    }
    if(@(Get-ChildItem $PackagePath -Recurse -Force -File|Where-Object {$_.FullName -ne "$PackagePath\release-manifest.json"}).Count -ne $seen.Count){throw 'Paket enthaelt nicht gelistete Dateien.'}
    foreach($required in @('payload\app\artisan','payload\app\vendor\autoload.php','payload\app\public\admin\index.html','installer\Worker.ps1')){if(-not $seen.ContainsKey("$PackagePath\$required")){throw 'Paket unvollstaendig.'}}
    $BackupRoot=[IO.Path]::GetFullPath($BackupRoot).TrimEnd('\')
    if($BackupRoot -eq $root -or $BackupRoot.StartsWith($root+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Sicherung muss ausserhalb der Installation liegen.'}
    if(-not(Test-Path $BackupRoot)){New-Item -ItemType Directory $BackupRoot|Out-Null}
    Assert-OperationsPath $BackupRoot
    if(-not $PSCmdlet.ShouldProcess($root,"Version $($state.version) auf $($manifest.version) aktualisieren; Wartung, Sicherung und Migration aller aktiven Restaurants")){return}
    Suspend-Operations
    $backup=Join-Path $BackupRoot ('before-update-'+[Guid]::NewGuid().ToString('N'))
    Save-OperationsBackup $backup
    Set-OperationPhase 'backed-up' $backup
    try {
        Set-OperationPhase 'updating' $backup
        foreach($entry in @('app','bootstrap','config','database','public','resources','routes','vendor')){Copy-OperationsTree "$PackagePath\payload\app\$entry" "$root\app\$entry" -Mirror}
        foreach($entry in @('artisan','composer.json','composer.lock')){Copy-Item "$PackagePath\payload\app\$entry" "$root\app\$entry" -Force}
        Copy-Item "$PackagePath\installer\Worker.ps1" "$root\tasks\Worker.ps1" -Force
        Repair-OperationsAcl
        Invoke-DatabaseOperation 'migrate' $root
        & "$root\runtime\php\php.exe" "$root\app\artisan" config:cache
        if($LASTEXITCODE -ne 0){throw 'Konfigurationspruefung fehlgeschlagen.'}
        Invoke-DatabaseOperation 'health' $root
        $state.version=$manifest.version;Write-JsonFile "$root\installation.json" $state
        Set-OperationPhase 'verified' $backup
        Resume-Operations
        Write-Host "Update erfolgreich. Rollback-Sicherung: $backup"
    } catch {
        Write-Host 'Update fehlgeschlagen. Stelle Code und Datenbanken aus der Sicherung wieder her.'
        Set-OperationPhase 'rolling-back' $backup
        Restore-OperationsBackup $backup
        $state=Get-Content "$root\installation.json" -Raw|ConvertFrom-Json
        Set-OperationPhase 'rolled-back' $backup
        Resume-Operations
        throw 'Update abgebrochen; vorheriger Stand wiederhergestellt.'
    }
        if(-not(Test-Path "$root\operations-ui\scripts\OperationsUI-Worker.ps1") -and (Test-Path "$PackagePath\installer\Enable-OperationsUI.ps1")){
            & "$PackagePath\installer\Enable-OperationsUI.ps1" -InstallPath $root
        }
} finally {if($acquired){$mutex.ReleaseMutex()};$mutex.Dispose()}
