<?php
require_once __DIR__ . '/includes/auth.php';

$user = currentUser();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Enforce that logout only processes POST requests with valid CSRF token.
// If accessed via GET, render a simple confirmation form with CSRF token to prevent Logout CSRF (CWE-352).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if ($token === null || !verifyCsrfToken($token)) {
        http_response_code(403);
        die('Token CSRF tidak valid atau telah kedaluwarsa.');
    }

    doLogout();
    $target = (!empty($_POST['action']) && $_POST['action'] === 'switch') ? 'login.php?switch=1' : 'login.php';
    header('Location: ' . $target);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfirmasi Keluar — Mitra Kinerja</title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card" style="text-align:center;">
        <div class="login-brand">
            <div class="logo-circle">MK</div>
            <h1>MITRA KINERJA</h1>
            <p>Konfirmasi Keluar Sesi</p>
        </div>
        <p style="margin:20px 0;color:#475569;font-size:14px;line-height:1.5;">
            Apakah Anda yakin ingin keluar dari akun <strong><?= h($user['nama'] ?? '') ?></strong>?
        </p>
        <form method="POST" action="logout.php">
            <?= csrfField() ?>
            <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;width:100%;margin-bottom:12px;">
                Ya, Keluar
            </button>
        </form>
        <div>
            <a href="dashboard.php" style="display:inline-block;padding:8px 16px;color:#64748b;text-decoration:none;font-size:13px;">
                ← Batal, Kembali ke Dashboard
            </a>
        </div>
    </div>
</div>
</body>
</html>
