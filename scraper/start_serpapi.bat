@echo off
REM ============================================================
REM  start_serpapi.bat
REM  Launches the SerpAPI-powered SHEIN product lookup server.
REM  Replace YOUR_SERPAPI_KEY_HERE with your actual key,
REM  OR better: put SERPAPI_KEY=... in C:\xampp\htdocs\yaman\.env
REM ============================================================

cd /d "%~dp0"

REM -- Set your SerpAPI key here if not using .env --
REM SET SERPAPI_KEY=YOUR_SERPAPI_KEY_HERE

REM -- Verify Node is available --
where node >nul 2>&1
IF ERRORLEVEL 1 (
    echo [ERROR] Node.js not found. Install from https://nodejs.org
    pause
    exit /b 1
)

echo [*] Starting SerpAPI SHEIN lookup service on port 3579...
node serpapi_server.js

IF ERRORLEVEL 1 (
    echo [ERROR] Server failed to start.
    pause
)
