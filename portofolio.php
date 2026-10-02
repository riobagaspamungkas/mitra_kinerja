<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$pdo = getDB();
$all = getAllMitraSummary($pdo);

$pageTitle = 'Portofolio';
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Portofolio Kerja Sama</h1>
        <div class="muted" style="font-size:13px;">Rekap status dan evaluasi kerja sama</div>
    </div>
    <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
    <?php if (in_array(currentUser()['role'], ['admin','pemeriksa'])): ?>
    <a href="mitra_manage.php" class="btn btn-primary btn-sm">+ Tambah Naskah</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="filters">
        <input type="text" id="fSearch" placeholder="🔍 Cari kode / mitra / bidang..." style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;min-width:200px;">
        <select id="fPortofolio">
            <option value="">Semua Portofolio</option>
            <option value="Pilot Utama">Pilot Utama</option>
            <option value="Cadangan">Cadangan</option>
        </select>
        <select id="fBidang">
            <option value="">Semua Bidang</option>
            <option value="AHU">AHU</option>
            <option value="KI">KI</option>
            <option value="P3H">P3H</option>
            <option value="PPL">PPL</option>
            <option value="Keuangan">Keuangan</option>
            <option value="Humas">Humas</option>
            <option value="SDM">SDM</option>
        </select>
        <select id="fKategori">
            <option value="">Semua Kategori</option>
            <option value="KUAT">Kuat</option>
            <option value="CUKUP/PERLU PENGUATAN">Cukup/Perlu Penguatan</option>
            <option value="PERLU PERBAIKAN">Perlu Perbaikan</option>
            <option value="KRITIS">Kritis</option>
            <option value="DALAM PROSES">Dalam Proses</option>
        </select>
        <select id="fPosisi">
            <option value="">Semua Posisi</option>
            <option value="BERDAMPAK">Berdampak</option>
            <option value="OUTCOME TERBENTUK">Outcome Terbentuk</option>
            <option value="OUTPUT TERSEDIA">Output Tersedia</option>
            <option value="AKTIF">Aktif</option>
            <option value="BELUM DAPAT DITENTUKAN">Belum Dapat Ditentukan</option>
        </select>
        <select id="fRekomendasi">
            <option value="">Semua Rekomendasi</option>
            <option value="LANJUT">Lanjut</option>
            <option value="PERBAIKI">Perbaiki</option>
            <option value="PERPANJANG">Perpanjang</option>
            <option value="REPLIKASI">Replikasi</option>
            <option value="HENTIKAN">Hentikan</option>
        </select>
        <select id="fStatus">
            <option value="">Semua Status</option>
            <option value="FINAL/TERVALIDASI">Final/Tervalidasi</option>
            <option value="SIAP DIVALIDASI">Siap Divalidasi</option>
            <option value="DALAM PENILAIAN">Dalam Penilaian</option>
            <option value="BELUM DINILAI">Belum Dinilai</option>
            <option value="BUKTI BELUM MEMADAI">Bukti Belum Memadai</option>
            <option value="PERLU PERBAIKAN">Perlu Perbaikan</option>
            <option value="MASA IMPLEMENTASI AWAL">Masa Implementasi Awal</option>
        </select>
    </div>
    <div class="table-wrap">
    <table id="tblPortofolio">
        <thead>
            <tr><th>Kode</th><th>Portofolio</th><th>Mitra</th><th>Bidang</th><th>Berlaku s.d.</th><th>Nilai</th><th>Kategori</th><th>Kelengkapan</th><th>Posisi</th><th>Rekomendasi</th><th>Status</th><th>Warning</th><th>Aksi</th></tr>
        </thead>
        <tbody>
        <?php foreach ($all as $s): $m = $s['mitra']; ?>
            <tr data-portofolio="<?= h($m['portofolio']) ?>" data-bidang="<?= h($m['bidang'] ?? 'AHU') ?>" data-kategori="<?= h($s['kategori']) ?>" data-status="<?= h($s['status_scorecard']) ?>" data-posisi="<?= h($s['posisi_portofolio']) ?>" data-rekomendasi="<?= h($s['rekomendasi']) ?>">
                <td><strong><?= h($m['kode']) ?></strong></td>
                <td><?= h($m['portofolio']) ?></td>
                <td><?= h($m['nama_mitra']) ?></td>
                <td><span class="badge badge-secondary" style="font-size:11px;font-weight:600;"><?= h($m['bidang'] ?? 'AHU') ?></span><br><span class="muted" style="font-size:10px;"><?= h($m['jenis']) ?></span></td>
                <td><?= formatTanggal($m['tanggal_berakhir']) ?></td>
                <td><?= $s['nilai_final'] !== null ? number_format($s['nilai_final'], 2) : '<span class="badge badge-' . warnaKategori('DALAM PROSES') . '">Dalam Proses</span>' ?></td>
                <td><span class="badge badge-<?= warnaKategori($s['kategori']) ?>"><?= h($s['kategori']) ?></span></td>
                <td><?= $s['kelengkapan'] ?>%</td>
                <td><span class="badge badge-secondary" style="font-size:11px;max-width:180px;white-space:normal;display:inline-block;line-height:1.25;text-align:left;"><?= h($s['posisi_portofolio']) ?></span></td>
                <td>
                    <?php 
                    $rekHead = explode("\n", trim($s['rekomendasi'] ?? '-'))[0] ?: '-';
                    ?>
                    <span class="badge badge-warning" style="font-size:11px;max-width:210px;white-space:normal;display:inline-block;line-height:1.25;text-align:left;" title="<?= h($s['rekomendasi']) ?>">
                        <?= h(singkat($rekHead, 45)) ?>
                    </span>
                </td>
                <td><?= h($s['status_scorecard']) ?></td>
                <td><span class="badge badge-<?= warnaWarning($s['warning']['status']) ?>"><?= h($s['warning']['label']) ?></span></td>
                <td>
                    <?php if (in_array(currentUser()['role'], ['admin','pemeriksa'])): ?>
                    <a href="mitra_manage.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                    <?php endif; ?>
                    <a href="mitra_edit.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm">Scorecard</a>
                    <?php if (in_array(currentUser()['role'], ['admin','validator'])): ?>
                    <a href="mitra_validasi.php?id=<?= $m['id'] ?>" class="btn btn-primary btn-sm">Validasi</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
