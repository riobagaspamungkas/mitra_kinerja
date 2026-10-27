@echo off
title Inisialisasi Database Mitra Kinerja
cd /d "%~dp0"

echo ============================================================
echo   INISIALISASI & IMPORT DATABASE MITRA KINERJA
echo ============================================================
echo.

php scripts\setup_database.php

echo.
pause
