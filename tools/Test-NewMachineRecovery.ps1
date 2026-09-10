# Clean Windows runner: recreate infrastructure, then restore portable application data.
param([string]$PackagePath,[string]$Archive)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$target='C:\ph-recovered'
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$PackagePath\installer\Install-Platzhirsch.ps1" -InstallPath $target -Port 8379 -DatabasePort 3310 -NoBrowser -Unattended
if($LASTEXITCODE -ne 0){throw 'Fresh recovery installation failed.'}
$state=Get-Content "$target\installation.json" -Raw|ConvertFrom-Json
foreach($name in @('rootPassword','appPassword','provisionPassword','setupToken','appKey')){Write-Output "::add-mask::$($state.$name)"}
Add-Type -AssemblyName System.IO.Compression.FileSystem
$backup='C:\ph-recovery-source'
[IO.Compression.ZipFile]::ExtractToDirectory($Archive,$backup)
$second='C:\ph-recovery-second';New-Item -ItemType Directory $second|Out-Null
$password=[Guid]::NewGuid().ToString('N')+'Aa7!';Write-Output "::add-mask::$password"
$utf8=New-Object Text.UTF8Encoding($false)
$ini="$second\my.ini"
$text="[mysqld]`nbasedir=C:/ph-recovered/runtime/mysql`ndatadir=C:/ph-recovery-second/data`nport=3309`nbind-address=127.0.0.1`nmysqlx=0`nlog-error=C:/ph-recovery-second/mysql.log`n"
[IO.File]::WriteAllText($ini,$text,$utf8)
& "$target\runtime\mysql\bin\mysqld.exe" "--defaults-file=$ini" --initialize-insecure
if($LASTEXITCODE -ne 0){throw 'Second recovery instance initialization failed.'}
[IO.File]::WriteAllText("$second\init.sql","ALTER USER 'root'@'localhost' IDENTIFIED BY '$password';",$utf8)
[IO.File]::WriteAllText($ini,($text+"init-file=C:/ph-recovery-second/init.sql`n"),$utf8)
$process=Start-Process "$target\runtime\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=$ini" -PassThru
try {
    $ready=$false
    for($i=0;$i -lt 60;$i++){$tcp=New-Object Net.Sockets.TcpClient;try{$tcp.Connect('127.0.0.1',3309);$ready=$true;break}catch{Start-Sleep -Seconds 1}finally{$tcp.Dispose()}}
    if(-not $ready){throw 'Second recovery instance not ready.'}
    $manifest=Get-Content "$backup\databases\databases.json" -Raw|ConvertFrom-Json
    $mapping=@{local=@{host='127.0.0.1';port=3310;username='root';password=$state.rootPassword;account_host='127.0.0.1';ca=$null}}
    foreach($property in $manifest.targets.PSObject.Properties){if($property.Name -ne 'local'){$mapping[$property.Name]=@{host='127.0.0.1';port=3309;username='root';password=$password;account_host='127.0.0.1';ca=$null}}}
    [IO.File]::WriteAllText("$target\recovery-targets.json",($mapping|ConvertTo-Json -Depth 5),$utf8)
    & "$PackagePath\installer\Recover-Platzhirsch.ps1" -Mode NewMachine -InstallPath $target -Destination $backup -TargetMapping "$target\recovery-targets.json" -SourceOffline -Confirm:$false
    & "$target\runtime\php\php.exe" "$PSScriptRoot\Test-OperationsData.php" $target check
    if($LASTEXITCODE -ne 0){throw 'Recovered rows differ on new machine.'}
    $status=Invoke-RestMethod 'http://127.0.0.1:8379/api/bootstrap-status'
    if(-not $status.bootstrapped){throw 'Restored administrator missing.'}
    & "$PackagePath\installer\Test-OperationsReadiness.ps1" -InstallPath $target -Requests 100 -Concurrency 8
    Write-Host 'Clean-machine recovery passed: new Windows host, new paths, changed local port, fresh MySQL identities, two restored databases servers, original binary contents and application health.'
} finally {Stop-Process -Id $process.Id -Force -ErrorAction SilentlyContinue;Remove-Item "$target\recovery-targets.json" -Force -ErrorAction SilentlyContinue}
