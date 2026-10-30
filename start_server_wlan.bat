@echo off
setlocal enabledelayedexpansion
title MITRA KINERJA - Server WLAN
cd /d "%~dp0"

echo ============================================================
echo   MITRA KINERJA - KANWIL KEMENTERIAN HUKUM KEPRI
echo   Starting Local ^& WLAN Web Server...
echo ============================================================
echo.

:: 1. Check PHP
where php >nul 2>&1
if %ERRORLEVEL% neq 0 (
    if exist "C:\xampp\php\php.exe" (
        set "PATH=C:\xampp\php;!PATH!"
        echo       [OK] Menggunakan PHP dari C:\xampp\php.
    ) else if exist "D:\xampp\php\php.exe" (
        set "PATH=D:\xampp\php;!PATH!"
        echo       [OK] Menggunakan PHP dari D:\xampp\php.
    ) else (
        echo [ERROR] PHP tidak ditemukan di PATH sistem.
        echo Pastikan PHP sudah terinstall dan terdaftar di environment variable PATH.
        pause
        exit /b 1
    )
)

:: 2. Check & Ensure MySQL (XAMPP) is running
echo [1/3] Memeriksa koneksi Database MySQL (Port 3306)...
netstat -ano | findstr /r /c:":3306.*LISTENING" >nul 2>&1
if %ERRORLEVEL% equ 0 (
    echo       [OK] Database MySQL sudah aktif.
) else (
    echo       [..] MySQL belum berjalan. Mencoba menyalakan MySQL XAMPP...
    set "MYSQLD_EXE="
    set "MYSQL_INI="
    if exist "C:\xampp\mysql\bin\mysqld.exe" (
        set "MYSQLD_EXE=C:\xampp\mysql\bin\mysqld.exe"
        set "MYSQL_INI=C:\xampp\mysql\bin\my.ini"
    ) else if exist "D:\xampp\mysql\bin\mysqld.exe" (
        set "MYSQLD_EXE=D:\xampp\mysql\bin\mysqld.exe"
        set "MYSQL_INI=D:\xampp\mysql\bin\my.ini"
    )
    if defined MYSQLD_EXE (
        start /b "" "!MYSQLD_EXE!" --defaults-file="!MYSQL_INI!" --standalone >nul 2>&1
        %SystemRoot%\System32\timeout.exe /t 3 /nobreak >nul 2>&1
        netstat -ano | findstr /r /c:":3306.*LISTENING" >nul 2>&1
        if !ERRORLEVEL! equ 0 (
            echo       [OK] MySQL XAMPP berhasil dijalankan.
        ) else (
            echo       [WARNING] MySQL belum terdeteksi aktif. Silakan pastikan MySQL di XAMPP Control Panel menyala.
        )
    ) else (
        echo       [WARNING] XAMPP MySQL tidak ditemukan di C:\xampp atau D:\xampp. Pastikan MySQL berjalan manual.
    )
)

echo.
:: 3. Detect WLAN / Local IP Address
echo [2/3] Mendeteksi Alamat IP WLAN / Jaringan Lokal...

set "WLAN_IP="
for /f "usebackq tokens=*" %%i in (`powershell -NoProfile -Command "(Get-NetIPAddress -AddressFamily IPv4 -InterfaceAlias '*Wi-Fi*','*WLAN*','*Wireless*' -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '169.254*' -and $_.InterfaceAlias -notlike '*vEthernet*' -and $_.InterfaceAlias -notlike '*Virtual*' -and $_.InterfaceAlias -notlike '*WSL*' }).IPAddress | Select-Object -First 1"`) do (
    set "WLAN_IP=%%i"
)

if "%WLAN_IP%"=="" (
    for /f "usebackq tokens=*" %%i in (`powershell -NoProfile -Command "(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254*' -and $_.IPAddress -notlike '100.*' -and $_.InterfaceAlias -notlike '*vEthernet*' -and $_.InterfaceAlias -notlike '*Virtual*' -and $_.InterfaceAlias -notlike '*WSL*' }).IPAddress | Select-Object -First 1"`) do (
        set "WLAN_IP=%%i"
    )
)

if "%WLAN_IP%"=="" set "WLAN_IP=127.0.0.1"

set "PORT=8080"
netstat -ano | findstr /r /c:":8080.*LISTENING" >nul 2>&1
if %ERRORLEVEL% equ 0 (
    set "PORT=8085"
    netstat -ano | findstr /r /c:":8085.*LISTENING" >nul 2>&1
    if !ERRORLEVEL! equ 0 (
        set "FOUND_PORT="
        for /l %%p in (8086,1,8100) do (
            if not defined FOUND_PORT (
                netstat -ano | findstr /r /c:":%%p.*LISTENING" >nul 2>&1
                if !ERRORLEVEL! neq 0 (
                    set "PORT=%%p"
                    set "FOUND_PORT=1"
                )
            )
        )
        if not defined FOUND_PORT (
            echo [ERROR] Seluruh port antara 8080 hingga 8100 sedang digunakan.
            pause
            exit /b 1
        )
    )
)

echo.
echo ============================================================
echo   SERVER BERHASIL DIAKTIFKAN^^!
echo ============================================================
echo.
echo   [+] Akses dari Komputer ini (Lokal):
echo       http://localhost:%PORT%/login.php
echo       http://127.0.0.1:%PORT%/login.php
echo.
if not "%WLAN_IP%"=="127.0.0.1" (
echo   [+] Akses dari HP / Laptop Lain di Jaringan Wi-Fi yang sama:
echo       http://%WLAN_IP%:%PORT%/login.php
echo.
)
echo   [i] Akun Demo:
echo       - Admin     : admin / admin123
echo       - Pemeriksa : pemeriksa / pemeriksa123
echo       - Validator : validator / validator123
echo       - Pimpinan  : pimpinan / pimpinan123
echo       - Pengampu  : pengampu / pengampu123
echo       - PIC       : pic / pic123
echo.
echo   [^^!] Jangan tutup jendela ini selama web ingin diakses.
echo   [^^!] Tekan CTRL + C untuk mematikan server.
echo ============================================================
echo.

:: 4. Ensure required cache and upload directories exist
if not exist "cache\sessions" mkdir "cache\sessions"
if not exist "public\uploads\baseline_pdf" mkdir "public\uploads\baseline_pdf"
if not exist "public\uploads\tindak_lanjut" mkdir "public\uploads\tindak_lanjut"

:: 5. Auto Open Browser
start http://localhost:%PORT%/login.php

:: 6. Run PHP Server binding to 0.0.0.0 (all interfaces) with router
set "PHP_CLI_SERVER_WORKERS=4"
php -d session.save_path="cache\sessions" -d upload_max_filesize=32M -d post_max_size=64M -S 0.0.0.0:%PORT% router.php
