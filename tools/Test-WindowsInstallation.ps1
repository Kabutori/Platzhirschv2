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
& powershell.exe -NoLogo -NoProfile -File $installer -InstallPath $target -NoBrowser -Unattended
$installExit=$LASTEXITCODE
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
    $options=@{Uri="$base/api/$Path";Method=$Method;WebSession=$session;Headers=$headers;UseBasicParsing=$true;TimeoutSec=30}
    if($null -ne $Body){$options.ContentType='application/json';$options.Body=$Body|ConvertTo-Json -Depth 10 -Compress}
    try {$response=Invoke-WebRequest @options}
    catch {
        if($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -eq $Expected){return $null}
        throw "API-Test fehlgeschlagen: $Method $Path, erwartet $Expected"
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
