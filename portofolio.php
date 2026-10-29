<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$userRole = $user['role'] ?? 'pemeriksa';

$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);
$errors = [];
$success = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'created') {
    $success = 'Naskah kerja sama baru berhasil didaftarkan. Rencana kerja tahunan masih dalam keadaan kosong dan dapat ditambahkan di bawah.';
}

$bidangOptions = [
    'AHU'      => 'AHU (Administrasi Hukum Umum)',
    'KI'       => 'KI (Kekayaan Intelektual)',
    'P3H'      => 'P3H (Pelayanan & Pemenuhan HAM)',
    'PPL'      => 'PPL (Peraturan Perundang-undangan)',
    'Keuangan' => 'Keuangan',
    'Humas'    => 'Humas',
    'SDM'      => 'SDM (Kepegawaian)',
];
$allBidangKeys = array_keys($bidangOptions);
$mouOptions = $pdo->query("SELECT id, kode, nama_mitra, judul FROM mitra_kinerja WHERE jenis = 'MoU' ORDER BY kode")->fetchAll();

// ============================================================================
// 1. POST HANDLER: TAMBAH NASKAH BARU (?action=add)
// ============================================================================
if ($action === 'add' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireRole(['admin', 'pemeriksa', 'pengampu', 'pic']);
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        $kode        = strtoupper(trim($_POST['kode'] ?? ''));
        $nomorNaskah = trim($_POST['nomor_naskah'] ?? '');
        $namaMitra   = trim($_POST['nama_mitra'] ?? '');
        $judul       = trim($_POST['judul'] ?? '');
        $portofolio  = $_POST['portofolio'] ?? 'Pilot Utama';
        $jenis       = $_POST['jenis'] ?? 'PKS';
        $pksIndukId  = !empty($_POST['pks_induk_id']) ? (int)$_POST['pks_induk_id'] : null;
        $mulai       = !empty(trim($_POST['tanggal_mulai'] ?? '')) ? trim($_POST['tanggal_mulai']) : null;
        $berakhir    = !empty(trim($_POST['tanggal_berakhir'] ?? '')) ? trim($_POST['tanggal_berakhir']) : null;
        $statusTgl   = $_POST['status_tanggal'] ?? 'BELUM TERVERIFIKASI';
        $evaluasiPerTahun = min(12, max(1, !empty($_POST['evaluasi_per_tahun']) ? (int)$_POST['evaluasi_per_tahun'] : 4));

        // Multi-selection bidang
        $postBidangs = is_array($_POST['bidang'] ?? null) ? array_map('trim', $_POST['bidang']) : [];
        $validBidangs = array_values(array_intersect($postBidangs, $allBidangKeys));
        $bidang = !empty($validBidangs) ? implode(', ', $validBidangs) : 'AHU';

        // PIC Internal & PIC Mitra (dengan nomor kontak)
        $picInternal   = trim($_POST['pic_internal'] ?? '');
        $picInternalNo = trim($_POST['pic_internal_no'] ?? '');
        $picMitra      = trim($_POST['pic_mitra'] ?? '');
        $picMitraNo    = trim($_POST['pic_mitra_no'] ?? '');

        // Upload Scan Naskah PDF jika ada
        $fileNaskah = null;
        if (isset($_FILES['file_naskah']) && $_FILES['file_naskah']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['file_naskah']['name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf' && isPdfValid($_FILES['file_naskah']['tmp_name'])) {
                $safeKode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $kode);
                $targetName = 'scan_naskah_' . $safeKode . '_' . time() . '.pdf';
                $targetPath = __DIR__ . '/public/uploads/' . $targetName;
                if (move_uploaded_file($_FILES['file_naskah']['tmp_name'], $targetPath)) {
                    $fileNaskah = 'public/uploads/' . $targetName;
                } else {
                    $errors[] = 'Gagal menyimpan berkas scan naskah PDF ke server.';
                }
            } else {
                $errors[] = 'File naskah wajib berformat .PDF asli.';
            }
        }

        // Upload Foto Dokumentasi
        $fotoKerjasama = null;
        if (isset($_FILES['foto_kerjasama']) && $_FILES['foto_kerjasama']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto_kerjasama']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && isImageValid($_FILES['foto_kerjasama']['tmp_name'])) {
                $safeKode = preg_replace('/[^a-zA-Z0-9_-]/', '_', $kode);
                $targetName = 'foto_' . $safeKode . '_' . time() . '.' . $ext;
                $targetPath = __DIR__ . '/public/uploads/' . $targetName;
                if (move_uploaded_file($_FILES['foto_kerjasama']['tmp_name'], $targetPath)) {
                    $fotoKerjasama = 'public/uploads/' . $targetName;
                }
            }
        }

        if ($kode === '' || $namaMitra === '') {
            $errors[] = 'Kode dan Nama Mitra wajib diisi.';
        }

        // Cek duplikasi kode
        $chkKode = $pdo->prepare('SELECT id FROM mitra_kinerja WHERE kode = ?');
        $chkKode->execute([$kode]);
        if ($chkKode->fetch()) {
            $errors[] = "Kode '$kode' sudah terdaftar dalam sistem. Gunakan kode lain.";
        }

        if (!empty($mulai) && !empty($berakhir) && (strtotime($berakhir) < strtotime($mulai) || $berakhir < $mulai)) {
            $errors[] = 'Tanggal berakhir tidak boleh lebih awal dari tanggal mulai.';
        }

        if (empty($errors)) {
            $pdo->beginTransaction();
            try {
                $cutoff = $mulai ?: date('Y-m-d');
                $baselineStatusInit = !empty($fileNaskah) ? 'DALAM PROSES' : 'BELUM DIISI';

                $stmtIns = $pdo->prepare('INSERT INTO mitra_kinerja (
                    kode, nomor_naskah, portofolio, nama_mitra, judul, bidang, jenis, pks_induk_id,
                    tanggal_mulai, tanggal_berakhir, status_tanggal, cutoff_date, evaluasi_per_tahun,
                    pic_internal, pic_internal_no, pic_mitra, pic_mitra_no,
                    file_naskah, foto_kerjasama, baseline_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

                $stmtIns->execute([
                    $kode, $nomorNaskah ?: null, $portofolio, $namaMitra, $judul, $bidang, $jenis, $pksIndukId,
                    $mulai, $berakhir, $statusTgl, $cutoff, $evaluasiPerTahun,
                    $picInternal ?: null, $picInternalNo ?: null, $picMitra ?: null, $picMitraNo ?: null,
                    $fileNaskah, $fotoKerjasama, $baselineStatusInit
                ]);
                $mid = (int)$pdo->lastInsertId();

                // Catatan Aturan: Saat naskah baru ditambahkan, Rencana Kerja masih kosong.

                // Inisialisasi Siklus Monev Berkala
                $keb = hitungKebutuhanScorecard($mulai, $berakhir, $evaluasiPerTahun);
                if (!empty($keb['milestones'])) {
                    $stmtSM = $pdo->prepare('INSERT INTO siklus_monev (mitra_id, siklus_ke, nama_siklus, tanggal_target_evaluasi, status_siklus) VALUES (?, ?, ?, ?, ?)');
                    foreach ($keb['milestones'] as $ms) {
                        $statusSiklus = !empty($ms['is_past']) ? 'Perlu Penilaian Segera' : 'Menunggu';
                        $stmtSM->execute([$mid, $ms['siklus_ke'], $ms['nama'], $ms['target_tgl'], $statusSiklus]);
                    }
                }

                // Inisialisasi 7 Indikator V2.1
                $v2Defaults = [
                    ['I1', 'Kejelasan Pengelolaan & Rencana Tindak Lanjut', 10],
                    ['I2', 'Implementasi / Tindak Lanjut', 15],
                    ['I3', 'Output', 15],
                    ['I4', 'Outcome', 20],
                    ['I5', 'Kontribusi / Dampak', 20],
                    ['I6', 'Evidence & Data', 10],
                    ['I7', 'Risiko & Keberlanjutan', 10],
                ];
                foreach ($v2Defaults as $ind) {
                    $desc = INDIKATOR_APA_YANG_DINILAI[$ind[0]] ?? $ind[1];
                    $pdo->prepare('INSERT INTO indikator_skor (mitra_id, kode_indikator, deskripsi, bobot, referensi_baseline, status_pemeriksaan) VALUES (?, ?, ?, ?, \'Baseline awal\', \'BELUM DITELAAH\')')
                        ->execute([$mid, $ind[0], $desc, $ind[2]]);
                }

                // Inisialisasi 12 Elemen Baseline FIX
                foreach (BASELINE_12_DEFS as $n => $d) {
                    $linkInit = ($n === 1 && !empty($fileNaskah)) ? $fileNaskah : '';
                    $statusInit = ($n === 1 && !empty($fileNaskah)) ? 'TERVERIFIKASI' : 'BELUM DIISI';
                    $pdo->prepare('INSERT INTO baseline_elemen (mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa, sumber_bukti_minimum, link_sumber_bukti, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$mid, $n, $d['kelompok'], $d['nama'], $d['yang_diperiksa'], $d['sumber_minimum'], $linkInit, $statusInit]);
                }

                // Inisialisasi Early Warning & Validasi
                foreach (['Masa berlaku','Aktivitas/tenggat','Data/eviden','PIC'] as $d) {
                    $pdo->prepare("INSERT INTO early_warning (mitra_id,dimensi,status,progres) VALUES (?,?,'V0','BELUM MULAI')")->execute([$mid,$d]);
                }

                $pemicu = [
                    'Perlu keputusan perpanjangan/addendum/evaluasi/pengakhiran',
                    'Hambatan lintas unit di luar kewenangan PIC/unit',
                    'Butuh anggaran/SDM/fasilitas di luar kewenangan unit',
                    'Komitmen material mitra tidak dipenuhi',
                    'Ada risiko hukum, reputasi, atau strategis yang material'
                ];
                foreach ($pemicu as $i => $t) {
                    $pdo->prepare('INSERT INTO intervensi_pimpinan (mitra_id,no_pemicu,pemicu_teks,jawaban) VALUES (?,?,?, \'BELUM DIPASTIKAN\')')->execute([$mid, $i+1, $t]);
                }
                $pdo->prepare("INSERT INTO validasi (mitra_id, status) VALUES (?, 'BELUM')")->execute([$mid]);

                logAudit($mid, $user['id'], 'TAMBAH_MITRA', "Mendaftarkan naskah baru: $kode - $namaMitra");
                $pdo->commit();

                header("Location: portofolio.php?action=edit&id=$mid&msg=created");
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Gagal menambahkan naskah baru: ' . $e->getMessage();
            }
        }
    }
}

