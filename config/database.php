<?php
// Mencegah akses langsung via web browser
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'database.php' || (isset($_SERVER['SCRIPT_FILENAME']) && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME']))) {
    http_response_code(403);
    die('Akses langsung ditolak.');
}

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
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: (PHP_OS_FAMILY === 'Windows' ? 'localhost' : '127.0.0.1'));
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_SOCKET')) {
    $defaultSocket = '';
    if (PHP_OS_FAMILY !== 'Windows') {
        $candidateSockets = array_filter([
            getenv('HOME') ? rtrim(getenv('HOME'), '/\\') . '/.local/var/run/mysqld/mysqld.sock' : '',
            '/var/run/mysqld/mysqld.sock',
            '/tmp/mysql.sock'
        ]);
        foreach ($candidateSockets as $sock) {
            if (file_exists($sock)) {
                $defaultSocket = $sock;
                break;
            }
        }
    }
    define('DB_SOCKET', getenv('DB_SOCKET') ?: $defaultSocket);
}
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'mitra_kinerja');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (defined('DB_SOCKET') && DB_SOCKET !== '' && file_exists(DB_SOCKET)) {
            $dsn = "mysql:unix_socket=" . DB_SOCKET . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        } else {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        }
        $multiStmtAttr = defined('\Pdo\Mysql::ATTR_MULTI_STATEMENTS')
            ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS
            : PDO::MYSQL_ATTR_MULTI_STATEMENTS;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            $multiStmtAttr               => true,
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
            if (!headers_sent()) {
                http_response_code(500);
            }
            die(APP_ENV === 'production'
                ? 'Terjadi kesalahan sistem. Silakan hubungi admin.'
                : 'Koneksi database gagal: ' . $e->getMessage());
        }
    }
    return $pdo;
}


