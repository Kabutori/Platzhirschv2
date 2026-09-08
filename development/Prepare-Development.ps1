#requires -Version 5.1
[CmdletBinding()]
param(
    [string]$DevelopmentPath=(Join-Path $env:USERPROFILE 'source\Platzhirschv2'),
    [string]$RuntimePath='C:\Platzhirsch\runtime',
    [string]$PhpPath='',
    [string]$MySqlBin='',
    [ValidateRange(1024,65535)][int]$WebPort=5173,
    [ValidateRange(1024,65535)][int]$ApiPort=8000,
    [ValidateRange(1024,65535)][int]$DatabasePort=33018,
    [switch]$LocalComposer,
    [switch]$SmokeTest,
    [switch]$NoBrowser,
    [switch]$PrepareOnly,
    [switch]$SkipToolInstall
)
$ErrorActionPreference='Stop';Set-StrictMode -Version Latest
$source=Split-Path $PSScriptRoot -Parent
$repository='https://github.com/Kabutori/Platzhirschv2.git'
function Say([string]$text){Write-Host "[DEV-Einrichtung] $text"}
function Refresh-ToolPath {
    $paths=@($env:PATH,[Environment]::GetEnvironmentVariable('Path','Machine'),[Environment]::GetEnvironmentVariable('Path','User'),"$env:ProgramFiles\Git\cmd","$env:ProgramFiles\nodejs","$env:LOCALAPPDATA\Programs\Git\cmd")
    $env:PATH=($paths|Where-Object {$_}) -join ';'
}
function Ensure-Tool([string]$command,[string]$package){
    if(Get-Command $command -ErrorAction SilentlyContinue){return}
    if($SkipToolInstall -or -not(Get-Command winget -ErrorAction SilentlyContinue)){throw "$command fehlt. WinGet ist nicht verfuegbar oder deaktiviert. Bitte $package installieren und dieselbe BAT erneut starten."}
    Say "$command fehlt: Installation von $package ueber WinGet. Windows kann eine Bestaetigung anzeigen."
    & winget install --id $package --exact --source winget --accept-source-agreements
    if($LASTEXITCODE -ne 0){throw "Installation von $package nicht abgeschlossen (Exitcode $LASTEXITCODE)."}
    Refresh-ToolPath
    if(-not(Get-Command $command -ErrorAction SilentlyContinue)){throw "$command noch nicht verfuegbar. Terminal schliessen und BAT erneut starten."}
}
function Git([string[]]$arguments){& git.exe @arguments;if($LASTEXITCODE -ne 0){throw 'Git-Vorgang fehlgeschlagen. Vorhandene Dateien bleiben erhalten; Ausgabe oben pruefen.'}}
try {
    Refresh-ToolPath
    Ensure-Tool 'git.exe' 'Git.Git'
    Ensure-Tool 'node.exe' 'OpenJS.NodeJS.LTS'
    Ensure-Tool 'npm.cmd' 'OpenJS.NodeJS.LTS'
    $nodeVersion=& node.exe --version
    if([int]($nodeVersion.TrimStart('v').Split('.')[0]) -lt 22){throw 'Node.js 22 oder neuer erforderlich. Node.js aktualisieren und dieselbe BAT erneut starten.'}
    # Starting inside an existing working copy never changes its branch or files.
    if((Test-Path "$source\.git") -and (Test-Path "$source\app\composer.json")){
        $checkout=$source
        Say "Vorhandenes Quellcode-Checkout verwenden: $checkout"
    } else {
        $checkout=[IO.Path]::GetFullPath($DevelopmentPath)
        if($checkout -match '["\r\n]'){throw 'Ungueltiger Entwicklungsordner.'}
        if(Test-Path $checkout){
            if(-not(Test-Path "$checkout\.git")){throw "Zielordner existiert, ist aber kein Git-Checkout: $checkout. Mit -DevelopmentPath einen freien Ordner waehlen."}
            $origin=& git.exe -C $checkout remote get-url origin
            if($LASTEXITCODE -ne 0 -or $origin.Trim().TrimEnd('/') -notin @($repository,$repository.Substring(0,$repository.Length-4),'git@github.com:Kabutori/Platzhirschv2.git')){throw 'Im Zielordner liegt ein anderes Repository. Es wird nicht geaendert.'}
            Say "Vorhandene Entwicklung verwenden: $checkout (kein Pull, Reset oder Branchwechsel)."
        } else {
            $parent=Split-Path $checkout -Parent
            New-Item -ItemType Directory -Path $parent -Force|Out-Null
            Say "Quellcode von GitHub nach $checkout laden. Bei privatem Repository bitte bei Git anmelden."
            Git @('clone','--branch','codex/windows-application','--',$repository,$checkout)
            $branch='dev/local-'+(Get-Date -Format 'yyyyMMdd-HHmmss')
            Git @('-C',$checkout,'switch','-c',$branch)
            Say "Eigener Entwicklungsbranch angelegt: $branch"
        }
    }
    $runner=Join-Path $checkout 'development\Start-Development.ps1'
    if(-not(Test-Path $runner)){throw 'Der ausgewaehlte Quellcode enthaelt den Entwicklungsstarter noch nicht. Keine automatische Aenderung vorhandener Dateien.'}
    Say "IDE-Ordner: $checkout"
    if($PrepareOnly){Say 'Quellcode vorbereitet. Keine Datenbank und kein Webserver gestartet.';exit 0}
    Say 'Entwicklungsumgebung starten. Die Produktionsinstallation wird nicht veraendert.'
    & $runner -RuntimePath $RuntimePath -PhpPath $PhpPath -MySqlBin $MySqlBin -WebPort $WebPort -ApiPort $ApiPort -DatabasePort $DatabasePort -SmokeTest:$SmokeTest -NoBrowser:$NoBrowser -LocalComposer:$LocalComposer
    exit $LASTEXITCODE
} catch {Write-Host "DEV-Einrichtung angehalten: $($_.Exception.Message)" -ForegroundColor Red;exit 1}
