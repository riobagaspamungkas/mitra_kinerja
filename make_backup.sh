#!/usr/bin/env bash
# ============================================================
# MITRA KINERJA - LINUX / UBUNTU BACKUP UTILITY
# ============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "============================================================"
echo "  MITRA KINERJA - BACKUP UTILITY (Linux / Ubuntu)"
echo "  Membuat arsip backup lengkap (Codebase + Database MySQL)"
echo "============================================================"
echo ""

PYTHON_BIN=""
if [ -x "$SCRIPT_DIR/.venv/bin/python3" ]; then
    PYTHON_BIN="$SCRIPT_DIR/.venv/bin/python3"
elif [ -x "$SCRIPT_DIR/venv/bin/python3" ]; then
    PYTHON_BIN="$SCRIPT_DIR/venv/bin/python3"
elif command -v python3 >/dev/null 2>&1; then
    PYTHON_BIN="python3"
elif command -v python >/dev/null 2>&1; then
    PYTHON_BIN="python"
elif [ -x "$HOME/.local/bin/python3" ]; then
    PYTHON_BIN="$HOME/.local/bin/python3"
else
    echo "[ERROR] Python 3 tidak ditemukan di sistem."
    exit 1
fi

"$PYTHON_BIN" scripts/make_backup.py "$@"

echo ""
echo "============================================================"
echo "  Backup selesai disimpan di folder 'backups/'"
echo "============================================================"
echo ""
