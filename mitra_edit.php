<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$user = currentUser();
$userRole = $user['role'] ?? 'pemeriksa';

$id = (int)($_GET['id'] ?? 0);
$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
$stmt->execute([$id]);
$mitra = $stmt->fetch();
if (!$mitra) {
    http_response_code(404);
    die('Naskah tidak ditemukan.');
}

// Cek status validasi saat ini untuk mendeteksi kunci / status disetujui
$stmtVal = $pdo->prepare('SELECT status FROM validasi WHERE mitra_id = ?');
$stmtVal->execute([$id]);
$currentValStatus = $stmtVal->fetchColumn() ?: 'BELUM';

// Bug 8.3: Role pengampu diizinkan mengedit jika ditugaskan
$isAssigned = (!empty($mitra['pemeriksa_id']) && (int)$mitra['pemeriksa_id'] === (int)$user['id'])
    || (!empty($mitra['pic_internal']) && stripos($mitra['pic_internal'], $user['nama'] ?? $user['username'] ?? '') !== false);

$canEdit = in_array($userRole, ['admin', 'pemeriksa'], true)
    || ($userRole === 'pengampu' && ($isAssigned || empty($mitra['pemeriksa_id'])));

// Bug 2.2: Kunci naskah jika FINAL/TERVALIDASI atau validasi DISETUJUI (hanya admin yang dapat mengubah)
$isLockedFinal = (in_array($mitra['status_scorecard'] ?? '', ['FINAL/TERVALIDASI', 'FINAL'], true) || $currentValStatus === 'DISETUJUI');
if ($isLockedFinal && $userRole !== 'admin') {
    $canEdit = false;
}

