<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$canEdit = in_array($role, ['admin', 'pemeriksa', 'pengampu'], true);
$isAdmin = ($role === 'admin');

$id = (int)($_GET['id'] ?? 0);
$view = $_GET['view'] ?? 'ledger';

$errors = [];
$success = '';

/* ── DEFINISI 12 ELEMEN BASELINE FIX (DEFINED IN INCLUDES/FUNCTIONS.PHP) ─────── */
$b12Defs = BASELINE_12_DEFS;

/* ── POST HANDLERS ───────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['mitra_id'] ?? $id);

    // Ambil data mitra terkait
    $targetMitra = null;
    $isTargetLocked = false;
    if ($targetId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
        $stmt->execute([$targetId]);
        $targetMitra = $stmt->fetch();
        if ($targetMitra) {
            $isTargetLocked = ($targetMitra['baseline_status'] === 'TERVERIFIKASI / DIKUNCI');
        }
    }

    // 0. Import Data Baseline (12 Elemen) dari Berkas Spreadsheet (.xlsx)
    if ($action === 'import_baseline') {
        if (!$targetMitra) {
            $errors[] = 'Data mitra tidak ditemukan.';
        } elseif (!$canEdit) {
            $errors[] = 'Akses ditolak: Anda tidak memiliki hak akses untuk mengimpor baseline.';
        } elseif ($isTargetLocked && !$isAdmin) {
            $errors[] = 'Baseline FIX untuk mitra ' . $targetMitra['kode'] . ' telah dikunci. Buka kunci terlebih dahulu untuk mengimpor ulang data.';
        } elseif (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Pilih berkas spreadsheet Excel (.xlsx) yang valid.';
        } else {
            $fileInfo = $_FILES['excel_file'];
            $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));

            if ($ext !== 'xlsx') {
                $errors[] = 'Format berkas tidak didukung. Harap unggah file spreadsheet Excel dengan ekstensi .xlsx.';
            } elseif ($fileInfo['size'] > 25 * 1024 * 1024) {
                $errors[] = 'Ukuran berkas melebihi batas maksimum 25 MB.';
            } else {
                try {
                    $parsedWb = parseFullWorkbookXlsx($fileInfo['tmp_name']);
                } catch (Throwable $e) {
                    error_log('parseFullWorkbookXlsx error: ' . $e->getMessage());
                    $parsedWb = [];
                }

                if (empty($parsedWb)) {
                    $errors[] = 'Gagal membaca isi berkas Excel. Pastikan berkas tidak terkunci password atau rusak.';
                } else {
                    $targetCode = strtoupper(trim($targetMitra['kode']));
                    $baseSheetName = '';

                    // 1. Deteksi Sheet Baseline
                    // a. Cocokkan dengan kode naskah (misal: P01_DEKRANASDA, P02, dll)
                    foreach (array_keys($parsedWb) as $sName) {
                        $upper = strtoupper($sName);
                        if (str_contains($upper, 'REKAP') || str_contains($upper, 'PANDUAN') || str_contains($upper, 'ANOMALI') || str_contains($upper, 'SINKRONISASI')) continue;
                        if (str_contains($upper, $targetCode)) {
                            $baseSheetName = $sName;
                            break;
                        }
                    }
                    // b. Cari sheet yang bernama BASELINE / SUMBER_BASELINE / BASELINE_12_ELEMEN
                    if (!$baseSheetName) {
                        foreach (array_keys($parsedWb) as $sName) {
                            $upper = strtoupper($sName);
                            if (str_contains($upper, 'REKAP') || str_contains($upper, 'PANDUAN')) continue;
                            if (str_contains($upper, 'BASELINE') || str_contains($upper, 'SUMBER_BASELINE')) {
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

                    if (!$baseSheetName || empty($parsedWb[$baseSheetName])) {
                        $errors[] = 'Sheet baseline 12 elemen tidak ditemukan di dalam berkas Excel.';
                    } else {
                        $baseRows = $parsedWb[$baseSheetName];

                        // Baca metadata pemeriksa & cut-off jika ada
                        $pemeriksaVal = trim((string)($baseRows[5][8] ?? $baseRows[5][7] ?? $baseRows[5][3] ?? ''));
                        $cutoffVal = trim((string)($baseRows[8][8] ?? $baseRows[8][7] ?? $baseRows[8][2] ?? ''));
                        if (!empty($cutoffVal) && preg_match('/(\d{4}-\d{2}-\d{2})/', $cutoffVal, $mCut)) {
                            $cutoffDate = $mCut[1];
                        } elseif (is_numeric($cutoffVal) && (int)$cutoffVal > 30000) {
                            $cutoffDate = gmdate('Y-m-d', ((int)$cutoffVal - 25569) * 86400);
                        } else {
                            $cutoffDate = null;
                        }

                        $updatedBaseline = 0;
                        $fileNaskahExtracted = null;

                        $pdo->beginTransaction();
                        try {
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

                                $def = BASELINE_12_DEFS[$elNum];
                                $stmtE = $pdo->prepare('INSERT INTO baseline_elemen (
                                    mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa, sumber_bukti_minimum,
                                    status, fakta_pemeriksaan, link_sumber_bukti, catatan
                                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    status = VALUES(status),
                                    fakta_pemeriksaan = VALUES(fakta_pemeriksaan),
                                    link_sumber_bukti = VALUES(link_sumber_bukti),
                                    catatan = VALUES(catatan)');
                                $stmtE->execute([
                                    $targetId, $elNum,
                                    $def['kelompok'], $def['nama'], $def['yang_diperiksa'], $def['sumber_minimum'],
                                    $finalStatus, $fakta, $linkBukti, $catatan
                                ]);
                                $updatedBaseline++;

                                // Elemen 1 PDF / naskah
                                if ($elNum === 1 && !empty($linkBukti)) {
                                    if (preg_match('/^https?:\/\/[^\s]+/i', $linkBukti, $mUrl)) {
                                        $fileNaskahExtracted = $mUrl[0];
                                    } elseif (str_starts_with($linkBukti, 'public/uploads/')) {
                                        $fileNaskahExtracted = $linkBukti;
                                    }
                                }
                            }

                            // Cek kelengkapan pengisian untuk status baseline
                            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM baseline_elemen WHERE mitra_id = ? AND status != 'BELUM DIISI'");
                            $stmtCount->execute([$targetId]);
                            $filledCount = (int)$stmtCount->fetchColumn();

                            // Update ringkasan status baseline pada mitra_kinerja
                            $updateSqlParts = [];
                            $updateParams = [];

                            if ($filledCount >= 12) {
                                $updateSqlParts[] = "baseline_status = 'TERVERIFIKASI / DIKUNCI'";
                                $updateSqlParts[] = "baseline_locked_at = NOW()";
                                $updateSqlParts[] = "baseline_locked_by = ?";
                                $updateParams[] = $user['id'];
                            } else {
                                $updateSqlParts[] = "baseline_status = 'DALAM PROSES'";
                            }

                            if (!empty($pemeriksaVal) && !str_starts_with($pemeriksaVal, '[')) {
                                $updateSqlParts[] = "baseline_pemeriksa = ?";
                                $updateParams[] = $pemeriksaVal;
                            }
                            if (!empty($cutoffDate)) {
                                $updateSqlParts[] = "cutoff_date = ?";
                                $updateParams[] = $cutoffDate;
                            }
                            if (!empty($fileNaskahExtracted)) {
                                $updateSqlParts[] = "file_naskah = ?";
                                $updateParams[] = $fileNaskahExtracted;
                            }

                            if (!empty($updateSqlParts)) {
                                $updateParams[] = $targetId;
                                $pdo->prepare('UPDATE mitra_kinerja SET ' . implode(', ', $updateSqlParts) . ' WHERE id = ?')->execute($updateParams);
                            }

                            // Deteksi PIC sheet jika ada
                            foreach (array_keys($parsedWb) as $sName) {
                                $upper = strtoupper($sName);
                                if (str_contains($upper, 'IDENTITAS') || str_contains($upper, 'PIC')) {
                                    $picRows = $parsedWb[$sName];
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
                                    break;
                                }
                            }

                            $pdo->commit();
                            logAudit($targetId, $user['id'], 'IMPORT_BASELINE', "Import 12 elemen Baseline FIX mitra {$targetMitra['kode']} dari Excel ({$fileInfo['name']})");
                            $success = "Data Baseline FIX untuk mitra {$targetMitra['kode']} berhasil diimpor ({$updatedBaseline} elemen diperbarui).";
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $errors[] = 'Gagal menyimpan hasil import: ' . $e->getMessage();
                        }
                    }
                }
            }
        }
    }

    // Detail Handlers untuk ID tertentu
    elseif ($id > 0) {
        $mitra = $targetMitra;
        $isLocked = $isTargetLocked;

        if (!$mitra) {
            header('Location: baseline.php');
            exit;
        }

        // 1. Simpan Perubahan Elemen Baseline
        if ($action === 'save_baseline') {
        if (!$canEdit) {
            $errors[] = 'Akses ditolak: Hanya admin dan pemeriksa yang dapat mengubah baseline.';
        } elseif ($isLocked) {
            $errors[] = 'Baseline FIX ini telah dikunci. Buka kunci terlebih dahulu untuk melakukan koreksi.';
        } else {
            $pdo->beginTransaction();
            try {
                // Update kontrol naskah
                $cutoffDate = ($_POST['cutoff_date'] ?? '') ?: null;
                $statusTanggal = $_POST['status_tanggal'] ?? $mitra['status_tanggal'];
                $sumberBaseline = trim($_POST['sumber_baseline'] ?? '');
                $pemeriksa = trim($_POST['baseline_pemeriksa'] ?? '');
                $catatanRingkasan = trim($_POST['baseline_catatan_ringkasan'] ?? '');

                $stmtM = $pdo->prepare('UPDATE mitra_kinerja SET
                    cutoff_date = ?,
                    status_tanggal = ?,
                    sumber_baseline = ?,
                    baseline_pemeriksa = ?,
                    baseline_catatan_ringkasan = ?,
                    baseline_status = \'DALAM PROSES\'
                    WHERE id = ?');
                $stmtM->execute([$cutoffDate, $statusTanggal, $sumberBaseline, $pemeriksa, $catatanRingkasan, $id]);

                // Simpan 12 elemen
                for ($num = 1; $num <= 12; $num++) {
                    $st = $_POST["status_{$num}"] ?? 'BELUM DIISI';
                    $fakta = trim($_POST["fakta_{$num}"] ?? '');
                    $bukti = trim($_POST["bukti_{$num}"] ?? '');

                    $stmtE = $pdo->prepare('INSERT INTO baseline_elemen (
                        mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa, sumber_bukti_minimum,
                        status, fakta_pemeriksaan, link_sumber_bukti
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        status = VALUES(status),
                        fakta_pemeriksaan = VALUES(fakta_pemeriksaan),
                        link_sumber_bukti = VALUES(link_sumber_bukti)');
                    $stmtE->execute([
                        $id, $num,
                        BASELINE_12_DEFS[$num]['kelompok'],
                        BASELINE_12_DEFS[$num]['nama'],
                        BASELINE_12_DEFS[$num]['yang_diperiksa'],
                        BASELINE_12_DEFS[$num]['sumber_minimum'],
                        $st, $fakta, $bukti
                    ]);
                }

                $pdo->commit();
                logAudit($id, $user['id'], 'UPDATE_BASELINE', 'Pembaruan 12 elemen Baseline FIX ' . $mitra['kode']);
                $success = 'Data verifikasi 12 elemen Baseline FIX berhasil disimpan.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Gagal menyimpan: ' . $e->getMessage();
            }
        }
    }

    // 2. Kunci Baseline (Lock / Finalisasi)
    elseif ($action === 'lock_baseline') {
        if (!$canEdit) {
            $errors[] = 'Akses ditolak.';
        } else {
            // Periksa apakah semua 12 elemen sudah terisi (tidak ada yang BELUM DIISI)
            $stmtC = $pdo->prepare('SELECT COUNT(*) FROM baseline_elemen WHERE mitra_id = ? AND status != \'BELUM DIISI\'');
            $stmtC->execute([$id]);
            $filledCount = (int)$stmtC->fetchColumn();

            if ($filledCount < 12) {
                $errors[] = 'Gagal mengunci: Seluruh 12 elemen wajib diisi dan diklasifikasikan statusnya (saat ini baru ' . $filledCount . '/12 terisi).';
            } else {
                $pemeriksa = trim($_POST['baseline_pemeriksa'] ?? ($mitra['baseline_pemeriksa'] ?: $user['nama']));
                $stmtL = $pdo->prepare('UPDATE mitra_kinerja SET
                    baseline_status = \'TERVERIFIKASI / DIKUNCI\',
                    baseline_locked_at = NOW(),
                    baseline_locked_by = ?,
                    baseline_pemeriksa = ?
                    WHERE id = ?');
                $stmtL->execute([$user['id'], $pemeriksa, $id]);
                logAudit($id, $user['id'], 'LOCK_BASELINE', 'Kunci final Baseline FIX ' . $mitra['kode']);
                $success = 'Baseline FIX berhasil DIFINALISASI & DIKUNCI. Kondisi awal resmi menjadi titik pembanding Scorecard.';
            }
        }
    }

    // 3. Buka Kunci (Admin Only)
    elseif ($action === 'unlock_baseline') {
        if (!$isAdmin) {
            $errors[] = 'Hanya administrator yang berhak membuka kunci Baseline FIX.';
        } else {
            $stmtU = $pdo->prepare('UPDATE mitra_kinerja SET
                baseline_status = \'DALAM PROSES\',
                baseline_locked_at = NULL,
                baseline_locked_by = NULL
                WHERE id = ?');
            $stmtU->execute([$id]);
            logAudit($id, $user['id'], 'UNLOCK_BASELINE', 'Membuka kunci Baseline FIX ' . $mitra['kode']);
            $success = 'Kunci Baseline FIX dibuka kembali untuk penyesuaian administratif.';
        }
    }

    // 4. Upload PDF untuk Elemen Baseline (IDENTITAS, SUBSTANSI, TINDAK LANJUT, HAMBATAN)
    elseif ($action === 'upload_baseline_pdf') {
        if (!$canEdit) {
            $errors[] = 'Akses ditolak.';
        } elseif ($isLocked) {
            $errors[] = 'Baseline FIX telah dikunci.';
        } else {
            $elemenNomor = (int)($_POST['elemen_nomor'] ?? 0);
            if (!in_array($elemenNomor, [1, 3, 9, 12], true)) {
                $errors[] = 'Elemen tidak valid untuk upload berkas.';
            } else {
                $targetDir = __DIR__ . '/public/uploads/baseline_pdf/';
                if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

                if ($elemenNomor === 9 && isset($_FILES['pdf_files']) && is_array($_FILES['pdf_files']['name'])) {
                    // Multiple files for Tindak Lanjut
                    $savedPaths = [];
                    $stmtOld = $pdo->prepare('SELECT link_sumber_bukti FROM baseline_elemen WHERE mitra_id = ? AND nomor_elemen = 9');
                    $stmtOld->execute([$id]);
                    $oldLinks = $stmtOld->fetchColumn() ?: '';
                    if (!empty($oldLinks)) {
                        $dec = json_decode($oldLinks, true);
                        if (is_array($dec)) $savedPaths = $dec;
                        else $savedPaths = array_filter(explode(';', $oldLinks));
                    }

                    $fileCount = count($_FILES['pdf_files']['name']);
                    for ($f = 0; $f < $fileCount; $f++) {
                        if ($_FILES['pdf_files']['error'][$f] === UPLOAD_ERR_OK) {
                            $ext = strtolower(pathinfo($_FILES['pdf_files']['name'][$f], PATHINFO_EXTENSION));
                            if ($ext === 'pdf' && isPdfValid($_FILES['pdf_files']['tmp_name'][$f])) {
                                $safeLeaf = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', pathinfo($_FILES['pdf_files']['name'][$f], PATHINFO_FILENAME));
                                $targetName = 'baseline_e9_' . $mitra['kode'] . '_' . time() . '_' . $f . '_' . $safeLeaf . '.pdf';
                                if (move_uploaded_file($_FILES['pdf_files']['tmp_name'][$f], $targetDir . $targetName)) {
                                    $savedPaths[] = 'public/uploads/baseline_pdf/' . $targetName;
                                }
                            }
                        }
                    }

                    if (!empty($savedPaths)) {
                        $jsonVal = json_encode(array_values(array_unique($savedPaths)), JSON_UNESCAPED_UNICODE);
                        $stmtU = $pdo->prepare('UPDATE baseline_elemen SET link_sumber_bukti = ?, status = \'TERVERIFIKASI\' WHERE mitra_id = ? AND nomor_elemen = 9');
                        $stmtU->execute([$jsonVal, $id]);
                        logAudit($id, $user['id'], 'UPLOAD_BASELINE_PDF', 'Upload multiple dokumen tindak lanjut ' . $mitra['kode']);
                        $success = 'Berkas PDF Rencana Tindak Lanjut berhasil diunggah.';
                    } else {
                        $errors[] = 'Gagal mengunggah berkas. Pastikan format file adalah .PDF.';
                    }
                } else {
                    // Single file for 1, 3, 12
                    if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
                        $errors[] = 'Pilih file PDF yang valid.';
                    } else {
                        $ext = strtolower(pathinfo($_FILES['pdf_file']['name'], PATHINFO_EXTENSION));
                        if ($ext !== 'pdf' || !isPdfValid($_FILES['pdf_file']['tmp_name'])) {
                            $errors[] = 'Format file wajib berupa dokumen .PDF asli bertanda tangan.';
                        } else {
                            $targetName = 'baseline_e' . $elemenNomor . '_' . $mitra['kode'] . '_' . time() . '.pdf';
                            if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $targetDir . $targetName)) {
                                $filePath = 'public/uploads/baseline_pdf/' . $targetName;
                                $stmtU = $pdo->prepare('UPDATE baseline_elemen SET link_sumber_bukti = ?, status = \'TERVERIFIKASI\' WHERE mitra_id = ? AND nomor_elemen = ?');
                                $stmtU->execute([$filePath, $id, $elemenNomor]);

                                if ($elemenNomor === 1) {
                                    $stmtM = $pdo->prepare('UPDATE mitra_kinerja SET file_naskah = ? WHERE id = ?');
                                    $stmtM->execute([$filePath, $id]);
                                }

                                logAudit($id, $user['id'], 'UPLOAD_BASELINE_PDF', 'Upload PDF elemen ' . $elemenNomor . ' ' . $mitra['kode']);
                                $success = 'Berkas PDF untuk elemen ' . BASELINE_12_DEFS[$elemenNomor]['nama'] . ' berhasil diunggah.';
                            } else {
                                $errors[] = 'Gagal menyimpan file di server.';
                            }
                        }
                    }
                }
            }
        }
    }

    // 5. Update PIC Data (Internal & Mitra)
    elseif ($action === 'update_pic') {
        if (!$canEdit) {
            $errors[] = 'Akses ditolak.';
        } elseif ($isLocked) {
            $errors[] = 'Baseline FIX telah dikunci.';
        } else {
            $picType = $_POST['pic_type'] ?? '';
            $namaPic = trim($_POST['nama_pic'] ?? '');
            $jabatanPic = trim($_POST['jabatan_pic'] ?? '');
            $unitPic = trim($_POST['unit_pic'] ?? '');
            $kontakPic = trim($_POST['kontak_pic'] ?? '');
            $skPic = trim($_POST['sk_pic'] ?? '');

            if ($namaPic === '') {
                $errors[] = 'Nama PIC wajib diisi.';
            } else {
                $summaryPic = $namaPic . ($jabatanPic ? " ({$jabatanPic})" : '') . ($kontakPic ? " - HP/WA: {$kontakPic}" : '');
                if ($picType === 'internal') {
                    $detailFakta = "PIC Internal: {$namaPic}\nJabatan: " . ($jabatanPic ?: '-') . "\nUnit: " . ($unitPic ?: '-') . "\nKontak: " . ($kontakPic ?: '-') . "\nDasar Penetapan/SK: " . ($skPic ?: '-');
                    $stmtM = $pdo->prepare('UPDATE mitra_kinerja SET pic_internal = ? WHERE id = ?');
                    $stmtM->execute([$summaryPic, $id]);
                    $stmtE = $pdo->prepare('UPDATE baseline_elemen SET fakta_pemeriksaan = ?, status = \'TERVERIFIKASI\' WHERE mitra_id = ? AND nomor_elemen = 7');
                    $stmtE->execute([$detailFakta, $id]);
                    logAudit($id, $user['id'], 'UPDATE_PIC_INTERNAL', 'Update PIC Internal ' . $mitra['kode']);
                    $success = 'Data PIC Internal berhasil diperbarui dan diverifikasi.';
                } elseif ($picType === 'mitra') {
                    $detailFakta = "PIC Mitra: {$namaPic}\nJabatan: " . ($jabatanPic ?: '-') . "\nInstansi: " . ($unitPic ?: $mitra['nama_mitra']) . "\nKontak: " . ($kontakPic ?: '-') . "\nKeterangan: " . ($skPic ?: '-');
                    $stmtM = $pdo->prepare('UPDATE mitra_kinerja SET pic_mitra = ? WHERE id = ?');
                    $stmtM->execute([$summaryPic, $id]);
                    $stmtE = $pdo->prepare('UPDATE baseline_elemen SET fakta_pemeriksaan = ?, status = \'TERVERIFIKASI\' WHERE mitra_id = ? AND nomor_elemen = 8');
                    $stmtE->execute([$detailFakta, $id]);
                    logAudit($id, $user['id'], 'UPDATE_PIC_MITRA', 'Update PIC Mitra ' . $mitra['kode']);
                    $success = 'Data PIC Mitra berhasil diperbarui dan diverifikasi.';
                }
                // Refresh data mitra
                $stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
                $stmt->execute([$id]);
                $mitra = $stmt->fetch();
            }
        }
    }
}
}

/* ── VIEW ROUTER ─────────────────────────────────────────── */

