<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$pdo = getDB();
$all = getAllMitraSummary($pdo);
$user = currentUser();

$pageTitle = 'Daftar Naskah';
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Daftar Naskah</h1>
        <div class="muted" style="font-size:13px;"><?= count($all) ?> naskah terdaftar</div>
    </div>
    <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
</div>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Kode</th><th>Mitra</th><th>Bidang</th><th>Berakhir</th>
                <th>Kelengkapan</th><th>Posisi</th><th>Rekomendasi</th><th>Status</th><th>Validasi</th><th>Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($all as $s): $m = $s['mitra']; ?>
            <tr>
                <td><strong><?= h($m['kode']) ?></strong><br><span class="muted" style="font-size:11px;"><?= h($m['portofolio']) ?></span></td>
                <td><?= h($m['nama_mitra']) ?></td>
                <td><span class="badge badge-secondary" style="font-size:11px;font-weight:600;"><?= h($m['bidang'] ?? 'AHU') ?></span><br><span class="muted" style="font-size:10px;"><?= h($m['jenis']) ?></span></td>
                <td><?= formatTanggal($m['tanggal_berakhir']) ?></td>
                <td><?= $s['kelengkapan'] ?>%</td>
                <td><span class="badge badge-secondary" style="font-size:11px;"><?= h($s['posisi_portofolio']) ?></span></td>
                <td><span class="badge badge-warning" style="font-size:11px;"><?= h($s['rekomendasi']) ?></span></td>
                <td><?= h($s['status_scorecard']) ?></td>
                <td>
                    <?php
                    $vBadge = match($s['validasi']['status']) {
                        'DISETUJUI' => 'success', 'PERLU PERBAIKAN' => 'danger', default => 'secondary'
                    };
                    ?>
                    <span class="badge badge-<?= $vBadge ?>"><?= h($s['validasi']['status']) ?></span>
                </td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <a href="mitra_edit.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm">Scorecard</a>
                        <?php if (in_array($user['role'], ['admin','validator'], true)): ?>
                        <a href="mitra_validasi.php?id=<?= $m['id'] ?>" class="btn btn-primary btn-sm">Validasi</a>
                        <?php elseif ($user['role'] === 'pemeriksa'): ?>
                        <a href="mitra_validasi.php" class="btn btn-outline btn-sm">Penilaian</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
