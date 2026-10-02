<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$userRole = $user['role'] ?? 'pemeriksa';
$canImport = in_array($userRole, ['admin', 'pemeriksa', 'pengampu'], true);

$success = '';
$errors = [];

// Helper pemetaan file template resmi per kode naskah
if (!function_exists('getScorecardTemplate')) {
function getScorecardTemplate(string $kode): array {
    $map = [
        'P01' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P01_DEKRANASDA_KEPRI_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (P01)'],
        'P02' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P02_BNNP_MASA_IMPLEMENTASI_AWAL_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (P02)'],
        'P03' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P03_PEMKOT_TANJUNGPINANG_FINAL_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (P03)'],
        'P04' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P04_BAPPERIDA_BINTAN_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (P04)'],
        'P05' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P05_STAIN_SAR_KEPRI_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (P05/STAIN)'],
        'P06' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P06_UMRAH_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (P06)'],
        'P07' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P07_STAI_ANAMBAS_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (P07)'],
        'P08' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P08_POLIBATAM_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (P08)'],
        'P09' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P09_STAI_NATUNA_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (P09)'],
        'P10' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P10_STISIP_BATAM_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (P10)'],
        'C01' => ['file' => 'public/templates/import_naskah/02_Portofolio_Pengayaan/C01_Scorecard_STAIN_Sultan_Abdurrahman.xlsx', 'label' => 'Portofolio Pengayaan (C01)'],
        'C02' => ['file' => 'public/templates/import_naskah/02_Portofolio_Pengayaan/C02_Scorecard_Politeknik_Bintan_Cakrawala.xlsx', 'label' => 'Portofolio Pengayaan (C02)'],
        'C03' => ['file' => 'public/templates/import_naskah/02_Portofolio_Pengayaan/C03_Scorecard_Universitas_Ibnu_Sina.xlsx', 'label' => 'Portofolio Pengayaan (C03)'],
        'C04' => ['file' => 'public/templates/import_naskah/02_Portofolio_Pengayaan/C04_Scorecard_UNRIKA.xlsx', 'label' => 'Portofolio Pengayaan (C04)'],
    ];

    $k = strtoupper(trim($kode));
    if (isset($map[$k]) && file_exists(__DIR__ . '/' . $map[$k]['file'])) {
        return $map[$k];
    }
    return [
        'file' => 'public/templates/template_scorecard_v2_1.xlsx',
        'label' => 'Template Scorecard V2.1 (' . $k . ')'
    ];
}
}

// Helper penentuan kelas warna badge Status Scorecard
if (!function_exists('warnaStatusScorecard')) {
function warnaStatusScorecard(?string $st): string {
    $st = strtoupper(trim((string)$st));
    if (str_contains($st, 'FINAL') || str_contains($st, 'TERVALIDASI')) return 'success';
    if (str_contains($st, 'SIAP')) return 'primary';
    if (str_contains($st, 'IMPLEMENTASI AWAL')) return 'warning';
    if (str_contains($st, 'BELUM MEMADAI') || str_contains($st, 'PERLU PERBAIKAN')) return 'danger';
    if (str_contains($st, 'DALAM')) return 'orange';
    return 'secondary';
}
}

// Helper Keputusan Pimpinan yang diturunkan langsung dari $s['hasil_uji']
if (!function_exists('formatKeputusanPimpinan')) {
function formatKeputusanPimpinan(?string $hasilUji): array {
    $hu = strtoupper(trim((string)$hasilUji));
    if ($hu === 'CALON BUTUH INTERVENSI PIMPINAN') {
        return ['label' => 'Butuh Intervensi Pimpinan', 'badge' => 'danger', 'icon' => '🚨'];
    }
    if ($hu === 'PERLU KOORDINASI PROJECT LEADER') {
        return ['label' => 'Koordinasi Project Leader', 'badge' => 'orange', 'icon' => '⚠️'];
    }
    if ($hu === 'LENGKAPI UJI INTERVENSI') {
        return ['label' => 'Lengkapi Uji Intervensi', 'badge' => 'warning', 'icon' => '📝'];
    }
    if ($hu === 'LENGKAPI DATA') {
        return ['label' => 'Lengkapi Data', 'badge' => 'secondary', 'icon' => '📋'];
    }
    if ($hu === 'DITANGANI PIC/UNIT') {
        return ['label' => 'Ditangani PIC/Unit', 'badge' => 'success', 'icon' => '✅'];
    }
    return [
        'label' => $hasilUji ?: 'Ditangani PIC/Unit',
        'badge' => 'secondary',
        'icon'  => 'ℹ️'
    ];
}
}

