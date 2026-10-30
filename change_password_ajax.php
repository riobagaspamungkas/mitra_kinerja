<?php
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
if (!$user) {
    echo json_encode(['success' => false, 'message' => 'Sesi telah berakhir, silakan login kembali.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Metode permintaan tidak valid.']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? null;
if (!verifyCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.']);
    exit;
}

$oldPass = $_POST['old_password'] ?? '';
$newPass = $_POST['new_password'] ?? '';
$confirmPass = $_POST['confirm_password'] ?? '';

if (empty($oldPass) || empty($newPass) || empty($confirmPass)) {
    echo json_encode(['success' => false, 'message' => 'Semua kolom password wajib diisi.']);
    exit;
}

if (strlen($newPass) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password baru minimal 6 karakter.']);
    exit;
}

if ($newPass !== $confirmPass) {
    echo json_encode(['success' => false, 'message' => 'Konfirmasi password baru tidak cocok.']);
    exit;
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$user['id']]);
    $currentHash = $stmt->fetchColumn();

    if (!$currentHash || !password_verify($oldPass, $currentHash)) {
        echo json_encode(['success' => false, 'message' => 'Password saat ini salah.']);
        exit;
    }

    $newHash = password_hash($newPass, PASSWORD_BCRYPT);
    $updateStmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $updateStmt->execute([$newHash, (int)$user['id']]);

    logAudit(null, (int)$user['id'], 'UPDATE_PASSWORD', 'Pengguna berhasil mengganti kata sandi mandiri.');

    echo json_encode(['success' => true, 'message' => 'Kata sandi berhasil diperbarui!']);
} catch (Exception $e) {
    error_log('Error changing password: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem saat memperbarui kata sandi.']);
}
