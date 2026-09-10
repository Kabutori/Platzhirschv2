#requires -Version 5.1
[CmdletBinding()]
param([string]$DevelopmentPath=(Join-Path $env:USERPROFILE 'source\Platzhirschv2'))
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$repository='https://github.com/Kabutori/Platzhirschv2.git'
$base='codex/windows-application'
$source=Split-Path $PSScriptRoot -Parent
function Git([string[]]$Arguments){
    $output=& git.exe @Arguments
    if($LASTEXITCODE -ne 0){throw 'Git-Vorgang fehlgeschlagen. Ausgabe oben pruefen; es erfolgt kein erzwungener Push.'}
    return $output
}
try {
    if(-not(Get-Command git.exe -ErrorAction SilentlyContinue)){
        $gitCommand=Join-Path $env:ProgramFiles 'Git\cmd'
        if(Test-Path "$gitCommand\git.exe"){$env:PATH="$gitCommand;$env:PATH"}
        else{throw 'Git fehlt. Zuerst Start-Development.bat ausfuehren.'}
    }
    $checkout=[IO.Path]::GetFullPath($DevelopmentPath)
    if(Test-Path "$source\.git"){$checkout=$source}
    if(-not(Test-Path "$checkout\.git")){throw 'Kein Entwicklungs-Checkout gefunden. Zuerst Start-Development.bat starten; fuer andere Ordner -DevelopmentPath angeben.'}
    $origin=Git -Arguments @('-C',$checkout,'remote','get-url','origin')
    if($origin.Trim().TrimEnd('/') -notin @($repository,'https://github.com/Kabutori/Platzhirschv2','git@github.com:Kabutori/Platzhirschv2.git')){throw 'Der Zielordner gehoert nicht zum erwarteten Platzhirsch-Repository.'}
    $branch=Git -Arguments @('-C',$checkout,'branch','--show-current')
    if([string]::IsNullOrWhiteSpace($branch) -or $branch -in @('main','master',$base)){throw 'Bitte auf einem eigenen Entwicklungsbranch arbeiten. Der Paketbranch wird hier nicht direkt gepusht.'}
    $changes=Git -Arguments @('-C',$checkout,'status','--porcelain')
    if($changes){throw 'Es gibt noch nicht committete Dateien. Bitte in der IDE die gewuenschten Quelldateien pruefen und committen, dann diese BAT erneut starten. Es werden keine Dateien automatisch hinzugefuegt.'}
    Write-Host "Entwicklungsordner: $checkout"
    Write-Host "Dein Branch: $branch -> Ziel: $base"
    Write-Host 'Vergleichsstand von GitHub laden...'
    Git -Arguments @('-C',$checkout,'fetch','origin',"${base}:refs/remotes/origin/$base")
    $ahead=Git -Arguments @('-C',$checkout,'rev-list','--count',"origin/$base..HEAD")
    if([int]$ahead -eq 0){Write-Host 'Keine neuen Commits fuer den Paketbranch vorhanden.';exit 0}
    Write-Host "$ahead Commit(s) zur Uebernahme. Bitte pruefen:"
    Git -Arguments @('-C',$checkout,'log','--oneline',"origin/$base..HEAD")
    $answer=Read-Host 'Diesen Entwicklungsbranch jetzt pushen und GitHub oeffnen? [J/N]'
    if($answer -notmatch '^(j|ja|y|yes)$'){Write-Host 'Abgebrochen. Nichts gepusht.';exit 0}
    Git -Arguments @('-C',$checkout,'push','--set-upstream','origin','HEAD')
    $url='https://github.com/Kabutori/Platzhirschv2/compare/'+[Uri]::EscapeDataString($base)+'...'+[Uri]::EscapeDataString($branch)+'?expand=1'
    Write-Host "GitHub-Vergleich: $url"
    Write-Host 'Im Browser Create pull request waehlen. Falls bereits ein PR existiert, diesen oeffnen; der Push aktualisiert ihn automatisch.'
    Write-Host 'Nach erfolgreichen Pruefungen den PR auf GitHub zusammenfuehren. Das Skript fuehrt keinen Merge aus.'
    try{Start-Process $url}catch{Write-Host 'Browser konnte nicht gestartet werden. Den Link oben manuell oeffnen.'}
    exit 0
} catch {Write-Host "Pull Request angehalten: $($_.Exception.Message)" -ForegroundColor Red;exit 1}
