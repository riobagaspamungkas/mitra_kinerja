<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$canEdit = in_array($user['role'], ['admin', 'pemeriksa'], true);

$errors = [];
$success = '';

// Handle create
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!$canEdit) { $errors[] = 'Tidak memiliki akses.'; }
    else {
        $mitraId = (int)($_POST['mitra_id'] ?? 0);
        $tindakan = trim($_POST['tindakan'] ?? '');
        $tenggat = $_POST['tenggat'] ?? null;
        if ($mitraId <= 0 || $tindakan === '') {
            $errors[] = 'Pilih naskah dan isi tindakan.';
        } else {
            // Upload File Bukti Kegiatan
            $fileBukti = null;
            if (isset($_FILES['file_bukti']) && $_FILES['file_bukti']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['file_bukti']['name'], PATHINFO_EXTENSION));
                $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt'];
                if (!in_array($ext, $allowedExts, true)) {
                    $errors[] = 'Format file bukti tidak didukung. Gunakan PDF, JPG, PNG, DOCX, XLSX, atau PPTX.';
                } elseif ($_FILES['file_bukti']['size'] > 25 * 1024 * 1024) {
                    $errors[] = 'Ukuran file bukti melebihi batas maksimum 25MB.';
                } else {
                    // Validasi keaslian file (magic bytes)
                    $tmpPath = $_FILES['file_bukti']['tmp_name'];
                    $magicOk = true;
                    if ($ext === 'pdf') {
                        $magicOk = function_exists('isPdfValid') ? isPdfValid($tmpPath) : (str_starts_with((string)file_get_contents($tmpPath, false, null, 0, 5), '%PDF-'));
                    } elseif (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                        $magicOk = function_exists('isImageValid') ? isImageValid($tmpPath) : (@getimagesize($tmpPath) !== false);
                    }
                    if (!$magicOk) {
                        $errors[] = 'File tidak lolos validasi keaslian (magic-byte). Pastikan file tidak rusak atau dimanipulasi.';
                    } else {
                        $uploadDir = __DIR__ . '/public/uploads/tindak_lanjut/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }
                        $cleanBase = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($_FILES['file_bukti']['name'], PATHINFO_FILENAME));
                        $targetFile = 'bukti_' . $mitraId . '_' . time() . '_' . $cleanBase . '.' . $ext;
                        if (move_uploaded_file($_FILES['file_bukti']['tmp_name'], $uploadDir . $targetFile)) {
                            $fileBukti = 'public/uploads/tindak_lanjut/' . $targetFile;
                        } else {
                            $errors[] = 'Gagal menyimpan file bukti kegiatan di server.';
                        }
                    }
                }
            }

            if (empty($errors)) {
                $stmt = $pdo->prepare('INSERT INTO tindak_lanjut (mitra_id, tindakan, tenggat, file_bukti) VALUES (?, ?, ?, ?)');
                $stmt->execute([$mitraId, $tindakan, $tenggat ?: null, $fileBukti]);
                logAudit($mitraId, $user['id'], 'TINDAK_LANJUT', 'Tambah tindak lanjut baru: ' . $tindakan);
                $success = 'Tindak lanjut berhasil ditambahkan.';
            }
        }
    }
}

// Handle status update
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    if (!$canEdit) { $errors[] = 'Tidak memiliki akses.'; }
    else {
        $tlId = (int)($_POST['tl_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if (in_array($newStatus, ['Belum', 'Proses', 'Selesai'], true)) {
            $stmt = $pdo->prepare('UPDATE tindak_lanjut SET status = ? WHERE id = ?');
            $stmt->execute([$newStatus, $tlId]);
            $success = 'Status tindak lanjut diperbarui.';
        }
    }
}

$filterMitraId = !empty($_GET['mitra_id']) ? (int)$_GET['mitra_id'] : null;
$filteredMitra = null;
if ($filterMitraId > 0) {
    $stmtFM = $pdo->prepare('SELECT kode, nama_mitra FROM mitra_kinerja WHERE id = ?');
    $stmtFM->execute([$filterMitraId]);
    $filteredMitra = $stmtFM->fetch();
}

$tindakLanjut = getTindakLanjut($pdo, $filterMitraId);
$allMitra = $pdo->query('SELECT id, kode, nama_mitra, judul, bidang FROM mitra_kinerja ORDER BY kode')->fetchAll();

$pageTitle = 'Tindak Lanjut';
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Tindak Lanjut</h1>
        <div class="muted" style="font-size:13px;">
            <?php if ($filteredMitra): ?>
                Menampilkan tindak lanjut khusus naskah: <strong><?= h($filteredMitra['kode']) ?> &mdash; <?= h($filteredMitra['nama_mitra']) ?></strong>
            <?php else: ?>
                Pantau dan kelola progres perbaikan per naskah
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <?php if ($filteredMitra): ?>
            <a href="tindak_lanjut.php" class="btn btn-outline btn-sm">Tampilkan Semua</a>
            <a href="baseline.php?id=<?= $filterMitraId ?>" class="btn btn-outline btn-sm">&larr; Kembali ke Baseline</a>
        <?php else: ?>
            <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-info"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

