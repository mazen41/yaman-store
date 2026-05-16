@echo off
:: ============================================================
:: SHEIN Playwright Scraper — Windows Startup Script
:: Run this once to install, then anytime to start the server.
:: ============================================================

setlocal enabledelayedexpansion
cd /d "%~dp0"

echo ============================================
echo  SHEIN Playwright Scraper — Setup ^& Start
echo ============================================

:: Check Node.js
where node >nul 2>&1
if errorlevel 1 (
    echo ERROR: Node.js is not installed or not in PATH.
    echo Download from: https://nodejs.org/
    pause
    exit /b 1
)

for /f "tokens=*" %%v in ('node -v') do set NODE_VER=%%v
echo Node.js version: %NODE_VER%

:: Install npm packages if node_modules is missing
if not exist "node_modules" (
    echo.
    echo Installing npm packages...
    call npm install
    if errorlevel 1 (
        echo ERROR: npm install failed.
        pause
        exit /b 1
    )
    echo.
    echo Installing Playwright Chromium browser...
    call npx playwright install chromium
    if errorlevel 1 (
        echo ERROR: Playwright browser install failed.
        pause
        exit /b 1
    )
)

echo.
echo Starting SHEIN scraper server...
echo Press Ctrl+C to stop.
echo.

node server.js

pause
