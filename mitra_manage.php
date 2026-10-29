<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireRole(['admin', 'pemeriksa', 'pengampu', 'pic']);

$pdo = getDB();
$user = currentUser();
$userRole = $user['role'] ?? 'pemeriksa';
$id = (int)($_GET['id'] ?? 0);
$errors = [];
$success = '';

$bidangOptions = [
    'AHU'      => 'AHU (Administrasi Hukum Umum)',
    'KI'       => 'KI (Kekayaan Intelektual)',
    'P3H'      => 'P3H (Pelayanan & Pemenuhan HAM)',
    'PPL'      => 'PPL (Peraturan Perundang-undangan)',
    'Keuangan' => 'Keuangan',
    'Humas'    => 'Humas',
    'SDM'      => 'SDM (Kepegawaian)',
];

$mouOptions = $pdo->query("SELECT id, kode, nama_mitra, judul FROM mitra_kinerja WHERE jenis = 'MoU' ORDER BY kode")->fetchAll();

// MODAL QUICK UPLOAD SCAN PDF (dari Listing)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'upload_scan_pdf') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        $targetMitraId = (int)($_POST['target_mitra_id'] ?? 0);
        // Verifikasi target_mitra_id benar-benar ada di database sebelum memproses file
        $stmtM = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
        $stmtM->execute([$targetMitraId]);
        $targetMitra = $stmtM->fetch();
        $kodeMitra = $targetMitra['kode'] ?? null;

        if ($targetMitraId <= 0 || !$targetMitra) {
            $errors[] = 'Naskah kerja sama yang dipilih tidak ditemukan dalam sistem.';
        } else {
            // Enforce finalized lock and pengampu assignment check
            $userRole = $user['role'] ?? 'pengampu';
            $stmtVal = $pdo->prepare('SELECT status FROM validasi WHERE mitra_id = ?');
            $stmtVal->execute([$targetMitraId]);
            $valStatus = $stmtVal->fetchColumn() ?: 'BELUM';
            $isLockedFinal = (in_array($targetMitra['status_scorecard'] ?? '', ['FINAL/TERVALIDASI', 'FINAL'], true) || $valStatus === 'DISETUJUI');
            $isAssigned = (!empty($targetMitra['pemeriksa_id']) && (int)$targetMitra['pemeriksa_id'] === (int)$user['id'])
                || (!empty($targetMitra['pic_internal']) && stripos($targetMitra['pic_internal'], $user['nama'] ?? $user['username'] ?? '') !== false);

            if ($userRole !== 'admin' && $isLockedFinal) {
                $errors[] = 'Akses ditolak: Naskah telah berstatus FINAL/TERVALIDASI atau disetujui validator sehingga dokumen terkunci.';
            } elseif ($userRole === 'pengampu' && !$isAssigned && !empty($targetMitra['pemeriksa_id'])) {
                $errors[] = 'Akses ditolak: Sebagai role pengampu, Anda hanya diizinkan mengunggah berkas untuk naskah yang ditugaskan kepada Anda.';
            } elseif (!isset($_FILES['scan_pdf']) || $_FILES['scan_pdf']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Pilih file scan berkas dalam format PDF yang valid.';
            } else {
                $ext = strtolower(pathinfo($_FILES['scan_pdf']['name'], PATHINFO_EXTENSION));
                if ($ext !== 'pdf' || !isPdfValid($_FILES['scan_pdf']['tmp_name'])) {
                    $errors[] = 'File naskah wajib berformat .PDF asli (dokumen hasil scan fisik bertanda tangan, bukan hasil ketik atau berkas palsu).';
                } else {
                    $safeKodeMitra = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$kodeMitra);
                    $targetName = 'scan_naskah_' . $safeKodeMitra . '_' . time() . '.pdf';
                    $targetPath = __DIR__ . '/public/uploads/' . $targetName;
                    if (move_uploaded_file($_FILES['scan_pdf']['tmp_name'], $targetPath)) {
                        $savedPath = 'public/uploads/' . $targetName;
                        $stmtU = $pdo->prepare('UPDATE mitra_kinerja SET file_naskah = ? WHERE id = ?');
                        $stmtU->execute([$savedPath, $targetMitraId]);

                        // Verifikasi baseline_status !== 'TERVERIFIKASI / DIKUNCI' sebelum menimpa elemen 1 (kecuali admin)
                        $isBaselineLocked = (($targetMitra['baseline_status'] ?? '') === 'TERVERIFIKASI / DIKUNCI');
                        if (!$isBaselineLocked || $userRole === 'admin') {
                            $stmtB = $pdo->prepare("UPDATE baseline_elemen SET link_sumber_bukti = ?, status = 'TERVERIFIKASI' WHERE mitra_id = ? AND nomor_elemen = 1");
                            $stmtB->execute([$savedPath, $targetMitraId]);
                        }

                        logAudit($targetMitraId, $user['id'], 'UPLOAD_SCAN', 'Upload scan naskah PDF: ' . $kodeMitra);
                        $success = 'Berkas scan naskah PDF untuk ' . $kodeMitra . ' berhasil diunggah dan disinkronkan ke Identitas Baseline.';
                    } else {
                        // Tambahkan pesan error jika move_uploaded_file gagal
                        $errors[] = 'Gagal menyimpan berkas scan naskah di server.';
                    }
                }
            }
        }
    }
}

