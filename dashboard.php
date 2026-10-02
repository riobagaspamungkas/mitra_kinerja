<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/ai_config.php';
requireLogin();

$pdo = getDB();
$all = getAllMitraSummary($pdo);
$stats = getDashboardStats($all);
$opStats = getOperationalStats($pdo, $all);
$tindakLanjut = getTindakLanjut($pdo, null, 5);

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';

$pilotOnly = array_filter($all, function ($s) {
    return isset($s['mitra']['portofolio']) && $s['mitra']['portofolio'] === 'Pilot Utama';
});
?>

<?php
$user = currentUser();
$userRole = $user['role'] ?? 'admin';

$dueSoonMonev = [];
foreach ($all as $s) {
    if (!empty($s['monev']['warning_1_bulan'])) {
        $dueSoonMonev[] = $s;
    }
}
?>

<?php if ($userRole === 'pengampu' || $userRole === 'pic' || !empty($dueSoonMonev)): ?>
<div class="alert alert-warning" style="margin-bottom:20px;border-left:4px solid #ea580c;background:#fff7ed;color:#9a3412;">
    <?php if ($userRole === 'pengampu'): ?>
        <div style="font-weight:700;font-size:14px;margin-bottom:4px;color:#c2410c;">
            ⚠️ Notifikasi Pengisian Jadwal Evaluasi (Akun Pengampu)
        </div>
        <div style="font-size:13px;line-height:1.5;">
            Sebagai <strong>Akun Pengampu</strong>, Anda perlu untuk melakukan pengisian setiap <strong>SC1 atau SC2 atau SC3</strong> dan siklus evaluasi lainnya, sebelum <strong>30 hari</strong> dari tenggat waktu jadwal evaluasi tersebut.
        </div>
    <?php elseif ($userRole === 'pic'): ?>
        <div style="font-weight:700;font-size:14px;margin-bottom:4px;color:#c2410c;">
            ⚠️ Pengingat Jadwal Evaluasi Kinerja (Akun PIC)
        </div>
        <div style="font-size:13px;line-height:1.5;">
            Sebagai <strong>Akun PIC</strong>, Anda perlu melakukan pemantauan dan <strong>mengingatkan Akun Pengampu</strong> untuk melakukan pengisian setiap <strong>SC1 atau SC2 atau SC3</strong> dan siklus evaluasi lainnya, sebelum <strong>30 hari</strong> dari tenggat waktu evaluasi tersebut.
        </div>
    <?php else: ?>
        <div style="font-weight:700;font-size:14px;margin-bottom:4px;">
            ⚠️ Jadwal Evaluasi Mendatang (&lt; 30 Hari)
        </div>
        <div style="font-size:13px;line-height:1.5;">
            Terdapat <strong><?= count($dueSoonMonev) ?> kerja sama</strong> yang mendekati tenggat evaluasi. Akun Pengampu perlu melakukan pengisian dan pemutakhiran setiap siklus (SC1, SC2, SC3, dst.) sebelum 30 hari dari tenggat waktu, dengan koordinasi oleh Akun PIC:
        </div>
    <?php endif; ?>

    <?php if (!empty($dueSoonMonev)): ?>
    <div style="font-size:13px;margin-top:8px;">
        <div style="font-weight:600;margin-bottom:4px;">Daftar Naskah Mendekati Tenggat (&lt; 30 Hari):</div>
        <ul style="margin:4px 0 0 18px;padding:0;">
            <?php foreach ($dueSoonMonev as $ds): 
                $msLabel = 'SC-1';
                if (!empty($ds['monev']['milestones'])) {
                    foreach ($ds['monev']['milestones'] as $ms) {
                        if (!empty($ms['is_due_soon'])) {
                            $msLabel = $ms['nama'];
                            break;
                        }
                    }
                }
            ?>
            <li style="margin-bottom:4px;">
                <strong><?= h($ds['mitra']['kode']) ?></strong> &mdash; <?= h($ds['mitra']['nama_mitra']) ?> &bull; 
                Siklus: <span class="badge badge-warning" style="font-size:10px;font-weight:600;"><?= h($msLabel) ?></span> &bull;
                Target: <strong><?= formatTanggal($ds['monev']['target_evaluasi_terdekat']) ?></strong> 
                (<?= $ds['monev']['hari_menuju_evaluasi'] !== null ? ($ds['monev']['hari_menuju_evaluasi'] >= 0 ? $ds['monev']['hari_menuju_evaluasi'] . ' hari lagi' : abs($ds['monev']['hari_menuju_evaluasi']) . ' hari lalu') : '-' ?>) &bull;
                <a href="mitra_edit.php?id=<?= $ds['mitra']['id'] ?>" style="color:#2563eb;text-decoration:underline;">Buka Penilaian &rarr;</a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Indikator Kinerja -->
