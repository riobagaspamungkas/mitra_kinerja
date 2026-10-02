<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$user = currentUser();
$canEdit = in_array($user['role'], ['admin', 'pemeriksa'], true);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
$stmt->execute([$id]);
$mitra = $stmt->fetch();
if (!$mitra) {
    http_response_code(404);
    die('Naskah tidak ditemukan.');
}

$errors = [];
$saved = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        die('Role Anda tidak dapat mengubah data ini.');
    }

    $pdo->beginTransaction();
    try {
        // --- A. Identitas, kontrol, posisi & rekomendasi ---
        $statusTanggal = in_array($_POST['status_tanggal'] ?? '', ['TERVERIFIKASI','BELUM TERVERIFIKASI'], true)
            ? $_POST['status_tanggal'] : ($mitra['status_tanggal'] ?? 'BELUM TERVERIFIKASI');
        $posisiPortofolio = trim($_POST['posisi_portofolio'] ?? '') ?: 'BELUM DAPAT DITENTUKAN';
        $rekomendasi = trim($_POST['rekomendasi'] ?? '') ?: 'BELUM DITENTUKAN';
        $picFocalPoint = trim($_POST['pic_focal_point'] ?? '');

        $stmtU = $pdo->prepare('UPDATE mitra_kinerja SET pemeriksa_id = ?, tanggal_review = ?, status_tanggal = ?, posisi_portofolio = ?, rekomendasi = ?, pic_focal_point = ? WHERE id = ?');
        $stmtU->execute([
            $user['id'],
            ($_POST['tanggal_review'] ?? '') !== '' ? $_POST['tanggal_review'] : null,
            $statusTanggal,
            $posisiPortofolio,
            $rekomendasi,
            $picFocalPoint ?: null,
            $id,
        ]);

        // --- B. Tujuh Indikator V2.1 (I1..I7) ---
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

            $stmtI = $pdo->prepare('SELECT bobot FROM indikator_skor WHERE mitra_id = ? AND kode_indikator = ?');
            $stmtI->execute([$id, $kode]);
            $bobot = (int)($stmtI->fetchColumn() ?: (BOBOT_INDIKATOR[$kode] ?? 10));
            $nilai = hitungNilaiIndikator($skor, $bobot);

            $stmtU2 = $pdo->prepare(
                'UPDATE indikator_skor SET status_pemeriksaan=?, kondisi_saat_ini=?, temuan_bukti=?, skor=?, alasan_skor=?, catatan_tindak_lanjut=?, nilai=?, updated_by=? WHERE mitra_id=? AND kode_indikator=?'
            );
            $stmtU2->execute([$status, $kondisiSaatIni ?: null, $temuan ?: null, $skor, $alasan ?: null, $catatanTl ?: null, $nilai, $user['id'], $id, $kode]);
        }

        // --- D. Early warning (4 dimensi) ---
        // "Masa berlaku": Kondisi & Status 100% OTOMATIS (dari tanggal berakhir/cutoff/status
        // tanggal) -- tidak menerima input Kondisi/Status dari form sama sekali, hanya field
        // penanganan (Fakta, Tindakan, PIC, Tenggat, Progres) yang bisa diisi pemeriksa.
        // 3 dimensi lain: Kondisi dipilih dari daftar tetap, Status DITURUNKAN dari Kondisi
        // itu (bukan dipilih manual) -- ditegakkan di server via statusDariKondisi().
        foreach (['Masa berlaku','Aktivitas/tenggat','Data/eviden','PIC'] as $dim) {
            $key = preg_replace('/[^a-zA-Z]/', '', $dim);
            $fakta    = trim($_POST['warn_fakta_' . $key] ?? '');
            $tindakan = trim($_POST['warn_tindakan_' . $key] ?? '');
            $pic      = trim($_POST['warn_pic_' . $key] ?? '');
            $tenggat  = $_POST['warn_tenggat_' . $key] ?? '';
            $progres  = $_POST['warn_progres_' . $key] ?? 'BELUM MULAI';

            if ($dim === 'Masa berlaku') {
                // Kondisi/status dihitung ulang setiap tampil (lihat getMitraSummary);
                // tidak perlu -- dan tidak boleh -- menerima nilai dari klien.
                $stmtW = $pdo->prepare(
                    'UPDATE early_warning SET fakta_bukti=?, tindakan=?, pic=?, tenggat=?, progres=? WHERE mitra_id=? AND dimensi=?'
                );
                $stmtW->execute([$fakta ?: null, $tindakan ?: null, $pic ?: null, $tenggat !== '' ? $tenggat : null, $progres, $id, $dim]);
            } else {
                $kondisiPost = $_POST['warn_kondisi_' . $key] ?? 'BELUM DIPERIKSA';
                $allowedKondisi = array_keys(KONDISI_OPTIONS[$dim] ?? []);
                $kondisi = in_array($kondisiPost, $allowedKondisi, true) ? $kondisiPost : 'BELUM DIPERIKSA';
                $status = statusDariKondisi($dim, $kondisi); // dihitung server, bukan dari input klien

                $stmtW = $pdo->prepare(
                    'UPDATE early_warning SET status=?, kondisi=?, fakta_bukti=?, tindakan=?, pic=?, tenggat=?, progres=? WHERE mitra_id=? AND dimensi=?'
                );
                $stmtW->execute([$status, $kondisi, $fakta ?: null, $tindakan ?: null, $pic ?: null, $tenggat !== '' ? $tenggat : null, $progres, $id, $dim]);
            }
        }

        // --- E. Uji kebutuhan intervensi pimpinan (5 pemicu) ---
        for ($no = 1; $no <= 5; $no++) {
            $jawaban = $_POST['pemicu_' . $no] ?? 'BELUM DIPASTIKAN';
            $bukti   = trim($_POST['pemicu_bukti_' . $no] ?? '');
            $stmtP = $pdo->prepare('UPDATE intervensi_pimpinan SET jawaban=?, bukti_alasan=? WHERE mitra_id=? AND no_pemicu=?');
            $stmtP->execute([$jawaban, $bukti ?: null, $id, $no]);
        }

        $kendala   = trim($_POST['uraian_kendala'] ?? '');
        $upaya     = trim($_POST['upaya_dilakukan'] ?? '');
        $keputusan = trim($_POST['keputusan_diminta'] ?? '');
        $stmtV = $pdo->prepare('SELECT id FROM intervensi_usulan WHERE mitra_id = ?');
        $stmtV->execute([$id]);
        if ($stmtV->fetch()) {
            $stmtU3 = $pdo->prepare('UPDATE intervensi_usulan SET uraian_kendala=?, upaya_dilakukan=?, keputusan_diminta=? WHERE mitra_id=?');
            $stmtU3->execute([$kendala ?: null, $upaya ?: null, $keputusan ?: null, $id]);
        } else {
            $stmtU3 = $pdo->prepare('INSERT INTO intervensi_usulan (mitra_id, uraian_kendala, upaya_dilakukan, keputusan_diminta) VALUES (?,?,?,?)');
            $stmtU3->execute([$id, $kendala ?: null, $upaya ?: null, $keputusan ?: null]);
        }

        $pdo->commit();
        syncStatusScorecard($pdo, $id);
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
<?php if (!$canEdit): ?><div class="alert alert-info">Anda melihat data ini sebagai <?= h($user['role']) ?> (mode baca saja).</div><?php endif; ?>

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
        $scBadgeColor = match($summary['status_scorecard']) {
            'FINAL/TERVALIDASI', 'FINAL' => 'success',
            'SIAP DIVALIDASI' => 'info',
            'DALAM PENILAIAN' => 'primary',
            'BUKTI BELUM MEMADAI', 'PERLU PERBAIKAN' => 'warning',
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
<input type="hidden" name="status_tanggal" value="<?= h($mitra['status_tanggal']) ?>">

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
            <input type="text" value="<?= h($user['nama'] ?? $user['name'] ?? 'Reviewer') ?>" disabled>
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
                <?php foreach ($rencanaKerja as $rk): ?>
                <tr>
                    <td><strong><?= h($rk['judul_rencana']) ?></strong></td>
                    <td><?= formatTanggal($rk['tanggal_mulai']) ?> s.d. <?= formatTanggal($rk['tanggal_selesai']) ?></td>
                    <td><?= h(singkat($rk['ruang_lingkup'] ?? '-', 60)) ?></td>
                    <td><span class="badge badge-success"><?= h($rk['status']) ?></span></td>
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
                    <?php foreach (array_slice($monev['milestones'], 0, 4) as $ms): ?>
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
        <div class="field">
            <label>Rekomendasi Tindak Lanjut</label>
            <select name="rekomendasi" <?= $canEdit ? '' : 'disabled' ?>>
                <?php
                $rekOpts = ['BELUM DITENTUKAN','LANJUT','PERBAIKI','PERPANJANG','REPLIKASI','HENTIKAN'];
                if (!empty($summary['rekomendasi']) && !in_array($summary['rekomendasi'], $rekOpts, true)) {
                    $rekOpts[] = $summary['rekomendasi'];
                }
                foreach ($rekOpts as $opt): ?>
                <option value="<?= h($opt) ?>" <?= $summary['rekomendasi'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<div class="section-title">Penilaian Indikator Kinerja</div>
<?php foreach ($summary['indikator'] as $row):
    $kode = $row['kode_indikator'];
    $cek = hitungCekIndikator($row);
    $cekColor = $cek === 'OK' ? 'success' : ($cek === 'PERIKSA BUKTI' ? 'secondary' : 'warning');

    $statusOptions = ['DAPAT DINILAI', 'BUKTI BELUM MEMADAI', 'BELUM DAPAT DINILAI'];
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
        <div><span class="kode"><?= h($kode) ?></span><span class="bobot">Bobot <?= (int)$row['bobot'] ?>%</span></div>
        <span class="cek-pill badge-<?= $cekColor ?>"><?= h($cek) ?></span>
    </div>
    <div class="indikator-body">
        <div class="field" style="margin: 10px 0;">
            <label>Apa yang Dinilai</label>
            <input type="text" value="<?= h($row['deskripsi']) ?>" readonly disabled style="background:#f8fafc;font-weight:500;">
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