// ── PROSES IMPORT SCORECARD EXCEL (.xlsx) ──────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array(($_POST['action'] ?? ''), ['import_scorecard', 'import_naskah'], true)) {
    if (!$canImport) {
        $errors[] = 'Anda tidak memiliki hak akses untuk melakukan import Scorecard.';
    } else {
        $targetId = (int)($_POST['mitra_id'] ?? 0);
        $stmtM = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
        $stmtM->execute([$targetId]);
        $targetMitra = $stmtM->fetch();

        if (!$targetMitra) {
            $errors[] = 'Data naskah kerja sama tujuan tidak ditemukan.';
        } elseif (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Silakan pilih file spreadsheet Excel (.xlsx) yang valid untuk di-import.';
        } else {
            $fileInfo = $_FILES['excel_file'];
            $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));

            if ($ext !== 'xlsx') {
                $errors[] = 'Format file tidak didukung. Harap unggah file spreadsheet Excel dengan ekstensi .xlsx.';
            } elseif ($fileInfo['size'] > 25 * 1024 * 1024) {
                $errors[] = 'Ukuran file melebihi batas maksimum 25 MB.';
            } else {
                try {
                    $parsedWb = parseFullWorkbookXlsx($fileInfo['tmp_name']);
                } catch (Throwable $e) {
                    error_log('parseFullWorkbookXlsx error: ' . $e->getMessage());
                    $parsedWb = [];
                }

                if (empty($parsedWb)) {
                    $errors[] = 'Gagal membaca isi file Excel. Pastikan berkas spreadsheet tidak rusak atau terproteksi.';
                } else {
                    $scSheetName = '';
                    $rekSheetName = '';
                    $picSheetName = '';

                    // 1. Deteksi Sheet Scorecard (PENILAIAN atau SCORECARD)
                    foreach (array_keys($parsedWb) as $sName) {
                        $upper = strtoupper($sName);
                        if (str_contains($upper, 'PENILAIAN') || str_contains($upper, 'SCORECARD')) {
                            $scSheetName = $sName;
                            break;
                        }
                    }
                    if (!$scSheetName) {
                        foreach ($parsedWb as $shName => $shRows) {
                            for ($sr = 1; $sr <= 25; $sr++) {
                                $c1 = strtoupper(trim((string)($shRows[$sr][1] ?? '')));
                                if (str_starts_with($c1, 'I1')) {
                                    $scSheetName = $shName;
                                    break 2;
                                }
                            }
                        }
                    }

                    // 2. Deteksi Sheet Rekomendasi & PIC jika ada
                    foreach (array_keys($parsedWb) as $sName) {
                        $upper = strtoupper($sName);
                        if (str_contains($upper, 'REKOMENDASI')) $rekSheetName = $sName;
                        elseif (str_contains($upper, 'IDENTITAS') || str_contains($upper, 'PIC')) $picSheetName = $sName;
                    }

                    if (!$scSheetName || empty($parsedWb[$scSheetName])) {
                        $errors[] = 'Sheet Scorecard atau Penilaian tidak ditemukan di dalam berkas Excel. Pastikan terdapat sheet "PENILAIAN" atau "SCORECARD".';
                    } else {
                        $scRows = $parsedWb[$scSheetName];
                        $isPenilaianLayout = str_contains(strtoupper($scSheetName), 'PENILAIAN');

                        // Bobot default indikator V2.1 / V3
                        $weights = ['I1' => 10, 'I2' => 15, 'I3' => 15, 'I4' => 20, 'I5' => 20, 'I6' => 10, 'I7' => 10];
                        $updatedScorecard = 0;

                        foreach ($scRows as $rIdx => $row) {
                            $col1 = strtoupper(trim((string)($row[1] ?? '')));
                            if (!preg_match('/^(I[1-7])(?:\.|\b)/', $col1, $mCode)) continue;
                            $kodeInd = $mCode[1];

                            $bobot = isset($weights[$kodeInd]) ? $weights[$kodeInd] : (int)($row[2] ?? 10);

                            if ($isPenilaianLayout) {
                                $kondisiBaseline = trim((string)($row[4] ?? ''));
                                $kondisi = trim((string)($row[5] ?? ''));
                                $rawStatus = strtoupper(trim((string)($row[6] ?? '')));
                                $evidenceLoc = trim((string)($row[7] ?? ''));
                                $rawSkor = trim((string)($row[8] ?? ''));
                                $alasanSkor = trim((string)($row[10] ?? ''));
                                $catatanTl = trim((string)($row[11] ?? ''));
                            } else {
                                $kondisiBaseline = trim((string)($row[3] ?? ''));
                                $rawStatus = strtoupper(trim((string)($row[5] ?? '')));
                                $kondisi = trim((string)($row[6] ?? ''));
                                $rawSkor = trim((string)($row[7] ?? ''));
                                $alasanSkor = trim((string)($row[8] ?? ''));
                                $catatanTl = trim((string)($row[9] ?? ''));
                                $evidenceLoc = '';
                            }

                            // Normalisasi status pemeriksaan
                            $statusPem = 'BELUM DITELAAH';
                            if (str_contains($rawStatus, 'BELUM DAPAT') || str_contains($rawStatus, 'BELUM DINILAI')) {
                                $statusPem = 'BELUM DAPAT DINILAI';
                            } elseif (str_contains($rawStatus, 'DAPAT DINILAI') || (str_contains($rawStatus, 'MEMADAI') && !str_contains($rawStatus, 'BELUM'))) {
                                $statusPem = 'BUKTI MEMADAI';
                            } elseif (str_contains($rawStatus, 'CUKUP')) {
                                $statusPem = 'BUKTI CUKUP';
                            } elseif (str_contains($rawStatus, 'BELUM MEMADAI')) {
                                $statusPem = 'BUKTI BELUM MEMADAI';
                            }

                            $skor = is_numeric($rawSkor) ? (int)$rawSkor : null;
                            $nilai = $skor !== null ? round(($skor / 4.0) * $bobot, 2) : null;

                            // Update atau insert ke indikator_skor
                            $stmtCheck = $pdo->prepare('SELECT id FROM indikator_skor WHERE mitra_id = ? AND kode_indikator = ?');
                            $stmtCheck->execute([$targetId, $kodeInd]);
                            if ($stmtCheck->fetch()) {
                                $stmtU = $pdo->prepare('UPDATE indikator_skor SET status_pemeriksaan = ?, kondisi_baseline = ?, kondisi_saat_ini = ?, temuan_bukti = ?, skor = ?, alasan_skor = ?, catatan_tindak_lanjut = ?, nilai = ?, referensi_baseline = ? WHERE mitra_id = ? AND kode_indikator = ?');
                                $stmtU->execute([$statusPem, $kondisiBaseline, $kondisi, $evidenceLoc, $skor, $alasanSkor, $catatanTl, $nilai, $kondisiBaseline, $targetId, $kodeInd]);
                            } else {
                                $stmtI = $pdo->prepare('INSERT INTO indikator_skor (mitra_id, kode_indikator, deskripsi, bobot, referensi_baseline, kondisi_baseline, status_pemeriksaan, kondisi_saat_ini, temuan_bukti, skor, alasan_skor, catatan_tindak_lanjut, nilai) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                                $stmtI->execute([$targetId, $kodeInd, "Indikator $kodeInd", $bobot, $kondisiBaseline, $kondisiBaseline, $statusPem, $kondisi, $evidenceLoc, $skor, $alasanSkor, $catatanTl, $nilai]);
                            }
                            $updatedScorecard++;
                        }

                        if ($updatedScorecard === 0) {
                            $errors[] = 'Tidak ditemukan data indikator I1–I7 yang valid pada sheet Scorecard.';
                        } else {
                            // Metadata tambahan dari Scorecard final (Status, Rekomendasi, Posisi)
                            $rawScStatus = '';
                            if ($isPenilaianLayout) {
                                foreach ($scRows as $r) {
                                    $c1 = strtolower(trim((string)($r[1] ?? '')));
                                    if (str_contains($c1, 'status scorecard') && !empty($r[2])) {
                                        $rawScStatus = trim((string)$r[2]);
                                        break;
                                    }
                                }
                                if (!$rawScStatus && isset($scRows[27][2])) {
                                    $rawScStatus = trim((string)$scRows[27][2]);
                                }
                            }

                            $posisiPortofolio = '';
                            $rekomNarrative = '';
                            if ($rekSheetName && !empty($parsedWb[$rekSheetName])) {
                                $rekRows = $parsedWb[$rekSheetName];
                                foreach ($rekRows as $r) {
                                    $c1 = strtolower(trim((string)($r[1] ?? '')));
                                    if (str_contains($c1, 'posisi portofolio') && !empty($r[2])) {
                                        $posisiPortofolio = trim((string)$r[2]);
                                    }
                                    if (!$rawScStatus && str_contains($c1, 'status scorecard') && !empty($r[2])) {
                                        $rawScStatus = trim((string)$r[2]);
                                    }
                                }
                                if (!$posisiPortofolio && isset($rekRows[9][2])) {
                                    $posisiPortofolio = trim((string)$rekRows[9][2]);
                                }

                                $rekomText = '';
                                $temuan = '';
                                $tl = '';
                                foreach ($rekRows as $r) {
                                    $c1 = strtolower(trim((string)($r[1] ?? '')));
                                    if ($c1 === 'rekomendasi' && !empty($r[2])) $rekomText = trim((string)$r[2]);
                                    elseif ($c1 === 'temuan utama' && !empty($r[2])) $temuan = trim((string)$r[2]);
                                    elseif ($c1 === 'tindak lanjut' && !empty($r[2])) $tl = trim((string)$r[2]);
                                }
                                if (!$rekomText && isset($rekRows[17][2])) $rekomText = trim((string)$rekRows[17][2]);
                                if (!$temuan && isset($rekRows[16][2])) $temuan = trim((string)$rekRows[16][2]);
                                if (!$tl && isset($rekRows[19][2])) $tl = trim((string)$rekRows[19][2]);

                                if ($rekomText) {
                                    $parts = [$rekomText];
                                    if ($temuan) $parts[] = "Temuan Utama:
" . $temuan;
                                    if ($tl) $parts[] = "Rencana Tindak Lanjut:
" . $tl;
                                    $rekomNarrative = trim(implode("

", $parts));
                                }
                            }

                            $uSqlParts = [];
                            $uParams = [];
                            if (!empty($rawScStatus)) {
                                $uSqlParts[] = 'status_scorecard = ?';
                                $uParams[] = $rawScStatus;
                            }
                            if (!empty($posisiPortofolio)) {
                                $uSqlParts[] = 'posisi_portofolio = ?';
                                $uParams[] = $posisiPortofolio;
                            }
                            if (!empty($rekomNarrative)) {
                                $uSqlParts[] = 'rekomendasi = ?';
                                $uParams[] = $rekomNarrative;
                            }
                            if (!empty($uSqlParts)) {
                                $uParams[] = $targetId;
                                $pdo->prepare('UPDATE mitra_kinerja SET ' . implode(', ', $uSqlParts) . ' WHERE id = ?')->execute($uParams);
                            }

                            // Update PIC internal & PIC mitra jika tersedia
                            if ($picSheetName && !empty($parsedWb[$picSheetName])) {
                                $picRows = $parsedWb[$picSheetName];
                                $picInternalFound = '';
                                $picMitraFound = '';
                                foreach ($picRows as $pRow) {
                                    $label = strtolower(trim((string)($pRow[1] ?? '')));
                                    $val = trim((string)($pRow[2] ?? ''));
                                    if (str_contains($label, 'pic mitra') || (str_contains($label, 'nama') && str_contains($label, 'mitra'))) {
                                        if (!empty($val)) $picMitraFound = $val;
                                    } elseif (str_contains($label, 'pic internal') || str_contains($label, 'pengampu')) {
                                        if (!empty($val)) $picInternalFound = $val;
                                    }
                                }
                                if ($picInternalFound || $picMitraFound) {
                                    $uSql = 'UPDATE mitra_kinerja SET ';
                                    $uParams = [];
                                    if ($picInternalFound) { $uSql .= 'pic_internal = ?, '; $uParams[] = $picInternalFound; }
                                    if ($picMitraFound) { $uSql .= 'pic_mitra = ?, '; $uParams[] = $picMitraFound; }
                                    $uSql = rtrim($uSql, ', ') . ' WHERE id = ?';
                                    $uParams[] = $targetId;
                                    $pdo->prepare($uSql)->execute($uParams);
                                }
                            }

                            // Sinkronkan status scorecard dan nilai final di DB
                            syncStatusScorecard($pdo, $targetId);

                            logAudit($targetId, $user['id'], 'IMPORT_SCORECARD', "Import Scorecard untuk {$targetMitra['kode']}: {$updatedScorecard} indikator diperbarui.");
                            $success = "Data Scorecard untuk <strong>" . h($targetMitra['kode']) . " — " . h($targetMitra['nama_mitra']) . "</strong> berhasil di-import!<br>"
                                     . "&bull; {$updatedScorecard} indikator Scorecard (I1–I7) berhasil disinkronkan ke sistem.<br>"
                                     . (!empty($rawScStatus) ? "&bull; Status Scorecard: <strong>" . h($rawScStatus) . "</strong>.<br>" : "")
                                     . (!empty($posisiPortofolio) ? "&bull; Posisi Portofolio: <strong>" . h($posisiPortofolio) . "</strong>.<br>" : "");
                        }
                    }
                }
            }
        }
    }
}