// EDIT (ada ?id=)
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
    $stmt->execute([$id]);
    $mitra = $stmt->fetch();
    if (!$mitra) { http_response_code(404); die('Naskah tidak ditemukan.'); }

    // Enforce finalized lock and pengampu assignment check
    $userRole = $user['role'] ?? 'pengampu';
    $stmtVal = $pdo->prepare('SELECT status FROM validasi WHERE mitra_id = ?');
    $stmtVal->execute([$id]);
    $valStatus = $stmtVal->fetchColumn() ?: 'BELUM';
    $isLockedFinal = (in_array($mitra['status_scorecard'] ?? '', ['FINAL/TERVALIDASI', 'FINAL'], true) || $valStatus === 'DISETUJUI');
    $isAssigned = (!empty($mitra['pemeriksa_id']) && (int)$mitra['pemeriksa_id'] === (int)$user['id'])
        || (!empty($mitra['pic_internal']) && stripos($mitra['pic_internal'], $user['nama'] ?? $user['username'] ?? '') !== false);

    $canManage = in_array($userRole, ['admin', 'pemeriksa'], true)
        || ($userRole === 'pengampu' && ($isAssigned || empty($mitra['pemeriksa_id'])));
    if ($isLockedFinal && $userRole !== 'admin') {
        $canManage = false;
    }

    // Tambah rencana kerja dari edit form
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_rencana_kerja') {
        $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
        if (!verifyCsrfToken($csrfToken)) {
            $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
        } elseif (!$canManage) {
            http_response_code(403);
            die('Akses ditolak: Data naskah telah berstatus FINAL/TERVALIDASI atau disetujui validator, atau role Anda tidak memiliki izin untuk mengubah naskah ini.');
        } else {
            $judulRk = trim($_POST['judul_rencana'] ?? '');
            $ruangRk = trim($_POST['ruang_lingkup'] ?? '');
            $mulaiRk = $_POST['tanggal_mulai'] ?: date('Y-01-01');
            $selesaiRk = ($_POST['tanggal_selesai'] ?? '') ?: date('Y-12-31');
            $statusRk = $_POST['status'] ?? 'Disetujui';

            if ($judulRk === '') {
                $errors[] = 'Judul Rencana Kerja wajib diisi.';
            } elseif ($selesaiRk < $mulaiRk) {
                $errors[] = 'Tanggal selesai rencana kerja harus sama atau setelah tanggal mulai.';
            } else {
                try {
                    $stmtR = $pdo->prepare('INSERT INTO rencana_kerja (mitra_id, judul_rencana, ruang_lingkup, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmtR->execute([$id, $judulRk, $ruangRk, $mulaiRk, $selesaiRk, $statusRk]);
                    logAudit($id, $user['id'], 'ADD_RENCANA_KERJA', 'Tambah Rencana Kerja: ' . $judulRk);
                    $success = 'Rencana Kerja tahunan berhasil ditambahkan.';
                } catch (Throwable $e) {
                    $errors[] = 'Gagal menambahkan rencana kerja: ' . $e->getMessage();
                }
            }
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST['action'])) {
        $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
        if (!verifyCsrfToken($csrfToken)) {
            $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
        } elseif (!$canManage) {
            http_response_code(403);
            die('Akses ditolak: Data naskah telah berstatus FINAL/TERVALIDASI atau disetujui validator, atau role Anda tidak memiliki izin untuk mengubah naskah ini.');
        } else {
            $namaMitra  = trim($_POST['nama_mitra'] ?? '');
            $judul      = trim($_POST['judul'] ?? '');
            $portofolio = $_POST['portofolio'] ?? $mitra['portofolio'];
            $bidang     = $_POST['bidang'] ?? ($mitra['bidang'] ?? 'AHU');
            $jenis      = $_POST['jenis'] ?? $mitra['jenis'];
            $pksIndukId = !empty($_POST['pks_induk_id']) ? (int)$_POST['pks_induk_id'] : null;
            // Cegah referensi diri sendiri sebagai PKS Induk
            if ($pksIndukId === $id) {
                $pksIndukId = null;
            }
            $mulai      = !empty(trim($_POST['tanggal_mulai'] ?? '')) ? trim($_POST['tanggal_mulai']) : null;
            $berakhir   = !empty(trim($_POST['tanggal_berakhir'] ?? '')) ? trim($_POST['tanggal_berakhir']) : null;
            $statusTgl  = $_POST['status_tanggal'] ?? $mitra['status_tanggal'];
            $cutoff     = !empty($_POST['cutoff_date']) ? $_POST['cutoff_date'] : ($mitra['cutoff_date'] ?: ($mitra['tanggal_mulai'] ?: date('Y-m-d')));
            $sumber     = trim($_POST['sumber_baseline'] ?? '');
            $picInternal = trim($_POST['pic_internal'] ?? ($mitra['pic_internal'] ?? ''));
            $picMitra    = trim($_POST['pic_mitra'] ?? ($mitra['pic_mitra'] ?? ''));
            $evaluasiPerTahun = min(12, max(1, !empty($_POST['evaluasi_per_tahun']) ? (int)$_POST['evaluasi_per_tahun'] : 4));

        // Validasi enum values
        $validPortofolio = ['Pilot Utama', 'Cadangan'];
        $validJenis = ['PKS', 'MoU'];
        $validBidang = ['AHU', 'KI', 'P3H', 'PPL', 'Keuangan', 'Humas', 'SDM'];
        $validStatusTanggal = ['TERVERIFIKASI', 'BELUM TERVERIFIKASI'];

        if (!in_array($portofolio, $validPortofolio, true)) {
            $portofolio = $mitra['portofolio'] ?? 'Pilot Utama';
        }
        if (!in_array($jenis, $validJenis, true)) {
            $jenis = $mitra['jenis'] ?? 'PKS';
        }
        if (!in_array($bidang, $validBidang, true)) {
            $bidang = $mitra['bidang'] ?? 'AHU';
        }
        if (!in_array($statusTgl, $validStatusTanggal, true)) {
            $statusTgl = $mitra['status_tanggal'] ?? 'BELUM TERVERIFIKASI';
        }

        // Upload File Naskah (Khusus PDF Scanned)
        $fileNaskah = $mitra['file_naskah'] ?? null;
        $newFileUploaded = false;
        if (isset($_FILES['file_naskah']) && $_FILES['file_naskah']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['file_naskah']['name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf' && isPdfValid($_FILES['file_naskah']['tmp_name'])) {
                $safeKodeEdit = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($mitra['kode'] ?? ''));
                $targetName = 'scan_naskah_' . $safeKodeEdit . '_' . time() . '.pdf';
                $targetPath = __DIR__ . '/public/uploads/' . $targetName;
                if (move_uploaded_file($_FILES['file_naskah']['tmp_name'], $targetPath)) {
                    $fileNaskah = 'public/uploads/' . $targetName;
                    $newFileUploaded = true;
                } else {
                    // Cek hasil move_uploaded_file
                    $errors[] = 'Gagal menyimpan berkas scan naskah di server.';
                }
            } else {
                $errors[] = 'File naskah wajib berformat .PDF asli (hasil scan fisik bertanda tangan, bukan hasil ketik atau berkas palsu).';
            }
        }

        // Upload Foto Kegiatan
        $fotoKerjasama = $mitra['foto_kerjasama'] ?? null;
        if (isset($_FILES['foto_kerjasama']) && $_FILES['foto_kerjasama']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto_kerjasama']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && isImageValid($_FILES['foto_kerjasama']['tmp_name'])) {
                $targetName = 'foto_' . $mitra['kode'] . '_' . time() . '.' . $ext;
                $targetPath = __DIR__ . '/public/uploads/' . $targetName;
                if (move_uploaded_file($_FILES['foto_kerjasama']['tmp_name'], $targetPath)) {
                    $fotoKerjasama = 'public/uploads/' . $targetName;
                } else {
                    // Cek hasil move_uploaded_file
                    $errors[] = 'Gagal menyimpan berkas foto kerja sama di server.';
                }
            } else {
                $errors[] = 'Foto dokumentasi wajib berformat gambar valid (JPG, PNG, atau WebP).';
            }
        }

        if ($namaMitra === '') {
            $errors[] = 'Nama Mitra wajib diisi.';
        }
        if (!empty($mulai) && !empty($berakhir) && (strtotime($berakhir) < strtotime($mulai) || $berakhir < $mulai)) {
            $errors[] = 'Tanggal berakhir tidak boleh lebih awal dari tanggal mulai.';
        }
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $stmtU = $pdo->prepare('UPDATE mitra_kinerja SET nama_mitra=?, judul=?, portofolio=?, bidang=?, jenis=?, pks_induk_id=?, tanggal_mulai=?, tanggal_berakhir=?, status_tanggal=?, cutoff_date=?, sumber_baseline=?, pic_internal=?, pic_mitra=?, evaluasi_per_tahun=?, file_naskah=?, foto_kerjasama=? WHERE id=?');
                $stmtU->execute([$namaMitra, $judul, $portofolio, $bidang, $jenis, $pksIndukId, $mulai, $berakhir, $statusTgl, $cutoff, $sumber, $picInternal, $picMitra, $evaluasiPerTahun, $fileNaskah, $fotoKerjasama, $id]);

                // Sinkronkan/hitung ulang siklus_monev agar milestone sesuai durasi/tanggal kontrak baru
                $keb = hitungKebutuhanScorecard($mulai, $berakhir, $evaluasiPerTahun);
                if (!empty($keb['milestones'])) {
                    $stmtCurSM = $pdo->prepare('SELECT * FROM siklus_monev WHERE mitra_id = ?');
                    $stmtCurSM->execute([$id]);
                    $currentMilestones = $stmtCurSM->fetchAll(PDO::FETCH_ASSOC);
                    $curMap = [];
                    foreach ($currentMilestones as $cm) {
                        $curMap[(int)$cm['siklus_ke']] = $cm;
                    }

                    $validSiklusKe = [];
                    foreach ($keb['milestones'] as $ms) {
                        $ske = (int)$ms['siklus_ke'];
                        $validSiklusKe[] = $ske;
                        $isPast = !empty($ms['is_past']);
                        if (isset($curMap[$ske])) {
                            $curr = $curMap[$ske];
                            $isCompleted = in_array(strtolower(trim($curr['status_siklus'] ?? '')), ['selesai', 'selesai evaluasi'], true);
                            $newStatus = $isCompleted ? 'Selesai' : ($isPast ? 'Perlu Penilaian Segera' : ($curr['status_siklus'] === 'Sedang Dinilai' ? 'Sedang Dinilai' : 'Menunggu'));
                            $stmtUpdSM = $pdo->prepare('UPDATE siklus_monev SET nama_siklus = ?, tanggal_target_evaluasi = ?, status_siklus = ? WHERE id = ?');
                            $stmtUpdSM->execute([$ms['nama'], $ms['target_tgl'], $newStatus, $curr['id']]);
                        } else {
                            $statusSiklus = $isPast ? 'Perlu Penilaian Segera' : 'Menunggu';
                            $stmtInsSM = $pdo->prepare('INSERT INTO siklus_monev (mitra_id, siklus_ke, nama_siklus, tanggal_target_evaluasi, status_siklus) VALUES (?, ?, ?, ?, ?)');
                            $stmtInsSM->execute([$id, $ske, $ms['nama'], $ms['target_tgl'], $statusSiklus]);
                        }
                    }
                    if (!empty($validSiklusKe)) {
                        $inPlaceholders = implode(',', array_fill(0, count($validSiklusKe), '?'));
                        $stmtDelSM = $pdo->prepare("DELETE FROM siklus_monev WHERE mitra_id = ? AND siklus_ke NOT IN ($inPlaceholders) AND status_siklus NOT IN ('Selesai', 'selesai evaluasi')");
                        $stmtDelSM->execute(array_merge([$id], $validSiklusKe));
                    }
                }

                // Hanya perbarui status baseline elemen 1 jika tidak dikunci (atau jika user adalah admin)
                if ($newFileUploaded && $fileNaskah) {
                    $isBaselineLocked = (($mitra['baseline_status'] ?? '') === 'TERVERIFIKASI / DIKUNCI');
                    if (!$isBaselineLocked || $userRole === 'admin') {
                        $stmtB = $pdo->prepare("UPDATE baseline_elemen SET link_sumber_bukti = ?, status = 'TERVERIFIKASI' WHERE mitra_id = ? AND nomor_elemen = 1");
                        $stmtB->execute([$fileNaskah, $id]);
                    }
                }
                syncStatusScorecard($pdo, $id);
                logAudit($id, $user['id'], 'UPDATE_MITRA', 'Data naskah ' . $mitra['kode'] . ' diperbarui');

                $pdo->commit();
                $success = 'Data naskah berhasil diperbarui.';

                $stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
                $stmt->execute([$id]);
                $mitra = $stmt->fetch();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Gagal memperbarui data naskah: ' . $e->getMessage();
            }
        }
        }
    }

    // Ambil daftar rencana kerja untuk naskah ini
    $stmtRK = $pdo->prepare('SELECT * FROM rencana_kerja WHERE mitra_id = ? ORDER BY tanggal_mulai DESC');
    $stmtRK->execute([$id]);
    $listRk = $stmtRK->fetchAll();

    $pageTitle = 'Ubah ' . $mitra['kode'];
    require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:14px;">
    <div><h1 style="margin:0;font-size:20px;"><?= h($mitra['kode']) ?> — Edit Naskah</h1></div>
    <a href="mitra_manage.php" class="btn btn-outline btn-sm">&larr; Manajemen Naskah</a>
