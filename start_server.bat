@echo off
title SkyKin Call Center Server
echo ============================================
echo   SkyKin Call Center - PHP Dev Server
echo   URL: http://localhost:8000
echo ============================================
echo.
echo Starting PHP server on port 8000...
echo Press Ctrl+C to stop.
echo.
C:\php\php.exe -S localhost:8000 -t "%~dp0"
pause
