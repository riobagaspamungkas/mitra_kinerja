<?php
require_once __DIR__ . '/includes/auth.php';

if (currentUser()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $lockoutTime = 60; // 60 detik lockout
    $maxAttempts = 5;  // 5 kali percobaan

    // Dapatkan alamat IP klien (hanya percaya HTTP_X_FORWARDED_FOR jika REMOTE_ADDR ada di daftar trusted proxy)
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $trustedProxies = defined('TRUSTED_PROXIES') && is_array(TRUSTED_PROXIES)
        ? TRUSTED_PROXIES
        : (defined('TRUSTED_PROXIES') && is_string(TRUSTED_PROXIES) ? array_map('trim', explode(',', TRUSTED_PROXIES)) : []);

    if (in_array($clientIp, $trustedProxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $fwd = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($fwd, FILTER_VALIDATE_IP)) {
            $clientIp = $fwd;
        }
    }

    $rawUser = isset($_POST['username']) && is_string($_POST['username']) ? $_POST['username'] : '';
    $rawPass = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $username = trim($rawUser);
    $password = $rawPass;

    // Cek kegagalan login terkini di database (audit_log) dalam 5 menit terakhir
    $dbAttempts = 0;
    $lastFailedTime = 0;
    try {
        $pdo = getDB();
        $lastSuccessTime = null;
        if ($username !== '') {
            $stmtSuccess = $pdo->prepare("
                SELECT MAX(al.created_at) 
                FROM audit_log al 
                JOIN users u ON al.user_id = u.id 
                WHERE al.aksi = 'LOGIN' AND u.username = ?
            ");
            $stmtSuccess->execute([$username]);
            $lastSuccessTime = $stmtSuccess->fetchColumn() ?: null;
        }

        $escapedIp = addcslashes($clientIp, '%_\\');

        // Hitung kegagalan login berdasarkan IP client (mencegah brute-force/spray)
        // Gunakan delimiter baku untuk mencocokkan IP secara persis
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS failed_count, MAX(UNIX_TIMESTAMP(created_at)) AS last_failed
            FROM audit_log
            WHERE aksi = 'LOGIN_FAILED'
              AND created_at >= NOW() - INTERVAL 5 MINUTE
              AND detail LIKE ?
        ");
        $stmt->execute([
            '%IP: ' . $escapedIp
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $dbAttempts = (int)($row['failed_count'] ?? 0);
            $lastFailedTime = (int)($row['last_failed'] ?? 0);
        }
    } catch (Throwable $e) {}

    // Sinergi session + DB: ambil nilai attempt dan lockout tertinggi
    $sessionLockUntil = (int)($_SESSION['login_lockout'] ?? 0);
    $dbLockUntil = ($dbAttempts >= $maxAttempts && $lastFailedTime > 0) ? ($lastFailedTime + $lockoutTime) : 0;
    $lockUntil = max($sessionLockUntil, $dbLockUntil);

    if ($lockUntil > time()) {
        $remaining = $lockUntil - time();
        $error = "Terlalu banyak percobaan login gagal. Silakan tunggu {$remaining} detik.";
    } else {
        $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
        if ($token === null || !verifyCsrfToken($token)) {
            $error = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
        } elseif ($username === '' || $password === '') {
            $error = 'Username dan password wajib diisi.';
        } elseif (attemptLogin($username, $password)) {
            unset($_SESSION['login_attempts'], $_SESSION['login_lockout']);
            header('Location: dashboard.php');
            exit;
        } else {
            // Catat kegagalan ke audit_log (menyimpan Username dan IP, sanitasi delimiter untuk mencegah log injection)
            $safeLogUser = str_replace(["\x0d", "\x0a"], '', str_replace([' | ', '|'], [' ', '_'], $username));
            logAudit(null, null, 'LOGIN_FAILED', 'Username: ' . $safeLogUser . ' | IP: ' . $clientIp);
            $attempts = max((int)($_SESSION['login_attempts'] ?? 0), $dbAttempts) + 1;
            if ($attempts >= $maxAttempts) {
                $_SESSION['login_lockout'] = time() + $lockoutTime;
                $_SESSION['login_attempts'] = 0;
                $error = "Terlalu banyak percobaan login gagal. Akun dikunci sementara selama {$lockoutTime} detik.";
            } else {
                $_SESSION['login_attempts'] = $attempts;
                $sisa = $maxAttempts - $attempts;
                $error = "Username atau password salah. (Sisa percobaan: {$sisa})";
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Masuk — Mitra Kinerja</title>
<link rel="stylesheet" href="public/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-brand">
            <div class="logo-circle">MK</div>
            <h1>MITRA KINERJA</h1>
            <p>Monitoring &amp; Evaluasi Kerja Sama<br>Kanwil Kementerian Hukum Kepulauan Riau</p>
        </div>
        <?php if ($error): ?><div class="error-box"><?= h($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <label for="username">Username</label>
            <input type="text" id="username" name="username" autofocus required>
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
            <button type="submit">Masuk</button>
        </form>
        
        <?php if (defined('APP_ENV') && APP_ENV === 'development'): ?>
        <div style="margin-top:16px;padding:12px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;font-size:12px;color:#475569;">
            <div style="font-weight:700;margin-bottom:8px;color:#1e293b;display:flex;align-items:center;gap:6px;">
                <span>🔑</span> Akun Demo Pengujian (Klik untuk isi cepat):
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                <button type="button" class="btn-demo" onclick="fillLogin('admin','admin123')">Admin</button>
                <button type="button" class="btn-demo" onclick="fillLogin('pemeriksa','pemeriksa123')">Pemeriksa</button>
                <button type="button" class="btn-demo" onclick="fillLogin('pengampu','pengampu123')">Pengampu</button>
                <button type="button" class="btn-demo" onclick="fillLogin('pic','pic123')">PIC Kerja Sama</button>
                <button type="button" class="btn-demo" onclick="fillLogin('validator','validator123')">Validator</button>
                <button type="button" class="btn-demo" onclick="fillLogin('pimpinan','pimpinan123')">Pimpinan</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="login-hint" style="margin-top:10px;">Hubungi admin jika lupa kata sandi.</div>
    </div>
</div>
<?php if (defined('APP_ENV') && APP_ENV === 'development'): ?>
<script>
function fillLogin(u, p) {
    document.getElementById('username').value = u;
    document.getElementById('password').value = p;
}
</script>
<?php endif; ?>
</body>
</html>
