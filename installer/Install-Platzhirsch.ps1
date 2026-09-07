#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding()]
param(
    [ValidatePattern('^[A-Za-z]:\\[A-Za-z0-9_-]+(?:\\[A-Za-z0-9_-]+)*$')][string]$InstallPath = 'C:\Platzhirsch',
    [ValidateRange(1024,65535)][int]$Port = 8378,
    [ValidateRange(1024,65535)][int]$DatabasePort = 3308,
    [switch]$NoBrowser,
    [switch]$Unattended
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$source = Split-Path $PSScriptRoot -Parent
$utf8 = New-Object Text.UTF8Encoding($false)

function Write-Phase([string]$Text) { Write-Host "`n[Platzhirsch] $Text" -ForegroundColor Cyan }
function Write-Utf8([string]$Path,[string]$Text) { [IO.File]::WriteAllText($Path,$Text,$utf8) }
function Invoke-Checked([string]$File,[string[]]$Arguments,[int[]]$Allowed=@(0)) {
    & $File @Arguments
    if($LASTEXITCODE -notin $Allowed) { throw "Programm fehlgeschlagen: $File (Exitcode $LASTEXITCODE)" }
}
function New-Secret([int]$Bytes=32) { $value=New-Object byte[] $Bytes; $rng=[Security.Cryptography.RandomNumberGenerator]::Create(); try{$rng.GetBytes($value)}finally{$rng.Dispose()};return -join ($value|ForEach-Object{$_.ToString('x2')}) }
function Protect-Directory([string]$Path) {
    $acl=New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true,$false)
    foreach($sid in @('S-1-5-18','S-1-5-32-544')) {$rule=New-Object Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier($sid)),'FullControl','ContainerInherit,ObjectInherit','None','Allow');$acl.AddAccessRule($rule)}
    Set-Acl -LiteralPath $Path -AclObject $acl
}
function Add-Access([string]$Path,[string]$Identity,[string]$Rights) {
    $acl=Get-Acl -LiteralPath $Path
    $principal = if($Identity.StartsWith('*S-')) { New-Object Security.Principal.SecurityIdentifier($Identity.Substring(1)) } else { New-Object Security.Principal.NTAccount($Identity) }
    $rule=New-Object Security.AccessControl.FileSystemAccessRule($principal,$Rights,'ContainerInherit,ObjectInherit','None','Allow')
    $acl.AddAccessRule($rule);Set-Acl -LiteralPath $Path -AclObject $acl
}
function Protect-File([string]$Path) {
    $acl=New-Object Security.AccessControl.FileSecurity
    $acl.SetAccessRuleProtection($true,$false)
    foreach($sid in @('S-1-5-18','S-1-5-32-544')) {$rule=New-Object Security.AccessControl.FileSystemAccessRule((New-Object Security.Principal.SecurityIdentifier($sid)),'FullControl','Allow');$acl.AddAccessRule($rule)}
    Set-Acl -LiteralPath $Path -AclObject $acl
}
function Wait-Health([string]$Url,[int]$Attempts=40) {
    $lastStatus='keine HTTP-Antwort'
    for($i=0;$i -lt $Attempts;$i++){
        try{$response=Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec 5;$lastStatus="HTTP $($response.StatusCode)";if($response.StatusCode -eq 200){return}}
        catch{if($_.Exception.Response){$lastStatus="HTTP $([int]$_.Exception.Response.StatusCode)"}}
        Start-Sleep -Seconds 2
    }
    throw "Gesundheitspruefung fehlgeschlagen: $Url ($lastStatus). Siehe IIS-Protokoll und logs\php.log."
}
try {
    Write-Phase 'Release und Voraussetzungen pruefen'
    if(-not [Environment]::Is64BitProcess){throw 'Bitte 64-Bit Windows PowerShell verwenden.'}
    $os=Get-CimInstance Win32_OperatingSystem
    if([int]$os.BuildNumber -lt 20348){throw 'Windows Server 2022/2025 oder Windows 11 Pro/Enterprise erforderlich.'}
    if($Port -eq $DatabasePort){throw 'Web- und Datenbank-Port muessen verschieden sein.'}
    if($InstallPath -in @($env:windir,$env:ProgramFiles,$env:ProgramData,$env:USERPROFILE)){throw 'Eigenes Installationsverzeichnis erforderlich.'}
    if(-not (Test-Path "$source\release-manifest.json")){throw 'Dies ist ein Quellcode-Checkout, kein gebautes Windows-Release. Zuerst den Windows-Release-Workflow ausfuehren und dessen Installationspaket entpacken.'}
    $manifest=Get-Content "$source\release-manifest.json" -Raw | ConvertFrom-Json
    foreach($file in $manifest.files){
        $full=[IO.Path]::GetFullPath((Join-Path $source $file.path))
        if(-not $full.StartsWith([IO.Path]::GetFullPath($source)+[IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)){throw 'Ungueltiger Release-Pfad.'}
        if(-not (Test-Path -LiteralPath $full) -or (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash -ne $file.sha256){throw "Release-Datei beschaedigt: $($file.path)"}
    }
    $marker=Join-Path $InstallPath 'installation.json'
    if((Test-Path $InstallPath) -and -not (Test-Path $marker) -and (Get-ChildItem $InstallPath -Force | Measure-Object).Count -gt 0){throw 'Zielverzeichnis enthaelt fremde Dateien. Es wird nichts ueberschrieben.'}
    if(Test-Path $marker){
        $state=Get-Content $marker -Raw|ConvertFrom-Json
        if($state.product -ne 'Platzhirsch'){throw 'Fremde Installation erkannt.'}
        if($state.version -ne $manifest.version){throw 'Versionswechsel benoetigt einen freigegebenen Update-Ablauf. Keine automatische Datenbankmigration.'}
        if($state.port -ne $Port -or $state.databasePort -ne $DatabasePort){throw 'Bei Wiederaufnahme dieselben Ports verwenden.'}
        if($state.completed){Write-Host 'Diese Version ist bereits installiert. Daten und Schluessel bleiben unveraendert.';Wait-Health "http://127.0.0.1:$Port/up";if(-not $Unattended){Write-Host "Einrichtungsschluessel (falls noch nicht eingerichtet): $($state.setupToken)"};exit 0}
    } else {
        foreach($p in @($Port,$DatabasePort)){if(Get-NetTCPConnection -State Listen -LocalPort $p -ErrorAction SilentlyContinue){throw "Port $p ist bereits belegt."}}
        if(Get-Service | Where-Object { $_.Name -like '*mysql*' }) {throw 'Bestehende MySQL-Installation erkannt. Automatische Installation nur auf einer separaten Maschine; vorhandene Daten werden nicht veraendert.'}
        New-Item -ItemType Directory -Path $InstallPath -Force|Out-Null;Protect-Directory $InstallPath
        $state=[pscustomobject]@{product='Platzhirsch';version=$manifest.version;port=$Port;databasePort=$DatabasePort;completed=$false;rootPassword=(New-Secret);appPassword=(New-Secret);provisionPassword=(New-Secret);setupToken=(New-Secret);appKey=('base64:'+ [Convert]::ToBase64String([byte[]](1..32|ForEach-Object{[Convert]::ToByte((New-Secret 1),16)})))}
        Write-Utf8 $marker ($state|ConvertTo-Json)
    }
    $runtime=Join-Path $InstallPath 'runtime';$app=Join-Path $InstallPath 'app';$data=Join-Path $InstallPath 'mysql-data';$logs=Join-Path $InstallPath 'logs'
    foreach($dir in @($runtime,$app,$data,$logs,"$InstallPath\tasks")){New-Item -ItemType Directory -Path $dir -Force|Out-Null}
    Write-Phase 'Windows-Webserver aktivieren'
    if($os.ProductType -eq 1){
        $result=Enable-WindowsOptionalFeature -Online -FeatureName IIS-WebServerRole,IIS-WebServer,IIS-CommonHttpFeatures,IIS-StaticContent,IIS-DefaultDocument,IIS-HttpErrors,IIS-ApplicationDevelopment,IIS-CGI,IIS-ManagementConsole -All -NoRestart
        if($result.RestartNeeded){Write-Host 'Windows-Neustart erforderlich. Danach Install.bat erneut mit denselben Optionen starten.';exit 3010}
    }else{
        Import-Module ServerManager
        $result=Install-WindowsFeature Web-Server,Web-CGI,Web-Static-Content,Web-Default-Doc,Web-Http-Errors,Web-Mgmt-Console
        if(-not $result.Success){throw 'IIS-Rollen konnten nicht aktiviert werden.'}
        if($result.RestartNeeded -eq 'Yes'){Write-Host 'Windows-Neustart erforderlich. Danach Installation erneut starten.';exit 3010}
    }
    Write-Phase 'Laufzeitkomponenten installieren'
    foreach($name in @('vc_redist.x64.exe','rewrite_amd64_en-US.msi','mysql.msi')){
        $sig=Get-AuthenticodeSignature "$source\packages\$name"
        if($sig.Status -ne 'Valid'){throw "Herstellersignatur ungueltig: $name"}
        if($name -eq 'mysql.msi' -and $sig.SignerCertificate.Subject -notmatch 'Oracle'){throw 'MySQL-Herausgeber stimmt nicht ueberein.'}
        if($name -ne 'mysql.msi' -and $sig.SignerCertificate.Subject -notmatch 'Microsoft'){throw 'Microsoft-Herausgeber stimmt nicht ueberein.'}
    }
    $process=Start-Process "$source\packages\vc_redist.x64.exe" -ArgumentList '/install /quiet /norestart' -PassThru -Wait
    if($process.ExitCode -notin @(0,1638,3010)){throw "VC Runtime: $($process.ExitCode)"}
    $process=Start-Process msiexec.exe -ArgumentList "/i `"$source\packages\rewrite_amd64_en-US.msi`" /qn /norestart" -PassThru -Wait
    if($process.ExitCode -notin @(0,1638,3010)){throw "URL Rewrite: $($process.ExitCode)"}
    if(-not(Test-Path "$runtime\php\php.exe")){Expand-Archive "$source\packages\php.zip" "$runtime\php"}
    if(-not(Test-Path "$runtime\mysql\bin\mysqld.exe")){
        $process=Start-Process msiexec.exe -ArgumentList "/i `"$source\packages\mysql.msi`" /qn /norestart INSTALLDIR=`"$runtime\mysql`"" -PassThru -Wait
        if($process.ExitCode -notin @(0,3010)){throw "MySQL Installation: $($process.ExitCode)"}
    }
    $php="$runtime\php\php.exe";$mysqld="$runtime\mysql\bin\mysqld.exe"
    if(-not(Test-Path $mysqld)){throw 'MySQL-Binaerdateien fehlen nach MSI-Installation.'}
    $phpIni=@"
[PHP]
extension_dir="$runtime\php\ext"
extension=curl
extension=fileinfo
extension=intl
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=sodium
extension=zip
zend_extension=opcache
date.timezone=Europe/Berlin
display_errors=Off
log_errors=On
error_log="$logs\php.log"
expose_php=Off
cgi.force_redirect=0
fastcgi.impersonate=0
memory_limit=256M
max_execution_time=60
opcache.enable=1
opcache.validate_timestamps=0
"@
    Write-Utf8 "$runtime\php\php.ini" $phpIni
    # Windows PowerShell 5.1 strips embedded quotes in native arguments.
    # Execute a PHP file instead of passing PHP source through php -r.
    $checkFile=Join-Path $runtime 'check-extensions.php'
    Write-Utf8 $checkFile '<?php foreach(["pdo_mysql","mbstring","openssl","intl","fileinfo","curl"] as $x){if(!extension_loaded($x)){fwrite(STDERR,"Missing extension: ".$x);exit(1);}}'
    try { Invoke-Checked $php @($checkFile) } finally { Remove-Item -LiteralPath $checkFile -Force }
    Write-Phase 'Anwendung bereitstellen und Zugangsdaten schuetzen'
    Copy-Item "$source\payload\app\*" $app -Recurse -Force
    foreach($dir in @('bootstrap\cache','storage\logs','storage\framework\sessions','storage\framework\views','storage\framework\cache','storage\app\private')){New-Item -ItemType Directory -Path "$app\$dir" -Force|Out-Null}
    $sha=[Security.Cryptography.SHA256]::Create();try{$tokenHash=-join($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($state.setupToken))|ForEach-Object{$_.ToString('x2')})}finally{$sha.Dispose()}
    if(-not(Test-Path "$app\.env")){
        $envText=@"
APP_NAME=Platzhirsch
APP_ENV=production
APP_KEY=$($state.appKey)
APP_DEBUG=false
APP_URL=http://localhost:$Port
APP_TIMEZONE=Europe/Berlin
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=$DatabasePort
DB_DATABASE=platzhirsch_platform
DB_USERNAME=ph_app
DB_PASSWORD=$($state.appPassword)
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=false
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
BOOTSTRAP_TOKEN_HASH=$tokenHash
"@
        Write-Utf8 "$app\.env" $envText
    }
    Write-Utf8 "$app\storage\app\private\provision.json" (@{username='ph_provision';password=$state.provisionPassword}|ConvertTo-Json)
    Write-Phase 'Eigene MySQL-Instanz initialisieren'
    $ini=Join-Path $InstallPath 'my.ini';$initSql=Join-Path $InstallPath 'mysql-first-start.sql'
    $sql=@"
ALTER USER 'root'@'localhost' IDENTIFIED BY '$($state.rootPassword)';
CREATE DATABASE IF NOT EXISTS platzhirsch_platform CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ph_app'@'127.0.0.1' IDENTIFIED BY '$($state.appPassword)';
GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES ON platzhirsch_platform.* TO 'ph_app'@'127.0.0.1';
CREATE USER IF NOT EXISTS 'ph_provision'@'127.0.0.1' IDENTIFIED BY '$($state.provisionPassword)';
GRANT CREATE USER ON *.* TO 'ph_provision'@'127.0.0.1';
GRANT SELECT ON platzhirsch_platform.* TO 'ph_provision'@'127.0.0.1';
"@
    $sql += "`n" + 'GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES ON `ph\_t\_%`.* TO ''ph_provision''@''127.0.0.1'' WITH GRANT OPTION;'
    Write-Utf8 $initSql $sql
    $iniText="[mysqld]`nbasedir=$($runtime.Replace('\','/'))/mysql`ndatadir=$($data.Replace('\','/'))`nport=$DatabasePort`nbind-address=127.0.0.1`nmysqlx=0`ncharacter-set-server=utf8mb4`ncollation-server=utf8mb4_unicode_ci`nlog-error=$($logs.Replace('\','/'))/mysql.log`n"
    $service=Get-Service PlatzhirschMySQL -ErrorAction SilentlyContinue
    if(-not(Test-Path "$data\mysql")){Write-Utf8 $ini $iniText;Invoke-Checked $mysqld @("--defaults-file=$ini",'--initialize-insecure')}
    if(-not $service){
        Write-Utf8 $ini ($iniText+"init-file=$($initSql.Replace('\','/'))`n")
        Invoke-Checked $mysqld @('--install','PlatzhirschMySQL',"--defaults-file=$ini")
        Invoke-Checked sc.exe @('config','PlatzhirschMySQL','obj=','NT AUTHORITY\LocalService')
    }
    # MySQL and background workers run as LocalService, never as the IIS application identity.
    Add-Access $InstallPath '*S-1-5-19' 'ReadAndExecute'
    Protect-File $marker
    foreach($dir in @($data,$logs)){Add-Access $dir '*S-1-5-19' 'Modify'}
    Start-Service PlatzhirschMySQL
    $ready=$false
    for($i=0;$i -lt 40;$i++){try{Invoke-Checked $php @("$app\artisan",'platform:health');$ready=$true;break}catch{Start-Sleep -Seconds 2}}
    if(-not $ready){throw 'MySQL ist nicht betriebsbereit. Siehe logs\mysql.log.'}
    Write-Utf8 $ini $iniText
    Remove-Item -LiteralPath $initSql -Force
    Invoke-Checked $php @("$app\artisan",'migrate','--force')
    # Only the installation process needs platform DDL privileges. Revoke through the protected local admin connection.
    $mysql="$runtime\mysql\bin\mysql.exe";$clientIni=Join-Path $InstallPath 'mysql-admin.cnf'
    Write-Utf8 $clientIni "[client]`nuser=root`npassword=$($state.rootPassword)`nhost=127.0.0.1`nport=$DatabasePort`n"
    Protect-File $clientIni
    try {Invoke-Checked $mysql @("--defaults-extra-file=$clientIni",'-e',"REVOKE CREATE,ALTER,INDEX,DROP,REFERENCES ON platzhirsch_platform.* FROM 'ph_app'@'127.0.0.1';")} finally {Remove-Item -LiteralPath $clientIni -Force}
    Write-Phase 'IIS-Anwendung und Berechtigungen einrichten'
    Import-Module WebAdministration
    if(-not(Test-Path IIS:\AppPools\Platzhirsch)){New-WebAppPool Platzhirsch|Out-Null}
    Set-ItemProperty IIS:\AppPools\Platzhirsch -Name managedRuntimeVersion -Value ''
    Set-ItemProperty IIS:\AppPools\Platzhirsch -Name processModel.identityType -Value 4
    Set-ItemProperty IIS:\AppPools\Platzhirsch -Name processModel.idleTimeout -Value ([TimeSpan]::Zero)
    $identity='IIS AppPool\Platzhirsch'
    Add-Access $app $identity 'ReadAndExecute';Add-Access "$runtime\php" $identity 'ReadAndExecute'
    foreach($dir in @("$app\storage","$app\bootstrap\cache")){Add-Access $dir $identity 'Modify';Add-Access $dir '*S-1-5-19' 'Modify'}
    # Override inherited web access for provisioning credentials.
    Protect-Directory "$app\storage\app\private"
    Add-Access "$app\storage\app\private" '*S-1-5-19' 'ReadAndExecute'
    $fastCgi="$runtime\php\php-cgi.exe"
    $entry=Get-WebConfiguration "system.webServer/fastCgi/application[@fullPath='$fastCgi']" -PSPath 'MACHINE/WEBROOT/APPHOST'
    if(-not $entry){Add-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Filter 'system.webServer/fastCgi' -Name '.' -Value @{fullPath=$fastCgi;maxInstances=4;instanceMaxRequests=1000;activityTimeout=90;requestTimeout=90}}
    if(-not(Test-Path IIS:\Sites\Platzhirsch)){New-Website -Name Platzhirsch -Port $Port -IPAddress '127.0.0.1' -PhysicalPath "$app\public" -ApplicationPool Platzhirsch|Out-Null}
    # These sections are normally locked for web.config delegation. Keep that
    # protection and write administrator-owned, site-scoped ApplicationHost settings.
    $handler=Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Location Platzhirsch -Filter "system.webServer/handlers/add[@name='Platzhirsch-PHP']"
    if(-not $handler){Add-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location Platzhirsch -Filter 'system.webServer/handlers' -Name '.' -Value @{name='Platzhirsch-PHP';path='*.php';verb='*';modules='FastCgiModule';scriptProcessor=$fastCgi;resourceType='File'}}
    Set-WebConfigurationProperty -PSPath 'MACHINE/WEBROOT/APPHOST' -Location Platzhirsch -Filter 'system.webServer/security/authentication/anonymousAuthentication' -Name userName -Value ''
    Invoke-Checked $php @("$app\artisan",'config:cache')
    Write-Phase 'Hintergrundaufgaben einrichten'
    Copy-Item "$PSScriptRoot\Worker.ps1" "$InstallPath\tasks\Worker.ps1" -Force
    $principal=New-ScheduledTaskPrincipal -UserId 'S-1-5-19' -LogonType ServiceAccount
    $settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew
    foreach($queue in @('default','provisioning')){
        $action=New-ScheduledTaskAction -Execute "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe" -Argument "-NoLogo -NoProfile -File `"$InstallPath\tasks\Worker.ps1`" -InstallPath `"$InstallPath`" -Queue $queue"
        Register-ScheduledTask -TaskName "Platzhirsch-$queue" -Action $action -Trigger (New-ScheduledTaskTrigger -AtStartup) -Principal $principal -Settings $settings -Force|Out-Null
        Start-ScheduledTask -TaskName "Platzhirsch-$queue"
    }
    $action=New-ScheduledTaskAction -Execute $php -Argument "`"$app\artisan`" schedule:run" -WorkingDirectory $app
    $trigger=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName 'Platzhirsch-Scheduler' -Action $action -Trigger $trigger -Principal $principal -Settings (New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 2) -MultipleInstances IgnoreNew) -Force|Out-Null
    Start-ScheduledTask 'Platzhirsch-Scheduler'
    Start-Website Platzhirsch
    Restart-WebAppPool Platzhirsch
    Write-Phase 'Installation pruefen'
    Wait-Health "http://127.0.0.1:$Port/up"
    Wait-Health "http://127.0.0.1:$Port/api/bootstrap-status"
    Wait-Health "http://127.0.0.1:$Port/admin/"
    $state.completed=$true;Write-Utf8 $marker ($state|ConvertTo-Json)
    Write-Host "`nInstallation abgeschlossen. Nur lokal erreichbar: http://localhost:$Port/admin/" -ForegroundColor Green
    if(-not $Unattended){Write-Host "Einrichtungsschluessel: $($state.setupToken)" -ForegroundColor Yellow}
    Write-Host 'Ersten Administrator im Browser anlegen. Diesen Schluessel nicht weitergeben.'
    Write-Host 'Fuer Netzwerkbetrieb: gueltiges TLS-Zertifikat und Enable-PublicAccess.ps1 verwenden.'
    if(-not $NoBrowser -and -not $Unattended){Start-Process "http://localhost:$Port/admin/"}
    exit 0
} catch {
    Write-Host "`nInstallation angehalten: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host 'Vorhandene Daten bleiben erhalten. Nach Behebung mit denselben Optionen erneut starten.'
    exit 1
}
