<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$pdo = getDB();
$all = getAllMitraSummary($pdo);
$stats = getDashboardStats($all);
$tindakLanjut = getTindakLanjut($pdo);

$pageTitle = 'Laporan';
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between no-print" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Laporan Eksekutif</h1>
        <div class="muted" style="font-size:13px;">Rekapitulasi evaluasi kinerja dan tindak lanjut kerja sama</div>
    </div>
    <div style="display:flex;gap:8px;">
        <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Cetak / Simpan PDF</button>
        <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
    </div>
</div>

<!-- Kop Surat Resmi (Tampil saat Cetak) -->
<div class="print-header" style="display:none;margin-bottom:20px;border-bottom:3px double #000;padding-bottom:12px;">
    <div style="display:flex;align-items:center;gap:16px;">
        <img src="public/img/logo-hukum.png" alt="Logo" style="height:65px;">
        <div style="text-align:center;flex:1;">
            <div style="font-size:15px;font-weight:700;letter-spacing:0.5px;">KEMENTERIAN HUKUM REPUBLIK INDONESIA</div>
            <div style="font-size:17px;font-weight:800;letter-spacing:1px;">KANTOR WILAYAH KEPULAUAN RIAU</div>
            <div style="font-size:12px;color:#333;">Jalan Daeng Celak, Senggarang, Tanjungpinang, Kepulauan Riau</div>
            <div style="font-size:13px;font-weight:700;margin-top:4px;">REKAPITULASI EFEKTIVITAS MITRA KINERJA</div>
        </div>
    </div>
</div>

<!-- Ringkasan Statistik -->
<div class="kpi-row" style="grid-template-columns: repeat(4, 1fr);">
    <div class="kpi-card"><div class="kpi-icon kpi-icon-blue">📄</div><div><div class="kpi-number"><?= $stats['total'] ?></div><div class="kpi-label">Total Naskah</div></div></div>
    <div class="kpi-card"><div class="kpi-icon kpi-icon-green">✅</div><div><div class="kpi-number"><?= $stats['efektif'] ?></div><div class="kpi-label">Efektif</div></div></div>
    <div class="kpi-card"><div class="kpi-icon kpi-icon-yellow">⚠️</div><div><div class="kpi-number"><?= $stats['perluPerhatian'] ?></div><div class="kpi-label">Perlu Perhatian</div></div></div>
    <div class="kpi-card"><div class="kpi-icon kpi-icon-red">🔴</div><div><div class="kpi-number"><?= $stats['berisiko'] ?></div><div class="kpi-label">Berisiko</div></div></div>
</div>

<!-- Rekap per Naskah -->
<div class="card">
    <h2>Rekap Semua Naskah</h2>
    <div class="table-wrap">
    <table>
        <thead>
            <tr><th>Kode</th><th>Portofolio</th><th>Mitra</th><th>Bidang</th><th>Berakhir</th><th>Nilai</th><th>Kategori</th><th>Posisi</th><th>Rekomendasi</th><th>Warning</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($all as $s): $m = $s['mitra']; ?>
            <tr>
                <td><strong><?= h($m['kode']) ?></strong></td>
                <td><?= h($m['portofolio']) ?></td>
                <td><?= h($m['nama_mitra']) ?></td>
                <td><span class="badge badge-secondary" style="font-size:11px;font-weight:600;"><?= h($m['bidang'] ?? 'AHU') ?></span></td>
                <td><?= formatTanggal($m['tanggal_berakhir']) ?></td>
                <td><?= $s['nilai_berjalan'] > 0 ? number_format($s['nilai_berjalan'], 2) : '-' ?></td>
                <td><span class="badge badge-<?= warnaKategori($s['kategori']) ?>"><?= h($s['kategori']) ?></span></td>
                <td><span class="badge badge-secondary" style="font-size:11px;"><?= h($s['posisi_portofolio']) ?></span></td>
                <td><span class="badge badge-warning" style="font-size:11px;"><?= h($s['rekomendasi']) ?></span></td>
                <td><span class="badge badge-<?= warnaWarning($s['warning']['status']) ?>"><?= h($s['warning']['label']) ?></span></td>
                <td><?= h($s['status_scorecard']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Rata-rata per Aspek -->
<div class="card">
    <h2>Rata-rata Nilai per Aspek</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Aspek</th><th>Rata-rata Nilai</th><th>Persentase</th></tr></thead>
        <tbody>
        <?php foreach (ASPEK_LABELS as $kode => $label):
            $val = $stats['aspekRataRata'][$kode] ?? 0;
            $maxScore = BOBOT_INDIKATOR[$kode];
            $pct = $maxScore > 0 ? round($val / $maxScore * 100) : 0;
        ?>
            <tr>
                <td><strong><?= $label ?></strong> (<?= h($kode) ?>)</td>
                <td><?= $val ?> / <?= $maxScore ?></td>
                <td>
                    <div class="hbar-track" style="display:inline-block;vertical-align:middle;width:120px;height:14px;">
                        <div class="hbar-fill" style="width:<?= $pct ?>%;background:linear-gradient(90deg,#2563eb,#60a5fa);"></div>
                    </div>
                    <span style="margin-left:8px;font-weight:600;font-size:12px;"><?= $pct ?>%</span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Tindak Lanjut -->
<div class="card">
    <h2>Semua Tindak Lanjut (<?= count($tindakLanjut) ?>)</h2>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Naskah</th><th>Tindakan</th><th>Tenggat</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($tindakLanjut)): ?>
            <tr><td colspan="4" class="muted" style="text-align:center;padding:20px;">Belum ada tindak lanjut</td></tr>
        <?php else: foreach ($tindakLanjut as $tl):
            $dotClass = match($tl['status']) { 'Selesai' => 'dot-green', 'Proses' => 'dot-yellow', default => 'dot-red' };
        ?>
            <tr>
                <td><strong><?= h($tl['kode']) ?></strong></td>
                <td><?= h($tl['tindakan']) ?></td>
                <td><?= formatTanggal($tl['tenggat']) ?></td>
                <td><span class="status-dot <?= $dotClass ?>"><?= h($tl['status']) ?></span></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Lembar Tanda Tangan Resmi (Cetak) -->
<div class="signature-block" style="display:none;margin-top:40px;page-break-inside:avoid;">
    <table style="width:100%;border:none;background:none;">
        <tr style="border:none;">
            <td style="border:none;width:50%;text-align:center;font-size:12pt;vertical-align:top;">
                <div>Mengetahui,</div>
                <div style="font-weight:700;">Koordinator Tim Efektif</div>
                <div style="height:70px;"></div>
                <div style="font-weight:700;text-decoration:underline;">Kepala Bagian Tata Usaha &amp; Umum</div>
                <div style="font-size:10pt;">Kanwil Kementerian Hukum Kepulauan Riau</div>
            </td>
            <td style="border:none;width:50%;text-align:center;font-size:12pt;vertical-align:top;">
                <div>Tanjungpinang, <?= formatTanggalPanjang(date('Y-m-d')) ?></div>
                <div style="font-weight:700;">Kepala Kantor Wilayah</div>
                <div style="height:70px;"></div>
                <div style="font-weight:700;text-decoration:underline;">EDISON MANIK, S.H., M.Si.</div>
                <div style="font-size:10pt;">NIP. 19780217 200112 1 002</div>
            </td>
        </tr>
    </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>