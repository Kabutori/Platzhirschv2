#requires -Version 5.1
$ErrorActionPreference='Stop'
$root=Join-Path $env:TEMP ('ph-stage-'+[Guid]::NewGuid().ToString('N'))
$utf8=New-Object Text.UTF8Encoding($false)
$request='12345678-1234-1234-1234-123456789abc'
$tag='windows-preview-123-1';$filename="Platzhirsch-0.1.0-modules.$request-windows-x64.zip"
$script:tamper=$false;$script:wrongRequest=$false
function global:Invoke-RestMethod {
 param($Uri,$Headers,$TimeoutSec)
 if($Uri -like '*/releases/tags/*'){return @{draft=$false;prerelease=$true;assets=@(@{name=$filename;size=1000;browser_download_url="https://github.com/Kabutori/Platzhirschv2/releases/download/$tag/$filename"},@{name='SHA256SUMS.txt';browser_download_url="https://github.com/Kabutori/Platzhirschv2/releases/download/$tag/SHA256SUMS.txt"})}}
 return @{workflow_runs=@(@{run_number=123;run_attempt=1;conclusion='success';head_branch='main';display_title="Module composition $request"})}
}
function global:Invoke-WebRequest {
 param([switch]$UseBasicParsing,$Uri,$OutFile,$TimeoutSec)
 if($Uri.EndsWith('SHA256SUMS.txt')){$hash=if($script:tamper){'0'*64}else{(Get-FileHash "$root\fixture.zip").Hash};[IO.File]::WriteAllText($OutFile,"$hash  ./$filename",$utf8)}else{Copy-Item "$root\fixture.zip" $OutFile}
}
try {
 New-Item -ItemType Directory "$root\fixture","$root\operations-ui\private","$root\operations-ui\packages" -Force|Out-Null
 [IO.File]::WriteAllText("$root\fixture\module-composition.json",(@{request_id=$request}|ConvertTo-Json),$utf8)
 $hash=(Get-FileHash "$root\fixture\module-composition.json").Hash
 [IO.File]::WriteAllText("$root\fixture\release-manifest.json",(@{files=@(@{path='module-composition.json';sha256=$hash})}|ConvertTo-Json -Depth 5),$utf8)
 Add-Type -AssemblyName System.IO.Compression.FileSystem
 [IO.Compression.ZipFile]::CreateFromDirectory("$root\fixture","$root\fixture.zip")
 & "$PSScriptRoot\..\installer\Stage-ModuleUpdate.ps1" -InstallPath $root -Tag $tag
 if(-not(Test-Path "$root\operations-ui\packages\$tag\module-composition.json")){throw 'Staged package missing'}
 Remove-Item "$root\operations-ui\packages\$tag" -Recurse -Force
 $script:tamper=$true;$rejected=$false
 try {& "$PSScriptRoot\..\installer\Stage-ModuleUpdate.ps1" -InstallPath $root -Tag $tag}catch{$rejected=$true}
 if(-not $rejected -or (Test-Path "$root\operations-ui\packages\$tag")){throw 'Tampered download accepted'}
 Write-Host 'Module staging passed: approved run, matching request, immutable destination and tamper rejection.'
}finally{
 Remove-Item function:\Invoke-RestMethod,function:\Invoke-WebRequest -ErrorAction SilentlyContinue
 Remove-Item $root -Recurse -Force -ErrorAction SilentlyContinue
}
