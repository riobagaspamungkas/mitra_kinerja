<?php
/**
 * Script untuk inisialisasi & import otomatis database mitra_kinerja
 */
require_once __DIR__ . '/../config/database.php';

echo "=== Setup & Inisialisasi Database Mitra Kinerja ===\n";
echo "Host     : " . DB_HOST . "\n";
echo "Database : " . DB_NAME . "\n";
echo "User     : " . DB_USER . "\n\n";

try {
    echo "[1/4] Menghubungkan ke MySQL Server...\n";
    $dsnNoDb = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsnNoDb, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "      -> Berhasil terhubung ke MySQL Server!\n\n";

    echo "[2/4] Menyiapkan database '" . DB_NAME . "'...\n";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
    echo "      -> Database siap!\n\n";

    echo "[3/4] Mengimpor schema tabel (schema.sql)...\n";
    $schemaFile = __DIR__ . '/../database/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("File schema.sql tidak ditemukan di: $schemaFile");
    }
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);
    echo "      -> Tabel & struktur database berhasil dibuat!\n\n";

    echo "[4/4] Mengimpor data awal / seed data (seed_data.sql)...\n";
    $seedFile = __DIR__ . '/../database/seed_data.sql';
    if (file_exists($seedFile)) {
        // Cek apakah data mitra sudah ada agar tidak duplikat
        $stmt = $pdo->query("SELECT COUNT(*) FROM mitra_kinerja");
        $count = (int) $stmt->fetchColumn();
        if ($count === 0) {
            $seedSql = file_get_contents($seedFile);
            $pdo->exec($seedSql);
            echo "      -> Seed data berhasil diimpor!\n\n";
        } else {
            echo "      -> Data mitra sudah ada ($count baris), lewati seed agar tidak menimpa data.\n\n";
        }
    }

    echo "====================================================\n";
    echo " SUKSES! Database 'mitra_kinerja' siap digunakan.\n";
    echo "====================================================\n";
    echo "Akun Demo:\n";
    echo " - Admin     : admin / admin123\n";
    echo " - Pemeriksa : pemeriksa / pemeriksa123\n";
    echo " - Validator : validator / validator123\n";
    echo " - Pimpinan  : pimpinan / pimpinan123\n";
    echo " - Pengampu  : pengampu / pengampu123\n";
    echo " - PIC       : pic / pic123\n";
} catch (PDOException $e) {
    echo "\n[ERROR] Koneksi Database Gagal: " . $e->getMessage() . "\n";
    echo "Pastikan layanan MySQL (seperti XAMPP MySQL) sudah di-START dan berjalan di " . DB_HOST . ".\n";
    exit(1);
} catch (Throwable $e) {
    echo "\n[ERROR] Terjadi kesalahan: " . $e->getMessage() . "\n";
    exit(1);
}
