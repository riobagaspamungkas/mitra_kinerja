<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireRole(['admin', 'pemeriksa', 'pengampu']);

$pdo = getDB();
$user = currentUser();
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

/* ── MODAL QUICK UPLOAD SCAN PDF (dari Listing) ─────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'upload_scan_pdf') {
    $targetMitraId = (int)($_POST['target_mitra_id'] ?? 0);
    if ($targetMitraId <= 0) {
        $errors[] = 'Pilih naskah yang akan diunggah berkas scan-nya.';
    } elseif (!isset($_FILES['scan_pdf']) || $_FILES['scan_pdf']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Pilih file scan berkas dalam format PDF yang valid.';
    } else {
        $ext = strtolower(pathinfo($_FILES['scan_pdf']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf' || !isPdfValid($_FILES['scan_pdf']['tmp_name'])) {
            $errors[] = 'File naskah wajib berformat .PDF asli (dokumen hasil scan fisik bertanda tangan, bukan hasil ketik atau berkas palsu).';
        } else {
            $stmtM = $pdo->prepare('SELECT kode FROM mitra_kinerja WHERE id = ?');
            $stmtM->execute([$targetMitraId]);
            $kodeMitra = $stmtM->fetchColumn() ?: 'NASKAH';

            $targetName = 'scan_naskah_' . $kodeMitra . '_' . time() . '.pdf';
            $targetPath = __DIR__ . '/public/uploads/' . $targetName;
            if (move_uploaded_file($_FILES['scan_pdf']['tmp_name'], $targetPath)) {
                $savedPath = 'public/uploads/' . $targetName;
                $stmtU = $pdo->prepare('UPDATE mitra_kinerja SET file_naskah = ? WHERE id = ?');
                $stmtU->execute([$savedPath, $targetMitraId]);

                // Sinkronkan otomatis ke Baseline Elemen 1 (Identitas naskah)
                $stmtB = $pdo->prepare("UPDATE baseline_elemen SET link_sumber_bukti = ?, status = 'TERVERIFIKASI' WHERE mitra_id = ? AND nomor_elemen = 1");
                $stmtB->execute([$savedPath, $targetMitraId]);

                logAudit($targetMitraId, $user['id'], 'UPLOAD_SCAN', 'Upload scan naskah PDF: ' . $kodeMitra);
                $success = 'Berkas scan naskah PDF untuk ' . $kodeMitra . ' berhasil diunggah dan disinkronkan ke Identitas Baseline.';
            } else {
                $errors[] = 'Gagal menyimpan berkas scan naskah di server.';
            }
        }
    }
}

/* ── TAMBAH RENCANA KERJA DARI EDIT FORM ─────────────────── */
if ($id > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_rencana_kerja') {
    $judulRk = trim($_POST['judul_rencana'] ?? '');
    $ruangRk = trim($_POST['ruang_lingkup'] ?? '');
    $mulaiRk = $_POST['tanggal_mulai'] ?: date('Y-01-01');
    $selesaiRk = ($_POST['tanggal_selesai'] ?? '') ?: date('Y-12-31');
    $statusRk = $_POST['status'] ?? 'Disetujui';

    if ($judulRk === '') {
        $errors[] = 'Judul Rencana Kerja wajib diisi.';
    } else {
        $stmtR = $pdo->prepare('INSERT INTO rencana_kerja (mitra_id, judul_rencana, ruang_lingkup, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmtR->execute([$id, $judulRk, $ruangRk, $mulaiRk, $selesaiRk, $statusRk]);
        logAudit($id, $user['id'], 'ADD_RENCANA_KERJA', 'Tambah Rencana Kerja: ' . $judulRk);
        $success = 'Rencana Kerja tahunan berhasil ditambahkan.';
    }
}

/* ── EDIT (ada ?id=) ──────────────────────────────── */
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
    $stmt->execute([$id]);
    $mitra = $stmt->fetch();
    if (!$mitra) { http_response_code(404); die('Naskah tidak ditemukan.'); }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST['action'])) {
        $namaMitra  = trim($_POST['nama_mitra'] ?? '');
        $judul      = trim($_POST['judul'] ?? '');
        $portofolio = $_POST['portofolio'] ?? $mitra['portofolio'];
        $bidang     = $_POST['bidang'] ?? ($mitra['bidang'] ?? 'AHU');
        $jenis      = $_POST['jenis'] ?? $mitra['jenis'];
        $pksIndukId = !empty($_POST['pks_induk_id']) ? (int)$_POST['pks_induk_id'] : null;
        $mulai      = $_POST['tanggal_mulai'] ?: null;
        $berakhir   = $_POST['tanggal_berakhir'] ?: null;
        $statusTgl  = $_POST['status_tanggal'] ?? $mitra['status_tanggal'];
        $cutoff     = $_POST['cutoff_date'] ?: null;
        $sumber     = trim($_POST['sumber_baseline'] ?? '');
        $picInternal = trim($_POST['pic_internal'] ?? ($mitra['pic_internal'] ?? ''));
        $picMitra    = trim($_POST['pic_mitra'] ?? ($mitra['pic_mitra'] ?? ''));
        $evaluasiPerTahun = !empty($_POST['evaluasi_per_tahun']) ? (int)$_POST['evaluasi_per_tahun'] : 4;

        // Upload File Naskah (Khusus PDF Scanned)
        $fileNaskah = $mitra['file_naskah'] ?? null;
        if (isset($_FILES['file_naskah']) && $_FILES['file_naskah']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['file_naskah']['name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf' && isPdfValid($_FILES['file_naskah']['tmp_name'])) {
                $targetName = 'scan_naskah_' . $mitra['kode'] . '_' . time() . '.pdf';
                $targetPath = __DIR__ . '/public/uploads/' . $targetName;
                if (move_uploaded_file($_FILES['file_naskah']['tmp_name'], $targetPath)) {
                    $fileNaskah = 'public/uploads/' . $targetName;
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
                }
            } else {
                $errors[] = 'Foto dokumentasi wajib berformat gambar valid (JPG, PNG, atau WebP).';
            }
        }

        if ($namaMitra === '') {
            $errors[] = 'Nama Mitra wajib diisi.';
        } elseif (empty($errors)) {
            $stmtU = $pdo->prepare('UPDATE mitra_kinerja SET nama_mitra=?, judul=?, portofolio=?, bidang=?, jenis=?, pks_induk_id=?, tanggal_mulai=?, tanggal_berakhir=?, status_tanggal=?, cutoff_date=?, sumber_baseline=?, pic_internal=?, pic_mitra=?, evaluasi_per_tahun=?, file_naskah=?, foto_kerjasama=? WHERE id=?');
            $stmtU->execute([$namaMitra, $judul, $portofolio, $bidang, $jenis, $pksIndukId, $mulai, $berakhir, $statusTgl, $cutoff, $sumber, $picInternal, $picMitra, $evaluasiPerTahun, $fileNaskah, $fotoKerjasama, $id]);
            if ($fileNaskah) {
                // Sinkronkan ke Baseline Elemen 1 (Identitas naskah)
                $stmtB = $pdo->prepare("UPDATE baseline_elemen SET link_sumber_bukti = ?, status = 'TERVERIFIKASI' WHERE mitra_id = ? AND nomor_elemen = 1");
                $stmtB->execute([$fileNaskah, $id]);
            }
            syncStatusScorecard($pdo, $id);
            logAudit($id, $user['id'], 'UPDATE_MITRA', 'Data naskah ' . $mitra['kode'] . ' diperbarui');
            $success = 'Data naskah berhasil diperbarui.';
            $stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
            $stmt->execute([$id]);
            $mitra = $stmt->fetch();
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

<div class="card" style="margin-bottom:20px;">
<form method="post" enctype="multipart/form-data">
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
    <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
    <a href="mitra_edit.php?id=<?= $id ?>" class="btn btn-outline" style="margin-left:8px;">Buka Scorecard &rarr;</a>
    <a href="baseline.php?id=<?= $id ?>" class="btn btn-outline" style="margin-left:8px;">Buka Baseline &rarr;</a>
</div>
</form>
</div>

<!-- Card Rencana Kerja Tahunan (/Tahun) -->
<div class="card">
    <div class="flex-between" style="margin-bottom:14px;">
        <div>
            <h2 style="margin:0;font-size:16px;">Rencana Kerja Tahunan (/Tahun)</h2>
            <div class="muted" style="font-size:12px;">Program dan target operasional turunan naskah per tahun</div>
        </div>
        <button type="button" onclick="document.getElementById('rkAddForm').style.display = document.getElementById('rkAddForm').style.display === 'none' ? 'block' : 'none';" class="btn btn-outline btn-sm">+ Tambah Rencana Kerja</button>
    </div>

    <!-- Form Tambah Rencana Kerja Baru -->
    <div id="rkAddForm" style="display:none;background:#f8fafc;padding:16px;border-radius:6px;border:1px solid #e2e8f0;margin-bottom:16px;">
        <h3 style="font-size:14px;margin-top:0;margin-bottom:12px;color:#1e40af;">Formulir Rencana Kerja Baru</h3>
        <form method="post">
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

/* ── LISTING + TAMBAH (tanpa ?id=) ─────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST['action'])) {
    $kode       = strtoupper(trim($_POST['kode'] ?? ''));
    $portofolio = $_POST['portofolio'] ?? 'Pilot Utama';
    $namaMitra  = trim($_POST['nama_mitra'] ?? '');
    $judul      = trim($_POST['judul'] ?? '');
    $bidang     = $_POST['bidang'] ?? 'AHU';
    $jenis      = $_POST['jenis'] ?? 'PKS';
    $mulai      = $_POST['tanggal_mulai'] ?: null;
    $berakhir   = $_POST['tanggal_berakhir'] ?: null;
    $statusTgl  = $_POST['status_tanggal'] ?? 'BELUM TERVERIFIKASI';
    $evaluasiPerTahun = !empty($_POST['evaluasi_per_tahun']) ? (int)$_POST['evaluasi_per_tahun'] : 4;
    $picInternal = trim($_POST['pic_internal'] ?? '');
    $picMitra    = trim($_POST['pic_mitra'] ?? '');

    // Upload Scan Naskah PDF jika disertakan
    $fileNaskah = null;
    if (isset($_FILES['file_naskah_pdf']) && $_FILES['file_naskah_pdf']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['file_naskah_pdf']['name'], PATHINFO_EXTENSION));
        if ($ext === 'pdf' && isPdfValid($_FILES['file_naskah_pdf']['tmp_name'])) {
            $targetName = 'scan_naskah_' . $kode . '_' . time() . '.pdf';
            $targetPath = __DIR__ . '/public/uploads/' . $targetName;
            if (move_uploaded_file($_FILES['file_naskah_pdf']['tmp_name'], $targetPath)) {
                $fileNaskah = 'public/uploads/' . $targetName;
            }
        } else {
            $errors[] = 'File naskah wajib berformat .PDF asli (hasil scan fisik bertanda tangan, bukan hasil ketik atau berkas palsu).';
        }
    }

    if ($kode === '' || $namaMitra === '') {
        $errors[] = 'Kode dan Nama Mitra wajib diisi.';
    } elseif (empty($errors)) {
        try {
            $pksInduk = !empty($_POST['pks_induk_id']) ? (int)$_POST['pks_induk_id'] : null;
            $stmt = $pdo->prepare('INSERT INTO mitra_kinerja (kode, portofolio, nama_mitra, judul, bidang, jenis, pks_induk_id, tanggal_mulai, tanggal_berakhir, status_tanggal, evaluasi_per_tahun, pic_internal, pic_mitra, file_naskah) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$kode, $portofolio, $namaMitra, $judul, $bidang, $jenis, $pksInduk, $mulai, $berakhir, $statusTgl, $evaluasiPerTahun, $picInternal, $picMitra, $fileNaskah]);
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

            // Inisialisasi 7 Indikator V2.1
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
                $desc = $ind[1] . "\nCara periksa: Evaluasi berkala siklus monev";
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

            foreach (['Masa berlaku','Aktivitas/tenggat','Data/eviden','PIC'] as $d) { $pdo->prepare("INSERT INTO early_warning (mitra_id,dimensi,status,progres) VALUES (?,?,'V0','BELUM MULAI')")->execute([$mid,$d]); }
            $pemicu = ['Keterlambatan pelaksanaan','Perubahan kebijakan','Pengurangan anggaran','Konflik kepentingan','Risiko hukum'];
            foreach ($pemicu as $i => $t) { $pdo->prepare('INSERT INTO intervensi_pimpinan (mitra_id,no_pemicu,pemicu_teks) VALUES (?,?,?)')->execute([$mid,$i+1,$t]); }
            $pdo->prepare("INSERT INTO validasi (mitra_id, status) VALUES (?, 'BELUM')")->execute([$mid]);
            logAudit($mid, $user['id'], 'CREATE_MITRA', 'Naskah baru: '.$kode);
            $success = 'Naskah '.$kode.' berhasil ditambahkan.';
        } catch (PDOException $e) {
            $errors[] = str_contains($e->getMessage(),'Duplicate') ? 'Kode sudah ada.' : 'Gagal membuat: ' . $e->getMessage();
        }
    }
}

$all = $pdo->query('SELECT id,kode,portofolio,nama_mitra,judul,bidang,jenis,file_naskah,tanggal_mulai,tanggal_berakhir,status_tanggal,status_scorecard FROM mitra_kinerja ORDER BY kode')->fetchAll();
$pageTitle = 'Manajemen Naskah';
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Manajemen Naskah</h1>
        <div class="muted" style="font-size:13px;">Kelola identitas naskah, bidang kerja sama, scan dokumen fisik, dan rencana kerja</div>
    </div>
    <div style="display:flex;gap:8px;">
        <button type="button" onclick="document.getElementById('uploadScanModal').style.display='block'" class="btn btn-outline btn-sm">📤 Upload Scan Naskah (.pdf)</button>
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
            <input type="hidden" name="action" value="upload_scan_pdf">
            <div class="field" style="margin-bottom:14px;">
                <label style="display:block;font-weight:600;font-size:13px;margin-bottom:6px;">Pilih Naskah Kerja Sama * (Ketik untuk mencari)</label>
                <select name="target_mitra_id" required class="searchable-select" placeholder="Ketik nama mitra / kode PKS..." style="width:100%;padding:8px;font-size:13px;border:1px solid #cbd5e1;border-radius:4px;">
                    <option value="">Pilih Naskah...</option>
                    <?php foreach ($all as $m): ?>
                    <option value="<?= $m['id'] ?>" data-sub="Judul: <?= h(singkat($m['judul'] ?: '-', 45)) ?> | Bidang: <?= h($m['bidang'] ?? 'AHU') ?>">
                        <?= h($m['kode']) ?> &mdash; <?= h($m['nama_mitra']) ?>
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
                <button type="submit" class="btn btn-primary btn-sm">📤 Unggah Berkas Scan</button>
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
        
        <div class="field"><label>PIC Internal</label><input type="text" name="pic_internal" placeholder="Nama & kontak PIC Kanwil"></div>
        <div class="field"><label>PIC Mitra</label><input type="text" name="pic_mitra" placeholder="Nama & kontak PIC Mitra"></div>

        <!-- Upload Scan Naskah PDF -->
        <div class="field" style="grid-column:1/-1;">
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
                    <a href="<?= h($m['file_naskah']) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:11px;display:inline-flex;align-items:center;gap:3px;">
                        📄 Scan PDF
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