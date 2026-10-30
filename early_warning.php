<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$pdo = getDB();
$all = getAllMitraSummary($pdo);

// Collect early warning data
$warningData = [];
foreach ($all as $s) {
    $wLabel = $s['warning']['label'];
    $status = $s['warning']['status'];
    if ($status !== 'E0') {
        $warningData[] = [
            'kode' => $s['mitra']['kode'],
            'nama_mitra' => $s['mitra']['nama_mitra'],
            'tanggal_berakhir' => $s['mitra']['tanggal_berakhir'] ?? null,
            'warning_label' => $wLabel,
            'warning_status' => $status,
            'tingkat' => $s['warning']['tingkat_penanganan'],
            'hasil_uji' => $s['hasil_uji'],
            'rows' => $s['warning_rows'],
            'id' => $s['mitra']['id'],
        ];
    }
}
$severityOrder = ['E3' => 4, 'E2' => 3, 'E1' => 2, 'V0' => 1, 'E0' => 0];
usort($warningData, fn($a, $b) => ($severityOrder[$b['warning_status']] ?? -1) <=> ($severityOrder[$a['warning_status']] ?? -1));

// Filter controls
$filterStatus = trim($_GET['status'] ?? '');
if ($filterStatus !== '' && in_array($filterStatus, ['E3', 'E2', 'E1', 'V0'], true)) {
    $warningDataFiltered = array_filter($warningData, fn($w) => $w['warning_status'] === $filterStatus);
} else {
    $warningDataFiltered = $warningData;
}

$pageTitle = 'Early Warning';
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Early Warning</h1>
        <div class="muted" style="font-size:13px;">Pemantauan risiko dan jadwal evaluasi kerja sama</div>
    </div>
    <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
</div>

<?php
// Include overdue/past-due evaluations in the warning banner
$monevAlerts = [];
foreach ($all as $s) {
    $hari = $s['monev']['hari_menuju_evaluasi'] ?? null;
    $targetTgl = $s['monev']['target_evaluasi_terdekat'] ?? null;
    if ($targetTgl !== null) {
        $isOverdue = ($hari !== null && $hari < 0);
        $isDueSoon = (!empty($s['monev']['warning_1_bulan']) || ($hari !== null && $hari >= 0 && $hari <= 30));
        if ($isOverdue || $isDueSoon) {
            $monevAlerts[] = [
                's' => $s,
                'is_overdue' => $isOverdue,
                'hari' => $hari,
                'target' => $targetTgl
            ];
        }
    }
}
?>
<?php if (!empty($monevAlerts)): ?>
<div class="alert alert-warning" style="margin-bottom:20px;border-left:4px solid #ea580c;background:#fff7ed;color:#9a3412;">
    <div style="font-weight:700;font-size:14px;margin-bottom:4px;">
        ⚠️ Pemantauan Jadwal Evaluasi Berkala (Mendatang &amp; Terlewat):
    </div>
    <div style="font-size:13px;">
        Terdapat <strong><?= count($monevAlerts) ?> kerja sama</strong> yang memerlukan perhatian evaluasi berkala:
        <ul style="margin:6px 0 0 18px;padding:0;">
            <?php foreach ($monevAlerts as $ma): 
                $ds = $ma['s'];
            ?>
            <li style="margin-bottom:4px;">
                <?php if ($ma['is_overdue']): ?>
                    <span class="badge badge-danger" style="font-size:10px;vertical-align:middle;margin-right:4px;">TERLEWAT</span>
                <?php else: ?>
                    <span class="badge badge-warning" style="font-size:10px;vertical-align:middle;margin-right:4px;">SEGERA</span>
                <?php endif; ?>
                <strong><?= h($ds['mitra']['kode']) ?></strong> &mdash; <?= h($ds['mitra']['nama_mitra']) ?>: 
                Target: <strong><?= formatTanggal($ma['target']) ?></strong> 
                <?php if ($ma['is_overdue']): ?>
                    <span style="color:#b91c1c;font-weight:600;">(Terlewat <?= abs((int)$ma['hari']) ?> hari yang lalu)</span>
                <?php else: ?>
                    (<?= (int)$ma['hari'] ?> hari lagi)
                <?php endif; ?>
                &bull;
                <a href="mitra_edit.php?id=<?= $ds['mitra']['id'] ?>" style="color:#2563eb;text-decoration:underline;">Buka Penilaian &rarr;</a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if (empty($warningData)): ?>
<div class="card" style="padding:24px;">
    <?= uiEmptyState('Semua Naskah Dalam Kondisi Aman', 'Tidak ada naskah dengan status peringatan dini di atas E0 (Normal).', 'check-circle') ?>
</div>
<?php else: ?>

