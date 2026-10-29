<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$canEdit = in_array($user['role'], ['admin', 'pemeriksa'], true);

$errors = [];
$success = '';

// Handle POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        $action = $_POST['action'] ?? '';

        // Handle create
        if ($action === 'create') {
            if (!$canEdit) { $errors[] = 'Tidak memiliki akses.'; }
            else {
                $mitraId = (int)($_POST['mitra_id'] ?? 0);
                $tindakan = trim($_POST['tindakan'] ?? '');
                $tenggat = $_POST['tenggat'] ?? null;
                if ($mitraId <= 0 || $tindakan === '') {
                    $errors[] = 'Pilih naskah dan isi tindakan.';
                } else {
                    // FK validation
                    $chk = $pdo->prepare('SELECT id FROM mitra_kinerja WHERE id = ?');
                    $chk->execute([$mitraId]);
                    if (!$chk->fetch()) {
                        $errors[] = 'Naskah yang dipilih tidak valid atau tidak ditemukan dalam database.';
                    } else {
                        // Upload File Bukti Kegiatan
                        $fileBukti = null;
                        if (isset($_FILES['file_bukti'])) {
                            if ($_FILES['file_bukti']['error'] !== UPLOAD_ERR_OK && $_FILES['file_bukti']['error'] !== UPLOAD_ERR_NO_FILE) {
                                $errCode = $_FILES['file_bukti']['error'];
                                $uploadErrMap = [
                                    UPLOAD_ERR_INI_SIZE => 'Ukuran file melebihi upload_max_filesize pada server.',
                                    UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas form HTML.',
                                    UPLOAD_ERR_PARTIAL => 'File hanya terunggah sebagian. Silakan coba lagi.',
                                    UPLOAD_ERR_NO_TMP_DIR => 'Direktori sementara (tmp) server tidak ditemukan.',
                                    UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk server.',
                                    UPLOAD_ERR_EXTENSION => 'Unggahan file dihentikan oleh ekstensi PHP.',
                                ];
                                $errors[] = 'Gagal mengunggah file bukti: ' . ($uploadErrMap[$errCode] ?? "Kode error upload: $errCode");
                            } elseif ($_FILES['file_bukti']['error'] === UPLOAD_ERR_OK) {
                                $ext = strtolower(pathinfo($_FILES['file_bukti']['name'], PATHINFO_EXTENSION));
                                $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt'];
                                if (!in_array($ext, $allowedExts, true)) {
                                    $errors[] = 'Format file bukti tidak didukung. Gunakan PDF, JPG, PNG, DOCX, XLSX, atau PPTX.';
                                } elseif ($_FILES['file_bukti']['size'] > 25 * 1024 * 1024) {
                                    $errors[] = 'Ukuran file bukti melebihi batas maksimum 25MB.';
                                } else {
                                    // Validasi keaslian file (magic bytes)
                                    $tmpPath = $_FILES['file_bukti']['tmp_name'];
                                    $magicOk = false;
                                    if ($ext === 'pdf') {
                                        $magicOk = function_exists('isPdfValid') ? isPdfValid($tmpPath) : (str_starts_with((string)file_get_contents($tmpPath, false, null, 0, 4), '%PDF'));
                                    } elseif (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                                        $magicOk = function_exists('isImageValid') ? isImageValid($tmpPath) : (@getimagesize($tmpPath) !== false);
                                    } elseif (in_array($ext, ['docx', 'xlsx', 'pptx'], true)) {
                                        $header = (string)file_get_contents($tmpPath, false, null, 0, 4);
                                        $magicOk = str_starts_with($header, "PK\x03\x04") || str_starts_with($header, 'PK');
                                    } elseif (in_array($ext, ['doc', 'xls', 'ppt'], true)) {
                                        $header = (string)file_get_contents($tmpPath, false, null, 0, 4);
                                        $magicOk = str_starts_with($header, "\xD0\xCF\x11\xE0") || str_starts_with($header, 'PK');
                                    }
                                    if (!$magicOk) {
                                        $errors[] = 'File tidak lolos validasi keaslian (magic-byte). Pastikan file tidak rusak atau dimanipulasi.';
                                    } else {
                                        $uploadDir = __DIR__ . '/public/uploads/tindak_lanjut/';
                                        if (!is_dir($uploadDir)) {
                                            mkdir($uploadDir, 0775, true);
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
                        }

                        if (empty($errors)) {
                            // try/catch on insert
                            try {
                                $stmt = $pdo->prepare('INSERT INTO tindak_lanjut (mitra_id, tindakan, tenggat, file_bukti) VALUES (?, ?, ?, ?)');
                                $stmt->execute([$mitraId, $tindakan, $tenggat ?: null, $fileBukti]);
                                logAudit($mitraId, $user['id'], 'TINDAK_LANJUT', 'Tambah tindak lanjut baru: ' . $tindakan);
                                $success = 'Tindak lanjut berhasil ditambahkan.';
                            } catch (Throwable $e) {
                                $errors[] = 'Gagal menyimpan tindak lanjut: ' . $e->getMessage();
                            }
                        }
                    }
                }
            }
        }

        // Handle status update (BUG-TL-04: allow re-opening completed tasks or status toggling; Bug 4: verify existence)
        elseif ($action === 'update_status') {
            if (!$canEdit) { $errors[] = 'Tidak memiliki akses.'; }
            else {
                $tlId = (int)($_POST['tl_id'] ?? 0);
                $newStatus = $_POST['new_status'] ?? '';
                if (in_array($newStatus, ['Belum', 'Proses', 'Selesai'], true)) {
                    try {
                        $stmtG = $pdo->prepare('SELECT mitra_id, tindakan FROM tindak_lanjut WHERE id = ?');
                        $stmtG->execute([$tlId]);
                        $tlRow = $stmtG->fetch();
                        if (!$tlRow) {
                            $errors[] = 'Data tindak lanjut tidak ditemukan.';
                        } else {
                            $stmt = $pdo->prepare('UPDATE tindak_lanjut SET status = ? WHERE id = ?');
                            $stmt->execute([$newStatus, $tlId]);
                            logAudit($tlRow['mitra_id'], $user['id'], 'TINDAK_LANJUT', 'Ubah status tindak lanjut #' . $tlId . ' menjadi ' . $newStatus);
                            $success = 'Status tindak lanjut diperbarui menjadi: ' . $newStatus;
                        }
                    } catch (Throwable $e) {
                        $errors[] = 'Gagal memperbarui status: ' . $e->getMessage();
                    }
                }
            }
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
// Completed tasks (Selesai) placed at the bottom; valid imminent deadlines before NULL or '0000-00-00'
usort($tindakLanjut, function($a, $b) {
    $isDoneA = ($a['status'] ?? '') === 'Selesai';
    $isDoneB = ($b['status'] ?? '') === 'Selesai';
    if ($isDoneA !== $isDoneB) {
        return $isDoneA ? 1 : -1;
    }

    $hasDateA = !empty($a['tenggat']) && $a['tenggat'] !== '0000-00-00' && $a['tenggat'] > '1970-01-01';
    $hasDateB = !empty($b['tenggat']) && $b['tenggat'] !== '0000-00-00' && $b['tenggat'] > '1970-01-01';
    if ($hasDateA && !$hasDateB) return -1;
    if (!$hasDateA && $hasDateB) return 1;
    if (!$hasDateA && !$hasDateB) return $b['id'] <=> $a['id'];

    if ($a['tenggat'] === $b['tenggat']) {
        return $b['id'] <=> $a['id'];
    }
    return strcmp($a['tenggat'], $b['tenggat']);
});
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
            <?php elseif ($filterMitraId > 0): ?>
                <span style="color:#b91c1c;">Naskah kerja sama dengan ID <?= $filterMitraId ?> tidak ditemukan.</span>
            <?php else: ?>
                Pantau dan kelola progres perbaikan per naskah
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <?php if ($filteredMitra): ?>
            <a href="tindak_lanjut.php" class="btn btn-outline btn-sm">Tampilkan Semua</a>
            <a href="baseline.php?id=<?= $filterMitraId ?>" class="btn btn-outline btn-sm">&larr; Kembali ke Baseline</a>
        <?php elseif ($filterMitraId > 0): ?>
            <a href="tindak_lanjut.php" class="btn btn-primary btn-sm">Tampilkan Semua / Reset Filter</a>
            <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
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
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="field" style="grid-column: span 2;">
                <label>Naskah / Judul Kerja Sama * (Ketik untuk mencari)</label>
                <select name="mitra_id" class="searchable-select" placeholder="Ketik nama mitra / kode PKS / pilih naskah..." required>
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
    <div class="flex-between" style="margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:0;">Daftar Tindak Lanjut</h2>
    </div>

    <div class="filters" style="margin-bottom:16px;">
        <!-- Search Input -->
        <div style="position:relative;display:inline-block;min-width:240px;">
            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none;display:inline-flex;align-items:center;">
                <?= uiIcon('search', 14) ?>
            </span>
            <input type="text" id="tlSearch" placeholder="Cari kode / mitra / tindakan / bukti..." style="padding:7px 10px 7px 32px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box;">
        </div>

        <!-- Filter Status -->
        <select id="tlFilterStatus">
            <option value="">Semua Status</option>
            <option value="Belum">Belum</option>
            <option value="Proses">Proses</option>
            <option value="Selesai">Selesai</option>
        </select>

        <!-- Filter Tenggat Waktu -->
        <select id="tlFilterTenggat">
            <option value="">Semua Tenggat Waktu</option>
            <option value="terlewat">🚨 Terlewat / Overdue</option>
            <option value="mendekati">⚠️ Mendekati (&lt; 30 Hari)</option>
            <option value="aman">✓ Aman / Masih Panjang</option>
            <option value="tanpa_tenggat">Tanpa Tenggat</option>
        </select>

        <button type="button" id="tlBtnReset" onclick="resetTlFilters()" class="btn btn-outline btn-sm" style="padding:7px 12px;font-size:12.5px;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;cursor:pointer;" title="Reset Semua Filter">
            <?= uiIcon('refresh', 13) ?> Reset Filter
        </button>
    </div>

    <div class="table-wrap">
    <table id="tblTindakLanjut">
        <thead><tr><th>Naskah</th><th>Tindakan</th><th>Tenggat</th><th>Status</th><th>Bukti Kegiatan</th><th>Aksi</th></tr></thead>
        <tbody>
        <tr id="tlNoMatchRow" style="display:none;"><td colspan="6"><?= uiEmptyState('Tidak Ada Tindak Lanjut yang Sesuai', 'Tidak ditemukan agenda tindak lanjut yang cocok dengan filter pencarian.', 'search') ?></td></tr>
        <?php if (empty($tindakLanjut)): ?>
            <tr><td colspan="6"><?= uiEmptyState('Belum Ada Tindak Lanjut', 'Belum ada agenda atau catatan tindak lanjut yang tercatat pada sistem.', 'file-text') ?></td></tr>
        <?php else: foreach ($tindakLanjut as $tl):
            $dotClass = match($tl['status']) { 'Selesai' => 'dot-green', 'Proses' => 'dot-yellow', default => 'dot-red' };
            $tenggatType = 'tanpa_tenggat';
            if (!empty($tl['tenggat']) && $tl['tenggat'] !== '0000-00-00') {
                $diffDays = (int)floor((strtotime($tl['tenggat']) - time()) / 86400);
                if ($diffDays < 0 && ($tl['status'] ?? '') !== 'Selesai') {
                    $tenggatType = 'terlewat';
                } elseif ($diffDays <= 30 && ($tl['status'] ?? '') !== 'Selesai') {
                    $tenggatType = 'mendekati';
                } else {
                    $tenggatType = 'aman';
                }
            }
        ?>
            <tr data-kode="<?= h(strtoupper($tl['kode'])) ?>" data-mitra="<?= h(strtolower($tl['nama_mitra'] ?? '')) ?>" data-tindakan="<?= h(strtolower($tl['tindakan'] ?? '')) ?>" data-status="<?= h($tl['status']) ?>" data-tenggat-type="<?= $tenggatType ?>">
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
                    <?php if ($canEdit): ?>
                        <?php if ($tl['status'] === 'Belum'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="tl_id" value="<?= $tl['id'] ?>">
                            <input type="hidden" name="new_status" value="Proses">
                            <button type="submit" class="btn btn-outline btn-sm">Mulai</button>
                        </form>
                        <?php elseif ($tl['status'] === 'Proses'): ?>
                        <form method="post" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="tl_id" value="<?= $tl['id'] ?>">
                            <input type="hidden" name="new_status" value="Selesai">
                            <button type="submit" class="btn btn-outline btn-sm" style="color:var(--green);border-color:var(--green);">Selesai</button>
                        </form>
                        <?php elseif ($tl['status'] === 'Selesai'): ?>
                        <span class="muted" style="font-size:11.5px;margin-right:6px;">✓ Tuntas</span>
                        <form method="post" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="tl_id" value="<?= $tl['id'] ?>">
                            <input type="hidden" name="new_status" value="Proses">
                            <button type="submit" class="btn btn-outline btn-sm" title="Buka kembali tindak lanjut" style="font-size:11px;padding:3px 6px;">Buka Kembali</button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="muted" style="font-size:11.5px;"><?= $tl['status'] === 'Selesai' ? '✓ Tuntas' : '-' ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <!-- Tindak Lanjut Table Pagination -->
    <div id="tlPaginationWrap" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">
        <div id="tlPageInfo" style="font-size:12.5px;color:#64748b;font-weight:500;">
            Menampilkan 1 – <?= min(10, count($tindakLanjut)) ?> dari <?= count($tindakLanjut) ?> tindak lanjut
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#64748b;">
                <span>Tampilkan:</span>
                <select id="tlPageSize" onchange="changeTlPageSize(this.value)" style="padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:5px;background:#fff;cursor:pointer;">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="all">Semua</option>
                </select>
            </div>
            <div id="tlPaginationNav" style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;"></div>
        </div>
    </div>
</div>

<script>
// Tindak lanjut filter and pagination engine
var tlCurrentPage = 1;
var tlPageSize = 10;
var tlMatchedRows = [];

function applyTlFilters() {
    var q = (document.getElementById('tlSearch').value || '').toLowerCase().trim();
    var s = document.getElementById('tlFilterStatus').value;
    var tg = document.getElementById('tlFilterTenggat').value;

    tlMatchedRows = [];
    var rows = document.querySelectorAll('#tblTindakLanjut tbody tr:not(#tlNoMatchRow)');
    rows.forEach(function(tr) {
        var kode = (tr.dataset.kode || '').toLowerCase();
        var mitra = (tr.dataset.mitra || '').toLowerCase();
        var tindakan = (tr.dataset.tindakan || '').toLowerCase();
        var textContent = tr.textContent.toLowerCase();

        var matchQ = !q || (kode.indexOf(q) !== -1 || mitra.indexOf(q) !== -1 || tindakan.indexOf(q) !== -1 || textContent.indexOf(q) !== -1);
        var matchS = !s || tr.dataset.status === s;
        var matchTg = !tg || tr.dataset.tenggatType === tg;

        var isVisible = (matchQ && matchS && matchTg);
        if (isVisible) {
            tlMatchedRows.push(tr);
        } else {
            tr.style.display = 'none';
        }
    });

    var noMatch = document.getElementById('tlNoMatchRow');
    if (noMatch) {
        noMatch.style.display = (rows.length > 0 && tlMatchedRows.length === 0) ? '' : 'none';
    }

    tlCurrentPage = 1;
    renderTlPagination();
}

function changeTlPageSize(sz) {
    tlPageSize = (sz === 'all') ? 999999 : parseInt(sz, 10);
    tlCurrentPage = 1;
    renderTlPagination();
}

function goToTlPage(p) {
    tlCurrentPage = p;
    renderTlPagination();
}

function renderTlPagination() {
    var total = tlMatchedRows.length;
    var wrap = document.getElementById('tlPaginationWrap');
    var nav = document.getElementById('tlPaginationNav');
    var info = document.getElementById('tlPageInfo');
    if (!wrap || !nav || !info) return;

    if (total === 0) {
        wrap.style.display = 'none';
        return;
    }
    wrap.style.display = 'flex';

    var totalPages = Math.ceil(total / tlPageSize) || 1;
    if (tlCurrentPage > totalPages) tlCurrentPage = totalPages;
    if (tlCurrentPage < 1) tlCurrentPage = 1;

    var start = (tlCurrentPage - 1) * tlPageSize;
    var end = Math.min(start + tlPageSize, total);

    tlMatchedRows.forEach(function(tr, idx) {
        tr.style.display = (idx >= start && idx < end) ? '' : 'none';
    });

    info.textContent = 'Menampilkan ' + (start + 1) + ' – ' + end + ' dari ' + total + ' tindak lanjut';

    nav.innerHTML = '';
    if (totalPages <= 1) return;

    var btnFirst = document.createElement('button');
    btnFirst.type = 'button';
    btnFirst.className = 'tl-page-btn';
    btnFirst.innerHTML = '&laquo;';
    btnFirst.title = 'Halaman Pertama';
    btnFirst.disabled = (tlCurrentPage === 1);
    btnFirst.onclick = function(){ goToTlPage(1); };
    nav.appendChild(btnFirst);

    var btnPrev = document.createElement('button');
    btnPrev.type = 'button';
    btnPrev.className = 'tl-page-btn';
    btnPrev.innerHTML = '&lsaquo;';
    btnPrev.title = 'Sebelumnya';
    btnPrev.disabled = (tlCurrentPage === 1);
    btnPrev.onclick = function(){ goToTlPage(tlCurrentPage - 1); };
    nav.appendChild(btnPrev);

    var maxButtons = 5;
    var startP = Math.max(1, tlCurrentPage - 2);
    var endP = Math.min(totalPages, startP + maxButtons - 1);
    if (endP - startP < maxButtons - 1) {
        startP = Math.max(1, endP - maxButtons + 1);
    }

    for (var p = startP; p <= endP; p++) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tl-page-btn' + (p === tlCurrentPage ? ' active' : '');
        btn.textContent = p;
        (function(page){
            btn.onclick = function(){ goToTlPage(page); };
        })(p);
        nav.appendChild(btn);
    }

    var btnNext = document.createElement('button');
    btnNext.type = 'button';
    btnNext.className = 'tl-page-btn';
    btnNext.innerHTML = '&rsaquo;';
    btnNext.title = 'Berikutnya';
    btnNext.disabled = (tlCurrentPage === totalPages);
    btnNext.onclick = function(){ goToTlPage(tlCurrentPage + 1); };
    nav.appendChild(btnNext);

    var btnLast = document.createElement('button');
    btnLast.type = 'button';
    btnLast.className = 'tl-page-btn';
    btnLast.innerHTML = '&raquo;';
    btnLast.title = 'Halaman Terakhir';
    btnLast.disabled = (tlCurrentPage === totalPages);
    btnLast.onclick = function(){ goToTlPage(totalPages); };
    nav.appendChild(btnLast);
}

function resetTlFilters() {
    document.getElementById('tlSearch').value = '';
    document.getElementById('tlFilterStatus').value = '';
    document.getElementById('tlFilterTenggat').value = '';
    applyTlFilters();
}

document.getElementById('tlSearch').addEventListener('input', applyTlFilters);
document.getElementById('tlFilterStatus').addEventListener('change', applyTlFilters);
document.getElementById('tlFilterTenggat').addEventListener('change', applyTlFilters);

document.addEventListener('DOMContentLoaded', applyTlFilters);
window.addEventListener('pageshow', applyTlFilters);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>