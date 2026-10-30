@echo off
setlocal enabledelayedexpansion
title Inisialisasi Database Mitra Kinerja
cd /d "%~dp0"

echo ============================================================
echo   MITRA KINERJA - KANWIL KEMENTERIAN HUKUM KEPRI
echo   Inisialisasi ^& Setup Otomatis Database MySQL
echo ============================================================
echo.

:: 1. Deteksi PHP
set "PHP_BIN=php"
where php >nul 2>&1
if %ERRORLEVEL% neq 0 (
    if exist "C:\xampp\php\php.exe" (
        set "PHP_BIN=C:\xampp\php\php.exe"
        set "PATH=C:\xampp\php;!PATH!"
        echo       [OK] Menggunakan PHP dari C:\xampp\php.
    ) else if exist "D:\xampp\php\php.exe" (
        set "PHP_BIN=D:\xampp\php\php.exe"
        set "PATH=D:\xampp\php;!PATH!"
        echo       [OK] Menggunakan PHP dari D:\xampp\php.
    ) else if exist "E:\xampp\php\php.exe" (
        set "PHP_BIN=E:\xampp\php\php.exe"
        set "PATH=E:\xampp\php;!PATH!"
        echo       [OK] Menggunakan PHP dari E:\xampp\php.
    ) else (
        echo [ERROR] PHP tidak ditemukan di PATH sistem atau direktori XAMPP.
        echo Pastikan XAMPP sudah terinstall di C:\xampp atau D:\xampp.
        echo.
        pause
        exit /b 1
    )
)

:: 2. Deteksi & Pastikan MySQL (Port 3306) Berjalan
echo [1/2] Memeriksa koneksi layanan MySQL (Port 3306)...
netstat -ano | findstr /r /c:":3306.*LISTENING" >nul 2>&1
if %ERRORLEVEL% equ 0 (
    echo       [OK] Layanan MySQL terdeteksi aktif.
) else (
    echo       [..] MySQL belum aktif. Mencoba menyalakan MySQL XAMPP...
    set "MYSQLD_EXE="
    set "MYSQL_INI="
    if exist "C:\xampp\mysql\bin\mysqld.exe" (
        set "MYSQLD_EXE=C:\xampp\mysql\bin\mysqld.exe"
        set "MYSQL_INI=C:\xampp\mysql\bin\my.ini"
    ) else if exist "D:\xampp\mysql\bin\mysqld.exe" (
        set "MYSQLD_EXE=D:\xampp\mysql\bin\mysqld.exe"
        set "MYSQL_INI=D:\xampp\mysql\bin\my.ini"
    ) else if exist "E:\xampp\mysql\bin\mysqld.exe" (
        set "MYSQLD_EXE=E:\xampp\mysql\bin\mysqld.exe"
        set "MYSQL_INI=E:\xampp\mysql\bin\my.ini"
    )
    if defined MYSQLD_EXE (
        start /b "" "!MYSQLD_EXE!" --defaults-file="!MYSQL_INI!" --standalone >nul 2>&1
        %SystemRoot%\System32\timeout.exe /t 3 /nobreak >nul 2>&1
        netstat -ano | findstr /r /c:":3306.*LISTENING" >nul 2>&1
        if !ERRORLEVEL! equ 0 (
            echo       [OK] MySQL XAMPP berhasil dinyalakan.
        ) else (
            echo       [WARNING] MySQL belum merespons. Pastikan MySQL di XAMPP Control Panel sudah di-klik START.
        )
    ) else (
        echo       [WARNING] XAMPP MySQL tidak ditemukan di drive default. Pastikan MySQL sudah menyala.
    )
)

echo.
echo [2/2] Menjalankan skrip inisialisasi database...
echo.

"%PHP_BIN%" scripts\setup_database.php %*

if %ERRORLEVEL% neq 0 (
    echo.
    echo [ERROR] Proses inisialisasi database gagal. Periksa pesan error di atas.
) else (
    echo.
    echo [INFO] Inisialisasi database selesai dengan sukses.
)

echo.
pause
