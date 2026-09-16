#requires -Version 5.1
param([string]$InstallPath,[ValidatePattern('^windows-preview-[0-9]+-[0-9]+$')][string]$Tag)
$ErrorActionPreference='Stop'
$repo='https://api.github.com/repos/Kabutori/Platzhirschv2'
$headers=@{'User-Agent'='Platzhirsch-Module-Updater';'Accept'='application/vnd.github+json'}
$parts=$Tag.Split('-');$number=$parts[2];$attempt=$parts[3]
$release=Invoke-RestMethod -Uri "$repo/releases/tags/$Tag" -Headers $headers -TimeoutSec 30
if($release.draft -or -not $release.prerelease){throw 'Gepruefter Vorschau-Release erforderlich.'}
$runs=Invoke-RestMethod -Uri "$repo/actions/workflows/windows-package.yml/runs?event=workflow_dispatch&per_page=100" -Headers $headers -TimeoutSec 30
$run=@($runs.workflow_runs|Where-Object {$_.run_number -eq [int]$number -and $_.run_attempt -eq [int]$attempt -and $_.conclusion -eq 'success' -and $_.head_branch -eq 'main' -and $_.display_title -match '^Module composition [a-f0-9-]{36}$'})
if($run.Count -ne 1){throw 'Erfolgreicher Modul-Prueflauf nicht gefunden.'}
$requestId=$run[0].display_title.Substring(19)
$asset=@($release.assets|Where-Object {$_.name -match '^Platzhirsch-0\.1\.0-modules\.[a-f0-9-]{36}-windows-x64\.zip$'})
$checks=@($release.assets|Where-Object {$_.name -eq 'SHA256SUMS.txt'})
if($asset.Count -ne 1 -or $checks.Count -ne 1 -or $asset[0].size -gt 600MB){throw 'Release-Dateien fehlen oder sind ungueltig.'}
$base='https://github.com/Kabutori/Platzhirschv2/releases/download/'+$Tag+'/'
if($asset[0].browser_download_url -ne ($base+$asset[0].name) -or $checks[0].browser_download_url -ne ($base+'SHA256SUMS.txt')){throw 'Ungueltige Downloadquelle.'}
$dir="$InstallPath\operations-ui";$destination="$dir\packages\$Tag"
if(Test-Path $destination){throw 'Paket wurde bereits bereitgestellt.'}
$temp="$dir\private\stage-$([Guid]::NewGuid().ToString('N'))"
New-Item -ItemType Directory $temp|Out-Null
try {
 Invoke-WebRequest -UseBasicParsing -Uri $checks[0].browser_download_url -OutFile "$temp\SHA256SUMS.txt" -TimeoutSec 60
 $expected=$null
 foreach($line in Get-Content "$temp\SHA256SUMS.txt") {if($line -match '^([a-fA-F0-9]{64})\s+\*?(?:\./)?(.+)$' -and $Matches[2] -eq $asset[0].name){$expected=$Matches[1]}}
 if(-not $expected){throw 'Pruefsumme fehlt.'}
 Invoke-WebRequest -UseBasicParsing -Uri $asset[0].browser_download_url -OutFile "$temp\package.zip" -TimeoutSec 600
 if((Get-FileHash "$temp\package.zip" -Algorithm SHA256).Hash -ne $expected){throw 'Download-Pruefsumme stimmt nicht.'}
 Add-Type -AssemblyName System.IO.Compression.FileSystem
 $archive=[IO.Compression.ZipFile]::OpenRead("$temp\package.zip")
 try {
  $total=0L;$names=@{}
  foreach($entry in $archive.Entries){$name=$entry.FullName;$total+=$entry.Length
   if($name -match '(^[/\\]|(^|[/\\])\.\.([/\\]|$)|:)' -or $total -gt 3GB -or $names.ContainsKey($name)){throw 'Ungueltiger Archivinhalt.'}
   $names[$name]=$true
  }
 }finally{$archive.Dispose()}
 [IO.Compression.ZipFile]::ExtractToDirectory("$temp\package.zip","$temp\extracted")
 $composition=Get-Content "$temp\extracted\module-composition.json" -Raw|ConvertFrom-Json
 if($composition.request_id -ne $requestId){throw 'Paket gehoert nicht zum freigegebenen Auftrag.'}
 $manifest=Get-Content "$temp\extracted\release-manifest.json" -Raw|ConvertFrom-Json
 $seen=@{};$root=[IO.Path]::GetFullPath("$temp\extracted")
 foreach($file in $manifest.files){$full=[IO.Path]::GetFullPath((Join-Path $root $file.path));if(-not $full.StartsWith($root+'\',[StringComparison]::OrdinalIgnoreCase) -or $seen.ContainsKey($full) -or -not(Test-Path -LiteralPath $full -PathType Leaf) -or (Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash -ne $file.sha256){throw 'Paketmanifest ist ungueltig.'};$seen[$full]=$true}
 if(@(Get-ChildItem $root -Recurse -Force -File|Where-Object {$_.FullName -ne "$root\release-manifest.json"}).Count -ne $seen.Count){throw 'Nicht gelistete Paketdateien.'}
 Move-Item "$temp\extracted" $destination
 Write-Host 'Geprueftes Update bereitgestellt. Installation separat in der Oberflaeche freigeben.'
}finally{Remove-Item $temp -Recurse -Force -ErrorAction SilentlyContinue}
