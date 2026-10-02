<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'pemeriksa', 'pengampu']);
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/data.php';

$pdo = getDB();
$user = currentUser();
$pageTitle = 'Import Data Naskah';

$success = '';
$errors = [];

// Mapping file template resmi per kode naskah
$templateMap = [
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
    'C01' => ['file' => 'public/templates/import_naskah/01_Scorecard_Final_27_Sep/Scorecard_P05_STAIN_SAR_KEPRI_27_Sep_2026.xlsx', 'label' => 'Scorecard Final (STAIN SAR)'],
    'C02' => ['file' => 'public/templates/import_naskah/02_Portofolio_Pengayaan/C02_Scorecard_Politeknik_Bintan_Cakrawala.xlsx', 'label' => 'Portofolio Pengayaan (C02)'],
    'C03' => ['file' => 'public/templates/import_naskah/02_Portofolio_Pengayaan/C03_Scorecard_Universitas_Ibnu_Sina.xlsx', 'label' => 'Portofolio Pengayaan (C03)'],
    'C04' => ['file' => 'public/templates/import_naskah/02_Portofolio_Pengayaan/C04_Scorecard_UNRIKA.xlsx', 'label' => 'Portofolio Pengayaan (C04)'],
    'C05' => ['file' => 'public/templates/import_naskah/02_Baseline_Final/FINAL_BASELINE_MITRA_KINERJA_AUDIT_FINAL_28_AGUSTUS_2026.xlsx', 'label' => 'Baseline Final Audit (C05)'],
    'P11' => ['file' => 'public/templates/template_scorecard_v2_1.xlsx', 'label' => 'Scorecard Template V2.1 (P11)'],
    'P12' => ['file' => 'public/templates/template_scorecard_v2_1.xlsx', 'label' => 'Scorecard Template V2.1 (P12)'],
    'P13' => ['file' => 'public/templates/template_scorecard_v2_1.xlsx', 'label' => 'Scorecard Template V2.1 (P13)'],
];