// ============================================================================
// 2. POST HANDLER: EDIT NASKAH & RENCANA KERJA (?action=edit&id=...)
// ============================================================================
if ($action === 'edit' && $id > 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireRole(['admin', 'pemeriksa', 'pengampu', 'pic']);
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } else {
        $subAction = $_POST['sub_action'] ?? '';

        // A. TAMBAH RENCANA KERJA
        if ($subAction === 'add_rencana_kerja') {
            $judulRk   = trim($_POST['judul_rencana'] ?? '');
            $ruangRk   = trim($_POST['ruang_lingkup'] ?? '');
            $mulaiRk   = $_POST['tanggal_mulai'] ?: date('Y-01-01');
            $selesaiRk = ($_POST['tanggal_selesai'] ?? '') ?: date('Y-12-31');

            if ($judulRk === '') {
                $errors[] = 'Judul Rencana Kerja wajib diisi.';
            } elseif ($selesaiRk < $mulaiRk) {
                $errors[] = 'Tanggal selesai rencana kerja harus sama atau setelah tanggal mulai.';
            } else {
                try {
                    $stmtR = $pdo->prepare('INSERT INTO rencana_kerja (mitra_id, judul_rencana, ruang_lingkup, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmtR->execute([$id, $judulRk, $ruangRk, $mulaiRk, $selesaiRk, 'Disetujui']);
                    logAudit($id, $user['id'], 'ADD_RENCANA_KERJA', 'Tambah Rencana Kerja: ' . $judulRk);
                    $success = 'Rencana Kerja tahunan berhasil ditambahkan.';
                } catch (Throwable $e) {
                    $errors[] = 'Gagal menambahkan rencana kerja: ' . $e->getMessage();
                }
            }
        }
        // B. EDIT RENCANA KERJA
        elseif ($subAction === 'edit_rencana_kerja') {
            $rkId      = (int)($_POST['rk_id'] ?? 0);
            $judulRk   = trim($_POST['judul_rencana'] ?? '');
            $ruangRk   = trim($_POST['ruang_lingkup'] ?? '');
            $mulaiRk   = $_POST['tanggal_mulai'] ?: date('Y-01-01');
            $selesaiRk = ($_POST['tanggal_selesai'] ?? '') ?: date('Y-12-31');

            if ($rkId <= 0 || $judulRk === '') {
                $errors[] = 'ID dan Judul Rencana Kerja wajib diisi.';
            } elseif ($selesaiRk < $mulaiRk) {
                $errors[] = 'Tanggal selesai rencana kerja harus sama atau setelah tanggal mulai.';
            } else {
                try {
                    $stmtUpdRk = $pdo->prepare('UPDATE rencana_kerja SET judul_rencana = ?, ruang_lingkup = ?, tanggal_mulai = ?, tanggal_selesai = ? WHERE id = ? AND mitra_id = ?');
                    $stmtUpdRk->execute([$judulRk, $ruangRk, $mulaiRk, $selesaiRk, $rkId, $id]);
                    logAudit($id, $user['id'], 'EDIT_RENCANA_KERJA', 'Edit Rencana Kerja #' . $rkId . ': ' . $judulRk);
                    $success = 'Rencana Kerja tahunan berhasil diperbarui.';
                } catch (Throwable $e) {
                    $errors[] = 'Gagal memperbarui rencana kerja: ' . $e->getMessage();
                }
            }
        }
        // C. HAPUS RENCANA KERJA
        elseif ($subAction === 'delete_rencana_kerja') {
            $rkId = (int)($_POST['rk_id'] ?? 0);
            if ($rkId > 0) {
                try {
                    $stmtDelRk = $pdo->prepare('DELETE FROM rencana_kerja WHERE id = ? AND mitra_id = ?');
                    $stmtDelRk->execute([$rkId, $id]);
                    logAudit($id, $user['id'], 'DELETE_RENCANA_KERJA', 'Hapus Rencana Kerja #' . $rkId);
                    $success = 'Rencana Kerja tahunan berhasil dihapus.';
                } catch (Throwable $e) {
                    $errors[] = 'Gagal menghapus rencana kerja: ' . $e->getMessage();
                }
            }
        }
        // D. EDIT DATA NASKAH UTAMA
        else {
            $stmtM = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
            $stmtM->execute([$id]);
            $curMitra = $stmtM->fetch();

            if (!$curMitra) {
                http_response_code(404);
                die('Naskah tidak ditemukan.');
            }

            $nomorNaskah = trim($_POST['nomor_naskah'] ?? '');
            $namaMitra   = trim($_POST['nama_mitra'] ?? '');
            $judul       = trim($_POST['judul'] ?? '');
            $portofolio  = $_POST['portofolio'] ?? $curMitra['portofolio'];
            $jenis       = $_POST['jenis'] ?? $curMitra['jenis'];
            $pksIndukId  = !empty($_POST['pks_induk_id']) ? (int)$_POST['pks_induk_id'] : null;
            if ($pksIndukId === $id) $pksIndukId = null;

            $mulai       = !empty(trim($_POST['tanggal_mulai'] ?? '')) ? trim($_POST['tanggal_mulai']) : null;
            $berakhir    = !empty(trim($_POST['tanggal_berakhir'] ?? '')) ? trim($_POST['tanggal_berakhir']) : null;
            $statusTgl   = $_POST['status_tanggal'] ?? $curMitra['status_tanggal'];
            $cutoff      = $curMitra['cutoff_date'] ?: ($mulai ?: date('Y-m-d'));
            $sumber      = trim($_POST['sumber_baseline'] ?? '');
            $evaluasiPerTahun = min(12, max(1, !empty($_POST['evaluasi_per_tahun']) ? (int)$_POST['evaluasi_per_tahun'] : 4));

            // Multi-selection bidang
            $postBidangs = is_array($_POST['bidang'] ?? null) ? array_map('trim', $_POST['bidang']) : [];
            $validBidangs = array_values(array_intersect($postBidangs, $allBidangKeys));
            $bidang = !empty($validBidangs) ? implode(', ', $validBidangs) : ($curMitra['bidang'] ?? 'AHU');

            // PIC Internal & PIC Mitra (dengan nomor kontak)
            $picInternal   = trim($_POST['pic_internal'] ?? ($curMitra['pic_internal'] ?? ''));
            $picInternalNo = trim($_POST['pic_internal_no'] ?? ($curMitra['pic_internal_no'] ?? ''));
            $picMitra      = trim($_POST['pic_mitra'] ?? ($curMitra['pic_mitra'] ?? ''));
            $picMitraNo    = trim($_POST['pic_mitra_no'] ?? ($curMitra['pic_mitra_no'] ?? ''));

            // Upload Scan File PDF
            $fileNaskah = $curMitra['file_naskah'] ?? null;
            $newFileUploaded = false;
            if (isset($_FILES['file_naskah']) && $_FILES['file_naskah']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['file_naskah']['name'], PATHINFO_EXTENSION));
                if ($ext === 'pdf' && isPdfValid($_FILES['file_naskah']['tmp_name'])) {
                    $safeKode = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($curMitra['kode'] ?? ''));
                    $targetName = 'scan_naskah_' . $safeKode . '_' . time() . '.pdf';
                    $targetPath = __DIR__ . '/public/uploads/' . $targetName;
                    if (move_uploaded_file($_FILES['file_naskah']['tmp_name'], $targetPath)) {
                        $fileNaskah = 'public/uploads/' . $targetName;
                        $newFileUploaded = true;
                    }
                } else {
                    $errors[] = 'File naskah wajib berformat .PDF asli.';
                }
            }

            // Upload Foto Dokumentasi
            $fotoKerjasama = $curMitra['foto_kerjasama'] ?? null;
            if (isset($_FILES['foto_kerjasama']) && $_FILES['foto_kerjasama']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['foto_kerjasama']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && isImageValid($_FILES['foto_kerjasama']['tmp_name'])) {
                    $safeKode = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($curMitra['kode'] ?? ''));
                    $targetName = 'foto_' . $safeKode . '_' . time() . '.' . $ext;
                    $targetPath = __DIR__ . '/public/uploads/' . $targetName;
                    if (move_uploaded_file($_FILES['foto_kerjasama']['tmp_name'], $targetPath)) {
                        $fotoKerjasama = 'public/uploads/' . $targetName;
                    }
                }
            }

            if ($namaMitra === '') {
                $errors[] = 'Nama Mitra wajib diisi.';
            }
            if (!empty($mulai) && !empty($berakhir) && (strtotime($berakhir) < strtotime($mulai) || $berakhir < $mulai)) {
                $errors[] = 'Tanggal berakhir tidak boleh lebih awal dari tanggal mulai.';
            }

            if (empty($errors)) {
                try {
                    $pdo->beginTransaction();
                    $stmtU = $pdo->prepare('UPDATE mitra_kinerja SET
                        nomor_naskah=?, nama_mitra=?, judul=?, portofolio=?, bidang=?, jenis=?, pks_induk_id=?,
                        tanggal_mulai=?, tanggal_berakhir=?, status_tanggal=?, cutoff_date=?, sumber_baseline=?,
                        pic_internal=?, pic_internal_no=?, pic_mitra=?, pic_mitra_no=?,
                        evaluasi_per_tahun=?, file_naskah=?, foto_kerjasama=?
                        WHERE id=?');
                    $stmtU->execute([
                        $nomorNaskah ?: null, $namaMitra, $judul, $portofolio, $bidang, $jenis, $pksIndukId,
                        $mulai, $berakhir, $statusTgl, $cutoff, $sumber,
                        $picInternal ?: null, $picInternalNo ?: null, $picMitra ?: null, $picMitraNo ?: null,
                        $evaluasiPerTahun, $fileNaskah, $fotoKerjasama, $id
                    ]);

                    // Sinkronkan siklus_monev
                    $keb = hitungKebutuhanScorecard($mulai, $berakhir, $evaluasiPerTahun);
                    if (!empty($keb['milestones'])) {
                        $stmtCurSM = $pdo->prepare('SELECT * FROM siklus_monev WHERE mitra_id = ?');
                        $stmtCurSM->execute([$id]);
                        $currentMilestones = $stmtCurSM->fetchAll(PDO::FETCH_ASSOC);
                        $curMap = [];
                        foreach ($currentMilestones as $cm) {
                            $curMap[(int)$cm['siklus_ke']] = $cm;
                        }

                        $validSiklusKe = [];
                        foreach ($keb['milestones'] as $ms) {
                            $ske = (int)$ms['siklus_ke'];
                            $validSiklusKe[] = $ske;
                            $isPast = !empty($ms['is_past']);
                            if (isset($curMap[$ske])) {
                                $curr = $curMap[$ske];
                                $isCompleted = in_array(strtolower(trim($curr['status_siklus'] ?? '')), ['selesai', 'selesai evaluasi'], true);
                                $newStatus = $isCompleted ? 'Selesai' : ($isPast ? 'Perlu Penilaian Segera' : ($curr['status_siklus'] === 'Sedang Dinilai' ? 'Sedang Dinilai' : 'Menunggu'));
                                $stmtUpdSM = $pdo->prepare('UPDATE siklus_monev SET nama_siklus = ?, tanggal_target_evaluasi = ?, status_siklus = ? WHERE id = ?');
                                $stmtUpdSM->execute([$ms['nama'], $ms['target_tgl'], $newStatus, $curr['id']]);
                            } else {
                                $statusSiklus = $isPast ? 'Perlu Penilaian Segera' : 'Menunggu';
                                $stmtInsSM = $pdo->prepare('INSERT INTO siklus_monev (mitra_id, siklus_ke, nama_siklus, tanggal_target_evaluasi, status_siklus) VALUES (?, ?, ?, ?, ?)');
                                $stmtInsSM->execute([$id, $ske, $ms['nama'], $ms['target_tgl'], $statusSiklus]);
                            }
                        }
                    }

                    // Sinkronkan elemen 1 jika berkas PDF baru diunggah
                    if ($newFileUploaded && $fileNaskah) {
                        $isBaselineLocked = (($curMitra['baseline_status'] ?? '') === 'TERVERIFIKASI / DIKUNCI');
                        if (!$isBaselineLocked || $userRole === 'admin') {
                            $stmtB = $pdo->prepare("UPDATE baseline_elemen SET link_sumber_bukti = ?, status = 'TERVERIFIKASI' WHERE mitra_id = ? AND nomor_elemen = 1");
                            $stmtB->execute([$fileNaskah, $id]);
                        }
                    }

                    syncStatusScorecard($pdo, $id);
                    logAudit($id, $user['id'], 'UPDATE_MITRA', 'Data naskah ' . $curMitra['kode'] . ' diperbarui via Portofolio');

                    $pdo->commit();
                    $success = 'Data naskah kerja sama berhasil diperbarui.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = 'Gagal memperbarui naskah: ' . $e->getMessage();
                }
            }
        }
    }
}

