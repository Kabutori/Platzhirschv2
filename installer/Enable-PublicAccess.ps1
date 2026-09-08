#requires -Version 5.1
#requires -RunAsAdministrator
[CmdletBinding(SupportsShouldProcess,ConfirmImpact='High')]
param([string]$InstallPath='C:\Platzhirsch',[Parameter(Mandatory)][ValidatePattern('^[a-zA-Z0-9.-]+$')][string]$HostName,[Parameter(Mandatory)][ValidatePattern('^[a-fA-F0-9]{40}$')][string]$CertificateThumbprint)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$state=Get-Content (Join-Path $InstallPath 'installation.json') -Raw|ConvertFrom-Json
if($state.product -ne 'Platzhirsch' -or -not $state.completed){throw 'Keine abgeschlossene Installation gefunden.'}
$status=Invoke-RestMethod "http://127.0.0.1:$($state.port)/api/bootstrap-status"
if(-not $status.bootstrapped){throw 'Zuerst den ersten Administrator lokal einrichten.'}
$cert=Get-Item "Cert:\LocalMachine\My\$CertificateThumbprint"
if(-not $cert.HasPrivateKey -or $cert.NotAfter -le (Get-Date)){throw 'Zertifikat fehlt, ist abgelaufen oder besitzt keinen privaten Schluessel.'}
$chain=New-Object Security.Cryptography.X509Certificates.X509Chain
try{if(-not $chain.Build($cert)){throw 'Zertifikatskette ist nicht vertrauenswuerdig.'}}finally{$chain.Dispose()}
$matchesName=$false
foreach($name in $cert.DnsNameList){$dns=$name.Unicode;if($dns -eq $HostName){$matchesName=$true};if($dns.StartsWith('*.') -and $HostName.EndsWith($dns.Substring(1)) -and $HostName.Split('.').Count -eq $dns.Split('.').Count){$matchesName=$true}}
if(-not $matchesName){throw 'Zertifikat passt nicht zum Hostnamen.'}
if(-not $PSCmdlet.ShouldProcess("https://$HostName",'Platzhirsch ueber Port 443 freigeben und Sitzungen auf HTTPS beschraenken')){return}
Import-Module WebAdministration
$php=Join-Path $InstallPath 'runtime\php\php.exe';$app=Join-Path $InstallPath 'app';$envFile=Join-Path $app '.env'
$text=[IO.File]::ReadAllText($envFile)
$text=[regex]::Replace($text,'(?m)^APP_URL=.*$',"APP_URL=https://$HostName")
$text=[regex]::Replace($text,'(?m)^SESSION_SECURE_COOKIE=.*$','SESSION_SECURE_COOKIE=true')
[IO.File]::WriteAllText($envFile,$text,(New-Object Text.UTF8Encoding($false)))
& $php "$app\artisan" config:cache;if($LASTEXITCODE -ne 0){throw 'Konfiguration konnte nicht aktualisiert werden.'}
if(-not(Get-WebBinding -Name Platzhirsch -Protocol https -HostHeader $HostName -ErrorAction SilentlyContinue)){New-WebBinding -Name Platzhirsch -Protocol https -Port 443 -IPAddress '*' -HostHeader $HostName -SslFlags 1}
(Get-WebBinding -Name Platzhirsch -Protocol https -HostHeader $HostName).AddSslCertificate($CertificateThumbprint,'My')
if(-not(Get-NetFirewallRule -Name Platzhirsch-HTTPS -ErrorAction SilentlyContinue)){New-NetFirewallRule -Name Platzhirsch-HTTPS -DisplayName 'Platzhirsch HTTPS' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 443 -Profile Domain,Private|Out-Null}
Restart-WebAppPool Platzhirsch
Write-Host "HTTPS-Bindung eingerichtet: https://$HostName/admin/"
Write-Host 'DNS, externe Firewall, Zertifikatserneuerung und Mailversand gesondert pruefen. Kein MySQL-Port wurde freigegeben.'
