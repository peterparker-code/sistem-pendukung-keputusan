@echo off
setlocal EnableExtensions
cd /d "%~dp0"

title Setup Database SPK PT CISAS
where php >nul 2>&1
if errorlevel 1 (
    echo [ERROR] PHP tidak ditemukan pada PATH Windows.
    pause
    exit /b 1
)

php setup.php
if errorlevel 1 (
    echo.
    echo [ERROR] Setup database gagal.
    pause
    exit /b 1
)

echo.
echo Database siap digunakan.
pause
