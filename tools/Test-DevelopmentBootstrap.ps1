#requires -Version 5.1
param([string]$PackagePath='C:\ph-package')
$ErrorActionPreference='Stop'
$checkout='C:\ph-dev-bootstrap'
$foreign='C:\ph-dev-foreign'
if((Test-Path $checkout) -or (Test-Path $foreign)){throw 'Bootstrap test requires unused test paths.'}
$bat=Join-Path $PackagePath 'Start-Development.bat'
& $bat -DevelopmentPath $checkout -PrepareOnly -SkipToolInstall
if($LASTEXITCODE -ne 0){throw 'Release bootstrap could not clone the development checkout.'}
$branch=& git.exe -C $checkout branch --show-current
if($branch -notlike 'dev/local-*'){throw 'Development branch was not created.'}
$head=& git.exe -C $checkout rev-parse HEAD
$sentinel=Join-Path $checkout 'user-work.txt'
[IO.File]::WriteAllText($sentinel,'keep local work')
& $bat -DevelopmentPath $checkout -PrepareOnly -SkipToolInstall
if($LASTEXITCODE -ne 0){throw 'Bootstrap could not reuse the working copy.'}
if((& git.exe -C $checkout rev-parse HEAD) -ne $head -or (& git.exe -C $checkout branch --show-current) -ne $branch){throw 'Bootstrap changed the existing branch or commit.'}
if((Get-Content $sentinel -Raw) -ne 'keep local work'){throw 'Local work was modified.'}
New-Item -ItemType Directory -Path $foreign|Out-Null
[IO.File]::WriteAllText((Join-Path $foreign 'keep.txt'),'keep foreign files')
& $bat -DevelopmentPath $foreign -PrepareOnly -SkipToolInstall
if($LASTEXITCODE -eq 0){throw 'Existing non-repository folder was accepted.'}
if((Get-Content (Join-Path $foreign 'keep.txt') -Raw) -ne 'keep foreign files'){throw 'Existing folder was modified.'}
Write-Host 'Development bootstrap passed: release BAT clones, creates branch, preserves existing work and refuses foreign folders.'
# The rejected foreign directory intentionally left a native exit code of 1.
exit 0
