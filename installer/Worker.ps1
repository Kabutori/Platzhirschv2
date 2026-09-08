#requires -Version 5.1
param([Parameter(Mandatory)][string]$InstallPath,[ValidateSet('default','provisioning')][string]$Queue='default')
$ErrorActionPreference='Stop'
$php=Join-Path $InstallPath 'runtime\php\php.exe'
$artisan=Join-Path $InstallPath 'app\artisan'
# Windows has no PCNTL. Both limits remain below database retry_after=3900s.
# Large verified tenant copies may need considerably longer than a normal job.
$limitMilliseconds=if($Queue -eq 'provisioning'){3600000}else{180000}
while($true){
    $process=Start-Process $php -ArgumentList "`"$artisan`" queue:work database --queue=$Queue --once --tries=3 --backoff=15 --timeout=0" -WorkingDirectory (Split-Path $artisan) -PassThru -WindowStyle Hidden
    if(-not $process.WaitForExit($limitMilliseconds)){ $process.Kill();$process.WaitForExit() }
    $process.Dispose()
    Start-Sleep -Seconds 2
}