// Ambil seluruh ringkasan data mitra terbaru
$all = getAllMitraSummary($pdo);

// Urutkan Pilot Utama terlebih dahulu (P01..P13), disusul Cadangan (C01..C05)
usort($all, function($a, $b) {
    $pA = ($a['mitra']['portofolio'] === 'Pilot Utama' || $a['mitra']['portofolio'] === 'PILOT') ? 0 : 1;
    $pB = ($b['mitra']['portofolio'] === 'Pilot Utama' || $b['mitra']['portofolio'] === 'PILOT') ? 0 : 1;
    if ($pA !== $pB) return $pA <=> $pB;
    return strnatcasecmp($a['mitra']['kode'], $b['mitra']['kode']);
});

$dueSoonMonev = [];
foreach ($all as $s) {
    if (!empty($s['monev']['warning_1_bulan'])) {
        $dueSoonMonev[] = $s;
    }
}

$pageTitle = 'Scorecard';
require __DIR__ . '/includes/header.php';
?>

<style>
.rekom-tooltip-container {
    position: relative;
    display: inline-flex;
    align-items: center;
}
.rekom-tooltip-content {
    visibility: hidden;
    opacity: 0;
    transition: opacity 0.15s ease, visibility 0.15s ease;
    position: absolute;
    bottom: 125%;
    right: 0;
    width: 290px;
    background: #0f172a;
    color: #f8fafc;
    text-align: left;
    border-radius: 8px;
    padding: 10px 12px;
    font-size: 11.5px;
    line-height: 1.45;
    z-index: 1000;
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.35), 0 4px 6px -4px rgba(0,0,0,0.2);
    pointer-events: none;
    white-space: normal;
    word-break: break-word;
}
.rekom-tooltip-container:hover .rekom-tooltip-content {
    visibility: visible;
    opacity: 1;
}
.rekom-tooltip-content::after {
    content: "";
    position: absolute;
    top: 100%;
    right: 12px;
    border-width: 6px;
    border-style: solid;
    border-color: #0f172a transparent transparent transparent;
}
@keyframes fadeInModal {
    from { opacity: 0; transform: scale(0.97); }
    to { opacity: 1; transform: scale(1); }
}
.modal-anim {
    animation: fadeInModal 0.18s ease-out;
}
</style>