<?php if ($canEdit): ?>
<div class="card">
    <h2>Tambah Tindak Lanjut</h2>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field" style="grid-column: span 2;">
                <label>Naskah / Judul Kerja Sama * (Ketik untuk mencari)</label>
                <select name="mitra_id" required class="searchable-select" placeholder="Ketik nama mitra / kode PKS / pilih naskah...">
                    <option value="">Pilih Naskah...</option>
                    <?php foreach ($allMitra as $m): ?>
                    <option value="<?= $m['id'] ?>" data-sub="Judul: <?= h(singkat($m['judul'] ?: '-', 50)) ?> | Bidang: <?= h($m['bidang'] ?? 'AHU') ?>" <?= $filterMitraId === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= h($m['kode']) ?> &mdash; <?= h($m['nama_mitra']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="muted" style="font-size:11px;margin-top:2px;">Ketik kata kunci untuk memfilter saran naskah secara instan.</div>
            </div>
            <div class="field"><label>Tenggat</label><input type="date" name="tenggat"></div>
            <div class="field" style="grid-column: 1 / -1;"><label>Tindakan *</label><input type="text" name="tindakan" required placeholder="Deskripsi kegiatan atau tindak lanjut"></div>
            <div class="field" style="grid-column: 1 / -1;">
                <label>Bukti Kegiatan (PDF, JPG, PNG, DOCX, XLSX, PPTX)</label>
                <input type="file" name="file_bukti" accept=".pdf,.jpg,.jpeg,.png,.docx,.doc,.xlsx,.xls,.pptx,.ppt">
                <div class="muted" style="font-size:11px;margin-top:2px;">Unggah dokumen hasil, notula, laporan, atau foto dokumentasi (maks. 25MB).</div>
            </div>
        </div>
        <div style="margin-top:14px;"><button type="submit" class="btn btn-primary">Tambah Tindak Lanjut</button></div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Daftar Tindak Lanjut</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Naskah</th><th>Tindakan</th><th>Tenggat</th><th>Status</th><th>Bukti Kegiatan</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (empty($tindakLanjut)): ?>
            <tr><td colspan="6" class="muted" style="text-align:center;padding:20px;">Belum ada tindak lanjut</td></tr>
        <?php else: foreach ($tindakLanjut as $tl):
            $dotClass = match($tl['status']) { 'Selesai' => 'dot-green', 'Proses' => 'dot-yellow', default => 'dot-red' };
        ?>
            <tr>
                <td><strong><?= h($tl['kode']) ?></strong> <span class="muted" style="font-size:11px;"><?= h(singkat($tl['nama_mitra'], 30)) ?></span></td>
                <td><?= h($tl['tindakan']) ?></td>
                <td><?= formatTanggal($tl['tenggat']) ?></td>
                <td><span class="status-dot <?= $dotClass ?>"><?= h($tl['status']) ?></span></td>
                <td>
                    <?php if (!empty($tl['file_bukti'])): 
                        $fb = $tl['file_bukti'];
                        if (preg_match('/^https?:\/\//i', $fb)):
                            $isDrive = str_contains($fb, 'google.com');
                            $isP2ma = str_contains($fb, 'p2ma');
                            $btnLabel = $isDrive ? '📁 Google Drive' : ($isP2ma ? '🌐 P2MA' : '🔗 Tautan');
                            $btnStyle = $isDrive ? 'color:#059669;border-color:#a7f3d0;background:#ecfdf5;' : 'color:#1e40af;border-color:#bfdbfe;background:#eff6ff;';
                    ?>
                        <a href="<?= h($fb) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;<?= $btnStyle ?>">
                            <?= $btnLabel ?> &rarr;
                        </a>
                    <?php elseif (file_exists(__DIR__ . '/' . $fb) || str_starts_with($fb, 'public/uploads/')): ?>
                        <a href="<?= h($fb) ?>" target="_blank" download class="btn btn-outline btn-sm" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;">
                            📄 Unduh Bukti
                        </a>
                    <?php else: ?>
                        <span class="badge badge-secondary" style="font-size:10.5px;max-width:180px;white-space:normal;text-align:left;display:inline-block;line-height:1.25;"><?= h($fb) ?></span>
                    <?php endif; else: ?>
                    <span class="muted" style="font-size:11.5px;">-</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($canEdit && $tl['status'] !== 'Selesai'): ?>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="tl_id" value="<?= $tl['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= $tl['status'] === 'Belum' ? 'Proses' : 'Selesai' ?>">
                        <button type="submit" class="btn btn-outline btn-sm"><?= $tl['status'] === 'Belum' ? 'Mulai' : 'Selesai' ?></button>
                    </form>
                    <?php elseif ($tl['status'] === 'Selesai'): ?>
                    <span class="muted" style="font-size:11.5px;">✓ Tuntas</span>
                    <?php else: ?>
                    <span class="muted" style="font-size:11.5px;">-</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>