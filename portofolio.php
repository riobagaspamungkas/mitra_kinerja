<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$userRole = $user['role'] ?? 'pemeriksa';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
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

// Tambah naskah baru (?action=add)
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

                header("Location: portofolio.php?msg=created&edit_id=$mid");
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Gagal menambahkan naskah baru: ' . $e->getMessage();
            }
        }
    }
}

// Edit naskah dan rencana kerja (?action=edit&id=...)
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
                    header('Location: portofolio.php?msg=rk_added&edit_id=' . $id . '&tab=rk'); exit;
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
                    header('Location: portofolio.php?msg=rk_updated&edit_id=' . $id . '&tab=rk'); exit;
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
                    header('Location: portofolio.php?msg=rk_deleted&edit_id=' . $id . '&tab=rk'); exit;
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
                    header('Location: portofolio.php?msg=updated&edit_id=' . $id . '&tab=info'); exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = 'Gagal memperbarui naskah: ' . $e->getMessage();
                }
            }
        }
    }
}



// Render view portofolio dan modal
$all = getAllMitraSummary($pdo);
$pageTitle = 'Portofolio';

// Ambil rincian lengkap mitra dan rencana kerja untuk modal interaktif
$allMitraDetails = $pdo->query("SELECT * FROM mitra_kinerja ORDER BY kode")->fetchAll(PDO::FETCH_ASSOC);
$allRkDetails = $pdo->query("SELECT * FROM rencana_kerja ORDER BY tanggal_mulai ASC")->fetchAll(PDO::FETCH_ASSOC);

$partnersData = [];
foreach ($allMitraDetails as $mItem) {
    $mid = (int)$mItem['id'];
    $partnersData[$mid] = $mItem;
    $partnersData[$mid]['rencana_kerja'] = [];
}
foreach ($allRkDetails as $rkItem) {
    $mid = (int)$rkItem['mitra_id'];
    if (isset($partnersData[$mid])) {
        $partnersData[$mid]['rencana_kerja'][] = $rkItem;
    }
}

// Flash messages
$msg = $_GET['msg'] ?? '';
if ($msg === 'created') {
    $success = 'Naskah kerja sama baru berhasil didaftarkan ke sistem.';
} elseif ($msg === 'updated') {
    $success = 'Perubahan data naskah kerja sama berhasil disimpan.';
} elseif ($msg === 'rk_added') {
    $success = 'Rencana kerja tahunan berhasil ditambahkan.';
} elseif ($msg === 'rk_updated') {
    $success = 'Rencana kerja tahunan berhasil diperbarui.';
} elseif ($msg === 'rk_deleted') {
    $success = 'Rencana kerja tahunan berhasil dihapus.';
}

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
        <button type="button" onclick="openTambahNaskahModal()" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;">
            <?= uiIcon('plus', 13) ?> Tambah Naskah
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-info" style="margin-bottom:16px;animation:pageFadeSlideIn 0.2s cubic-bezier(0.16,1,0.3,1);">
    <?= h($success) ?>
