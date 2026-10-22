#!/usr/bin/env bash
# ============================================================
# MITRA KINERJA - LINUX / UBUNTU SERVER LAUNCHER
# Kanwil Kementerian Hukum Kepulauan Riau
# ============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "============================================================"
echo "  MITRA KINERJA - KANWIL KEMENTERIAN HUKUM KEPRI"
echo "  Starting Local & WLAN Web Server (Linux / Ubuntu)..."
echo "============================================================"
echo ""

# 1. Check PHP
if ! command -v php >/dev/null 2>&1; then
    if [ -x "$HOME/.local/bin/php" ]; then
        export PATH="$HOME/.local/bin:$PATH"
    else
        echo "[ERROR] PHP tidak ditemukan di PATH sistem."
        echo "Pastikan PHP 8.0+ sudah terpasang."
        exit 1
    fi
fi

# 2. Check & Ensure MySQL / MariaDB is active on port 3306
echo "[1/3] Memeriksa koneksi Database MySQL (Port 3306)..."
is_port_listening() {
    local port="$1"
    if command -v ss >/dev/null 2>&1; then
        ss -tuln "sport = :$port" 2>/dev/null | grep -q ":$port " && return 0
    fi
    (exec 3<>/dev/tcp/127.0.0.1/"$port") 2>/dev/null && exec 3<&- && exec 3>&- && return 0
    return 1
}

if is_port_listening 3306; then
    echo "      [OK] Database MySQL sudah aktif di port 3306."
else
    echo "      [..] MySQL belum aktif di port 3306. Mencoba menyalakan userland mysqld..."
    if [ -x "$HOME/.local/bin/mysqld" ] && [ -f "$HOME/.local/etc/my.cnf" ]; then
        nohup "$HOME/.local/bin/mysqld" --defaults-file="$HOME/.local/etc/my.cnf" >/dev/null 2>&1 &
        sleep 3
        if is_port_listening 3306; then
            echo "      [OK] MySQL berhasil dijalankan."
        else
            echo "      [WARNING] MySQL belum terdeteksi aktif di port 3306. Pastikan MySQL menyala."
        fi
    else
        echo "      [WARNING] MySQL daemon tidak ditemukan di ~/.local/bin/mysqld. Pastikan service MySQL aktif."
    fi
fi

echo ""
# 3. Detect WLAN / Local LAN IP Address
echo "[2/3] Mendeteksi Alamat IP WLAN / Jaringan Lokal..."
WLAN_IP=""
if command -v ip >/dev/null 2>&1; then
    WLAN_IP=$(ip -4 -o addr show scope global 2>/dev/null | awk '$2 ~ /^(wl|wlan)/ {split($4, a, "/"); print a[1]; exit}' || true)
    if [ -z "$WLAN_IP" ]; then
        WLAN_IP=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") {print $(i+1); exit}}' || true)
    fi
fi
if [ -z "$WLAN_IP" ] && command -v hostname >/dev/null 2>&1; then
    WLAN_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || true)
fi
if [ -z "$WLAN_IP" ]; then
    WLAN_IP="127.0.0.1"
fi

# 4. Port Collision Check & Auto Failover
PORT=8080
if is_port_listening 8080; then
    PORT=8085
    if is_port_listening 8085; then
        FOUND_PORT=""
        for p in $(seq 8086 8100); do
            if ! is_port_listening "$p"; then
                PORT="$p"
                FOUND_PORT="1"
                break
            fi
        done
        if [ -z "$FOUND_PORT" ]; then
            echo "[ERROR] Seluruh port antara 8080 hingga 8100 sedang digunakan."
            exit 1
        fi
    fi
fi

# 5. Initialize safe session directory
mkdir -p "$SCRIPT_DIR/cache/sessions" "$SCRIPT_DIR/public/uploads/baseline_pdf" "$SCRIPT_DIR/public/uploads/tindak_lanjut"
chmod 700 "$SCRIPT_DIR/cache/sessions" 2>/dev/null || true

echo ""
echo "============================================================"
echo "  SERVER BERHASIL DIAKTIFKAN!"
echo "============================================================"
echo ""
echo "  [+] Akses dari Komputer ini (Lokal):"
echo "      http://localhost:$PORT/login.php"
echo "      http://127.0.0.1:$PORT/login.php"
echo ""
if [ "$WLAN_IP" != "127.0.0.1" ]; then
    echo "  [+] Akses dari HP / Laptop Lain di Jaringan Wi-Fi yang sama:"
    echo "      http://$WLAN_IP:$PORT/login.php"
    echo ""
fi
echo "  [i] Akun Demo:"
echo "      - Admin     : admin / admin123"
echo "      - Pemeriksa : pemeriksa / pemeriksa123"
echo "      - Validator : validator / validator123"
echo "      - Pimpinan  : pimpinan / pimpinan123"
echo "      - Pengampu  : pengampu / pengampu123"
echo "      - PIC       : pic / pic123"
echo ""
echo "  [!] Tekan CTRL + C untuk mematikan server."
echo "============================================================"
echo ""

# 6. Auto-Open browser if graphical desktop session is available
if [ -n "${DISPLAY:-}" ] || [ -n "${WAYLAND_DISPLAY:-}" ]; then
    if command -v xdg-open >/dev/null 2>&1; then
        (sleep 1 && xdg-open "http://localhost:$PORT/login.php" >/dev/null 2>&1 &) || true
    fi
fi

# 7. Run PHP Server binding to 0.0.0.0 with router
export PHP_CLI_SERVER_WORKERS=4
exec php -d session.save_path="$SCRIPT_DIR/cache/sessions" -d upload_max_filesize=32M -d post_max_size=64M -S 0.0.0.0:"$PORT" router.php