<div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,#f8fafc,#eff6ff);border:1px solid #dbeafe;">
    <div class="flex-between">
        <div>
            <h3 style="margin:0;font-size:15px;color:#1e40af;">Indikator Kinerja</h3>
            <div class="muted" style="font-size:12px;">Ringkasan efektivitas, capaian hasil, dan tindak lanjut portofolio</div>
        </div>
    </div>
    <div class="kpi-grid" style="margin-top:14px;grid-template-columns:repeat(auto-fit, minmax(210px, 1fr));">
        <div class="kpi-card kpi-card-vertical" style="background:#fff;">
            <div class="kpi-value" style="color:#2563eb;"><?= $stats['aktifCount'] ?> / <?= $stats['total'] ?></div>
            <div class="kpi-label">Kerja Sama Aktif</div>
            <div class="kpi-sub">Implementasi berjalan</div>
        </div>
        <div class="kpi-card kpi-card-vertical" style="background:#fff;">
            <div class="kpi-value" style="color:#0891b2;"><?= $stats['outputOutcomeCount'] ?></div>
            <div class="kpi-label">Output &amp; Outcome</div>
            <div class="kpi-sub">Menghasilkan produk/manfaat</div>
        </div>
        <div class="kpi-card kpi-card-vertical" style="background:#fff;">
            <div class="kpi-value" style="color:#16a34a;"><?= $stats['berdampakCount'] ?></div>
            <div class="kpi-label">Berdampak</div>
            <div class="kpi-sub">Mendukung pelayanan hukum</div>
        </div>
        <div class="kpi-card kpi-card-vertical" style="background:#fff;">
            <div class="kpi-value" style="font-size:18px;color:#ca8a04;">
                <?= $stats['rekomendasiCount']['LANJUT'] + $stats['rekomendasiCount']['PERPANJANG'] + $stats['rekomendasiCount']['REPLIKASI'] ?> Lanjut / <?= $stats['rekomendasiCount']['HENTIKAN'] ?> Henti
            </div>
            <div class="kpi-label">Rekomendasi</div>
            <div class="kpi-sub"><?= $stats['rekomendasiCount']['PERBAIKI'] ?> perlu perbaikan</div>
        </div>
    </div>
</div>

