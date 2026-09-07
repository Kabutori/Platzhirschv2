#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding(SupportsShouldProcess,ConfirmImpact='High')]
param(
    [ValidateSet('Backup','Restore')][string]$Mode='Backup',
    [ValidatePattern('^[A-Za-z]:\\[A-Za-z0-9_-]+(?:\\[A-Za-z0-9_-]+)*$')][string]$InstallPath='C:\Platzhirsch',
    [Parameter(Mandatory)][string]$Destination
)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
Import-Module WebAdministration
$maintenanceMutex=New-Object Threading.Mutex($false,'Global\PlatzhirschMaintenance')
$maintenanceAcquired=$false
try {
    try {$maintenanceAcquired=$maintenanceMutex.WaitOne(0)}catch [Threading.AbandonedMutexException] {$maintenanceAcquired=$true}
    if(-not $maintenanceAcquired){throw 'Eine andere Sicherung oder Wiederherstellung laeuft bereits.'}
$root=[IO.Path]::GetFullPath($InstallPath).TrimEnd('\')
$destinationPath=[IO.Path]::GetFullPath($Destination).TrimEnd('\')
if($destinationPath -eq $root -or $destinationPath.StartsWith($root+'\',[StringComparison]::OrdinalIgnoreCase) -or $root.StartsWith($destinationPath+'\',[StringComparison]::OrdinalIgnoreCase)){throw 'Sicherung und Installation muessen getrennte Verzeichnisse sein.'}
$state=Get-Content "$root\installation.json" -Raw|ConvertFrom-Json
if($state.product -ne 'Platzhirsch' -or -not $state.completed){throw 'Abgeschlossene Platzhirsch-Installation erforderlich.'}
$taskNames=@('Platzhirsch-default','Platzhirsch-provisioning','Platzhirsch-Scheduler')
function Assert-NoLinks([string]$Path) {
    $parent=Get-Item -LiteralPath $Path -Force
    while($parent){if($parent.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Verknuepfungen und Junctions werden nicht unterstuetzt.'};$parent=$parent.Parent}
    if(Get-ChildItem -LiteralPath $Path -Force -Recurse|Where-Object {$_.Attributes -band [IO.FileAttributes]::ReparsePoint}|Select-Object -First 1){throw 'Verknuepfungen und Junctions werden nicht unterstuetzt.'}
}
function Protect-Snapshot([string]$Path) {
    & icacls.exe $Path /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' /q|Out-Null
    if($LASTEXITCODE -ne 0){throw 'Sicherungsverzeichnis konnte nicht geschuetzt werden.'}
}
function Copy-Tree([string]$From,[string]$To,[switch]$Mirror) {
    $mode=if($Mirror){'/MIR'}else{'/E'}
    # Do not copy the live ACL into the backup: all backup data stays admin-only.
    & robocopy.exe $From $To $mode /COPY:DAT /DCOPY:DAT /XJ /R:1 /W:1 /NFL /NDL /NJH /NJS /NP|Out-Null
    if($LASTEXITCODE -ge 8){throw "Dateikopie fehlgeschlagen (Robocopy $LASTEXITCODE)."}
}
function Stop-Application {
    foreach($taskName in $taskNames){Disable-ScheduledTask $taskName|Out-Null;Stop-ScheduledTask $taskName}
    if((Get-Website -Name Platzhirsch).State -eq 'Started'){Stop-Website Platzhirsch}
    if((Get-WebAppPoolState Platzhirsch).Value -eq 'Started'){Stop-WebAppPool Platzhirsch}
    $idle=$false
    for($wait=0;$wait -lt 60;$wait++){
        $running=@($taskNames|Where-Object {(Get-ScheduledTask $_).State -eq 'Running'})
        if($running.Count -eq 0 -and (Get-WebAppPoolState Platzhirsch).Value -eq 'Stopped'){$idle=$true;break}
        Start-Sleep -Seconds 1
    }
    if(-not $idle){throw 'Hintergrundaufgaben oder IIS konnten nicht rechtzeitig angehalten werden.'}
    # Scheduled-task cancellation can leave child PHP processes alive. Stop only this installation's runtime.
    Get-CimInstance Win32_Process|Where-Object {$_.ExecutablePath -and $_.ExecutablePath.StartsWith($root+'\runtime\php\',[StringComparison]::OrdinalIgnoreCase)}|ForEach-Object {Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue}
    Stop-Service PlatzhirschMySQL
    (Get-Service PlatzhirschMySQL).WaitForStatus('Stopped',[TimeSpan]::FromSeconds(60))
}
function Start-Application {
    Start-Service PlatzhirschMySQL
    (Get-Service PlatzhirschMySQL).WaitForStatus('Running',[TimeSpan]::FromSeconds(60))
    if((Get-WebAppPoolState Platzhirsch).Value -ne 'Started'){Start-WebAppPool Platzhirsch}
    if((Get-Website -Name Platzhirsch).State -ne 'Started'){Start-Website Platzhirsch}
    foreach($taskName in $taskNames){Enable-ScheduledTask $taskName|Out-Null;Start-ScheduledTask $taskName}
    for($attempt=0;$attempt -lt 30;$attempt++){
        try {
            $status=Invoke-RestMethod "http://127.0.0.1:$($state.port)/api/bootstrap-status" -TimeoutSec 5
            if($null -ne $status.bootstrapped){return}
        }catch{}
        Start-Sleep -Seconds 2
    }
    throw 'Anwendung nach Neustart nicht erreichbar. Sicherung bleibt erhalten.'
}
function Save-Snapshot([string]$Folder) {
    New-Item -ItemType Directory -Path $Folder|Out-Null;Protect-Snapshot $Folder
    # Save ACLs relative to the installation parent, for restoration to that exact path.
    Push-Location (Split-Path $root -Parent)
    try {& icacls.exe (Split-Path $root -Leaf) /save "$Folder\permissions.acl" /t /q|Out-Null;if($LASTEXITCODE -ne 0){throw 'Dateirechte konnten nicht gesichert werden.'}}finally{Pop-Location}
    Copy-Tree $root "$Folder\files"
    $prefix="$Folder\files\"
    $files=@(Get-ChildItem "$Folder\files" -Force -Recurse -File|ForEach-Object {@{path=$_.FullName.Substring($prefix.Length);sha256=(Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash}})
    @{format=1;product='Platzhirsch';machine=$env:COMPUTERNAME;installPath=$root;version=$state.version;createdAt=[DateTime]::UtcNow.ToString('o');permissionsHash=(Get-FileHash "$Folder\permissions.acl" -Algorithm SHA256).Hash;files=$files}|ConvertTo-Json -Depth 5|Set-Content "$Folder\snapshot.json" -Encoding UTF8
}
function Verify-Snapshot([string]$Folder) {
    Assert-NoLinks $Folder
    $manifest=Get-Content "$Folder\snapshot.json" -Raw|ConvertFrom-Json
    if($manifest.format -ne 1 -or $manifest.product -ne 'Platzhirsch' -or $manifest.machine -ne $env:COMPUTERNAME -or $manifest.installPath -ne $root -or $manifest.version -ne $state.version){throw 'Sicherung passt nicht zu Maschine, Pfad oder installierter Version.'}
    if((Get-FileHash "$Folder\permissions.acl" -Algorithm SHA256).Hash -ne $manifest.permissionsHash){throw 'Gesicherte Dateirechte sind beschaedigt.'}
    $seen=@{}
    foreach($file in $manifest.files){
        $full=[IO.Path]::GetFullPath((Join-Path "$Folder\files" $file.path))
        if(-not $full.StartsWith("$Folder\files\",[StringComparison]::OrdinalIgnoreCase) -or $seen.ContainsKey($full)){throw 'Ungueltiger Sicherungspfad.'}
        $seen[$full]=$true
        if(-not(Test-Path -LiteralPath $full -PathType Leaf) -or (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash -ne $file.sha256){throw 'Sicherung unvollstaendig oder beschaedigt. Installation bleibt unveraendert.'}
    }
    if(@(Get-ChildItem "$Folder\files" -Force -Recurse -File).Count -ne $seen.Count){throw 'Unerwartete Dateien in der Sicherung.'}
    $saved=Get-Content "$Folder\files\installation.json" -Raw|ConvertFrom-Json
    if($saved.port -ne $state.port -or $saved.databasePort -ne $state.databasePort){throw 'Ports passen nicht zur bestehenden Installation.'}
    $currentEnv=Get-Content "$root\app\.env"|Where-Object {$_ -match '^(APP_URL|SESSION_SECURE_COOKIE)='}
    $savedEnv=Get-Content "$Folder\files\app\.env"|Where-Object {$_ -match '^(APP_URL|SESSION_SECURE_COOKIE)='}
    if(($currentEnv -join "`n") -ne ($savedEnv -join "`n")){throw 'Netzwerkfreigabe wurde seit der Sicherung veraendert. Zuerst IIS und Zieladresse fachgerecht abgleichen.'}
    foreach($required in @('app\.env','app\artisan','runtime\mysql\bin\mysqld.exe','runtime\php\php.exe','my.ini')){if(-not(Test-Path "$Folder\files\$required")){throw 'Sicherung enthaelt keine vollstaendige Installation.'}}
}
Assert-NoLinks $root
if($Mode -eq 'Restore'){
    Verify-Snapshot $destinationPath
    if(-not $PSCmdlet.ShouldProcess($root,'Alle Anwendungsdaten auf den Sicherungszeitpunkt zuruecksetzen; aktuellen Stand vorher separat sichern')){return}
    $snapshotPath=$destinationPath
    $rollback=Join-Path (Split-Path $snapshotPath -Parent) ('vor-wiederherstellung-'+[Guid]::NewGuid().ToString('N'))
}else{
    if(-not(Test-Path $destinationPath)){New-Item -ItemType Directory -Path $destinationPath -Force|Out-Null}
    Assert-NoLinks $destinationPath
    if(-not $PSCmdlet.ShouldProcess($root,'Anwendung und MySQL kurz anhalten und vollstaendige Sicherung erstellen')){return}
    $snapshotPath=Join-Path $destinationPath ('platzhirsch-'+(Get-Date -Format 'yyyyMMdd-HHmmss')+'-'+[Guid]::NewGuid().ToString('N').Substring(0,8))
}
$stopped=$false;$safeToStart=$true
try {
    $stopped=$true;Stop-Application
    if($Mode -eq 'Backup'){
        Save-Snapshot $snapshotPath
        Write-Host "Vollstaendige Sicherung erstellt: $snapshotPath"
    }else{
        Save-Snapshot $rollback
        Write-Host "Stand vor Wiederherstellung gesichert: $rollback"
        $safeToStart=$false
        Copy-Tree "$snapshotPath\files" $root -Mirror
        & icacls.exe (Split-Path $root -Parent) /restore "$snapshotPath\permissions.acl" /q|Out-Null
        if($LASTEXITCODE -ne 0){throw "Dateirechte konnten nicht wiederhergestellt werden. Anwendung bleibt gestoppt. Ruecksicherung: $rollback"}
        $safeToStart=$true
        Write-Host 'Dateien, MySQL-Daten, Benutzer und Anwendungsschluessel wiederhergestellt.'
    }
}finally{if($stopped -and $safeToStart){Start-Application}}
Write-Output $snapshotPath

} finally {if($maintenanceAcquired){$maintenanceMutex.ReleaseMutex()};$maintenanceMutex.Dispose()}