<!-- Ringkasan (BUG-EW-02: responsive kpi-row without inline repeat(4, 1fr)) -->
<div class="kpi-row" style="margin-bottom:20px;">
    <?php
    $e3Count = count(array_filter($warningData, fn($w) => $w['warning_status'] === 'E3'));
    $e2Count = count(array_filter($warningData, fn($w) => $w['warning_status'] === 'E2'));
    $e1Count = count(array_filter($warningData, fn($w) => $w['warning_status'] === 'E1'));
    $v0Count = count(array_filter($warningData, fn($w) => $w['warning_status'] === 'V0'));
    ?>
    <div class="kpi-card"><div class="kpi-icon kpi-icon-red">🔴</div><div><div class="kpi-number"><?= $e3Count ?></div><div class="kpi-label">E3 — Merah</div></div></div>
    <div class="kpi-card"><div class="kpi-icon" style="background:#ffedd5;color:#ea580c;">🟠</div><div><div class="kpi-number"><?= $e2Count ?></div><div class="kpi-label">E2 — Jingga</div></div></div>
    <div class="kpi-card"><div class="kpi-icon kpi-icon-yellow">🟡</div><div><div class="kpi-number"><?= $e1Count ?></div><div class="kpi-label">E1 — Kuning</div></div></div>
    <div class="kpi-card"><div class="kpi-icon" style="background:#f1f5f9;color:#64748b;">⚪</div><div><div class="kpi-number"><?= $v0Count ?></div><div class="kpi-label">V0 — Data Belum Cukup</div></div></div>
</div>

<!-- Filter Controls -->
<form method="get" class="filters" style="margin-bottom:20px;align-items:center;">
    <label style="font-weight:600;font-size:13px;">Filter Tingkat Risiko:</label>
    <select name="status" onchange="this.form.submit()">
        <option value="">Semua Tingkat (<?= count($warningData) ?>)</option>
        <option value="E3" <?= $filterStatus === 'E3' ? 'selected' : '' ?>>E3 — Merah (<?= $e3Count ?>)</option>
        <option value="E2" <?= $filterStatus === 'E2' ? 'selected' : '' ?>>E2 — Jingga (<?= $e2Count ?>)</option>
        <option value="E1" <?= $filterStatus === 'E1' ? 'selected' : '' ?>>E1 — Kuning (<?= $e1Count ?>)</option>
        <option value="V0" <?= $filterStatus === 'V0' ? 'selected' : '' ?>>V0 — Data Belum Cukup (<?= $v0Count ?>)</option>
    </select>
    <?php if ($filterStatus !== ''): ?>
        <a href="early_warning.php" class="btn btn-outline btn-sm">Reset Filter</a>
    <?php endif; ?>
</form>

<!-- Detail -->
<?php if (empty($warningDataFiltered)): ?>
    <div class="card" style="padding:20px;">
        <?= uiEmptyState('Tidak Ada Naskah Cocok', 'Tidak ditemukan naskah dengan tingkat risiko yang dipilih pada filter.', 'search') ?>
    </div>
<?php else: ?>
<?php foreach ($warningDataFiltered as $wd): ?>
<div class="card">
    <div class="flex-between" style="margin-bottom:10px;">
        <div>
            <strong><?= h($wd['kode']) ?></strong> — <?= h($wd['nama_mitra']) ?>
            <span class="badge badge-<?= warnaWarning($wd['warning_status']) ?>" style="margin-left:8px;"><?= h($wd['warning_label']) ?></span>
        </div>
        <a href="mitra_edit.php?id=<?= $wd['id'] ?>" class="btn btn-outline btn-sm">Detail</a>
    </div>
    <p style="margin:0 0 10px;font-size:12.5px;">Penanganan: <strong><?= h($wd['tingkat']) ?></strong> &mdash; Hasil uji: <?= h($wd['hasil_uji']) ?></p>
    <div class="table-wrap">
    <table style="font-size:12px;">
        <thead><tr><th>Dimensi</th><th>Kondisi</th><th>Status</th><th>Fakta/Bukti</th><th>Tindakan</th><th>Tenggat</th><th>Progres</th></tr></thead>
        <tbody>
        <?php foreach ($wd['rows'] as $r): ?>
            <?php
            // Display actual expiry date for Masa Berlaku dimension instead of '-'
            $tenggatDisplay = formatTanggal($r['tenggat']);
            if (($tenggatDisplay === '-' || empty($r['tenggat'])) && stripos($r['dimensi'], 'Masa Berlaku') !== false && !empty($wd['tanggal_berakhir'])) {
                $tenggatDisplay = formatTanggal($wd['tanggal_berakhir']);
            }
            ?>
            <tr>
                <td><strong><?= h($r['dimensi']) ?></strong></td>
                <td><?= h($r['kondisi'] ?? '-') ?></td>
                <td><span class="badge badge-<?= warnaWarning($r['status']) ?>"><?= h($r['status']) ?></span></td>
                <td><?= h($r['fakta_bukti'] ?? '-') ?></td>
                <td><?= h($r['tindakan'] ?? '-') ?></td>
                <td><?= $tenggatDisplay ?></td>
                <td><?= h($r['progres'] ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>