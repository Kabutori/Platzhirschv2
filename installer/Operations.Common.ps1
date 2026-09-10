# Shared administrative operations. Caller supplies $root, $state and $PSScriptRoot.
$taskNames=@('Platzhirsch-default','Platzhirsch-provisioning','Platzhirsch-Scheduler')
$utf8=New-Object Text.UTF8Encoding($false)
function Write-JsonFile([string]$Path,$Value) { [IO.File]::WriteAllText($Path,($Value|ConvertTo-Json -Depth 15),$utf8) }
function Protect-OperationsPath([string]$Path) {
    & icacls.exe $Path /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' /q|Out-Null
    if($LASTEXITCODE -ne 0){throw 'Operationsverzeichnis konnte nicht geschuetzt werden.'}
}
function Assert-OperationsPath([string]$Path) {
    $item=Get-Item -LiteralPath $Path -Force
    while($item){if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Verknuepfungen sind nicht erlaubt.'};$item=$item.Parent}
    if(Get-ChildItem -LiteralPath $Path -Force -Recurse|Where-Object {$_.Attributes -band [IO.FileAttributes]::ReparsePoint}|Select-Object -First 1){throw 'Verknuepfungen sind nicht erlaubt.'}
}
function Copy-OperationsTree([string]$From,[string]$To,[switch]$Mirror) {
    $mode=if($Mirror){'/MIR'}else{'/E'}
    & robocopy.exe $From $To $mode /COPY:DAT /DCOPY:DAT /XJ /R:1 /W:1 /NFL /NDL /NJH /NJS /NP|Out-Null
    if($LASTEXITCODE -ge 8){throw 'Dateikopie fehlgeschlagen.'}
}
function Invoke-DatabaseOperation([string]$Mode,[string]$Folder,[string]$Mapping='') {
    & "$root\runtime\php\php.exe" "$PSScriptRoot\Database-Recovery.php" $root $Mode $Folder $Mapping
    if($LASTEXITCODE -ne 0){throw "Datenbankoperation $Mode fehlgeschlagen. Anwendung bleibt angehalten."}
}
function Set-OperationPhase([string]$Phase,[string]$Backup='') {
    $journal=Get-Content "$root\maintenance.json" -Raw|ConvertFrom-Json
    $journal.phase=$Phase
    if($Backup){$journal.backup=$Backup}
    Write-JsonFile "$root\maintenance.json" $journal
    Write-Host "Betriebsoperation: $Phase"
}
function Suspend-Operations([int]$DrainSeconds=300) {
    if(Test-Path "$root\maintenance.json"){throw 'Eine unterbrochene Operation ist vorhanden. Zuerst Status pruefen und Resume/Rollback ausfuehren.'}
    $tasks=@($taskNames|ForEach-Object {@{name=$_;enabled=[bool](Get-ScheduledTask $_).Settings.Enabled}})
    Write-JsonFile "$root\maintenance.json" @{format=1;phase='stopping';backup='';createdAt=[DateTime]::UtcNow.ToString('o');tasks=$tasks;siteStarted=((Get-Website Platzhirsch).State -eq 'Started');poolStarted=((Get-WebAppPoolState Platzhirsch).Value -eq 'Started');siteAutoStart=[bool](Get-Item IIS:\Sites\Platzhirsch).serverAutoStart;poolAutoStart=[bool](Get-Item IIS:\AppPools\Platzhirsch).autoStart}
    Set-ItemProperty IIS:\Sites\Platzhirsch -Name serverAutoStart -Value $false
    Set-ItemProperty IIS:\AppPools\Platzhirsch -Name autoStart -Value $false
    foreach($name in $taskNames){Disable-ScheduledTask $name|Out-Null;Stop-ScheduledTask $name}
    if((Get-Website Platzhirsch).State -eq 'Started'){Stop-Website Platzhirsch}
    if((Get-WebAppPoolState Platzhirsch).Value -eq 'Started'){Stop-WebAppPool Platzhirsch}
    # Never kill in-flight SQL/migrations. Task parents are stopped; their current PHP child may finish.
    $idle=$false
    for($n=0;$n -lt $DrainSeconds;$n++){
        $php=@(Get-CimInstance Win32_Process|Where-Object {$_.ExecutablePath -and $_.ExecutablePath.StartsWith($root+'\runtime\php\',[StringComparison]::OrdinalIgnoreCase)})
        if($php.Count -eq 0 -and (Get-WebAppPoolState Platzhirsch).Value -eq 'Stopped'){$idle=$true;break}
        Start-Sleep -Seconds 1
    }
    if(-not $idle){throw 'Aktive PHP-Arbeit wurde nicht abgeschlossen. Keine Daten geaendert; Wartungsmodus bleibt aktiv.'}
    Set-OperationPhase 'stopped'
}
function Repair-OperationsAcl {
    & icacls.exe "$root\app" /grant 'IIS AppPool\Platzhirsch:(OI)(CI)RX' '*S-1-5-19:(OI)(CI)RX' /q|Out-Null
    if($LASTEXITCODE -ne 0){throw 'Anwendungsrechte fehlgeschlagen.'}
    & icacls.exe "$root\app\storage" /grant 'IIS AppPool\Platzhirsch:(OI)(CI)M' '*S-1-5-19:(OI)(CI)M' /q|Out-Null
    if($LASTEXITCODE -ne 0){throw 'Speicherrechte fehlgeschlagen.'}
    Protect-OperationsPath "$root\app\storage\app\private"
    & icacls.exe "$root\app\storage\app\private" /grant '*S-1-5-19:(OI)(CI)RX' /q|Out-Null
    if($LASTEXITCODE -ne 0){throw 'Workerrechte fehlgeschlagen.'}
}
function Resume-Operations {
    Invoke-DatabaseOperation 'health' $root
    $journal=Get-Content "$root\maintenance.json" -Raw|ConvertFrom-Json
    Set-ItemProperty IIS:\Sites\Platzhirsch -Name serverAutoStart -Value ([bool]$journal.siteAutoStart)
    Set-ItemProperty IIS:\AppPools\Platzhirsch -Name autoStart -Value ([bool]$journal.poolAutoStart)
    if($journal.poolStarted){Start-WebAppPool Platzhirsch}
    if($journal.siteStarted){Start-Website Platzhirsch}
    if($journal.siteStarted){
        $ok=$false
        for($n=0;$n -lt 20;$n++){try{$response=Invoke-RestMethod "http://127.0.0.1:$($state.port)/api/bootstrap-status" -TimeoutSec 5;if($null -ne $response.bootstrapped){$ok=$true;break}}catch{};Start-Sleep -Seconds 1}
        if(-not $ok){Stop-Website Platzhirsch;Stop-WebAppPool Platzhirsch;throw 'HTTP-Pruefung fehlgeschlagen. Hintergrundaufgaben bleiben deaktiviert.'}
    }
    Remove-Item "$root\maintenance.json" -Force
    foreach($task in $journal.tasks){if($task.enabled){Enable-ScheduledTask $task.name|Out-Null;Start-ScheduledTask $task.name}}
}
function Save-OperationsBackup([string]$Folder) {
    if(Test-Path $Folder){throw 'Sicherungsziel muss neu sein.'}
    New-Item -ItemType Directory "$Folder\databases","$Folder\files" -Force|Out-Null
    Protect-OperationsPath $Folder
    Invoke-DatabaseOperation 'backup' "$Folder\databases"
    Copy-OperationsTree "$root\app" "$Folder\files\app"
    Copy-OperationsTree "$root\tasks" "$Folder\files\tasks"
    Copy-Item "$root\installation.json" "$Folder\files\installation.json"
    $prefix="$Folder\files\"
    $files=@(Get-ChildItem "$Folder\files" -Recurse -Force -File|ForEach-Object {@{path=$_.FullName.Substring($prefix.Length);sha256=(Get-FileHash $_.FullName -Algorithm SHA256).Hash}})
    $dbPrefix="$Folder\databases\"
    $databases=@(Get-ChildItem "$Folder\databases" -File|ForEach-Object {@{path=$_.FullName.Substring($dbPrefix.Length);sha256=(Get-FileHash $_.FullName -Algorithm SHA256).Hash}})
    Write-JsonFile "$Folder\recovery.json" @{format=2;product='Platzhirsch';version=$state.version;machine=$env:COMPUTERNAME;installPath=$root;createdAt=[DateTime]::UtcNow.ToString('o');files=$files;databases=$databases}
    Write-Host "Koordinierte Sicherung: $Folder"
}
function Verify-OperationsBackup([string]$Folder) {
    Assert-OperationsPath $Folder
    $manifest=Get-Content "$Folder\recovery.json" -Raw|ConvertFrom-Json
    if($manifest.format -ne 2 -or $manifest.product -ne 'Platzhirsch'){throw 'Unbekanntes Sicherungsformat.'}
    foreach($group in @('files','databases')){
        $seen=@{}
        foreach($entry in $manifest.$group){
            $path=[IO.Path]::GetFullPath((Join-Path "$Folder\$group" $entry.path))
            if(-not $path.StartsWith("$Folder\$group\",[StringComparison]::OrdinalIgnoreCase) -or $seen.ContainsKey($path)){throw 'Ungueltiger Sicherungspfad.'}
            $seen[$path]=$true
            if(-not(Test-Path -LiteralPath $path -PathType Leaf) -or (Get-FileHash $path -Algorithm SHA256).Hash -ne $entry.sha256){throw 'Sicherung unvollstaendig oder beschaedigt.'}
        }
        if(@(Get-ChildItem "$Folder\$group" -Recurse -Force -File).Count -ne $seen.Count){throw 'Unerwartete Sicherungsdateien.'}
    }
    foreach($required in @('app\.env','app\artisan','app\vendor\autoload.php','installation.json','tasks\Worker.ps1')){if(-not(Test-Path "$Folder\files\$required")){throw 'Erforderliche Wiederherstellungsdatei fehlt.'}}
    return $manifest
}
function Restore-OperationsBackup([string]$Folder) {
    $manifest=Verify-OperationsBackup $Folder
    if($manifest.installPath -ne $root -or $manifest.machine -ne $env:COMPUTERNAME){throw 'Anderer Rechner/Pfad: den NewMachine-Wiederherstellungsmodus verwenden.'}
    # APP_KEY currently matches this installation. Restore databases before rolling back executable code.
    Invoke-DatabaseOperation 'restore' "$Folder\databases"
    Copy-OperationsTree "$Folder\files\app" "$root\app" -Mirror
    Copy-OperationsTree "$Folder\files\tasks" "$root\tasks" -Mirror
    Copy-Item "$Folder\files\installation.json" "$root\installation.json" -Force
    Repair-OperationsAcl
    & "$root\runtime\php\php.exe" "$root\app\artisan" config:cache
    if($LASTEXITCODE -ne 0){throw 'Konfiguration konnte nicht wiederhergestellt werden.'}
}
