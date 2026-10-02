<?php
/**
 * Konfigurasi environment. Aman-secara-default: kalau APP_ENV tidak di-set
 * sama sekali di server, dianggap 'production' (error TIDAK ditampilkan ke
 * pengunjung) — supaya tidak ada yang lupa mematikan display_errors saat go-live.
 *
 * Cara mengaktifkan mode development di server dev:
 *   - cPanel: Setup Node/PHP App atau "Environment Variables" -> APP_ENV=development
 *   - .htaccess (kalau mod_env aktif): SetEnv APP_ENV development
 *   - atau paling gampang: buat file config/env.local.php isi 'development' (lihat bawah)
 */
if (!defined('APP_ENV')) {
    $env = getenv('APP_ENV');
    if (!$env && file_exists(__DIR__ . '/env.local.php')) {
        $env = trim((string) include __DIR__ . '/env.local.php');
    }
    // Auto-detect development mode on localhost or local LAN
    if (!$env) {
        $serverName = $_SERVER['SERVER_NAME'] ?? '';
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($serverName === 'localhost' || $remoteAddr === '127.0.0.1' || $remoteAddr === '::1' || str_starts_with($remoteAddr, '192.168.') || str_starts_with($remoteAddr, '10.') || str_starts_with($remoteAddr, '172.')) {
            $env = 'development';
        }
    }
    define('APP_ENV', $env === 'development' ? 'development' : 'production');
}

if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// Cookie sesi lebih aman kalau sudah HTTPS (production biasanya sudah SSL).
// Di dev lokal tanpa HTTPS, cookie_secure otomatis nonaktif supaya login tetap bisa dites.
if (!headers_sent() && session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
