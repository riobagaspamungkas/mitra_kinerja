<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireRole(['admin', 'pemeriksa', 'validator']);

$pdo = getDB();
$user = currentUser();
$userRole = $user['role'] ?? 'pemeriksa';
$id = (int)($_GET['id'] ?? 0);

// Jika pemeriksa membuka detail validasi, arahkan langsung ke form penilaian (mitra_edit.php)
if ($id > 0 && $userRole === 'pemeriksa') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        http_response_code(403);
        die('Akses ditolak: Hanya validator dan administrator yang berwenang menetapkan keputusan validasi.');
    }
    header('Location: mitra_edit.php?id=' . $id);
    exit;
}

/* ── LIST VIEW (Jika tidak ada ?id=) ──────────────────────── */
if ($id <= 0) {
    $all = getAllMitraSummary($pdo);
    $pageTitle = 'Penilaian';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="flex-between" style="margin-bottom:18px;">
        <div>
            <h1 style="margin:0;font-size:20px;">Penilaian &amp; Validasi Naskah</h1>
            <div class="muted" style="font-size:13px;">Pelaksanaan evaluasi berkala (SC-1, SC-2, dst.) untuk pemeriksa dan persetujuan validator</div>
        </div>
        <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
    </div>

    <div class="card">
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:8%;">Kode</th>
                    <th style="width:22%;">Mitra &amp; Bidang</th>
                    <th style="width:16%;">Siklus Monev &amp; Target</th>
                    <th style="width:14%;">Status Jadwal</th>
                    <th style="width:10%;">Kelengkapan</th>
                    <th style="width:14%;">Status Scorecard</th>
                    <th style="width:16%;">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($all as $s): 
                $m = $s['mitra']; 
                $monev = $s['monev'];
                $msLabel = 'SC-1';
                $msTarget = $monev['target_evaluasi_terdekat'] ?? $m['tanggal_berakhir'];
                $msDays = $monev['hari_menuju_evaluasi'] ?? 0;
                $isDuePast = false;

                if (!empty($monev['milestones'])) {
                    foreach ($monev['milestones'] as $ms) {
                        if (!empty($ms['is_due_soon']) || !empty($ms['is_past'])) {
                            $msLabel = $ms['nama'];
                            $msTarget = $ms['target_tgl'];
                            $msDays = $ms['sisa_hari'];
                            $isDuePast = $ms['is_past'];
                            break;
                        }
                    }
                }
            ?>
                <tr>
                    <td><strong><?= h($m['kode']) ?></strong><br><span class="muted" style="font-size:10.5px;"><?= h($m['portofolio']) ?></span></td>
                    <td>
                        <div style="font-weight:600;color:#1e293b;"><?= h($m['nama_mitra']) ?></div>
                        <span class="badge badge-secondary" style="font-size:10px;margin-top:2px;"><?= h($m['bidang'] ?? 'AHU') ?> &bull; <?= h($m['jenis']) ?></span>
                    </td>
                    <td>
                        <strong style="color:#1e40af;"><?= h($msLabel) ?></strong>
                        <div class="muted" style="font-size:11px;margin-top:2px;">Target: <?= formatTanggal($msTarget) ?></div>
                    </td>
                    <td>
                        <?php if ($isDuePast || ($msDays !== null && $msDays < 0)): ?>
                            <span class="badge badge-success" style="font-size:10.5px;">✓ Siap Dinilai (Lewat Tenggat)</span>
                            <div class="muted" style="font-size:10px;margin-top:2px;"><?= abs($msDays) ?> hari setelah tenggat</div>
                        <?php elseif ($msDays !== null && $msDays <= 30): ?>
                            <span class="badge badge-warning" style="font-size:10.5px;">⏳ Mendekati (<?= $msDays ?> hari)</span>
                            <div class="muted" style="font-size:10px;margin-top:2px;">Periode input pengampu</div>
                        <?php else: ?>
                            <span class="badge badge-secondary" style="font-size:10.5px;">Menunggu Jadwal</span>
                            <div class="muted" style="font-size:10px;margin-top:2px;"><?= $msDays ?> hari lagi</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:600;font-size:12px;"><?= $s['kelengkapan'] ?>%</div>
                        <div class="hbar-track" style="width:65px;height:5px;display:inline-block;">
                            <div class="hbar-fill" style="width:<?= $s['kelengkapan'] ?>%;background:<?= $s['kelengkapan'] >= 100 ? '#16a34a' : '#ca8a04' ?>;"></div>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:600;font-size:11.5px;"><?= h($s['status_scorecard']) ?></div>
                        <?php
                        $vBadge = match($s['validasi']['status']) {
                            'DISETUJUI' => 'success',
                            'PERLU PERBAIKAN' => 'danger',
                            default => 'secondary'
                        };
                        ?>
                        <span class="badge badge-<?= $vBadge ?>" style="font-size:10px;margin-top:2px;"><?= h($s['validasi']['status']) ?></span>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <?php if ($userRole === 'pemeriksa' || $userRole === 'admin'): ?>
                                <a href="mitra_edit.php?id=<?= $m['id'] ?>" class="btn btn-primary btn-sm" style="font-size:11px;padding:3px 7px;">
                                    📝 Nilai <?= h(explode(':', $msLabel)[0]) ?> &rarr;
                                </a>
                            <?php endif; ?>
                            <?php if ($userRole === 'validator' || $userRole === 'admin'): ?>
                                <a href="mitra_validasi.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 7px;">
                                    🔍 Validasi &rarr;
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ── DETAIL & FORM VALIDASI (Jika ada ?id=) ───────────────── */
$stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
$stmt->execute([$id]);
$mitra = $stmt->fetch();
if (!$mitra) { http_response_code(404); die('Naskah tidak ditemukan.'); }

$errors = [];
$saved = false;
$summary = getMitraSummary($pdo, $mitra);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!in_array($userRole, ['admin', 'validator'], true)) {
        http_response_code(403);
        die('Akses ditolak: Hanya validator dan administrator yang berwenang menetapkan keputusan validasi.');
    }
    $status = $_POST['status'] ?? 'BELUM';
    $catatan = trim($_POST['catatan'] ?? '');

    if (!in_array($status, ['BELUM','DISETUJUI','PERLU PERBAIKAN'], true)) {
        $errors[] = 'Status validasi tidak valid.';
    } elseif ($status === 'DISETUJUI' && $summary['kelengkapan'] < 100) {
        $errors[] = 'Tidak dapat menyetujui: kelengkapan indikator belum 100%.';
    } elseif ($status === 'PERLU PERBAIKAN' && $catatan === '') {
        $errors[] = 'Tulis catatan bagian yang harus diperbaiki.';
    } else {
        $stmtV = $pdo->prepare(
            'INSERT INTO validasi (mitra_id, status, validator_id, tanggal_validasi, catatan)
             VALUES (?, ?, ?, CURDATE(), ?)
             ON DUPLICATE KEY UPDATE status=VALUES(status), validator_id=VALUES(validator_id), tanggal_validasi=VALUES(tanggal_validasi), catatan=VALUES(catatan)'
        );
        $stmtV->execute([$id, $status, $user['id'], $catatan ?: null]);
        syncStatusScorecard($pdo, $id);
        logAudit($id, $user['id'], 'VALIDASI', 'Status validasi diubah menjadi ' . $status);
        $saved = true;
        $summary = getMitraSummary($pdo, $mitra);
    }
}

