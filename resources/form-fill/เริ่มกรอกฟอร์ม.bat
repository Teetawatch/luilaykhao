@echo off
rem ============================================================
rem  Double-click: fill the Google Form from data.json in this folder.
rem  First run installs Bun + playwright-core (internet needed once).
rem  ASCII only on purpose - Thai text lives in autofill.js.
rem ============================================================
setlocal
chcp 65001 >nul
title Luilaykhao - autofill
cd /d "%~dp0"

if exist "%~dp0autofill.js" goto have_script
echo [X] autofill.js is missing. Unzip the whole ZIP first, then run this file again.
pause
exit /b 1

:have_script
set "TOOLS=%LOCALAPPDATA%\luilaykhao-autofill"
set "BUN=%USERPROFILE%\.bun\bin\bun.exe"
where bun >nul 2>nul && set "BUN=bun"
if "%BUN%"=="bun" goto have_bun
if exist "%BUN%" goto have_bun

echo Installing Bun - first time only, please wait...
powershell -NoProfile -ExecutionPolicy Bypass -Command "irm https://bun.sh/install.ps1 | iex"
if exist "%BUN%" goto have_bun
echo.
echo [X] Could not install Bun. Check the internet connection and try again.
pause
exit /b 1

:have_bun
if exist "%TOOLS%\node_modules\playwright-core\package.json" goto have_pw
echo Installing playwright-core - first time only, please wait...
if not exist "%TOOLS%" mkdir "%TOOLS%"
pushd "%TOOLS%"
if not exist package.json (echo {"private": true}) > package.json
"%BUN%" add playwright-core
popd
if exist "%TOOLS%\node_modules\playwright-core\package.json" goto have_pw
echo.
echo [X] Could not install playwright-core. Check the internet connection and try again.
pause
exit /b 1

:have_pw
rem Run the newest script from this folder, with the shared playwright-core
copy /y "%~dp0autofill.js" "%TOOLS%\autofill.js" >nul
"%BUN%" "%TOOLS%\autofill.js"
if errorlevel 1 pause
exit /b 0