<!-- KPI Row -->
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-icon kpi-icon-blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 7h6M9 11h6M9 15h4"/></svg></div>
        <div class="kpi-data">
            <div class="kpi-number"><?= $stats['total'] ?></div>
            <div class="kpi-label">Total Naskah Kerja Sama</div>
            <div class="kpi-sub"><?= $stats['pilotCount'] ?> Pilot Utama<br><?= $stats['total'] - $stats['pilotCount'] ?> Naskah Cadangan</div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon kpi-icon-navy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="9" cy="7" r="3.5"/><path d="M2 19c0-3.5 3-6 7-6s7 2.5 7 6"/><circle cx="16" cy="7" r="2.5"/><path d="M17 13c2.5 0 5 1.5 5 4.5"/></svg></div>
        <div class="kpi-data">
            <div class="kpi-number"><?= $stats['pilotCount'] ?></div>
            <div class="kpi-label">Naskah Pilot</div>
            <div class="kpi-progress"><div class="bar" style="width:<?= $stats['total'] > 0 ? round($stats['pilotCount']/$stats['total']*100) : 0 ?>%;background:#1e40af;"></div></div>
            <div class="kpi-sub"><?= $stats['total'] > 0 ? round($stats['pilotCount']/$stats['total']*100) : 0 ?>% dari total portofolio</div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon kpi-icon-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 13l3.5 3.5L17 9"/></svg></div>
        <div class="kpi-data">
            <div class="kpi-number"><?= $stats['efektif'] ?></div>
            <div class="kpi-label">Efektif</div>
            <div class="kpi-progress"><div class="bar" style="width:<?= $stats['total'] > 0 ? round($stats['efektif']/$stats['total']*100) : 0 ?>%;background:#16a34a;"></div></div>
            <div class="kpi-sub"><?= $stats['total'] > 0 ? round($stats['efektif']/$stats['total']*100) : 0 ?>% dari total</div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon kpi-icon-yellow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.5l3.5 2"/></svg></div>
        <div class="kpi-data">
            <div class="kpi-number"><?= $stats['perluPerhatian'] ?></div>
            <div class="kpi-label">Perlu Perhatian</div>
            <div class="kpi-progress"><div class="bar" style="width:<?= $stats['total'] > 0 ? round($stats['perluPerhatian']/$stats['total']*100) : 0 ?>%;background:#ca8a04;"></div></div>
            <div class="kpi-sub"><?= $stats['total'] > 0 ? round($stats['perluPerhatian']/$stats['total']*100) : 0 ?>% dari total</div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon kpi-icon-red"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 20h20L12 2z"/><path d="M12 10v4M12 17v.5"/></svg></div>
        <div class="kpi-data">
            <div class="kpi-number"><?= $stats['berisiko'] ?></div>
            <div class="kpi-label">Berisiko</div>
            <div class="kpi-progress"><div class="bar" style="width:<?= $stats['total'] > 0 ? round($stats['berisiko']/$stats['total']*100) : 0 ?>%;background:#dc2626;"></div></div>
            <div class="kpi-sub"><?= $stats['total'] > 0 ? round($stats['berisiko']/$stats['total']*100) : 0 ?>% dari total</div>
        </div>
    </div>
</div>

<!-- Charts Row -->
<div class="charts-row">
    <div class="chart-card">
        <h3>Status Efektivitas Kerja Sama</h3>
        <div class="donut-wrap">
            <canvas id="donutChart" width="160" height="160"></canvas>
            <div class="donut-legend">
                <div class="leg-item"><span class="leg-dot" style="background:#16a34a;"></span> Efektif <span class="leg-count"><?= $stats['efektif'] ?></span> <span class="leg-pct"><?= $stats['total'] > 0 ? round($stats['efektif']/$stats['total']*100) : 0 ?>%</span></div>
                <div class="leg-item"><span class="leg-dot" style="background:#ca8a04;"></span> Perlu Perhatian <span class="leg-count"><?= $stats['perluPerhatian'] ?></span> <span class="leg-pct"><?= $stats['total'] > 0 ? round($stats['perluPerhatian']/$stats['total']*100) : 0 ?>%</span></div>
                <div class="leg-item"><span class="leg-dot" style="background:#dc2626;"></span> Berisiko <span class="leg-count"><?= $stats['berisiko'] ?></span> <span class="leg-pct"><?= $stats['total'] > 0 ? round($stats['berisiko']/$stats['total']*100) : 0 ?>%</span></div>
            </div>
        </div>
    </div>
    <div class="chart-card">
        <h3>Nilai Rata-rata Scorecard</h3>
        <div class="gauge-wrap">
            <canvas id="gaugeChart" width="220" height="130"></canvas>
            <div class="gauge-center"><?= number_format($stats['rataRataNilai'], 1, ',', '.') ?></div>
            <div class="gauge-subtitle"><?= $stats['rataRataNilai'] >= 75 ? 'Baik' : 'Perlu Perbaikan' ?></div>
        </div>
    </div>
    <div class="chart-card">
        <h3>Sebaran Nilai per Aspek</h3>
        <?php foreach (ASPEK_LABELS as $kode => $label): $val = $stats['aspekRataRata'][$kode] ?? 0; ?>
        <div class="hbar-item">
            <span class="hbar-label"><?= $label ?></span>
            <div class="hbar-track"><div class="hbar-fill" style="width:<?= $val ?>%;background:linear-gradient(90deg,#2563eb,#60a5fa);"></div></div>
            <span class="hbar-val"><?= $val ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Tables Row -->