$pageTitle = 'Validasi ' . $mitra['kode'];
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:14px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Validasi — <?= h($mitra['kode']) ?> <?= h($mitra['nama_mitra']) ?></h1>
    </div>
    <a href="mitra_validasi.php" class="btn btn-outline btn-sm">&larr; Daftar Validasi</a>
</div>

<?php if ($saved): ?><div class="alert alert-info">Status validasi tersimpan.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

<div class="card">
    <h2>Ringkasan</h2>
    <div class="kpi-grid" style="grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));">
        <div class="kpi-card"><div class="kpi-value"><?= number_format($summary['nilai_berjalan'],2) ?></div><div class="kpi-label">Nilai Berjalan (<?= $summary['bobot_dinilai'] ?>%)</div></div>
        <div class="kpi-card"><div class="kpi-value"><?= $summary['kelengkapan'] ?>%</div><div class="kpi-label">Kelengkapan</div></div>
        <div class="kpi-card"><span class="badge badge-<?= warnaKategori($summary['kategori']) ?>"><?= h($summary['kategori']) ?></span><div class="kpi-label">Kategori</div></div>
        <div class="kpi-card"><span class="badge badge-secondary" style="font-size:11px;"><?= h($summary['posisi_portofolio']) ?></span><div class="kpi-label">Posisi Portofolio</div></div>
        <div class="kpi-card"><span class="badge badge-warning" style="font-size:11px;"><?= h($summary['rekomendasi']) ?></span><div class="kpi-label">Rekomendasi</div></div>
        <div class="kpi-card"><div style="font-weight:700;font-size:13px;"><?= h($summary['status_scorecard']) ?></div><div class="kpi-label">Status Scorecard</div></div>
    </div>
    <?php if ($summary['kelengkapan'] < 100): ?>
    <div class="alert alert-warning" style="margin-top:14px;">Kelengkapan indikator belum 100%. Naskah ini belum bisa disetujui — kembalikan ke pemeriksa untuk dilengkapi dulu.</div>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Tinjau per Indikator</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Kode</th><th>Status</th><th>Skor</th><th>Nilai</th><th>Cek</th></tr></thead>
        <tbody>
        <?php foreach ($summary['indikator'] as $row): $cek = hitungCekIndikator($row); ?>
        <tr>
            <td><?= h($row['kode_indikator']) ?></td>
            <td><?= h($row['status_pemeriksaan']) ?></td>
            <td><?= $row['skor'] !== null ? $row['skor'] : '-' ?></td>
            <td><?= $row['nilai'] !== null ? number_format($row['nilai'],2) : '-' ?></td>
            <td><span class="badge badge-<?= $cek === 'OK' ? 'success' : 'warning' ?>"><?= h($cek) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p style="margin-top:10px;"><a href="mitra_edit.php?id=<?= $id ?>" class="btn btn-outline btn-sm">Lihat detail lengkap (bukti, temuan, alasan)</a></p>
</div>

<div class="card">
    <h2>Keputusan Validasi</h2>
    <form method="post">
        <div class="form-grid">
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <?php foreach (['BELUM','DISETUJUI','PERLU PERBAIKAN'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= $summary['validasi']['status'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field" style="margin-top:10px;">
            <label>Catatan Validator</label>
            <textarea name="catatan"><?= h($summary['validasi']['catatan'] ?? '') ?></textarea>
        </div>
        <div style="margin-top:14px;">
            <button type="submit" class="btn btn-primary">Simpan Validasi</button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