</div>
<?php if ($success): ?><div class="alert alert-info"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

<?php if (!$canManage): ?>
<div class="alert alert-warning" style="margin-bottom:14px;">
    🔒 <strong>Naskah Terkunci:</strong> Naskah ini telah berstatus <strong>FINAL/TERVALIDASI</strong> atau disetujui validator, atau akun Anda tidak memiliki izin penugasan untuk mengubah naskah ini. Perubahan dinonaktifkan.
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:20px;">
<form method="post" enctype="multipart/form-data">
<?= csrfField() ?>
<fieldset <?= $canManage ? '' : 'disabled' ?> style="border:none;padding:0;margin:0;">
<div class="form-grid">
    <div class="field"><label>Kode</label><input value="<?= h($mitra['kode']) ?>" disabled></div>
    <div class="field"><label>Portofolio</label><select name="portofolio"><?php foreach (['Pilot Utama','Cadangan'] as $opt): ?><option <?= $mitra['portofolio']===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></div>
    
    <!-- Bidang (Pengganti Jenis) -->
    <div class="field">
        <label>Bidang</label>
        <select name="bidang">
            <?php foreach ($bidangOptions as $bKey => $bLabel): ?>
            <option value="<?= $bKey ?>" <?= ($mitra['bidang'] ?? 'AHU') === $bKey ? 'selected' : '' ?>><?= $bLabel ?></option>
            <?php endforeach; ?>
        </select>
        <div class="muted" style="font-size:11px;margin-top:2px;">Bidang kerja sama di lingkungan Kanwil Kepri.</div>
    </div>

    <!-- Bentuk Naskah -->
    <div class="field"><label>Bentuk Naskah</label><select name="jenis"><?php foreach (['PKS','MoU'] as $opt): ?><option <?= $mitra['jenis']===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></div>

    <div class="field"><label>Nama Mitra</label><input type="text" name="nama_mitra" value="<?= h($mitra['nama_mitra']) ?>" required></div>
    <div class="field" style="grid-column:1/-1;"><label>Judul Naskah</label><input type="text" name="judul" value="<?= h($mitra['judul']) ?>"></div>
    
    <div class="field">
        <label>Kerja Sama Utama (Payung MoU)</label>
        <select name="pks_induk_id">
            <option value="">- Tidak ada (Bukan Turunan) -</option>
            <?php foreach ($mouOptions as $mou): ?>
            <?php if ((int)$mou['id'] === $id) continue; // Hindari memilih diri sendiri sebagai induk ?>
            <option value="<?= $mou['id'] ?>" <?= (int)($mitra['pks_induk_id'] ?? 0) === (int)$mou['id'] ? 'selected' : '' ?>><?= h($mou['kode']) ?> &mdash; <?= h(singkat($mou['nama_mitra'], 35)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field"><label>Tanggal Mulai</label><input type="date" name="tanggal_mulai" value="<?= h($mitra['tanggal_mulai']) ?>"></div>
    <div class="field"><label>Tanggal Berakhir</label><input type="date" name="tanggal_berakhir" value="<?= h($mitra['tanggal_berakhir']) ?>"></div>
    <div class="field">
        <label>Evaluasi Rencana Kerja (Per Tahun)</label>
        <input type="number" name="evaluasi_per_tahun" value="<?= h($mitra['evaluasi_per_tahun'] ?? 4) ?>" min="1" max="12" required>
        <div class="muted" style="font-size:11px;margin-top:2px;">Berapa kali kegiatan evaluasi rencana kerja dilakukan dalam periode 1 tahun (default: 4).</div>
    </div>
    <div class="field"><label>Status Tanggal</label><select name="status_tanggal"><?php foreach (['BELUM TERVERIFIKASI','TERVERIFIKASI'] as $opt): ?><option <?= $mitra['status_tanggal']===$opt?'selected':'' ?>><?= $opt ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Cutoff Date</label><input type="date" name="cutoff_date" value="<?= h($mitra['cutoff_date']) ?>"></div>
    <div class="field"><label>Sumber Baseline</label><input type="text" name="sumber_baseline" value="<?= h($mitra['sumber_baseline']) ?>"></div>
    <div class="field"><label>PIC Internal</label><input type="text" name="pic_internal" value="<?= h($mitra['pic_internal'] ?? '') ?>" placeholder="Nama & kontak PIC Kanwil"></div>
    <div class="field"><label>PIC Mitra</label><input type="text" name="pic_mitra" value="<?= h($mitra['pic_mitra'] ?? '') ?>" placeholder="Nama & kontak PIC Mitra"></div>

    <div class="field">
        <label>Upload Scan Naskah Asli (.PDF)</label>
        <input type="file" name="file_naskah" accept=".pdf">
        <div class="muted" style="font-size:11px;margin-top:2px;">Wajib dokumen fisik hasil scan resmi (bukan hasil ketikan/draft).</div>
        <?php if (!empty($mitra['file_naskah'])): ?>
        <div style="margin-top:6px;"><a href="<?= h($mitra['file_naskah']) ?>" target="_blank" class="btn btn-outline btn-sm">📄 Lihat Naskah Scan PDF</a></div>
        <?php endif; ?>
    </div>
    <div class="field">
        <label>Foto Dokumentasi (JPG/PNG)</label>
        <input type="file" name="foto_kerjasama" accept=".jpg,.jpeg,.png,.webp">
        <?php if (!empty($mitra['foto_kerjasama'])): ?>
        <div style="margin-top:6px;"><a href="<?= h($mitra['foto_kerjasama']) ?>" target="_blank" class="btn btn-outline btn-sm">🖼️ Lihat Foto</a></div>
        <?php endif; ?>
    </div>
</div>
<div style="margin-top:16px;">
    <?php if ($canManage): ?>
    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
    <?php endif; ?>
    <a href="mitra_edit.php?id=<?= $id ?>" class="btn btn-outline" style="margin-left:8px;">Buka Scorecard &rarr;</a>
    <a href="baseline.php?id=<?= $id ?>" class="btn btn-outline" style="margin-left:8px;">Buka Baseline &rarr;</a>
</div>
</fieldset>
</form>
</div>

<!-- Card Rencana Kerja Tahunan (/Tahun) -->
<div class="card">
    <div class="flex-between" style="margin-bottom:14px;">
        <div>
            <h2 style="margin:0;font-size:16px;">Rencana Kerja Tahunan (/Tahun)</h2>
            <div class="muted" style="font-size:12px;">Program dan target operasional turunan naskah per tahun</div>
        </div>
        <?php if ($canManage): ?>
        <button type="button" onclick="document.getElementById('rkAddForm').style.display = document.getElementById('rkAddForm').style.display === 'none' ? 'block' : 'none';" class="btn btn-outline btn-sm">+ Tambah Rencana Kerja</button>
        <?php endif; ?>
    </div>

    <!-- Form Tambah Rencana Kerja Baru -->
    <div id="rkAddForm" style="display:none;background:#f8fafc;padding:16px;border-radius:6px;border:1px solid #e2e8f0;margin-bottom:16px;">
        <h3 style="font-size:14px;margin-top:0;margin-bottom:12px;color:#1e40af;">Formulir Rencana Kerja Baru</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_rencana_kerja">
            <div class="form-grid">
                <div class="field" style="grid-column:1/-1;">
                    <label>Judul Rencana Kerja Tahunan *</label>
                    <input type="text" name="judul_rencana" required placeholder="Contoh: Rencana Aksi Sosialisasi Kekayaan Intelektual Terpadu 2026">
                </div>
                <div class="field">
                    <label>Tanggal Mulai</label>
                    <input type="date" name="tanggal_mulai" value="<?= date('Y-01-01') ?>">
                </div>
                <div class="field">
                    <label>Tanggal Selesai</label>
                    <input type="date" name="tanggal_selesai" value="<?= date('Y-12-31') ?>">
                </div>
                <div class="field">
                    <label>Status Pelaksanaan</label>
                    <select name="status">
                        <option value="Disetujui">Disetujui</option>
                        <option value="Proses Persetujuan">Proses Persetujuan</option>
                        <option value="Draft">Draft</option>
                        <option value="Selesai">Selesai</option>
                    </select>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Ruang Lingkup &amp; Target Kegiatan</label>
                    <textarea name="ruang_lingkup" rows="2" placeholder="Uraikan target kegiatan dan keluaran tahunan yang disepakati..."></textarea>
                </div>
            </div>
            <div style="margin-top:12px;">
                <button type="submit" class="btn btn-primary btn-sm">💾 Simpan Rencana Kerja</button>
                <button type="button" onclick="document.getElementById('rkAddForm').style.display='none'" class="btn btn-outline btn-sm">Batal</button>
            </div>
        </form>
    </div>

    <!-- Tabel Daftar Rencana Kerja -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40%;">Judul Rencana Kerja</th>
                    <th style="width:25%;">Periode Pelaksanaan</th>
                    <th style="width:15%;">Status</th>
                    <th style="width:20%;">Ruang Lingkup</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($listRk)): ?>
                <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:16px;">Belum ada rencana kerja tahunan yang tercatat.</td></tr>
                <?php else: ?>
                <?php foreach ($listRk as $rk): ?>
                <tr>
                    <td><strong><?= h($rk['judul_rencana']) ?></strong></td>
                    <td><?= formatTanggal($rk['tanggal_mulai']) ?> s.d. <?= formatTanggal($rk['tanggal_selesai']) ?></td>
                    <td><span class="badge badge-<?= $rk['status']==='Disetujui'?'success':($rk['status']==='Selesai'?'primary':'warning') ?>"><?= h($rk['status']) ?></span></td>
                    <td style="font-size:11.5px;"><?= nl2br(h($rk['ruang_lingkup'] ?? '-')) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
