<?php
require_once __DIR__ . '/includes/auth.php';

// Wajib login
requireLogin();

$user = currentUser();
if (!$user) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Sesi login telah berakhir. Silakan login kembali.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'Metode request tidak diizinkan.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$csrfToken = $_POST['csrf_token'] ?? null;
if (!verifyCsrfToken($csrfToken)) {
    echo json_encode(['success' => false, 'error' => 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.', 'csrf_token' => csrfToken()]);
    exit;
}

$rawNama = isset($_POST['nama']) && is_string($_POST['nama']) ? $_POST['nama'] : '';
$nama = trim($rawNama);

if ($nama === '') {
    echo json_encode(['success' => false, 'error' => 'Nama lengkap wajib diisi.', 'csrf_token' => csrfToken()]);
    exit;
}

if (mb_strlen($nama) > 150) {
    echo json_encode(['success' => false, 'error' => 'Nama lengkap maksimal 150 karakter.', 'csrf_token' => csrfToken()]);
    exit;
}

$pdo = getDB();
$userId = (int)$user['id'];
$currentFoto = $user['foto'] ?? null;
$newFoto = $currentFoto;

// Handle remove photo request
$removeFoto = !empty($_POST['remove_foto']) && $_POST['remove_foto'] === '1';
if ($removeFoto) {
    if ($currentFoto && file_exists(__DIR__ . '/' . $currentFoto)) {
        @unlink(__DIR__ . '/' . $currentFoto);
    }
    $newFoto = null;
}

// Handle upload new photo
if (!empty($_FILES['foto']) && is_array($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['foto'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errMsgs = [
            UPLOAD_ERR_INI_SIZE   => 'Ukuran foto melebihi batas upload server.',
            UPLOAD_ERR_FORM_SIZE  => 'Ukuran foto melebihi batas form.',
            UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak tersedia.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk server.',
        ];
        $msg = $errMsgs[$file['error']] ?? 'Gagal mengunggah foto profil.';
        echo json_encode(['success' => false, 'error' => $msg, 'csrf_token' => csrfToken()]);
        exit;
    }

    // Limit size to 3MB
    if ($file['size'] > 3 * 1024 * 1024) {
        echo json_encode(['success' => false, 'error' => 'Ukuran foto maksimal 3 MB.', 'csrf_token' => csrfToken()]);
        exit;
    }

    // Validate MIME type & image info
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedMimes[$mime])) {
        echo json_encode(['success' => false, 'error' => 'Format file tidak didukung. Harap unggah foto berekstensi JPG, PNG, atau WebP.', 'csrf_token' => csrfToken()]);
        exit;
    }

    // Double-check image validity with getimagesize
    $imgSize = @getimagesize($file['tmp_name']);
    if (!$imgSize) {
        echo json_encode(['success' => false, 'error' => 'File yang diunggah bukan format gambar yang valid.', 'csrf_token' => csrfToken()]);
        exit;
    }

    $ext = $allowedMimes[$mime];
    $uploadDir = __DIR__ . '/public/uploads/avatars';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    $filename = 'avatar_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $filename;
    $relPath = 'public/uploads/avatars/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        echo json_encode(['success' => false, 'error' => 'Gagal menyimpan foto profil ke direktori server.', 'csrf_token' => csrfToken()]);
        exit;
    }

    // Delete previous photo if exists
    if ($currentFoto && file_exists(__DIR__ . '/' . $currentFoto)) {
        @unlink(__DIR__ . '/' . $currentFoto);
    }

    $newFoto = $relPath;
}

try {
    $stmt = $pdo->prepare('UPDATE users SET nama = ?, foto = ? WHERE id = ?');
    $stmt->execute([$nama, $newFoto, $userId]);

    // Update current session
    $_SESSION['user']['nama'] = $nama;
    $_SESSION['user']['foto'] = $newFoto;
    currentUser(true); // refresh static cache

    logAudit(null, $userId, 'PROFILE_UPDATE', "Memperbarui profil pengguna (Nama: {$nama}, Foto: " . ($newFoto ? 'Ada' : 'Kosong') . ")");

    // Generate initials for fallback
    $parts = preg_split('/\s+/', trim($nama));
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= strtoupper(substr(end($parts), 0, 1));
    }

    $photoUrl = $newFoto ? ($newFoto . '?v=' . time()) : null;

    echo json_encode([
        'success'    => true,
        'message'    => 'Profil berhasil diperbarui!',
        'nama'       => $nama,
        'foto'       => $photoUrl,
        'has_foto'   => !empty($newFoto),
        'initials'   => $initials,
        'csrf_token' => csrfToken()
    ]);
    exit;
} catch (Throwable $e) {
    error_log('profile_update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Terjadi kesalahan sistem saat menyimpan profil.', 'csrf_token' => csrfToken()]);
    exit;
}
