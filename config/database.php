<?php
/**
 * Konfigurasi koneksi database — aman dipakai di dev maupun production
 * TANPA mengubah kode, cukup lewat salah satu dari dua cara ini:
 *
 * 1. (Direkomendasikan di shared hosting/cPanel) Salin config/database.local.php.example
 *    menjadi config/database.local.php lalu isi kredensial di sana dengan define().
 *    File ini TIDAK ikut dibagikan/di-deploy ulang secara otomatis (lihat .gitignore
 *    kalau nanti pakai git) sehingga dev dan production bisa punya kredensial
 *    berbeda dari satu paket kode yang sama persis.
 *
 * 2. Set environment variable di server (DB_HOST/DB_NAME/DB_USER/DB_PASS/APP_ENV),
 *    misalnya lewat "Environment Variables" di cPanel/MultiPHP, atau SetEnv di
 *    .htaccess/virtual host.
 *
 * Urutan prioritas: config/database.local.php  >  environment variable  >  default dev.
 */
require_once __DIR__ . '/env.php';

// 1. Override lokal (opsional, tidak wajib ada, TIDAK ikut ter-deploy otomatis).
if (file_exists(__DIR__ . '/database.local.php')) {
    require __DIR__ . '/database.local.php';
}

// 2. Environment variable, lalu default dev lokal (hanya dipakai jika belum di-define di atas).
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'mitra_kinerja');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Auto-check and migrate schema if missing (prevents HTTP 500 on fresh pulls)
            if (file_exists(__DIR__ . '/../includes/auto_migrate.php')) {
                require_once __DIR__ . '/../includes/auto_migrate.php';
                ensureDatabaseSchema($pdo);
            }
        } catch (PDOException $e) {
            // Di production jangan bocorkan detail koneksi (host/kredensial) ke pengguna.
            error_log('DB connection failed: ' . $e->getMessage());
            die(APP_ENV === 'production'
                ? 'Terjadi kesalahan sistem. Silakan hubungi admin.'
                : 'Koneksi database gagal: ' . $e->getMessage());
        }
    }
    return $pdo;
}