</div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-warning" style="margin-bottom:16px;">
    <?php foreach ($errors as $e): ?><div><?= h($e) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="filters">
        <!-- Search Input -->
        <div style="position:relative;display:inline-block;min-width:240px;">
            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none;display:inline-flex;align-items:center;">
                <?= uiIcon('search', 14) ?>
            </span>
            <input type="text" id="fSearch" placeholder="Cari kode / mitra / naskah / bidang..." style="padding:7px 10px 7px 32px;border:1px solid var(--border);border-radius:6px;font-size:13px;width:100%;box-sizing:border-box;">
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
                <th>Naskah</th>
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
            <tr id="emptyRow"><td colspan="14"><?= uiEmptyState('Belum Ada Data Kerja Sama', 'Naskah kerja sama belum tersedia atau belum ditambahkan.', 'file-text') ?></td></tr>
        <?php else: ?>
            <tr id="noFilterMatchRow" style="display:none;"><td colspan="14"><?= uiEmptyState('Tidak Ada Data yang Sesuai', 'Tidak ditemukan naskah yang cocok dengan filter. Gunakan Reset Filter untuk memuat kembali.', 'search') ?></td></tr>
            <?php foreach ($all as $s): $m = $s['mitra']; ?>
            <tr data-id="<?= (int)$m['id'] ?>" data-portofolio="<?= h($m['portofolio']) ?>" data-bidang="<?= h($m['bidang'] ?? 'AHU') ?>" data-kategori="<?= h($s['kategori']) ?>" data-status="<?= h($s['status_scorecard']) ?>" data-posisi="<?= h($s['posisi_portofolio']) ?>" data-rekomendasi="<?= h($s['rekomendasi']) ?>" data-rekomendasi-key="<?= h(ekstrakKeywordRekomendasi($s['rekomendasi'])) ?>">
                <td>
                    <strong><?= h($m['kode']) ?></strong>
                    <?php if (!empty($m['nomor_naskah'])): ?>
                    <div class="muted" style="font-size:10px;"><?= h($m['nomor_naskah']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= h($m['portofolio']) ?></td>
                <td><strong><?= h($m['nama_mitra']) ?></strong></td>
                <td class="port-naskah-cell">
                    <span class="port-naskah-title" title="<?= h($m['judul'] ?: '-') ?>">
                        <?= h($m['judul'] ?: '-') ?>
                    </span>
                    <?php if (!empty($m['file_naskah'])): ?>
                    <div style="margin-top:4px;">
                        <a href="<?= h($m['file_naskah']) ?>" target="_blank" class="badge badge-primary" style="font-size:9.5px;text-decoration:none;display:inline-flex;align-items:center;gap:3px;padding:2px 6px;">
                            <?= uiIcon('file-text', 10) ?> Scan PDF
                        </a>
                    </div>
                    <?php endif; ?>
                </td>
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
                    $rekHead = trim(explode("
", trim($s['rekomendasi'] ?? '-'))[0]) ?: '-';
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
                        <button type="button" class="btn btn-outline btn-sm" onclick="openEditNaskahModal(<?= $m['id'] ?>)">Edit</button>
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

    <!-- Table Pagination -->
    <div id="portPaginationWrap" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">
        <div id="portPageInfo" style="font-size:12.5px;color:#64748b;font-weight:500;">
            Menampilkan 1 – <?= min(10, count($all)) ?> dari <?= count($all) ?> naskah
        </div>
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#64748b;">
                <span>Tampilkan:</span>
                <select id="portPageSize" onchange="changePortPageSize(this.value)" style="padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:5px;background:#fff;cursor:pointer;">
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="all">Semua</option>
                </select>
            </div>
            <div id="portPaginationNav" style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;"></div>
        </div>
    </div>
</div>

<!-- MODAL 1: TAMBAH NASKAH BARU                                                -->
<div id="modalTambahNaskah" class="app-modal-overlay">
    <div class="app-modal-box" style="max-width: 820px;">
        <div class="app-modal-header">
            <div>
                <h2 style="margin:0;font-size:18px;color:#0f172a;display:inline-flex;align-items:center;gap:8px;">
                    <?= uiIcon('plus', 16) ?> Tambah Naskah Kerja Sama Baru
                </h2>
                <div class="muted" style="font-size:12px;">Registrasi dokumen PKS/MoU dan rincian stakeholder ke dalam sistem</div>
            </div>
            <button type="button" onclick="closeTambahNaskahModal()" style="background:none;border:none;font-size:24px;color:#64748b;cursor:pointer;padding:2px 8px;line-height:1;">&times;</button>
        </div>

        <form method="post" enctype="multipart/form-data" id="formTambahNaskah" style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            
            <div class="app-modal-body">
                <div class="form-grid">
                <div class="field">
                    <label style="font-weight:600;">Kode Naskah (P11, C06, dst) *</label>
                    <input type="text" name="kode" maxlength="5" required placeholder="Contoh: P11" value="">
                </div>
                <div class="field">
                    <label style="font-weight:600;">Nomor Naskah Resmi (Form Number)</label>
                    <input type="text" name="nomor_naskah" placeholder="Contoh: W.32.UM.01.01-123 Tahun 2026" value="">
                </div>
                <div class="field">
                    <label style="font-weight:600;">Portofolio Kerja Sama *</label>
                    <select name="portofolio" required>
                        <option value="Pilot Utama">Pilot Utama (Scorecard Evaluasi)</option>
                        <option value="Cadangan">Cadangan (Monitoring)</option>
                    </select>
                </div>
                <div class="field">
                    <label style="font-weight:600;">Bentuk Naskah *</label>
                    <select name="jenis" required>
                        <option value="PKS">PKS (Perjanjian Kerja Sama)</option>
                        <option value="MoU">MoU (Nota Kesepahaman)</option>
                    </select>
                </div>

                <!-- Dropdown Multiple Select Bidang -->
                <div class="field" style="grid-column: 1 / -1;">
                    <label style="font-weight:600;">Bidang Kerja Sama *</label>
                    <div class="form-multiselect-wrap" id="msWrapTambahBidang">
                        <button type="button" class="form-multiselect-btn" id="msBtnTambahBidang" onclick="toggleMultiSelectDropdown('msMenuTambahBidang')">
                            <span id="msLabelTambahBidang" style="color:#64748b;">Pilih Bidang Kerja Sama...</span>
                            <?= uiIcon('chevron-down', 13) ?>
                        </button>
                        <div class="form-multiselect-dropdown" id="msMenuTambahBidang" style="display:none;">
                            <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 6px 8px;border-bottom:1px solid #f1f5f9;margin-bottom:6px;">
                                <span style="font-size:11px;font-weight:700;color:#64748b;">PILIH SATU ATAU LEBIH</span>
                                <button type="button" onclick="selectAllFormBidang('tambah', true)" style="background:none;border:none;color:#2563eb;font-size:11px;cursor:pointer;padding:0;">Pilih Semua</button>
                            </div>
                            <?php foreach ($bidangOptions as $bKey => $bLabel): ?>
                            <label style="display:flex;align-items:center;gap:8px;padding:5px 6px;border-radius:4px;cursor:pointer;font-size:12.5px;color:#334155;user-select:none;">
                                <input type="checkbox" name="bidang[]" value="<?= $bKey ?>" class="chk-tambah-bidang" onchange="updateMultiSelectLabel('tambah')">
                                <span><?= $bLabel ?></span>
                            </label>
                            <?php endforeach; ?>
                            <div style="border-top:1px solid #f1f5f9;margin-top:6px;padding-top:6px;text-align:right;">
                                <button type="button" onclick="selectAllFormBidang('tambah', false)" style="background:none;border:none;color:#dc2626;font-size:11px;cursor:pointer;padding:0;">Reset Pilihan</button>
                            </div>
                        </div>
                    </div>
                    <div class="muted" style="font-size:11.5px;margin-top:4px;">Pilih satu atau beberapa bidang pengampu di lingkungan Kanwil Kepri.</div>
                </div>

                <div class="field" style="grid-column: 1 / -1;">
                    <label style="font-weight:600;">Nama Mitra Kerja Sama *</label>
                    <input type="text" name="nama_mitra" required placeholder="Contoh: Universitas Maritim Raja Ali Haji (UMRAH)" value="">
                </div>
                <div class="field" style="grid-column: 1 / -1;">
                    <label style="font-weight:600;">Judul Naskah Kerja Sama *</label>
                    <textarea name="judul" rows="2" required placeholder="Tuliskan judul lengkap naskah kerja sama"></textarea>
                </div>
                <div class="field" style="grid-column: 1 / -1;">
                    <label style="font-weight:600;">MoU Induk / Kerja Sama Utama (Opsional)</label>
                    <select name="pks_induk_id">
                        <option value="">-- Tidak Berinduk (MoU Sendiri atau PKS Mandiri) --</option>
                        <?php foreach ($mouOptions as $mo): ?>
                        <option value="<?= $mo['id'] ?>"><?= h($mo['kode']) ?> - <?= h($mo['nama_mitra']) ?>: <?= h(singkat($mo['judul'], 60)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label style="font-weight:600;">Status Verifikasi Tanggal *</label>
                    <select name="status_tanggal">
                        <option value="DEFINITIF">Definitif (Tanggal naskah pasti)</option>
                        <option value="ESTIMASI">Estimasi (Dalam proses penetapan)</option>
                    </select>
                </div>
                <div class="field">
                    <label style="font-weight:600;">Tanggal Mulai Berlaku *</label>
                    <input type="date" name="tanggal_mulai" required value="<?= date('Y-m-d') ?>">
                </div>
                <div class="field">
                    <label style="font-weight:600;">Tanggal Berakhir *</label>
                    <input type="date" name="tanggal_berakhir" required value="<?= date('Y-m-d', strtotime('+3 years')) ?>">
                </div>
                <div class="field">
                    <label style="font-weight:600;">Frekuensi Evaluasi Scorecard</label>
                    <select name="evaluasi_per_tahun">
                        <option value="1">1 Kali / Tahun (Tahunan)</option>
                        <option value="2">2 Kali / Tahun (Semesteran)</option>
                        <option value="4">4 Kali / Tahun (Triwulanan)</option>
                    </select>
                </div>

                <!-- PIC Stakeholders -->
                <div class="field">
                    <label style="font-weight:600;">Nama PIC Internal (Kanwil)</label>
                    <input type="text" name="pic_internal" placeholder="Pejabat / Staf Pengampu" value="">
                </div>
                <div class="field">
                    <label style="font-weight:600;">No. Telp / HP PIC Internal (Form Number)</label>
                    <input type="text" name="pic_internal_no" placeholder="Contoh: 0812xxxxxxx" value="">
                </div>
                <div class="field">
                    <label style="font-weight:600;">Nama PIC Mitra</label>
                    <input type="text" name="pic_mitra" placeholder="Nama Narahubung Mitra" value="">
                </div>
                <div class="field">
                    <label style="font-weight:600;">No. Telp / HP PIC Mitra (Form Number)</label>
                    <input type="text" name="pic_mitra_no" placeholder="Contoh: 0813xxxxxxx" value="">
                </div>

                <!-- File Berkas -->
                <div class="field">
                    <label style="font-weight:600;">Upload Scan Berkas Naskah (PDF)</label>
                    <input type="file" name="file_naskah" accept=".pdf">
                    <div class="muted" style="font-size:11px;margin-top:3px;">Format PDF (Maks. 15MB). Terhubung otomatis ke Bukti Elemen 1.</div>
                </div>
                <div class="field">
                    <label style="font-weight:600;">Upload Foto Dokumentasi (Opsional)</label>
                    <input type="file" name="foto_kerjasama" accept=".jpg,.jpeg,.png,.webp">
                    <div class="muted" style="font-size:11px;margin-top:3px;">Format Gambar (JPG/PNG/WebP, Maks. 5MB).</div>
                </div>
            </div>

                </div>
            </div>

            <div class="app-modal-footer">
                <button type="button" onclick="closeTambahNaskahModal()" class="btn btn-outline">Batal</button>
                <button type="submit" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;">
                    <?= uiIcon('check-circle', 14) ?> Simpan Naskah Baru
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: EDIT NASKAH & RENCANA KERJA TAHUNAN                               -->
<div id="modalEditNaskah" class="app-modal-overlay">
    <div class="app-modal-box" style="max-width: 880px;">
        <div class="app-modal-header">
            <div>
                <h2 style="margin:0;font-size:18px;color:#0f172a;display:inline-flex;align-items:center;gap:8px;">
                    <span id="editModalPartnerKode" class="badge badge-primary" style="font-size:13px;padding:3px 8px;">-</span>
                    <span id="editModalPartnerNama">Edit Naskah Kerja Sama</span>
                </h2>
                <div class="muted" style="font-size:12px;">Edit naskah kerja sama dan kelola rencana kerja tahunan</div>
            </div>
            <button type="button" onclick="closeEditNaskahModal()" style="background:none;border:none;font-size:24px;color:#64748b;cursor:pointer;padding:2px 8px;line-height:1;">&times;</button>
        </div>

        <!-- Modal Tabs (Pinned) -->
        <div class="modal-tab-nav" style="padding:0 22px;margin-bottom:0;background:#fff;border-bottom:1px solid #e2e8f0;flex-shrink:0;">
            <button type="button" class="modal-tab-btn active" id="tabBtnEditInfo" onclick="switchEditModalTab('info')">
                <?= uiIcon('file-text', 14) ?> Identitas & Berkas Naskah
            </button>
            <button type="button" class="modal-tab-btn" id="tabBtnEditRk" onclick="switchEditModalTab('rk')">
                <?= uiIcon('calendar', 14) ?> Rencana Kerja Tahunan (<span id="editModalRkCount">0</span>)
            </button>
        </div>

        <!-- TAB 1: IDENTITAS & BERKAS NASKAH -->
        <div id="tabContentEditInfo" style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;">
            <form method="post" enctype="multipart/form-data" id="formEditNaskah" style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id" value="">

                <div class="app-modal-body">
                    <div class="form-grid">
                    <div class="field">
                        <label style="font-weight:600;">Kode Naskah</label>
                        <input type="text" id="edit_kode_display" disabled style="background:#f8fafc;color:#64748b;font-weight:700;">
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">Nomor Naskah Resmi (Form Number)</label>
                        <input type="text" name="nomor_naskah" id="edit_nomor_naskah" placeholder="Nomor naskah resmi">
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">Portofolio *</label>
                        <select name="portofolio" id="edit_portofolio" required>
                            <option value="Pilot Utama">Pilot Utama</option>
                            <option value="Cadangan">Cadangan</option>
                        </select>
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">Bentuk Naskah *</label>
                        <select name="jenis" id="edit_jenis" required>
                            <option value="PKS">PKS (Perjanjian Kerja Sama)</option>
                            <option value="MoU">MoU (Nota Kesepahaman)</option>
                        </select>
                    </div>

                    <!-- Dropdown Multiple Select Bidang Edit -->
                    <div class="field" style="grid-column: 1 / -1;">
                        <label style="font-weight:600;">Bidang Kerja Sama *</label>
                        <div class="form-multiselect-wrap" id="msWrapEditBidang">
                            <button type="button" class="form-multiselect-btn" id="msBtnEditBidang" onclick="toggleMultiSelectDropdown('msMenuEditBidang')">
                                <span id="msLabelEditBidang" style="color:#64748b;">Pilih Bidang Kerja Sama...</span>
                                <?= uiIcon('chevron-down', 13) ?>
                            </button>
                            <div class="form-multiselect-dropdown" id="msMenuEditBidang" style="display:none;">
                                <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 6px 8px;border-bottom:1px solid #f1f5f9;margin-bottom:6px;">
                                    <span style="font-size:11px;font-weight:700;color:#64748b;">PILIH SATU ATAU LEBIH</span>
                                    <button type="button" onclick="selectAllFormBidang('edit', true)" style="background:none;border:none;color:#2563eb;font-size:11px;cursor:pointer;padding:0;">Pilih Semua</button>
                                </div>
                                <?php foreach ($bidangOptions as $bKey => $bLabel): ?>
                                <label style="display:flex;align-items:center;gap:8px;padding:5px 6px;border-radius:4px;cursor:pointer;font-size:12.5px;color:#334155;user-select:none;">
                                    <input type="checkbox" name="bidang[]" value="<?= $bKey ?>" class="chk-edit-bidang" onchange="updateMultiSelectLabel('edit')">
                                    <span><?= $bLabel ?></span>
                                </label>
                                <?php endforeach; ?>
                                <div style="border-top:1px solid #f1f5f9;margin-top:6px;padding-top:6px;text-align:right;">
                                    <button type="button" onclick="selectAllFormBidang('edit', false)" style="background:none;border:none;color:#dc2626;font-size:11px;cursor:pointer;padding:0;">Reset Pilihan</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="field" style="grid-column: 1 / -1;">
                        <label style="font-weight:600;">Nama Mitra Kerja Sama *</label>
                        <input type="text" name="nama_mitra" id="edit_nama_mitra" required>
                    </div>
                    <div class="field" style="grid-column: 1 / -1;">
                        <label style="font-weight:600;">Judul Naskah Kerja Sama *</label>
                        <textarea name="judul" id="edit_judul" rows="2" required></textarea>
                    </div>
                    <div class="field" style="grid-column: 1 / -1;">
                        <label style="font-weight:600;">MoU Induk / Kerja Sama Utama</label>
                        <select name="pks_induk_id" id="edit_pks_induk_id">
                            <option value="">-- Tidak Berinduk (Mandiri) --</option>
                            <?php foreach ($mouOptions as $mo): ?>
                            <option value="<?= $mo['id'] ?>"><?= h($mo['kode']) ?> - <?= h($mo['nama_mitra']) ?>: <?= h(singkat($mo['judul'], 60)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">Status Verifikasi Tanggal *</label>
                        <select name="status_tanggal" id="edit_status_tanggal">
                            <option value="DEFINITIF">Definitif (Pasti)</option>
                            <option value="ESTIMASI">Estimasi (Proses)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">Tanggal Mulai Berlaku *</label>
                        <input type="date" name="tanggal_mulai" id="edit_tanggal_mulai" required>
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">Tanggal Berakhir *</label>
                        <input type="date" name="tanggal_berakhir" id="edit_tanggal_berakhir" required>
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">Frekuensi Evaluasi Scorecard</label>
                        <select name="evaluasi_per_tahun" id="edit_evaluasi_per_tahun">
                            <option value="1">1 Kali / Tahun (Tahunan)</option>
                            <option value="2">2 Kali / Tahun (Semesteran)</option>
                            <option value="4">4 Kali / Tahun (Triwulanan)</option>
                        </select>
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">Sumber Data Baseline</label>
                        <select name="sumber_baseline" id="edit_sumber_baseline">
                            <option value="LAPORAN RESMI">Laporan Resmi</option>
                            <option value="DATA UNIT">Data Unit</option>
                            <option value="ESTIMASI TERVERIFIKASI">Estimasi Terverifikasi</option>
                            <option value="SURVEI AWAL">Survei Awal</option>
                        </select>
                    </div>

                    <!-- PIC Stakeholders -->
                    <div class="field">
                        <label style="font-weight:600;">Nama PIC Internal (Kanwil)</label>
                        <input type="text" name="pic_internal" id="edit_pic_internal">
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">No. Telp / HP PIC Internal (Form Number)</label>
                        <input type="text" name="pic_internal_no" id="edit_pic_internal_no">
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">Nama PIC Mitra</label>
                        <input type="text" name="pic_mitra" id="edit_pic_mitra">
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">No. Telp / HP PIC Mitra (Form Number)</label>
                        <input type="text" name="pic_mitra_no" id="edit_pic_mitra_no">
                    </div>

                    <!-- File Berkas -->
                    <div class="field">
                        <label style="font-weight:600;">Upload Scan Berkas Naskah (PDF)</label>
                        <input type="file" name="file_naskah" accept=".pdf">
                        <div id="edit_file_preview" style="margin-top:4px;font-size:12px;"></div>
                    </div>
                    <div class="field">
                        <label style="font-weight:600;">Upload Foto Dokumentasi</label>
                        <input type="file" name="foto_kerjasama" accept=".jpg,.jpeg,.png,.webp">
                        <div id="edit_foto_preview" style="margin-top:4px;font-size:12px;"></div>
                    </div>
                </div>

                    </div>
                </div>

                <div class="app-modal-footer">
                    <button type="button" onclick="closeEditNaskahModal()" class="btn btn-outline">Batal</button>
                    <button type="submit" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;">
                        <?= uiIcon('check-circle', 14) ?> Simpan Perubahan Naskah
                    </button>
                </div>
            </form>
        </div>

        <!-- TAB 2: RENCANA KERJA TAHUNAN -->
        <div id="tabContentEditRk" style="display:none;flex-direction:column;flex:1;min-height:0;overflow:hidden;">
            <div class="app-modal-body">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <div>
                    <h3 style="margin:0;font-size:15px;color:#0f172a;">Rencana Kerja Tahunan</h3>
                    <div class="muted" style="font-size:12px;">Target operasional tahunan dan kegiatan riil turunan naskah kerja sama</div>
                </div>
                <button type="button" class="btn btn-primary btn-sm" onclick="toggleAddRkBox(true)" style="display:inline-flex;align-items:center;gap:5px;">
                    <?= uiIcon('plus', 13) ?> Tambah Rencana Kerja
                </button>
            </div>

            <!-- Form Tambah Rencana Kerja (Inline Drop-box) -->
            <div id="boxAddRk" style="display:none;background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:16px;margin-bottom:16px;animation:dropdownFadeSlide 0.18s cubic-bezier(0.16,1,0.3,1);">
                <h4 style="margin:0 0 12px 0;font-size:14px;color:#1e293b;">Formulir Rencana Kerja Baru</h4>
                <form method="post" id="formAddRk">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="sub_action" value="add_rencana_kerja">
                    <input type="hidden" name="id" id="add_rk_mitra_id" value="">

                    <div class="form-grid">
                        <div class="field" style="grid-column:1/-1;">
                            <label style="font-weight:600;">Judul Rencana Kerja *</label>
                            <input type="text" name="judul_rencana" required placeholder="Contoh: Penyelenggaraan Sosialisasi AHU dan Pendaftaran Hak Cipta Bersama">
                        </div>
                        <div class="field" style="grid-column:1/-1;">
                            <label style="font-weight:600;">Ruang Lingkup & Target Kegiatan</label>
                            <textarea name="ruang_lingkup" rows="2" placeholder="Uraikan target, output konkret, atau indikator kegiatan tahunan"></textarea>
                        </div>
                        <div class="field">
                            <label style="font-weight:600;">Tanggal Mulai</label>
                            <input type="date" name="tanggal_mulai" value="<?= date('Y-01-01') ?>">
                        </div>
                        <div class="field">
                            <label style="font-weight:600;">Tanggal Selesai</label>
                            <input type="date" name="tanggal_selesai" value="<?= date('Y-12-31') ?>">
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
                        <button type="button" onclick="toggleAddRkBox(false)" class="btn btn-outline btn-sm">Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm"><?= uiIcon('check-circle', 13) ?> Simpan Rencana Kerja</button>
                    </div>
                </form>
            </div>

            <!-- Table Rencana Kerja -->
            <div class="table-wrap">
                <table id="tblEditModalRk" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:140px;">Tahun / Periode</th>
                            <th>Judul Rencana Kerja</th>
                            <th>Ruang Lingkup & Target</th>
                            <th style="width:110px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tbodyEditModalRk">
                        <!-- Populated dynamically via JS -->
                    </tbody>
                </table>
            </div>
            </div>
            <div class="app-modal-footer">
                <button type="button" onclick="closeEditNaskahModal()" class="btn btn-outline">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- NESTED MODAL: EDIT RENCANA KERJA                                           -->
<div id="modalNestedEditRk" class="app-modal-overlay">
    <div class="app-modal-box" style="max-width: 600px;">
        <div class="app-modal-header">
            <h3 style="margin:0;font-size:16px;color:#0f172a;">Edit Rencana Kerja Tahunan</h3>
            <button type="button" onclick="closeNestedEditRk()" style="background:none;border:none;font-size:22px;color:#64748b;cursor:pointer;padding:2px 8px;line-height:1;">&times;</button>
        </div>
        <form method="post" id="formNestedEditRk" style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;margin:0;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="sub_action" value="edit_rencana_kerja">
            <input type="hidden" name="id" id="nested_rk_mitra_id" value="">
            <input type="hidden" name="rk_id" id="nested_rk_id" value="">

            <div class="app-modal-body">
                <div class="form-grid">
                <div class="field" style="grid-column:1/-1;">
                    <label style="font-weight:600;">Judul Rencana Kerja *</label>
                    <input type="text" name="judul_rencana" id="nested_rk_judul" required>
                </div>
                <div class="field" style="grid-column:1/-1;">
                    <label style="font-weight:600;">Ruang Lingkup & Target Kegiatan</label>
                    <textarea name="ruang_lingkup" id="nested_rk_ruang" rows="3"></textarea>
                </div>
                <div class="field">
                    <label style="font-weight:600;">Tanggal Mulai</label>
                    <input type="date" name="tanggal_mulai" id="nested_rk_mulai">
                </div>
                <div class="field">
                    <label style="font-weight:600;">Tanggal Selesai</label>
                    <input type="date" name="tanggal_selesai" id="nested_rk_selesai">
                </div>
            </div>
                </div>
            </div>
            <div class="app-modal-footer">
                <button type="button" onclick="closeNestedEditRk()" class="btn btn-outline btn-sm">Batal</button>
                <button type="submit" class="btn btn-primary btn-sm"><?= uiIcon('check-circle', 13) ?> Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- Form Hidden untuk Hapus Rencana Kerja -->
<form method="post" id="formDeleteRk" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="sub_action" value="delete_rencana_kerja">
    <input type="hidden" name="id" id="delete_rk_mitra_id" value="">
    <input type="hidden" name="rk_id" id="delete_rk_id" value="">
</form>

<script>
// Data Mitra & Rencana Kerja untuk Modal Interaktif
window.PORT_PARTNERS_DATA = <?= json_encode($partnersData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Modal management
function openTambahNaskahModal() {
    var m = document.getElementById('modalTambahNaskah');
    if (!m) return;
    document.getElementById('formTambahNaskah').reset();
    selectAllFormBidang('tambah', false);
    m.style.display = 'flex';
}

function closeTambahNaskahModal() {
    var m = document.getElementById('modalTambahNaskah');
    if (m) m.style.display = 'none';
}

function openEditNaskahModal(id, initialTab) {
    var data = window.PORT_PARTNERS_DATA[id];
    if (!data) return;

    // Set header
    document.getElementById('editModalPartnerKode').textContent = data.kode || '-';
    document.getElementById('editModalPartnerNama').textContent = data.nama_mitra || 'Edit Naskah';

    // Set fields
    document.getElementById('edit_id').value = data.id;
    document.getElementById('add_rk_mitra_id').value = data.id;
    document.getElementById('edit_kode_display').value = data.kode || '';
    document.getElementById('edit_nomor_naskah').value = data.nomor_naskah || '';
    document.getElementById('edit_portofolio').value = data.portofolio || 'Pilot Utama';
    document.getElementById('edit_jenis').value = data.jenis || 'PKS';
    document.getElementById('edit_nama_mitra').value = data.nama_mitra || '';
    document.getElementById('edit_judul').value = data.judul || '';
    document.getElementById('edit_pks_induk_id').value = data.pks_induk_id || '';
    document.getElementById('edit_status_tanggal').value = data.status_tanggal || 'DEFINITIF';
    document.getElementById('edit_tanggal_mulai').value = data.tanggal_mulai || '';
    document.getElementById('edit_tanggal_berakhir').value = data.tanggal_berakhir || '';
    document.getElementById('edit_evaluasi_per_tahun').value = data.evaluasi_per_tahun || '1';
    document.getElementById('edit_sumber_baseline').value = data.sumber_baseline || 'LAPORAN RESMI';
    document.getElementById('edit_pic_internal').value = data.pic_internal || '';
    document.getElementById('edit_pic_internal_no').value = data.pic_internal_no || '';
    document.getElementById('edit_pic_mitra').value = data.pic_mitra || '';
    document.getElementById('edit_pic_mitra_no').value = data.pic_mitra_no || '';

    // Bidang multi-selection
    var curBidangs = (data.bidang || 'AHU').split(',').map(function(s){ return s.trim(); });
    setMultiSelectValues('edit', curBidangs);

    // Previews
    var fPrev = document.getElementById('edit_file_preview');
    if (data.file_naskah) {
        fPrev.innerHTML = '<a href="' + encodeURI(data.file_naskah) + '" target="_blank" class="badge badge-primary" style="text-decoration:none;display:inline-flex;align-items:center;gap:4px;"><?= uiIcon('file-text', 11) ?> Berkas Tersedia (Klik untuk Lihat)</a>';
    } else {
        fPrev.innerHTML = '<span class="muted" style="font-size:11px;">Belum ada scan naskah diunggah.</span>';
    }

    var imgPrev = document.getElementById('edit_foto_preview');
    if (data.foto_kerjasama) {
        imgPrev.innerHTML = '<img src="' + encodeURI(data.foto_kerjasama) + '" alt="Foto" style="max-height:48px;border-radius:4px;border:1px solid #cbd5e1;display:block;">';
    } else {
        imgPrev.innerHTML = '<span class="muted" style="font-size:11px;">Belum ada foto dokumentasi.</span>';
    }

    // Render Rencana Kerja
    renderEditModalRk(data);

    // Switch tab
    switchEditModalTab(initialTab || 'info');

    // Show modal
    var m = document.getElementById('modalEditNaskah');
    if (m) m.style.display = 'flex';
}

function closeEditNaskahModal() {
    var m = document.getElementById('modalEditNaskah');
    if (m) m.style.display = 'none';
    toggleAddRkBox(false);
}

function switchEditModalTab(tab) {
    var btnInfo = document.getElementById('tabBtnEditInfo');
    var btnRk = document.getElementById('tabBtnEditRk');
    var cInfo = document.getElementById('tabContentEditInfo');
    var cRk = document.getElementById('tabContentEditRk');

    if (tab === 'rk') {
        btnInfo.classList.remove('active');
        btnRk.classList.add('active');
        cInfo.style.display = 'none';
        cRk.style.display = 'flex';
    } else {
        btnRk.classList.remove('active');
        btnInfo.classList.add('active');
        cRk.style.display = 'none';
        cInfo.style.display = 'flex';
    }
}

function renderEditModalRk(data) {
    var rkList = data.rencana_kerja || [];
    document.getElementById('editModalRkCount').textContent = rkList.length;

    var tbody = document.getElementById('tbodyEditModalRk');
    tbody.innerHTML = '';

    if (rkList.length === 0) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td colspan="4" style="text-align:center;padding:24px;color:#64748b;font-size:12.5px;">Belum ada rencana kerja tahunan untuk naskah ini. Klik "+ Tambah Rencana Kerja" di atas untuk menambahkan.</td>';
        tbody.appendChild(tr);
        return;
    }

    rkList.forEach(function(rk) {
        var tr = document.createElement('tr');
        var periode = (rk.tanggal_mulai ? rk.tanggal_mulai.substring(0, 4) : '-') + (rk.tanggal_selesai ? ' s.d. ' + rk.tanggal_selesai.substring(0, 4) : '');
        var escapeTitle = (rk.judul_rencana || '').replace(/"/g, '&quot;');
        var escapeRuang = (rk.ruang_lingkup || '').replace(/"/g, '&quot;');

        tr.innerHTML = '<td><strong>' + periode + '</strong><br><span class="muted" style="font-size:10px;">' + (rk.tanggal_mulai || '') + '</span></td>' +
            '<td><div style="font-weight:600;font-size:12.5px;color:#1e293b;">' + (rk.judul_rencana || '-') + '</div></td>' +
            '<td><div style="font-size:12px;color:#475569;line-height:1.4;">' + (rk.ruang_lingkup || '-') + '</div></td>' +
            '<td><div style="display:flex;gap:4px;">' +
            '<button type="button" class="btn btn-outline btn-sm" onclick="openNestedEditRk(' + rk.id + ', ' + data.id + ')">Edit</button>' +
            '<button type="button" class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#fca5a5;" onclick="confirmDeleteRk(' + rk.id + ', ' + data.id + ')">Hapus</button>' +
            '</div></td>';
        tbody.appendChild(tr);
    });
}

function toggleAddRkBox(show) {
    var box = document.getElementById('boxAddRk');
    if (!box) return;
    box.style.display = show ? 'block' : 'none';
    if (show) {
        document.getElementById('formAddRk').reset();
        document.getElementById('add_rk_mitra_id').value = document.getElementById('edit_id').value;
    }
}

function openNestedEditRk(rkId, mitraId) {
    var data = window.PORT_PARTNERS_DATA[mitraId];
    if (!data) return;
    var rk = (data.rencana_kerja || []).find(function(item){ return item.id == rkId; });
    if (!rk) return;

    document.getElementById('nested_rk_mitra_id').value = mitraId;
    document.getElementById('nested_rk_id').value = rk.id;
    document.getElementById('nested_rk_judul').value = rk.judul_rencana || '';
    document.getElementById('nested_rk_ruang').value = rk.ruang_lingkup || '';
    document.getElementById('nested_rk_mulai').value = rk.tanggal_mulai || '';
    document.getElementById('nested_rk_selesai').value = rk.tanggal_selesai || '';

    var m = document.getElementById('modalNestedEditRk');
    if (m) m.style.display = 'flex';
}

function closeNestedEditRk() {
    var m = document.getElementById('modalNestedEditRk');
    if (m) m.style.display = 'none';
}

function confirmDeleteRk(rkId, mitraId) {
    if (!confirm('Apakah Anda yakin ingin menghapus rencana kerja tahunan ini?')) return;
    document.getElementById('delete_rk_mitra_id').value = mitraId;
    document.getElementById('delete_rk_id').value = rkId;
    document.getElementById('formDeleteRk').submit();
}

// Close modals when clicking outside
window.addEventListener('click', function(e) {
    var mAdd = document.getElementById('modalTambahNaskah');
    var mEdit = document.getElementById('modalEditNaskah');
    var mNested = document.getElementById('modalNestedEditRk');
    if (e.target === mAdd) closeTambahNaskahModal();
    if (e.target === mEdit) closeEditNaskahModal();
    if (e.target === mNested) closeNestedEditRk();
});

// Dropdown multi-select helpers
function toggleMultiSelectDropdown(menuId) {
    var menu = document.getElementById(menuId);
    if (!menu) return;
    var isShown = (menu.style.display === 'block');
    document.querySelectorAll('.form-multiselect-dropdown').forEach(function(d){ d.style.display = 'none'; });
    menu.style.display = isShown ? 'none' : 'block';
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.form-multiselect-wrap')) {
        document.querySelectorAll('.form-multiselect-dropdown').forEach(function(d){ d.style.display = 'none'; });
    }
});

function selectAllFormBidang(prefix, val) {
    document.querySelectorAll('.chk-' + prefix + '-bidang').forEach(function(cb) {
        cb.checked = val;
    });
    updateMultiSelectLabel(prefix);
}

function updateMultiSelectLabel(prefix) {
    var checked = [];
    document.querySelectorAll('.chk-' + prefix + '-bidang:checked').forEach(function(cb) {
        checked.push(cb.value);
    });
    var capFirst = prefix.charAt(0).toUpperCase() + prefix.slice(1);
    var lbl = document.getElementById('msLabel' + capFirst + 'Bidang');
    if (!lbl) return;

    if (checked.length === 0) {
        lbl.textContent = 'Pilih Bidang Kerja Sama...';
        lbl.style.color = '#64748b';
        lbl.title = '';
    } else if (checked.length === 1) {
        lbl.textContent = checked[0];
        lbl.style.color = '#0f172a';
        lbl.title = checked[0];
    } else {
        lbl.textContent = checked.join(', ') + ' (' + checked.length + ' Bidang)';
        lbl.style.color = '#0f172a';
        lbl.title = checked.join(', ');
    }
}

function setMultiSelectValues(prefix, valuesArray) {
    document.querySelectorAll('.chk-' + prefix + '-bidang').forEach(function(cb) {
        cb.checked = valuesArray.indexOf(cb.value) !== -1;
    });
    updateMultiSelectLabel(prefix);
}

// Filter tabel dan pagination
var portCurrentPage = 1;
var portPageSize = 10;
var portMatchedRows = [];

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

    var checkedBidangs = [];
    document.querySelectorAll('.chk-filter-bidang:checked').forEach(function(cb) {
        checkedBidangs.push(cb.value);
    });

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

    portMatchedRows = [];
    var rows = document.querySelectorAll('#tblPortofolio tbody tr:not(#emptyRow):not(#noFilterMatchRow)');
    rows.forEach(function(tr) {
        var matchQ = !q || tr.textContent.toLowerCase().indexOf(q) !== -1;
        var matchP = !p || tr.dataset.portofolio === p;
        var matchK = !k || tr.dataset.kategori === k;

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
        
        if (isVisible) {
            portMatchedRows.push(tr);
        } else {
            tr.style.display = 'none';
        }
    });

    var noMatch = document.getElementById('noFilterMatchRow');
    if (noMatch) {
        noMatch.style.display = (rows.length > 0 && portMatchedRows.length === 0) ? '' : 'none';
    }

    portCurrentPage = 1;
    renderPortPagination();
}

function changePortPageSize(sz) {
    portPageSize = (sz === 'all') ? 999999 : parseInt(sz, 10);
    portCurrentPage = 1;
    renderPortPagination();
}

function goToPortPage(p) {
    portCurrentPage = p;
    renderPortPagination();
}

function renderPortPagination() {
    var total = portMatchedRows.length;
    var wrap = document.getElementById('portPaginationWrap');
    var nav = document.getElementById('portPaginationNav');
    var info = document.getElementById('portPageInfo');
    if (!wrap || !nav || !info) return;

    if (total === 0) {
        wrap.style.display = 'none';
        return;
    }
    wrap.style.display = 'flex';

    var totalPages = Math.ceil(total / portPageSize) || 1;
    if (portCurrentPage > totalPages) portCurrentPage = totalPages;
    if (portCurrentPage < 1) portCurrentPage = 1;

    var start = (portCurrentPage - 1) * portPageSize;
    var end = Math.min(start + portPageSize, total);

    // Show only the page slice
    portMatchedRows.forEach(function(tr, idx) {
        tr.style.display = (idx >= start && idx < end) ? '' : 'none';
    });

    info.textContent = 'Menampilkan ' + (start + 1) + ' – ' + end + ' dari ' + total + ' naskah';

    // Build pagination buttons
    nav.innerHTML = '';
    if (totalPages <= 1) return;

    var btnFirst = document.createElement('button');
    btnFirst.type = 'button';
    btnFirst.className = 'port-page-btn';
    btnFirst.innerHTML = '&laquo;';
    btnFirst.title = 'Halaman Pertama';
    btnFirst.disabled = (portCurrentPage === 1);
    btnFirst.onclick = function(){ goToPortPage(1); };
    nav.appendChild(btnFirst);

    var btnPrev = document.createElement('button');
    btnPrev.type = 'button';
    btnPrev.className = 'port-page-btn';
    btnPrev.innerHTML = '&lsaquo;';
    btnPrev.title = 'Sebelumnya';
    btnPrev.disabled = (portCurrentPage === 1);
    btnPrev.onclick = function(){ goToPortPage(portCurrentPage - 1); };
    nav.appendChild(btnPrev);

    var maxButtons = 5;
    var startP = Math.max(1, portCurrentPage - 2);
    var endP = Math.min(totalPages, startP + maxButtons - 1);
    if (endP - startP < maxButtons - 1) {
        startP = Math.max(1, endP - maxButtons + 1);
    }

    for (var p = startP; p <= endP; p++) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'port-page-btn' + (p === portCurrentPage ? ' active' : '');
        btn.textContent = p;
        (function(page){
            btn.onclick = function(){ goToPortPage(page); };
        })(p);
        nav.appendChild(btn);
    }

    var btnNext = document.createElement('button');
    btnNext.type = 'button';
    btnNext.className = 'port-page-btn';
    btnNext.innerHTML = '&rsaquo;';
    btnNext.title = 'Berikutnya';
    btnNext.disabled = (portCurrentPage === totalPages);
    btnNext.onclick = function(){ goToPortPage(portCurrentPage + 1); };
    nav.appendChild(btnNext);

    var btnLast = document.createElement('button');
    btnLast.type = 'button';
    btnLast.className = 'port-page-btn';
    btnLast.innerHTML = '&raquo;';
    btnLast.title = 'Halaman Terakhir';
    btnLast.disabled = (portCurrentPage === totalPages);
    btnLast.onclick = function(){ goToPortPage(totalPages); };
    nav.appendChild(btnLast);
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

// Auto-open modal jika diminta via query param
document.addEventListener('DOMContentLoaded', function() {
    applyFilters();
    var params = new URLSearchParams(window.location.search);
    if (params.get('open_modal') === 'add' || params.get('action') === 'add') {
        openTambahNaskahModal();
    } else if (params.get('edit_id')) {
        openEditNaskahModal(parseInt(params.get('edit_id'), 10), params.get('tab') || 'info');
    } else if (params.get('action') === 'edit' && params.get('id')) {
        openEditNaskahModal(parseInt(params.get('id'), 10), params.get('tab') || 'info');
    }
});
window.addEventListener('pageshow', applyFilters);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