<?php exit; }

// LISTING + TAMBAH (tanpa ?id=)
$userRole = $user['role'] ?? 'pemeriksa';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST['action'])) {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        $kode       = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtoupper(trim($_POST['kode'] ?? '')));
        $portofolio = $_POST['portofolio'] ?? 'Pilot Utama';
        $namaMitra  = trim($_POST['nama_mitra'] ?? '');
        $judul      = trim($_POST['judul'] ?? '');
        $bidang     = $_POST['bidang'] ?? 'AHU';
        $jenis      = $_POST['jenis'] ?? 'PKS';
        $mulai      = !empty(trim($_POST['tanggal_mulai'] ?? '')) ? trim($_POST['tanggal_mulai']) : null;
        $berakhir   = !empty(trim($_POST['tanggal_berakhir'] ?? '')) ? trim($_POST['tanggal_berakhir']) : null;
        $statusTgl  = $_POST['status_tanggal'] ?? 'BELUM TERVERIFIKASI';
        $evaluasiPerTahun = min(12, max(1, !empty($_POST['evaluasi_per_tahun']) ? (int)$_POST['evaluasi_per_tahun'] : 4));
        $picInternal = trim($_POST['pic_internal'] ?? '');
        $picMitra    = trim($_POST['pic_mitra'] ?? '');

        // Validasi enum values pada pembuatan mitra baru
        $validPortofolio = ['Pilot Utama', 'Cadangan'];
        $validJenis = ['PKS', 'MoU'];
        $validBidang = ['AHU', 'KI', 'P3H', 'PPL', 'Keuangan', 'Humas', 'SDM'];
        $validStatusTanggal = ['TERVERIFIKASI', 'BELUM TERVERIFIKASI'];

        if (!in_array($portofolio, $validPortofolio, true)) {
            $portofolio = 'Pilot Utama';
        }
        if (!in_array($jenis, $validJenis, true)) {
            $jenis = 'PKS';
        }
        if (!in_array($bidang, $validBidang, true)) {
            $bidang = 'AHU';
        }
        if (!in_array($statusTgl, $validStatusTanggal, true)) {
            $statusTgl = 'BELUM TERVERIFIKASI';
        }

    // Upload Scan Naskah PDF jika disertakan
    $fileNaskah = null;
    if (isset($_FILES['file_naskah_pdf']) && $_FILES['file_naskah_pdf']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['file_naskah_pdf']['name'], PATHINFO_EXTENSION));
        if ($ext === 'pdf' && isPdfValid($_FILES['file_naskah_pdf']['tmp_name'])) {
            $safeKode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $kode);
            $targetName = 'scan_naskah_' . $safeKode . '_' . time() . '.pdf';
            $targetPath = __DIR__ . '/public/uploads/' . $targetName;
            if (move_uploaded_file($_FILES['file_naskah_pdf']['tmp_name'], $targetPath)) {
                $fileNaskah = 'public/uploads/' . $targetName;
            } else {
                // Cek hasil move_uploaded_file
                $errors[] = 'Gagal menyimpan berkas scan naskah PDF ke server.';
            }
        } else {
            $errors[] = 'File naskah wajib berformat .PDF asli (hasil scan fisik bertanda tangan, bukan hasil ketik atau berkas palsu).';
        }
    }

    if ($kode === '' || $namaMitra === '') {
        $errors[] = 'Kode dan Nama Mitra wajib diisi.';
    }
    if (!empty($mulai) && !empty($berakhir) && (strtotime($berakhir) < strtotime($mulai) || $berakhir < $mulai)) {
        $errors[] = 'Tanggal berakhir tidak boleh lebih awal dari tanggal mulai.';
    }
    if (empty($errors)) {
        // Bungkus pembuatan mitra multi-tabel dalam transaksi database PDO
        $pdo->beginTransaction();
        try {
            $pksInduk = !empty($_POST['pks_induk_id']) ? (int)$_POST['pks_induk_id'] : null;
            // Set default cutoff_date (tanggal mulai atau hari ini) agar EWS Masa Berlaku berfungsi
            $cutoff = !empty($_POST['cutoff_date']) ? $_POST['cutoff_date'] : ($mulai ?: date('Y-m-d'));

            // Inisialisasi baseline_status ke 'DALAM PROSES' jika berkas scan naskah diunggah (karena elemen 1 terverifikasi)
            $baselineStatusInit = !empty($fileNaskah) ? 'DALAM PROSES' : 'BELUM DIISI';

            $stmt = $pdo->prepare('INSERT INTO mitra_kinerja (kode, portofolio, nama_mitra, judul, bidang, jenis, pks_induk_id, tanggal_mulai, tanggal_berakhir, status_tanggal, cutoff_date, evaluasi_per_tahun, pic_internal, pic_mitra, file_naskah, baseline_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$kode, $portofolio, $namaMitra, $judul, $bidang, $jenis, $pksInduk, $mulai, $berakhir, $statusTgl, $cutoff, $evaluasiPerTahun, $picInternal, $picMitra, $fileNaskah, $baselineStatusInit]);
            $mid = $pdo->lastInsertId();

            // Simpan Rencana Kerja Tahunan Awal jika diisi
            $rkJudul = trim($_POST['rencana_judul'] ?? '');
            if ($rkJudul !== '') {
                $rkTahun = (int)($_POST['rencana_tahun'] ?? date('Y'));
                $rkMulai = $mulai ?: ($rkTahun . '-01-01');
                $rkSelesai = $berakhir ?: ($rkTahun . '-12-31');
                $rkRuang = trim($_POST['rencana_ruang_lingkup'] ?? '');
                $stmtRK = $pdo->prepare('INSERT INTO rencana_kerja (mitra_id, judul_rencana, ruang_lingkup, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, ?, ?, ?)');
                $stmtRK->execute([$mid, $rkJudul, $rkRuang, $rkMulai, $rkSelesai, 'Disetujui']);
            }

            // Inisialisasi Siklus Monev Berkala saat mitra baru dibuat
            $keb = hitungKebutuhanScorecard($mulai, $berakhir, $evaluasiPerTahun);
            if (!empty($keb['milestones'])) {
                foreach ($keb['milestones'] as $ms) {
                    $statusSiklus = !empty($ms['is_past']) ? 'Perlu Penilaian Segera' : 'Menunggu';
                    $pdo->prepare('INSERT INTO siklus_monev (mitra_id, siklus_ke, nama_siklus, tanggal_target_evaluasi, status_siklus) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$mid, $ms['siklus_ke'], $ms['nama'], $ms['target_tgl'], $statusSiklus]);
                }
            }

            // Inisialisasi 7 Indikator V2.1 / V3
            $v2Defaults = [
                ['I1', 'Kejelasan Pengelolaan & Rencana Tindak Lanjut', 10],
                ['I2', 'Implementasi / Tindak Lanjut', 15],
                ['I3', 'Output', 15],
                ['I4', 'Outcome', 20],
                ['I5', 'Kontribusi / Dampak', 20],
                ['I6', 'Evidence & Data', 10],
                ['I7', 'Risiko & Keberlanjutan', 10],
            ];
            foreach ($v2Defaults as $ind) {
                $desc = INDIKATOR_APA_YANG_DINILAI[$ind[0]] ?? $ind[1];
                $pdo->prepare('INSERT INTO indikator_skor (mitra_id, kode_indikator, deskripsi, bobot, referensi_baseline, status_pemeriksaan) VALUES (?, ?, ?, ?, \'Baseline awal\', \'BELUM DITELAAH\')')
                    ->execute([$mid, $ind[0], $desc, $ind[2]]);
            }

            // Inisialisasi 12 Elemen Baseline FIX
            foreach (BASELINE_12_DEFS as $n => $d) {
                $linkInit = ($n === 1 && !empty($fileNaskah)) ? $fileNaskah : '';
                $statusInit = ($n === 1 && !empty($fileNaskah)) ? 'TERVERIFIKASI' : 'BELUM DIISI';
                $pdo->prepare('INSERT INTO baseline_elemen (mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa, sumber_bukti_minimum, link_sumber_bukti, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$mid, $n, $d['kelompok'], $d['nama'], $d['yang_diperiksa'], $d['sumber_minimum'], $linkInit, $statusInit]);
            }

            foreach (['Masa berlaku','Aktivitas/tenggat','Data/eviden','PIC'] as $d) {
                $pdo->prepare("INSERT INTO early_warning (mitra_id,dimensi,status,progres) VALUES (?,?,'V0','BELUM MULAI')")->execute([$mid,$d]);
            }

            // Gunakan teks 5 pemicu standar saat seeding intervensi_pimpinan
            $pemicu = [
                'Perlu keputusan perpanjangan/addendum/evaluasi/pengakhiran',
                'Hambatan lintas unit di luar kewenangan PIC/unit',
                'Butuh anggaran/SDM/fasilitas di luar kewenangan unit',
                'Komitmen material mitra tidak dipenuhi',
                'Ada risiko hukum, reputasi, atau strategis yang material'
            ];
            foreach ($pemicu as $i => $t) {
                $pdo->prepare('INSERT INTO intervensi_pimpinan (mitra_id,no_pemicu,pemicu_teks,jawaban) VALUES (?,?,?, \'BELUM DIPASTIKAN\')')->execute([$mid, $i+1, $t]);
            }

            $pdo->prepare("INSERT INTO validasi (mitra_id, status) VALUES (?, 'BELUM')")->execute([$mid]);

            // Panggil syncStatusScorecard agar status terinisialisasi dengan benar (menjadi 'BELUM DINILAI')
            syncStatusScorecard($pdo, $mid);

            $pdo->commit();

            logAudit($mid, $user['id'], 'CREATE_MITRA', 'Naskah baru: '.$kode);
            $success = 'Naskah '.$kode.' berhasil ditambahkan.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = str_contains($e->getMessage(),'Duplicate') ? 'Kode sudah ada.' : 'Gagal membuat naskah: ' . $e->getMessage();
        }
    }
    }
}

$all = $pdo->query('SELECT id,kode,portofolio,nama_mitra,judul,bidang,jenis,file_naskah,tanggal_mulai,tanggal_berakhir,status_tanggal,status_scorecard,pemeriksa_id,pic_internal,baseline_status FROM mitra_kinerja ORDER BY kode')->fetchAll();
$pageTitle = 'Manajemen Naskah';
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Manajemen Naskah</h1>
        <div class="muted" style="font-size:13px;">Kelola identitas naskah, bidang kerja sama, scan dokumen fisik, dan rencana kerja</div>
    </div>
    <div style="display:flex;gap:8px;">
        <button type="button" onclick="document.getElementById('uploadScanModal').style.display='block'" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:5px;"><?= uiIcon('upload', 13) ?> Upload Scan Naskah (.pdf)</button>
        <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-info"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

<!-- Modal Quick Upload Scan PDF -->
<div id="uploadScanModal" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;overflow:auto;">
    <div style="background:#fff;max-width:540px;margin:60px auto;padding:24px;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.15);">
        <div class="flex-between" style="margin-bottom:16px;">
            <h2 style="margin:0;font-size:17px;color:#1e40af;">Upload Berkas Scan Naskah (.PDF)</h2>
            <button type="button" onclick="document.getElementById('uploadScanModal').style.display='none'" style="background:none;border:none;font-size:18px;cursor:pointer;">&times;</button>
        </div>
        <p style="font-size:12.5px;color:#475569;margin-top:0;">
            Unggah dokumen fisik hasil pemindaian/scan resmi bertanda tangan para pihak. Format wajib <strong>.PDF</strong> (bukan file hasil ketik/draft .docx).
        </p>
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="upload_scan_pdf">
            <div class="field" style="margin-bottom:14px;">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Pilih Naskah Kerja Sama * (Ketik untuk mencari)</label>
                <select name="target_mitra_id" required class="searchable-select" placeholder="Ketik nama mitra / kode PKS..." style="width:100%;padding:8px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px;">
                    <option value="">Pilih Naskah...</option>
                    <?php foreach ($all as $m): 
                        $isOptLocked = in_array($m['status_scorecard'] ?? '', ['FINAL/TERVALIDASI', 'FINAL'], true);
                        $isOptAssigned = (!empty($m['pemeriksa_id']) && (int)$m['pemeriksa_id'] === (int)$user['id'])
                            || (!empty($m['pic_internal']) && stripos($m['pic_internal'], $user['nama'] ?? $user['username'] ?? '') !== false);
                        $isOptDisabled = ($userRole !== 'admin' && $isOptLocked) || ($userRole === 'pengampu' && !$isOptAssigned && !empty($m['pemeriksa_id']));
                    ?>
                    <option value="<?= $m['id'] ?>" data-sub="Judul: <?= h(singkat($m['judul'] ?: '-', 45)) ?> | Bidang: <?= h($m['bidang'] ?? 'AHU') ?>" <?= $isOptDisabled ? 'disabled' : '' ?>>
                        <?= h($m['kode']) ?> &mdash; <?= h($m['nama_mitra']) ?><?= $isOptLocked ? ' (Terkunci)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="margin-bottom:18px;">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Pilih File Scan (.PDF) *</label>
                <input type="file" name="scan_pdf" accept=".pdf" required style="width:100%;padding:6px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px;">
            </div>
            <div style="display:flex;justify-content:flex-end;gap:8px;">
                <button type="button" onclick="document.getElementById('uploadScanModal').style.display='none'" class="btn btn-outline btn-sm">Batal</button>
                <button type="submit" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:5px;"><?= uiIcon('upload', 13) ?> Unggah Berkas Scan</button>
            </div>
        </form>
    </div>
</div>

<!-- Card Tambah Naskah -->
<div class="card" style="margin-bottom:20px;">
    <div class="flex-between" style="margin-bottom:12px;">
        <h2 style="margin:0;font-size:16px;">Tambah Naskah Baru</h2>
        <span class="muted" style="font-size:12px;">Input data naskah, bidang, scan berkas fisik, dan rencana kerja tahunan</span>
    </div>
    <form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>
    <div class="form-grid">
        <div class="field"><label>Kode (P11, C06, dst) *</label><input type="text" name="kode" maxlength="5" required placeholder="P11"></div>
        <div class="field"><label>Portofolio</label><select name="portofolio"><option>Pilot Utama</option><option>Cadangan</option></select></div>
        
        <!-- Bidang Pengganti Jenis -->
        <div class="field">
            <label>Bidang</label>
            <select name="bidang">
                <?php foreach ($bidangOptions as $bKey => $bLabel): ?>
                <option value="<?= $bKey ?>"><?= $bLabel ?></option>
                <?php endforeach; ?>
            </select>
            <div class="muted" style="font-size:11px;margin-top:2px;">Bidang terkait di Kanwil Kepri.</div>
        </div>

        <!-- Bentuk Naskah -->
        <div class="field"><label>Bentuk Naskah</label><select name="jenis"><option>PKS</option><option>MoU</option></select></div>

        <div class="field"><label>Nama Mitra *</label><input type="text" name="nama_mitra" required placeholder="Nama instansi mitra"></div>
        
        <div class="field">
            <label>Kerja Sama Utama (Payung MoU)</label>
            <select name="pks_induk_id">
                <option value="">- Tidak ada (Bukan Turunan) -</option>
                <?php foreach ($mouOptions as $mou): ?>
                <option value="<?= $mou['id'] ?>"><?= h($mou['kode']) ?> &mdash; <?= h(singkat($mou['nama_mitra'], 35)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="grid-column:1/-1;"><label>Judul Kerja Sama</label><input type="text" name="judul" placeholder="Judul lengkap kerja sama"></div>
        <div class="field"><label>Tanggal Mulai</label><input type="date" name="tanggal_mulai"></div>
        <div class="field"><label>Tanggal Berakhir</label><input type="date" name="tanggal_berakhir"></div>
        <div class="field">
            <label>Evaluasi Rencana Kerja (Per Tahun) *</label>
            <input type="number" name="evaluasi_per_tahun" value="4" min="1" max="12" required placeholder="Contoh: 4">
            <div class="muted" style="font-size:11px;margin-top:2px;">Berapa kali kegiatan evaluasi rencana kerja dilakukan per tahun (default: 4). Contoh: 36 bulan durasi &amp; 4 kali/tahun &rarr; 9 kali target evaluasi.</div>
        </div>
        <div class="field"><label>Status Tanggal</label><select name="status_tanggal"><option>BELUM TERVERIFIKASI</option><option>TERVERIFIKASI</option></select></div>
        <div class="field"><label>Tanggal Cut-off Baseline</label><input type="date" name="cutoff_date"><div class="muted" style="font-size:11px;margin-top:2px;">Tanggal acuan cutoff baseline &amp; EWS masa berlaku (default: tanggal mulai).</div></div>
        
        <div class="field"><label>PIC Internal</label><input type="text" name="pic_internal" placeholder="Nama & kontak PIC Kanwil"></div>
        <div class="field"><label>PIC Mitra</label><input type="text" name="pic_mitra" placeholder="Nama & kontak PIC Mitra"></div>

        <!-- Input Rencana Kerja Tahunan Awal pada form tambah naskah -->
        <div class="field" style="grid-column:1/-1;border-top:1px dashed #cbd5e1;padding-top:12px;margin-top:6px;">
            <label style="font-weight:600;color:#1e40af;">Rencana Kerja Tahunan Awal (Opsional)</label>
            <div class="muted" style="font-size:11px;margin-bottom:6px;">Jika sudah ada rencana kerja tahunan/program aksi awal, dapat langsung didaftarkan di sini.</div>
        </div>
        <div class="field" style="grid-column:1/-1;">
            <label>Judul Rencana Kerja Tahunan</label>
            <input type="text" name="rencana_judul" placeholder="Contoh: Rencana Aksi Sosialisasi Kekayaan Intelektual 2026">
        </div>
        <div class="field">
            <label>Tahun Rencana Kerja</label>
            <input type="number" name="rencana_tahun" value="<?= date('Y') ?>" min="2020" max="2050">
        </div>
        <div class="field">
            <label>Ruang Lingkup Rencana Kerja</label>
            <input type="text" name="rencana_ruang_lingkup" placeholder="Fokus kegiatan tahun berjalan">
        </div>

        <!-- Upload Scan Naskah PDF -->
        <div class="field" style="grid-column:1/-1;border-top:1px dashed #cbd5e1;padding-top:12px;margin-top:6px;">
            <label>Upload Scan Naskah Asli (.PDF)</label>
            <input type="file" name="file_naskah_pdf" accept=".pdf">
            <div class="muted" style="font-size:11px;margin-top:2px;">Khusus dokumen resmi fisik hasil scan bertanda tangan para pihak (bukan naskah ketik/draft).</div>
        </div>
    </div>
    <div style="margin-top:16px;"><button type="submit" class="btn btn-primary">Tambah Naskah</button></div>
    </form>
</div>

<!-- Card Daftar Naskah -->
<div class="card">
    <div class="flex-between" style="margin-bottom:12px;">
        <h2 style="margin:0;font-size:16px;">Daftar Naskah Kerja Sama</h2>
        <span class="muted" style="font-size:12px;">Total: <?= count($all) ?> naskah terdaftar</span>
    </div>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th style="width:6%;">Kode</th>
                <th style="width:10%;">Portofolio</th>
                <th style="width:12%;">Bidang</th>
                <th style="width:26%;">Mitra &amp; Judul</th>
                <th style="width:14%;">Scan Dokumen</th>
                <th style="width:12%;">Masa Berlaku</th>
                <th style="width:8%;">Status</th>
                <th style="width:12%;">Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($all as $m): ?>
            <tr>
                <td><strong><?= h($m['kode']) ?></strong></td>
                <td><span class="badge badge-<?= $m['portofolio']==='Pilot Utama'?'primary':'secondary' ?>" style="font-size:10.5px;"><?= h($m['portofolio']) ?></span></td>
                <td><span class="badge badge-secondary" style="font-size:11px;font-weight:600;"><?= h($m['bidang'] ?? 'AHU') ?></span></td>
                <td>
                    <div style="font-weight:600;color:#1e293b;"><?= h($m['nama_mitra']) ?></div>
                    <div class="muted" style="font-size:11px;margin-top:2px;"><?= h(singkat($m['judul'] ?? '-', 55)) ?></div>
                </td>
                <td>
                    <?php if (!empty($m['file_naskah'])): ?>
                    <a href="<?= h($m['file_naskah']) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;">
                        <?= uiIcon('file-text', 12) ?> Scan PDF
                    </a>
                    <?php else: ?>
                    <span class="muted" style="font-size:11px;">Belum diunggah</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:11.5px;">
                    <?= formatTanggal($m['tanggal_berakhir']) ?>
                </td>
                <td><span class="badge badge-<?= $m['status_tanggal']==='TERVERIFIKASI'?'success':'warning' ?>" style="font-size:10px;"><?= h($m['status_tanggal']) ?></span></td>
                <td>
                    <a href="mitra_manage.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                    <a href="mitra_edit.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm">Scorecard</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>