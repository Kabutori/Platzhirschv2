@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0development\Start-Development.ps1" %*
set "PH_EXIT=%ERRORLEVEL%"
if not "%PH_EXIT%"=="0" echo Entwicklungsumgebung angehalten. Exitcode: %PH_EXIT%
if not "%GITHUB_ACTIONS%"=="true" pause
exit /b %PH_EXIT%