// ── PROSES IMPORT WORKBOOK EXCEL ──────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'import_naskah') {
    $targetId = (int)($_POST['mitra_id'] ?? 0);
    $stmtM = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
    $stmtM->execute([$targetId]);
    $targetMitra = $stmtM->fetch();

    if (!$targetMitra) {
        $errors[] = 'Data naskah kerja sama tujuan tidak ditemukan.';
    } elseif (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Silakan pilih file Excel (.xlsx) yang valid untuk di-import.';
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
                $errors[] = 'Gagal membaca isi file Excel. Pastikan file tidak terkunci atau rusak.';
            } else {
                $importType = trim($_POST['import_type'] ?? 'all');
                $targetCode = strtoupper(trim($targetMitra['kode']));

                // Temukan Sheet Baseline & Sheet Scorecard
                $baseSheetName = '';
                $scSheetName = '';
                $rekSheetName = '';
                $picSheetName = '';
                $kegSheetName = '';
                $utlSheetName = '';

                // 1. Deteksi Sheet Baseline
                if ($importType !== 'scorecard') {
                    // a. Cocokkan dengan kode naskah (misal: P01_DEKRANASDA, P02, dll)
                    foreach (array_keys($parsedWb) as $sName) {
                        $upper = strtoupper($sName);
                        if (str_contains($upper, 'REKAP') || str_contains($upper, 'PANDUAN') || str_contains($upper, 'ANOMALI') || str_contains($upper, 'SINKRONISASI')) continue;
                        if (str_contains($upper, $targetCode)) {
                            $baseSheetName = $sName;
                            break;
                        }
                    }
                    // b. Cari sheet yang bernama BASELINE / SUMBER_BASELINE
                    if (!$baseSheetName) {
                        foreach (array_keys($parsedWb) as $sName) {
                            $upper = strtoupper($sName);
                            if (str_contains($upper, 'REKAP') || str_contains($upper, 'PANDUAN')) continue;
                            if (str_contains($upper, 'SUMBER_BASELINE') || str_contains($upper, 'BASELINE')) {
                                $baseSheetName = $sName;
                                break;
                            }
                        }
                    }
                    // c. Jika file hanya memiliki 1 sheet
                    if (!$baseSheetName && count($parsedWb) === 1) {
                        $baseSheetName = array_key_first($parsedWb);
                    }
                    // d. Fallback: cari sheet yang memiliki angka 1..12 di kolom 1
                    if (!$baseSheetName) {
                        foreach ($parsedWb as $shName => $shRows) {
                            $upper = strtoupper($shName);
                            if (str_contains($upper, 'REKAP') || str_contains($upper, 'PANDUAN')) continue;
                            for ($sr = 1; $sr <= 25; $sr++) {
                                if (isset($shRows[$sr][1]) && ((string)$shRows[$sr][1] === '1' || $shRows[$sr][1] === 1)) {
                                    $baseSheetName = $shName;
                                    break 2;
                                }
                            }
                        }
                    }
                }

                // 2. Deteksi Sheet Scorecard
                if ($importType !== 'baseline') {
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
                }

                // 3. Deteksi Sheet Rekomendasi, Identitas, Kegiatan
                foreach (array_keys($parsedWb) as $sName) {
                    $upper = strtoupper($sName);
                    if (str_contains($upper, 'REKOMENDASI')) $rekSheetName = $sName;
                    elseif (str_contains($upper, 'IDENTITAS') || str_contains($upper, 'PIC')) $picSheetName = $sName;
                    elseif (str_contains($upper, 'PELAKSANAAN') || str_contains($upper, 'KEGIATAN') || str_contains($upper, 'SUMBER_AKTUAL')) $kegSheetName = $sName;
                    elseif (str_contains($upper, 'USULAN') || str_contains($upper, 'TINDAK LANJUT')) $utlSheetName = $sName;
                }

                $updatedBaseline = 0;
                $updatedScorecard = 0;
                $fileNaskahExtracted = null;

                // 1. IMPORT DATA BASELINE
                if ($importType !== 'scorecard' && $baseSheetName && !empty($parsedWb[$baseSheetName])) {
                    $baseRows = $parsedWb[$baseSheetName];

                    // Baca metadata pemeriksa & cut-off jika ada
                    $pemeriksaVal = $baseRows[5][8] ?? $baseRows[5][7] ?? $baseRows[5][3] ?? '';
                    $cutoffVal = $baseRows[8][8] ?? $baseRows[8][7] ?? $baseRows[8][2] ?? '';
                    if (!empty($cutoffVal) && preg_match('/(\d{4}-\d{2}-\d{2})/', $cutoffVal, $mCut)) {
                        $cutoffDate = $mCut[1];
                    } else {
                        $cutoffDate = date('Y-m-d');
                    }

                    // Loop baris elemen baseline (fleksibel baris 4 s.d 35)
                    for ($r = 4; $r <= 35; $r++) {
                        if (!isset($baseRows[$r])) continue;
                        $row = $baseRows[$r];
                        $col1 = trim((string)($row[1] ?? ''));
                        if (!is_numeric($col1)) continue;
                        $elNum = (int)$col1;
                        if ($elNum < 1 || $elNum > 12) continue;

                        $rawStatus = strtoupper(trim((string)($row[6] ?? 'BELUM DIISI')));
                        $fakta = trim((string)($row[7] ?? ''));
                        $linkBukti = trim((string)($row[8] ?? ''));
                        $catatan = trim((string)($row[9] ?? ''));

                        // Normalisasi status secara cerdas
                        $finalStatus = 'BELUM DIISI';
                        if (str_contains($rawStatus, 'BELUM TERVERIFIKASI')) {
                            $finalStatus = 'BELUM TERVERIFIKASI';
                        } elseif (str_contains($rawStatus, 'BELUM TERSEDIA') || str_contains($rawStatus, 'TIDAK TERSEDIA') || str_contains($rawStatus, 'TIDAK ADA')) {
                            $finalStatus = 'BELUM TERSEDIA';
                        } elseif (str_contains($rawStatus, 'TIDAK RELEVAN') || str_contains($rawStatus, 'BUKAN')) {
                            $finalStatus = 'TIDAK RELEVAN';
                        } elseif (str_contains($rawStatus, 'TERVERIFIKASI') || str_contains($rawStatus, 'SESUAI') || str_contains($rawStatus, 'VERIFIED') || str_contains($rawStatus, 'ADA') || str_contains($rawStatus, 'SUDAH')) {
                            $finalStatus = 'TERVERIFIKASI';
                        } elseif (!empty($linkBukti) || !empty($fakta)) {
                            $finalStatus = !empty($linkBukti) ? 'TERVERIFIKASI' : 'BELUM TERVERIFIKASI';
                        }

                        // Update atau insert ke baseline_elemen
                        $stmtCheck = $pdo->prepare('SELECT id FROM baseline_elemen WHERE mitra_id = ? AND nomor_elemen = ?');
                        $stmtCheck->execute([$targetId, $elNum]);
                        if ($stmtCheck->fetch()) {
                            $stmtU = $pdo->prepare('UPDATE baseline_elemen SET status = ?, fakta_pemeriksaan = ?, link_sumber_bukti = ?, catatan = ? WHERE mitra_id = ? AND nomor_elemen = ?');
                            $stmtU->execute([$finalStatus, $fakta, $linkBukti, $catatan, $targetId, $elNum]);
                        } else {
                            $def = BASELINE_12_DEFS[$elNum] ?? ['kelompok' => 'UMUM', 'nama' => "Elemen $elNum", 'yang_diperiksa' => '', 'sumber_minimum' => ''];
                            $stmtI = $pdo->prepare('INSERT INTO baseline_elemen (mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa, sumber_bukti_minimum, status, fakta_pemeriksaan, link_sumber_bukti, catatan) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                            $stmtI->execute([$targetId, $elNum, $def['kelompok'], $def['nama'], $def['yang_diperiksa'], $def['sumber_minimum'], $finalStatus, $fakta, $linkBukti, $catatan]);
                        }
                        $updatedBaseline++;

                        // Jika elemen 1 memiliki URL naskah PDF / P2MA, simpan ke file_naskah
                        if ($elNum === 1 && !empty($linkBukti)) {
                            if (preg_match('/^https?:\/\/[^\s]+/i', $linkBukti, $mUrl)) {
                                $fileNaskahExtracted = $mUrl[0];
                            } elseif (str_starts_with($linkBukti, 'public/uploads/')) {
                                $fileNaskahExtracted = $linkBukti;
                            }
                        }
                    }

                    // Update ringkasan status baseline pada mitra_kinerja
                    $updateSql = 'UPDATE mitra_kinerja SET baseline_status = \'TERVERIFIKASI / DIKUNCI\', baseline_locked_at = NOW(), baseline_locked_by = ?';
                    $params = [$user['id']];
                    if (!empty($pemeriksaVal)) {
                        $updateSql .= ', baseline_pemeriksa = ?';
                        $params[] = $pemeriksaVal;
                    }
                    if (!empty($cutoffDate)) {
                        $updateSql .= ', cutoff_date = ?';
                        $params[] = $cutoffDate;
                    }
                    if (!empty($fileNaskahExtracted)) {
                        $updateSql .= ', file_naskah = ?';
                        $params[] = $fileNaskahExtracted;
                    }
                    $updateSql .= ' WHERE id = ?';
                    $params[] = $targetId;
                    $pdo->prepare($updateSql)->execute($params);
                }

                // 2. IMPORT DATA SCORECARD
                if ($importType !== 'baseline' && $scSheetName && !empty($parsedWb[$scSheetName])) {
                    $scRows = $parsedWb[$scSheetName];
                    $isPenilaianLayout = str_contains(strtoupper($scSheetName), 'PENILAIAN');

                    // Bobot default V2.1
                    $weights = ['I1' => 10, 'I2' => 15, 'I3' => 15, 'I4' => 20, 'I5' => 20, 'I6' => 10, 'I7' => 10];

                    foreach ($scRows as $rIdx => $row) {
                        $col1 = strtoupper(trim($row[1] ?? ''));
                        if (!preg_match('/^(I[1-7])\b/', $col1, $mCode)) continue;
                        $kodeInd = $mCode[1];

                        $bobot = isset($weights[$kodeInd]) ? $weights[$kodeInd] : (int)($row[2] ?? 10);

                        if ($isPenilaianLayout) {
                            $kondisiBaseline = trim($row[4] ?? '');
                            $kondisi = trim($row[5] ?? '');
                            $rawStatus = strtoupper(trim($row[6] ?? ''));
                            $evidenceLoc = trim($row[7] ?? '');
                            $rawSkor = trim($row[8] ?? '');
                            $alasanSkor = trim($row[10] ?? '');
                            $catatanTl = trim($row[11] ?? '');
                        } else {
                            $kondisiBaseline = trim($row[3] ?? '');
                            $rawStatus = strtoupper(trim($row[5] ?? ''));
                            $kondisi = trim($row[6] ?? '');
                            $rawSkor = trim($row[7] ?? '');
                            $alasanSkor = trim($row[8] ?? '');
                            $catatanTl = trim($row[9] ?? '');
                            $evidenceLoc = '';
                        }

                        // Normalisasi status pemeriksaan
                        $statusPem = 'BELUM DITELAAH';
                        if (str_contains($rawStatus, 'BELUM DAPAT') || str_contains($rawStatus, 'BELUM DINILAI')) $statusPem = 'BELUM DAPAT DINILAI';
                        elseif (str_contains($rawStatus, 'DAPAT DINILAI') || (str_contains($rawStatus, 'MEMADAI') && !str_contains($rawStatus, 'BELUM'))) $statusPem = 'BUKTI MEMADAI';
                        elseif (str_contains($rawStatus, 'CUKUP')) $statusPem = 'BUKTI CUKUP';
                        elseif (str_contains($rawStatus, 'BELUM MEMADAI')) $statusPem = 'BUKTI BELUM MEMADAI';

                        $skor = is_numeric($rawSkor) ? (int)$rawSkor : null;
                        $nilai = $skor !== null ? round(($skor / 4.0) * $bobot, 2) : null;

                        // Cek apakah indikator sudah ada
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

                    // Metadata tambahan dari Scorecard final (Status, Rekomendasi, Posisi)
                    $rawScStatus = '';
                    if ($isPenilaianLayout && isset($scRows[27][2])) {
                        $rawScStatus = trim($scRows[27][2]);
                    }

                    $posisiPortofolio = '';
                    $rekomNarrative = '';
                    if ($rekSheetName && !empty($parsedWb[$rekSheetName])) {
                        $rekRows = $parsedWb[$rekSheetName];
                        $posisiPortofolio = trim($rekRows[9][2] ?? '');
                        $rekomText = trim($rekRows[17][2] ?? '');
                        $temuan = trim($rekRows[16][2] ?? '');
                        $tl = trim($rekRows[19][2] ?? '');
                        if ($rekomText) {
                            $rekomNarrative = trim("$rekomText\n\nTemuan Utama:\n$temuan\n\nRencana Tindak Lanjut:\n$tl");
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

                    // Sinkronkan total skor dan status scorecard
                    syncStatusScorecard($pdo, $targetId);
                }

                // 3. IMPORT DATA IDENTITAS & PIC (JIKA ADA)
                if ($picSheetName && !empty($parsedWb[$picSheetName])) {
                    $picRows = $parsedWb[$picSheetName];
                    $picInternalFound = '';
                    $picMitraFound = '';
                    foreach ($picRows as $pRow) {
                        $label = strtolower(trim($pRow[1] ?? ''));
                        $val = trim($pRow[2] ?? '');
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

                if ($importType === 'baseline') {
                    logAudit($targetId, $user['id'], 'IMPORT_BASELINE', "Import Baseline untuk {$targetMitra['kode']}: {$updatedBaseline} elemen diperbarui.");
                    $success = "Data Baseline FIX (12 Elemen) untuk <strong>{$targetMitra['kode']} - {$targetMitra['nama_mitra']}</strong> berhasil di-import!<br>"
                             . "&bull; {$updatedBaseline} elemen Baseline FIX diperbarui dan diverifikasi.<br>"
                             . "&bull; Status Baseline naskah telah dikunci (TERVERIFIKASI / DIKUNCI).<br>"
                             . ($fileNaskahExtracted ? "&bull; Tautan naskah resmi P2MA terhubung secara otomatis.<br>" : "");
                } elseif ($importType === 'scorecard') {
                    logAudit($targetId, $user['id'], 'IMPORT_SCORECARD', "Import Scorecard untuk {$targetMitra['kode']}: {$updatedScorecard} indikator diperbarui.");
                    $success = "Data Scorecard untuk <strong>{$targetMitra['kode']} - {$targetMitra['nama_mitra']}</strong> berhasil di-import!<br>"
                             . "&bull; {$updatedScorecard} indikator Scorecard disinkronkan ke sistem.<br>"
                             . (!empty($rawScStatus) ? "&bull; Status Scorecard: <strong>" . h($rawScStatus) . "</strong>.<br>" : "");
                } else {
                    logAudit($targetId, $user['id'], 'IMPORT_EXCEL', "Import workbook Excel untuk {$targetMitra['kode']}: {$updatedBaseline} elemen baseline, {$updatedScorecard} indikator scorecard diperbarui.");
                    $success = "Data untuk naskah <strong>{$targetMitra['kode']} - {$targetMitra['nama_mitra']}</strong> berhasil di-import!<br>"
                             . ($updatedBaseline ? "&bull; {$updatedBaseline} elemen Baseline FIX diperbarui.<br>" : "")
                             . ($updatedScorecard ? "&bull; {$updatedScorecard} indikator Scorecard disinkronkan ke sistem.<br>" : "")
                             . ($fileNaskahExtracted ? "&bull; Tautan naskah resmi P2MA terhubung secara otomatis.<br>" : "");
                }
            }
        }
    }
}

// Ambil daftar seluruh naskah kerja sama
$stmt = $pdo->query('
    SELECT m.*, 
           (SELECT COUNT(*) FROM baseline_elemen WHERE mitra_id = m.id AND status = "TERVERIFIKASI") as total_terverifikasi,
           (SELECT COUNT(*) FROM indikator_skor WHERE mitra_id = m.id AND skor IS NOT NULL) as total_terisi_skor,
           (SELECT ROUND(SUM(nilai), 1) FROM indikator_skor WHERE mitra_id = m.id) as total_skor
    FROM mitra_kinerja m 
    ORDER BY m.portofolio DESC, m.kode ASC
');
$daftarMitra = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div class="content-header" style="margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="margin:0 0 4px 0;font-size:22px;color:#0f172a;display:flex;align-items:center;gap:8px;">
                <span>📥</span> Menu Import Data Naskah
            </h1>
            <p style="margin:0;font-size:13px;color:#64748b;">
                Perbarui data <strong>Baseline FIX (12 Elemen)</strong> dan <strong>Scorecard</strong> kerja sama secara langsung melalui file spreadsheet Excel (.xlsx).
            </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="mitra_manage.php" class="btn btn-outline" style="font-size:12px;">
                &larr; Manajemen Naskah
            </a>
            <a href="baseline.php" class="btn btn-outline" style="font-size:12px;">
                Buka Baseline &rarr;
            </a>
            <a href="scorecard.php" class="btn btn-primary" style="font-size:12px;">
                Buka Scorecard &rarr;
            </a>
        </div>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success" style="margin-bottom:20px;border-left:4px solid #10b981;background:#ecfdf5;color:#065f46;padding:14px 18px;border-radius:8px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:4px;">Berhasil Memproses Import!</div>
    <div style="font-size:13px;line-height:1.5;"><?= $success ?></div>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" style="margin-bottom:20px;border-left:4px solid #ef4444;background:#fef2f2;color:#991b1b;padding:14px 18px;border-radius:8px;">
    <div style="font-weight:700;font-size:14px;margin-bottom:4px;">Terjadi Kesalahan:</div>
    <ul style="margin:4px 0 0 18px;padding:0;font-size:13px;">
        <?php foreach ($errors as $err): ?>
        <li><?= h($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- KARTU INFORMASI PANDUAN IMPORT -->
<div class="card" style="margin-bottom:24px;border:1px solid #e2e8f0;background:#ffffff;border-radius:10px;padding:18px 20px;">
    <div style="display:flex;gap:16px;align-items:flex-start;">
        <div style="font-size:28px;line-height:1;">💡</div>
        <div style="flex:1;">
            <h3 style="margin:0 0 6px 0;font-size:15px;color:#1e293b;font-weight:700;">Petunjuk Penggunaan Template &amp; Mekanisme Import:</h3>
            <div style="font-size:13px;color:#475569;line-height:1.6;">
                1. <strong>Data Baseline (12 Elemen):</strong> Jika naskah kerja sama belum memiliki data Baseline (seperti P12, P13, atau naskah baru), tombol <strong>[⬇️ Unduh Template]</strong> dan <strong>[📥 Import]</strong> pada kolom Baseline akan <strong>aktif</strong>. Setelah data Baseline terisi/terkunci, tombol tersebut secara otomatis menjadi <strong>terkunci (disabled)</strong> untuk menjaga keutuhan data awal.<br>
                2. <strong>Data Scorecard (V2.1):</strong> Unduh template evaluasi kinerja naskah melalui kolom Template Scorecard, lalu unggah berkas penilaian melalui tombol <strong>[📥 Import]</strong> Scorecard untuk memperbarui nilai berjalan I1–I7, posisi portofolio, dan rekomendasi tindak lanjut.<br>
                3. Sistem secara otomatis mendeteksi format file Excel (.xlsx), membaca sheet <code>PENILAIAN</code>, <code>REKOMENDASI</code>, maupun <code>BASELINE_12_ELEMEN</code>, dan menyinkronkannya ke database.
            </div>
        </div>
    </div>
</div>

<!-- TABEL DATA NASKAH & IMPORT -->
<div class="card" style="border:1px solid #e2e8f0;background:#ffffff;border-radius:10px;overflow:hidden;">
    <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
        <div>
            <h3 style="margin:0;font-size:16px;color:#0f172a;font-weight:700;">Daftar Naskah Kerja Sama &amp; Fitur Import</h3>
            <div style="font-size:12px;color:#64748b;margin-top:2px;">Total: <?= count($daftarMitra) ?> Naskah Kerja Sama Terdaftar</div>
        </div>
    </div>

    <div class="table-wrap" style="width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;">
        <table class="table" style="width:100%;min-width:960px;margin:0;border-collapse:collapse;font-size:12.5px;">
            <thead>
                <tr style="background:#f1f5f9;color:#334155;text-align:left;border-bottom:1px solid #cbd5e1;">
                    <th rowspan="2" style="padding:10px 8px;width:55px;text-align:center;vertical-align:middle;">Kode</th>
                    <th rowspan="2" style="padding:10px 8px;width:90px;vertical-align:middle;">Portofolio</th>
                    <th rowspan="2" style="padding:10px 10px;vertical-align:middle;">Mitra &amp; Judul Kerja Sama</th>
                    <th rowspan="2" style="padding:10px 8px;width:70px;text-align:center;vertical-align:middle;">Bidang</th>
                    <th colspan="3" style="padding:8px 8px;text-align:center;background:#eef2ff;color:#3730a3;border-left:1px solid #cbd5e1;border-right:1px solid #cbd5e1;font-weight:700;">
                        📋 DATA BASELINE (12 ELEMEN)
                    </th>
                    <th colspan="3" style="padding:8px 8px;text-align:center;background:#ecfdf5;color:#065f46;font-weight:700;">
                        📊 DATA SCORECARD (V2.1)
                    </th>
                </tr>
                <tr style="background:#f8fafc;color:#475569;border-bottom:2px solid #cbd5e1;font-size:11.5px;">
                    <!-- Baseline Sub-Columns -->
                    <th style="padding:6px 6px;text-align:center;border-left:1px solid #cbd5e1;width:100px;">Status Baseline</th>
                    <th style="padding:6px 6px;text-align:center;width:105px;">Template Excel</th>
                    <th style="padding:6px 6px;text-align:center;border-right:1px solid #cbd5e1;width:85px;">Aksi Import</th>
                    <!-- Scorecard Sub-Columns -->
                    <th style="padding:6px 6px;text-align:center;width:100px;">Status &amp; Nilai</th>
                    <th style="padding:6px 6px;text-align:center;width:105px;">Template Excel</th>
                    <th style="padding:6px 6px;text-align:center;width:85px;">Aksi Import</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daftarMitra as $m): 
                    $tInfo = $templateMap[$m['kode']] ?? null;
                    $isLocked = str_contains($m['baseline_status'] ?? '', 'DIKUNCI');
                    $hasBaseline = $isLocked || ((int)($m['total_terverifikasi'] ?? 0) > 0);
                    $scorecardStatus = $m['status_scorecard'] ?? 'BELUM LENGKAP';
                ?>
                <tr style="border-bottom:1px solid #f1f5f9;transition:background 0.15s ease;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                    <td style="padding:12px 14px;text-align:center;font-weight:700;color:#1e40af;vertical-align:middle;">
                        <?= h($m['kode']) ?>
                    </td>
                    <td style="padding:12px 14px;vertical-align:middle;">
                        <?php if ($m['portofolio'] === 'Pilot Utama' || $m['portofolio'] === 'PILOT'): ?>
                            <span class="badge badge-primary" style="font-size:10.5px;padding:3px 8px;">Pilot Utama</span>
                        <?php else: ?>
                            <span class="badge badge-secondary" style="font-size:10.5px;padding:3px 8px;">Cadangan</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 14px;vertical-align:middle;">
                        <div style="font-weight:600;color:#0f172a;margin-bottom:2px;font-size:13.5px;">
                            <?= h($m['nama_mitra']) ?>
                        </div>
                        <div style="font-size:12px;color:#64748b;line-height:1.35;">
                            <?= h($m['judul'] ?: '-') ?>
                        </div>
                        <?php if (!empty($m['file_naskah'])): ?>
                            <div style="margin-top:4px;">
                                <a href="<?= h($m['file_naskah']) ?>" target="_blank" rel="noopener noreferrer" style="font-size:11px;color:#2563eb;text-decoration:none;display:inline-flex;align-items:center;gap:3px;">
                                    <span>📄</span> Naskah P2MA Resmi &rarr;
                                </a>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 14px;text-align:center;vertical-align:middle;">
                        <span class="badge badge-outline" style="font-size:11px;font-weight:600;">
                            <?= h($m['bidang'] ?: ($m['jenis'] ?: 'AHU')) ?>
                        </span>
                    </td>

                    <!-- 1. BASELINE: STATUS -->
                    <td style="padding:12px 14px;text-align:center;vertical-align:middle;border-left:1px solid #f1f5f9;">
                        <?php if ($isLocked): ?>
                            <span class="badge badge-success" style="font-size:10.5px;padding:3px 7px;display:inline-flex;align-items:center;gap:3px;">
                                <span>🔒</span> Terkunci
                            </span>
                            <div style="font-size:10.5px;color:#64748b;margin-top:2px;">(12 Elemen)</div>
                        <?php elseif ((int)($m['total_terverifikasi'] ?? 0) > 0): ?>
                            <span class="badge badge-warning" style="font-size:10.5px;padding:3px 7px;">
                                Dalam Proses
                            </span>
                            <div style="font-size:10.5px;color:#64748b;margin-top:2px;"><?= $m['total_terverifikasi'] ?>/12 Terisi</div>
                        <?php else: ?>
                            <span class="badge badge-secondary" style="font-size:10.5px;padding:3px 7px;background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;">
                                Belum Ada
                            </span>
                            <div style="font-size:10.5px;color:#94a3b8;margin-top:2px;">0/12 Terisi</div>
                        <?php endif; ?>
                    </td>

                    <!-- 2. BASELINE: TEMPLATE EXCEL -->
                    <td style="padding:12px 14px;text-align:center;vertical-align:middle;">
                        <?php if ($hasBaseline): ?>
                            <button type="button" class="btn btn-sm" disabled style="font-size:10.5px;padding:4px 8px;color:#94a3b8;background:#f8fafc;border:1px solid #e2e8f0;cursor:not-allowed;" title="Data Baseline sudah terisi / terkunci. Unduh template dinonaktifkan.">
                                <span>🔒</span> Terkunci
                            </button>
                        <?php else: ?>
                            <a href="public/templates/template_baseline_12_elemen.xlsx" download="Template_Baseline_<?= h($m['kode']) ?>.xlsx" class="btn btn-outline btn-sm" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;padding:5px 9px;color:#4338ca;border-color:#c7d2fe;background:#eef2ff;font-weight:600;" title="Unduh Formulir Template Baseline (.xlsx)">
                                <span>⬇️</span> Unduh Template
                            </a>
                        <?php endif; ?>
                    </td>

                    <!-- 3. BASELINE: AKSI IMPORT -->
                    <td style="padding:12px 14px;text-align:center;vertical-align:middle;border-right:1px solid #f1f5f9;">
                        <?php if ($hasBaseline): ?>
                            <button type="button" class="btn btn-sm" disabled style="font-size:10.5px;padding:5px 10px;color:#94a3b8;background:#f8fafc;border:1px solid #e2e8f0;cursor:not-allowed;" title="Data Baseline sudah terverifikasi dan terkunci. Import dinonaktifkan.">
                                <span>🔒</span> Terkunci
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm" onclick="openImportModal(<?= (int)$m['id'] ?>, <?= htmlspecialchars(json_encode((string)$m['kode']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$m['nama_mitra']), ENT_QUOTES, 'UTF-8') ?>, 'baseline')" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;padding:5px 12px;background:#4f46e5;color:#ffffff;border:none;border-radius:4px;font-weight:600;cursor:pointer;" title="Import Data Baseline">
                                <span>📥</span> Import
                            </button>
                        <?php endif; ?>
                    </td>

                    <!-- 4. SCORECARD: STATUS & NILAI -->
                    <td style="padding:12px 14px;text-align:center;vertical-align:middle;">
                        <?php if ($scorecardStatus === 'FINAL' || $scorecardStatus === 'FINAL/TERVALIDASI'): ?>
                            <span class="badge badge-success" style="font-size:10.5px;padding:3px 7px;">Tervalidasi</span>
                        <?php elseif ($scorecardStatus === 'SIAP_VALIDASI' || $scorecardStatus === 'SIAP DIVALIDASI'): ?>
                            <span class="badge badge-info" style="font-size:10.5px;padding:3px 7px;">Siap Validasi</span>
                        <?php elseif ($scorecardStatus === 'MASA IMPLEMENTASI AWAL'): ?>
                            <span class="badge badge-warning" style="font-size:10.5px;padding:3px 7px;background:#fef3c7;color:#92400e;border:1px solid #fde68a;">Implementasi Awal</span>
                        <?php else: ?>
                            <span class="badge badge-secondary" style="font-size:10.5px;padding:3px 7px;">Belum Lengkap</span>
                        <?php endif; ?>
                        <?php if (isset($m['total_skor']) && $m['total_skor'] !== null && $m['total_skor'] !== ''): ?>
                            <div style="font-size:11px;font-weight:700;color:#0f172a;margin-top:2px;">
                                <?= number_format((float)$m['total_skor'], 1) ?>
                            </div>
                        <?php endif; ?>
                    </td>

                    <!-- 5. SCORECARD: TEMPLATE EXCEL -->
                    <td style="padding:12px 14px;text-align:center;vertical-align:middle;">
                        <?php if ($tInfo && file_exists(__DIR__ . '/' . $tInfo['file'])): ?>
                            <a href="<?= h($tInfo['file']) ?>" download="Template_Scorecard_<?= h($m['kode']) ?>.xlsx" class="btn btn-outline btn-sm" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;padding:5px 9px;color:#047857;border-color:#a7f3d0;background:#ecfdf5;font-weight:600;" title="Unduh Template Scorecard (.xlsx)">
                                <span>⬇️</span> Unduh Template
                            </a>
                        <?php else: ?>
                            <a href="public/templates/template_scorecard_v2_1.xlsx" download="Template_Scorecard_<?= h($m['kode']) ?>.xlsx" class="btn btn-outline btn-sm" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;padding:5px 9px;color:#047857;border-color:#a7f3d0;background:#ecfdf5;font-weight:600;" title="Unduh Template Scorecard (.xlsx)">
                                <span>⬇️</span> Unduh Template
                            </a>
                        <?php endif; ?>
                    </td>

                    <!-- 6. SCORECARD: AKSI IMPORT -->
                    <td style="padding:12px 14px;text-align:center;vertical-align:middle;">
                        <button type="button" class="btn btn-primary btn-sm" onclick="openImportModal(<?= (int)$m['id'] ?>, <?= htmlspecialchars(json_encode((string)$m['kode']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$m['nama_mitra']), ENT_QUOTES, 'UTF-8') ?>, 'scorecard')" style="font-size:11.5px;display:inline-flex;align-items:center;gap:4px;padding:5px 12px;font-weight:600;" title="Import Data Scorecard">
                            <span>📥</span> Import
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL POPUP UPLOAD & IMPORT EXCEL -->
<div id="modalImport" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#ffffff;border-radius:12px;width:100%;max-width:540px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);overflow:hidden;animation:fadeIn 0.2s ease-out;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:20px;">📥</span>
                <h3 id="modalTitle" style="margin:0;font-size:16px;font-weight:700;color:#0f172a;">Import Data Naskah (.xlsx)</h3>
            </div>
            <button type="button" onclick="closeImportModal()" style="border:none;background:transparent;font-size:20px;cursor:pointer;color:#64748b;">&times;</button>
        </div>

        <form method="POST" enctype="multipart/form-data" style="margin:0;">
            <input type="hidden" name="action" value="import_naskah">
            <input type="hidden" name="mitra_id" id="modalMitraId" value="0">
            <input type="hidden" name="import_type" id="modalImportType" value="scorecard">

            <div style="padding:20px;">
                <div style="margin-bottom:16px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
                    <div id="modalTargetTypeLabel" style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:0.5px;">Target Naskah Kerja Sama:</div>
                    <div id="modalMitraLabel" style="font-size:14px;font-weight:700;color:#1e3a8a;margin-top:2px;">-</div>
                </div>

                <div style="margin-bottom:18px;">
                    <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">
                        Pilih Berkas Spreadsheet Excel (.xlsx) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="file" name="excel_file" accept=".xlsx" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#f8fafc;">
                    <div id="modalHelpText" style="font-size:11.5px;color:#64748b;margin-top:4px;">
                        Format didukung: <strong>.xlsx</strong> (Maks. 25 MB). Gunakan template resmi dari folder <code>01_Pilot_Utama</code> atau <code>02_Portofolio_Pengayaan</code>.
                    </div>
                </div>

                <div id="modalInfoBox" style="font-size:12.5px;color:#475569;background:#f1f5f9;padding:12px 14px;border-radius:6px;line-height:1.5;">
                    <div style="font-weight:600;margin-bottom:4px;color:#1e293b;">Data yang akan otomatis di-update:</div>
                    <div id="modalInfoItems">
                        &bull; <strong>Baseline FIX:</strong> Status, fakta pemeriksaan, tautan bukti pada 12 Elemen.<br>
                        &bull; <strong>Scorecard:</strong> Status pemeriksaan, kondisi saat ini, skor & alasan skor I1-I7.<br>
                        &bull; <strong>Tautan Naskah Resmi:</strong> Tautan naskah P2MA pada Elemen 1 otomatis ditautkan ke profil kerja sama.
                    </div>
                </div>
            </div>

            <div style="padding:14px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" onclick="closeImportModal()" class="btn btn-outline" style="font-size:12.5px;padding:7px 14px;">
                    Batal
                </button>
                <button type="submit" id="modalSubmitBtn" class="btn btn-primary" style="font-size:12.5px;padding:7px 18px;font-weight:600;">
                    📥 Mulai Proses Import
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openImportModal(id, kode, nama, type) {
    type = type || 'scorecard';
    document.getElementById('modalMitraId').value = id;
    document.getElementById('modalImportType').value = type;
    
    var titleEl = document.getElementById('modalTitle');
    var targetLabelEl = document.getElementById('modalTargetTypeLabel');
    var helpEl = document.getElementById('modalHelpText');
    var infoItemsEl = document.getElementById('modalInfoItems');
    var submitBtn = document.getElementById('modalSubmitBtn');

    if (type === 'baseline') {
        titleEl.textContent = 'Import Data Baseline FIX (12 Elemen)';
        targetLabelEl.textContent = 'Target Naskah Kerja Sama (Modul Baseline):';
        document.getElementById('modalMitraLabel').innerHTML = '<strong>[' + kode + ']</strong> ' + nama + ' <span class="badge badge-info" style="font-size:10px;margin-left:6px;background:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;">Modul Baseline</span>';
        helpEl.innerHTML = 'Format didukung: <strong>.xlsx</strong> (Maks. 25 MB). Gunakan template <code>template_baseline_12_elemen.xlsx</code>.';
        infoItemsEl.innerHTML = '&bull; <strong>12 Elemen Baseline:</strong> Status pemeriksaan, fakta audit, dan tautan bukti.<br>' +
                                '&bull; <strong>Kunci Status:</strong> Otomatis mengunci status baseline menjadi <em>TERVERIFIKASI / DIKUNCI</em>.<br>' +
                                '&bull; <strong>Scan Naskah:</strong> Tautan naskah P2MA resmi pada Elemen 1 otomatis terhubung.';
        submitBtn.className = 'btn';
        submitBtn.style.background = '#4f46e5';
        submitBtn.style.color = '#ffffff';
        submitBtn.innerHTML = '📥 Mulai Import Baseline';
    } else {
        titleEl.textContent = 'Import Data Scorecard Kinerja (V2.1)';
        targetLabelEl.textContent = 'Target Naskah Kerja Sama (Modul Scorecard):';
        document.getElementById('modalMitraLabel').innerHTML = '<strong>[' + kode + ']</strong> ' + nama + ' <span class="badge badge-primary" style="font-size:10px;margin-left:6px;">Modul Scorecard</span>';
        helpEl.innerHTML = 'Format didukung: <strong>.xlsx</strong> (Maks. 25 MB). Gunakan template evaluasi <code>Scorecard_*.xlsx</code>.';
        infoItemsEl.innerHTML = '&bull; <strong>Indikator I1–I7:</strong> Status penilaian, kondisi saat ini, skor & alasan skor.<br>' +
                                '&bull; <strong>Rekomendasi & Posisi:</strong> Posisi portofolio dan telaah tindak lanjut.<br>' +
                                '&bull; <strong>Sinkronisasi Total Nilai:</strong> Nilai berjalan diperbarui secara otomatis.';
        submitBtn.className = 'btn btn-primary';
        submitBtn.style.background = '';
        submitBtn.style.color = '';
        submitBtn.innerHTML = '📥 Mulai Import Scorecard';
    }

    var modal = document.getElementById('modalImport');
    modal.style.display = 'flex';
}

function closeImportModal() {
    var modal = document.getElementById('modalImport');
    modal.style.display = 'none';
}

// Tutup modal jika klik di luar area konten
window.addEventListener('click', function(e) {
    var modal = document.getElementById('modalImport');
    if (e.target === modal) {
        closeImportModal();
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
