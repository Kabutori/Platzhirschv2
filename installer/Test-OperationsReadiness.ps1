#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding()]
param([string]$InstallPath='C:\Platzhirsch',[ValidateRange(1,10000)][int]$Requests=100,[ValidateRange(1,32)][int]$Concurrency=4,[string]$ExpectedPreviousBoot)
$ErrorActionPreference='Stop'
$state=Get-Content "$InstallPath\installation.json" -Raw|ConvertFrom-Json
$boot=(Get-CimInstance Win32_OperatingSystem).LastBootUpTime.ToUniversalTime().ToString('o')
if($ExpectedPreviousBoot -and $boot -eq $ExpectedPreviousBoot){throw 'Kein tatsaechlicher Windows-Neustart erkannt.'}
if(Test-Path "$InstallPath\maintenance.json"){throw 'Installation befindet sich im Wartungsmodus.'}
if((Get-Service PlatzhirschMySQL).Status -ne 'Running'){throw 'MySQL startet nicht.'}
foreach($task in @('Platzhirsch-default','Platzhirsch-provisioning')){if((Get-ScheduledTask $task).State -ne 'Running'){throw "Worker startet nicht: $task"}}
& "$InstallPath\runtime\php\php.exe" "$PSScriptRoot\Database-Recovery.php" $InstallPath health $InstallPath
if($LASTEXITCODE -ne 0){throw 'Datenbankpruefung fehlgeschlagen.'}
Add-Type -AssemblyName System.Net.Http
$client=New-Object Net.Http.HttpClient;$client.Timeout=[TimeSpan]::FromSeconds(15)
$timings=New-Object 'System.Collections.Generic.List[double]'
try {
    for($sent=0;$sent -lt $Requests;$sent+=$Concurrency){
        $pending=@();$clocks=@()
        for($i=0;$i -lt [Math]::Min($Concurrency,$Requests-$sent);$i++){$clocks+=,[Diagnostics.Stopwatch]::StartNew();$pending+=,$client.GetAsync("http://127.0.0.1:$($state.port)/api/bootstrap-status")}
        for($i=0;$i -lt $pending.Count;$i++){
            $response=$pending[$i].GetAwaiter().GetResult()
            try {if(-not $response.IsSuccessStatusCode){throw "HTTP-Belastungspruefung: $([int]$response.StatusCode)"};$body=$response.Content.ReadAsStringAsync().GetAwaiter().GetResult()|ConvertFrom-Json;if($null -eq $body.bootstrapped){throw 'Unerwartete API-Antwort.'};$timings.Add($clocks[$i].Elapsed.TotalMilliseconds)}finally{$response.Dispose()}
        }
    }
} finally {$client.Dispose()}
$sorted=@($timings|Sort-Object)
$result=@{at=[DateTime]::UtcNow.ToString('o');boot=$boot;actualRebootVerified=[bool]$ExpectedPreviousBoot;requests=$Requests;concurrency=$Concurrency;errors=0;p95Milliseconds=$sorted[[Math]::Min($sorted.Count-1,[Math]::Floor($sorted.Count*0.95))];maxMilliseconds=$sorted[-1]}
$result|ConvertTo-Json|Set-Content "$InstallPath\logs\operations-readiness.json" -Encoding UTF8
$result|ConvertTo-Json
