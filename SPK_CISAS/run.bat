@echo off
setlocal EnableExtensions
cd /d "%~dp0"

title SPK PT CISAS
color 0A
set "HOST=127.0.0.1"
set "PORT=8000"
set "URL=http://localhost:%PORT%"
set "APP_ENV=production"
set "APP_DEBUG=false"
set "APP_URL=%URL%"

cls
echo ============================================================
echo                     SPK PT CISAS
echo ============================================================
echo.

where php >nul 2>&1
if errorlevel 1 (
    echo [ERROR] PHP tidak ditemukan pada PATH Windows.
    pause
    exit /b 1
)

php -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);"
if errorlevel 1 (
    echo [ERROR] Ekstensi pdo_sqlite belum aktif.
    pause
    exit /b 1
)

if not exist "database\spk_cisas.sqlite" (
    echo Database belum ada. Menjalankan setup...
    php setup.php
    if errorlevel 1 (
        pause
        exit /b 1
    )
)

php tests\system_audit.php
if errorlevel 1 (
    echo.
    echo [ERROR] Database belum siap digunakan.
    pause
    exit /b 1
)

echo.
echo Sistem berjalan di %URL%
echo Tekan Ctrl+C untuk menghentikan server.
echo.
start "" powershell -NoProfile -WindowStyle Hidden -Command "Start-Sleep -Seconds 2; Start-Process '%URL%'"
php -S %HOST%:%PORT% -t public
pause
