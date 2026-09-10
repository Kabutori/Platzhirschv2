#requires -Version 5.1
param([string]$InstallPath,[string]$JobId)
$ErrorActionPreference='Stop'
if($JobId -notmatch '^[a-f0-9-]{36}$'){exit 2}
$dir="$InstallPath\operations-ui"
$job=Get-Content "$dir\private\$JobId.json" -Raw|ConvertFrom-Json
try {
 switch($job.action){
  'backup' {& "$PSScriptRoot\Recover-Platzhirsch.ps1" -Mode Backup -InstallPath $InstallPath -Destination "$InstallPath-Backups\backup-$JobId" -Confirm:$false}
  'verify' {& "$PSScriptRoot\Recover-Platzhirsch.ps1" -Mode Verify -InstallPath $InstallPath -Destination "$InstallPath-Backups\$($job.target)" -Confirm:$false}
  'restore' {& "$PSScriptRoot\Recover-Platzhirsch.ps1" -Mode Restore -InstallPath $InstallPath -Destination "$InstallPath-Backups\$($job.target)" -Confirm:$false}
  'rollback' {& "$PSScriptRoot\Update-Platzhirsch.ps1" -Mode Rollback -InstallPath $InstallPath -BackupPath "$InstallPath-Backups\$($job.target)" -BackupRoot "$InstallPath-Backups" -Confirm:$false}
  'update' {& "$PSScriptRoot\Update-Platzhirsch.ps1" -InstallPath $InstallPath -PackagePath "$dir\packages\$($job.target)" -BackupRoot "$InstallPath-Backups" -Confirm:$false}
  'check' {& "$PSScriptRoot\Test-OperationsReadiness.ps1" -InstallPath $InstallPath -Requests 100 -Concurrency 8}
  default {throw 'Unbekannte Aktion.'}
 }
 [IO.File]::WriteAllText("$dir\private\$JobId.result",'success')
 exit 0
}catch{[IO.File]::WriteAllText("$dir\private\$JobId.result",'failed');Write-Error -ErrorAction Continue $_;exit 1}