<div class="dash-tables">
    <div class="dash-table-card">
        <div class="dtc-head"><h3>Daftar Naskah Pilot</h3><a href="portofolio.php">Lihat Semua &rarr;</a></div>
        <div class="table-wrap">
        <table>
            <thead><tr><th>No</th><th>Nama Naskah</th><th>Mitra</th><th>Berlaku s.d.</th><th>Nilai</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php $pilot5 = array_slice($pilotOnly, 0, 5);
            $no = 0; foreach ($pilot5 as $s): $m = $s['mitra']; $no++; $ef = statusEfektivitas($s['kategori']); ?>
                <tr>
                    <td><?= $no ?></td>
                    <td><?= h(singkat($m['judul'] ?? '-', 40)) ?></td>
                    <td><?= h($m['nama_mitra']) ?></td>
                    <td><?= formatTanggal($m['tanggal_berakhir']) ?></td>
                    <td><strong><?= $s['nilai_berjalan'] > 0 ? number_format($s['nilai_berjalan'], 0) : '-' ?></strong></td>
                    <td><span class="badge badge-<?= warnaEfektivitas($ef) ?>"><?= $ef ?></span></td>
                    <td><a href="mitra_edit.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm">Detail</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <div class="dash-table-card">
        <div class="dtc-head"><h3>Tindak Lanjut (5 Terbaru)</h3><a href="tindak_lanjut.php">Lihat Semua &rarr;</a></div>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Naskah</th><th>Tindakan</th><th>Tenggat</th><th>Status</th></tr></thead>
            <tbody>
            <?php if (empty($tindakLanjut)): ?>
                <tr><td colspan="4" class="muted" style="text-align:center;padding:20px;">Belum ada tindak lanjut</td></tr>
            <?php else: foreach ($tindakLanjut as $tl):
                $dotClass = ($tl['status'] === 'Selesai')
            ? 'dot-green'
            : (($tl['status'] === 'Proses') ? 'dot-yellow' : 'dot-red');
            ?>
                <tr>
                    <td><?= h($tl['kode']) ?></td>
                    <td><?= h(singkat($tl['tindakan'], 40)) ?></td>
                    <td><?= formatTanggal($tl['tenggat']) ?></td>
                    <td><span class="status-dot <?= $dotClass ?>"><?= h($tl['status']) ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Operational Status & Pipeline Summary -->
