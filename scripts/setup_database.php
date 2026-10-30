<?php
/**
 * Script untuk inisialisasi & import otomatis database mitra_kinerja (Dual-OS Windows & Linux)
 */
require_once __DIR__ . '/../config/database.php';

echo "====================================================\n";
echo "  Setup & Inisialisasi Database Mitra Kinerja\n";
echo "====================================================\n";
echo "Host     : " . DB_HOST . (defined('DB_PORT') ? ':' . DB_PORT : '') . "\n";
echo "Database : " . DB_NAME . "\n";
echo "User     : " . DB_USER . "\n\n";

$isForce = in_array('--force', $argv ?? []);

try {
    echo "[1/4] Menghubungkan ke MySQL Server...\n";
    
    $multiStmtAttr = defined('\Pdo\Mysql::ATTR_MULTI_STATEMENTS')
        ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS
        : PDO::MYSQL_ATTR_MULTI_STATEMENTS;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        $multiStmtAttr               => true,
    ];

    $pdo = null;
    $connectErrors = [];

    // Prioritas 1: Socket jika ada dan valid (Linux)
    if (defined('DB_SOCKET') && DB_SOCKET !== '' && file_exists(DB_SOCKET)) {
        try {
            $dsnNoDb = "mysql:unix_socket=" . DB_SOCKET . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsnNoDb, DB_USER, DB_PASS, $options);
        } catch (Throwable $e) {
            $connectErrors[] = "Socket: " . $e->getMessage();
        }
    }

    // Prioritas 2: TCP DB_HOST (Windows / Linux TCP)
    if (!$pdo) {
        $candidateHosts = [DB_HOST];
        if (DB_HOST === 'localhost') {
            $candidateHosts[] = '127.0.0.1';
        }
        foreach ($candidateHosts as $h) {
            try {
                $port = defined('DB_PORT') ? DB_PORT : '3306';
                $dsnNoDb = "mysql:host={$h};port={$port};charset=" . DB_CHARSET;
                $pdo = new PDO($dsnNoDb, DB_USER, DB_PASS, $options);
                break;
            } catch (Throwable $e) {
                $connectErrors[] = "Host {$h}: " . $e->getMessage();
            }
        }
    }

    if (!$pdo) {
        throw new PDOException("Gagal terhubung ke MySQL Server. Detail:\n - " . implode("\n - ", $connectErrors));
    }

    echo "      -> Berhasil terhubung ke MySQL Server!\n\n";

    echo "[2/4] Menyiapkan database '" . DB_NAME . "'...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
    echo "      -> Database '" . DB_NAME . "' siap!\n\n";

    // Cek apakah tabel dan data sudah ada
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'mitra_kinerja'")->fetchAll();
    $existingCount = 0;
    if (!empty($tableCheck)) {
        $existingCount = (int) $pdo->query("SELECT COUNT(*) FROM `mitra_kinerja`")->fetchColumn();
    }

    if ($existingCount > 0 && !$isForce) {
        echo "[3/4] Memeriksa struktur & integritas data...\n";
        echo "      -> Database sudah berisi data aktif ($existingCount naskah mitra).\n";
        echo "      -> Melewati import ulang agar tidak menimpa data.\n";
        echo "      (Gunakan '--force' jika ingin reset ulang dari awal).\n\n";
    } else {
        echo "[3/4] Mengimpor data master & struktur database...\n";
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        $masterFile = __DIR__ . '/../database/mitra_kinerja.sql';
        if (file_exists($masterFile)) {
            echo "      -> Menggunakan master database: database/mitra_kinerja.sql\n";
            $sql = file_get_contents($masterFile);
            $pdo->exec($sql);
        } else {
            // Fallback jika mitra_kinerja.sql tidak ada
            $schemaFile = __DIR__ . '/../database/schema.sql';
            $seedFile = __DIR__ . '/../database/seed_data.sql';
            if (file_exists($schemaFile)) {
                echo "      -> Mengimpor database/schema.sql\n";
                $pdo->exec(file_get_contents($schemaFile));
            }
            if (file_exists($seedFile)) {
                echo "      -> Mengimpor database/seed_data.sql\n";
                $pdo->exec(file_get_contents($seedFile));
            }
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        echo "      -> Import master database berhasil 100%!\n\n";
    }

    echo "[4/4] Verifikasi Tabel & Ringkasan Data:\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $tbl) {
        $c = (int) $pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
        printf("      %-22s : %5d baris\n", $tbl, $c);
    }
    echo "\n";

    echo "====================================================\n";
    echo " SUKSES! Database '" . DB_NAME . "' Siap Digunakan.\n";
    echo "====================================================\n";
    echo "Akun Demo:\n";
    echo " - Admin     : admin / admin123\n";
    echo " - Pemeriksa : pemeriksa / pemeriksa123\n";
    echo " - Validator : validator / validator123\n";
    echo " - Pimpinan  : pimpinan / pimpinan123\n";
    echo " - Pengampu  : pengampu / pengampu123\n";
    echo " - PIC       : pic / pic123\n";
    exit(0);
} catch (PDOException $e) {
    echo "\n[ERROR] Koneksi / Query Database Gagal: " . $e->getMessage() . "\n";
    echo "Pastikan layanan MySQL (seperti XAMPP MySQL) sudah di-START dan berjalan di Port 3306.\n";
    exit(1);
} catch (Throwable $e) {
    echo "\n[ERROR] Terjadi kesalahan: " . $e->getMessage() . "\n";
    exit(1);
}
