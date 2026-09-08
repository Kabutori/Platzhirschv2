#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$PackagePath)
Set-StrictMode -Version Latest
$ErrorActionPreference='Stop'
if($env:GITHUB_ACTIONS -ne 'true' -or $env:RUNNER_ENVIRONMENT -ne 'github-hosted') {
    throw 'Dieser Integrationstest ist ausschliesslich fuer wegwerfbare GitHub-hosted Testmaschinen bestimmt.'
}
$target='C:\ph-ci'
if(Test-Path $target){throw 'Testziel existiert bereits.'}
$installer=Join-Path $PackagePath 'installer\Install-Platzhirsch.ps1'
$previousPolicy=$env:PSExecutionPolicyPreference
try {
    $env:PSExecutionPolicyPreference='Restricted'
    & cmd.exe /d /c "`"$PackagePath\Install.bat`" -InstallPath $target -NoBrowser -Unattended"
    $installExit=$LASTEXITCODE
} finally {
    $env:PSExecutionPolicyPreference=$previousPolicy
}
if(Test-Path "$target\installation.json") {
    $state=Get-Content "$target\installation.json" -Raw|ConvertFrom-Json
    foreach($key in @('rootPassword','appPassword','provisionPassword','setupToken','appKey')) {Write-Output "::add-mask::$($state.$key)"}
}
if($installExit -ne 0){
    # Only this fresh, disposable CI installation is inspected, before any guest data exists.
    try {Invoke-WebRequest -UseBasicParsing 'http://127.0.0.1:8378/up' -TimeoutSec 5|Out-Null}
    catch {
        if($_.Exception.Response){
            Write-Host "IIS health HTTP status: $([int]$_.Exception.Response.StatusCode)"
            $reader=New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())
            try {$html=$reader.ReadToEnd()} finally {$reader.Dispose()}
            [regex]::Matches($html,'HTTP Error [0-9.]+|0x[0-9a-fA-F]{8}')|ForEach-Object {Write-Host $_.Value}
            foreach($label in @('Config Error','Module','Notification','Handler')) {
                $match=[regex]::Match($html,"(?is)<th>\s*$label\s*</th>\s*<td>(.*?)</td>")
                if($match.Success){Write-Host "$label : $([Net.WebUtility]::HtmlDecode(($match.Groups[1].Value -replace '<[^>]+>','')))"}
            }
        }
    }
    foreach($path in @("$target\logs\php.log","$target\app\storage\logs\*.log",'C:\inetpub\logs\LogFiles\W3SVC*\*.log')) {
        Get-ChildItem $path -ErrorAction SilentlyContinue|ForEach-Object {Write-Host "Diagnostic log: $($_.Name)";Get-Content $_.FullName -Tail 30}
    }
    throw "Erstinstallation fehlgeschlagen, Exitcode $installExit"
}
$state=Get-Content "$target\installation.json" -Raw|ConvertFrom-Json
foreach($key in @('rootPassword','appPassword','provisionPassword','setupToken','appKey')) {Write-Output "::add-mask::$($state.$key)"}
$password=[Guid]::NewGuid().ToString('N')+'-Aa7!'
Write-Output "::add-mask::$password"
$base='http://127.0.0.1:8378'
$session=New-Object Microsoft.PowerShell.Commands.WebRequestSession
$headers=@{Accept='application/json'}
function Call-Api([string]$Method,[string]$Path,$Body=$null,[int]$Expected=200) {
    # WebRequestSession retains custom headers between requests; the current
    # request dictionary alone must determine which portal is selected.
    $null=$session.Headers.Remove('X-Platzhirsch-Portal')
    $options=@{Uri="$base/api/$Path";Method=$Method;WebSession=$session;Headers=$headers;UseBasicParsing=$true;TimeoutSec=30}
    if($null -ne $Body){$options.ContentType='application/json';$options.Body=$Body|ConvertTo-Json -Depth 10 -Compress}
    try {$response=Invoke-WebRequest @options}
    catch {
        if($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq $Expected){return $null}
        $actual=if($_.Exception.Response){[int]$_.Exception.Response.StatusCode}else{0}
        throw "API-Test fehlgeschlagen: $Method $Path, erwartet $Expected, HTTP $actual"
    }
    if([int]$response.StatusCode -ne $Expected){throw "API-Status fuer $Method $Path ist $($response.StatusCode), erwartet $Expected"}
    if($response.Content){return $response.Content|ConvertFrom-Json}
}
$csrf=Call-Api GET 'csrf'
$headers['X-CSRF-TOKEN']=$csrf.token
$headers['X-Setup-Token']=$state.setupToken
$null=Call-Api POST 'bootstrap/first-admin' @{name='CI Admin';email='admin@example.test';password=$password;password_confirmation=$password} 201
$null=Call-Api GET 'v1/admin/auth/me' $null 401
$null=Call-Api POST 'bootstrap/first-admin' @{name='CI Admin';email='admin@example.test';password=$password;password_confirmation=$password} 409
$headers.Remove('X-Setup-Token')
$null=Call-Api POST 'v1/admin/auth/login' @{email='admin@example.test';password=$password}
$csrf=Call-Api GET 'csrf'
$headers['X-CSRF-TOKEN']=$csrf.token
# Exercise the installed module and actual PDO/MySQL path, without logging credentials.
$modules=Call-Api GET 'v1/admin/modules'
if(-not($modules|Where-Object {$_.code -eq 'provisioning'})){throw 'Provisioning-Modul fehlt.'}
$dbServer=Call-Api POST 'v1/admin/database-servers' @{name='CI local database';host='127.0.0.1';port=3308;region='CI';purpose='test';database='platzhirsch_platform';username='ph_app';password=$state.appPassword;tls_required=$false}
if($dbServer.PSObject.Properties.Name -contains 'password'){throw 'Serverantwort enthaelt ein Passwortfeld.'}
$probe=Call-Api POST "v1/admin/database-servers/$($dbServer.id)/test" @{check='connection'}
if(-not $probe.ok -or $probe.code -ne 'connected'){throw 'Reale Datenbank-Verbindungspruefung fehlgeschlagen.'}
$probe=Call-Api POST "v1/admin/database-servers/$($dbServer.id)/test" @{check='permissions'}
if($probe.ok -or $probe.code -ne 'permission_denied'){throw 'Eingeschraenkte Datenbankrechte wurden nicht korrekt erkannt.'}
foreach($portalPath in @('/administration/login','/restaurant/login')) {
    $portalPage=Invoke-WebRequest -Uri "$base$portalPath" -UseBasicParsing
    if($portalPage.StatusCode -ne 200 -or $portalPage.Content -notmatch 'id="root"'){throw 'Portal-Seite nicht erreichbar.'}
}
$demo=Call-Api POST 'v1/admin/test-restaurant' @{name='CI Demo';owner_name='Demo Owner';email='demo-owner@example.test';password=$password;password_confirmation=$password} 202
$demoId=$demo.tenant.id
$demoReady=$false
for($attempt=0;$attempt -lt 60;$attempt++) {
    $demoList=Call-Api GET 'v1/admin/tenants'
    $demoRow=$demoList.data|Where-Object {$_.id -eq $demoId}
    if($demoRow.status -eq 'active'){$demoReady=$true;break}
    if($demoRow.status -eq 'failed'){throw 'Demo-Provisionierung fehlgeschlagen.'}
    Start-Sleep -Seconds 2
}
if(-not $demoReady){throw 'Demo-Provisionierung nicht abgeschlossen.'}
$headers['X-Platzhirsch-Portal']='restaurant'
$null=Call-Api POST 'v1/admin/auth/login' @{email='demo-owner@example.test';password=$password}
$csrf=Call-Api GET 'csrf';$headers['X-CSRF-TOKEN']=$csrf.token
$demoTables=Call-Api GET 'v1/restaurant/tables'
if(@($demoTables).Count -ne 3){throw 'Demo-Tische fehlen.'}
$demoHours=Call-Api GET 'v1/restaurant/hours'
if(@($demoHours).Count -ne 7){throw 'Demo-Oeffnungszeiten fehlen.'}
$null=Call-Api GET 'v1/admin/tenants' $null 403
$null=Call-Api POST 'v1/admin/auth/logout' $null 204
$headers.Remove('X-Platzhirsch-Portal')
$csrf=Call-Api GET 'csrf';$headers['X-CSRF-TOKEN']=$csrf.token
$tenant=Call-Api POST 'v1/admin/tenants' @{name='CI Restaurant';email='restaurant@example.test';timezone='Europe/Berlin'} 202
$active=$false
for($i=0;$i -lt 90;$i++) {
    $list=Call-Api GET 'v1/admin/tenants'
    $current=@($list.data|Where-Object {$_.id -eq $tenant.id})[0]
    if($current.status -eq 'active'){$active=$true;break}
    if($current.status -eq 'failed'){
        & "$target\runtime\php\php.exe" "$PSScriptRoot\Inspect-CiJobs.php" $target
        throw 'Mandanten-Provisionierung fehlgeschlagen.'
    }
    Start-Sleep -Seconds 2
}
if(-not $active){throw 'Provisionierungsworker wurde nicht rechtzeitig fertig.'}
$headers['X-Tenant-ID']=[string]$tenant.id
$room=Call-Api POST 'v1/restaurant/rooms' @{name='Saal';color='sage';outdoor=$false}
$table=Call-Api POST 'v1/restaurant/tables' @{name='Tisch 1';room_id=$room.id;capacity=4;active=$true}
foreach($day in 1..7){$null=Call-Api POST 'v1/restaurant/hours' @{weekday=$day;opens='12:00';closes='23:00'}}
$date=(Get-Date).AddDays(2).ToString('yyyy-MM-dd')
$booking=@{table_id=$table.id;guest_name='CI Guest';party_size=2;starts_at="${date}T18:00";duration_minutes=90;request_key=[Guid]::NewGuid().ToString()}
$reservation=Call-Api POST 'v1/restaurant/reservations' $booking 201
$booking.request_key=[Guid]::NewGuid().ToString()
$null=Call-Api POST 'v1/restaurant/reservations' $booking 409
$null=Call-Api POST "v1/restaurant/reservations/$($reservation.id)/cancel" @{} 204
$null=Call-Api POST 'v1/restaurant/reservations' $booking 201
$widget=Call-Api POST 'v1/restaurant/widget' @{origins=@('https://restaurant.example');duration_minutes=90;months=1} 201
# Stateless requests avoid session locking: both IIS requests may execute concurrently.
Add-Type -AssemblyName System.Net.Http
$client=New-Object System.Net.Http.HttpClient
$client.Timeout=[TimeSpan]::FromSeconds(30)
$client.DefaultRequestHeaders.Add('Origin','https://restaurant.example')
$client.DefaultRequestHeaders.Add('Accept','application/json')
$requests=@();$responses=@()
try {
    foreach($n in 1..2) {
        $body=@{table_id=$table.id;guest_name="Concurrent guest $n";email="guest$n@example.test";party_size=2;starts_at="${date}T20:00";consent=$true;request_key=[Guid]::NewGuid().ToString()}|ConvertTo-Json -Compress
        $request=New-Object System.Net.Http.HttpRequestMessage([System.Net.Http.HttpMethod]::Post,"$base/api/widget/$($widget.token)")
        $request.Content=New-Object System.Net.Http.StringContent($body,[Text.Encoding]::UTF8,'application/json')
        $requests+=,$request
    }
    $pending=@($requests|ForEach-Object {$client.SendAsync($_)})
    $statuses=@()
    foreach($task in $pending) {
        $response=$task.GetAwaiter().GetResult();$responses+=,$response
        $statuses+=[int]$response.StatusCode
        if(($response.Headers.GetValues('Access-Control-Allow-Origin') -join '') -ne 'https://restaurant.example'){throw 'Widget-CORS-Header fehlt bei MySQL-Buchung.'}
    }
    if(($statuses|Sort-Object) -join ',' -ne '200,409'){throw "Gleichzeitige Buchung ist nicht eindeutig: $($statuses -join ',')"}
} finally {
    foreach($response in $responses){$response.Dispose()}
    foreach($request in $requests){$request.Dispose()}
    $client.Dispose()
}
$health=Call-Api GET 'v1/admin/health'
if($health.failed_jobs -ne 0){throw 'Fehlgeschlagene Queue-Jobs vorhanden.'}
if(-not $health.scheduler_last_seen){throw 'Scheduler hat keinen Heartbeat geschrieben.'}
if((Get-Service PlatzhirschMySQL).Status -ne 'Running'){throw 'MySQL-Dienst nicht aktiv.'}
foreach($task in @('Platzhirsch-default','Platzhirsch-provisioning')) {
    if((Get-ScheduledTask $task).State -ne 'Running'){throw "Worker nicht aktiv: $task"}
}
& powershell.exe -NoLogo -NoProfile -File $installer -InstallPath $target -NoBrowser -Unattended
if($LASTEXITCODE -ne 0){throw 'Wiederholte Installation fehlgeschlagen.'}
$again=Get-Content "$target\installation.json" -Raw|ConvertFrom-Json
foreach($key in @('rootPassword','appPassword','provisionPassword','setupToken','appKey')) {
    if($state.$key -ne $again.$key){throw 'Wiederholte Installation hat einen Schluessel geaendert.'}
}
$rows=Call-Api GET "v1/restaurant/reservations?date=$date"
if(@($rows).Count -ne 3){throw 'Reservierungen nach Wiederholung nicht erhalten.'}
Write-Host 'Windows-Integration bestanden: Installation, Bootstrap, Login, MySQL-Provisionierung, Buchung, Konflikt, Storno, gleichzeitige Widget-Buchungen, Worker, Scheduler und Wiederholung.'

# Offline snapshot restores application, all schemas, MySQL accounts and keys together.
$snapshotScript=Join-Path $PackagePath 'installer\Snapshot-Platzhirsch.ps1'
$backup=@(& $snapshotScript -Mode Backup -InstallPath $target -Destination 'C:\ph-backups' -Confirm:$false)[-1]
if(-not(Test-Path "$backup\snapshot.json")){throw 'Vollstaendige Sicherung fehlt.'}
$afterBackup=$booking.Clone();$afterBackup.starts_at="${date}T14:00";$afterBackup.request_key=[Guid]::NewGuid().ToString()
$null=Call-Api POST 'v1/restaurant/reservations' $afterBackup 201
# A corrupt file must be rejected before any services or live data are changed.
$marker="$backup\files\installation.json"
$original=[IO.File]::ReadAllBytes($marker)
[IO.File]::AppendAllText($marker,'corrupt')
$rejected=$false
try {& $snapshotScript -Mode Restore -InstallPath $target -Destination $backup -Confirm:$false|Out-Null}catch{$rejected=$true}
finally{[IO.File]::WriteAllBytes($marker,$original)}
if(-not $rejected){throw 'Beschaedigte Sicherung wurde akzeptiert.'}
$null=Call-Api GET 'bootstrap-status'
$rows=Call-Api GET "v1/restaurant/reservations?date=$date"
if(@($rows).Count -ne 4){throw 'Abgewiesene Wiederherstellung hat Daten veraendert.'}
$null=& $snapshotScript -Mode Restore -InstallPath $target -Destination $backup -Confirm:$false
$rows=Call-Api GET "v1/restaurant/reservations?date=$date"
if(@($rows).Count -ne 3){throw 'Wiederherstellung hat den Buchungsstand nicht zurueckgesetzt.'}
$restored=Get-Content "$target\installation.json" -Raw|ConvertFrom-Json
foreach($key in @('rootPassword','appPassword','provisionPassword','setupToken','appKey')){if($state.$key -ne $restored.$key){throw 'Schluessel nach Wiederherstellung veraendert.'}}
$health=Call-Api GET 'v1/admin/health'
if($health.failed_jobs -ne 0){throw 'Queue nach Wiederherstellung fehlerhaft.'}
Write-Host 'Snapshot-Test bestanden: Sicherung, Beschaedigungspruefung, Wiederherstellung, IIS/MySQL-Start, Mandantenzugriff und Schluesselerhalt.'

# Verify module activation and tenant movement against an independent MySQL instance.
$secondRoot='C:\ph-ci-second'
if(Test-Path $secondRoot){throw 'Zweites Testziel existiert bereits.'}
New-Item -ItemType Directory $secondRoot|Out-Null
$secondPassword=[Guid]::NewGuid().ToString('N')+'Aa7!'
Write-Output "::add-mask::$secondPassword"
$secondIni="$secondRoot\my.ini"
$secondInit="$secondRoot\init.sql"
$secondText="[mysqld]`nbasedir=C:/ph-ci/runtime/mysql`ndatadir=C:/ph-ci-second/data`nport=3309`nbind-address=127.0.0.1`nmysqlx=0`nlog-error=C:/ph-ci-second/mysql.log`n"
[IO.File]::WriteAllText($secondIni,$secondText,(New-Object Text.UTF8Encoding($false)))
& "$target\runtime\mysql\bin\mysqld.exe" "--defaults-file=$secondIni" --initialize-insecure
if($LASTEXITCODE -ne 0){throw 'Zweite MySQL-Instanz konnte nicht initialisiert werden.'}
[IO.File]::WriteAllText($secondInit,"ALTER USER 'root'@'localhost' IDENTIFIED BY '$secondPassword';",(New-Object Text.UTF8Encoding($false)))
[IO.File]::WriteAllText($secondIni,($secondText+"init-file=C:/ph-ci-second/init.sql`n"),(New-Object Text.UTF8Encoding($false)))
$secondProcess=Start-Process "$target\runtime\mysql\bin\mysqld.exe" -ArgumentList "--defaults-file=$secondIni" -PassThru
try {
    $ready=$false
    for($attempt=0;$attempt -lt 60;$attempt++){
        $tcp=New-Object Net.Sockets.TcpClient
        try {$tcp.Connect('127.0.0.1',3309);$ready=$true;break}catch{Start-Sleep -Seconds 1}finally{$tcp.Dispose()}
    }
    if(-not $ready){throw 'Zweite MySQL-Instanz nicht erreichbar.'}
    $credentials=@{username='root';password=$secondPassword;account_host='127.0.0.1'}|ConvertTo-Json -Compress
    [IO.File]::WriteAllText("$target\ci-second-credentials.json",$credentials,(New-Object Text.UTF8Encoding($false)))
    $env:PH_CI_PASSWORD=$password
    & "$target\runtime\php\php.exe" "$PSScriptRoot\Test-CiModulePlacement.php" $target
    if($LASTEXITCODE -ne 0){throw 'Modul-/Serverumzugstest fehlgeschlagen.'}
    $snapshotRejected=$false
    try {& $snapshotScript -Mode Backup -InstallPath $target -Destination 'C:\ph-backups-after-placement' -Confirm:$false|Out-Null}catch{$snapshotRejected=$true}
    if(-not $snapshotRejected){throw 'Lokaler Snapshot hat externe Datenbanken nicht erkannt.'}
} finally {
    Remove-Item Env:\PH_CI_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item "$target\ci-second-credentials.json",$secondInit -ErrorAction SilentlyContinue
    Stop-Process -Id $secondProcess.Id -Force -ErrorAction SilentlyContinue
}
