@echo off
setlocal
cd /d "%~dp0"
echo Platzhirsch - Sicherung und Wiederherstellung
echo Bitte als Administrator starten. Details: UPDATES-UND-WIEDERHERSTELLUNG.md
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0installer\Recover-Platzhirsch.ps1" %*
set "PH_EXIT=%ERRORLEVEL%"
if not "%PH_EXIT%"=="0" echo Vorgang angehalten. Details stehen oben. Exitcode: %PH_EXIT%
if not "%GITHUB_ACTIONS%"=="true" pause
exit /b %PH_EXIT%
