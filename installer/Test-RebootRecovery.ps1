#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding(SupportsShouldProcess,ConfirmImpact='High')]
param([string]$InstallPath='C:\Platzhirsch',[switch]$RestartNow)
$ErrorActionPreference='Stop'
if(Test-Path "$InstallPath\maintenance.json"){throw 'Kein Neustarttest waehrend einer Betriebsoperation.'}
$boot=(Get-CimInstance Win32_OperatingSystem).LastBootUpTime.ToUniversalTime().ToString('o')
$destination="$InstallPath\tasks\reboot-check"
New-Item -ItemType Directory $destination -Force|Out-Null
Copy-Item "$PSScriptRoot\Test-OperationsReadiness.ps1","$PSScriptRoot\Database-Recovery.php" $destination -Force
$runner=@'
param([string]$Root,[string]$Boot)
$ErrorActionPreference='Stop'
for($attempt=0;$attempt -lt 30;$attempt++){
    try {& "$PSScriptRoot\Test-OperationsReadiness.ps1" -InstallPath $Root -ExpectedPreviousBoot $Boot;Unregister-ScheduledTask Platzhirsch-RebootCheck -Confirm:$false;exit 0}catch{Start-Sleep -Seconds 10}
}
'Neustartpruefung fehlgeschlagen. Dienste, Aufgaben und Anwendungslogs pruefen.'|Set-Content "$Root\logs\reboot-check-failed.txt"
exit 1
'@
[IO.File]::WriteAllText("$destination\Run.ps1",$runner,(New-Object Text.UTF8Encoding($false)))
$action=New-ScheduledTaskAction -Execute "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$destination\Run.ps1`" -Root `"$InstallPath`" -Boot `"$boot`""
Register-ScheduledTask -TaskName Platzhirsch-RebootCheck -Action $action -Trigger (New-ScheduledTaskTrigger -AtStartup) -Principal (New-ScheduledTaskPrincipal -UserId SYSTEM -LogonType ServiceAccount -RunLevel Highest) -Settings (New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 15)) -Force|Out-Null
Write-Host "Neustartpruefung vorbereitet. Ergebnis nach dem Neustart: $InstallPath\logs\operations-readiness.json"
if($RestartNow -and $PSCmdlet.ShouldProcess($env:COMPUTERNAME,'Windows jetzt neu starten und anschliessend Dienste, Datenbanken und HTTP pruefen')){Restart-Computer -Force}