function applyFilters() {
    var q = (document.getElementById('fSearch').value || '').toLowerCase().trim();
    var p = document.getElementById('fPortofolio').value;
    var b = document.getElementById('fBidang').value;
    var k = document.getElementById('fKategori') ? document.getElementById('fKategori').value : '';
    var pos = document.getElementById('fPosisi').value;
    var rek = document.getElementById('fRekomendasi').value;
    var s = document.getElementById('fStatus').value;
    document.querySelectorAll('#tblPortofolio tbody tr').forEach(function(tr) {
        var matchQ = !q || tr.textContent.toLowerCase().indexOf(q) !== -1;
        var matchP = !p || tr.dataset.portofolio === p;
        var matchB = !b || tr.dataset.bidang === b;
        var matchK = !k || tr.dataset.kategori === k;
        var matchPos = !pos || tr.dataset.posisi === pos;
        var matchRek = !rek || tr.dataset.rekomendasi === rek;
        var matchS = !s || tr.dataset.status === s;
        tr.style.display = (matchQ && matchP && matchB && matchK && matchPos && matchRek && matchS) ? '' : 'none';
    });
}
document.getElementById('fSearch').addEventListener('input', applyFilters);
document.getElementById('fPortofolio').addEventListener('change', applyFilters);
document.getElementById('fBidang').addEventListener('change', applyFilters);
if (document.getElementById('fKategori')) {
    document.getElementById('fKategori').addEventListener('change', applyFilters);
}
document.getElementById('fPosisi').addEventListener('change', applyFilters);
document.getElementById('fRekomendasi').addEventListener('change', applyFilters);
document.getElementById('fStatus').addEventListener('change', applyFilters);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>