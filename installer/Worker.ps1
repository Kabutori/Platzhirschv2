#requires -Version 5.1
param([Parameter(Mandatory)][string]$InstallPath,[ValidateSet('default','provisioning')][string]$Queue='default')
$ErrorActionPreference='Stop'
$php=Join-Path $InstallPath 'runtime\php\php.exe'
$artisan=Join-Path $InstallPath 'app\artisan'
# Windows has no PCNTL. The parent enforces a hard per-job limit below retry_after=300s.
while($true){
    $process=Start-Process $php -ArgumentList "`"$artisan`" queue:work database --queue=$Queue --once --tries=3 --backoff=15 --timeout=0" -WorkingDirectory (Split-Path $artisan) -PassThru -WindowStyle Hidden
    if(-not $process.WaitForExit(180000)){ $process.Kill();$process.WaitForExit() }
    $process.Dispose()
    Start-Sleep -Seconds 2
}
