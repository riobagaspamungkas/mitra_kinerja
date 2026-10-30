<?php
/**
 * Autentikasi & otorisasi berbasis role.
 * Role: admin, pemeriksa, validator, pimpinan
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    session_start();
}

function currentUser(bool $forceRefresh = false): ?array {
    static $cachedUser = null;
    static $hasChecked = false;

    if ($forceRefresh) {
        $cachedUser = null;
        $hasChecked = false;
    }

    if ($hasChecked) {
        return $cachedUser;
    }

    if (empty($_SESSION['user']) || !is_array($_SESSION['user']) || empty($_SESSION['user']['id'])) {
        $hasChecked = true;
        $cachedUser = null;
        return null;
    }

    // Idle session timeout check (30 minutes = 1800 seconds)
    if (!empty($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity'] > 1800)) {
        doLogout();
        $hasChecked = true;
        $cachedUser = null;
        return null;
    }
    $_SESSION['last_activity'] = time();

    // Re-check user status from DB to ensure session is still valid/active.
    // If user is inactive or deleted, log them out immediately.
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT id, nama, username, role, aktif, foto FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user']['id']]);
        $dbUser = $stmt->fetch();

        if (!$dbUser || empty($dbUser['aktif'])) {
            doLogout();
            $hasChecked = true;
            $cachedUser = null;
            return null;
        }

        // Keep session data synchronized with latest DB state
        $_SESSION['user']['nama'] = $dbUser['nama'];
        $_SESSION['user']['username'] = $dbUser['username'];
        $_SESSION['user']['role'] = $dbUser['role'];
        $_SESSION['user']['aktif'] = (int)$dbUser['aktif'];
        $_SESSION['user']['foto'] = $dbUser['foto'] ?? null;

        $cachedUser = $_SESSION['user'];
        $hasChecked = true;
        return $cachedUser;
    } catch (Throwable $e) {
        error_log('currentUser DB verification failed: ' . $e->getMessage());
        $hasChecked = true;
        $cachedUser = null;
        return null;
    }
}

function requireLogin(): void {
    if (!currentUser()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Wajibkan salah satu role dari daftar yang diizinkan.
 * Contoh: requireRole(['admin','pemeriksa']);
 */
function requireRole(array $roles): void {
    requireLogin();
    $user = currentUser();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        die('Akses ditolak: role Anda (' . htmlspecialchars($user['role']) . ') tidak memiliki izin untuk halaman ini.');
    }
}

function attemptLogin(string $username, string $password): bool {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $demoUsers = [
        'admin'      => ['nama' => 'Administrator', 'role' => 'admin'],
        'pemeriksa'  => ['nama' => 'Pemeriksa Kerja Sama', 'role' => 'pemeriksa'],
        'validator'  => ['nama' => 'Validator Unit', 'role' => 'validator'],
        'pimpinan'   => ['nama' => 'Pimpinan Wilayah', 'role' => 'pimpinan'],
        'pengampu'   => ['nama' => 'Unit Pengampu (Divisi/Bagian)', 'role' => 'pengampu'],
        'pic'        => ['nama' => 'PIC Operasional Kerja Sama', 'role' => 'pic'],
    ];

    $isValid = false;
    $dummyHash = '$2y$12$I9etv6Uk10J47jr/70NiU.nScEgbBP/oVv0LpqIM2S3z6sO/ZxXuq';
    if ($user) {
        $hashToVerify = (!empty($user['password_hash']) && is_string($user['password_hash'])) ? $user['password_hash'] : $dummyHash;
        $pwMatches = password_verify($password, $hashToVerify);
        if ($pwMatches && !empty($user['aktif']) && !empty($user['password_hash'])) {
            $isValid = true;
        }
    } else {
        password_verify($password, $dummyHash);
    }
    if (!$isValid && defined('APP_ENV') && APP_ENV === 'development' && isset($demoUsers[$username]) && $password === $username . '123') {
        // Auto-heal demo account jika password cocok username123 (hanya di mode development)
        $demo = $demoUsers[$username];
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        if ($user) {
            try {
                $pdo->prepare('UPDATE users SET password_hash = ?, aktif = 1, role = ? WHERE id = ?')
                    ->execute([$newHash, $demo['role'], $user['id']]);
                $user['role'] = $demo['role'];
                $user['aktif'] = 1;
            } catch (Throwable $e) {}
        } else {
            try {
                $stmtIns = $pdo->prepare('INSERT INTO users (nama, username, password_hash, role, aktif) VALUES (?, ?, ?, ?, 1)');
                $stmtIns->execute([$demo['nama'], $username, $newHash, $demo['role']]);
                $user = [
                    'id' => (int)$pdo->lastInsertId(),
                    'nama' => $demo['nama'],
                    'username' => $username,
                    'role' => $demo['role'],
                    'aktif' => 1
                ];
            } catch (Throwable $e) {}
        }
        $isValid = true;
    }

    if ($isValid && $user) {
        session_regenerate_id(true);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        $_SESSION['last_activity'] = time();
        currentUser(true);
        logAudit(null, (int)$user['id'], 'LOGIN', 'Login berhasil');
        return true;
    }
    return false;
}

function doLogout(): void {
    $user = $_SESSION['user'] ?? null;
    if ($user && is_array($user) && !empty($user['id'])) {
        logAudit(null, (int)$user['id'], 'LOGOUT', 'Logout');
    }
    $_SESSION = [];
    currentUser(true);
    if (ini_get("session.use_cookies") && !headers_sent()) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function logAudit(?int $mitraId, ?int $userId, string $aksi, string $detail = ''): void {
    if ($mitraId !== null && $mitraId <= 0) {
        $mitraId = null;
    }
    if ($userId !== null && $userId <= 0) {
        $userId = null;
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('INSERT INTO audit_log (mitra_id, user_id, aksi, detail) VALUES (?, ?, ?, ?)');
        $stmt->execute([$mitraId, $userId, $aksi, $detail]);
    } catch (Throwable $e) {
        // Jangan sampai kegagalan audit log mengganggu alur utama aplikasi.
    }
}

/**
 * CSRF Protection Helpers
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_token(): string {
    return csrfToken();
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_field(): string {
    return csrfField();
}

function verifyCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function verify_csrf_token(?string $token): bool {
    return verifyCsrfToken($token);
}
