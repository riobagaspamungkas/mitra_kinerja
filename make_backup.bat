@echo off
setlocal
title MITRA KINERJA - Project Backup
cd /d "%~dp0"

echo ============================================================
echo   MITRA KINERJA - BACKUP UTILITY
echo   Membuat arsip backup lengkap (Codebase + Database MySQL)
echo ============================================================
echo.

where python >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo [ERROR] Python tidak ditemukan di PATH sistem.
    pause
    exit /b 1
)

python scripts\make_backup.py %*

echo.
echo ============================================================
echo   Backup selesai disimpan di folder 'backups/'
echo ============================================================
echo.
pause