$errors = [];
$saved = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        die('Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.');
    }
    if (!$canEdit) {
        http_response_code(403);
        die('Akses ditolak: Data naskah telah berstatus FINAL/TERVALIDASI atau disetujui validator, atau role Anda tidak memiliki izin untuk mengubah data ini.');
    }

    $pdo->beginTransaction();
    try {
        // --- A. Identitas, kontrol, posisi & rekomendasi ---
        $statusTanggal = in_array($_POST['status_tanggal'] ?? '', ['TERVERIFIKASI','BELUM TERVERIFIKASI'], true)
            ? $_POST['status_tanggal'] : ($mitra['status_tanggal'] ?? 'BELUM TERVERIFIKASI');
        $posisiPortofolio = trim($_POST['posisi_portofolio'] ?? '') ?: 'BELUM DAPAT DITENTUKAN';

        // Preserve rich multi-line audit recommendation narrative / allow deliberate clearing
        $rawRek = isset($_POST['rekomendasi']) ? trim($_POST['rekomendasi']) : null;
        $kwRek  = trim($_POST['rekomendasi_keyword'] ?? '');
        $existRek = trim($mitra['rekomendasi'] ?? '');
        if ($rawRek !== null) {
            if ($rawRek !== '') {
                $rekomendasi = $rawRek;
            } elseif ($kwRek !== '' && $kwRek !== 'BELUM DITENTUKAN') {
                $rekomendasi = $kwRek;
            } else {
                $rekomendasi = 'BELUM DITENTUKAN';
            }
        } elseif ($kwRek !== '') {
            if ($existRek !== '' && strlen($existRek) > 50 && !str_contains(strtoupper($existRek), $kwRek)) {
                $rekomendasi = $kwRek . " — " . $existRek;
            } elseif ($existRek !== '' && strlen($existRek) > 50) {
                $rekomendasi = $existRek;
            } else {
                $rekomendasi = $kwRek;
            }
        } else {
            $rekomendasi = $existRek ?: 'BELUM DITENTUKAN';
        }

        $picFocalPoint = trim($_POST['pic_focal_point'] ?? '');

        $reviewerIdToSave = !empty($mitra['pemeriksa_id']) ? $mitra['pemeriksa_id'] : $user['id'];
        $stmtU = $pdo->prepare('UPDATE mitra_kinerja SET pemeriksa_id = ?, tanggal_review = ?, status_tanggal = ?, posisi_portofolio = ?, rekomendasi = ?, pic_focal_point = ? WHERE id = ?');
        $stmtU->execute([
            $reviewerIdToSave,
            ($_POST['tanggal_review'] ?? '') !== '' ? $_POST['tanggal_review'] : null,
            $statusTanggal,
            $posisiPortofolio,
            $rekomendasi,
            $picFocalPoint ?: null,
            $id,
        ]);

        // --- B. Tujuh Indikator V2.1 (I1..I7) ---
        // Bug 2.3: Gunakan INSERT ... ON DUPLICATE KEY UPDATE agar tidak silently match 0 baris
        foreach (['I1','I2','I3','I4','I5','I6','I7'] as $kode) {
            $status  = $_POST['status_' . $kode] ?? 'BELUM DITELAAH';
            $kondisiSaatIni = trim($_POST['kondisi_saat_ini_' . $kode] ?? '');
            $temuan  = trim($_POST['temuan_' . $kode] ?? '');
            $skorRaw = $_POST['skor_' . $kode] ?? '';
            $alasan  = trim($_POST['alasan_' . $kode] ?? '');
            $catatanTl = trim($_POST['catatan_tl_' . $kode] ?? '');

            // Aturan V2.1: skor HANYA disimpan jika status = DAPAT DINILAI (atau BUKTI CUKUP/BUKTI MEMADAI).
            $skor = null;
            if (in_array($status, ['DAPAT DINILAI', 'BUKTI CUKUP', 'BUKTI MEMADAI'], true) && $skorRaw !== '') {
                $skor = max(0, min(4, (int)$skorRaw));
            }

            $stmtI = $pdo->prepare('SELECT bobot, deskripsi FROM indikator_skor WHERE mitra_id = ? AND kode_indikator = ?');
            $stmtI->execute([$id, $kode]);
            $existRow = $stmtI->fetch();
            $bobot = (int)($existRow['bobot'] ?? (BOBOT_INDIKATOR[$kode] ?? 10));
            $deskripsiExist = $existRow['deskripsi'] ?? "Indikator $kode";
            $nilai = hitungNilaiIndikator($skor, $bobot);

            $stmtU2 = $pdo->prepare(
                'INSERT INTO indikator_skor (mitra_id, kode_indikator, deskripsi, bobot, referensi_baseline, status_pemeriksaan, kondisi_saat_ini, temuan_bukti, skor, alasan_skor, catatan_tindak_lanjut, nilai, updated_by)
                 VALUES (?, ?, ?, ?, \'Baseline awal\', ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE status_pemeriksaan=VALUES(status_pemeriksaan), kondisi_saat_ini=VALUES(kondisi_saat_ini), temuan_bukti=VALUES(temuan_bukti), skor=VALUES(skor), alasan_skor=VALUES(alasan_skor), catatan_tindak_lanjut=VALUES(catatan_tindak_lanjut), nilai=VALUES(nilai), updated_by=VALUES(updated_by)'
            );
            $stmtU2->execute([$id, $kode, $deskripsiExist, $bobot, $status, $kondisiSaatIni ?: null, $temuan ?: null, $skor, $alasan ?: null, $catatanTl ?: null, $nilai, $user['id']]);
        }

        // --- D. Early warning (4 dimensi) ---
        // Bug 2.3: Gunakan INSERT ... ON DUPLICATE KEY UPDATE
        foreach (['Masa berlaku','Aktivitas/tenggat','Data/eviden','PIC'] as $dim) {
            $key = preg_replace('/[^a-zA-Z]/', '', $dim);
            $fakta    = trim($_POST['warn_fakta_' . $key] ?? '');
            $tindakan = trim($_POST['warn_tindakan_' . $key] ?? '');
            $pic      = trim($_POST['warn_pic_' . $key] ?? '');
            $tenggat  = $_POST['warn_tenggat_' . $key] ?? '';
            $progres  = $_POST['warn_progres_' . $key] ?? 'BELUM MULAI';

            if ($dim === 'Masa berlaku') {
                $stmtW = $pdo->prepare(
                    'INSERT INTO early_warning (mitra_id, dimensi, status, fakta_bukti, tindakan, pic, tenggat, progres)
                     VALUES (?, ?, \'V0\', ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE fakta_bukti=VALUES(fakta_bukti), tindakan=VALUES(tindakan), pic=VALUES(pic), tenggat=VALUES(tenggat), progres=VALUES(progres)'
                );
                $stmtW->execute([$id, $dim, $fakta ?: null, $tindakan ?: null, $pic ?: null, $tenggat !== '' ? $tenggat : null, $progres]);
            } else {
                $kondisiPost = $_POST['warn_kondisi_' . $key] ?? 'BELUM DIPERIKSA';
                $allowedKondisi = array_keys(KONDISI_OPTIONS[$dim] ?? []);
                $kondisi = in_array($kondisiPost, $allowedKondisi, true) ? $kondisiPost : 'BELUM DIPERIKSA';
                $status = statusDariKondisi($dim, $kondisi);

                $stmtW = $pdo->prepare(
                    'INSERT INTO early_warning (mitra_id, dimensi, status, kondisi, fakta_bukti, tindakan, pic, tenggat, progres)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status=VALUES(status), kondisi=VALUES(kondisi), fakta_bukti=VALUES(fakta_bukti), tindakan=VALUES(tindakan), pic=VALUES(pic), tenggat=VALUES(tenggat), progres=VALUES(progres)'
                );
                $stmtW->execute([$id, $dim, $status, $kondisi, $fakta ?: null, $tindakan ?: null, $pic ?: null, $tenggat !== '' ? $tenggat : null, $progres]);
            }
        }

        // --- E. Uji kebutuhan intervensi pimpinan (5 pemicu) ---
        // Bug 2.3: Gunakan INSERT ... ON DUPLICATE KEY UPDATE
        $standarPemicu = [
            1 => 'Perlu keputusan perpanjangan/addendum/evaluasi/pengakhiran',
            2 => 'Hambatan lintas unit di luar kewenangan PIC/unit',
            3 => 'Butuh anggaran/SDM/fasilitas di luar kewenangan unit',
            4 => 'Komitmen material mitra tidak dipenuhi',
            5 => 'Ada risiko hukum, reputasi, atau strategis yang material',
        ];
        for ($no = 1; $no <= 5; $no++) {
            $jawaban = $_POST['pemicu_' . $no] ?? 'BELUM DIPASTIKAN';
            $bukti   = trim($_POST['pemicu_bukti_' . $no] ?? '');
            $teksPemicu = $standarPemicu[$no] ?? '';
            $stmtP = $pdo->prepare(
                'INSERT INTO intervensi_pimpinan (mitra_id, no_pemicu, pemicu_teks, jawaban, bukti_alasan)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE jawaban=VALUES(jawaban), bukti_alasan=VALUES(bukti_alasan)'
            );
            $stmtP->execute([$id, $no, $teksPemicu, $jawaban, $bukti ?: null]);
        }

        $kendala   = trim($_POST['uraian_kendala'] ?? '');
        $upaya     = trim($_POST['upaya_dilakukan'] ?? '');
        $keputusan = trim($_POST['keputusan_diminta'] ?? '');
        $stmtU3 = $pdo->prepare(
            'INSERT INTO intervensi_usulan (mitra_id, uraian_kendala, upaya_dilakukan, keputusan_diminta)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE uraian_kendala=VALUES(uraian_kendala), upaya_dilakukan=VALUES(upaya_dilakukan), keputusan_diminta=VALUES(keputusan_diminta)'
        );
        $stmtU3->execute([$id, $kendala ?: null, $upaya ?: null, $keputusan ?: null]);

        // Bug 2: Reset validasi status to 'BELUM' jika sebelumnya berstatus 'PERLU PERBAIKAN' (revisi baru dari pemeriksa)
        resetValidasiJikaPerluPerbaikan($pdo, $id);

        // Bug 4: Update active siklus_monev record only when corresponding to active review cycle or explicitly targeted
        // Prevents successive saves from draining future milestones
        $targetSMId = !empty($_POST['target_siklus_id']) ? (int)$_POST['target_siklus_id'] : (!empty($_POST['siklus_monev_id']) ? (int)$_POST['siklus_monev_id'] : (!empty($_POST['siklus_id']) ? (int)$_POST['siklus_id'] : null));
        $activeSMId = null;

        if ($targetSMId) {
            $stmtCheck = $pdo->prepare('SELECT id FROM siklus_monev WHERE id = ? AND mitra_id = ?');
            $stmtCheck->execute([$targetSMId, $id]);
            $activeSMId = $stmtCheck->fetchColumn() ?: null;
        }

        if (!$activeSMId) {
            // Hanya targetkan milestone yang berstatus aktif penilaian ('Sedang Dinilai' atau 'Perlu Penilaian Segera')
            $stmtActiveSM = $pdo->prepare("SELECT id FROM siklus_monev WHERE mitra_id = ? AND status_siklus IN ('Sedang Dinilai', 'Perlu Penilaian Segera') ORDER BY siklus_ke ASC LIMIT 1");
            $stmtActiveSM->execute([$id]);
            $activeSMId = $stmtActiveSM->fetchColumn() ?: null;
        }

        if (!$activeSMId) {
            // Jika siklus aktif telah diselesaikan, perbarui siklus terakhir yang selesai daripada mengorbankan siklus masa depan
            $stmtRecentSM = $pdo->prepare("SELECT id FROM siklus_monev WHERE mitra_id = ? AND status_siklus = 'Selesai' ORDER BY siklus_ke DESC LIMIT 1");
            $stmtRecentSM->execute([$id]);
            $recentCompletedSMId = $stmtRecentSM->fetchColumn() ?: null;

            // Cek apakah ada milestone berikutnya yang sudah jatuh tempo / mendekati (dalam 30 hari ke depan)
            $stmtNextDueSM = $pdo->prepare("SELECT id FROM siklus_monev WHERE mitra_id = ? AND status_siklus NOT IN ('Selesai', 'selesai evaluasi') AND tanggal_target_evaluasi <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY siklus_ke ASC LIMIT 1");
            $stmtNextDueSM->execute([$id]);
            $nextDueSMId = $stmtNextDueSM->fetchColumn() ?: null;

            if ($nextDueSMId) {
                $activeSMId = $nextDueSMId;
            } elseif ($recentCompletedSMId) {
                $activeSMId = $recentCompletedSMId;
            }
        }

        if ($activeSMId) {
            $stmtInd = $pdo->prepare('SELECT * FROM indikator_skor WHERE mitra_id = ?');
            $stmtInd->execute([$id]);
            $indRows = $stmtInd->fetchAll();
            $rInd = ringkasanIndikator($indRows);
            $nilaiBerjalan = $rInd['nilai_berjalan'];

            if ($rInd['skor_lengkap'] && $rInd['cek_lengkap_ok'] && ($rInd['bukti_kurang_n'] ?? 0) === 0) {
                $tglRealisasi = ($_POST['tanggal_review'] ?? '') !== '' ? $_POST['tanggal_review'] : date('Y-m-d');
                $stmtUpdSM = $pdo->prepare("UPDATE siklus_monev SET status_siklus = 'Selesai', nilai_siklus = ?, tanggal_realisasi_evaluasi = ? WHERE id = ?");
                $stmtUpdSM->execute([round($nilaiBerjalan, 2), $tglRealisasi, $activeSMId]);
            } else {
                $stmtUpdSM = $pdo->prepare("UPDATE siklus_monev SET status_siklus = 'Sedang Dinilai', nilai_siklus = ?, tanggal_realisasi_evaluasi = NULL WHERE id = ?");
                $stmtUpdSM->execute([round($nilaiBerjalan, 2), $activeSMId]);
            }
        }

        // Bug 2.1: Call syncStatusScorecard inside the try-catch block before or alongside commit
        syncStatusScorecard($pdo, $id, true);

        // Allow 'BELUM DAPAT DITENTUKAN' and rich recommendation to be preserved without automatic forced reversion
        $stmtPreserve = $pdo->prepare('UPDATE mitra_kinerja SET posisi_portofolio = ?, rekomendasi = ? WHERE id = ?');
        $stmtPreserve->execute([$posisiPortofolio, $rekomendasi, $id]);

        $pdo->commit();
        logAudit($id, $user['id'], 'SIMPAN_SCORECARD', 'Menyimpan penilaian naskah ' . $mitra['kode']);
        $saved = true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
    }

    // Muat ulang mitra (untuk status_scorecard terbaru)
    $stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
    $stmt->execute([$id]);
    $mitra = $stmt->fetch();
}

$summary = getMitraSummary($pdo, $mitra);
if (!empty($mitra['posisi_portofolio']) && $mitra['posisi_portofolio'] === 'BELUM DAPAT DITENTUKAN') {
    $summary['posisi_portofolio'] = 'BELUM DAPAT DITENTUKAN';
}
if (!empty($mitra['rekomendasi'])) {
    $summary['rekomendasi'] = $mitra['rekomendasi'];
}

// Hitung total kegiatan tindak lanjut terkait PKS ini
$stmtTLCount = $pdo->prepare('SELECT COUNT(*) FROM tindak_lanjut WHERE mitra_id = ?');
$stmtTLCount->execute([$id]);
$kegiatanCount = (int)$stmtTLCount->fetchColumn();

$pageTitle = 'Naskah ' . $mitra['kode'];
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:14px;">
    <div>
        <h1 style="margin:0;font-size:20px;"><?= h($mitra['kode']) ?> — <?= h($mitra['nama_mitra']) ?></h1>
        <div class="muted" style="font-size:13px;"><?= h($mitra['judul']) ?></div>
    </div>
    <a href="portofolio.php" class="btn btn-outline btn-sm">&larr; Portofolio</a>
</div>

<?php if ($saved): ?><div class="alert alert-info">Perubahan tersimpan.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>
<?php if ($isLockedFinal && $userRole !== 'admin'): ?>
    <div class="alert alert-warning">🔒 Naskah ini telah berstatus <strong>FINAL/TERVALIDASI</strong> atau telah disetujui validator. Mode baca saja (hanya Administrator yang berwenang mengubah data).</div>
<?php elseif (!$canEdit): ?>
    <div class="alert alert-info">Anda melihat data ini sebagai <?= h($userRole) ?> (mode baca saja).</div>
<?php endif; ?>

<div class="kpi-grid" style="margin-bottom:12px;grid-template-columns:repeat(auto-fit, minmax(135px, 1fr));gap:12px;">
    <div class="kpi-card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:92px;padding:10px 8px;">
        <div class="kpi-value"><?= $summary['nilai_final'] !== null ? number_format($summary['nilai_final'], 2) : '—' ?></div>
        <div class="kpi-label">Nilai Final</div>
    </div>
    <div class="kpi-card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:92px;padding:10px 8px;">
        <div class="kpi-value" style="font-size:20px;"><?= $summary['dapat_dinilai_n'] ?>/7</div>
        <div class="kpi-label"><?= $summary['dapat_dinilai_n'] ?>/7 Dapat Dinilai</div>
    </div>
    <div class="kpi-card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:92px;padding:10px 8px;">
        <span class="badge badge-<?= warnaKategori($summary['kategori']) ?>" style="font-size:12px;font-weight:700;white-space:normal;line-height:1.25;padding:4px 8px;max-width:100%;text-align:center;word-break:break-word;display:inline-block;"><?= h($summary['kategori']) ?></span>
        <div class="kpi-label" style="margin-top:4px;">Kategori</div>
    </div>
    <div class="kpi-card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:92px;padding:10px 8px;">
        <?php
        // Bug 8.1: Gunakan primary untuk SIAP DIVALIDASI agar memiliki kelas CSS valid
        $scBadgeColor = match($summary['status_scorecard']) {
            'FINAL/TERVALIDASI', 'FINAL' => 'success',
            'SIAP DIVALIDASI' => 'primary',
            'DALAM PENILAIAN' => 'warning',
            'BUKTI BELUM MEMADAI', 'PERLU PERBAIKAN' => 'danger',
            default => 'secondary'
        };
        ?>
        <span class="badge badge-<?= $scBadgeColor ?>" style="font-size:11px;font-weight:700;white-space:normal;line-height:1.25;padding:4px 8px;max-width:100%;text-align:center;word-break:break-word;display:inline-block;"><?= h($summary['status_scorecard']) ?></span>
        <div class="kpi-label" style="margin-top:4px;">Status Scorecard</div>
    </div>
    <div class="kpi-card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:92px;padding:10px 8px;">
        <span class="badge badge-<?= warnaWarning($summary['warning']['status']) ?>" style="font-size:11px;font-weight:700;white-space:normal;line-height:1.25;padding:4px 8px;max-width:100%;text-align:center;word-break:break-word;display:inline-block;"><?= h($summary['warning']['label']) ?></span>
        <div class="kpi-label" style="margin-top:4px;">Warning Tertinggi</div>
    </div>
    <div class="kpi-card" style="display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:92px;padding:10px 8px;">
        <div class="kpi-value" style="color:#0284c7;font-weight:800;"><?= $kegiatanCount ?></div>
        <div class="kpi-label">Kegiatan Terkait</div>
        <div style="font-size:10px;margin-top:2px;"><a href="tindak_lanjut.php?mitra_id=<?= $id ?>" style="color:#0284c7;text-decoration:none;">Lihat Kegiatan &rarr;</a></div>
    </div>
</div>

<div class="stat-bar" style="background:#f8fafc;border:1px solid var(--border,#e2e8f0);border-radius:6px;padding:8px 14px;margin-bottom:20px;font-size:12.5px;color:#475569;display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:center;text-align:center;font-weight:500;">
    <span>Dapat Dinilai: <strong><?= (int)$summary['dapat_dinilai_n'] ?></strong></span>
    <span style="color:#cbd5e1;">|</span>
    <span>BDN: <strong><?= (int)$summary['bdn_n'] ?></strong></span>
    <span style="color:#cbd5e1;">|</span>
    <span>Bukti Belum Memadai: <strong><?= (int)$summary['bukti_kurang_n'] ?></strong></span>
    <span style="color:#cbd5e1;">|</span>
    <span>Bobot Dinilai: <strong><?= (int)$summary['bobot_dinilai'] ?>%</strong></span>
</div>

<form method="post">
<?= csrfField() ?>
<input type="hidden" name="status_tanggal" value="<?= h($mitra['status_tanggal']) ?>">
<?php
// Target siklus aktif saat ini
$formActiveSMId = null;
try {
    $stmtFormSM = $pdo->prepare("SELECT id FROM siklus_monev WHERE mitra_id = ? AND status_siklus IN ('Sedang Dinilai', 'Perlu Penilaian Segera') ORDER BY siklus_ke ASC LIMIT 1");
    $stmtFormSM->execute([$id]);
    $formActiveSMId = $stmtFormSM->fetchColumn() ?: null;
    if (!$formActiveSMId) {
        $stmtNextFormSM = $pdo->prepare("SELECT id FROM siklus_monev WHERE mitra_id = ? AND status_siklus NOT IN ('Selesai', 'selesai evaluasi') ORDER BY siklus_ke ASC LIMIT 1");
        $stmtNextFormSM->execute([$id]);
        $formActiveSMId = $stmtNextFormSM->fetchColumn() ?: null;
    }
} catch (Throwable $e) {}
?>
<?php if ($formActiveSMId): ?>
<input type="hidden" name="target_siklus_id" value="<?= (int)$formActiveSMId ?>">
<?php endif; ?>

<div class="card">
    <h2>Identitas Kerja Sama</h2>
    <div class="form-grid">
        <div class="field">
            <label>Nama Mitra</label>
            <input type="text" value="<?= h($mitra['nama_mitra']) ?>" disabled>
        </div>
        <div class="field">
            <label>Nomor/Tanggal Naskah</label>
            <input type="text" value="<?= h($mitra['kode'] . ' / ' . formatTanggal($mitra['tanggal_mulai'])) ?>" disabled>
        </div>
        <div class="field">
            <label>Ruang Lingkup</label>
            <input type="text" value="<?= h($mitra['judul']) ?>" disabled>
        </div>
        <div class="field">
            <label>Periode Penilaian</label>
            <input type="text" value="<?= h(formatTanggal($mitra['cutoff_date'])) ?>" disabled>
        </div>
        <div class="field">
            <label>Reviewer/Pengelola</label>
            <?php
            // Bug 8.2: Tampilkan nama reviewer aktual dari pemeriksa_id join users
            $reviewerName = 'Belum Ditugaskan';
            if (!empty($mitra['pemeriksa_id'])) {
                $stmtRev = $pdo->prepare('SELECT nama FROM users WHERE id = ?');
                $stmtRev->execute([$mitra['pemeriksa_id']]);
                $rev = $stmtRev->fetchColumn();
                if ($rev) $reviewerName = $rev;
            }
            ?>
            <input type="text" value="<?= h($reviewerName) ?>" disabled>
        </div>
        <div class="field">
            <label>Tanggal Penilaian</label>
            <input type="date" name="tanggal_review" value="<?= h($mitra['tanggal_review'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
        </div>
        <div class="field">
            <label>PIC/Focal Point</label>
            <input type="text" name="pic_focal_point" value="<?= h($mitra['pic_focal_point'] ?? '') ?>" placeholder="Nama PIC / Focal Point" <?= $canEdit ? '' : 'disabled' ?>>
        </div>
        <div class="field">
            <label>Masa Berlaku</label>
            <input type="text" value="<?= formatTanggal($mitra['tanggal_mulai']) . ' s.d. ' . formatTanggal($mitra['tanggal_berakhir']) ?>" disabled>
        </div>
    </div>
    <div class="form-grid" style="margin-top:14px;border-top:1px solid var(--border,#e2e8f0);padding-top:14px;">
        <div class="field">
            <label>Kondisi Awal (Baseline FIX)</label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input value="<?= $summary['baseline']['is_locked'] ? '🔒 Terkunci (Final)' : '⏳ ' . $summary['baseline']['terverifikasi'] . '/12 Terverifikasi' ?>" disabled style="flex:1;">
                <a href="baseline.php?id=<?= $id ?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:6px 10px;">Buka &rarr;</a>
            </div>
        </div>
        <div class="field">
            <label>Dokumen Naskah Asli</label>
            <div>
                <?php if (!empty($mitra['file_naskah'])): ?>
                    <a href="<?= h($mitra['file_naskah']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;font-size:12px;font-weight:600;color:#1e40af;border-color:#93c5fd;background:#eff6ff;">
                        📄 Buka Naskah Resmi (PDF) &rarr;
                    </a>
                <?php else: ?>
                    <span class="muted" style="font-size:12px;">Belum diunggah</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$rencanaKerja = [];
$siklusMonev = [];
try {
    $stmtRK = $pdo->prepare('SELECT * FROM rencana_kerja WHERE mitra_id = ? ORDER BY tanggal_mulai DESC');
    $stmtRK->execute([$id]);
    $rencanaKerja = $stmtRK->fetchAll();

    $stmtSM = $pdo->prepare('SELECT * FROM siklus_monev WHERE mitra_id = ? ORDER BY siklus_ke ASC');
    $stmtSM->execute([$id]);
    $siklusMonev = $stmtSM->fetchAll();
} catch (Throwable $e) {}
$monev = $summary['monev'];
?>

<div class="card" style="margin-top:20px;">
    <div class="flex-between">
        <div>
            <h2 style="margin:0;">Jadwal Evaluasi &amp; Rencana Kerja</h2>
            <div class="muted" style="font-size:13px;">Target evaluasi berkala per triwulan selama masa berlaku kerja sama</div>
        </div>
        <div>
            <?php if ($monev['warning_1_bulan']): ?>
            <span class="badge badge-warning" style="font-size:12px;">⚠️ Evaluasi Mendekati Tenggat</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="kpi-grid" style="margin:16px 0;grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));">
        <div class="kpi-card">
            <div class="kpi-value"><?= $monev['durasi_bulan'] ?> Bulan</div>
            <div class="kpi-label">Durasi Berjalan</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-value"><?= $monev['total_siklus'] ?> Kali</div>
            <div class="kpi-label">Target Evaluasi Rencana Kerja</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-value" style="font-size:15px;"><?= $monev['target_evaluasi_terdekat'] ? formatTanggal($monev['target_evaluasi_terdekat']) : '-' ?></div>
            <div class="kpi-label">Evaluasi Terdekat</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-value" style="font-size:15px;"><?= $monev['hari_menuju_evaluasi'] !== null ? ($monev['hari_menuju_evaluasi'] >= 0 ? $monev['hari_menuju_evaluasi'] . ' hari lagi' : abs($monev['hari_menuju_evaluasi']) . ' hari lalu') : '-' ?></div>
            <div class="kpi-label">Sisa Waktu Penilaian</div>
        </div>
    </div>

    <?php if (!empty($rencanaKerja)): ?>
    <h3 style="font-size:15px;margin:16px 0 8px;">Rencana Kerja Terkait</h3>
    <div class="table-wrap" style="margin-bottom:16px;">
        <table>
            <thead>
                <tr><th>Judul Rencana Kerja</th><th>Periode Pelaksanaan</th><th>Ruang Lingkup</th><th>Status Persetujuan</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rencanaKerja as $rk): 
                    $rkStatus = trim((string)($rk['status'] ?? 'Draft'));
                    $rkBadge = match(strtolower($rkStatus)) {
                        'disetujui' => 'success',
                        'proses', 'dalam proses' => 'warning',
                        'draft' => 'secondary',
                        default => 'secondary'
                    };
                ?>
                <tr>
                    <td><strong><?= h($rk['judul_rencana']) ?></strong></td>
                    <td><?= formatTanggal($rk['tanggal_mulai']) ?> s.d. <?= formatTanggal($rk['tanggal_selesai']) ?></td>
                    <td><?= h(singkat($rk['ruang_lingkup'] ?? '-', 60)) ?></td>
                    <td><span class="badge badge-<?= $rkBadge ?>"><?= h($rk['status']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <h3 style="font-size:15px;margin:16px 0 8px;">Jadwal Evaluasi Berkala</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Siklus</th><th>Nama Evaluasi</th><th>Target Tanggal</th><th>Status</th><th>Keterangan / Realisasi</th></tr>
            </thead>
            <tbody>
                <?php if (!empty($siklusMonev)): ?>
                    <?php foreach ($siklusMonev as $sm): 
                        $smBadge = match($sm['status_siklus']) {
                            'Selesai' => 'success',
                            'Perlu Penilaian Segera' => 'warning',
                            'Sedang Dinilai' => 'info',
                            default => 'secondary'
                        };
                    ?>
                    <tr>
                        <td><strong>SC-<?= $sm['siklus_ke'] ?></strong></td>
                        <td><?= h($sm['nama_siklus']) ?></td>
                        <td><?= formatTanggal($sm['tanggal_target_evaluasi']) ?></td>
                        <td><span class="badge badge-<?= $smBadge ?>"><?= h($sm['status_siklus']) ?></span></td>
                        <td><?= h($sm['catatan_monev'] ?? ($sm['tanggal_realisasi_evaluasi'] ? 'Selesai: ' . formatTanggal($sm['tanggal_realisasi_evaluasi']) : '-')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach (($monev['milestones'] ?? []) as $ms): ?>
                    <tr>
                        <td><strong>SC-<?= $ms['siklus_ke'] ?></strong></td>
                        <td><?= h($ms['nama']) ?></td>
                        <td><?= formatTanggal($ms['target_tgl']) ?></td>
                        <td>
                            <?php if ($ms['is_past']): ?>
                                <span class="badge badge-secondary">Terlewati</span>
                            <?php elseif ($ms['is_due_soon']): ?>
                                <span class="badge badge-warning">Jatuh Tempo Segera</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Menunggu</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $ms['sisa_hari'] >= 0 ? $ms['sisa_hari'] . ' hari lagi' : abs($ms['sisa_hari']) . ' hari lalu' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <h2>Kendali &amp; Rekomendasi</h2>
    <div class="form-grid">
        <div class="field">
            <label>Posisi Portofolio</label>
            <select name="posisi_portofolio" <?= $canEdit ? '' : 'disabled' ?>>
                <?php
                $posOpts = ['BELUM DAPAT DITENTUKAN','AKTIF','OUTPUT TERSEDIA','OUTCOME TERBENTUK','BERDAMPAK'];
                if (!empty($summary['posisi_portofolio']) && !in_array($summary['posisi_portofolio'], $posOpts, true)) {
                    $posOpts[] = $summary['posisi_portofolio'];
                }
                foreach ($posOpts as $opt): ?>
                <option value="<?= h($opt) ?>" <?= $summary['posisi_portofolio'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="grid-column: 1 / -1;">
            <label>Rekomendasi Tindak Lanjut</label>
            <div style="display:flex;gap:10px;margin-bottom:6px;align-items:center;">
                <select id="rekomendasi_keyword" name="rekomendasi_keyword" onchange="applyRekomKeyword(this.value)" <?= $canEdit ? '' : 'disabled' ?> style="max-width:220px;">
                    <?php
                    $cleanRekOptions = ['BELUM DITENTUKAN', 'LANJUT', 'PERBAIKI', 'PERPANJANG', 'REPLIKASI', 'HENTIKAN'];
                    $selectedRek = ekstrakKeywordRekomendasi($summary['rekomendasi'] ?? null);
                    if (!in_array($selectedRek, $cleanRekOptions, true)) {
                        $selectedRek = 'BELUM DITENTUKAN';
                    }
                    foreach ($cleanRekOptions as $opt): ?>
                    <option value="<?= h($opt) ?>" <?= $selectedRek === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="muted" style="font-size:11.5px;">Pilih kata kunci di atas atau sunting narasi rekomendasi/audit lengkap di bawah:</span>
            </div>
            <textarea name="rekomendasi" id="textarea_rekomendasi" rows="4" style="width:100%;font-size:12.5px;line-height:1.4;" <?= $canEdit ? '' : 'readonly' ?>><?= h($summary['rekomendasi'] ?? '') ?></textarea>
            <script>
            function applyRekomKeyword(val) {
                const ta = document.getElementById('textarea_rekomendasi');
                if (!ta) return;
                let cur = ta.value.trim();
                if (!cur || cur === 'BELUM DITENTUKAN') {
                    ta.value = val;
                    return;
                }
                const prefixRegex = /^(?:(?:LANJUT|PERBAIKI|PERPANJANG|REPLIKASI|HENTIKAN|BELUM DITENTUKAN)\s*[-—–:]\s*)+/i;
                let cleaned = cur.replace(prefixRegex, '').trim();
                if (val === 'BELUM DITENTUKAN') {
                    ta.value = cleaned ? cleaned : 'BELUM DITENTUKAN';
                } else if (cleaned) {
                    ta.value = val + " — " + cleaned;
                } else {
                    ta.value = val;
                }
            }
            </script>
        </div>
    </div>
</div>

<div class="section-title">Penilaian Indikator Kinerja</div>
<?php foreach ($summary['indikator'] as $row):
    $kode = $row['kode_indikator'];
    $cek = hitungCekIndikator($row);
    $cekColor = $cek === 'OK' ? 'success' : ($cek === 'PERIKSA BUKTI' ? 'secondary' : 'warning');

    $statusOptions = ['DAPAT DINILAI', 'BUKTI BELUM MEMADAI', 'BELUM DAPAT DINILAI', 'BELUM DITELAAH'];
    $curStatus = $row['status_pemeriksaan'] ?? 'BELUM DITELAAH';
    if (in_array($curStatus, ['BUKTI CUKUP', 'BUKTI MEMADAI'], true)) {
        $curStatus = 'DAPAT DINILAI';
    } elseif (in_array($curStatus, ['BUKTI BELUM CUKUP'], true)) {
        $curStatus = 'BUKTI BELUM MEMADAI';
    } elseif (!in_array($curStatus, $statusOptions, true)) {
        $curStatus = 'BELUM DAPAT DINILAI';
    }
    $isScorable = ($curStatus === 'DAPAT DINILAI');
    $kondisiBaselineVal = !empty($row['kondisi_baseline']) ? $row['kondisi_baseline'] : ($row['referensi_baseline'] ?? '-');
?>
<div class="indikator-block">
    <div class="indikator-head">
        <div>
            <span class="kode"><?= h($kode) ?> &mdash; <?= h(INDIKATOR_NAMA[$kode] ?? 'Indikator ' . $kode) ?></span>
            <span class="bobot">Bobot <?= (int)$row['bobot'] ?>%</span>
        </div>
        <span class="cek-pill badge-<?= $cekColor ?>"><?= h($cek) ?></span>
    </div>
    <div class="indikator-body">
        <?php $apaYangDinilai = INDIKATOR_APA_YANG_DINILAI[$kode] ?? ($row['deskripsi'] ?? ''); ?>
        <div class="field" style="margin: 10px 0;">
            <label>Apa yang Dinilai</label>
            <textarea readonly disabled rows="2" style="background:#f8fafc;color:#1e293b;font-weight:500;resize:none;min-height:52px;line-height:1.45;"><?= h($apaYangDinilai) ?></textarea>
        </div>
        <div class="field" style="margin: 10px 0;">
            <label>Kondisi Baseline</label>
            <input type="text" value="<?= h($kondisiBaselineVal) ?>" readonly disabled>
        </div>
        <div class="field" style="margin: 10px 0;">
            <label>Kondisi Saat Ini</label>
            <input type="text" name="kondisi_saat_ini_<?= $kode ?>" value="<?= h($row['kondisi_saat_ini'] ?? '') ?>" placeholder="Fakta kondisi terkini pasca-baseline" <?= $canEdit ? '' : 'disabled' ?>>
        </div>
        <div class="form-grid">
            <div class="field">
                <label>Status Penilaian</label>
                <select name="status_<?= $kode ?>" class="status-select" data-kode="<?= $kode ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <?php foreach ($statusOptions as $opt): ?>
                    <option value="<?= $opt ?>" <?= $curStatus === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Skor (0–4)</label>
                <select name="skor_<?= $kode ?>" class="skor-select" data-kode="<?= $kode ?>" data-bobot="<?= (int)$row['bobot'] ?>"
                    <?= (!$canEdit || !$isScorable) ? 'disabled' : '' ?>>
                    <option value="">-</option>
                    <?php for ($s = 0; $s <= 4; $s++): ?>
                    <option value="<?= $s ?>" <?= (int)$row['skor'] === $s && $row['skor'] !== null ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="field">
                <label>Nilai Bobot</label>
                <input type="text" class="nilai-bobot-display" data-kode="<?= $kode ?>" value="<?= $row['nilai'] !== null ? number_format($row['nilai'], 2) : '-' ?>" disabled>
            </div>
        </div>
        <div class="field" style="margin-top:10px;">
            <label>Evidence/Lokasi Bukti</label>
            <textarea name="temuan_<?= $kode ?>" placeholder="Fakta singkat dan lokasi/link/file evidence..." <?= $canEdit ? '' : 'disabled' ?>><?= h($row['temuan_bukti']) ?></textarea>
        </div>
        <div class="form-grid" style="margin-top:10px;">
            <div class="field">
                <label>Alasan Skor/Temuan</label>
                <textarea name="alasan_<?= $kode ?>" placeholder="Alasan pemberian skor merujuk rubrik..." <?= $canEdit ? '' : 'disabled' ?>><?= h($row['alasan_skor']) ?></textarea>
            </div>
            <div class="field">
                <label>Catatan Tindak Lanjut</label>
                <textarea name="catatan_tl_<?= $kode ?>" placeholder="Tindak lanjut yang diperlukan dari temuan..." <?= $canEdit ? '' : 'disabled' ?>><?= h($row['catatan_tindak_lanjut'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<div class="section-title">Early Warning System (EWS) — Area Kontrol</div>
<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th>Dimensi</th><th>Kondisi</th><th>Status</th><th>Fakta/Bukti</th><th>Tindakan</th><th>PIC</th><th>Tenggat</th><th>Progres</th></tr></thead>
        <tbody>
        <?php
        $dimDisplayMap = [
            'Masa berlaku' => 'Masa Berlaku',
            'Aktivitas/tenggat' => 'Pelaksanaan/Tenggat',
            'Data/eviden' => 'Data/Evidence',
            'PIC' => 'Penanggung Jawab/Koordinasi',
        ];
        $ewsStatusLabels = [
            'V0' => 'V0 Data Belum Cukup',
            'E0' => 'E0 Normal',
            'E1' => 'E1 Perhatian',
            'E2' => 'E2 Perlu Tindakan',
            'E3' => 'E3 Kritis',
        ];
        foreach ($summary['warning_rows'] as $w):
            $key = preg_replace('/[^a-zA-Z]/', '', $w['dimensi']);
            $isMasaBerlaku = $w['dimensi'] === 'Masa berlaku';
            $displayDim = $dimDisplayMap[$w['dimensi']] ?? $w['dimensi'];
            $stLabel = $ewsStatusLabels[$w['status']] ?? labelWarning($w['status']);
        ?>
        <tr>
            <td><strong><?= h($displayDim) ?></strong>
                <?php if ($isMasaBerlaku): ?><div class="muted" style="font-size:11px;">otomatis</div><?php endif; ?>
            </td>
            <td>
                <?php if ($isMasaBerlaku): ?>
                    <input value="<?= h($w['kondisi']) ?>" disabled>
                <?php else: ?>
                    <select name="warn_kondisi_<?= $key ?>" <?= $canEdit ? '' : 'disabled' ?>>
                        <?php foreach (array_keys(KONDISI_OPTIONS[$w['dimensi']]) as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $w['kondisi'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </td>
            <td><span class="badge badge-<?= warnaWarning($w['status']) ?>"><?= h($stLabel) ?></span></td>
            <td><input type="text" name="warn_fakta_<?= $key ?>" value="<?= h($w['fakta_bukti']) ?>" <?= $canEdit ? '' : 'disabled' ?>></td>
            <td><input type="text" name="warn_tindakan_<?= $key ?>" value="<?= h($w['tindakan']) ?>" <?= $canEdit ? '' : 'disabled' ?>></td>
            <td><input type="text" name="warn_pic_<?= $key ?>" value="<?= h($w['pic']) ?>" <?= $canEdit ? '' : 'disabled' ?>></td>
            <td><input type="date" name="warn_tenggat_<?= $key ?>" value="<?= h($w['tenggat']) ?>" <?= $canEdit ? '' : 'disabled' ?>></td>
            <td>
                <select name="warn_progres_<?= $key ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <?php foreach (['BELUM MULAI','DALAM PROSES','SELESAI'] as $pg): ?>
                    <option value="<?= $pg ?>" <?= $w['progres'] === $pg ? 'selected' : '' ?>><?= $pg ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted" style="font-size:12px;margin-top:10px;margin-bottom:0;">
        Warning tertinggi saat ini: <strong><?= h($ewsStatusLabels[$summary['warning']['status']] ?? $summary['warning']['label']) ?></strong> &mdash;
        tingkat penanganan: <strong><?= h($summary['warning']['tingkat_penanganan']) ?></strong>
    </p>
</div>

<div class="section-title">Kebutuhan Intervensi Pimpinan</div>
<div class="alert alert-info">Isi jika terdapat kendala yang memerlukan arahan atau keputusan pimpinan.</div>
<div class="card">
    <div class="table-wrap">
    <table>
        <thead><tr><th style="width:40%;">Pemicu</th><th>Ada?</th><th>Bukti/Alasan</th></tr></thead>
        <tbody>
        <?php
        $perluIntervensi = false;
        foreach ($summary['pemicu_rows'] as $p):
            if (($p['jawaban'] ?? '') === 'YA') {
                $perluIntervensi = true;
            }
        ?>
        <tr>
            <td><?= h($p['pemicu_teks']) ?></td>
            <td>
                <select name="pemicu_<?= $p['no_pemicu'] ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <?php foreach (['BELUM DIPASTIKAN','TIDAK','YA'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $p['jawaban'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="pemicu_bukti_<?= $p['no_pemicu'] ?>" value="<?= h($p['bukti_alasan']) ?>" <?= $canEdit ? '' : 'disabled' ?>></td>
        </tr>
        <?php endforeach;
        if ($summary['hasil_uji'] === 'CALON BUTUH INTERVENSI PIMPINAN') {
            $perluIntervensi = true;
        }
        ?>
        </tbody>
    </table>
    </div>

    <div style="margin-top:12px;padding:10px 14px;background:#f8fafc;border:1px solid var(--border,#e2e8f0);border-radius:6px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div style="font-weight:600;font-size:13px;color:#1e293b;">
            Perlu Intervensi Pimpinan:
            <?php if ($perluIntervensi): ?>
                <span class="badge badge-danger" style="font-size:12px;margin-left:6px;">YA</span>
            <?php else: ?>
                <span class="badge badge-success" style="font-size:12px;margin-left:6px;">TIDAK</span>
            <?php endif; ?>
        </div>
        <div style="font-size:12px;color:#64748b;">
            Hasil Uji: <span class="badge badge-<?= $summary['hasil_uji'] === 'CALON BUTUH INTERVENSI PIMPINAN' ? 'warning' : 'secondary' ?>"><?= h($summary['hasil_uji']) ?></span>
            &nbsp;|&nbsp; Cek Usulan: <strong><?= h($summary['cek_usulan']) ?></strong>
        </div>
    </div>

    <?php $usulan = $summary['usulan']; ?>
    <div class="field" style="margin-top:14px;">
        <label>Uraian Singkat Kendala</label>
        <textarea name="uraian_kendala" placeholder="Uraikan kendala faktual yang dihadapi..." <?= $canEdit ? '' : 'disabled' ?>><?= h($usulan['uraian_kendala'] ?? '') ?></textarea>
    </div>
    <div class="form-grid" style="margin-top:10px;">
        <div class="field">
            <label>Alasan Utama</label>
            <textarea name="upaya_dilakukan" placeholder="Alasan utama perlunya intervensi atau upaya yang telah dilakukan..." <?= $canEdit ? '' : 'disabled' ?>><?= h($usulan['upaya_dilakukan'] ?? '') ?></textarea>
        </div>
        <div class="field">
            <label>Keputusan Spesifik yang Diminta</label>
            <textarea name="keputusan_diminta" placeholder="Bentuk keputusan atau arahan pimpinan yang diharapkan..." <?= $canEdit ? '' : 'disabled' ?>><?= h($usulan['keputusan_diminta'] ?? '') ?></textarea>
        </div>
    </div>
</div>

<?php if ($canEdit): ?>
<div style="margin:20px 0 40px;">
    <button type="submit" class="btn btn-primary">Simpan Penilaian</button>
</div>
<?php endif; ?>
</form>

<script>
// Kunci field Skor mengikuti Status Pemeriksaan — server tetap menegakkan aturan ini ulang saat simpan.
document.querySelectorAll('.status-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
        var kode = this.dataset.kode;
        var skorSel = document.querySelector('.skor-select[data-kode="' + kode + '"]');
        if (this.value === 'DAPAT DINILAI' || this.value === 'BUKTI CUKUP' || this.value === 'BUKTI MEMADAI') {
            skorSel.disabled = false;
        } else {
            skorSel.disabled = true;
            skorSel.value = '';
        }
        if (skorSel) {
            skorSel.dispatchEvent(new Event('change'));
        }
    });
});

// Auto-compute nilai_bobot display when skor changes
document.querySelectorAll('.skor-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
        var bobot = parseFloat(this.dataset.bobot) || 0;
        var block = this.closest('.indikator-block');
        var nilaiInput = block ? block.querySelector('.nilai-bobot-display') : document.querySelector('.nilai-bobot-display[data-kode="' + this.dataset.kode + '"]');
        if (nilaiInput) {
            if (this.value !== '' && !this.disabled) {
                var skor = parseFloat(this.value);
                var nilai = (skor / 4.0) * bobot;
                nilaiInput.value = nilai.toFixed(2);
            } else {
                nilaiInput.value = '-';
            }
        }
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
