@echo off
setlocal EnableExtensions
cd /d "%~dp0"

title Backup Database SPK PT CISAS

if not exist "database\spk_cisas.sqlite" (
    echo [ERROR] Database database\spk_cisas.sqlite tidak ditemukan.
    pause
    exit /b 1
)

if not exist "backups" mkdir "backups"

for /f %%I in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd_HHmmss"') do set "STAMP=%%I"
set "TARGET=backups\spk_cisas_%STAMP%.sqlite"

copy /Y "database\spk_cisas.sqlite" "%TARGET%" >nul
if errorlevel 1 (
    echo [ERROR] Backup database gagal dibuat.
    pause
    exit /b 1
)

echo [OK] Backup berhasil dibuat:
echo %TARGET%
echo.
echo Disarankan membuat backup saat aplikasi tidak sedang melakukan proses simpan data.
pause
endlocal
exit /b 0
