#requires -Version 5.1
param([ValidatePattern('^[A-Za-z]:\\[A-Za-z0-9_-]+(?:\\[A-Za-z0-9_-]+)*$')][string]$InstallPath)
$ErrorActionPreference='Stop'
$dir="$InstallPath\operations-ui";$utf8=New-Object Text.UTF8Encoding($false)
function Write-Atomic($path,$value){$tmp=$path+'.tmp';[IO.File]::WriteAllText($tmp,($value|ConvertTo-Json -Depth 10),$utf8);Move-Item -LiteralPath $tmp -Destination $path -Force}
function Catalog($folder,$manifest){
 @(Get-ChildItem -LiteralPath $folder -Directory|Where-Object {$_.Name -match '^[a-zA-Z0-9_-]{1,100}$' -and -not($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -and (Test-Path (Join-Path $_.FullName $manifest))}|ForEach-Object {@{id=$_.Name;label=$_.Name}})
}
$jobs=@();if(Test-Path "$dir\private\history.json"){$jobs=@(Get-Content "$dir\private\history.json" -Raw|ConvertFrom-Json)}
foreach($j in $jobs){if($j.status -eq 'running'){$j.status='interrupted';$j.message='Windows-Ausfuehrung unterbrochen. Wartungsjournal am Server pruefen.'}}
$process=$null;$active=$null
while($true){
 $backups=@(Catalog "$InstallPath-Backups" 'recovery.json');$packages=@(Catalog "$dir\packages" 'release-manifest.json')
 if($process -and $process.HasExited){$active.status=if($process.ExitCode -eq 0){'success'}else{'failed'};$active.message=if($process.ExitCode -eq 0){'Aktion erfolgreich abgeschlossen.'}else{'Aktion fehlgeschlagen. Details im geschuetzten Serverprotokoll; Wartungszustand pruefen.'};$active.finishedAt=[DateTime]::UtcNow.ToString('o');$process.Dispose();$process=$null}
 if(-not $process){
  foreach($file in @(Get-ChildItem "$dir\inbox" -File -Filter '*.json'|Sort-Object CreationTimeUtc)){
   if($file.Attributes -band [IO.FileAttributes]::ReparsePoint){Remove-Item -LiteralPath $file.FullName -Force;continue}
   if($file.Length -gt 4096){Remove-Item -LiteralPath $file.FullName -Force;continue}
   try{$r=Get-Content -LiteralPath $file.FullName -Raw|ConvertFrom-Json}catch{continue}
   if($r.id -notmatch '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$' -or $file.BaseName -ne $r.id -or $r.action -notin @('backup','verify','restore','rollback','update','check')){Remove-Item -LiteralPath $file.FullName -Force;continue}
   if(Test-Path "$dir\private\$($r.id).json"){Remove-Item -LiteralPath $file.FullName -Force;continue}
   $valid=$true
   if($r.action -in @('verify','restore','rollback')){$valid=$r.target -in @($backups|ForEach-Object {$_.id})}
   if($r.action -eq 'update'){$valid=$r.target -in @($packages|ForEach-Object {$_.id})}
   try{if([DateTime]::Parse($r.createdAt).ToUniversalTime() -lt [DateTime]::UtcNow.AddMinutes(-5)){$valid=$false}}catch{$valid=$false}
   $active=[pscustomobject]@{id=$r.id;action=$r.action;target=$r.target;actor=$r.actor;createdAt=$r.createdAt;finishedAt=$null;status='running';message='Windows fuehrt die Aktion aus. Bei Wartung wird die Verbindung unterbrochen.'}
   Write-Atomic "$dir\private\$($r.id).json" $active
   Remove-Item -LiteralPath $file.FullName -Force
   $jobs=@($active)+@($jobs|Select-Object -First 49)
   if(-not $valid){$active.status='failed';$active.message='Auftrag abgelaufen oder Ziel nicht freigegeben.';continue}
   Write-Atomic "$dir\private\history.json" $jobs
   $arguments="-NoProfile -ExecutionPolicy Bypass -File `"$PSScriptRoot\OperationsUI-Run.ps1`" -InstallPath `"$InstallPath`" -JobId $($active.id)"
   $process=Start-Process "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -ArgumentList $arguments -PassThru -WindowStyle Hidden -RedirectStandardOutput "$dir\private\$($active.id).log" -RedirectStandardError "$dir\private\$($active.id).error.log"
   break
  }
 }
 Write-Atomic "$dir\private\history.json" $jobs
 Write-Atomic "$dir\public\state.json" @{heartbeat=[DateTime]::UtcNow.ToString('o');backups=@($backups);packages=@($packages);jobs=@($jobs|Select-Object id,action,status,createdAt,finishedAt,message)}
 Start-Sleep -Seconds 2
}