<div class="dash-tables" style="grid-template-columns: 1fr 1fr; margin-bottom: 24px;">
    <!-- Card 1: Pipeline Pra-Kerja Sama & Validasi -->
    <div class="dash-table-card">
        <div class="dtc-head">
            <h3>Pipeline Usulan &amp; Antrean Validasi</h3>
            <a href="gate0.php">Ke Gate 0 &rarr;</a>
        </div>
        <div style="padding: 16px 20px;">
            <div style="margin-bottom: 14px;">
                <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    Usulan Pra-Kerja Sama (Gate 0)
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 90px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: var(--navy);"><?= $opStats['g0Total'] ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);">Total Usulan</div>
                    </div>
                    <div style="flex: 1; min-width: 90px; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #b45309;"><?= $opStats['g0Pending'] ?></div>
                        <div style="font-size: 11px; color: #92400e;">Menunggu Putusan</div>
                    </div>
                    <div style="flex: 1; min-width: 90px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #15803d;"><?= $opStats['g0Approved'] ?></div>
                        <div style="font-size: 11px; color: #166534;">Disetujui / PKS</div>
                    </div>
                </div>
            </div>

            <div>
                <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    Antrean Validasi Scorecard
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 90px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #15803d;"><?= $opStats['disetujuiValidasi'] ?></div>
                        <div style="font-size: 11px; color: #166534;">Tervalidasi Final</div>
                    </div>
                    <div style="flex: 1; min-width: 90px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #1d4ed8;"><?= $opStats['siapValidasi'] ?></div>
                        <div style="font-size: 11px; color: #1e40af;">Siap Divalidasi</div>
                    </div>
                    <div style="flex: 1; min-width: 90px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #475569;"><?= $opStats['belumLengkapValidasi'] ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);">Dalam Telaah</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Card 2: Profil Portofolio & Masa Berlaku -->
    <div class="dash-table-card">
        <div class="dtc-head">
            <h3>Profil Dokumen &amp; Pelaksanaan</h3>
            <a href="portofolio.php">Ke Portofolio &rarr;</a>
        </div>
        <div style="padding: 16px 20px;">
            <div style="margin-bottom: 14px;">
                <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    Komposisi &amp; Rencana Kerja
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 80px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: var(--navy);"><?= $opStats['mouCount'] ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);">Payung MoU</div>
                    </div>
                    <div style="flex: 1; min-width: 80px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #2563eb;"><?= $opStats['pksCount'] ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);">Perjanjian PKS</div>
                    </div>
                    <div style="flex: 1; min-width: 80px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #0891b2;"><?= $opStats['rkCount'] ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);">Rencana Kerja</div>
                    </div>
                    <div style="flex: 1; min-width: 80px; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #7c3aed;"><?= $opStats['smCount'] ?></div>
                        <div style="font-size: 11px; color: var(--text-muted);">Siklus Monev</div>
                    </div>
                </div>
            </div>

            <div>
                <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                    Ketahanan Masa Berlaku Kerja Sama
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 90px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #15803d;"><?= $opStats['validAman'] ?></div>
                        <div style="font-size: 11px; color: #166534;">Aman (&gt; 6 Bln)</div>
                    </div>
                    <div style="flex: 1; min-width: 90px; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #b45309;"><?= $opStats['validPerhatian'] ?></div>
                        <div style="font-size: 11px; color: #92400e;">1 s.d. 6 Bulan</div>
                    </div>
                    <div style="flex: 1; min-width: 90px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 10px; text-align: center;">
                        <div style="font-size: 18px; font-weight: 800; color: #b91c1c;"><?= $opStats['validKritis'] ?></div>
                        <div style="font-size: 11px; color: #991b1b;">Perlu Verifikasi / Habis</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (defined('AI_ENABLED') && AI_ENABLED): ?>
<!-- AI Floating Toast -->
<div id="aiToast" class="ai-toast" style="display:none">
    <div class="ai-toast-header">
        <div class="ai-header-left">
            <span class="ai-sparkle">✨</span>
            <span class="ai-title">Analisis AI</span>
            <span class="ai-provider"></span>
        </div>
        <div class="ai-header-right">
            <span class="ai-timestamp"></span>
            <button class="ai-refresh" title="Analisis Ulang" onclick="refreshAI()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M1 4v6h6M23 20v-6h-6"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
            </button>
            <button class="ai-close" title="Tutup" onclick="closeAIToast()">×</button>
        </div>
    </div>
    <div class="ai-toast-body">
        <div class="ai-loading" id="aiLoading">
            <div class="ai-spinner"></div>
            <span>Menganalisis data portofolio...</span>
        </div>
        <div class="ai-content" id="aiContent" style="display:none"></div>
        <div class="ai-error" id="aiError" style="display:none">
            <span class="ai-error-icon">⚠️</span>
            <span class="ai-error-msg"></span>
        </div>
    </div>
</div>

<button id="aiTriggerBtn" class="ai-trigger-btn" onclick="showAIToast()" title="Analisis AI">
    <img src="public/img/logo.png" alt="Kementerian Hukum">
</button>
<?php endif; ?>

<script src="public/js/chart.umd.min.js"></script>
<script>
(function(){
    var ctx1 = document.getElementById('donutChart').getContext('2d');
    new Chart(ctx1, { type:'doughnut', data:{ labels:['Efektif','Perlu Perhatian','Berisiko'], datasets:[{ data:[<?= $stats['efektif'] ?>,<?= $stats['perluPerhatian'] ?>,<?= $stats['berisiko'] ?>], backgroundColor:['#16a34a','#ca8a04','#dc2626'], borderWidth:0, hoverOffset:6 }] }, options:{ cutout:'62%', plugins:{ legend:{display:false} }, responsive:false } });

    var ctx2 = document.getElementById('gaugeChart').getContext('2d');
    var val = <?= $stats['rataRataNilai'] ?>;
    var clr = val >= 75 ? '#16a34a' : (val >= 50 ? '#ca8a04' : '#dc2626');
    new Chart(ctx2, { type:'doughnut', data:{ labels:['Skor',''], datasets:[{ data:[val, 100-val], backgroundColor:[clr,'#e9ecef'], borderWidth:0 }] }, options:{ rotation:-90, circumference:180, cutout:'72%', plugins:{ legend:{display:false}, tooltip:{enabled:false} }, responsive:false } });
})();

