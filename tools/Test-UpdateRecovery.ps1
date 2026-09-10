# Invoked while both disposable MySQL instances from Test-WindowsInstallation are alive.
param([string]$InstallPath,[string]$PackagePath)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$php="$InstallPath\runtime\php\php.exe"
$recovery="$PackagePath\installer\Recover-Platzhirsch.ps1"
$updater="$PackagePath\installer\Update-Platzhirsch.ps1"
function Check-Data([string]$Mode){& $php "$PSScriptRoot\Test-OperationsData.php" $InstallPath $Mode;if($LASTEXITCODE -ne 0){throw "Operations fixture failed: $Mode"}}
Check-Data seed
$backup='C:\ph-coordinated-backup'
& $recovery -Mode Backup -InstallPath $InstallPath -Destination $backup -Confirm:$false
& $recovery -Mode Verify -InstallPath $InstallPath -Destination $backup
Check-Data change
$corruptFile=(Get-ChildItem "$backup\databases" -Filter '*.jsonl'|Select-Object -First 1).FullName
$bytes=[IO.File]::ReadAllBytes($corruptFile)
try {
    [IO.File]::AppendAllText($corruptFile,'corrupt')
    $rejected=$false
    try {& $recovery -Mode Restore -InstallPath $InstallPath -Destination $backup -Confirm:$false}catch{$rejected=$true}
    if(-not $rejected -or (Test-Path "$InstallPath\maintenance.json")){throw 'Corrupt recovery archive did not fail before downtime.'}
} finally {[IO.File]::WriteAllBytes($corruptFile,$bytes)}
& $recovery -Mode Restore -InstallPath $InstallPath -Destination $backup -Confirm:$false
Check-Data check
# Build an isolated fixture release with real platform and tenant schema changes.
$fixture='C:\ph-update-fixture'
& robocopy.exe $PackagePath $fixture /E /COPY:DAT /R:1 /W:1 /NFL /NDL /NJH /NJS /NP|Out-Null
if($LASTEXITCODE -ge 8){throw 'Cannot copy update fixture.'}
$manifest=Get-Content "$fixture\release-manifest.json" -Raw|ConvertFrom-Json
$originalVersion=(Get-Content "$InstallPath\installation.json" -Raw|ConvertFrom-Json).version
$manifest.version='0.1.0-ci-update'
$migration=@'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
 public function up(): void { \Illuminate\Support\Facades\Schema::connection($this->getConnection() ?: config('database.default'))->create('ops_update_fixture', fn($t) => $t->integer('id')); }
 public function down(): void { \Illuminate\Support\Facades\Schema::dropIfExists('ops_update_fixture'); }
};
'@
# Tenant migration must explicitly use the tenant connection, as real reservation migrations do.
$paths=@('payload/app/database/migrations/2099_01_01_000001_ops_update.php','payload/app/vendor/platzhirsch/reservation/src/migrations/2099_01_01_000001_ops_update.php')
foreach($path in $paths){
    $content=if($path -match '/vendor/'){$migration.Replace("config('database.default')","'tenant'")}else{$migration}
    [IO.File]::WriteAllText((Join-Path $fixture $path),$content,(New-Object Text.UTF8Encoding($false)))
    $manifest.files+=,[pscustomobject]@{path=$path;sha256=(Get-FileHash (Join-Path $fixture $path)).Hash}
}
[IO.File]::WriteAllText("$fixture\release-manifest.json",($manifest|ConvertTo-Json -Depth 10),(New-Object Text.UTF8Encoding($false)))
$archiveRoot='C:\ph-update-backups'
& $updater -InstallPath $InstallPath -PackagePath $fixture -BackupRoot $archiveRoot -Confirm:$false
if((Get-Content "$InstallPath\installation.json" -Raw|ConvertFrom-Json).version -ne '0.1.0-ci-update'){throw 'Version not committed.'}
Check-Data updated
$rollback=(Get-ChildItem $archiveRoot -Directory -Filter 'before-update-*'|Select-Object -First 1).FullName
& $updater -Mode Rollback -InstallPath $InstallPath -BackupPath $rollback -BackupRoot $archiveRoot -Confirm:$false
if((Get-Content "$InstallPath\installation.json" -Raw|ConvertFrom-Json).version -ne $originalVersion){throw 'Version rollback failed.'}
Check-Data rolled-back
Check-Data check
# Failure after a real DDL change must restore both schemas and old files automatically.
$failure='payload/app/database/migrations/2099_01_01_000002_ops_fail.php'
[IO.File]::WriteAllText((Join-Path $fixture $failure),'<?php return new class extends \Illuminate\Database\Migrations\Migration {public function up():void {throw new \RuntimeException("ci_expected_failure");}};',(New-Object Text.UTF8Encoding($false)))
$manifest.files+=,[pscustomobject]@{path=$failure;sha256=(Get-FileHash (Join-Path $fixture $failure)).Hash}
[IO.File]::WriteAllText("$fixture\release-manifest.json",($manifest|ConvertTo-Json -Depth 10),(New-Object Text.UTF8Encoding($false)))
$failed=$false
try {& $updater -InstallPath $InstallPath -PackagePath $fixture -BackupRoot $archiveRoot -Confirm:$false}catch{$failed=$true}
if(-not $failed -or (Test-Path "$InstallPath\maintenance.json")){throw 'Automatic rollback failed.'}
Check-Data rolled-back
Check-Data check
& "$PackagePath\installer\Test-OperationsReadiness.ps1" -InstallPath $InstallPath -Requests 100 -Concurrency 8
# Test-only backup artifact: contains synthetic data and disposable CI credentials, never production data.
Add-Type -AssemblyName System.IO.Compression.FileSystem
[IO.Compression.ZipFile]::CreateFromDirectory($backup,'C:\ph-recovery-fixture.zip')
Write-Host 'Operations passed: two-server consistent backup, 10000 binary rows, tamper rejection, restore, update, tenant migrations, manual and automatic database rollback, HTTP concurrency.'
