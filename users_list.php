<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin']);

$pdo = getDB();
$errors = [];
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $nama = trim($_POST['nama'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    if ($nama === '' || $username === '' || strlen($password) < 6 || !in_array($role, ['admin','pemeriksa','validator','pimpinan','pengampu','pic'], true)) {
        $errors[] = 'Lengkapi semua kolom. Password minimal 6 karakter.';
    } else {
        try {
            $stmt = $pdo->prepare('INSERT INTO users (nama, username, password_hash, role) VALUES (?,?,?,?)');
            $stmt->execute([$nama, $username, password_hash($password, PASSWORD_BCRYPT), $role]);
            $success = 'Pengguna baru berhasil dibuat.';
        } catch (PDOException $e) {
            $errors[] = str_contains($e->getMessage(), 'Duplicate') ? 'Username sudah dipakai.' : 'Gagal membuat pengguna.';
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $currUser = currentUser();
    if ($uid === (int)($currUser['id'] ?? 0)) {
        $errors[] = 'Anda tidak dapat menonaktifkan akun Anda sendiri saat sedang login.';
    } else {
        $stmt = $pdo->prepare('UPDATE users SET aktif = 1 - aktif WHERE id = ?');
        $stmt->execute([$uid]);
        $success = 'Status pengguna berhasil diperbarui.';
    }
}

$users = $pdo->query('SELECT * FROM users ORDER BY role, nama')->fetchAll();

$pageTitle = 'Pengguna';
require __DIR__ . '/includes/header.php';
?>

<h1 style="font-size:20px;">Manajemen Pengguna</h1>

<?php if ($success): ?><div class="alert alert-info"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

<div class="card">
    <h2>Tambah Pengguna</h2>
    <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field"><label>Nama</label><input type="text" name="nama" required></div>
            <div class="field"><label>Username</label><input type="text" name="username" required></div>
            <div class="field"><label>Password</label><input type="password" name="password" required minlength="6"></div>
            <div class="field">
                <label>Role</label>
                <select name="role">
                    <option value="pemeriksa">Pemeriksa</option>
                    <option value="validator">Validator</option>
                    <option value="pimpinan">Pimpinan</option>
                    <option value="pengampu">Pengampu</option>
                    <option value="pic">PIC Kerja Sama</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
        </div>
        <div style="margin-top:14px;"><button type="submit" class="btn btn-primary">Tambah</button></div>
    </form>
</div>

<div class="card">
    <h2>Daftar Pengguna</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= h($u['nama']) ?></td>
            <td><?= h($u['username']) ?></td>
            <td><span class="badge badge-secondary"><?= h(ucfirst($u['role'])) ?></span></td>
            <td><span class="badge badge-<?= $u['aktif'] ? 'success' : 'danger' ?>"><?= $u['aktif'] ? 'Aktif' : 'Nonaktif' ?></span></td>
            <td>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn btn-outline btn-sm"><?= $u['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
