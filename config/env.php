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
    // Auto-detect development mode only on direct local development hostnames
    if (!$env) {
        $serverHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        $forwardedHeaders = [
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_SERVER'] ?? '',
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            $_SERVER['HTTP_FORWARDED'] ?? '',
            $_SERVER['HTTP_CLIENT_IP'] ?? '',
        ];
        $hasForwardedHeaders = false;
        foreach ($forwardedHeaders as $hdr) {
            if ($hdr !== '') {
                $hasForwardedHeaders = true;
                break;
            }
        }

        $isLocalHost = in_array($serverHost, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($serverHost, '.local')
            || str_ends_with($serverHost, '.test');

        $isLoopbackIp = in_array($remoteAddr, ['127.0.0.1', '::1', ''], true);

        // Reverse proxy headers and production hostnames cannot spoof development mode
        if ($isLocalHost && !$hasForwardedHeaders && $isLoopbackIp) {
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
    // Default direktori sesi aman jika direktori sistem tidak dapat ditulis (Linux non-root / server headless)
    $currentSessionPath = ini_get('session.save_path');
    if (empty($currentSessionPath) || !is_dir($currentSessionPath) || !is_writable($currentSessionPath)) {
        $localSessionPath = __DIR__ . '/../cache/sessions';
        if (!is_dir($localSessionPath)) {
            @mkdir($localSessionPath, 0700, true);
        }
        if (is_dir($localSessionPath) && is_writable($localSessionPath)) {
            session_save_path($localSessionPath);
        } else {
            session_save_path(sys_get_temp_dir());
        }
    }

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
