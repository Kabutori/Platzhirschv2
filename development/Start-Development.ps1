#requires -Version 5.1
[CmdletBinding()]
param(
    [string]$RuntimePath='C:\Platzhirsch\runtime',
    [string]$PhpPath='',
    [string]$MySqlBin='',
    [ValidateRange(1024,65535)][int]$WebPort=5173,
    [ValidateRange(1024,65535)][int]$ApiPort=8000,
    [ValidateRange(1024,65535)][int]$DatabasePort=33018,
    [switch]$LocalComposer,
    [switch]$SmokeTest,
    [switch]$NoBrowser
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root=Split-Path $PSScriptRoot -Parent
$dev=Join-Path $root '.development'
$app=Join-Path $root 'app'
$utf8=New-Object Text.UTF8Encoding($false)
$children=New-Object 'System.Collections.Generic.List[System.Diagnostics.Process]'
$jobs=@();$lock=$null;$mysqlStarted=$false
function Status([string]$text){Write-Host "[$(Get-Date -Format HH:mm:ss)] DEV: $text"}
function Write-File([string]$path,[string]$text){[IO.File]::WriteAllText($path,$text,$utf8)}
function Checked([string]$file,[string[]]$arguments){& $file @arguments;if($LASTEXITCODE -ne 0){throw "Fehlgeschlagen: $file (Exitcode $LASTEXITCODE)"}}
function Secret { $bytes=New-Object byte[] 32;$rng=[Security.Cryptography.RandomNumberGenerator]::Create();try{$rng.GetBytes($bytes)}finally{$rng.Dispose()};return -join($bytes|ForEach-Object{$_.ToString('x2')}) }
function Protect([string]$path){
    $acl=New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true,$false)
    foreach($sid in @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,'S-1-5-18','S-1-5-32-544')){
        $rule=New-Object Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier($sid)),'FullControl','ContainerInherit,ObjectInherit','None','Allow');$acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $path -AclObject $acl
}
function Launch([string]$file,[string[]]$arguments,[string]$name){
    # All arguments are internally generated; paths containing quotes are rejected below.
    $quoted=@($arguments|ForEach-Object{'"'+$_+'"'})
    $p=Start-Process -FilePath $file -ArgumentList $quoted -WorkingDirectory $root -PassThru -NoNewWindow -RedirectStandardOutput "$dev\$name.out.log" -RedirectStandardError "$dev\$name.err.log"
    $children.Add($p);return $p
}
try {
    Status 'Quellcode und Voraussetzungen pruefen'
    if(-not(Test-Path "$root\.git") -or -not(Test-Path "$root\admin-ui\src\main.tsx")){throw 'Git-Checkout erforderlich. Anleitung: docs\DEVELOPMENT.md. Das Release-ZIP ist kein Quellcode-Checkout.'}
    foreach($tool in @('git','node','npm.cmd')){if(-not(Get-Command $tool -ErrorAction SilentlyContinue)){throw "$tool fehlt. Zuerst die Voraussetzungen aus docs\DEVELOPMENT.md installieren."}}
    if(!$PhpPath){$PhpPath=Join-Path $RuntimePath 'php\php.exe'}
    if(!$MySqlBin){$MySqlBin=Join-Path $RuntimePath 'mysql\bin'}
    $PhpPath=(Resolve-Path $PhpPath).Path;$MySqlBin=(Resolve-Path $MySqlBin).Path
    $mysqld=Join-Path $MySqlBin 'mysqld.exe'
    foreach($file in @($mysqld,"$MySqlBin\mysqladmin.exe")){if(-not(Test-Path $file)){throw "Laufzeitdatei fehlt: $file"}}
    foreach($path in @($root,$PhpPath,$MySqlBin)){if($path -match '["\r\n]'){throw 'Pfad mit Anfuehrungszeichen/Zeilenumbruch nicht unterstuetzt.'}}
    if(@($WebPort,$ApiPort,$DatabasePort|Select-Object -Unique).Count -ne 3){throw 'Drei unterschiedliche Ports erforderlich.'}
    foreach($port in @($WebPort,$ApiPort,$DatabasePort)){if(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue){throw "Port $port belegt. Keine laufende Instanz wird uebernommen oder beendet."}}
    if(Test-Path "$app\.env"){throw 'app\.env existiert. Fuer Entwicklung ein separates frisches Git-Checkout verwenden; vorhandene Konfiguration bleibt erhalten.'}
    if(Test-Path "$app\bootstrap\cache\config.php"){throw 'Gecachte Anwendungskonfiguration vorhanden. Frisches Entwicklungs-Checkout verwenden.'}
    New-Item -ItemType Directory -Path $dev -Force|Out-Null;Protect $dev
    $lock=[IO.File]::Open("$dev\running.lock",'OpenOrCreate','ReadWrite','None')
    foreach($dir in @('logs','framework\sessions','framework\views','framework\cache','app\private')){New-Item -ItemType Directory -Path "$dev\storage\$dir" -Force|Out-Null}
    $stateFile="$dev\state.json"
    if(Test-Path $stateFile){$state=Get-Content $stateFile -Raw|ConvertFrom-Json;if($state.databasePort -ne $DatabasePort){throw 'Bei erneutem Start denselben Datenbankport verwenden.'}}
    else{
        if(Test-Path "$dev\data"){throw 'Daten ohne Entwicklungsstatus gefunden. Keine automatische Neuinitialisierung.'}
        $state=[pscustomobject]@{databasePort=$DatabasePort;rootPassword=(Secret);appPassword=(Secret);provisionPassword=(Secret);setupToken=(Secret);appKey=('base64:'+ [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes((Secret).Substring(0,32))))}
        Write-File $stateFile ($state|ConvertTo-Json)
    }
    $env:PATH=(Split-Path $PhpPath -Parent)+';'+$env:PATH
    $env:PHPRC="$dev\php.ini"
    $env:PHP_INI_SCAN_DIR="$dev\empty-ini"
    New-Item -ItemType Directory -Path $env:PHP_INI_SCAN_DIR -Force|Out-Null
    Write-File $env:PHPRC @"
[PHP]
extension_dir="$(Split-Path $PhpPath -Parent)\ext"
extension=curl
extension=fileinfo
extension=intl
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=pdo_sqlite
extension=sqlite3
extension=sodium
extension=zip
memory_limit=512M
opcache.enable=0
log_errors=On
display_errors=Off
error_log="$dev\php.log"
"@
    # Environment variables are inherited by PHP/Composer/workers, not written into the checkout.
    $env:APP_ENV='local';$env:APP_DEBUG='true';$env:APP_KEY=$state.appKey
    $env:APP_URL="http://127.0.0.1:$WebPort";$env:APP_TIMEZONE='Europe/Berlin'
    $env:PLATZHIRSCH_DEV_STORAGE="$dev\storage"
    $env:SESSION_COOKIE="platzhirsch_dev_$WebPort";$env:SESSION_SECURE_COOKIE='false'
    $env:SESSION_DRIVER='database';$env:CACHE_STORE='database';$env:QUEUE_CONNECTION='database';$env:MAIL_MAILER='log'
    $env:DB_CONNECTION='mysql';$env:DB_HOST='127.0.0.1';$env:DB_PORT=[string]$DatabasePort
    $env:DB_DATABASE='platzhirsch_development';$env:DB_USERNAME='ph_dev';$env:DB_PASSWORD=$state.appPassword
    $sha=[Security.Cryptography.SHA256]::Create();try{$env:BOOTSTRAP_TOKEN_HASH=-join($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($state.setupToken))|ForEach-Object{$_.ToString('x2')})}finally{$sha.Dispose()}
    Write-File "$dev\storage\app\private\provision.json" (@{username='ph_dev_provision';password=$state.provisionPassword}|ConvertTo-Json)
    New-Item -ItemType Directory -Path "$app\bootstrap\cache" -Force|Out-Null
    Status 'Composer- und npm-Abhaengigkeiten anhand der Lockdateien installieren'
    $composerPhar=$null
    if($LocalComposer -or -not(Get-Command composer -ErrorAction SilentlyContinue)){
        $composerPhar="$dev\tools\composer.phar"
        if(-not(Test-Path $composerPhar)){
            Status 'Composer lokal herunterladen und Installer-Pruefsumme kontrollieren'
            New-Item -ItemType Directory -Path "$dev\tools" -Force|Out-Null
            [Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
            $installer="$dev\tools\composer-setup.php"
            try {
                $expected=(Invoke-WebRequest -UseBasicParsing 'https://composer.github.io/installer.sig' -TimeoutSec 60).Content.Trim()
                if($expected -notmatch '^[a-fA-F0-9]{96}$'){throw 'Ungueltige Composer-Pruefsumme.'}
                Invoke-WebRequest -UseBasicParsing 'https://getcomposer.org/installer' -OutFile $installer -TimeoutSec 60
                if((Get-FileHash $installer -Algorithm SHA384).Hash -ne $expected){throw 'Composer-Installer-Pruefsumme stimmt nicht.'}
                Checked $PhpPath @($installer,'--2',"--install-dir=$dev\tools",'--filename=composer.phar')
            } finally {if(Test-Path $installer){Remove-Item $installer}}
        }
    }
    Push-Location $app
    try {
        if($composerPhar){Checked $PhpPath @($composerPhar,'install','--no-interaction','--prefer-dist')}
        else{Checked 'composer' @('install','--no-interaction','--prefer-dist')}
    } finally {Pop-Location}
    Push-Location "$root\admin-ui";try{Checked 'npm.cmd' @('ci')}finally{Pop-Location}
    Checked 'node' @("$root\widget-embed\build.mjs")
    $ini="$dev\my.ini";$init="$dev\initialize.sql"
    Write-File $ini "[mysqld]`nbasedir=$((Split-Path $MySqlBin -Parent).Replace('\','/'))`ndatadir=$($dev.Replace('\','/'))/data`nport=$DatabasePort`nbind-address=127.0.0.1`nmysqlx=0`nlog-error=$($dev.Replace('\','/'))/mysql.log`n"
    Write-File "$dev\admin.cnf" "[client]`nuser=root`npassword=$($state.rootPassword)`nhost=127.0.0.1`nport=$DatabasePort`n"
    if(-not(Test-Path "$dev\data\mysql")){Status 'Eigene Entwicklungsdatenbank initialisieren';Checked $mysqld @("--defaults-file=$ini",'--initialize-insecure')}
    $sql=@"
ALTER USER 'root'@'localhost' IDENTIFIED BY '$($state.rootPassword)';
CREATE DATABASE IF NOT EXISTS platzhirsch_development CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ph_dev'@'127.0.0.1' IDENTIFIED BY '$($state.appPassword)';
GRANT ALL ON platzhirsch_development.* TO 'ph_dev'@'127.0.0.1';
CREATE USER IF NOT EXISTS 'ph_dev_provision'@'127.0.0.1' IDENTIFIED BY '$($state.provisionPassword)';
GRANT CREATE USER ON *.* TO 'ph_dev_provision'@'127.0.0.1';
GRANT SELECT ON platzhirsch_development.* TO 'ph_dev_provision'@'127.0.0.1';
GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES,LOCK TABLES,TRIGGER ON ``ph\_t\_%``.* TO 'ph_dev_provision'@'127.0.0.1' WITH GRANT OPTION;
"@
    Write-File $init $sql
    Status "MySQL auf 127.0.0.1:$DatabasePort starten"
    $dbProcess=Launch $mysqld @("--defaults-file=$ini","--init-file=$init",'--console') 'mysql';$mysqlStarted=$true
    $ready=$false
    for($i=0;$i -lt 60;$i++){
        if($dbProcess.HasExited){throw 'MySQL konnte nicht starten. Siehe .development\mysql.err.log.'}
        & $PhpPath "$app\artisan" platform:health *> $null
        if($LASTEXITCODE -eq 0){$ready=$true;break};Start-Sleep -Seconds 1
    }
    if(!$ready){throw 'Entwicklungsdatenbank antwortet nicht.'}
    Remove-Item $init
    Status 'Entwicklungsmigrationen anwenden (keine Produktionsverbindung)'
    Checked $PhpPath @("$app\artisan",'migrate','--force')
    Status 'API und Vite mit Live-Aktualisierung starten'
    $apiProcess=Launch $PhpPath @('-S',"127.0.0.1:$ApiPort",'-t',"$app\public","$PSScriptRoot\router.php") 'api'
    $env:PLATZHIRSCH_DEV_API_PORT=[string]$ApiPort
    $vite=Launch (Get-Command node).Source @("$root\admin-ui\node_modules\vite\bin\vite.js","$root\admin-ui",'--host','127.0.0.1','--port',[string]$WebPort,'--strictPort') 'vite'
    foreach($queue in @('provisioning','default')){
        $jobs+=Start-Job -ArgumentList $PhpPath,"$app\artisan",$queue -ScriptBlock {param($php,$artisan,$queue);while($true){& $php $artisan queue:work "--queue=$queue" --once --tries=1 --timeout=180;Start-Sleep -Seconds 1}}
    }
    $jobs+=Start-Job -ArgumentList $PhpPath,"$app\artisan" -ScriptBlock {param($php,$artisan);while($true){& $php $artisan schedule:run;Start-Sleep -Seconds 60}}
    $ready=$false
    for($i=0;$i -lt 40;$i++){try{$null=Invoke-WebRequest -UseBasicParsing "$env:APP_URL/api/bootstrap-status" -TimeoutSec 2;$ready=$true;break}catch{Start-Sleep -Seconds 1}}
    if(!$ready){throw 'Frontend/API nicht bereit. Siehe .development\vite.err.log und api.err.log.'}
    Status "Administration: $env:APP_URL/administration/login"
    Status "Restaurant: $env:APP_URL/restaurant/login"
    Status 'Quellcode in dieser IDE bearbeiten; React/CSS aktualisiert sich automatisch. Kein automatischer Git-Push und keine Produktionsbereitstellung.'
    if(!$SmokeTest){Write-Host "Lokaler Einrichtungsschluessel: $($state.setupToken)";if(!$NoBrowser){Start-Process "$env:APP_URL/administration/login"}}
    if($SmokeTest){
        $adminPage=Invoke-WebRequest -UseBasicParsing "$env:APP_URL/administration/login"
        $restaurantPage=Invoke-WebRequest -UseBasicParsing "$env:APP_URL/restaurant/login"
        if($adminPage.Content -notmatch '@vite/client' -or $restaurantPage.Content -notmatch '@vite/client'){throw 'Portale verwenden keinen Vite-Entwicklungsserver.'}
        $null=Invoke-WebRequest -UseBasicParsing "$env:APP_URL/src/main.tsx"
        Status 'Entwicklungs-Smoke-Test erfolgreich: beide Portale, Quellcode und API';exit 0
    }
    Write-Host 'Zum geordneten Beenden Q druecken. Testdaten bleiben erhalten.'
    while($true){
        foreach($process in $children){if($process.HasExited){throw 'Ein Entwicklungsprozess wurde beendet. Protokolle unter .development pruefen.'}}
        foreach($job in $jobs){Receive-Job $job;if($job.State -ne 'Running'){throw 'Hintergrundaufgabe wurde beendet.'}}
        if([Console]::KeyAvailable -and [Console]::ReadKey($true).Key -eq 'Q'){break}
        Start-Sleep -Milliseconds 500
    }
} catch {Write-Host "Entwicklung angehalten: $($_.Exception.Message)" -ForegroundColor Red;exit 1}
finally {
    foreach($job in $jobs){Stop-Job $job;Remove-Job $job -Force}
    if($mysqlStarted){& "$MySqlBin\mysqladmin.exe" "--defaults-file=$dev\admin.cnf" shutdown *> $null}
    foreach($process in $children){if(!$process.HasExited){Stop-Process -Id $process.Id -ErrorAction SilentlyContinue}}
    if($lock){$lock.Dispose()}
}