<?php if (defined('AI_ENABLED') && AI_ENABLED): ?>
/* ── AI Insight Floating Toast ─────────────────────────── */
var aiToastEl   = document.getElementById('aiToast');
var aiLoadingEl = document.getElementById('aiLoading');
var aiContentEl = document.getElementById('aiContent');
var aiErrorEl   = document.getElementById('aiError');

function showAIToast(){
    aiToastEl.style.display='flex';
    document.getElementById('aiTriggerBtn').style.display='none';
    aiLoadingEl.style.display='flex';
    aiContentEl.style.display='none';
    aiErrorEl.style.display='none';
    fetchInsight(false);
}
function closeAIToast(){
    aiToastEl.style.display='none';
    document.getElementById('aiTriggerBtn').style.display='flex';
    try{ localStorage.setItem('ai_dismissed','1'); }catch(e){}
}
function refreshAI(){
    aiLoadingEl.style.display='flex';
    aiContentEl.style.display='none';
    aiErrorEl.style.display='none';
    fetchInsight(true);
}
function fetchInsight(force){
    var opts = { headers:{'Accept':'application/json'} };
    if(force){ opts.method='POST'; }
    fetch('ai_insight.php', opts)
        .then(function(r){ return r.json(); })
        .then(function(d){
            aiLoadingEl.style.display='none';
            if(d.success){
                aiContentEl.style.display='block';
                aiContentEl.innerHTML = formatInsight(d.insight);
                var ts = d.generated_at + (d.cached ? ' (cache)' : '');
                document.querySelector('.ai-timestamp').textContent = ts;
                // Tampilkan provider badge
                var providerEl = document.querySelector('.ai-provider');
                if(providerEl){
                    var prov = d.provider || '';
                    var provClass = 'ai-prov-' + prov.toLowerCase();
                    providerEl.innerHTML = prov ? '<span class="ai-provider-badge '+provClass+'">'+prov+'</span>' : '';
                }
                try{ localStorage.setItem('ai_dismissed',''); }catch(e){}
            } else {
                var msg = d.message || 'Terjadi kesalahan';
                if(d.detail) msg += '<br><small>' + d.detail + '</small>';
                msg += '<br><small style="color:#64748b">Provider: Gemini → OpenRouter → Groq</small>';
                if(d.debug_url) msg += '<br><a href="' + d.debug_url + '" target="_blank" style="font-size:11px;color:#2563eb">🔍 Buka Diagnostic Tool</a>';
                showError(msg);
            }
        })
        .catch(function(e){
            aiLoadingEl.style.display='none';
            showError('Tidak dapat terhubung ke server AI.');
        });
}
function showError(msg){
    aiErrorEl.style.display='flex';
    aiErrorEl.querySelector('.ai-error-msg').innerHTML = msg;
}
function formatInsight(text){
    // Convert markdown-like bold **text** to <strong>
    text = text.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>');
    // Split into paragraphs
    var paras = text.split(/\n\n|\n/).filter(function(p){ return p.trim(); });
    return paras.map(function(p){ return '<p>'+p+'</p>'; }).join('');
}

// Auto-show on load if not dismissed in this session
document.addEventListener('DOMContentLoaded', function(){
    var dismissed = false;
    try { dismissed = localStorage.getItem('ai_dismissed') === '1'; } catch(e){}
    if(!dismissed){
        setTimeout(showAIToast, 800);
    } else {
        // Already dismissed — show trigger button
        document.getElementById('aiTriggerBtn').style.display='flex';
    }
});
<?php endif; ?>
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
