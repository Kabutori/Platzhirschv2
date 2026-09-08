$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$failed=$false
Get-ChildItem "$root\installer","$root\tools","$root\development" -Filter '*.ps1' -Recurse|ForEach-Object {
    $tokens=$null;$errors=$null
    [System.Management.Automation.Language.Parser]::ParseFile($_.FullName,[ref]$tokens,[ref]$errors)|Out-Null
    if($errors.Count){$failed=$true;$errors|ForEach-Object{Write-Host "$($_.Extent.File):$($_.Extent.StartLineNumber) $($_.Message)" -ForegroundColor Red}}
    else{Write-Host "Syntax OK: $($_.Name)"}
}
if($failed){exit 1}