// ============================================================================
// 3. RENDER VIEW: TAMBAH NASKAH (?action=add)
// ============================================================================
if ($action === 'add') {
    requireRole(['admin', 'pemeriksa', 'pengampu', 'pic']);
    $pageTitle = 'Tambah Naskah Baru — Portofolio';
    require __DIR__ . '/includes/header.php';
?>
<div class="flex-between" style="margin-bottom:14px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Tambah Naskah Baru</h1>
        <div class="muted" style="font-size:13px;">Registrasi dokumen PKS/MoU dan rincian stakeholder ke dalam sistem</div>
    </div>
    <a href="portofolio.php" class="btn btn-outline btn-sm">&larr; Kembali ke Portofolio</a>
</div>

<?php if ($success): ?><div class="alert alert-info"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

<div class="card" style="margin-bottom:20px;">
    <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <div class="form-grid">
            <div class="field">
                <label>Kode Naskah (P11, C06, dst) *</label>
                <input type="text" name="kode" maxlength="5" required placeholder="Contoh: P11" value="<?= h($_POST['kode'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Nomor Naskah Resmi (Form Number)</label>
                <input type="text" name="nomor_naskah" placeholder="Contoh: W.31.HH.01.02-45/2026" value="<?= h($_POST['nomor_naskah'] ?? '') ?>">
                <div class="muted" style="font-size:11px;margin-top:2px;">Nomor registrasi surat naskah fisik yang ditandatangani.</div>
            </div>
            <div class="field">
                <label>Portofolio</label>
                <select name="portofolio">
                    <option <?= ($_POST['portofolio'] ?? '') === 'Pilot Utama' ? 'selected' : '' ?>>Pilot Utama</option>
                    <option <?= ($_POST['portofolio'] ?? '') === 'Cadangan' ? 'selected' : '' ?>>Cadangan</option>
                </select>
            </div>
            <div class="field">
                <label>Bentuk Naskah</label>
                <select name="jenis">
                    <option <?= ($_POST['jenis'] ?? '') === 'PKS' ? 'selected' : '' ?>>PKS</option>
                    <option <?= ($_POST['jenis'] ?? '') === 'MoU' ? 'selected' : '' ?>>MoU</option>
                </select>
            </div>

            <!-- Multi-Selection Bidang -->
            <div class="field" style="grid-column:1/-1;">
                <label style="font-weight:600;">Bidang Kerja Sama (Dapat Memilih Lebih Dari Satu) *</label>
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                    <?php
                    $curPostBidang = is_array($_POST['bidang'] ?? null) ? $_POST['bidang'] : ['AHU'];
                    foreach ($bidangOptions as $bKey => $bLabel):
                        $checked = in_array($bKey, $curPostBidang, true) ? 'checked' : '';
                    ?>
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;cursor:pointer;">
                        <input type="checkbox" name="bidang[]" value="<?= $bKey ?>" <?= $checked ?>>
                        <span><?= $bLabel ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="muted" style="font-size:11px;margin-top:4px;">Centang satu atau beberapa bidang pengampu terkait di lingkungan Kanwil Kepri.</div>
            </div>

            <div class="field" style="grid-column:1/-1;">
                <label>Nama Mitra *</label>
                <input type="text" name="nama_mitra" required placeholder="Contoh: Universitas Maritim Raja Ali Haji (UMRAH)" value="<?= h($_POST['nama_mitra'] ?? '') ?>">
            </div>

            <div class="field" style="grid-column:1/-1;">
                <label>Judul Kerja Sama</label>
                <input type="text" name="judul" placeholder="Judul lengkap naskah kerja sama" value="<?= h($_POST['judul'] ?? '') ?>">
            </div>

            <div class="field">
                <label>Kerja Sama Utama (Payung MoU)</label>
                <select name="pks_induk_id">
                    <option value="">- Tidak ada (Bukan Turunan) -</option>
                    <?php foreach ($mouOptions as $mou): ?>
                    <option value="<?= $mou['id'] ?>"><?= h($mou['kode']) ?> &mdash; <?= h(singkat($mou['nama_mitra'], 35)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Status Verifikasi Tanggal</label>
                <select name="status_tanggal">
                    <option>BELUM TERVERIFIKASI</option>
                    <option>TERVERIFIKASI</option>
                </select>
            </div>

            <div class="field">
                <label>Tanggal Mulai</label>
                <input type="date" name="tanggal_mulai" value="<?= h($_POST['tanggal_mulai'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Tanggal Berakhir</label>
                <input type="date" name="tanggal_berakhir" value="<?= h($_POST['tanggal_berakhir'] ?? '') ?>">
            </div>

            <div class="field">
                <label>Frekuensi Evaluasi (Per Tahun) *</label>
                <input type="number" name="evaluasi_per_tahun" value="<?= h($_POST['evaluasi_per_tahun'] ?? 4) ?>" min="1" max="12" required>
                <div class="muted" style="font-size:11px;margin-top:2px;">Berapa kali kegiatan evaluasi monev dilakukan dalam periode 1 tahun (default: 4).</div>
            </div>

            <!-- PIC Mitra & PIC Internal -->
            <div class="field">
                <label>Nama PIC Internal (Kanwil)</label>
                <input type="text" name="pic_internal" placeholder="Nama pejabat / pengampu Kanwil" value="<?= h($_POST['pic_internal'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Nomor HP/Kontak PIC Internal (Form Number)</label>
                <input type="text" name="pic_internal_no" placeholder="Contoh: 0812-3456-7890" value="<?= h($_POST['pic_internal_no'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Nama PIC Mitra</label>
                <input type="text" name="pic_mitra" placeholder="Nama narahubung / PIC dari mitra" value="<?= h($_POST['pic_mitra'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Nomor HP/Kontak PIC Mitra (Form Number)</label>
                <input type="text" name="pic_mitra_no" placeholder="Contoh: 0813-9876-5432" value="<?= h($_POST['pic_mitra_no'] ?? '') ?>">
            </div>

            <div class="field">
                <label>Upload Scan Berkas Naskah Fisik (.PDF)</label>
                <input type="file" name="file_naskah" accept=".pdf">
                <div class="muted" style="font-size:11px;margin-top:2px;">Dokumen PDF resmi bertanda tangan basah / digital.</div>
            </div>
            <div class="field">
                <label>Foto Dokumentasi (JPG/PNG/WebP)</label>
                <input type="file" name="foto_kerjasama" accept=".jpg,.jpeg,.png,.webp">
            </div>
        </div>

        <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;">
                <?= uiIcon('save', 14) ?> Simpan Naskah Baru
            </button>
            <a href="portofolio.php" class="btn btn-outline" style="margin-left:8px;">Batal</a>
        </div>
    </form>
</div>
<?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================================
// 4. RENDER VIEW: EDIT NASKAH & RENCANA KERJA (?action=edit&id=...)
// ============================================================================
if ($action === 'edit' && $id > 0) {
    requireRole(['admin', 'pemeriksa', 'pengampu', 'pic']);
    $stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
    $stmt->execute([$id]);
    $mitra = $stmt->fetch();
    if (!$mitra) {
        http_response_code(404);
        die('Naskah kerja sama tidak ditemukan.');
    }

    $stmtVal = $pdo->prepare('SELECT status FROM validasi WHERE mitra_id = ?');
    $stmtVal->execute([$id]);
    $valStatus = $stmtVal->fetchColumn() ?: 'BELUM';
    $isLockedFinal = (in_array($mitra['status_scorecard'] ?? '', ['FINAL/TERVALIDASI', 'FINAL'], true) || $valStatus === 'DISETUJUI');

    $isAssigned = (!empty($mitra['pemeriksa_id']) && (int)$mitra['pemeriksa_id'] === (int)$user['id'])
        || (!empty($mitra['pic_internal']) && stripos($mitra['pic_internal'], $user['nama'] ?? $user['username'] ?? '') !== false);

    $canManage = in_array($userRole, ['admin', 'pemeriksa', 'pic'], true)
        || ($userRole === 'pengampu' && ($isAssigned || empty($mitra['pemeriksa_id'])));
    if ($isLockedFinal && $userRole !== 'admin') {
        $canManage = false;
    }

    // Ambil daftar rencana kerja untuk naskah ini
    $stmtRK = $pdo->prepare('SELECT * FROM rencana_kerja WHERE mitra_id = ? ORDER BY tanggal_mulai ASC');
    $stmtRK->execute([$id]);
    $listRk = $stmtRK->fetchAll();

    $pageTitle = 'Edit Naskah ' . $mitra['kode'] . ' — Portofolio';
    require __DIR__ . '/includes/header.php';
?>
<div class="flex-between" style="margin-bottom:14px;">
    <div>
        <h1 style="margin:0;font-size:20px;"><?= h($mitra['kode']) ?> &mdash; Edit Naskah</h1>
        <div class="muted" style="font-size:13px;"><?= h($mitra['nama_mitra']) ?></div>
    </div>
    <a href="portofolio.php" class="btn btn-outline btn-sm">&larr; Kembali ke Portofolio</a>
</div>

<?php if ($success): ?><div class="alert alert-info"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

<?php if (!$canManage): ?>
<div class="alert alert-warning" style="margin-bottom:14px;">
    <?= uiIcon('lock', 14) ?> <strong>Naskah Terkunci:</strong> Naskah ini telah berstatus <strong>FINAL/TERVALIDASI</strong> atau akun Anda tidak memiliki izin penugasan untuk mengubah naskah ini. Perubahan dinonaktifkan.
</div>
<?php endif; ?>

<!-- Formulir Edit Naskah -->
<div class="card" style="margin-bottom:20px;">
    <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <fieldset <?= $canManage ? '' : 'disabled' ?> style="border:none;padding:0;margin:0;">
        <div class="form-grid">
            <div class="field">
                <label>Kode</label>
                <input value="<?= h($mitra['kode']) ?>" disabled style="background:#f1f5f9;font-weight:700;">
            </div>
            <div class="field">
                <label>Nomor Naskah Resmi (Form Number)</label>
                <input type="text" name="nomor_naskah" value="<?= h($mitra['nomor_naskah'] ?? '') ?>" placeholder="Contoh: W.31.HH.01.02-45/2026">
                <div class="muted" style="font-size:11px;margin-top:2px;">Nomor registrasi naskah fisik bertanda tangan.</div>
            </div>
            <div class="field">
                <label>Portofolio</label>
                <select name="portofolio">
                    <?php foreach (['Pilot Utama','Cadangan'] as $opt): ?>
                    <option <?= ($mitra['portofolio'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Bentuk Naskah</label>
                <select name="jenis">
                    <?php foreach (['PKS','MoU'] as $opt): ?>
                    <option <?= ($mitra['jenis'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Multi-Selection Bidang pada Edit Naskah -->
            <div class="field" style="grid-column:1/-1;">
                <label style="font-weight:600;">Bidang Kerja Sama (Dapat Memilih Lebih Dari Satu) *</label>
                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                    <?php
                    $curBidangArr = array_filter(array_map('trim', explode(',', $mitra['bidang'] ?? 'AHU')));
                    foreach ($bidangOptions as $bKey => $bLabel):
                        $checked = in_array($bKey, $curBidangArr, true) ? 'checked' : '';
                    ?>
                    <label style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;cursor:pointer;">
                        <input type="checkbox" name="bidang[]" value="<?= $bKey ?>" <?= $checked ?>>
                        <span><?= $bLabel ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="muted" style="font-size:11px;margin-top:4px;">Centang satu atau beberapa bidang pengampu di lingkungan Kanwil Kepri.</div>
            </div>

            <div class="field" style="grid-column:1/-1;">
                <label>Nama Mitra *</label>
                <input type="text" name="nama_mitra" value="<?= h($mitra['nama_mitra']) ?>" required>
            </div>

            <div class="field" style="grid-column:1/-1;">
                <label>Judul Naskah</label>
                <input type="text" name="judul" value="<?= h($mitra['judul']) ?>">
            </div>

            <div class="field">
                <label>Kerja Sama Utama (Payung MoU)</label>
                <select name="pks_induk_id">
                    <option value="">- Tidak ada (Bukan Turunan) -</option>
                    <?php foreach ($mouOptions as $mou): ?>
                    <?php if ((int)$mou['id'] === $id) continue; ?>
                    <option value="<?= $mou['id'] ?>" <?= (int)($mitra['pks_induk_id'] ?? 0) === (int)$mou['id'] ? 'selected' : '' ?>><?= h($mou['kode']) ?> &mdash; <?= h(singkat($mou['nama_mitra'], 35)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Status Tanggal</label>
                <select name="status_tanggal">
                    <?php foreach (['BELUM TERVERIFIKASI','TERVERIFIKASI'] as $opt): ?>
                    <option <?= ($mitra['status_tanggal'] ?? '') === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Tanggal Mulai</label>
                <input type="date" name="tanggal_mulai" value="<?= h($mitra['tanggal_mulai']) ?>">
            </div>
            <div class="field">
                <label>Tanggal Berakhir</label>
                <input type="date" name="tanggal_berakhir" value="<?= h($mitra['tanggal_berakhir']) ?>">
            </div>

            <div class="field">
                <label>Evaluasi Rencana Kerja (Per Tahun)</label>
                <input type="number" name="evaluasi_per_tahun" value="<?= h($mitra['evaluasi_per_tahun'] ?? 4) ?>" min="1" max="12" required>
                <div class="muted" style="font-size:11px;margin-top:2px;">Berapa kali kegiatan evaluasi rencana kerja dilakukan per tahun (default: 4).</div>
            </div>

            <div class="field">
                <label>Sumber Baseline</label>
                <input type="text" name="sumber_baseline" value="<?= h($mitra['sumber_baseline'] ?? '') ?>">
            </div>

            <!-- PIC Internal & PIC Mitra (dengan nomor kontak) -->
            <div class="field">
                <label>Nama PIC Internal (Kanwil)</label>
                <input type="text" name="pic_internal" value="<?= h($mitra['pic_internal'] ?? '') ?>" placeholder="Nama pejabat / pengampu Kanwil">
            </div>
            <div class="field">
                <label>Nomor HP/Kontak PIC Internal (Form Number)</label>
                <input type="text" name="pic_internal_no" value="<?= h($mitra['pic_internal_no'] ?? '') ?>" placeholder="Contoh: 0812-3456-7890">
            </div>
            <div class="field">
                <label>Nama PIC Mitra</label>
                <input type="text" name="pic_mitra" value="<?= h($mitra['pic_mitra'] ?? '') ?>" placeholder="Nama narahubung / PIC Mitra">
            </div>
            <div class="field">
                <label>Nomor HP/Kontak PIC Mitra (Form Number)</label>
                <input type="text" name="pic_mitra_no" value="<?= h($mitra['pic_mitra_no'] ?? '') ?>" placeholder="Contoh: 0813-9876-5432">
            </div>

            <div class="field">
                <label>Upload Scan Berkas Naskah (.PDF)</label>
                <input type="file" name="file_naskah" accept=".pdf">
                <?php if (!empty($mitra['file_naskah'])): ?>
                <div style="margin-top:6px;">
                    <a href="<?= h($mitra['file_naskah']) ?>" target="_blank" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:4px;">
                        <?= uiIcon('file-text', 13) ?> Lihat Berkas Scan PDF
                    </a>
                </div>
                <?php endif; ?>
            </div>
            <div class="field">
                <label>Foto Dokumentasi (JPG/PNG)</label>
                <input type="file" name="foto_kerjasama" accept=".jpg,.jpeg,.png,.webp">
                <?php if (!empty($mitra['foto_kerjasama'])): ?>
                <div style="margin-top:6px;">
                    <a href="<?= h($mitra['foto_kerjasama']) ?>" target="_blank" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:4px;">
                        <?= uiIcon('eye', 13) ?> Lihat Foto
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-top:16px;">
            <?php if ($canManage): ?>
            <button type="submit" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;">
                <?= uiIcon('save', 14) ?> Simpan Perubahan
            </button>
            <?php endif; ?>
            <a href="portofolio.php" class="btn btn-outline" style="margin-left:8px;">&larr; Kembali ke Portofolio</a>
        </div>
        </fieldset>
    </form>
</div>

<!-- Card Rencana Kerja Tahunan (/Tahun) -->
<div class="card" style="margin-bottom:24px;">
    <div class="flex-between" style="margin-bottom:14px;">
        <div>
            <h2 style="margin:0;font-size:16px;">Rencana Kerja Tahunan (/Tahun)</h2>
            <div class="muted" style="font-size:12px;">Program dan target operasional turunan naskah per tahun</div>
        </div>
        <?php if ($canManage): ?>
        <button type="button" onclick="toggleRkAddForm()" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:5px;">
            <?= uiIcon('plus', 13) ?> Tambah Rencana Kerja
        </button>
        <?php endif; ?>
    </div>

    <!-- Formulir Rencana Kerja Baru (Tanpa Field Status) -->
    <div id="rkAddForm" style="display:none;background:#f8fafc;padding:16px;border-radius:6px;border:1px solid #e2e8f0;margin-bottom:16px;">
        <h3 style="font-size:14px;margin-top:0;margin-bottom:12px;color:#1e40af;">Formulir Rencana Kerja Baru</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="sub_action" value="add_rencana_kerja">
            <div class="form-grid">
                <div class="field" style="grid-column:1/-1;">
                    <label>Judul Rencana Kerja Tahunan *</label>
                    <input type="text" name="judul_rencana" required placeholder="Contoh: Rencana Aksi Sosialisasi Kekayaan Intelektual Terpadu 2026">
                </div>
                <div class="field">
                    <label>Tanggal Mulai</label>
                    <input type="date" name="tanggal_mulai" value="<?= date('Y-01-01') ?>">
                </div>
                <div class="field">
                    <label>Tanggal Selesai</label>
                    <input type="date" name="tanggal_selesai" value="<?= date('Y-12-31') ?>">
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Ruang Lingkup &amp; Target Kegiatan</label>
                    <textarea name="ruang_lingkup" rows="2" placeholder="Uraikan target kegiatan dan keluaran tahunan yang disepakati..."></textarea>
                </div>
            </div>
            <div style="margin-top:12px;">
                <button type="submit" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:5px;">
                    <?= uiIcon('save', 13) ?> Simpan Rencana Kerja
                </button>
                <button type="button" onclick="toggleRkAddForm()" class="btn btn-outline btn-sm" style="margin-left:6px;">Batal</button>
            </div>
        </form>
    </div>

    <!-- Tabel Rencana Kerja (Kolom Status Dihilangkan, Tambah Kolom Aksi Edit & Hapus) -->
    <div class="table-wrap">
        <table class="table" style="width:100%;">
            <thead>
                <tr>
                    <th style="width:22%;">Tahun / Periode</th>
                    <th style="width:34%;">Judul Rencana Kerja</th>
                    <th style="width:30%;">Ruang Lingkup &amp; Target Kegiatan</th>
                    <th style="width:14%;text-align:center;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($listRk)): ?>
                <tr>
                    <td colspan="4">
                        <?= uiEmptyState('Belum Ada Rencana Kerja Tahunan', 'Gunakan formulir di atas untuk menambahkan jadwal kegiatan evaluasi tahunan.', 'file-text') ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($listRk as $rk): ?>
                <tr>
                    <td>
                        <strong><?= date('Y', strtotime($rk['tanggal_mulai'])) ?></strong>
                        <div class="muted" style="font-size:11px;margin-top:2px;">
                            <?= formatTanggal($rk['tanggal_mulai']) ?> s.d. <?= formatTanggal($rk['tanggal_selesai']) ?>
                        </div>
                    </td>
                    <td><strong><?= h($rk['judul_rencana']) ?></strong></td>
                    <td style="font-size:12.5px;line-height:1.45;"><?= nl2br(h($rk['ruang_lingkup'] ?? '-')) ?></td>
                    <td style="text-align:center;white-space:nowrap;">
                        <?php if ($canManage): ?>
                        <div style="display:inline-flex;gap:4px;align-items:center;">
                            <button type="button" class="btn btn-outline btn-sm" style="padding:4px 8px;font-size:11.5px;display:inline-flex;align-items:center;gap:4px;" onclick="openEditRkModal(<?= htmlspecialchars(json_encode($rk), ENT_QUOTES, 'UTF-8') ?>)">
                                <?= uiIcon('edit', 12) ?> Edit
                            </button>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Apakah Anda yakin ingin menghapus rencana kerja ini?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="sub_action" value="delete_rencana_kerja">
                                <input type="hidden" name="rk_id" value="<?= $rk['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm" style="padding:4px 8px;font-size:11.5px;color:#dc2626;border-color:#fca5a5;background:#fef2f2;display:inline-flex;align-items:center;gap:4px;">
                                    <?= uiIcon('trash', 12) ?> Hapus
                                </button>
                            </form>
                        </div>
                        <?php else: ?>
                        <span class="muted" style="font-size:11px;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Pop-up Edit Rencana Kerja (Tanpa Field Status) -->
<div id="editRkModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:8px;max-width:540px;width:100%;padding:20px;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);border:1px solid #cbd5e1;position:relative;">
        <div class="flex-between" style="margin-bottom:14px;border-bottom:1px solid #f1f5f9;padding-bottom:10px;">
            <h3 style="margin:0;font-size:15px;color:#1e293b;display:inline-flex;align-items:center;gap:6px;">
                <?= uiIcon('edit', 15) ?> Edit Rencana Kerja Tahunan
            </h3>
            <button type="button" onclick="closeEditRkModal()" style="background:none;border:none;font-size:18px;cursor:pointer;color:#64748b;line-height:1;">&times;</button>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="sub_action" value="edit_rencana_kerja">
            <input type="hidden" name="rk_id" id="modalEditRkId">
            <div class="form-grid">
                <div class="field" style="grid-column:1/-1;">
                    <label>Judul Rencana Kerja Tahunan *</label>
                    <input type="text" name="judul_rencana" id="modalEditRkJudul" required>
                </div>
                <div class="field">
                    <label>Tanggal Mulai</label>
                    <input type="date" name="tanggal_mulai" id="modalEditRkMulai">
                </div>
                <div class="field">
                    <label>Tanggal Selesai</label>
                    <input type="date" name="tanggal_selesai" id="modalEditRkSelesai">
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label>Ruang Lingkup &amp; Target Kegiatan</label>
                    <textarea name="ruang_lingkup" id="modalEditRkRuang" rows="3"></textarea>
                </div>
            </div>
            <div style="margin-top:16px;text-align:right;">
                <button type="button" onclick="closeEditRkModal()" class="btn btn-outline btn-sm" style="margin-right:6px;">Batal</button>
                <button type="submit" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:5px;">
                    <?= uiIcon('save', 13) ?> Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleRkAddForm() {
    var f = document.getElementById('rkAddForm');
    f.style.display = (f.style.display === 'none' || f.style.display === '') ? 'block' : 'none';
}
function openEditRkModal(rk) {
    document.getElementById('modalEditRkId').value = rk.id || '';
    document.getElementById('modalEditRkJudul').value = rk.judul_rencana || '';
    document.getElementById('modalEditRkMulai').value = rk.tanggal_mulai || '';
    document.getElementById('modalEditRkSelesai').value = rk.tanggal_selesai || '';
    document.getElementById('modalEditRkRuang').value = rk.ruang_lingkup || '';
    document.getElementById('editRkModal').style.display = 'flex';
}
function closeEditRkModal() {
    document.getElementById('editRkModal').style.display = 'none';
}
</script>
<?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================================
// 5. RENDER VIEW: LISTING PORTOFOLIO (DEFAULT)
// ============================================================================
$all = getAllMitraSummary($pdo);
$pageTitle = 'Portofolio';
require __DIR__ . '/includes/header.php';
?>

<div class="flex-between" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Portofolio Kerja Sama</h1>
        <div class="muted" style="font-size:13px;">Rekap status, bidang, evaluasi, dan pengelolaan naskah kerja sama</div>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="dashboard.php" class="btn btn-outline btn-sm">&larr; Dashboard</a>
        <?php if (in_array(currentUser()['role'] ?? '', ['admin','pemeriksa','pengampu','pic'], true)): ?>
        <a href="portofolio.php?action=add" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:5px;">
            <?= uiIcon('plus', 13) ?> Tambah Naskah
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="filters">
        <!-- Search Input -->
        <div style="position:relative;display:inline-block;min-width:240px;">
            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none;display:inline-flex;align-items:center;">
                <?= uiIcon('search', 14) ?>
            </span>
            <input type="text" id="fSearch" placeholder="Cari kode / mitra / bidang..." style="padding:7px 10px 7px 32px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box;">
        </div>

        <!-- Filter Portofolio -->
        <select id="fPortofolio">
            <option value="">Semua Portofolio</option>
            <option value="Pilot Utama">Pilot Utama</option>
            <option value="Cadangan">Cadangan</option>
        </select>

        <!-- Multi-Selection Filter Bidang -->
        <div id="fBidang" class="multi-select-wrap" style="position:relative;display:inline-block;">
            <button type="button" id="btnFilterBidang" class="btn btn-outline btn-sm" style="background:#fff;border:1px solid var(--border);padding:7px 10px;font-size:13px;display:inline-flex;align-items:center;gap:6px;min-width:145px;justify-content:space-between;color:#1e293b;border-radius:6px;box-sizing:border-box;">
                <span id="labelFilterBidang" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:130px;">Semua Bidang</span>
                <?= uiIcon('chevron-down', 12) ?>
            </button>
            <div id="menuFilterBidang" style="display:none;position:absolute;top:calc(100% + 4px);left:0;z-index:100;background:#fff;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);padding:8px;min-width:210px;">
                <div style="display:flex;justify-content:space-between;align-items:center;padding:2px 4px 6px 4px;border-bottom:1px solid #f1f5f9;margin-bottom:6px;">
                    <span style="font-size:11px;font-weight:700;color:#64748b;">PILIH BIDANG</span>
                    <button type="button" onclick="selectAllBidangFilter(true)" style="background:none;border:none;color:#2563eb;font-size:11px;cursor:pointer;padding:0;">Pilih Semua</button>
                </div>
                <?php foreach ($allBidangKeys as $bOpt): ?>
                <label style="display:flex;align-items:center;gap:8px;padding:4px 6px;border-radius:4px;cursor:pointer;font-size:12.5px;color:#334155;user-select:none;">
                    <input type="checkbox" class="chk-filter-bidang" value="<?= $bOpt ?>" onchange="applyFilters()">
                    <span><?= $bOpt ?></span>
                </label>
                <?php endforeach; ?>
                <div style="border-top:1px solid #f1f5f9;margin-top:6px;padding-top:6px;text-align:right;">
                    <button type="button" onclick="clearBidangFilter()" style="background:none;border:none;color:#dc2626;font-size:11px;cursor:pointer;">Reset Pilihan</button>
                </div>
            </div>
        </div>

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
            <option value="BELUM DITENTUKAN">Belum Ditentukan</option>
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
        <button type="button" id="btnResetFilter" onclick="resetFilters()" class="btn btn-outline btn-sm" style="padding:7px 12px;font-size:12.5px;display:inline-flex;align-items:center;gap:5px;white-space:nowrap;cursor:pointer;" title="Reset Semua Filter">
            <?= uiIcon('refresh', 13) ?> Reset Filter
        </button>
    </div>

    <div class="table-wrap">
    <table id="tblPortofolio">
        <thead>
            <tr>
                <th>Kode</th>
                <th>Portofolio</th>
                <th>Mitra</th>
                <th>Bidang</th>
                <th>Berlaku s.d.</th>
                <th>Nilai</th>
                <th>Kategori</th>
                <th>Kelengkapan</th>
                <th>Posisi</th>
                <th>Rekomendasi</th>
                <th>Status</th>
                <th>Warning</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($all)): ?>
            <tr id="emptyRow"><td colspan="13"><?= uiEmptyState('Belum Ada Data Kerja Sama', 'Naskah kerja sama belum tersedia atau belum ditambahkan.', 'file-text') ?></td></tr>
        <?php else: ?>
            <tr id="noFilterMatchRow" style="display:none;"><td colspan="13"><?= uiEmptyState('Tidak Ada Data yang Sesuai', 'Tidak ditemukan naskah yang cocok dengan filter. Gunakan Reset Filter untuk memuat kembali.', 'search') ?></td></tr>
            <?php foreach ($all as $s): $m = $s['mitra']; ?>
            <tr data-portofolio="<?= h($m['portofolio']) ?>" data-bidang="<?= h($m['bidang'] ?? 'AHU') ?>" data-kategori="<?= h($s['kategori']) ?>" data-status="<?= h($s['status_scorecard']) ?>" data-posisi="<?= h($s['posisi_portofolio']) ?>" data-rekomendasi="<?= h($s['rekomendasi']) ?>" data-rekomendasi-key="<?= h(ekstrakKeywordRekomendasi($s['rekomendasi'])) ?>">
                <td>
                    <strong><?= h($m['kode']) ?></strong>
                    <?php if (!empty($m['nomor_naskah'])): ?>
                    <div class="muted" style="font-size:10px;"><?= h($m['nomor_naskah']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= h($m['portofolio']) ?></td>
                <td><?= h($m['nama_mitra']) ?></td>
                <td>
                    <?php
                    $bArray = array_filter(array_map('trim', explode(',', $m['bidang'] ?? 'AHU')));
                    if (empty($bArray)) $bArray = ['AHU'];
                    foreach ($bArray as $bItem):
                    ?>
                    <span class="badge badge-secondary" style="font-size:10px;font-weight:600;margin-right:2px;margin-bottom:2px;display:inline-block;"><?= h($bItem) ?></span>
                    <?php endforeach; ?>
                    <br><span class="muted" style="font-size:10px;"><?= h($m['jenis']) ?></span>
                </td>
                <td><?= formatTanggal($m['tanggal_berakhir']) ?></td>
                <td><?= $s['nilai_final'] !== null ? number_format($s['nilai_final'], 2) : '<span class="badge badge-' . warnaKategori('DALAM PROSES') . '">Dalam Proses</span>' ?></td>
                <td><span class="badge badge-<?= warnaKategori($s['kategori']) ?>"><?= h($s['kategori']) ?></span></td>
                <td><?= $s['kelengkapan'] ?>%</td>
                <td><span class="badge badge-secondary" style="font-size:11px;max-width:180px;white-space:normal;display:inline-block;line-height:1.25;text-align:left;"><?= h($s['posisi_portofolio']) ?></span></td>
                <td>
                    <?php 
                    $rekHead = trim(explode("\n", trim($s['rekomendasi'] ?? '-'))[0]) ?: '-';
                    ?>
                    <span class="badge badge-<?= warnaRekomendasi($s['rekomendasi']) ?>" style="font-size:11px;max-width:210px;white-space:normal;display:inline-block;line-height:1.25;text-align:left;" title="<?= h($s['rekomendasi']) ?>">
                        <?= h(singkat($rekHead, 45)) ?>
                    </span>
                </td>
                <td><?= h($s['status_scorecard']) ?></td>
                <td><span class="badge badge-<?= warnaWarning($s['warning']['status']) ?>"><?= h($s['warning']['label']) ?></span></td>
                <td>
                    <div style="display:flex;gap:4px;flex-wrap:nowrap;">
                        <?php if (in_array(currentUser()['role'], ['admin','pemeriksa','pengampu','pic'])): ?>
                        <a href="portofolio.php?action=edit&id=<?= $m['id'] ?>" class="btn btn-outline btn-sm">Edit</a>
                        <?php endif; ?>
                        <a href="mitra_edit.php?id=<?= $m['id'] ?>" class="btn btn-outline btn-sm">Scorecard</a>
                        <?php if (in_array(currentUser()['role'], ['admin','validator'])): ?>
                        <a href="mitra_validasi.php?id=<?= $m['id'] ?>" class="btn btn-primary btn-sm">Validasi</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
// Multi-selection dropdown toggle
var btnFilterBidang = document.getElementById('btnFilterBidang');
var menuFilterBidang = document.getElementById('menuFilterBidang');

if (btnFilterBidang && menuFilterBidang) {
    btnFilterBidang.addEventListener('click', function(e) {
        e.stopPropagation();
        menuFilterBidang.style.display = (menuFilterBidang.style.display === 'none' || menuFilterBidang.style.display === '') ? 'block' : 'none';
    });
    document.addEventListener('click', function(e) {
        if (!menuFilterBidang.contains(e.target) && e.target !== btnFilterBidang) {
            menuFilterBidang.style.display = 'none';
        }
    });
}

function selectAllBidangFilter(val) {
    document.querySelectorAll('.chk-filter-bidang').forEach(function(cb) {
        cb.checked = val;
    });
    applyFilters();
}

function clearBidangFilter() {
    selectAllBidangFilter(false);
}

function applyFilters() {
    var q = (document.getElementById('fSearch').value || '').toLowerCase().trim();
    var p = document.getElementById('fPortofolio').value;
    var k = document.getElementById('fKategori') ? document.getElementById('fKategori').value : '';
    var pos = document.getElementById('fPosisi').value;
    var rek = document.getElementById('fRekomendasi').value;
    var s = document.getElementById('fStatus').value;

    // Kumpulkan multi-selection bidang
    var checkedBidangs = [];
    document.querySelectorAll('.chk-filter-bidang:checked').forEach(function(cb) {
        checkedBidangs.push(cb.value);
    });

    // Update label tombol filter bidang
    var lbl = document.getElementById('labelFilterBidang');
    if (lbl) {
        if (checkedBidangs.length === 0) {
            lbl.textContent = 'Semua Bidang';
            lbl.title = 'Semua Bidang';
        } else if (checkedBidangs.length === 1) {
            lbl.textContent = 'Bidang: ' + checkedBidangs[0];
            lbl.title = checkedBidangs[0];
        } else {
            lbl.textContent = 'Bidang (' + checkedBidangs.length + ')';
            lbl.title = checkedBidangs.join(', ');
        }
    }

    var visibleCount = 0;
    var rows = document.querySelectorAll('#tblPortofolio tbody tr:not(#emptyRow):not(#noFilterMatchRow)');
    rows.forEach(function(tr) {
        var matchQ = !q || tr.textContent.toLowerCase().indexOf(q) !== -1;
        var matchP = !p || tr.dataset.portofolio === p;
        var matchK = !k || tr.dataset.kategori === k;

        // Cek multi-selection bidang
        var matchB = true;
        if (checkedBidangs.length > 0) {
            var rowBidangRaw = tr.dataset.bidang || '';
            var rowBidangs = rowBidangRaw.split(',').map(function(item){ return item.trim(); });
            matchB = checkedBidangs.some(function(b){ return rowBidangs.indexOf(b) !== -1; });
        }

        var matchPos = !pos;
        if (pos) {
            var pUpper = (tr.dataset.posisi || '').toUpperCase().trim();
            var resolvedPos = 'BELUM DAPAT DITENTUKAN';
            if (pUpper && pUpper !== '-' && pUpper.indexOf('BELUM') === -1 && pUpper.indexOf('MASA IMPLEMENTASI AWAL') === -1 && pUpper.indexOf('TIDAK') === -1) {
                if (pUpper.indexOf('BERDAMPAK') !== -1 || pUpper.indexOf('DAMPAK') !== -1) {
                    resolvedPos = 'BERDAMPAK';
                } else if (pUpper.indexOf('OUTCOME') !== -1) {
                    resolvedPos = 'OUTCOME TERBENTUK';
                } else if (pUpper.indexOf('OUTPUT') !== -1) {
                    resolvedPos = 'OUTPUT TERSEDIA';
                } else if (pUpper.indexOf('AKTIF') !== -1 || pUpper.indexOf('IMPLEMENTASI') !== -1) {
                    resolvedPos = 'AKTIF';
                }
            }
            matchPos = (resolvedPos === pos);
        }

        var matchRek = !rek || (tr.dataset.rekomendasiKey === rek);
        var matchS = !s || tr.dataset.status === s;
        var isVisible = (matchQ && matchP && matchB && matchK && matchPos && matchRek && matchS);
        tr.style.display = isVisible ? '' : 'none';
        if (isVisible) visibleCount++;
    });
    var noMatch = document.getElementById('noFilterMatchRow');
    if (noMatch) {
        noMatch.style.display = (rows.length > 0 && visibleCount === 0) ? '' : 'none';
    }
}

document.getElementById('fSearch').addEventListener('input', applyFilters);
document.getElementById('fPortofolio').addEventListener('change', applyFilters);
if (document.getElementById('fKategori')) {
    document.getElementById('fKategori').addEventListener('change', applyFilters);
}
document.getElementById('fPosisi').addEventListener('change', applyFilters);
document.getElementById('fRekomendasi').addEventListener('change', applyFilters);
document.getElementById('fStatus').addEventListener('change', applyFilters);

function resetFilters() {
    document.getElementById('fSearch').value = '';
    document.getElementById('fPortofolio').value = '';
    clearBidangFilter();
    if (document.getElementById('fKategori')) {
        document.getElementById('fKategori').value = '';
    }
    document.getElementById('fPosisi').value = '';
    document.getElementById('fRekomendasi').value = '';
    document.getElementById('fStatus').value = '';
    applyFilters();
}

document.addEventListener('DOMContentLoaded', applyFilters);
window.addEventListener('pageshow', applyFilters);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
