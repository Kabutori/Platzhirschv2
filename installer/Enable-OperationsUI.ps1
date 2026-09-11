#requires -Version 5.1
#requires -RunAsAdministrator
param([ValidatePattern('^[A-Za-z]:\\[A-Za-z0-9_-]+(?:\\[A-Za-z0-9_-]+)*$')][string]$InstallPath='C:\Platzhirsch')
$ErrorActionPreference='Stop'
$dir="$InstallPath\operations-ui"
foreach($path in @($dir,"$dir\public","$dir\inbox","$dir\private","$dir\packages","$dir\scripts","$InstallPath-Backups")){New-Item -ItemType Directory -Force $path|Out-Null}
& icacls.exe $dir /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' /q|Out-Null
if($LASTEXITCODE -ne 0){throw 'Betriebsverwaltung konnte nicht geschuetzt werden.'}
& icacls.exe "$dir\public" /grant 'IIS AppPool\Platzhirsch:(OI)(CI)RX' /q|Out-Null
if($LASTEXITCODE -ne 0){throw 'Statusrechte fehlen.'}
& icacls.exe "$dir\inbox" /grant 'IIS AppPool\Platzhirsch:(OI)(CI)M' /q|Out-Null
if($LASTEXITCODE -ne 0){throw 'Auftragsrechte fehlen.'}
& icacls.exe "$InstallPath-Backups" /inheritance:r /grant:r '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' /q|Out-Null
if($LASTEXITCODE -ne 0){throw 'Backupverzeichnis konnte nicht geschuetzt werden.'}
Copy-Item "$PSScriptRoot\*.ps1","$PSScriptRoot\*.php" "$dir\scripts" -Force
$action=New-ScheduledTaskAction -Execute "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$dir\scripts\OperationsUI-Worker.ps1`" -InstallPath `"$InstallPath`""
$principal=New-ScheduledTaskPrincipal -UserId 'S-1-5-18' -LogonType ServiceAccount -RunLevel Highest
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew
Stop-ScheduledTask 'Platzhirsch-OperationsUI' -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName 'Platzhirsch-OperationsUI' -Action $action -Trigger (New-ScheduledTaskTrigger -AtStartup) -Principal $principal -Settings $settings -Force|Out-Null
Start-ScheduledTask 'Platzhirsch-OperationsUI'