// ═════════════════════════════════════════════════════════════
// DETAIL / PRINT FORM RESMI (Ketik ?id=X)
// ═════════════════════════════════════════════════════════════
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
    $stmt->execute([$id]);
    $mitra = $stmt->fetch();

    if (!$mitra) {
        header('Location: baseline.php');
        exit;
    }

    // Ambil 12 elemen tersimpan
    $stmtE = $pdo->prepare('SELECT * FROM baseline_elemen WHERE mitra_id = ? ORDER BY nomor_elemen ASC');
    $stmtE->execute([$id]);
    $rowsRaw = $stmtE->fetchAll();

    $elemenData = [];
    foreach ($rowsRaw as $r) {
        $elemenData[(int)$r['nomor_elemen']] = $r;
    }

    // Self-healing: pastikan ke-12 elemen ada di database
    for ($i = 1; $i <= 12; $i++) {
        if (!isset($elemenData[$i])) {
            $def = BASELINE_12_DEFS[$i];
            $pdo->prepare('INSERT INTO baseline_elemen (mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa, sumber_bukti_minimum, status) VALUES (?, ?, ?, ?, ?, ?, \'BELUM DIISI\') ON DUPLICATE KEY UPDATE id=id')
                ->execute([$id, $i, $def['kelompok'], $def['nama'], $def['yang_diperiksa'], $def['sumber_minimum']]);
            $elemenData[$i] = [
                'mitra_id' => $id,
                'nomor_elemen' => $i,
                'kelompok' => $def['kelompok'],
                'nama_elemen' => $def['nama'],
                'status' => 'BELUM DIISI',
                'fakta_pemeriksaan' => '',
                'link_sumber_bukti' => ''
            ];
        }
    }

    // Hitung status kelengkapan
    $countVerified = 0;
    $countFilled = 0;
    for ($i = 1; $i <= 12; $i++) {
        $st = $elemenData[$i]['status'] ?? 'BELUM DIISI';
        if ($st === 'TERVERIFIKASI') $countVerified++;
        if ($st !== 'BELUM DIISI') $countFilled++;
    }

    $isLocked = ($mitra['baseline_status'] === 'TERVERIFIKASI / DIKUNCI');
    $pageTitle = 'Baseline FIX — ' . $mitra['kode'];

    if ($view === 'print') {
        // Standalone Printable View
        ?><!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title><?= h($pageTitle) ?></title>
            <link rel="stylesheet" href="public/css/style.css">
            <style>
                body { background: #fff; color: #000; font-family: 'Calibri', 'Segoe UI', sans-serif; line-height: 1.35; padding: 20px; font-size: 12px; }
                .print-container { max-width: 950px; margin: 0 auto; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 11.5px; }
                th, td { border: 1px solid #333; padding: 5px 8px; vertical-align: top; }
                th { background: #f1f5f9; text-align: left; font-weight: 700; }
                .kop-surat { display: flex; align-items: center; border-bottom: 3px double #000; padding-bottom: 10px; margin-bottom: 16px; }
                .kop-surat img { height: 70px; margin-right: 16px; }
                .kop-text { text-align: center; flex: 1; }
                .kop-text h2 { margin: 0; font-size: 15px; font-weight: 800; letter-spacing: 0.5px; }
                .kop-text h1 { margin: 2px 0; font-size: 17px; font-weight: 900; letter-spacing: 1px; }
                .badge { padding: 2px 5px; font-weight: 700; border-radius: 4px; font-size: 10.5px; display: inline-block; }
                .badge-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
                .badge-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
                .badge-secondary { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
                .sig-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 30px; text-align: center; font-size: 11.5px; }
                @media print {
                    .no-print { display: none !important; }
                    body { padding: 0; }
                }
            </style>
        </head>
        <body>
        <div class="print-container">
            <div class="no-print" style="margin-bottom:14px;display:flex;justify-content:space-between;">
                <a href="baseline.php?id=<?= $id ?>" class="btn btn-outline btn-sm">&larr; Kembali ke Form</a>
                <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Cetak / Simpan PDF</button>
            </div>
            
            <div class="kop-surat">
                <img src="public/img/logo-hukum.png" alt="Logo">
                <div class="kop-text">
                    <div style="font-size:14px;font-weight:700;">KEMENTERIAN HUKUM REPUBLIK INDONESIA</div>
                    <div style="font-size:16px;font-weight:900;">KANTOR WILAYAH KEPULAUAN RIAU</div>
                    <div style="font-size:11px;color:#333;">Jalan Daeng Celak, Senggarang, Tanjungpinang, Kepulauan Riau</div>
                    <div style="font-size:13px;font-weight:800;margin-top:4px;text-decoration:underline;">FORMULIR BASELINE FIX: VERIFIKASI &amp; PENGUNCIAN KONDISI AWAL (12 ELEMEN)</div>
                </div>
            </div>

            <table style="margin-bottom:14px;">
                <tr><th style="width:25%;">Kode &amp; Mitra</th><td><strong><?= h($mitra['kode']) ?></strong> &mdash; <?= h($mitra['nama_mitra']) ?></td><th style="width:20%;">Portofolio</th><td><?= h($mitra['portofolio']) ?></td></tr>
                <tr><th>Judul Kerja Sama</th><td colspan="3"><?= h($mitra['judul']) ?></td></tr>
                <tr><th>Bidang &amp; Bentuk Naskah</th><td><strong><?= h($mitra['bidang'] ?? 'AHU') ?></strong> &mdash; <?= h($mitra['jenis']) ?> (<?= formatTanggal($mitra['tanggal_mulai']) ?> s.d. <?= formatTanggal($mitra['tanggal_berakhir']) ?>)</td><th>Status Tanggal</th><td><?= h($mitra['status_tanggal']) ?></td></tr>
                <tr><th>Tanggal Cut-off Baseline</th><td><strong><?= formatTanggal($mitra['cutoff_date']) ?></strong></td><th>Status Kunci</th><td><strong><?= h($mitra['baseline_status']) ?></strong> <?= $mitra['baseline_locked_at'] ? '(' . formatTanggal($mitra['baseline_locked_at']) . ')' : '' ?></td></tr>
                <tr><th>Pemeriksa Baseline</th><td><?= h($mitra['baseline_pemeriksa'] ?? 'Tim Penilai') ?></td><th>Sumber Rujukan</th><td><?= h($mitra['sumber_baseline'] ?? 'P2MA Kementerian Hukum') ?></td></tr>
            </table>

            <table>
                <thead>
                    <tr>
                        <th style="width:4%;text-align:center;">No</th>
                        <th style="width:14%;">Kelompok</th>
                        <th style="width:20%;">Elemen &amp; Standar Bukti</th>
                        <th style="width:14%;text-align:center;">Status Baseline</th>
                        <th style="width:28%;">Fakta / Hasil Pemeriksaan</th>
                        <th style="width:20%;">Sumber / Bukti</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($num = 1; $num <= 12; $num++):
                        $def = BASELINE_12_DEFS[$num];
                        $el = $elemenData[$num] ?? [];
                        $st = $el['status'] ?? 'BELUM DIISI';
                        $bClass = match($st) {
                            'TERVERIFIKASI' => 'success',
                            'BELUM TERVERIFIKASI' => 'warning',
                            default => 'secondary'
                        };
                    ?>
                    <tr>
                        <td style="text-align:center;"><?= $num ?></td>
                        <td><strong><?= h($def['kelompok']) ?></strong></td>
                        <td>
                            <strong><?= h($def['nama']) ?></strong>
                            <div style="font-size:10.5px;color:#555;margin-top:2px;"><?= h($def['sumber_minimum']) ?></div>
                        </td>
                        <td style="text-align:center;"><span class="badge badge-<?= $bClass ?>"><?= h($st) ?></span></td>
                        <td><?= nl2br(h($el['fakta_pemeriksaan'] ?? '-')) ?></td>
                        <td style="font-size:10.5px;"><?= formatLinkSumberBukti($el['link_sumber_bukti'] ?? '') ?></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>

            <div class="sig-grid">
                <div>
                    <div>Unit Pengampu / PIC,</div>
                    <div style="height:60px;"></div>
                    <div style="font-weight:700;text-decoration:underline;">Pejabat Pemangku Kegiatan</div>
                    <div class="muted">Kanwil Kementerian Hukum Kepri</div>
                </div>
                <div>
                    <div>Pemeriksa / Verifikator,</div>
                    <div style="height:60px;"></div>
                    <div style="font-weight:700;text-decoration:underline;"><?= h($mitra['baseline_pemeriksa'] ?: 'Bagian Tata Usaha dan Umum') ?></div>
                    <div class="muted">Tim Pengelola Kerja Sama</div>
                </div>
                <div>
                    <div>Mengetahui,</div>
                    <div style="height:60px;"></div>
                    <div style="font-weight:700;text-decoration:underline;">Edison Manik, S.H., M.H.</div>
                    <div class="muted">Kepala Kantor Wilayah</div>
                </div>
            </div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Standard Interactive Form View
    require __DIR__ . '/includes/header.php';
    ?>

    <div class="flex-between" style="margin-bottom:14px;">
        <div>
            <h1 style="margin:0;font-size:20px;"><?= h($mitra['kode']) ?> — Baseline FIX (Kondisi Awal)</h1>
            <div class="muted" style="font-size:13px;"><?= h($mitra['nama_mitra']) ?> &bull; <?= h($mitra['judul']) ?></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <a href="baseline.php" class="btn btn-outline btn-sm">&larr; Daftar Baseline</a>
            <a href="public/templates/template_baseline_12_elemen.xlsx" download="Template_Baseline_<?= h($mitra['kode']) ?>.xlsx" class="btn btn-outline btn-sm" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;color:#4338ca;border-color:#c7d2fe;background:#eef2ff;" title="Unduh Formulir Template Baseline (.xlsx)">
                <span>📥</span> Unduh Template (.xlsx)
            </a>
            <?php if ($canEdit && (!$isLocked || $isAdmin)): ?>
            <button type="button" onclick="openImportModal(<?= $id ?>, <?= htmlspecialchars(json_encode((string)$mitra['kode']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$mitra['nama_mitra']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-sm" style="font-size:11px;display:inline-flex;align-items:center;gap:4px;background:#4f46e5;color:#fff;border:none;border-radius:4px;cursor:pointer;" title="Import Data Baseline">
                <span>📤</span> Import Baseline (.xlsx)
            </button>
            <?php endif; ?>
            <a href="mitra_edit.php?id=<?= $id ?>" class="btn btn-outline btn-sm">Buka Scorecard &rarr;</a>
            <a href="baseline.php?view=print&id=<?= $id ?>" target="_blank" class="btn btn-primary btn-sm">🖨️ Cetak / PDF</a>
        </div>
    </div>

    <?php if ($success): ?><div class="alert alert-info"><?= h($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

    <!-- Lock Status Notification -->
    <?php if ($isLocked): ?>
    <div class="alert alert-info" style="display:flex;align-items:center;justify-content:space-between;background:#f0fdf4;border-left:4px solid #16a34a;color:#166534;margin-bottom:18px;">
        <div>
            <strong>🔒 BASELINE FIX DIKUNCI (FINAL)</strong> &mdash; 
            Kondisi awal telah resmi dikunci pada <strong><?= formatTanggal($mitra['baseline_locked_at']) ?></strong> oleh <strong><?= h($mitra['baseline_pemeriksa'] ?? 'Pemeriksa') ?></strong>.
            <div style="font-size:12px;margin-top:2px;color:#15803d;">
                Perubahan setelah tanggal cut-off wajib dicatat pada kolom Scorecard V2.1 (tidak mengubah baseline awal).
            </div>
        </div>
        <?php if ($isAdmin): ?>
        <form method="post" style="margin:0;" onsubmit="return confirm('Buka kunci Baseline FIX ini untuk melakukan perbaikan administratif?');">
            <input type="hidden" name="action" value="unlock_baseline">
            <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px;background:#fff;">🔓 Buka Kunci (Admin)</button>
        </form>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="alert alert-warning" style="display:flex;align-items:center;justify-content:space-between;background:#fffbeb;border-left:4px solid #ca8a04;color:#854d0e;margin-bottom:18px;">
        <div>
            <strong>⚠️ BASELINE FIX DALAM PROSES VERIFIKASI</strong> &mdash;
            Status saat ini: <strong><?= $countVerified ?> / 12</strong> elemen terverifikasi (<?= $countFilled ?>/12 terisi).
            <div style="font-size:12px;margin-top:2px;">
                Lengkapi 12 elemen faktual kondisi awal, lalu lakukan penguncian baseline sebelum penilaian berkala berjalan.
            </div>
        </div>
        <?php if ($canEdit && $countFilled >= 12): ?>
        <form method="post" style="margin:0;" onsubmit="return confirm('Kunci Baseline FIX ini? Setelah dikunci, data kondisi awal akan menjadi titik pembanding permanen.');">
            <input type="hidden" name="action" value="lock_baseline">
            <button type="submit" class="btn btn-success btn-sm" style="font-size:11px;">🔒 Kunci Baseline (Finalisasi)</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- KPI Summary Row -->
    <div class="kpi-grid" style="margin-bottom:20px;grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));">
        <div class="kpi-card">
            <div class="kpi-value" style="color:#2563eb;"><?= $countVerified ?> / 12</div>
            <div class="kpi-label">Elemen Terverifikasi</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-value" style="color:<?= $countFilled >= 12 ? '#16a34a' : '#ca8a04' ?>;"><?= round(($countFilled/12)*100) ?>%</div>
            <div class="kpi-label">Kelengkapan Pemeriksaan</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-value" style="font-size:15px;"><?= $mitra['cutoff_date'] ? formatTanggal($mitra['cutoff_date']) : '-' ?></div>
            <div class="kpi-label">Tanggal Cut-off</div>
        </div>
        <div class="kpi-card">
            <span class="badge badge-<?= $isLocked ? 'success' : 'warning' ?>" style="font-size:12px;margin-top:6px;display:inline-block;">
                <?= $isLocked ? '🔒 DIKUNCI' : '⏳ DALAM PROSES' ?>
            </span>
            <div class="kpi-label">Status Baseline</div>
        </div>
    </div>

    <!-- Main Verification Form -->
    <form method="post">
        <input type="hidden" name="action" value="save_baseline">

        <!-- Card A: Kontrol Identitas & Cut-off -->
        <div class="card" style="margin-bottom:20px;">
            <h2>Kontrol Tanggal &amp; Pemeriksa Baseline</h2>
            <div class="form-grid">
                <div class="field">
                    <label>Tanggal Cut-off Baseline *</label>
                    <input type="date" name="cutoff_date" value="<?= h($mitra['cutoff_date']) ?>" <?= $isLocked || !$canEdit ? 'disabled' : 'required' ?>>
                </div>
                <div class="field">
                    <label>Status Tanggal Naskah</label>
                    <select name="status_tanggal" <?= $isLocked || !$canEdit ? 'disabled' : '' ?>>
                        <option value="TERVERIFIKASI" <?= $mitra['status_tanggal'] === 'TERVERIFIKASI' ? 'selected' : '' ?>>TERVERIFIKASI</option>
                        <option value="BELUM TERVERIFIKASI" <?= $mitra['status_tanggal'] === 'BELUM TERVERIFIKASI' ? 'selected' : '' ?>>BELUM TERVERIFIKASI</option>
                    </select>
                </div>
                <div class="field">
                    <label>Pemeriksa / Verifikator</label>
                    <input type="text" name="baseline_pemeriksa" value="<?= h($mitra['baseline_pemeriksa'] ?? 'Tim Pengelola Kerja Sama') ?>" placeholder="Nama pejabat / unit pemeriksa" <?= $isLocked || !$canEdit ? 'disabled' : '' ?>>
                </div>
                <div class="field">
                    <label>Sumber Baseline Utama</label>
                    <input type="text" name="sumber_baseline" value="<?= h($mitra['sumber_baseline'] ?? 'P2MA Kementerian Hukum RI') ?>" placeholder="P2MA / Berkas Fisik" <?= $isLocked || !$canEdit ? 'disabled' : '' ?>>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Catatan Ringkasan Kondisi Awal</label>
                    <textarea name="baseline_catatan_ringkasan" rows="2" placeholder="Ringkasan temuan kondisi awal saat cut-off..." <?= $isLocked || !$canEdit ? 'disabled' : '' ?>><?= h($mitra['baseline_catatan_ringkasan'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Card B: 12 Elemen Baseline FIX -->
        <div class="card">
            <div class="flex-between" style="margin-bottom:14px;">
                <div>
                    <h2 style="margin:0;">12 Elemen Baseline FIX</h2>
                    <div class="muted" style="font-size:12px;">Pengecekan faktual 12 elemen kondisi awal sesuai standar Bab IV Pedoman Teknis</div>
                </div>
                <?php if (!$isLocked && $canEdit): ?>
                <button type="submit" class="btn btn-primary btn-sm">💾 Simpan Perubahan</button>
                <?php endif; ?>
            </div>

            <?php
            $stmtTLCount = $pdo->prepare('SELECT COUNT(*) FROM tindak_lanjut WHERE mitra_id = ?');
            $stmtTLCount->execute([$id]);
            $kegiatanCount = (int)$stmtTLCount->fetchColumn();
            ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="width:4%;text-align:center;">No</th>
                            <th style="width:10%;">Kelompok</th>
                            <th style="width:20%;">Elemen &amp; Standar Bukti</th>
                            <th style="width:12%;">Status Baseline</th>
                            <th style="width:20%;">Fakta / Hasil Pemeriksaan</th>
                            <th style="width:16%;">Sumber / Bukti Minimum</th>
                            <th style="width:18%;text-align:center;">Aksi / Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($num = 1; $num <= 12; $num++):
                            $def = BASELINE_12_DEFS[$num];
                            $el = $elemenData[$num] ?? [];
                            $currStatus = $el['status'] ?? 'BELUM DIISI';
                            $statusOptions = ['TERVERIFIKASI', 'BELUM TERVERIFIKASI', 'BELUM TERSEDIA', 'TIDAK RELEVAN', 'BELUM DIISI'];
                        ?>
                        <tr>
                            <td style="text-align:center;font-weight:700;"><?= $num ?></td>
                            <td><span class="badge badge-secondary" style="font-size:10px;"><?= h($def['kelompok']) ?></span></td>
                            <td>
                                <strong><?= h($def['nama']) ?></strong>
                                <div style="font-size:11px;color:#475569;margin-top:2px;"><?= h($def['yang_diperiksa']) ?></div>
                                <div class="muted" style="font-size:10.5px;margin-top:2px;"><em>Rujukan: <?= h($def['sumber_minimum']) ?></em></div>
                            </td>
                            <td>
                                <?php if ($isLocked || !$canEdit): ?>
                                    <span class="badge badge-<?= $currStatus === 'TERVERIFIKASI' ? 'success' : ($currStatus === 'BELUM TERVERIFIKASI' ? 'warning' : 'secondary') ?>">
                                        <?= h($currStatus) ?>
                                    </span>
                                <?php else: ?>
                                    <select name="status_<?= $num ?>" style="font-size:12px;padding:4px 6px;">
                                        <?php foreach ($statusOptions as $opt): ?>
                                        <option value="<?= $opt ?>" <?= $currStatus === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isLocked || !$canEdit): ?>
                                    <div style="font-size:11.5px;color:#1e293b;"><?= nl2br(h($el['fakta_pemeriksaan'] ?? '-')) ?></div>
                                <?php else: ?>
                                    <textarea name="fakta_<?= $num ?>" rows="2" style="width:100%;font-size:11.5px;" placeholder="Tuliskan fakta hasil pemeriksaan..."><?= h($el['fakta_pemeriksaan'] ?? '') ?></textarea>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isLocked || !$canEdit): ?>
                                    <div style="font-size:11px;color:#475569;"><?= formatLinkSumberBukti($el['link_sumber_bukti'] ?? '') ?></div>
                                <?php else: ?>
                                    <textarea name="bukti_<?= $num ?>" rows="2" style="width:100%;font-size:11px;" placeholder="Tautan P2MA / surat / nomor arsip..."><?= h($el['link_sumber_bukti'] ?? '') ?></textarea>
                                <?php endif; ?>
                            </td>
                            <!-- Kolom Aksi / Tindakan Khusus Elemen -->
                            <td style="text-align:center;vertical-align:middle;">
                                <?php if ($num === 1): // IDENTITAS: fitur upload file pdf ?>
                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
                                        <?php 
                                            $pdfPath = $el['link_sumber_bukti'] ?: ($mitra['file_naskah'] ?? '');
                                            if ($pdfPath && (file_exists(__DIR__ . '/' . $pdfPath) || preg_match('/^https?:\/\//i', $pdfPath))): 
                                        ?>
                                            <a href="<?= h($pdfPath) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:3px 7px;">📄 Lihat PDF</a>
                                        <?php endif; ?>
                                        <?php if (!$isLocked && $canEdit): ?>
                                            <button type="button" onclick="openUploadModal(1, 'Identitas Naskah')" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:3px 7px;">📤 Upload PDF</button>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($num === 3): // SUBSTANSI: fitur upload file pdf ?>
                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
                                        <?php 
                                            $subPath = $el['link_sumber_bukti'] ?? '';
                                            if ($subPath && (file_exists(__DIR__ . '/' . $subPath) || preg_match('/^https?:\/\//i', $subPath))): 
                                        ?>
                                            <a href="<?= h($subPath) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:3px 7px;">📄 Lihat PDF</a>
                                        <?php endif; ?>
                                        <?php if (!$isLocked && $canEdit): ?>
                                            <button type="button" onclick="openUploadModal(3, 'Dokumen Ruang Lingkup')" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:3px 7px;">📤 Upload PDF</button>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($num === 7): // PIC internal: fitur update data PIC internal ?>
                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
                                        <?php if (!empty($mitra['pic_internal'])): ?>
                                            <div style="font-size:11px;color:#1e40af;font-weight:600;max-width:140px;"><?= h(singkat($mitra['pic_internal'], 28)) ?></div>
                                        <?php endif; ?>
                                        <?php if (!$isLocked && $canEdit): ?>
                                            <button type="button" onclick="openPicModal('internal')" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:3px 7px;display:inline-flex;align-items:center;gap:3px;">
                                                👤 Update PIC
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($num === 8): // PIC mitra: fitur update data PIC mitra ?>
                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
                                        <?php if (!empty($mitra['pic_mitra'])): ?>
                                            <div style="font-size:11px;color:#1e40af;font-weight:600;max-width:140px;"><?= h(singkat($mitra['pic_mitra'], 28)) ?></div>
                                        <?php endif; ?>
                                        <?php if (!$isLocked && $canEdit): ?>
                                            <button type="button" onclick="openPicModal('mitra')" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:3px 7px;display:inline-flex;align-items:center;gap:3px;">
                                                🤝 Update PIC
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($num === 9): // TINDAK LANJUT: fitur upload multiple pdf ?>
                                    <div style="display:flex;flex-direction:column;gap:3px;align-items:center;">
                                        <?php 
                                            $tlFiles = [];
                                            if (!empty($el['link_sumber_bukti'])) {
                                                $decoded = json_decode($el['link_sumber_bukti'], true);
                                                if (is_array($decoded)) {
                                                    $tlFiles = $decoded;
                                                } elseif (str_contains($el['link_sumber_bukti'], ';')) {
                                                    $tlFiles = explode(';', $el['link_sumber_bukti']);
                                                } elseif (str_ends_with(strtolower($el['link_sumber_bukti']), '.pdf')) {
                                                    $tlFiles = [$el['link_sumber_bukti']];
                                                }
                                            }
                                            foreach ($tlFiles as $idxF => $fPath):
                                                $fName = basename(trim($fPath));
                                        ?>
                                            <a href="<?= h(trim($fPath)) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:10px;padding:2px 5px;margin-bottom:2px;">
                                                📄 <?= h(singkat($fName, 16)) ?>
                                            </a>
                                        <?php endforeach; ?>
                                        <?php if (!$isLocked && $canEdit): ?>
                                            <button type="button" onclick="openUploadMultipleModal(9, 'Dokumen Rencana Tindak Lanjut')" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:3px 7px;">
                                                📤 Upload PDF (Multi)
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($num === 10): // PELAKSANAAN: terbaca "(angka) Kegiatan" dari menu Tindak Lanjut ?>
                                    <div>
                                        <span class="badge badge-<?= $kegiatanCount > 0 ? 'success' : 'secondary' ?>" style="font-size:11.5px;font-weight:700;padding:4px 8px;">
                                            <?= $kegiatanCount ?> Kegiatan
                                        </span>
                                        <div class="muted" style="font-size:10px;margin-top:2px;">Dari Tindak Lanjut</div>
                                    </div>

                                <?php elseif ($num === 11): // EVIDEN: jika terdapat kegiatan, tampilkan tombol ke menu Tindak Lanjut ?>
                                    <div>
                                        <?php if ($kegiatanCount > 0): ?>
                                            <a href="tindak_lanjut.php?mitra_id=<?= $id ?>" target="_blank" class="btn btn-primary btn-sm" style="font-size:10.5px;display:inline-flex;align-items:center;gap:3px;padding:3px 6px;">
                                                📂 Buka Tindak Lanjut &rarr;
                                            </a>
                                        <?php else: ?>
                                            <a href="tindak_lanjut.php" target="_blank" class="btn btn-outline btn-sm" style="font-size:10px;color:#64748b;padding:2px 5px;">
                                                + Tambah Kegiatan
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($num === 12): // HAMBATAN: fitur upload file pdf ?>
                                    <div style="display:flex;flex-direction:column;gap:4px;align-items:center;">
                                        <?php if (!empty($el['link_sumber_bukti']) && file_exists(__DIR__ . '/' . $el['link_sumber_bukti'])): ?>
                                            <a href="<?= h($el['link_sumber_bukti']) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:3px 7px;">📄 Lihat PDF</a>
                                        <?php endif; ?>
                                        <?php if (!$isLocked && $canEdit): ?>
                                            <button type="button" onclick="openUploadModal(12, 'Dokumen Kendala / Gap')" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:3px 7px;">📤 Upload PDF</button>
                                        <?php endif; ?>
                                    </div>

                                <?php else: ?>
                                    <span class="muted" style="font-size:11px;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$isLocked && $canEdit): ?>
            <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;">
                <button type="submit" class="btn btn-primary">💾 Simpan Perubahan Baseline</button>
                <span class="muted" style="font-size:12px;">Pastikan seluruh 12 elemen terisi sebelum mengunci baseline.</span>
            </div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Modal Upload Single PDF (Elemen 1, 3, 12) -->
    <div id="modalUploadSingle" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;overflow:auto;">
        <div style="background:#fff;max-width:480px;margin:80px auto;padding:20px;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.15);">
            <div class="flex-between" style="margin-bottom:12px;">
                <h3 id="modalSingleTitle" style="margin:0;font-size:16px;color:#1e40af;">Upload Berkas PDF</h3>
                <button type="button" onclick="document.getElementById('modalUploadSingle').style.display='none'" style="background:none;border:none;font-size:18px;cursor:pointer;">&times;</button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_baseline_pdf">
                <input type="hidden" id="modalSingleElemen" name="elemen_nomor" value="1">
                <div class="field" style="margin-bottom:16px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;">Pilih File Dokumen (.PDF) *</label>
                    <input type="file" name="pdf_file" accept=".pdf" required style="width:100%;font-size:12.5px;">
                    <div class="muted" style="font-size:11px;margin-top:4px;">Wajib format .PDF resmi bertanda tangan.</div>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" onclick="document.getElementById('modalUploadSingle').style.display='none'" class="btn btn-outline btn-sm">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">📤 Unggah PDF</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Upload Multiple PDF (Elemen 9: Tindak Lanjut) -->
    <div id="modalUploadMulti" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;overflow:auto;">
        <div style="background:#fff;max-width:520px;margin:80px auto;padding:20px;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.15);">
            <div class="flex-between" style="margin-bottom:12px;">
                <h3 style="margin:0;font-size:16px;color:#1e40af;">Upload Dokumen Tindak Lanjut (Bisa Multiple PDF)</h3>
                <button type="button" onclick="document.getElementById('modalUploadMulti').style.display='none'" style="background:none;border:none;font-size:18px;cursor:pointer;">&times;</button>
            </div>
            <p style="font-size:12px;color:#475569;margin-top:0;">
                Anda dapat memilih satu atau beberapa file PDF sekaligus. Sistem mendukung dokumen berukuran besar hingga 50 MB tanpa perlu menggabungkan secara manual.
            </p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_baseline_pdf">
                <input type="hidden" name="elemen_nomor" value="9">
                <div class="field" style="margin-bottom:16px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:6px;">Pilih File PDF (Multiple) *</label>
                    <input type="file" name="pdf_files[]" multiple accept=".pdf" required style="width:100%;font-size:12.5px;">
                    <div class="muted" style="font-size:11px;margin-top:4px;">Gunakan tombol Ctrl atau Shift untuk memilih lebih dari 1 file PDF.</div>
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" onclick="document.getElementById('modalUploadMulti').style.display='none'" class="btn btn-outline btn-sm">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">📤 Unggah Dokumen</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Update PIC (Elemen 7: Internal & Elemen 8: Mitra) -->
    <div id="modalUpdatePic" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:999;overflow:auto;">
        <div style="background:#fff;max-width:520px;margin:60px auto;padding:22px;border-radius:8px;box-shadow:0 10px 25px rgba(0,0,0,0.15);">
            <div class="flex-between" style="margin-bottom:14px;">
                <h3 id="modalPicTitle" style="margin:0;font-size:16px;color:#1e40af;">Update Data PIC</h3>
                <button type="button" onclick="document.getElementById('modalUpdatePic').style.display='none'" style="background:none;border:none;font-size:18px;cursor:pointer;">&times;</button>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="update_pic">
                <input type="hidden" id="modalPicType" name="pic_type" value="internal">
                <div class="field" style="margin-bottom:12px;">
                    <label id="lblNamaPic" style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Nama Lengkap PIC *</label>
                    <input type="text" id="picNama" name="nama_pic" required style="width:100%;padding:6px 8px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:4px;">
                </div>
                <div class="field" style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Jabatan</label>
                    <input type="text" id="picJabatan" name="jabatan_pic" style="width:100%;padding:6px 8px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:4px;">
                </div>
                <div class="field" style="margin-bottom:12px;">
                    <label id="lblUnitPic" style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Unit Kerja / Instansi</label>
                    <input type="text" id="picUnit" name="unit_pic" style="width:100%;padding:6px 8px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:4px;">
                </div>
                <div class="field" style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Nomor Kontak / WhatsApp</label>
                    <input type="text" id="picKontak" name="kontak_pic" placeholder="08xxxxxxxxxx" style="width:100%;padding:6px 8px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:4px;">
                </div>
                <div class="field" style="margin-bottom:16px;">
                    <label id="lblSkPic" style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Dasar Penetapan (SK / ND / Surat Resmi)</label>
                    <input type="text" id="picSk" name="sk_pic" placeholder="Nomor SK / Dasar Penunjukan" style="width:100%;padding:6px 8px;font-size:12.5px;border:1px solid #cbd5e1;border-radius:4px;">
                </div>
                <div style="display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" onclick="document.getElementById('modalUpdatePic').style.display='none'" class="btn btn-outline btn-sm">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm">💾 Simpan Data PIC</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Import Baseline (.xlsx) Detail View -->
    <div id="modalImportBaseline" class="modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px;">
        <div style="background:#ffffff;border-radius:12px;max-width:540px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2), 0 10px 10px -5px rgba(0,0,0,0.1);overflow:hidden;margin:auto;">
            <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
                <div style="font-size:16px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                    <span>📋</span> Import Data Baseline FIX (12 Elemen)
                </div>
                <button type="button" onclick="closeImportModal()" style="background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
            </div>

            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_baseline">
                <input type="hidden" name="mitra_id" id="modalImportMitraId" value="<?= $id ?>">

                <div style="padding:20px;">
                    <div style="margin-bottom:16px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
                        <div style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:0.5px;">Target Naskah Kerja Sama:</div>
                        <div id="modalImportMitraKodeNama" style="font-size:14px;font-weight:700;color:#1e3a8a;margin-top:2px;">[<?= h($mitra['kode']) ?>] <?= h($mitra['nama_mitra']) ?></div>
                    </div>

                    <div style="margin-bottom:18px;">
                        <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">
                            Pilih Berkas Spreadsheet Excel (.xlsx) <span style="color:#ef4444;">*</span>
                        </label>
                        <input type="file" name="excel_file" accept=".xlsx" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#f8fafc;">
                        <div style="font-size:11.5px;color:#64748b;margin-top:4px;">
                            Format didukung: <strong>.xlsx</strong> (Maks. 25 MB). Gunakan template resmi <code>template_baseline_12_elemen.xlsx</code>.
                        </div>
                    </div>

                    <div style="font-size:12.5px;color:#475569;background:#f1f5f9;padding:12px 14px;border-radius:6px;line-height:1.5;">
                        <div style="font-weight:600;margin-bottom:4px;color:#1e293b;">Data yang akan otomatis diperbarui:</div>
                        &bull; <strong>12 Elemen Baseline:</strong> Status pemeriksaan, fakta audit, dan tautan bukti.<br>
                        &bull; <strong>Kontrol Naskah:</strong> Tanggal cut-off dan nama pemeriksa jika terisi di file.<br>
                        &bull; <strong>Tautan Naskah Resmi:</strong> Tautan naskah P2MA resmi pada Elemen 1 otomatis terhubung.
                    </div>
                </div>

                <div style="padding:14px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" onclick="closeImportModal()" class="btn btn-outline" style="font-size:12.5px;padding:7px 14px;">
                        Batal
                    </button>
                    <button type="submit" class="btn" style="background:#4f46e5;color:#ffffff;font-size:12.5px;padding:7px 18px;font-weight:600;border:none;border-radius:4px;cursor:pointer;">
                        📥 Mulai Proses Import
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openUploadModal(elemenNo, label) {
        document.getElementById('modalSingleElemen').value = elemenNo;
        document.getElementById('modalSingleTitle').innerText = 'Upload ' + label + ' (.PDF)';
        document.getElementById('modalUploadSingle').style.display = 'block';
    }

    function openUploadMultipleModal(elemenNo, label) {
        document.getElementById('modalUploadMulti').style.display = 'block';
    }

    function openPicModal(type) {
        document.getElementById('modalPicType').value = type;
        if (type === 'internal') {
            document.getElementById('modalPicTitle').innerText = 'Update Data PIC Internal (Kanwil Kepri)';
            document.getElementById('lblNamaPic').innerText = 'Nama PIC Internal *';
            document.getElementById('lblUnitPic').innerText = 'Divisi / Subbagian Internal';
            document.getElementById('lblSkPic').innerText = 'Dasar Penunjukan (Nomor SK / Nota Dinas)';
            document.getElementById('picNama').value = '<?= addslashes($mitra['pic_internal'] ?? '') ?>';
        } else {
            document.getElementById('modalPicTitle').innerText = 'Update Data PIC Mitra Kerja Sama';
            document.getElementById('lblNamaPic').innerText = 'Nama PIC Mitra *';
            document.getElementById('lblUnitPic').innerText = 'Instansi / Lembaga Mitra';
            document.getElementById('lblSkPic').innerText = 'Surat Tugas / Konfirmasi Resmi Mitra';
            document.getElementById('picNama').value = '<?= addslashes($mitra['pic_mitra'] ?? '') ?>';
        }
        document.getElementById('modalUpdatePic').style.display = 'block';
    }

    function openImportModal(id, kode, nama) {
        var idEl = document.getElementById('modalImportMitraId');
        if (idEl) idEl.value = id;
        var nameEl = document.getElementById('modalImportMitraKodeNama');
        if (nameEl) nameEl.textContent = '[' + kode + '] ' + nama;
        var modal = document.getElementById('modalImportBaseline');
        if (modal) modal.style.display = 'flex';
    }

    function closeImportModal() {
        var modal = document.getElementById('modalImportBaseline');
        if (modal) modal.style.display = 'none';
    }

    window.addEventListener('click', function(e) {
        var modal = document.getElementById('modalImportBaseline');
        if (e.target === modal) {
            closeImportModal();
        }
    });
    </script>

    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ═════════════════════════════════════════════════════════════
// OVERVIEW / LEDGER VIEW (Default tanpa ?id=)
// ═════════════════════════════════════════════════════════════
$all = getAllMitraSummary($pdo);

// Statistik Keseluruhan
$totalCount = count($all);
$lockedCount = 0;
$inProgressCount = 0;
$totalVerifiedElements = 0;

foreach ($all as $s) {
    $b = $s['baseline'];
    if ($b['is_locked']) {
        $lockedCount++;
    } elseif ($b['terisi'] > 0) {
        $inProgressCount++;
    }
    $totalVerifiedElements += $b['terverifikasi'];
}
$avgVerified = $totalCount > 0 ? round($totalVerifiedElements / $totalCount, 1) : 0;

$pageTitle = 'Baseline Kerja Sama';
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Baseline Kerja Sama</h1>
        <div class="muted" style="font-size:13px;">Verifikasi dan penguncian kondisi awal 12 elemen (Baseline FIX)</div>
    </div>
    <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
</div>

<?php if ($success): ?><div class="alert alert-info" style="margin-bottom:16px;"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning" style="margin-bottom:16px;"><?= h($e) ?></div><?php endforeach; ?>

<!-- KPI Cards -->
<div class="kpi-grid" style="margin-bottom:20px;grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));">
    <div class="kpi-card">
        <div class="kpi-value"><?= $totalCount ?></div>
        <div class="kpi-label">Total Naskah</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color:#16a34a;"><?= $lockedCount ?></div>
        <div class="kpi-label">Baseline Dikunci (Final)</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color:#ca8a04;"><?= $inProgressCount ?></div>
        <div class="kpi-label">Dalam Verifikasi</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color:#2563eb;"><?= $avgVerified ?> / 12</div>
        <div class="kpi-label">Rata-rata Terverifikasi</div>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Mitra &amp; Judul</th>
                <th>Jenis</th>
                <th>Masa Berlaku</th>
                <th>Cut-off</th>
                <th style="min-width:210px;text-align:center;">📋 DATA BASELINE (12 ELEMEN)</th>
                <th>Kelengkapan 12 Elemen</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($all as $s): 
            $m = $s['mitra'];
            $b = $s['baseline'];
            $bBadge = match($b['status']) {
                'TERVERIFIKASI / DIKUNCI' => 'success',
                'DALAM PROSES' => 'warning',
                default => 'secondary'
            };
        ?>
            <tr>
                <td>
                    <strong><?= h($m['kode']) ?></strong><br>
                    <span class="muted" style="font-size:10px;"><?= h($m['portofolio']) ?></span>
                </td>
                <td>
                    <strong><?= h($m['nama_mitra']) ?></strong>
                    <div style="font-size:12px;color:#334155;margin-top:2px;"><?= h(singkat($m['judul'] ?? '-', 50)) ?></div>
                </td>
                <td><span class="badge badge-primary" style="font-size:10px;"><?= h($m['jenis']) ?></span></td>
                <td><?= formatTanggal($m['tanggal_mulai']) ?> s.d.<br><?= formatTanggal($m['tanggal_berakhir']) ?></td>
                <td><?= $m['cutoff_date'] ? formatTanggal($m['cutoff_date']) : '-' ?></td>
                <td style="text-align:center;vertical-align:middle;">
                    <div style="margin-bottom:6px;">
                        <span class="badge badge-<?= $bBadge ?>" style="font-size:10.5px;">
                            <?= $b['is_locked'] ? '🔒 Dikunci' : h($b['status']) ?>
                        </span>
                    </div>
                    <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                        <a href="public/templates/template_baseline_12_elemen.xlsx" download="Template_Baseline_<?= h($m['kode']) ?>.xlsx" class="btn btn-outline btn-sm" style="font-size:10.5px;padding:3px 8px;display:inline-flex;align-items:center;gap:3px;color:#4338ca;border-color:#c7d2fe;background:#eef2ff;" title="Unduh Formulir Template Baseline (.xlsx)">
                            <span>📥</span> Unduh Template
                        </a>
                        <?php if ($canEdit && (!$b['is_locked'] || $isAdmin)): ?>
                        <button type="button" class="btn btn-sm" onclick="openImportModal(<?= (int)$m['id'] ?>, <?= htmlspecialchars(json_encode((string)$m['kode']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$m['nama_mitra']), ENT_QUOTES, 'UTF-8') ?>)" style="font-size:10.5px;padding:3px 8px;display:inline-flex;align-items:center;gap:3px;background:#4f46e5;color:#fff;border:none;border-radius:4px;cursor:pointer;" title="Import Data Baseline">
                            <span>📤</span> Import
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-sm" disabled style="font-size:10.5px;padding:3px 8px;color:#94a3b8;background:#f8fafc;border:1px solid #e2e8f0;cursor:not-allowed;" title="<?= $b['is_locked'] ? 'Baseline telah dikunci' : 'Akses terbatas' ?>">
                            <span>🔒</span> Terkunci
                        </button>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <div style="font-size:11.5px;font-weight:600;margin-bottom:2px;">
                        <?= $b['terverifikasi'] ?> / 12 Terverifikasi
                    </div>
                    <div class="hbar-track" style="width:90px;height:6px;display:inline-block;">
                        <div class="hbar-fill" style="width:<?= $b['persentase'] ?>%;background:<?= $b['is_locked'] ? '#16a34a' : '#ca8a04' ?>;"></div>
                    </div>
                </td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <a href="baseline.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 7px;">🔍 Periksa</a>
                        <a href="baseline.php?view=print&id=<?= $m['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 7px;">🖨️ PDF</a>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Modal Import Baseline (.xlsx) Overview View -->
<div id="modalImportBaseline" class="modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#ffffff;border-radius:12px;max-width:540px;width:100%;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2), 0 10px 10px -5px rgba(0,0,0,0.1);overflow:hidden;margin:auto;">
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#f8fafc;">
            <div style="font-size:16px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                <span>📋</span> Import Data Baseline FIX (12 Elemen)
            </div>
            <button type="button" onclick="closeImportModal()" style="background:none;border:none;font-size:20px;color:#94a3b8;cursor:pointer;line-height:1;">&times;</button>
        </div>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_baseline">
            <input type="hidden" name="mitra_id" id="modalImportMitraId" value="0">

            <div style="padding:20px;">
                <div style="margin-bottom:16px;padding:12px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;">
                    <div style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:0.5px;">Target Naskah Kerja Sama:</div>
                    <div id="modalImportMitraKodeNama" style="font-size:14px;font-weight:700;color:#1e3a8a;margin-top:2px;">-</div>
                </div>

                <div style="margin-bottom:18px;">
                    <label style="display:block;font-size:13px;font-weight:600;color:#334155;margin-bottom:6px;">
                        Pilih Berkas Spreadsheet Excel (.xlsx) <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="file" name="excel_file" accept=".xlsx" required style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#f8fafc;">
                    <div style="font-size:11.5px;color:#64748b;margin-top:4px;">
                        Format didukung: <strong>.xlsx</strong> (Maks. 25 MB). Gunakan template resmi <code>template_baseline_12_elemen.xlsx</code>.
                    </div>
                </div>

                <div style="font-size:12.5px;color:#475569;background:#f1f5f9;padding:12px 14px;border-radius:6px;line-height:1.5;">
                    <div style="font-weight:600;margin-bottom:4px;color:#1e293b;">Data yang akan otomatis diperbarui:</div>
                    &bull; <strong>12 Elemen Baseline:</strong> Status pemeriksaan, fakta audit, dan tautan bukti.<br>
                    &bull; <strong>Kontrol Naskah:</strong> Tanggal cut-off dan nama pemeriksa jika terisi di file.<br>
                    &bull; <strong>Tautan Naskah Resmi:</strong> Tautan naskah P2MA resmi pada Elemen 1 otomatis terhubung.
                </div>
            </div>

            <div style="padding:14px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" onclick="closeImportModal()" class="btn btn-outline" style="font-size:12.5px;padding:7px 14px;">
                    Batal
                </button>
                <button type="submit" class="btn" style="background:#4f46e5;color:#ffffff;font-size:12.5px;padding:7px 18px;font-weight:600;border:none;border-radius:4px;cursor:pointer;">
                    📥 Mulai Proses Import
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openImportModal(id, kode, nama) {
    var idEl = document.getElementById('modalImportMitraId');
    if (idEl) idEl.value = id;
    var nameEl = document.getElementById('modalImportMitraKodeNama');
    if (nameEl) nameEl.textContent = '[' + kode + '] ' + nama;
    var modal = document.getElementById('modalImportBaseline');
    if (modal) modal.style.display = 'flex';
}

function closeImportModal() {
    var modal = document.getElementById('modalImportBaseline');
    if (modal) modal.style.display = 'none';
}

window.addEventListener('click', function(e) {
    var modal = document.getElementById('modalImportBaseline');
    if (e.target === modal) {
        closeImportModal();
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
