#requires -Version 7.0
[CmdletBinding()]
param([string]$Version='0.1.0')
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$root=Split-Path $PSScriptRoot -Parent
if($Version -notmatch '^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$'){throw 'Ungueltige Version.'}
if(-not(Test-Path "$root\app\vendor\autoload.php") -or -not(Test-Path "$root\app\public\admin\index.html")){throw 'Zuerst Composer-Abhaengigkeiten installieren und Frontend bauen.'}
$out=Join-Path $root "dist\Platzhirsch-$Version-windows-x64"
if(Test-Path $out){throw 'Ausgabeverzeichnis existiert bereits. Fuer einen neuen Build ein frisches Checkout verwenden.'}
New-Item -ItemType Directory -Path "$out\packages","$out\payload\app","$out\installer" -Force|Out-Null
Copy-Item "$root\Install.bat" $out
Copy-Item "$root\Start-Development.bat" $out
Copy-Item "$root\Create-PullRequest.bat" $out
New-Item -ItemType Directory -Path "$out\development" -Force|Out-Null
Copy-Item "$root\development\Prepare-Development.ps1" "$out\development"
Copy-Item "$root\development\Create-PullRequest.ps1" "$out\development"
Copy-Item "$root\docs\DEVELOPMENT.md" "$out\DEVELOPMENT.md"
Copy-Item "$root\installer\*.ps1" "$out\installer"
Copy-Item "$root\docs\BETRIEB.md" "$out\BETRIEB.md"
Copy-Item "$root\docs\UMSETZUNGSSTAND.md" "$out\UMSETZUNGSSTAND.md"
Copy-Item "$root\docs\MODULE-UND-SERVER.md" "$out\MODULE-UND-SERVER.md"
Copy-Item "$root\docs\SYSTEM-GUIDE.md" "$out\SYSTEM-GUIDE.md"
# Package runtime files explicitly: no test databases, logs, developer environment
# or cached configuration from the build machine may enter an installation.
foreach($entry in @('app','bootstrap','config','database','public','resources','routes','vendor','artisan','composer.json','composer.lock')) {
    Copy-Item "$root\app\$entry" "$out\payload\app" -Recurse
}
Get-ChildItem "$out\payload\app\bootstrap\cache" -File -Filter '*.php' | Remove-Item
Get-ChildItem "$out\payload\app\public\landing" -File -Filter 'preview-*.html' -ErrorAction SilentlyContinue | Remove-Item
foreach($directory in @('storage\logs','storage\framework\sessions','storage\framework\views','storage\framework\cache','storage\app\private')) {
    New-Item -ItemType Directory -Path "$out\payload\app\$directory" -Force | Out-Null
}
Copy-Item "$root\app\.env.example" "$out\payload\app\.env.example"
if(Test-Path "$out\payload\app\.env"){throw 'Release darf keine .env enthalten.'}
$sources=@(
    @{name='php.zip';url='https://downloads.php.net/~windows/releases/archives/php-8.5.10-nts-Win32-vs17-x64.zip';sha='22ec430195984d233eb9e62c637a945bbcda06efca2f392d9d96d62c6acd34f8'},
    @{name='mysql.msi';url='https://cdn.mysql.com/Downloads/MySQL-8.4/mysql-8.4.11-winx64.msi';publisher='Oracle'},
    @{name='rewrite_amd64_en-US.msi';url='https://download.microsoft.com/download/1/2/8/128E2E22-C1B9-44A4-BE2A-5859ED1D4592/rewrite_amd64_en-US.msi';publisher='Microsoft'},
    @{name='vc_redist.x64.exe';url='https://aka.ms/vs/17/release/vc_redist.x64.exe';publisher='Microsoft'}
)
foreach($item in $sources){
    $path=Join-Path "$out\packages" $item.name
    Write-Host "Download und Herstellerpruefung: $($item.name)"
    Invoke-WebRequest -Uri $item.url -OutFile $path -TimeoutSec 600 -MaximumRetryCount 2 -RetryIntervalSec 5
    if($item.ContainsKey('sha')){if((Get-FileHash $path -Algorithm SHA256).Hash -ne $item.sha){throw "SHA256 stimmt nicht: $($item.name)"}}
    else{$signature=Get-AuthenticodeSignature $path;if($signature.Status -ne 'Valid' -or $signature.SignerCertificate.Subject -notmatch $item.publisher){throw "Herstellersignatur stimmt nicht: $($item.name)"}}
}
$files=@(Get-ChildItem $out -Recurse -File|ForEach-Object{ @{path=[IO.Path]::GetRelativePath($out,$_.FullName).Replace('\','/');sha256=(Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()} })
@{version=$Version;sourceCommit=(& git -C $root rev-parse HEAD);createdAt=[DateTime]::UtcNow.ToString('o');files=$files;sources=$sources}|ConvertTo-Json -Depth 6|Set-Content "$out\release-manifest.json" -Encoding utf8NoBOM
# ZipFile includes dotfiles such as .env.example, unlike Compress-Archive.
[IO.Compression.ZipFile]::CreateFromDirectory($out,"$root\dist\Platzhirsch-$Version-windows-x64.zip",[IO.Compression.CompressionLevel]::Optimal,$false)
Get-FileHash "$root\dist\Platzhirsch-$Version-windows-x64.zip" -Algorithm SHA256|Format-List
Write-Host 'Installationspaket erstellt. Dies ist noch keine Produktionsfreigabe.'
