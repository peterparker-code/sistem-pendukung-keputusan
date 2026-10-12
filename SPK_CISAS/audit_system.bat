@echo off
setlocal EnableExtensions
cd /d "%~dp0"

title Audit Sistem SPK PT CISAS
color 0A

where php >nul 2>&1
if errorlevel 1 (
    echo [ERROR] PHP tidak ditemukan pada PATH Windows.
    pause
    exit /b 1
)

echo [1/4] Menguji algoritma AHP, TOPSIS, dan workflow...
php tests\calculation\run.php
if errorlevel 1 (
    set "AUDIT_STATUS=1"
    goto :audit_result
)

echo.
echo [2/4] Regression test data penelitian Agustus 2026...
php tests\research_august_2026.php
if errorlevel 1 (
    set "AUDIT_STATUS=1"
    goto :audit_result
)

echo.
echo [3/4] Mengaudit kebijakan role dan pemisahan tugas...
php tests\access_control_policy.php
if errorlevel 1 (
    set "AUDIT_STATUS=1"
    goto :audit_result
)

echo.
echo [4/4] Mengaudit database dan konsistensi data...
php tests\system_audit.php
set "AUDIT_STATUS=%ERRORLEVEL%"

:audit_result
echo.
if "%AUDIT_STATUS%"=="0" (
    echo Audit sistem selesai tanpa masalah utama.
) else (
    echo Audit menemukan masalah yang perlu diperbaiki.
)

pause
endlocal
exit /b %AUDIT_STATUS%