<div class="flex-between" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Scorecard Kerja Sama</h1>
        <div class="muted" style="font-size:13px;">Ringkasan hasil penilaian, evaluasi kinerja, dan arahan pimpinan per naskah</div>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
        <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
        <?php if ($canImport): ?>
        <a href="import.php" class="btn btn-outline btn-sm">Menu Import Lengkap &rarr;</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success" style="margin-bottom:20px;border-left:4px solid #10b981;background:#ecfdf5;color:#065f46;padding:14px 18px;border-radius:8px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:4px;">Berhasil Memproses Import Scorecard!</div>
    <div style="font-size:13px;line-height:1.5;"><?= $success ?></div>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:20px;border-left:4px solid #ef4444;background:#fef2f2;color:#991b1b;padding:14px 18px;border-radius:8px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:4px;">Terjadi Kesalahan Saat Import:</div>
    <ul style="margin:4px 0 0 18px;padding:0;font-size:13px;">
        <?php foreach ($errors as $err): ?>
        <li><?= h($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<?php if ($userRole === 'pengampu' || $userRole === 'pic' || !empty($dueSoonMonev)): ?>
<div class="alert alert-warning" style="margin-bottom:20px;border-left:4px solid #ea580c;background:#fff7ed;color:#9a3412;">
    <?php if ($userRole === 'pengampu'): ?>
        <div style="font-weight:700;font-size:14px;margin-bottom:4px;color:#c2410c;">
            ⚠️ Notifikasi Pengisian Jadwal Evaluasi (Akun Pengampu)
        </div>
        <div style="font-size:13px;line-height:1.5;">
            Akun <strong>Pengampu</strong> perlu untuk melakukan pengisian setiap <strong>SC1 atau SC2 atau SC3</strong> dan siklus evaluasi lainnya, sebelum <strong>30 hari</strong> dari tenggat waktu SC tersebut.
        </div>
    <?php elseif ($userRole === 'pic'): ?>
        <div style="font-weight:700;font-size:14px;margin-bottom:4px;color:#c2410c;">
            ⚠️ Pengingat Jadwal Evaluasi Kinerja (Akun PIC)
        </div>
        <div style="font-size:13px;line-height:1.5;">
            Akun <strong>PIC</strong> bertugas untuk memantau dan mengingatkan Akun <strong>Pengampu</strong> agar melakukan pengisian setiap <strong>SC1 atau SC2 atau SC3</strong> sebelum <strong>30 hari</strong> dari tenggat waktu SC.
        </div>
    <?php else: ?>
        <div style="font-weight:700;font-size:14px;margin-bottom:4px;color:#c2410c;">
            ⚠️ Monitoring Jadwal Evaluasi Mendatang (&lt; 30 Hari)
        </div>
    <?php endif; ?>

    <?php if (!empty($dueSoonMonev)): ?>
    <ul style="margin:6px 0 0 18px;padding:0;font-size:12.5px;">
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
            <a href="mitra_edit.php?id=<?= $ds['mitra']['id'] ?>" style="color:#2563eb;text-decoration:underline;">Buka Pengisian &rarr;</a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th style="width:55px;text-align:center;">Kode</th>
                <th>Nama Mitra</th>
                <th style="width:110px;text-align:center;">Nilai Final</th>
                <th style="width:130px;text-align:center;">Status Scorecard</th>
                <th style="width:125px;text-align:center;">Kategori Kinerja</th>
                <th style="width:115px;text-align:center;">Warning Tertinggi</th>
                <th style="width:95px;text-align:center;">Validasi</th>
                <?php if ($canImport): ?>
                    <th style="width:180px;text-align:center;">📊 Data Scorecard (V2.1)</th>
                <?php endif; ?>
                <th style="width:170px;text-align:center;">Keputusan Pimpinan</th>
                <th style="width:105px;text-align:center;">Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($all as $s): 
            $m = $s['mitra'];
            $vStatus = strtoupper(trim((string)($s['validasi']['status'] ?? 'BELUM')));
            $vBadge = match($vStatus) {
                'DISETUJUI' => 'success',
                'PERLU PERBAIKAN' => 'danger',
                'SIAP', 'SIAP DIVALIDASI' => 'primary',
                default => 'secondary'
            };
            $kp = formatKeputusanPimpinan($s['hasil_uji'] ?? '');
            $rekomText = trim((string)($s['rekomendasi'] ?? ''));
            $posisiText = trim((string)($s['posisi_portofolio'] ?? '-'));
        ?>
            <tr>
                <td style="text-align:center;font-weight:700;color:#1e40af;vertical-align:middle;">
                    <?= h($m['kode']) ?>
                </td>
                <td style="vertical-align:middle;">
                    <div style="font-weight:600;color:#0f172a;margin-bottom:2px;">
                        <?= h($m['nama_mitra']) ?>
                    </div>
                    <?php if (!empty($m['judul'])): ?>
                        <div class="muted" style="font-size:11.5px;line-height:1.35;max-width:320px;">
                            <?= h($m['judul']) ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;vertical-align:middle;">
                    <?php if ($s['nilai_final'] !== null): ?>
                        <strong style="font-size:14px;color:#0f172a;">
                            <?= number_format((float)$s['nilai_final'], 2) ?>
                        </strong>
                    <?php else: ?>
                        <span class="badge badge-warning" style="font-size:11px;">Dalam Proses</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;vertical-align:middle;">
                    <span class="badge badge-<?= warnaStatusScorecard($s['status_scorecard']) ?>" style="font-size:11px;">
                        <?= h($s['status_scorecard']) ?>
                    </span>
                </td>
                <td style="text-align:center;vertical-align:middle;">
                    <span class="badge badge-<?= warnaKategori($s['kategori']) ?>" style="font-size:11px;">
                        <?= h($s['kategori']) ?>
                    </span>
                </td>
                <td style="text-align:center;vertical-align:middle;">
                    <span class="badge badge-<?= warnaWarning($s['warning']['status']) ?>" style="font-size:11px;">
                        <?= h($s['warning']['label'] ?? $s['warning']['status']) ?>
                    </span>
                </td>
                <td style="text-align:center;vertical-align:middle;">
                    <span class="badge badge-<?= $vBadge ?>" style="font-size:11px;">
                        <?= h($s['validasi']['status'] ?? 'BELUM') ?>
                    </span>
                </td>
                <?php if ($canImport): 
                    $tmpl = getScorecardTemplate($m['kode']);
                ?>
                <td style="text-align:center;vertical-align:middle;white-space:nowrap;">
                    <div style="display:inline-flex;gap:6px;align-items:center;justify-content:center;">
                        <a href="<?= h($tmpl['file']) ?>" download="Template_Scorecard_<?= h($m['kode']) ?>.xlsx" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 8px;display:inline-flex;align-items:center;gap:3px;color:#047857;border-color:#a7f3d0;background:#ecfdf5;font-weight:600;" title="Unduh <?= h($tmpl['label']) ?>">
                            <span>⬇️</span> Template
                        </a>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openImportModal(<?= (int)$m['id'] ?>, <?= htmlspecialchars(json_encode((string)$m['kode']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$m['nama_mitra']), ENT_QUOTES, 'UTF-8') ?>)" style="font-size:11px;padding:4px 9px;display:inline-flex;align-items:center;gap:3px;font-weight:600;" title="Import Data Scorecard (.xlsx)">
                            <span>📥</span> Import
                        </button>
                    </div>
                </td>
                <?php endif; ?>
                <td style="text-align:center;vertical-align:middle;">
                    <span class="badge badge-<?= $kp['badge'] ?>" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;" title="<?= h($s['hasil_uji'] ?? '') ?>">
                        <span><?= $kp['icon'] ?></span>
                        <span><?= h($kp['label']) ?></span>
                    </span>
                </td>
                <td style="text-align:center;vertical-align:middle;white-space:nowrap;">
                    <div style="display:inline-flex;gap:6px;align-items:center;justify-content:center;">
                        <a href="mitra_edit.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:4px 8px;">Detail</a>
                        <div class="rekom-tooltip-container">
                            <button type="button" class="btn btn-outline btn-sm" 
                                    onclick="showRekomModal(<?= htmlspecialchars(json_encode((string)$m['kode']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$m['nama_mitra']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)($rekomText ?: 'Belum ada rekomendasi yang ditetapkan.')), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$posisiText), ENT_QUOTES, 'UTF-8') ?>)"
                                    style="padding:4px 7px;font-size:12px;line-height:1;border-color:#cbd5e1;cursor:pointer;"
                                    title="Klik untuk melihat rekomendasi lengkap"
                                    aria-label="Rekomendasi">
                                ℹ️
                            </button>
                            <div class="rekom-tooltip-content">
                                <div style="font-weight:700;margin-bottom:3px;color:#93c5fd;font-size:11px;">Rekomendasi Tindak Lanjut:</div>
                                <div><?= nl2br(h(mb_strimwidth($rekomText ?: 'Belum ada rekomendasi yang ditetapkan.', 0, 160, '...'))) ?></div>
                                <div style="margin-top:6px;font-size:10px;color:#94a3b8;border-top:1px solid #334155;padding-top:4px;">Klik ikon ℹ️ untuk membaca lengkap</div>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- MODAL POPUP REKOMENDASI -->
<div id="modalRekom" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div class="modal-anim" style="background:#ffffff;border-radius:12px;width:100%;max-width:620px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
            <div style="display:flex;align-items:center;gap:10px;">
                <span style="font-size:22px;">ℹ️</span>
                <div>
                    <h3 style="margin:0;font-size:16px;font-weight:700;color:#0f172a;">Rekomendasi &amp; Tindak Lanjut Naskah</h3>
                    <div id="modalRekomSub" style="font-size:12px;color:#64748b;margin-top:2px;">-</div>
                </div>
            </div>
            <button type="button" onclick="closeRekomModal()" style="border:none;background:transparent;font-size:24px;cursor:pointer;color:#64748b;line-height:1;" aria-label="Tutup">&times;</button>
        </div>
        <div style="padding:20px;max-height:70vh;overflow-y:auto;">
            <div style="margin-bottom:14px;display:flex;align-items:center;gap:8px;">
                <span style="font-size:12px;font-weight:600;color:#475569;">Posisi Portofolio:</span>
                <span id="modalRekomPosisi" class="badge badge-secondary" style="font-size:11.5px;">-</span>
            </div>
            <div style="font-size:12.5px;font-weight:600;color:#334155;margin-bottom:6px;">Uraian Rekomendasi &amp; Tindak Lanjut:</div>
            <div id="modalRekomText" style="white-space:pre-wrap;font-size:13px;line-height:1.6;color:#1e293b;background:#f8fafc;padding:14px 16px;border-radius:8px;border:1px solid #e2e8f0;max-height:360px;overflow-y:auto;">-</div>
        </div>
        <div style="padding:12px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;">
            <button type="button" onclick="closeRekomModal()" class="btn btn-outline" style="font-size:12.5px;padding:6px 16px;">Tutup</button>
        </div>
    </div>
</div>

<?php if ($canImport): ?>
<!-- MODAL POPUP UPLOAD & IMPORT EXCEL -->
<div id="modalImport" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div class="modal-anim" style="background:#ffffff;border-radius:12px;width:100%;max-width:540px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.25);overflow:hidden;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:20px;">📥</span>
                <h3 style="margin:0;font-size:16px;font-weight:700;color:#0f172a;">Import Data Scorecard (.xlsx)</h3>
            </div>
            <button type="button" onclick="closeImportModal()" style="border:none;background:transparent;font-size:24px;cursor:pointer;color:#64748b;line-height:1;" aria-label="Tutup">&times;</button>
        </div>

        <form method="POST" enctype="multipart/form-data" style="margin:0;">
            <input type="hidden" name="action" value="import_scorecard">

            <div style="padding:20px;">
                <div style="margin-bottom:16px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
                    <div style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Target Naskah Kerja Sama:</div>
                    <div id="modalMitraLabel" style="font-size:13.5px;font-weight:700;color:#1e3a8a;margin-bottom:8px;">-</div>
                    <label for="modalMitraSelect" style="display:block;font-size:11px;color:#475569;margin-bottom:4px;">Ganti target naskah jika diperlukan:</label>
                    <select name="mitra_id" id="modalMitraSelect" onchange="onMitraSelectChange(this)" style="width:100%;padding:7px 10px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:6px;background:#ffffff;">
                        <?php foreach ($all as $item): $im = $item['mitra']; ?>
                            <option value="<?= (int)$im['id'] ?>" data-kode="<?= h($im['kode']) ?>" data-nama="<?= h($im['nama_mitra']) ?>">
                                [<?= h($im['kode']) ?>] <?= h($im['nama_mitra']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-bottom:18px;">
                    <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">
                        Pilih Berkas Spreadsheet Excel (.xlsx) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="file" name="excel_file" accept=".xlsx" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#f8fafc;">
                    <div style="font-size:11.5px;color:#64748b;margin-top:4px;">
                        Format didukung: <strong>.xlsx</strong> (Maks. 25 MB). Gunakan template resmi evaluasi <code>Scorecard_*.xlsx</code> atau <code>template_scorecard_v2_1.xlsx</code>.
                    </div>
                </div>

                <div style="font-size:12.5px;color:#475569;background:#f1f5f9;padding:12px 14px;border-radius:6px;line-height:1.5;">
                    <div style="font-weight:600;margin-bottom:4px;color:#1e293b;">Data yang akan otomatis diperbarui:</div>
                    <div>
                        &bull; <strong>Indikator I1–I7:</strong> Status pemeriksaan, kondisi saat ini, eviden, skor &amp; alasan skor.<br>
                        &bull; <strong>Posisi &amp; Rekomendasi:</strong> Posisi portofolio dan telaah tindak lanjut hasil audit.<br>
                        &bull; <strong>Nilai Final &amp; Status:</strong> Sinkronisasi nilai berjalan, nilai final, dan status scorecard.
                    </div>
                </div>
            </div>

            <div style="padding:14px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" onclick="closeImportModal()" class="btn btn-outline" style="font-size:12.5px;padding:7px 14px;">
                    Batal
                </button>
                <button type="submit" class="btn btn-primary" style="font-size:12.5px;padding:7px 18px;font-weight:600;">
                    📥 Mulai Proses Import
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openImportModal(id, kode, nama) {
    var select = document.getElementById('modalMitraSelect');
    if (select) select.value = id;
    var label = document.getElementById('modalMitraLabel');
    if (label) label.innerHTML = '<strong>[' + escapeHtml(kode) + ']</strong> ' + escapeHtml(nama);
    var modal = document.getElementById('modalImport');
    if (modal) modal.style.display = 'flex';
}

function onMitraSelectChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    var kode = opt.getAttribute('data-kode') || '';
    var nama = opt.getAttribute('data-nama') || opt.text;
    var label = document.getElementById('modalMitraLabel');
    if (label) label.innerHTML = '<strong>[' + escapeHtml(kode) + ']</strong> ' + escapeHtml(nama);
}

function closeImportModal() {
    var modal = document.getElementById('modalImport');
    if (modal) modal.style.display = 'none';
}

function showRekomModal(kode, nama, rekom, posisi) {
    var sub = document.getElementById('modalRekomSub');
    if (sub) sub.textContent = '[' + kode + '] ' + nama;
    var pos = document.getElementById('modalRekomPosisi');
    if (pos) pos.textContent = posisi || '-';
    var txt = document.getElementById('modalRekomText');
    if (txt) txt.textContent = rekom || 'Belum ada rekomendasi yang ditetapkan.';
    var modal = document.getElementById('modalRekom');
    if (modal) modal.style.display = 'flex';
}

function closeRekomModal() {
    var modal = document.getElementById('modalRekom');
    if (modal) modal.style.display = 'none';
}

function escapeHtml(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

window.addEventListener('click', function(e) {
    var mImp = document.getElementById('modalImport');
    if (e.target === mImp) closeImportModal();
    var mRek = document.getElementById('modalRekom');
    if (e.target === mRek) closeRekomModal();
});

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeImportModal();
        closeRekomModal();
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
