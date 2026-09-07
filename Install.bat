@echo off
setlocal
cd /d "%~dp0"
echo Platzhirsch - Windows-Installation
echo Bitte dieses Fenster als Administrator starten.
powershell.exe -NoLogo -NoProfile -File "%~dp0installer\Install-Platzhirsch.ps1" %*
set "PH_EXIT=%ERRORLEVEL%"
if not "%PH_EXIT%"=="0" echo Installation angehalten. Details stehen oben. Exitcode: %PH_EXIT%
pause
exit /b %PH_EXIT%
