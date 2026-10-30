@echo off
setlocal
title MITRA KINERJA - Project Backup
cd /d "%~dp0"

echo ============================================================
echo   MITRA KINERJA - BACKUP UTILITY
echo   Membuat arsip backup lengkap (Codebase + Database MySQL)
echo ============================================================
echo.

set "PY_CMD=python"
if exist ".venv\Scripts\python.exe" (
    set "PY_CMD=.venv\Scripts\python.exe"
) else if exist "venv\Scripts\python.exe" (
    set "PY_CMD=venv\Scripts\python.exe"
) else (
    where python >nul 2>&1
    if %ERRORLEVEL% neq 0 (
        where py >nul 2>&1
        if %ERRORLEVEL% equ 0 (
            set "PY_CMD=py"
        ) else (
            echo [ERROR] Python tidak ditemukan di PATH sistem.
            echo Pastikan Python 3 sudah terinstall dan terdaftar di PATH sistem.
            pause
            exit /b 1
        )
    )
)

%PY_CMD% scripts\make_backup.py %*
if %ERRORLEVEL% neq 0 (
    echo.
    echo [ERROR] Backup gagal dibuat. Terjadi kesalahan pada proses backup.
    pause
    exit /b %ERRORLEVEL%
)

echo.
echo ============================================================
echo   Backup selesai disimpan di folder 'backups/'
echo ============================================================
echo.
pause
