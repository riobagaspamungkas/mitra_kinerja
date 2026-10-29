<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireRole(['admin', 'pimpinan', 'pengampu', 'pic']);

$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$canEdit = in_array($role, ['admin', 'pic', 'pengampu'], true);
$canDecide = in_array($role, ['admin', 'pimpinan'], true);

$errors = [];
$success = '';

// Definisi 18 pertanyaan uji (5 kriteria inti)
const GATE0_CRITERIA = [
    'K1' => [
        'kode' => 'K1',
        'nama' => 'Kesesuaian Strategis dan Kewenangan',
        'questions' => [
            1 => 'Apakah rencana kerja sama sesuai dengan tugas, fungsi, dan kewenangan organisasi?',
            2 => 'Apakah rencana kerja sama mendukung sasaran kinerja, prioritas, atau kebutuhan strategis organisasi?',
            3 => 'Apakah ruang lingkup yang direncanakan tidak melampaui kewenangan para pihak?'
        ]
    ],
    'K2' => [
        'kode' => 'K2',
        'nama' => 'Kebutuhan, Manfaat dan Daya Ungkit',
        'questions' => [
            4 => 'Apakah terdapat kebutuhan/permasalahan nyata yang ingin diselesaikan melalui kerja sama?',
            5 => 'Apakah manfaat yang diharapkan bagi organisasi, pelayanan, atau penerima manfaat dapat dijelaskan dengan jelas?',
            6 => 'Apakah output dan/atau outcome yang diharapkan dapat diidentifikasi sejak awal?',
            7 => 'Apakah kerja sama memiliki daya ungkit yang wajar dibanding sumber daya yang akan digunakan?'
        ]
    ],
    'K3' => [
        'kode' => 'K3',
        'nama' => 'Kelayakan Mitra',
        'questions' => [
            8 => 'Apakah calon mitra memiliki status/legalitas dan kewenangan yang memadai untuk bekerja sama?',
            9 => 'Apakah calon mitra memiliki kapasitas, kompetensi, sumber daya, atau jejaring yang relevan dengan ruang lingkup kerja sama?',
            10 => 'Apakah tidak terdapat informasi material mengenai integritas, reputasi, konflik kepentingan, atau kepatuhan yang menghambat kerja sama?'
        ]
    ],
    'K4' => [
        'kode' => 'K4',
        'nama' => 'Kesiapan Pelaksanaan',
        'questions' => [
            11 => 'Apakah peran dan kontribusi utama masing-masing pihak dapat dirumuskan secara jelas?',
            12 => 'Apakah terdapat unit/focal point sementara yang dapat mengawal proses sampai PIC definitif ditetapkan?',
            13 => 'Apakah kebutuhan sumber daya utama (SDM, waktu, data, sarana, dan/atau anggaran bila diperlukan) dapat dipenuhi?',
            14 => 'Apakah indikator awal, target, atau ukuran keberhasilan dapat dirumuskan sesuai karakter kerja sama?'
        ]
    ],
    'K5' => [
        'kode' => 'K5',
        'nama' => 'Risiko dan Keberlanjutan',
        'questions' => [
            15 => 'Apakah risiko hukum, reputasi, data, keuangan, operasional, atau strategis utama dapat diidentifikasi dan dikelola?',
            16 => 'Apakah terdapat mekanisme tindak lanjut setelah penandatanganan agar kerja sama tidak berhenti pada dokumen/seremoni?',
            17 => 'Apakah terdapat peluang keberlanjutan manfaat selama masa kerja sama?',
            18 => 'Apakah tidak terdapat hambatan material yang belum memiliki rencana mitigasi?'
        ]
    ]
];

// Definisi 7 trigger karakteristik khusus
const GATE0_TRIGGERS = [
    1 => [
        'judul' => 'Pihak Asing / Lembaga Internasional',
        'teks' => 'Melibatkan pihak asing, organisasi internasional, NGO asing, atau sumber pendanaan asing?',
        'reviu' => 'Review kewenangan, legalitas, due diligence, clearance/koordinasi yang diperlukan (Biro Hukerma).'
    ],
    2 => [
        'judul' => 'Komitmen Keuangan & Aset Fiskal',
        'teks' => 'Memuat komitmen keuangan, hibah, pembiayaan, aset, atau kewajiban fiskal/material?',
        'reviu' => 'Review keuangan/anggaran dan kewenangan komitmen.'
    ],
    3 => [
        'judul' => 'Data Pribadi & Akses Sistem',
        'teks' => 'Melibatkan data pribadi, data rahasia, data strategis, pertukaran database, atau akses sistem?',
        'reviu' => 'Review perlindungan data, keamanan informasi, akses dan kerahasiaan.'
    ],
    4 => [
        'judul' => 'Kekayaan Intelektual (KI/IP)',
        'teks' => 'Melibatkan Kekayaan Intelektual, lisensi, penggunaan karya, merek/logo, atau hasil ciptaan bersama?',
        'reviu' => 'Review hak, kepemilikan, penggunaan, publikasi dan lisensi.'
    ],
    5 => [
        'judul' => 'Teknologi & Integrasi API',
        'teks' => 'Melibatkan teknologi, integrasi sistem, akses API, perangkat, atau pengembangan aplikasi?',
        'reviu' => 'Review teknis, keamanan, interoperabilitas dan pengelolaan perubahan.'
    ],
    6 => [
        'judul' => 'Sensitivitas Strategis / Risiko Reputasi',
        'teks' => 'Berpotensi menimbulkan risiko hukum, reputasi, konflik kepentingan, atau sensitivitas strategis tinggi?',
        'reviu' => 'Review hukum, kepatuhan, integritas/risk owner sesuai isu.'
    ],
    7 => [
        'judul' => 'Publikasi & Identitas Institusi',
        'teks' => 'Melibatkan publikasi bersama, penggunaan identitas institusi, komunikasi publik, atau representasi resmi?',
        'reviu' => 'Review komunikasi, branding, otorisasi dan batas representasi.'
    ]
];

// Tambah usulan pra-pks
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } elseif (!$canEdit) {
        $errors[] = 'Anda tidak memiliki hak akses untuk menambah usulan.';
    } else {
        $nomorUsulan    = trim($_POST['nomor_usulan'] ?? '');
        $tipeKerjasama  = $_POST['tipe_kerjasama'] ?? 'Dalam Negeri';
        $jenisNaskah    = $_POST['jenis_naskah'] ?? 'PKS';
        $unitPemrakarsa = trim($_POST['unit_pemrakarsa'] ?? '');
        $pjUsulan       = trim($_POST['penanggung_jawab_usulan'] ?? '');
        $calonMitra     = trim($_POST['calon_mitra'] ?? '');
        $judulRencana   = trim($_POST['judul_rencana'] ?? '');
        $tujuanSingkat  = trim($_POST['tujuan_singkat'] ?? '');
        $ruangLingkup   = trim($_POST['ruang_lingkup'] ?? '');
        $penerimaManfaat = trim($_POST['penerima_manfaat'] ?? '');
        $mulai          = ($_POST['perkiraan_mulai'] ?? '') ?: null;
        $selesai        = ($_POST['perkiraan_selesai'] ?? '') ?: null;

        // Ambil 18 pertanyaan uji & bukti
        $pertanyaanUji = [];
        $kSummary = ['K1' => 'YA', 'K2' => 'YA', 'K3' => 'YA', 'K4' => 'YA', 'K5' => 'YA'];

        for ($q = 1; $q <= 18; $q++) {
            $ans = strtoupper($_POST["q{$q}_jawab"] ?? 'YA') === 'TIDAK' ? 'TIDAK' : 'YA';
            $bukti = trim($_POST["q{$q}_bukti"] ?? '');
            $pertanyaanUji["q{$q}"] = [
                'jawab' => $ans,
                'bukti' => $bukti
            ];
            // Evaluasi per kriteria: jika ada 1 TIDAK dalam kriteria, maka kriteria = TIDAK
            if ($ans === 'TIDAK') {
                if ($q <= 3) $kSummary['K1'] = 'TIDAK';
                elseif ($q <= 7) $kSummary['K2'] = 'TIDAK';
                elseif ($q <= 10) $kSummary['K3'] = 'TIDAK';
                elseif ($q <= 14) $kSummary['K4'] = 'TIDAK';
                else $kSummary['K5'] = 'TIDAK';
            }
        }

        // Ambil 7 trigger khusus
        $triggerKhusus = [];
        $adaTriggerYa = false;
        for ($t = 1; $t <= 7; $t++) {
            $tAns = strtoupper($_POST["trigger{$t}_jawab"] ?? 'TIDAK') === 'YA' ? 'YA' : 'TIDAK';
            $tCat = trim($_POST["trigger{$t}_catatan"] ?? '');
            $triggerKhusus["t{$t}"] = [
                'jawab' => $tAns,
                'catatan' => $tCat
            ];
            if ($tAns === 'YA') {
                $adaTriggerYa = true;
            }
        }

        // Evaluasi rekomendasi Gate 0:
        $semuaKriteriaYa = ($kSummary['K1'] === 'YA' && $kSummary['K2'] === 'YA' && $kSummary['K3'] === 'YA' && $kSummary['K4'] === 'YA' && $kSummary['K5'] === 'YA');
        $jumlahKriteriaYa = ($kSummary['K1'] === 'YA' ? 1 : 0) + ($kSummary['K2'] === 'YA' ? 1 : 0) + ($kSummary['K3'] === 'YA' ? 1 : 0) + ($kSummary['K4'] === 'YA' ? 1 : 0) + ($kSummary['K5'] === 'YA' ? 1 : 0);

        if ($jumlahKriteriaYa === 0) {
            $statusRekomendasi = 'Tidak Prioritas / Tidak Layak';
        } elseif ($semuaKriteriaYa && !$adaTriggerYa) {
            $statusRekomendasi = 'Layak';
        } elseif ($jumlahKriteriaYa >= 3) {
            $statusRekomendasi = 'Perlu Penyempurnaan';
        } else {
            $statusRekomendasi = 'Tidak Prioritas / Tidak Layak';
        }

        $catatanVerif   = trim($_POST['catatan_verifikasi'] ?? '');
        $gapPenutupan   = trim($_POST['gap_penyempurnaan'] ?? '');
        $unitReviu      = trim($_POST['unit_review_tambahan'] ?? '');
        $batasWaktu     = ($_POST['batas_waktu_penyempurnaan'] ?? '') ?: null;
        $submitMode     = $_POST['submit_mode'] ?? 'submit_final';
        $initialStatus  = ($submitMode === 'save_draft') ? 'Draft' : 'Menunggu Persetujuan Pimpinan';

        if ($nomorUsulan === '' || $calonMitra === '' || $judulRencana === '') {
            $errors[] = 'Nomor Usulan, Calon Mitra, dan Judul Rencana wajib diisi.';
        } elseif ($mulai && $selesai && $selesai < $mulai) {
            // Validasi perkiraan_selesai >= perkiraan_mulai
            $errors[] = 'Perkiraan tanggal selesai tidak boleh lebih awal dari perkiraan tanggal mulai.';
        } else {
            // Cek nomor usulan sudah terdaftar
            $stmtCheck = $pdo->prepare('SELECT id FROM pra_pks WHERE nomor_usulan = ? LIMIT 1');
            $stmtCheck->execute([$nomorUsulan]);
            if ($stmtCheck->fetch()) {
                $errors[] = 'Nomor usulan ' . h($nomorUsulan) . ' sudah terdaftar. Silakan gunakan nomor usulan lain.';
            } else {
                $savedFilePath = null;
                if (!empty($_FILES['file_usulan']) && $_FILES['file_usulan']['error'] === UPLOAD_ERR_OK) {
                    $fName = $_FILES['file_usulan']['name'];
                    $fExt  = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
                    if (in_array($fExt, ['pdf', 'docx', 'xlsx', 'csv'], true) && $_FILES['file_usulan']['size'] <= 25 * 1024 * 1024) {
                        $upDir = __DIR__ . '/public/uploads/gate0';
                        if (!is_dir($upDir)) @mkdir($upDir, 0775, true);
                        $targetFname = 'gate0_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $nomorUsulan) . '_' . time() . '.' . $fExt;
                        if (move_uploaded_file($_FILES['file_usulan']['tmp_name'], $upDir . '/' . $targetFname)) {
                            $savedFilePath = 'public/uploads/gate0/' . $targetFname;
                        }
                    }
                }

                try {
                    $stmt = $pdo->prepare('INSERT INTO pra_pks (
                        nomor_usulan, tipe_kerjasama, jenis_naskah, unit_pemrakarsa, penanggung_jawab_usulan,
                        calon_mitra, judul_rencana, tujuan_singkat, ruang_lingkup, penerima_manfaat,
                        perkiraan_mulai, perkiraan_selesai, file_usulan,
                        k1_kesesuaian_strategis, k2_kebutuhan_daya_ungkit, k3_kelayakan_mitra, k4_kesiapan_sumber_daya, k5_risiko_keberlanjutan,
                        pertanyaan_uji, trigger_khusus, catatan_verifikasi, gap_penyempurnaan, unit_review_tambahan, batas_waktu_penyempurnaan,
                        status_rekomendasi, status_persetujuan, created_by
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([
                        $nomorUsulan, $tipeKerjasama, $jenisNaskah, $unitPemrakarsa, $pjUsulan ?: null,
                        $calonMitra, $judulRencana, $tujuanSingkat, $ruangLingkup, $penerimaManfaat,
                        $mulai, $selesai, $savedFilePath,
                        $kSummary['K1'], $kSummary['K2'], $kSummary['K3'], $kSummary['K4'], $kSummary['K5'],
                        json_encode($pertanyaanUji), json_encode($triggerKhusus),
                        $catatanVerif, $gapPenutupan, $unitReviu, $batasWaktu,
                        $statusRekomendasi, $initialStatus, $user['id']
                    ]);
                    // Audit log on create
                    logAudit(0, $user['id'], 'GATE0_CREATE', "Pengajuan usulan Gate 0: {$nomorUsulan} ({$calonMitra}) - Status: {$initialStatus}");
                    $success = 'Usulan Gate 0 <strong>' . htmlspecialchars($nomorUsulan) . '</strong> berhasil ' . ($initialStatus === 'Draft' ? 'disimpan sebagai Draft.' : 'diajukan ke Pimpinan.') . ' Evaluasi 18 pertanyaan uji & 7 trigger khusus terverifikasi.';
                } catch (PDOException $e) {
                    $errors[] = str_contains($e->getMessage(), 'Duplicate') ? 'Nomor usulan sudah terdaftar.' : 'Gagal menyimpan: ' . $e->getMessage();
                }
            }
        }
    }
}

// Edit / update usulan pra-pks (popup modal)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } elseif (!$canEdit) {
        $errors[] = 'Anda tidak memiliki hak akses untuk mengubah usulan.';
    } else {
        $idUsulan       = (int)($_POST['usulan_id'] ?? 0);
        $nomorUsulan    = trim($_POST['nomor_usulan'] ?? '');
        $tipeKerjasama  = $_POST['tipe_kerjasama'] ?? 'Dalam Negeri';
        $jenisNaskah    = $_POST['jenis_naskah'] ?? 'PKS';
        $unitPemrakarsa = trim($_POST['unit_pemrakarsa'] ?? '');
        $pjUsulan       = trim($_POST['penanggung_jawab_usulan'] ?? '');
        $calonMitra     = trim($_POST['calon_mitra'] ?? '');
        $judulRencana   = trim($_POST['judul_rencana'] ?? '');
        $tujuanSingkat  = trim($_POST['tujuan_singkat'] ?? '');
        $ruangLingkup   = trim($_POST['ruang_lingkup'] ?? '');
        $penerimaManfaat = trim($_POST['penerima_manfaat'] ?? '');
        $mulai          = ($_POST['perkiraan_mulai'] ?? '') ?: null;
        $selesai        = ($_POST['perkiraan_selesai'] ?? '') ?: null;
        $submitMode     = $_POST['submit_mode'] ?? 'save_draft';

        $stmtEx = $pdo->prepare('SELECT * FROM pra_pks WHERE id = ?');
        $stmtEx->execute([$idUsulan]);
        $existing = $stmtEx->fetch();
        if (!$existing) {
            $errors[] = 'Data usulan tidak ditemukan.';
        } elseif (in_array($existing['status_persetujuan'] ?? '', ['Menunggu Persetujuan Pimpinan', 'Disetujui Pimpinan', 'Ditolak Pimpinan'], true)) {
            $errors[] = 'Akses ditolak: Usulan dengan status ' . h($existing['status_persetujuan']) . ' tidak dapat diedit.';
        } elseif ($nomorUsulan === '' || $calonMitra === '' || $judulRencana === '') {
            $errors[] = 'Nomor Usulan, Calon Mitra, dan Judul Rencana wajib diisi.';
        } elseif ($mulai && $selesai && $selesai < $mulai) {
            $errors[] = 'Perkiraan tanggal selesai tidak boleh lebih awal dari perkiraan tanggal mulai.';
        } else {
            if ($nomorUsulan !== $existing['nomor_usulan']) {
                $stmtDup = $pdo->prepare('SELECT id FROM pra_pks WHERE nomor_usulan = ? AND id != ?');
                $stmtDup->execute([$nomorUsulan, $idUsulan]);
                if ($stmtDup->fetch()) {
                    $errors[] = 'Nomor usulan ' . h($nomorUsulan) . ' sudah digunakan oleh usulan lain.';
                }
            }

            if (empty($errors)) {
                $pertanyaanUji = [];
                $kSummary = ['K1' => 'YA', 'K2' => 'YA', 'K3' => 'YA', 'K4' => 'YA', 'K5' => 'YA'];
                for ($q = 1; $q <= 18; $q++) {
                    $ans = strtoupper($_POST["q{$q}_jawab"] ?? 'YA') === 'TIDAK' ? 'TIDAK' : 'YA';
                    $bukti = trim($_POST["q{$q}_bukti"] ?? '');
                    $pertanyaanUji["q{$q}"] = [
                        'jawab' => $ans,
                        'bukti' => $bukti
                    ];
                    if ($ans === 'TIDAK') {
                        if ($q <= 3) $kSummary['K1'] = 'TIDAK';
                        elseif ($q <= 7) $kSummary['K2'] = 'TIDAK';
                        elseif ($q <= 10) $kSummary['K3'] = 'TIDAK';
                        elseif ($q <= 14) $kSummary['K4'] = 'TIDAK';
                        else $kSummary['K5'] = 'TIDAK';
                    }
                }

                $triggerKhusus = [];
                $adaTriggerYa = false;
                for ($t = 1; $t <= 7; $t++) {
                    $tAns = strtoupper($_POST["trigger{$t}_jawab"] ?? 'TIDAK') === 'YA' ? 'YA' : 'TIDAK';
                    $tCat = trim($_POST["trigger{$t}_catatan"] ?? '');
                    $triggerKhusus["t{$t}"] = [
                        'jawab' => $tAns,
                        'catatan' => $tCat
                    ];
                    if ($tAns === 'YA') $adaTriggerYa = true;
                }

                $semuaKriteriaYa = ($kSummary['K1'] === 'YA' && $kSummary['K2'] === 'YA' && $kSummary['K3'] === 'YA' && $kSummary['K4'] === 'YA' && $kSummary['K5'] === 'YA');
                $jumlahKriteriaYa = ($kSummary['K1'] === 'YA' ? 1 : 0) + ($kSummary['K2'] === 'YA' ? 1 : 0) + ($kSummary['K3'] === 'YA' ? 1 : 0) + ($kSummary['K4'] === 'YA' ? 1 : 0) + ($kSummary['K5'] === 'YA' ? 1 : 0);

                if ($jumlahKriteriaYa === 0) {
                    $statusRekomendasi = 'Tidak Prioritas / Tidak Layak';
                } elseif ($semuaKriteriaYa && !$adaTriggerYa) {
                    $statusRekomendasi = 'Layak';
                } elseif ($jumlahKriteriaYa >= 3) {
                    $statusRekomendasi = 'Perlu Penyempurnaan';
                } else {
                    $statusRekomendasi = 'Tidak Prioritas / Tidak Layak';
                }

                $catatanVerif   = trim($_POST['catatan_verifikasi'] ?? '');
                $gapPenutupan   = trim($_POST['gap_penyempurnaan'] ?? '');
                $unitReviu      = trim($_POST['unit_review_tambahan'] ?? '');
                $batasWaktu     = ($_POST['batas_waktu_penyempurnaan'] ?? '') ?: null;

                $filePath = $existing['file_usulan'] ?? null;
                if (!empty($_FILES['file_naskah_baru']) && $_FILES['file_naskah_baru']['error'] === UPLOAD_ERR_OK) {
                    $fName = $_FILES['file_naskah_baru']['name'];
                    $fSize = $_FILES['file_naskah_baru']['size'];
                    $fExt  = strtolower(pathinfo($fName, PATHINFO_EXTENSION));
                    if (!in_array($fExt, ['pdf', 'docx', 'xlsx', 'csv'], true)) {
                        $errors[] = 'Format file lampiran baru harus berupa PDF, DOCX, XLSX, atau CSV.';
                    } elseif ($fSize > 25 * 1024 * 1024) {
                        $errors[] = 'Ukuran file lampiran baru melebihi 25MB.';
                    } else {
                        $upDir = __DIR__ . '/public/uploads/gate0';
                        if (!is_dir($upDir)) @mkdir($upDir, 0775, true);
                        $targetFname = 'gate0_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $nomorUsulan) . '_' . time() . '.' . $fExt;
                        if (move_uploaded_file($_FILES['file_naskah_baru']['tmp_name'], $upDir . '/' . $targetFname)) {
                            $filePath = 'public/uploads/gate0/' . $targetFname;
                        }
                    }
                }

                if (empty($errors)) {
                    $newStatusPersetujuan = ($submitMode === 'submit_final') ? 'Menunggu Persetujuan Pimpinan' : ($existing['status_persetujuan'] ?? 'Draft');

                    $stmtUpd = $pdo->prepare('UPDATE pra_pks SET
                        nomor_usulan = ?, tipe_kerjasama = ?, jenis_naskah = ?, unit_pemrakarsa = ?, penanggung_jawab_usulan = ?,
                        calon_mitra = ?, judul_rencana = ?, tujuan_singkat = ?, ruang_lingkup = ?, penerima_manfaat = ?,
                        perkiraan_mulai = ?, perkiraan_selesai = ?, file_usulan = ?,
                        k1_kesesuaian_strategis = ?, k2_kebutuhan_daya_ungkit = ?, k3_kelayakan_mitra = ?, k4_kesiapan_sumber_daya = ?, k5_risiko_keberlanjutan = ?,
                        pertanyaan_uji = ?, trigger_khusus = ?, catatan_verifikasi = ?, gap_penyempurnaan = ?, unit_review_tambahan = ?, batas_waktu_penyempurnaan = ?,
                        status_rekomendasi = ?, status_persetujuan = ?
                        WHERE id = ?');
                    $stmtUpd->execute([
                        $nomorUsulan, $tipeKerjasama, $jenisNaskah, $unitPemrakarsa, $pjUsulan ?: null,
                        $calonMitra, $judulRencana, $tujuanSingkat, $ruangLingkup, $penerimaManfaat,
                        $mulai, $selesai, $filePath,
                        $kSummary['K1'], $kSummary['K2'], $kSummary['K3'], $kSummary['K4'], $kSummary['K5'],
                        json_encode($pertanyaanUji), json_encode($triggerKhusus),
                        $catatanVerif, $gapPenutupan, $unitReviu, $batasWaktu,
                        $statusRekomendasi, $newStatusPersetujuan, $idUsulan
                    ]);
                    logAudit(0, $user['id'], 'GATE0_UPDATE', "Memperbarui usulan Gate 0: {$nomorUsulan} ({$calonMitra}) - Status: {$newStatusPersetujuan}");
                    $success = 'Usulan Gate 0 <strong>' . htmlspecialchars($nomorUsulan) . '</strong> berhasil diperbarui' . ($submitMode === 'submit_final' ? ' dan diajukan ke Pimpinan.' : '.');
                }
            }
        }
    }
}

// Submit usulan dari tabel
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'submit_proposal') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } elseif (!$canEdit) {
        $errors[] = 'Akses ditolak.';
    } else {
        $idUsulan = (int)($_POST['usulan_id'] ?? 0);
        $stmtEx = $pdo->prepare('SELECT id, nomor_usulan, calon_mitra, status_persetujuan FROM pra_pks WHERE id = ?');
        $stmtEx->execute([$idUsulan]);
        $rowP = $stmtEx->fetch();
        if (!$rowP) {
            $errors[] = 'Usulan tidak ditemukan.';
        } else {
            $stmtSub = $pdo->prepare("UPDATE pra_pks SET status_persetujuan = 'Menunggu Persetujuan Pimpinan' WHERE id = ?");
            $stmtSub->execute([$idUsulan]);
            logAudit(0, $user['id'], 'GATE0_SUBMIT', "Submit usulan Gate 0 ke pimpinan: {$rowP['nomor_usulan']}");
            $success = "Usulan <strong>" . htmlspecialchars($rowP['nomor_usulan']) . "</strong> (" . htmlspecialchars($rowP['calon_mitra']) . ") berhasil disubmit ke Pimpinan. Status kini final menunggu persetujuan.";
        }
    }
}

// Keputusan pimpinan
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'decision') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } elseif (!$canDecide) {
        $errors[] = 'Hanya pimpinan atau admin yang berhak memberikan keputusan Gate 0.';
    } else {
        $idUsulan = (int)($_POST['usulan_id'] ?? 0);
        $statusPersetujuan = $_POST['status_persetujuan'] ?? '';
        $catatanPimpinan   = trim($_POST['catatan_pimpinan'] ?? '');

        if ($idUsulan <= 0 || !in_array($statusPersetujuan, ['Disetujui Pimpinan', 'Dikembalikan untuk Revisi', 'Ditolak Pimpinan'], true)) {
            $errors[] = 'Data keputusan tidak valid.';
        } else {
            // & Bug 18: Prevent decision status change if proposal is already promoted to PKS, wrap in try/catch
            try {
                $chkP = $pdo->prepare('SELECT is_promoted_to_pks, nomor_usulan FROM pra_pks WHERE id = ?');
                $chkP->execute([$idUsulan]);
                $rowP = $chkP->fetch();
                if (!$rowP) {
                    $errors[] = 'Usulan tidak ditemukan.';
                } elseif (!empty($rowP['is_promoted_to_pks'])) {
                    $errors[] = 'Usulan ' . $rowP['nomor_usulan'] . ' telah dipromosikan menjadi PKS aktif. Keputusan pimpinan tidak dapat diubah kembali.';
                } else {
                    $stmt = $pdo->prepare('UPDATE pra_pks SET status_persetujuan = ?, catatan_pimpinan = ?, tanggal_persetujuan = CURDATE(), pimpinan_id = ? WHERE id = ?');
                    $stmt->execute([$statusPersetujuan, $catatanPimpinan ?: null, $user['id'], $idUsulan]);
                    // Audit log on decision
                    logAudit(0, $user['id'], 'GATE0_DECISION', "Keputusan Gate 0 untuk usulan {$rowP['nomor_usulan']}: {$statusPersetujuan}");
                    $success = 'Keputusan dan disposisi pimpinan untuk usulan <strong>' . htmlspecialchars($rowP['nomor_usulan']) . '</strong> berhasil disimpan.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Gagal menyimpan keputusan pimpinan: ' . $e->getMessage();
            }
        }
    }
}

// Promosi otomatis ke pks aktif
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'promote') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } elseif (!$canEdit) {
        $errors[] = 'Akses ditolak.';
    } else {
        $idUsulan = (int)($_POST['usulan_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM pra_pks WHERE id = ?');
        $stmt->execute([$idUsulan]);
        $pra = $stmt->fetch();

        if (!$pra || $pra['status_persetujuan'] !== 'Disetujui Pimpinan') {
            $errors[] = 'Hanya usulan yang telah disetujui pimpinan yang dapat dipromosikan ke PKS.';
        } elseif ($pra['is_promoted_to_pks']) {
            $errors[] = 'Usulan ini sudah pernah dipromosikan sebelumnya.';
        } else {
            $pdo->beginTransaction();
            try {
                // Tentukan kode baru berikutnya menggunakan MAX(CAST(SUBSTRING(kode, 2) AS UNSIGNED))
                $maxNum = (int)$pdo->query("SELECT MAX(CAST(SUBSTRING(kode, 2) AS UNSIGNED)) FROM mitra_kinerja WHERE kode REGEXP '^P[0-9]+$'")->fetchColumn();
                $nextNum = $maxNum > 0 ? ($maxNum + 1) : 11;
                $newKode = 'P' . str_pad($nextNum, 2, '0', STR_PAD_LEFT);

                // Tentukan Bidang terkait dengan word boundary regex
                $bidangCandidate = 'AHU';
                $unitLower = strtolower($pra['unit_pemrakarsa'] . ' ' . ($pra['penanggung_jawab_usulan'] ?? ''));
                if (str_contains($unitLower, 'kekayaan intelektual') || preg_match('/\bki\b/i', $unitLower)) $bidangCandidate = 'KI';
                elseif (str_contains($unitLower, 'ham') || str_contains($unitLower, 'p3h')) $bidangCandidate = 'P3H';
                elseif (str_contains($unitLower, 'peraturan') || str_contains($unitLower, 'perundang') || str_contains($unitLower, 'ppl')) $bidangCandidate = 'PPL';
                elseif (str_contains($unitLower, 'keuangan')) $bidangCandidate = 'Keuangan';
                elseif (str_contains($unitLower, 'humas')) $bidangCandidate = 'Humas';
                elseif (str_contains($unitLower, 'sdm') || str_contains($unitLower, 'kepegawaian')) $bidangCandidate = 'SDM';

                // Periksa apakah perkiraan_mulai kosong atau '0000-00-00'
                $mulaiRaw = trim((string)($pra['perkiraan_mulai'] ?? ''));
                $mulaiPks = (!empty($mulaiRaw) && $mulaiRaw !== '0000-00-00' && strtotime($mulaiRaw) !== false) ? $mulaiRaw : date('Y-m-d');
                $selesaiRaw = trim((string)($pra['perkiraan_selesai'] ?? ''));
                $selesaiPks = (!empty($selesaiRaw) && $selesaiRaw !== '0000-00-00' && strtotime($selesaiRaw) !== false) ? $selesaiRaw : date('Y-m-d', strtotime($mulaiPks . ' +3 years'));

                // Map jenis_naskah 'Lainnya' to 'PKS'
                $jenisMitra = in_array($pra['jenis_naskah'], ['PKS', 'MoU'], true) ? $pra['jenis_naskah'] : 'PKS';

                // Inisialisasi posisi_portofolio ke 'BELUM DAPAT DITENTUKAN' dan rekomendasi ke 'BELUM DITENTUKAN'
                $stmtM = $pdo->prepare('INSERT INTO mitra_kinerja (
                    kode, portofolio, nama_mitra, judul, bidang, jenis, tanggal_mulai, tanggal_berakhir,
                    status_tanggal, evaluasi_per_tahun, baseline_status, cutoff_date, sumber_baseline, status_scorecard, posisi_portofolio, rekomendasi,
                    pic_internal
                ) VALUES (?, \'Pilot Utama\', ?, ?, ?, ?, ?, ?, \'TERVERIFIKASI\', 4, \'DALAM PROSES\', CURDATE(), \'Gate 0 Promoted\', \'BELUM LENGKAP\', \'BELUM DAPAT DITENTUKAN\', \'BELUM DITENTUKAN\', ?)');
                $stmtM->execute([
                    $newKode, $pra['calon_mitra'], $pra['judul_rencana'], $bidangCandidate, $jenisMitra,
                    $mulaiPks, $selesaiPks, $pra['penanggung_jawab_usulan'] ?: $pra['unit_pemrakarsa']
                ]);
                $newMitraId = (int)$pdo->lastInsertId();

                // Truncate / fit rencana_kerja judul_rencana to 255 chars
                $rkJudul = 'Rencana Kerja ' . $pra['judul_rencana'];
                $rkLen = function_exists('mb_strlen') ? mb_strlen($rkJudul) : strlen($rkJudul);
                if ($rkLen > 255) {
                    $rkJudul = (function_exists('mb_substr') ? mb_substr($rkJudul, 0, 252) : substr($rkJudul, 0, 252)) . '...';
                }

                // Clamp work plan duration so tanggal_selesai does not exceed $selesaiPks
                $rkSelesai = date('Y-m-d', strtotime($mulaiPks . ' +1 year'));
                if ($rkSelesai > $selesaiPks) {
                    $rkSelesai = $selesaiPks;
                }

                $stmtRK = $pdo->prepare('INSERT INTO rencana_kerja (
                    mitra_id, judul_rencana, ruang_lingkup, maksud_tujuan, tanggal_mulai, tanggal_selesai, status, alasan_persetujuan
                ) VALUES (?, ?, ?, ?, ?, ?, \'Disetujui\', ?)');
                $stmtRK->execute([
                    $newMitraId,
                    $rkJudul,
                    $pra['ruang_lingkup'],
                    $pra['tujuan_singkat'],
                    $mulaiPks,
                    $rkSelesai,
                    'Disetujui otomatis melalui kelayakan Gate 0'
                ]);

                // Inisialisasi 12 Elemen Baseline FIX
                foreach (BASELINE_12_DEFS as $n => $d) {
                    $faktaInit = '';
                    $statusInit = 'BELUM DIISI';
                    if ($n === 1) {
                        $faktaInit = "Identitas dari Gate 0 ({$pra['nomor_usulan']}): {$pra['calon_mitra']} - {$pra['judul_rencana']}";
                        $statusInit = 'TERVERIFIKASI';
                    } elseif ($n === 3) {
                        $faktaInit = $pra['ruang_lingkup'] ?: 'Ruang lingkup usulan Gate 0 disepakati.';
                        $statusInit = 'TERVERIFIKASI';
                    } elseif ($n === 6) {
                        $faktaInit = "Unit pengampu: {$pra['unit_pemrakarsa']}";
                        $statusInit = 'TERVERIFIKASI';
                    } elseif ($n === 7 && !empty($pra['penanggung_jawab_usulan'])) {
                        $faktaInit = "PIC internal: {$pra['penanggung_jawab_usulan']}";
                        $statusInit = 'TERVERIFIKASI';
                    } elseif ($n === 9 && !empty($pra['tujuan_singkat'])) {
                        $faktaInit = "Tujuan & tindak lanjut: {$pra['tujuan_singkat']}";
                        $statusInit = 'TERVERIFIKASI';
                    }
                    $pdo->prepare('INSERT INTO baseline_elemen (mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa, sumber_bukti_minimum, status, fakta_pemeriksaan) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$newMitraId, $n, $d['kelompok'], $d['nama'], $d['yang_diperiksa'], $d['sumber_minimum'], $statusInit, $faktaInit ?: null]);
                }

                // Inisialisasi Siklus Monev Berkala (Triwulanan / 4x setahun)
                $keb = hitungKebutuhanScorecard($mulaiPks, $selesaiPks, 4);
                if (!empty($keb['milestones'])) {
                    foreach ($keb['milestones'] as $ms) {
                        $pdo->prepare('INSERT INTO siklus_monev (mitra_id, siklus_ke, nama_siklus, tanggal_target_evaluasi, status_siklus) VALUES (?, ?, ?, ?, ?)')
                            ->execute([$newMitraId, $ms['siklus_ke'], $ms['nama'], $ms['target_tgl'], $ms['is_past'] ? 'Perlu Penilaian Segera' : 'Menunggu']);
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
                    $pdo->prepare('INSERT INTO indikator_skor (mitra_id, kode_indikator, deskripsi, bobot, referensi_baseline, status_pemeriksaan) VALUES (?, ?, ?, ?, \'Dipromosikan dari Gate 0\', \'BELUM DITELAAH\')')
                        ->execute([$newMitraId, $ind[0], $desc, $ind[2]]);
                }

                // Inisialisasi Early Warning (4 dimensi)
                foreach (['Masa berlaku','Aktivitas/tenggat','Data/eviden','PIC'] as $dim) {
                    $pdo->prepare("INSERT INTO early_warning (mitra_id, dimensi, status, progres) VALUES (?, ?, 'V0', 'BELUM MULAI')")
                        ->execute([$newMitraId, $dim]);
                }

                // Inisialisasi 5 Pemicu Intervensi
                $pemicuTeks = [
                    'Keterlambatan pelaksanaan', 'Perubahan kebijakan / regulasi',
                    'Keterbatasan sumber daya / anggaran', 'Hambatan koordinasi / respon mitra',
                    'Risiko hukum atau kepatuhan'
                ];
                foreach ($pemicuTeks as $idx => $t) {
                    $pdo->prepare('INSERT INTO intervensi_pimpinan (mitra_id, no_pemicu, pemicu_teks) VALUES (?, ?, ?)')
                        ->execute([$newMitraId, $idx + 1, $t]);
                }

                // Inisialisasi status validasi
                $pdo->prepare("INSERT INTO validasi (mitra_id, status) VALUES (?, 'BELUM')")->execute([$newMitraId]);

                // Tandai sudah dipromosikan
                $pdo->prepare('UPDATE pra_pks SET is_promoted_to_pks = 1 WHERE id = ?')->execute([$idUsulan]);

                $pdo->commit();
                // Audit log on promote
                logAudit($newMitraId, $user['id'], 'GATE0_PROMOTE', "Promosi usulan Gate 0 {$pra['nomor_usulan']} ke PKS {$newKode}");
                $success = 'Usulan <strong>' . htmlspecialchars($pra['nomor_usulan']) . '</strong> berhasil dipromosikan menjadi PKS baru dengan kode <strong>' . $newKode . '</strong>!';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Gagal mempromosikan usulan: ' . $e->getMessage();
            }
        }
    }
}

// HELPER PARSER EXCEL (.xlsx) & CSV
// Convert Excel serial date numbers to Y-m-d format & parse Indonesian dates
function parseGate0Date($val): ?string {
    if (empty($val)) return null;
    $str = trim((string)$val);
    if ($str === '' || $str === '0000-00-00') return null;
    if (is_numeric($str) && (float)$str > 20000 && (float)$str < 70000) {
        $ts = ((float)$str - 25569) * 86400;
        return gmdate('Y-m-d', (int)round($ts));
    }
    if (!function_exists('parseIndonesianDateText') && file_exists(__DIR__ . '/includes/functions.php')) {
        require_once __DIR__ . '/includes/functions.php';
    }
    if (function_exists('parseIndonesianDateText')) {
        $indo = parseIndonesianDateText($str);
        if ($indo !== null) return $indo;
    }
    if (preg_match('/(\d{1,2})\s*[-\/\s]\s*([a-zA-Z]+)\s*[-\/\s]\s*(\d{4})/', $str, $m)) {
        $day = (int)$m[1];
        $monthStr = strtolower(trim($m[2]));
        $year = (int)$m[3];
        $monthMap = [
            'jan' => 1, 'januari' => 1, 'january' => 1,
            'feb' => 2, 'februari' => 2, 'february' => 2,
            'mar' => 3, 'maret' => 3, 'march' => 3,
            'apr' => 4, 'april' => 4,
            'mei' => 5, 'may' => 5,
            'jun' => 6, 'juni' => 6, 'june' => 6,
            'jul' => 7, 'juli' => 7, 'july' => 7,
            'agu' => 8, 'agt' => 8, 'ags' => 8, 'agustus' => 8, 'aug' => 8, 'august' => 8,
            'sep' => 9, 'sept' => 9, 'september' => 9,
            'okt' => 10, 'oct' => 10, 'oktober' => 10, 'october' => 10,
            'nov' => 11, 'nop' => 11, 'november' => 11, 'nopember' => 11,
            'des' => 12, 'dec' => 12, 'desember' => 12, 'december' => 12,
        ];
        if (isset($monthMap[$monthStr])) {
            return sprintf('%04d-%02d-%02d', $year, $monthMap[$monthStr], $day);
        }
    }
    if (preg_match('/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $str, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (preg_match('/(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/', $str, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    $ts = strtotime($str);
    return ($ts !== false && $ts > 0) ? date('Y-m-d', $ts) : null;
}

function parseGate0Upload(string $tmpPath, string $origName): array {
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') {
        $zip = new RobustZipReader();
        if (!$zip->open($tmpPath)) return [];
        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $xml = @simplexml_load_string(cleanXmlString($ssXml));
            if ($xml !== false && isset($xml->si)) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } else {
                        $textParts = [];
                        if (isset($si->r)) {
                            foreach ($si->r as $r) $textParts[] = (string)($r->t ?? '');
                        }
                        $sharedStrings[] = implode('', $textParts);
                    }
                }
            }
        }
        $rows = [];
        // Parse sheet targets dynamically from xl/workbook.xml and relationships rather than hardcoding xl/worksheets/sheet1.xml
        $sheetTarget = '';
        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relMap = [];
        if ($relsContent) {
            preg_match_all('/<Relationship[^>]+>/i', $relsContent, $rm);
            foreach ($rm[0] as $tag) {
                preg_match('/Id=\"([^\"]+)\"/i', $tag, $mId);
                preg_match('/Target=\"([^\"]+)\"/i', $tag, $mTgt);
                if (!empty($mId[1]) && !empty($mTgt[1])) {
                    $t = ltrim($mTgt[1], '/');
                    if (!str_starts_with($t, 'xl/')) $t = 'xl/' . $t;
                    $relMap[$mId[1]] = $t;
                }
            }
        }
        $wbContent = $zip->getFromName('xl/workbook.xml');
        if ($wbContent) {
            $cleanWb = cleanXmlString($wbContent);
            $wbXml = @simplexml_load_string($cleanWb);
            if ($wbXml !== false && isset($wbXml->sheets->sheet)) {
                foreach ($wbXml->sheets->sheet as $s) {
                    $rId = (string)($s['id'] ?? '');
                    if (!$rId) {
                        foreach ($s->attributes() as $k => $v) {
                            if (str_ends_with(strtolower($k), 'id')) {
                                $rId = (string)$v;
                                break;
                            }
                        }
                    }
                    if ($rId && isset($relMap[$rId])) {
                        $sheetTarget = $relMap[$rId];
                        break;
                    }
                }
            }
            if (!$sheetTarget) {
                preg_match_all('/<[^>]*sheet[^>]+>/i', $wbContent, $sm);
                foreach ($sm[0] as $tag) {
                    preg_match('/(?:r:id|\bid)=\"([^\"]+)\"/i', $tag, $mRid);
                    if (!empty($mRid[1]) && isset($relMap[$mRid[1]])) {
                        $sheetTarget = $relMap[$mRid[1]];
                        break;
                    }
                }
            }
        }
        if (!$sheetTarget) {
            $sheetTarget = 'xl/worksheets/sheet1.xml';
        }
        $sheetXml = $zip->getFromName($sheetTarget);
        if (!$sheetXml && $sheetTarget !== 'xl/worksheets/sheet1.xml') {
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        }

        if ($sheetXml) {
            $xml = @simplexml_load_string(cleanXmlString($sheetXml));
            if ($xml !== false && isset($xml->sheetData->row)) {
                foreach ($xml->sheetData->row as $r) {
                $rowMap = [];
                $maxCol = 0;
                foreach ($r->c as $c) {
                    $ref = (string)$c['r'];
                    $colIdx = 0;
                    if (preg_match('/^([A-Z]+)/', $ref, $mCol)) {
                        $colLetters = $mCol[1];
                        $colIdx = 0;
                        for ($ci = 0; $ci < strlen($colLetters); $ci++) {
                            $colIdx = $colIdx * 26 + (ord($colLetters[$ci]) - ord('A') + 1);
                        }
                        $colIdx -= 1;
                    }
                    $type = (string)$c['t'];
                    if ($type === 'inlineStr') {
                        $val = (string)($c->is->t ?? '');
                    } elseif ($type === 's') {
                        $sIdx = (int)$c->v;
                        $val = $sharedStrings[$sIdx] ?? '';
                    } else {
                        $val = (string)($c->v ?? '');
                    }
                    $rowMap[$colIdx] = $val;
                    if ($colIdx > $maxCol) $maxCol = $colIdx;
                }
                if (!empty($rowMap)) {
                    $rowValues = [];
                    for ($k = 0; $k <= $maxCol; $k++) {
                        $rowValues[$k] = $rowMap[$k] ?? '';
                    }
                    $rows[] = $rowValues;
                }
            }
            }
        }
        $zip->close();
        return $rows;
    } else {
        // Use fgetcsv on a stream that respects multiline quotes
        $content = file_get_contents($tmpPath);
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (trim($content) === '') return [];
        $sample = substr($content, 0, 500);
        $delim = (substr_count($sample, ';') > substr_count($sample, ',')) ? ';' : ',';
        $rows = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        while (($data = fgetcsv($stream, 0, $delim, '"', '\\')) !== false) {
            if (count($data) === 1 && $data[0] === null) continue;
            $rows[] = $data;
        }
        fclose($stream);
        return $rows;
    }
}

// IMPORT EXCEL (.xlsx) & CSV
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!verifyCsrfToken($csrfToken)) {
        $errors[] = 'Token keamanan tidak valid atau telah kedaluwarsa. Silakan muat ulang halaman.';
    } elseif (!$canEdit) {
        $errors[] = 'Akses ditolak.';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Pilih file Excel (.xlsx) atau CSV yang valid.';
    } else {
        // Enforce allowed extensions (.xlsx, .csv) and 25MB file size cap
        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            $errors[] = 'Format file tidak didukung. Harap unggah file spreadsheet Excel (.xlsx) atau CSV (.csv).';
        } elseif ($_FILES['csv_file']['size'] > 25 * 1024 * 1024) {
            $errors[] = 'Ukuran file melebihi batas maksimum 25MB.';
        } else {
            $uploadedFile = $_FILES['csv_file']['tmp_name'];
            $origName = $_FILES['csv_file']['name'];
            $rows = parseGate0Upload($uploadedFile, $origName);

        if (empty($rows)) {
            $errors[] = 'File kosong atau format tidak dapat dibaca.';
        } else {
            $importedCount = 0;

            // Cek apakah file menggunakan format vertikal (data menurun ke bawah / form gdrive)
            $isVertical = false;
            foreach ($rows as $r) {
                $c0 = strtolower(trim($r[0] ?? ''));
                $c1 = strtolower(trim($r[1] ?? ''));
                if (str_contains($c0, 'bagian i') || str_contains($c1, 'bagian i') ||
                    str_contains($c0, 'nomor usulan') || str_contains($c1, 'nomor usulan') ||
                    str_contains($c0, 'calon mitra') || str_contains($c1, 'calon mitra')) {
                    $isVertical = true;
                    break;
                }
            }

            if ($isVertical) {
                // Parsing format vertikal (menurun ke bawah)
                $vMap = [];
                $pertanyaanUji = [];
                $triggerKhusus = [];
                $kSummary = ['K1' => 'YA', 'K2' => 'YA', 'K3' => 'YA', 'K4' => 'YA', 'K5' => 'YA'];

                foreach ($rows as $r) {
                    $cNo = strtoupper(trim($r[0] ?? ''));
                    $cParam = trim($r[1] ?? '');
                    $cVal = trim($r[2] ?? '');
                    $cNote = trim($r[3] ?? '');

                    $cleanParam = strtolower(preg_replace('/[^a-z0-9]/', '', $cParam));
                    if ($cleanParam !== '') {
                        $vMap[$cleanParam] = $cVal;
                        $vMap[$cleanParam . '_note'] = $cNote;
                    }

                    // Deteksi Q1..Q18
                    if (preg_match('/^Q(\d+)$/i', $cNo, $mQ) || preg_match('/^K(\d+)\.(\d+)/i', $cParam)) {
                        $qIdx = !empty($mQ[1]) ? (int)$mQ[1] : 0;
                        if ($qIdx === 0 && preg_match('/^K(\d+)\.(\d+)/i', $cParam, $mK)) {
                            // Hitung indeks pertanyaan dari K
                            $kMajor = (int)$mK[1];
                            $kMinor = (int)$mK[2];
                            $offsets = [1 => 0, 2 => 3, 3 => 7, 4 => 10, 5 => 14];
                            $qIdx = ($offsets[$kMajor] ?? 0) + $kMinor;
                        }
                        if ($qIdx >= 1 && $qIdx <= 18) {
                            $rawAns = strtoupper($cVal);
                            if ($rawAns === 'YA') {
                                $ans = 'YA';
                            } elseif ($rawAns === 'TIDAK') {
                                $ans = 'TIDAK';
                            } else {
                                $ans = 'BELUM DITELAAH';
                            }
                            $pertanyaanUji["q{$qIdx}"] = [
                                'jawab' => $ans,
                                'bukti' => $cNote ?: ($ans === 'BELUM DITELAAH' ? 'Belum ada data uji' : 'Dokumen terverifikasi')
                            ];
                        }
                    }

                    // Deteksi T1..T7 (Trigger)
                    if (preg_match('/^T(\d+)$/i', $cNo, $mT) || str_contains(strtolower($cParam), 'pemicu ')) {
                        $tIdx = !empty($mT[1]) ? (int)$mT[1] : 0;
                        if ($tIdx === 0 && preg_match('/pemicu\s*(\d+)/i', $cParam, $mP)) {
                            $tIdx = (int)$mP[1];
                        }
                        if ($tIdx >= 1 && $tIdx <= 7) {
                            $tAns = strtoupper($cVal) === 'YA' ? 'YA' : 'TIDAK';
                            $triggerKhusus["t{$tIdx}"] = [
                                'jawab' => $tAns,
                                'catatan' => $cNote
                            ];
                        }
                    }
                }

                // Lengkapi pertanyaan uji jika belum terisi (jangan fabrikasi YA)
                for ($q = 1; $q <= 18; $q++) {
                    if (!isset($pertanyaanUji["q{$q}"])) {
                        $pertanyaanUji["q{$q}"] = ['jawab' => 'BELUM DITELAAH', 'bukti' => 'Belum ada data uji'];
                    }
                }

                // Evaluasi kriteria K1..K5 secara akurat dari pertanyaan uji
                $kSummary = ['K1' => 'YA', 'K2' => 'YA', 'K3' => 'YA', 'K4' => 'YA', 'K5' => 'YA'];
                for ($q = 1; $q <= 18; $q++) {
                    $qAns = $pertanyaanUji["q{$q}"]['jawab'] ?? 'BELUM DITELAAH';
                    if ($qAns !== 'YA') {
                        if ($q <= 3) $kSummary['K1'] = 'TIDAK';
                        elseif ($q <= 7) $kSummary['K2'] = 'TIDAK';
                        elseif ($q <= 10) $kSummary['K3'] = 'TIDAK';
                        elseif ($q <= 14) $kSummary['K4'] = 'TIDAK';
                        else $kSummary['K5'] = 'TIDAK';
                    }
                }
                for ($t = 1; $t <= 7; $t++) {
                    if (!isset($triggerKhusus["t{$t}"])) {
                        $triggerKhusus["t{$t}"] = ['jawab' => 'TIDAK', 'catatan' => ''];
                    }
                }

                $getV = fn($keys, $def = '') => array_reduce((array)$keys, fn($carry, $k) => $carry !== $def ? $carry : ($vMap[strtolower(preg_replace('/[^a-z0-9]/', '', $k))] ?? $def), $def);

                $noUsulan = $getV(['Nomor Usulan', 'no_usulan'], 'PRA-DN-' . rand(100, 999));
                $tipe     = in_array($getV(['Tipe Kerja Sama', 'tipe_kerjasama']), ['Dalam Negeri', 'Luar Negeri']) ? $getV(['Tipe Kerja Sama', 'tipe_kerjasama']) : 'Dalam Negeri';
                $jenis    = in_array($getV(['Jenis Naskah', 'jenis_naskah']), ['MoU', 'PKS', 'Lainnya']) ? $getV(['Jenis Naskah', 'jenis_naskah']) : 'PKS';
                $unit     = $getV(['Unit Pemrakarsa', 'unit_pemrakarsa'], 'Divisi Pelayanan Hukum');
                $pj       = $getV(['Penanggung Jawab Usulan', 'penanggung_jawab_usulan'], 'Kabid Pelayanan Hukum');
                $mitra    = $getV(['Calon Mitra', 'calon_mitra'], 'Mitra Kerja Sama');
                $judul    = $getV(['Judul Rencana Kerja Sama', 'judul_rencana'], 'Kerja Sama Pelayanan Hukum');
                $tujuan   = $getV(['Tujuan Singkat', 'tujuan_singkat'], '');
                $ruang    = $getV(['Ruang Lingkup Utama', 'ruang_lingkup'], '');
                $manfaat  = $getV(['Penerima Manfaat', 'penerima_manfaat'], '');
                // Convert Excel serial dates
                $mulai    = parseGate0Date($getV(['Perkiraan Tanggal Mulai', 'perkiraan_mulai']));
                $selesai  = parseGate0Date($getV(['Perkiraan Tanggal Selesai', 'perkiraan_selesai']));

                $catatan = $getV(['Catatan Verifikator', 'catatan_verifikasi'], 'Diimpor dari formulir vertikal Gate 0.');
                $gap = $getV(['Gap yang Harus Ditutup', 'gap_penyempurnaan'], 'Tidak ada gap material.');
                $unitRev = $getV(['Unit/Fungsi Reviu Tambahan', 'unit_review_tambahan'], 'Subbagian Humas, RB, dan TI');

                $adaTrigger = false;
                foreach ($triggerKhusus as $t) {
                    if ($t['jawab'] === 'YA') { $adaTrigger = true; break; }
                }

                // Rekomendasi vertikal import mencakup 'Tidak Prioritas / Tidak Layak'
                $rawRek = trim($getV(['status_rekomendasi', 'rekomendasi'], ''));
                if (in_array($rawRek, ['Layak', 'Perlu Penyempurnaan', 'Tidak Prioritas / Tidak Layak'], true)) {
                    $rekomendasi = $rawRek;
                } else {
                    $jumlahKriteriaYa = 0;
                    foreach ($kSummary as $kVal) {
                        if ($kVal === 'YA') $jumlahKriteriaYa++;
                    }
                    if ($jumlahKriteriaYa === 0) {
                        $rekomendasi = 'Tidak Prioritas / Tidak Layak';
                    } elseif ($jumlahKriteriaYa === 5 && !$adaTrigger) {
                        $rekomendasi = 'Layak';
                    } elseif ($jumlahKriteriaYa >= 3) {
                        $rekomendasi = 'Perlu Penyempurnaan';
                    } else {
                        $rekomendasi = 'Tidak Prioritas / Tidak Layak';
                    }
                }

                $pertanyaanJson = json_encode($pertanyaanUji, JSON_UNESCAPED_UNICODE);
                $triggerJson = json_encode($triggerKhusus, JSON_UNESCAPED_UNICODE);

                // Cek nomor usulan sudah ada atau belum (GAGAL jika nomor usulan sudah terdaftar)
                $stmtCheck = $pdo->prepare('SELECT id FROM pra_pks WHERE nomor_usulan = ? LIMIT 1');
                $stmtCheck->execute([$noUsulan]);
                if ($stmtCheck->fetch()) {
                    $errors[] = "Proses import usulan <strong>" . h($noUsulan) . "</strong> GAGAL: Nomor usulan sudah terdaftar di sistem. Silakan gunakan nomor usulan baru atau lakukan edit.";
                } else {
                    $savedFilePath = null;
                    $upDir = __DIR__ . '/public/uploads/gate0';
                    if (!is_dir($upDir)) @mkdir($upDir, 0775, true);
                    $targetFname = 'gate0_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $noUsulan) . '_' . time() . '.' . $ext;
                    if (@copy($uploadedFile, $upDir . '/' . $targetFname)) {
                        $savedFilePath = 'public/uploads/gate0/' . $targetFname;
                    }

                    // Wrap vertical import execution in try ... catch
                    try {
                        $stmt = $pdo->prepare('
                            INSERT INTO pra_pks (
                                nomor_usulan, tipe_kerjasama, jenis_naskah, unit_pemrakarsa, penanggung_jawab_usulan,
                                calon_mitra, judul_rencana, tujuan_singkat, ruang_lingkup, penerima_manfaat,
                                perkiraan_mulai, perkiraan_selesai, file_usulan,
                                k1_kesesuaian_strategis, k2_kebutuhan_daya_ungkit, k3_kelayakan_mitra,
                                k4_kesiapan_sumber_daya, k5_risiko_keberlanjutan,
                                pertanyaan_uji, trigger_khusus,
                                catatan_verifikasi, gap_penyempurnaan, unit_review_tambahan,
                                status_rekomendasi, status_persetujuan, created_by
                            ) VALUES (
                                ?, ?, ?, ?, ?,
                                ?, ?, ?, ?, ?,
                                ?, ?, ?,
                                ?, ?, ?,
                                ?, ?,
                                ?, ?,
                                ?, ?, ?,
                                ?, \'Draft\', ?
                            )
                        ');
                        $stmt->execute([
                            $noUsulan, $tipe, $jenis, $unit, $pj,
                            $mitra, $judul, $tujuan, $ruang, $manfaat,
                            $mulai, $selesai, $savedFilePath,
                            $kSummary['K1'], $kSummary['K2'], $kSummary['K3'],
                            $kSummary['K4'], $kSummary['K5'],
                            $pertanyaanJson, $triggerJson,
                            $catatan, $gap, $unitRev,
                            $rekomendasi, $user['id']
                        ]);
                        $importedCount = 1;
                        $success = "Berhasil mengimpor 1 usulan (Status: <strong>Draft</strong>): <strong>" . h($noUsulan) . "</strong> (" . h($mitra) . "). Anda dapat mengedit data atau menekan tombol Submit untuk memfinalkan pengajuan.";
                    } catch (Throwable $e) {
                        $errors[] = "Gagal mengimpor usulan vertikal [{$noUsulan}]: " . $e->getMessage();
                    }
                }
            } else {
                // Parsing format horizontal (tabel kolom)
                $headerRow = array_map('strtolower', array_map('trim', $rows[0]));
                $dataRows = array_slice($rows, 1);

                $colMap = [];
                foreach ($headerRow as $idx => $hName) {
                    $colMap[$hName] = $idx;
                }

                foreach ($dataRows as $row) {
                    // In horizontal import, do not skip rows where column A is empty if colMap maps required fields on other columns
                    if (empty(array_filter($row, fn($v) => trim((string)$v) !== ''))) continue;

                    // Fix $getVal fallback in horizontal import for column indices
                    $getVal = function($keys, $def = '') use ($row, $colMap) {
                        foreach ((array)$keys as $k) {
                            if (is_int($k)) {
                                if (isset($row[$k])) {
                                    $v = trim((string)$row[$k]);
                                    if ($v !== '') return $v;
                                }
                            } else {
                                $kLower = strtolower(trim((string)$k));
                                if (isset($colMap[$kLower]) && isset($row[$colMap[$kLower]])) {
                                    $v = trim((string)$row[$colMap[$kLower]]);
                                    if ($v !== '') return $v;
                                }
                            }
                        }
                        return $def;
                    };

                    $noUsulan = $getVal(['nomor_usulan', 'no_usulan', 0]);
                    if (empty($noUsulan)) continue;

                    $tipe     = in_array($getVal(['tipe_kerjasama', 1]), ['Dalam Negeri', 'Luar Negeri']) ? $getVal(['tipe_kerjasama', 1]) : 'Dalam Negeri';
                    $jenis    = in_array($getVal(['jenis_naskah', 2]), ['MoU', 'PKS', 'Lainnya']) ? $getVal(['jenis_naskah', 2]) : 'PKS';
                    $unit     = $getVal(['unit_pemrakarsa', 3], 'Divisi Pelayanan Hukum');
                    $pj       = $getVal(['penanggung_jawab_usulan', 4], 'Tim Kerja Sama');
                    $mitra    = $getVal(['calon_mitra', 5], '');
                    $judul    = $getVal(['judul_rencana', 6], '');
                    $tujuan   = $getVal(['tujuan_singkat', 7], '');
                    $ruang    = $getVal(['ruang_lingkup', 8], '');
                    $manfaat  = $getVal(['penerima_manfaat', 9], '');
                    // Convert Excel serial dates
                    $mulai    = parseGate0Date($getVal(['perkiraan_mulai', 10]));
                    $selesai  = parseGate0Date($getVal(['perkiraan_selesai', 11]));

                    // Cek apakah ada kolom Q1..Q18
                    $pertanyaanUji = [];
                    $kSummary = ['K1' => 'YA', 'K2' => 'YA', 'K3' => 'YA', 'K4' => 'YA', 'K5' => 'YA'];

                    $hasQCols = isset($colMap['q1_tusi_kewenangan']) || isset($colMap['q1_jawab']);
                    if ($hasQCols) {
                        for ($q = 1; $q <= 18; $q++) {
                            // Do not fabricate 'YA' answers or 'Dokumen terverifikasi' for unreviewed/unanswered questions; default to 'Belum ada data uji' matching vertical import
                            $valRaw = $getVal(["q{$q}_jawab", "q{$q}_tusi_kewenangan", "q{$q}_sasaran_kinerja", "q{$q}_batas_kewenangan", "q{$q}_kebutuhan_nyata", "q{$q}_kejelasan_manfaat", "q{$q}_output_outcome", "q{$q}_daya_ungkit", "q{$q}_legalitas_mitra", "q{$q}_kapasitas_mitra", "q{$q}_integritas_reputasi", "q{$q}_peran_kontribusi", "q{$q}_focal_point", "q{$q}_kesiapan_sdm_anggaran", "q{$q}_indikator_awal", "q{$q}_manajemen_risiko", "q{$q}_tindak_lanjut_pascattd", "q{$q}_keberlanjutan_manfaat", "q{$q}_mitigasi_hambatan"], '');
                            if ($valRaw === '') {
                                $qAns = 'BELUM DITELAAH';
                                $defaultBukti = 'Belum ada data uji';
                            } else {
                                $qAns = strtoupper($valRaw) === 'YA' ? 'YA' : (strtoupper($valRaw) === 'TIDAK' ? 'TIDAK' : 'BELUM DITELAAH');
                                $defaultBukti = $qAns === 'YA' ? 'Dokumen terverifikasi' : 'Belum ada data uji';
                            }
                            $rawBukti = $getVal(["q{$q}_bukti"], '');
                            $qBukti = $rawBukti !== '' ? $rawBukti : $defaultBukti;
                            $pertanyaanUji["q{$q}"] = ['jawab' => $qAns, 'bukti' => $qBukti];
                            if ($qAns !== 'YA') {
                                if ($q <= 3) $kSummary['K1'] = 'TIDAK';
                                elseif ($q <= 7) $kSummary['K2'] = 'TIDAK';
                                elseif ($q <= 10) $kSummary['K3'] = 'TIDAK';
                                elseif ($q <= 14) $kSummary['K4'] = 'TIDAK';
                                else $kSummary['K5'] = 'TIDAK';
                            }
                        }
                    } else {
                        // Fallback kolom K1..K5 sederhana
                        $kSummary['K1'] = strtoupper($getVal(['k1_kesesuaian_strategis', 12], 'TIDAK')) === 'YA' ? 'YA' : 'TIDAK';
                        $kSummary['K2'] = strtoupper($getVal(['k2_kebutuhan_daya_ungkit', 13], 'TIDAK')) === 'YA' ? 'YA' : 'TIDAK';
                        $kSummary['K3'] = strtoupper($getVal(['k3_kelayakan_mitra', 14], 'TIDAK')) === 'YA' ? 'YA' : 'TIDAK';
                        $kSummary['K4'] = strtoupper($getVal(['k4_kesiapan_sumber_daya', 15], 'TIDAK')) === 'YA' ? 'YA' : 'TIDAK';
                        $kSummary['K5'] = strtoupper($getVal(['k5_risiko_keberlanjutan', 16], 'TIDAK')) === 'YA' ? 'YA' : 'TIDAK';
                        for ($q = 1; $q <= 18; $q++) {
                            $pertanyaanUji["q{$q}"] = ['jawab' => 'BELUM DITELAAH', 'bukti' => 'Belum ada data uji'];
                        }
                    }

                    // 7 Trigger khusus
                    $triggerKhusus = [];
                    $adaTrigger = false;
                    for ($t = 1; $t <= 7; $t++) {
                        $tAns = strtoupper($getVal(["trigger{$t}_jawab", "trigger{$t}_pihak_asing", "trigger{$t}_keuangan_aset", "trigger{$t}_data_sistem", "trigger{$t}_kekayaan_intelektual", "trigger{$t}_teknologi_api", "trigger{$t}_risiko_hukum_reputasi", "trigger{$t}_publikasi_branding"], 'TIDAK')) === 'YA' ? 'YA' : 'TIDAK';
                        $triggerKhusus["t{$t}"] = ['jawab' => $tAns, 'catatan' => ''];
                        if ($tAns === 'YA') $adaTrigger = true;
                    }

                    $catatan = $getVal(['catatan_verifikasi', 17], 'Diimpor melalui template Excel/CSV');
                    $gap = $getVal(['gap_penyempurnaan'], 'Tidak ada gap material.');
                    $unitRev = $getVal(['unit_review_tambahan'], 'Subbagian Humas, RB, dan TI');

                    // Rekomendasi horizontal import mencakup 'Tidak Prioritas / Tidak Layak'
                    $rawRek = trim($getVal(['status_rekomendasi', 'rekomendasi'], ''));
                    if (in_array($rawRek, ['Layak', 'Perlu Penyempurnaan', 'Tidak Prioritas / Tidak Layak'], true)) {
                        $rek = $rawRek;
                    } else {
                        $jumlahKriteriaYa = 0;
                        foreach ($kSummary as $kVal) {
                            if ($kVal === 'YA') $jumlahKriteriaYa++;
                        }
                        if ($jumlahKriteriaYa === 0) {
                            $rek = 'Tidak Prioritas / Tidak Layak';
                        } elseif ($jumlahKriteriaYa === 5 && !$adaTrigger) {
                            $rek = 'Layak';
                        } elseif ($jumlahKriteriaYa >= 3) {
                            $rek = 'Perlu Penyempurnaan';
                        } else {
                            $rek = 'Tidak Prioritas / Tidak Layak';
                        }
                    }

                    // Cek duplicate nomor_usulan (GAGAL jika sudah terdaftar)
                    $stmtCheck = $pdo->prepare('SELECT id FROM pra_pks WHERE nomor_usulan = ? LIMIT 1');
                    $stmtCheck->execute([$noUsulan]);
                    if ($stmtCheck->fetch()) {
                        $errors[] = "Proses import usulan <strong>" . h($noUsulan) . "</strong> GAGAL: Nomor usulan sudah terdaftar di sistem. Usulan tidak diimpor.";
                        continue;
                    }

                    // Try/catch SQL exceptions
                    try {
                        $stmt = $pdo->prepare('INSERT INTO pra_pks (
                            nomor_usulan, tipe_kerjasama, jenis_naskah, unit_pemrakarsa, penanggung_jawab_usulan,
                            calon_mitra, judul_rencana, tujuan_singkat, ruang_lingkup, penerima_manfaat,
                            perkiraan_mulai, perkiraan_selesai,
                            k1_kesesuaian_strategis, k2_kebutuhan_daya_ungkit, k3_kelayakan_mitra, k4_kesiapan_sumber_daya, k5_risiko_keberlanjutan,
                            pertanyaan_uji, trigger_khusus, catatan_verifikasi, gap_penyempurnaan, unit_review_tambahan,
                            status_rekomendasi, status_persetujuan, created_by
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'Draft\',?)');
                        $stmt->execute([
                            $noUsulan, $tipe, $jenis, $unit, $pj,
                            $mitra, $judul, $tujuan, $ruang, $manfaat,
                            $mulai, $selesai,
                            $kSummary['K1'], $kSummary['K2'], $kSummary['K3'], $kSummary['K4'], $kSummary['K5'],
                            json_encode($pertanyaanUji), json_encode($triggerKhusus),
                            $catatan, $gap, $unitRev, $rek, $user['id']
                        ]);
                        $importedCount++;
                    } catch (Throwable $e) {
                        error_log("Gate 0 horizontal import error on [{$noUsulan}]: " . $e->getMessage());
                        $errors[] = "Gagal mengimpor usulan [{$noUsulan}]: " . $e->getMessage();
                    }
                }
                if ($importedCount > 0) {
                    $success = "Berhasil mengimpor $importedCount usulan Pra-PKS (Status: <strong>Draft</strong>) dari file " . h($origName) . ".";
                }
            }
        }
    }
}
}

// View router: detail / print form resmi
$view = $_GET['view'] ?? 'list';
$detailId = (int)($_GET['id'] ?? 0);

if (($view === 'detail' || $view === 'print') && $detailId > 0) {
    $stmt = $pdo->prepare('SELECT p.*, u.nama as nama_pembuat, pim.nama as nama_pimpinan 
                           FROM pra_pks p 
                           LEFT JOIN users u ON p.created_by = u.id 
                           LEFT JOIN users pim ON p.pimpinan_id = pim.id 
                           WHERE p.id = ?');
    $stmt->execute([$detailId]);
    $usulan = $stmt->fetch();

    if (!$usulan) {
        header('Location: gate0.php');
        exit;
    }

    $pertanyaanData = json_decode($usulan['pertanyaan_uji'] ?? '[]', true) ?: [];
    $triggerData = json_decode($usulan['trigger_khusus'] ?? '[]', true) ?: [];

    $pageTitle = 'Formulir Gate 0 — ' . $usulan['nomor_usulan'];
    if ($view === 'detail') {
        require __DIR__ . '/includes/header.php';
    } else {
        // Standalone print view
        ?><!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title><?= h($pageTitle) ?></title>
            <link rel="stylesheet" href="public/css/style.css">
            <style>
                body { background: #fff; color: #000; font-family: 'Calibri', 'Segoe UI', sans-serif; line-height: 1.4; padding: 20px; font-size: 13px; }
                .print-container { max-width: 900px; margin: 0 auto; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 12px; }
                th, td { border: 1px solid #333; padding: 6px 8px; vertical-align: top; }
                th { background: #f1f5f9; text-align: left; font-weight: 700; }
                .kop-surat { display: flex; align-items: center; border-bottom: 3px double #000; padding-bottom: 12px; margin-bottom: 18px; }
                .kop-surat img { height: 75px; margin-right: 18px; }
                .kop-text { text-align: center; flex: 1; }
                .kop-text h2 { margin: 0; font-size: 16px; font-weight: 800; letter-spacing: 0.5px; }
                .kop-text h1 { margin: 2px 0; font-size: 18px; font-weight: 900; letter-spacing: 1px; }
                .kop-text p { margin: 0; font-size: 11px; color: #444; }
                .badge { padding: 3px 6px; font-weight: 700; border-radius: 4px; font-size: 11px; display: inline-block; }
                .badge-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
                .badge-warning { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
                .badge-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
                .sig-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 30px; text-align: center; }
                .sig-box { min-height: 90px; }
                @media print {
                    .no-print { display: none !important; }
                    body { padding: 0; }
                }
            </style>
        </head>
        <body>
        <?php
    }
    ?>

    <div class="print-container" style="max-width:960px;margin:0 auto;background:#fff;padding:24px;border-radius:8px;border:1px solid #e2e8f0;">
        <div class="flex-between no-print" style="margin-bottom:16px;">
            <a href="gate0.php" class="btn btn-outline btn-sm">&larr; Kembali ke Daftar Usulan</a>
            <div style="display:flex;gap:8px;">
                <button onclick="window.print()" class="btn btn-primary btn-sm">🖨️ Cetak / Simpan PDF</button>
            </div>
        </div>

        <!-- Kop Surat Resmi -->
        <div class="kop-surat" style="display:flex;align-items:center;border-bottom:3px double #000;padding-bottom:12px;margin-bottom:18px;">
            <img src="public/img/logo-hukum.png" alt="Logo" style="height:70px;margin-right:16px;">
            <div class="kop-text" style="text-align:center;flex:1;">
                <div style="font-size:14px;font-weight:700;letter-spacing:0.5px;">KEMENTERIAN HUKUM REPUBLIK INDONESIA</div>
                <div style="font-size:17px;font-weight:900;letter-spacing:1px;">KANTOR WILAYAH KEPULAUAN RIAU</div>
                <div style="font-size:11px;color:#333;">Jalan Daeng Celak, Senggarang, Tanjungpinang, Kepulauan Riau</div>
                <div style="font-size:13px;font-weight:800;margin-top:4px;text-decoration:underline;">FORM GATE 0: UJI KELAYAKAN DAN PRIORITAS KERJA SAMA</div>
            </div>
        </div>

        <p style="font-size:12px;color:#475569;margin-bottom:16px;text-align:justify;">
            <strong>Fungsi Gate 0:</strong> Menyaring usulan kerja sama sebelum penyusunan/penandatanganan naskah agar kerja sama yang diproses memiliki kesesuaian strategis, kebutuhan nyata, manfaat dan daya ungkit yang jelas, mitra yang layak, kesiapan pelaksanaan, serta risiko yang dapat dikelola. <em>Gate 0 bukan pengganti telaah hukum atas rancangan naskah.</em>
        </p>

        <!-- A. Identitas Rencana Kerja Sama -->
        <h3 style="font-size:14px;margin:14px 0 6px;background:#f8fafc;padding:6px 10px;border-left:4px solid #1e40af;">
            A. IDENTITAS RENCANA KERJA SAMA
        </h3>
        <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
            <tr><th style="width:30%;">Nomor Usulan</th><td><strong><?= h($usulan['nomor_usulan']) ?></strong></td></tr>
            <tr><th>Tipe &amp; Jenis Naskah</th><td><?= h($usulan['tipe_kerjasama']) ?> &mdash; <?= h($usulan['jenis_naskah']) ?></td></tr>
            <tr><th>Unit Pemrakarsa / Pengusul</th><td><?= h($usulan['unit_pemrakarsa']) ?></td></tr>
            <tr><th>Penanggung Jawab Usulan</th><td><?= h($usulan['penanggung_jawab_usulan'] ?? '-') ?></td></tr>
            <tr><th>Calon Mitra Kerja Sama</th><td><strong><?= h($usulan['calon_mitra']) ?></strong></td></tr>
            <tr><th>Judul / Objek Rencana Kerja Sama</th><td><strong><?= h($usulan['judul_rencana']) ?></strong></td></tr>
            <tr><th>Tujuan Singkat</th><td><?= nl2br(h($usulan['tujuan_singkat'] ?? '-')) ?></td></tr>
            <tr><th>Ruang Lingkup Utama</th><td><?= nl2br(h($usulan['ruang_lingkup'] ?? '-')) ?></td></tr>
            <tr><th>Penerima Manfaat</th><td><?= h($usulan['penerima_manfaat'] ?? '-') ?></td></tr>
            <tr><th>Perkiraan Periode Kerja Sama</th><td><?= formatTanggal($usulan['perkiraan_mulai']) ?> s.d. <?= formatTanggal($usulan['perkiraan_selesai']) ?></td></tr>
            <tr><th>Tanggal Pengajuan</th><td><?= formatTanggal($usulan['created_at']) ?></td></tr>
        </table>

        <!-- B. Checklist 5 Kriteria Inti & 18 Pertanyaan Uji -->
        <h3 style="font-size:14px;margin:18px 0 6px;background:#f8fafc;padding:6px 10px;border-left:4px solid #1e40af;">
            B. CHECKLIST 5 KRITERIA INTI &amp; 18 PERTANYAAN UJI (YA / TIDAK)
        </h3>
        <p style="font-size:11px;color:#64748b;margin-bottom:8px;">
            <em>Petunjuk: Kriteria dinyatakan YA apabila seluruh pertanyaan wajib di dalam kriteria tersebut dijawab YA berdasarkan bukti/keterangan yang memadai.</em>
        </p>
        <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
            <thead>
                <tr>
                    <th style="width:5%;text-align:center;">No</th>
                    <th style="width:50%;">Kriteria / Pertanyaan Uji</th>
                    <th style="width:10%;text-align:center;">Hasil</th>
                    <th style="width:35%;">Bukti / Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (GATE0_CRITERIA as $kCode => $crit): 
                    $critVal = $usulan[strtolower($kCode) . '_' . ($kCode === 'K1' ? 'kesesuaian_strategis' : ($kCode === 'K2' ? 'kebutuhan_daya_ungkit' : ($kCode === 'K3' ? 'kelayakan_mitra' : ($kCode === 'K4' ? 'kesiapan_sumber_daya' : 'risiko_keberlanjutan'))))];
                ?>
                <tr style="background:#f1f5f9;font-weight:700;">
                    <td colspan="2"><?= $kCode ?>. <?= strtoupper($crit['nama']) ?></td>
                    <td style="text-align:center;">
                        <span class="badge badge-<?= $critVal === 'YA' ? 'success' : 'danger' ?>"><?= $critVal ?></span>
                    </td>
                    <td style="font-size:11px;color:#334155;">Kesimpulan Kriteria <?= $kCode ?></td>
                </tr>
                <?php foreach ($crit['questions'] as $qNum => $qText): 
                    $qItem = $pertanyaanData["q{$qNum}"] ?? ['jawab' => 'BELUM DITELAAH', 'bukti' => '-'];
                ?>
                <tr>
                    <td style="text-align:center;"><?= $qNum ?></td>
                    <td><?= h($qText) ?></td>
                    <td style="text-align:center;">
                        <strong style="color:<?= $qItem['jawab'] === 'YA' ? '#16a34a' : ($qItem['jawab'] === 'TIDAK' ? '#dc2626' : '#64748b') ?>;"><?= h($qItem['jawab']) ?></strong>
                    </td>
                    <td style="font-size:11px;color:#475569;"><?= h($qItem['bukti'] ?? '-') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- C. Trigger Karakteristik Khusus -->
        <h3 style="font-size:14px;margin:18px 0 6px;background:#f8fafc;padding:6px 10px;border-left:4px solid #1e40af;">
            C. TRIGGER KARAKTERISTIK KHUSUS (7 ITEM)
        </h3>
        <p style="font-size:11px;color:#64748b;margin-bottom:8px;">
            <em>Jika salah satu jawaban adalah YA, rencana kerja sama memerlukan reviu tambahan sebelum keputusan Gate 0 difinalisasi.</em>
        </p>
        <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
            <thead>
                <tr>
                    <th style="width:5%;text-align:center;">No</th>
                    <th style="width:35%;">Pertanyaan Trigger</th>
                    <th style="width:10%;text-align:center;">YA / TIDAK</th>
                    <th style="width:50%;">Tindak Lanjut &amp; Catatan Reviu</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (GATE0_TRIGGERS as $tNum => $trig): 
                    $tItem = $triggerData["t{$tNum}"] ?? ['jawab' => 'TIDAK', 'catatan' => ''];
                    $isYa = ($tItem['jawab'] === 'YA');
                ?>
                <tr style="<?= $isYa ? 'background:#fffbeb;' : '' ?>">
                    <td style="text-align:center;"><?= $tNum ?></td>
                    <td>
                        <strong><?= h($trig['judul']) ?></strong><br>
                        <span style="font-size:11px;color:#475569;"><?= h($trig['teks']) ?></span>
                    </td>
                    <td style="text-align:center;">
                        <span class="badge badge-<?= $isYa ? 'warning' : 'success' ?>"><?= $tItem['jawab'] ?></span>
                    </td>
                    <td style="font-size:11px;">
                        <strong>SOP Reviu:</strong> <?= h($trig['reviu']) ?><br>
                        <?php if (!empty($tItem['catatan'])): ?>
                        <span style="color:#b45309;"><strong>Catatan:</strong> <?= h($tItem['catatan']) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- D. Hasil Verifikasi & Rekomendasi -->
        <h3 style="font-size:14px;margin:18px 0 6px;background:#f8fafc;padding:6px 10px;border-left:4px solid #1e40af;">
            D. HASIL VERIFIKASI &amp; REKOMENDASI PENGELOLA KERJA SAMA
        </h3>
        <table style="width:100%;border-collapse:collapse;margin-bottom:14px;">
            <tr>
                <th style="width:30%;">Kesimpulan Verifikasi</th>
                <td>
                    <span class="badge badge-<?= $usulan['status_rekomendasi'] === 'Layak' ? 'success' : ($usulan['status_rekomendasi'] === 'Perlu Penyempurnaan' ? 'warning' : 'danger') ?>" style="font-size:12px;">
                        <?= h($usulan['status_rekomendasi']) ?>
                    </span>
                </td>
            </tr>
            <tr><th>Catatan Verifikator</th><td><?= nl2br(h($usulan['catatan_verifikasi'] ?? 'Seluruh aspek kelayakan awal telah diverifikasi.')) ?></td></tr>
            <tr><th>Gap yang Harus Ditutup</th><td><?= nl2br(h($usulan['gap_penyempurnaan'] ?? '-')) ?></td></tr>
            <tr><th>Unit / Fungsi Reviu Tambahan</th><td><?= h($usulan['unit_review_tambahan'] ?? '-') ?></td></tr>
            <tr><th>Batas Waktu Penyempurnaan</th><td><?= $usulan['batas_waktu_penyempurnaan'] ? formatTanggal($usulan['batas_waktu_penyempurnaan']) : '-' ?></td></tr>
        </table>

        <!-- E. Keputusan Pejabat Berwenang -->
        <h3 style="font-size:14px;margin:18px 0 6px;background:#f8fafc;padding:6px 10px;border-left:4px solid #1e40af;">
            E. KEPUTUSAN PEJABAT BERWENANG
        </h3>
        <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
            <tr>
                <th style="width:30%;">Status Keputusan</th>
                <td>
                    <span class="badge badge-<?= match($usulan['status_persetujuan']) { 'Disetujui Pimpinan' => 'success', 'Dikembalikan untuk Revisi' => 'warning', 'Ditolak Pimpinan' => 'danger', default => 'secondary' } ?>" style="font-size:13px;">
                        <?= h($usulan['status_persetujuan']) ?>
                    </span>
                </td>
            </tr>
            <tr><th>Arahan / Disposisi Pimpinan</th><td><?= nl2br(h($usulan['catatan_pimpinan'] ?? 'Belum ada arahan / menunggu putusan pimpinan.')) ?></td></tr>
            <tr><th>Pejabat yang Memutuskan</th><td><?= h($usulan['nama_pimpinan'] ?? 'Kepala Kantor Wilayah') ?></td></tr>
            <tr><th>Tanggal Keputusan</th><td><?= $usulan['tanggal_persetujuan'] ? formatTanggal($usulan['tanggal_persetujuan']) : '-' ?></td></tr>
        </table>

        <!-- Tanda Tangan -->
        <div class="sig-grid" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:30px;text-align:center;font-size:12px;">
            <div>
                <div>Unit Pemrakarsa,</div>
                <div style="height:60px;"></div>
                <div style="font-weight:700;text-decoration:underline;"><?= h($usulan['penanggung_jawab_usulan'] ?: $usulan['unit_pemrakarsa']) ?></div>
                <div class="muted">Pengusul Kerja Sama</div>
            </div>
            <div>
                <div>Pengelola Kerja Sama / Verifikator,</div>
                <div style="height:60px;"></div>
                <div style="font-weight:700;text-decoration:underline;">Bagian Tata Usaha dan Umum</div>
                <div class="muted">Kanwil Kementerian Hukum Kepri</div>
            </div>
            <div>
                <div>Kepala Kantor Wilayah,</div>
                <div style="height:60px;"></div>
                <div style="font-weight:700;text-decoration:underline;"><?= h($usulan['nama_pimpinan'] ?: 'Edison Manik') ?></div>
                <div class="muted">Pejabat Berwenang</div>
            </div>
        </div>
    </div>

    <?php
    if ($view === 'detail') {
        require __DIR__ . '/includes/footer.php';
    } else {
        echo '</body></html>';
    }
    exit;
}

// Data query
$usulanList = $pdo->query('SELECT * FROM pra_pks ORDER BY id DESC')->fetchAll();

$totalUsulan = count($usulanList);
$draftCount = count(array_filter($usulanList, fn($u) => ($u['status_persetujuan'] ?? '') === 'Draft'));
$menungguCount = count(array_filter($usulanList, fn($u) => ($u['status_persetujuan'] ?? '') === 'Menunggu Persetujuan Pimpinan'));
$disetujuiCount = count(array_filter($usulanList, fn($u) => ($u['status_persetujuan'] ?? '') === 'Disetujui Pimpinan'));
$revisiCount = count(array_filter($usulanList, fn($u) => ($u['status_persetujuan'] ?? '') === 'Dikembalikan untuk Revisi'));
$ditolakCount = count(array_filter($usulanList, fn($u) => ($u['status_persetujuan'] ?? '') === 'Ditolak Pimpinan'));
$siapPromosiCount = count(array_filter($usulanList, fn($u) => ($u['status_persetujuan'] ?? '') === 'Disetujui Pimpinan' && !$u['is_promoted_to_pks']));

$pageTitle = 'Gate 0 — Pra-Kerja Sama';
require __DIR__ . '/includes/header.php';
?>

<style>
.g0-tab-btn {
    padding: 6px 14px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
}
.g0-tab-btn:hover {
    background: #f1f5f9;
    color: #0f172a;
    border-color: #94a3b8;
}
.g0-tab-btn.active {
    background: #1e40af;
    color: #ffffff;
    border-color: #1d4ed8;
    box-shadow: 0 1px 3px rgba(30, 64, 175, 0.25);
}
.g0-tab-btn.active .badge-tab {
    background: rgba(255, 255, 255, 0.25) !important;
    color: #ffffff !important;
}
.badge-tab {
    font-size: 11px;
    padding: 1px 6px;
    border-radius: 10px;
    font-weight: 700;
}
.g0-page-btn {
    min-width: 32px;
    height: 32px;
    padding: 0 8px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    color: #334155;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}
.g0-page-btn:hover:not(:disabled) {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a;
}
.g0-page-btn.active {
    background: #1e40af;
    border-color: #1d4ed8;
    color: #ffffff;
    box-shadow: 0 1px 2px rgba(30, 64, 175, 0.25);
}
.g0-page-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
</style>

<div class="flex-between" style="margin-bottom:18px;">
    <div>
        <h1 style="margin:0;font-size:20px;">Gate 0 — Pra-Kerja Sama</h1>
        <div class="muted" style="font-size:13px;">Uji kelayakan calon kerja sama sebelum penandatanganan naskah</div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;background:#f8fafc;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px;">
        <label for="templateSelect" style="font-size:12px;font-weight:600;color:#1e293b;margin:0;">Jenis Kerja Sama:</label>
        <select id="templateSelect" style="font-size:12px;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;background:#fff;">
            <option value="public/templates/template_gate0_dalam_negeri.xlsx">Dalam Negeri (.xlsx)</option>
            <option value="public/templates/template_gate0_luar_negeri.xlsx">Luar Negeri (.xlsx)</option>
        </select>
        <button type="button" onclick="downloadSelectedTemplate()" class="btn btn-primary btn-sm" style="font-size:12px;display:inline-flex;align-items:center;gap:4px;">
            📥 Unduh Template Excel
        </button>
    </div>
    <script>
    function downloadSelectedTemplate() {
        var sel = document.getElementById('templateSelect');
        if (sel && sel.value) {
            var a = document.createElement('a');
            a.href = sel.value;
            a.download = sel.value.split('/').pop();
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    }
    </script>
</div>

<?php if ($success): ?><div class="alert alert-info"><?= $success ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

<!-- KPI Row -->
<div class="kpi-grid" style="margin-bottom:20px;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));">
    <div class="kpi-card">
        <div class="kpi-value"><?= $totalUsulan ?></div>
        <div class="kpi-label">Total Usulan</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color:#64748b;"><?= $draftCount ?></div>
        <div class="kpi-label">Draft Usulan</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color:#ca8a04;"><?= $menungguCount ?></div>
        <div class="kpi-label">Menunggu Persetujuan</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color:#16a34a;"><?= $disetujuiCount ?></div>
        <div class="kpi-label">Disetujui</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-value" style="color:#2563eb;"><?= $siapPromosiCount ?></div>
        <div class="kpi-label">Siap Jadi PKS</div>
    </div>
</div>

<!-- Daftar Usulan -->
<div class="card">
    <div class="flex-between" style="margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <h2 style="margin:0;">Daftar Usulan Pra-PKS</h2>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($canEdit): ?>
            <button onclick="openGate0Modal('importModal')" class="btn btn-outline btn-sm">📤 Import Excel / CSV</button>
            <button onclick="openGate0Modal('addModal')" class="btn btn-primary btn-sm">+ Usulan Baru</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Status Filter Tabs -->
    <div class="g0-tabs" style="display:flex;gap:8px;overflow-x:auto;padding-bottom:8px;margin-bottom:12px;-webkit-overflow-scrolling:touch;">
        <button type="button" class="g0-tab-btn active" data-status="" onclick="setGate0StatusTab('', this)">
            Semua <span class="badge-tab" style="background:#e2e8f0;color:#334155;"><?= $totalUsulan ?></span>
        </button>
        <button type="button" class="g0-tab-btn" data-status="Draft" onclick="setGate0StatusTab('Draft', this)">
            📝 Draft <span class="badge-tab" style="background:#f1f5f9;color:#475569;"><?= $draftCount ?></span>
        </button>
        <button type="button" class="g0-tab-btn" data-status="Menunggu Persetujuan Pimpinan" onclick="setGate0StatusTab('Menunggu Persetujuan Pimpinan', this)">
            ⏳ Menunggu <span class="badge-tab" style="background:#fef3c7;color:#92400e;"><?= $menungguCount ?></span>
        </button>
        <button type="button" class="g0-tab-btn" data-status="Disetujui Pimpinan" onclick="setGate0StatusTab('Disetujui Pimpinan', this)">
            ✅ Disetujui <span class="badge-tab" style="background:#dcfce7;color:#166534;"><?= $disetujuiCount ?></span>
        </button>
        <button type="button" class="g0-tab-btn" data-status="Dikembalikan untuk Revisi" onclick="setGate0StatusTab('Dikembalikan untuk Revisi', this)">
            ↩️ Revisi <span class="badge-tab" style="background:#ffedd5;color:#9a3412;"><?= $revisiCount ?></span>
        </button>
        <button type="button" class="g0-tab-btn" data-status="Ditolak Pimpinan" onclick="setGate0StatusTab('Ditolak Pimpinan', this)">
            ❌ Ditolak <span class="badge-tab" style="background:#fee2e2;color:#991b1b;"><?= $ditolakCount ?></span>
        </button>
    </div>

    <!-- Filter & Search Bar -->
    <div class="filters" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;background:#f8fafc;padding:10px 14px;border-radius:8px;border:1px solid var(--border,#e2e8f0);">
        <div style="flex:1;min-width:220px;">
            <input type="text" id="g0Search" placeholder="🔍 Cari Nomor Usulan / Calon Mitra / Judul..." style="width:100%;padding:7px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff;" oninput="onGate0FilterChange()">
        </div>
        <div style="min-width:180px;">
            <select id="g0StatusFilter" style="width:100%;padding:7px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff;font-weight:600;color:#1e293b;" onchange="onGate0StatusSelectChange()">
                <option value="">Semua Status Persetujuan</option>
                <option value="Draft">Draft</option>
                <option value="Menunggu Persetujuan Pimpinan">Menunggu Persetujuan Pimpinan</option>
                <option value="Disetujui Pimpinan">Disetujui Pimpinan</option>
                <option value="Dikembalikan untuk Revisi">Dikembalikan untuk Revisi</option>
                <option value="Ditolak Pimpinan">Ditolak Pimpinan</option>
            </select>
        </div>
        <div style="min-width:140px;">
            <select id="g0TipeFilter" style="width:100%;padding:7px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;background:#fff;" onchange="onGate0FilterChange()">
                <option value="">Semua Tipe</option>
                <option value="Dalam Negeri">Dalam Negeri</option>
                <option value="Luar Negeri">Luar Negeri</option>
            </select>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
            <label for="g0PageSize" style="font-size:12px;color:#64748b;margin:0;white-space:nowrap;">Tampil:</label>
            <select id="g0PageSize" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12.5px;background:#fff;" onchange="onGate0PageSizeChange()">
                <option value="10" selected>10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="all">Semua</option>
            </select>
        </div>
        <button type="button" onclick="resetGate0Filters()" class="btn btn-outline btn-sm" style="padding:7px 14px;font-size:12.5px;display:inline-flex;align-items:center;gap:4px;cursor:pointer;" title="Reset Filter Gate 0">
            🔄 Reset Filter
        </button>
    </div>

    <div class="table-wrap">
        <table id="tblGate0">
            <thead>
                <tr>
                    <th>Nomor Usulan</th>
                    <th>Tipe / Jenis</th>
                    <th>Calon Mitra &amp; Judul Rencana</th>
                    <th>5 Kriteria Inti</th>
                    <th>Trigger Khusus</th>
                    <th>Rekomendasi</th>
                    <th>Status Persetujuan</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($usulanList)): ?>
                <tr><td colspan="8" class="muted" style="text-align:center;padding:24px;">Belum ada usulan pra-PKS terdaftar.</td></tr>
                <?php else: ?>
                <tr id="g0NoMatchRow" style="display:none;"><td colspan="8" class="muted" style="text-align:center;padding:24px;">Tidak ada usulan yang sesuai dengan filter pencarian.</td></tr>
                <?php foreach ($usulanList as $u): 
                    $rekBadge = match($u['status_rekomendasi']) {
                        'Layak' => 'success',
                        'Perlu Penyempurnaan' => 'warning',
                        default => 'danger'
                    };
                    $stApp = $u['status_persetujuan'] ?? 'Draft';
                    $appBadge = match($stApp) {
                        'Disetujui Pimpinan' => 'success',
                        'Dikembalikan untuk Revisi' => 'warning',
                        'Ditolak Pimpinan' => 'danger',
                        'Menunggu Persetujuan Pimpinan' => 'primary',
                        'Draft' => 'secondary',
                        default => 'secondary'
                    };
                    $tData = json_decode($u['trigger_khusus'] ?? '[]', true) ?: [];
                    $activeTriggers = [];
                    foreach ($tData as $k => $tv) {
                        if (($tv['jawab'] ?? '') === 'YA') {
                            $tNum = (int)substr($k, 1);
                            $activeTriggers[] = GATE0_TRIGGERS[$tNum]['judul'] ?? "Trigger $tNum";
                        }
                    }

                    // Requirement 4: jika status menunggu persetujuan, disetujui dan ditolak, tidak ada tombol edit
                    $isNonEditable = in_array($stApp, ['Menunggu Persetujuan Pimpinan', 'Disetujui Pimpinan', 'Ditolak Pimpinan'], true);
                    $canShowEdit = $canEdit && !$isNonEditable;

                    $uData = [
                        'id' => (int)$u['id'],
                        'nomor_usulan' => $u['nomor_usulan'],
                        'tipe_kerjasama' => $u['tipe_kerjasama'],
                        'jenis_naskah' => $u['jenis_naskah'],
                        'unit_pemrakarsa' => $u['unit_pemrakarsa'],
                        'penanggung_jawab_usulan' => $u['penanggung_jawab_usulan'] ?? '',
                        'calon_mitra' => $u['calon_mitra'],
                        'judul_rencana' => $u['judul_rencana'],
                        'tujuan_singkat' => $u['tujuan_singkat'] ?? '',
                        'ruang_lingkup' => $u['ruang_lingkup'] ?? '',
                        'penerima_manfaat' => $u['penerima_manfaat'] ?? '',
                        'perkiraan_mulai' => $u['perkiraan_mulai'] ?? '',
                        'perkiraan_selesai' => $u['perkiraan_selesai'] ?? '',
                        'file_usulan' => $u['file_usulan'] ?? '',
                        'pertanyaan_uji' => json_decode($u['pertanyaan_uji'] ?? '[]', true) ?: [],
                        'trigger_khusus' => json_decode($u['trigger_khusus'] ?? '[]', true) ?: [],
                        'catatan_verifikasi' => $u['catatan_verifikasi'] ?? '',
                        'gap_penyempurnaan' => $u['gap_penyempurnaan'] ?? '',
                        'unit_review_tambahan' => $u['unit_review_tambahan'] ?? '',
                        'batas_waktu_penyempurnaan' => $u['batas_waktu_penyempurnaan'] ?? '',
                        'status_rekomendasi' => $u['status_rekomendasi'] ?? '',
                        'status_persetujuan' => $stApp,
                    ];
                ?>
                <tr class="g0-row" data-nomor="<?= strtolower(h($u['nomor_usulan'])) ?>" data-mitra="<?= strtolower(h($u['calon_mitra'])) ?>" data-judul="<?= strtolower(h($u['judul_rencana'])) ?>" data-status-persetujuan="<?= h($stApp) ?>" data-tipe="<?= h($u['tipe_kerjasama']) ?>">
                    <td>
                        <a href="gate0.php?view=detail&id=<?= $u['id'] ?>" title="Lihat detail formulir resmi" style="color:#1d4ed8;text-decoration:none;">
                            <strong><?= h($u['nomor_usulan']) ?></strong>
                        </a><br>
                        <span class="muted" style="font-size:11px;"><?= formatTanggal($u['created_at']) ?></span>
                    </td>
                    <td>
                        <span class="badge badge-<?= $u['tipe_kerjasama'] === 'Dalam Negeri' ? 'primary' : 'info' ?>" style="font-size:10px;"><?= h($u['tipe_kerjasama']) ?></span>
                        <div class="muted" style="font-size:11px;margin-top:2px;"><?= h($u['jenis_naskah']) ?></div>
                    </td>
                    <td>
                        <strong><?= h($u['calon_mitra']) ?></strong>
                        <div style="font-size:12px;color:#334155;margin-top:2px;"><?= h(singkat($u['judul_rencana'], 50)) ?></div>
                        <div class="muted" style="font-size:11px;">Pemrakarsa: <?= h($u['unit_pemrakarsa']) ?></div>
                        <?php if (!empty($u['file_usulan'])): ?>
                        <div style="margin-top:3px;">
                            <a href="<?= h($u['file_usulan']) ?>" target="_blank" style="font-size:10.5px;color:#059669;text-decoration:none;display:inline-flex;align-items:center;gap:3px;font-weight:600;">
                                📎 File Lampiran
                            </a>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size:11px;line-height:1.4;">
                            K1: <span style="font-weight:700;color:<?= $u['k1_kesesuaian_strategis']==='YA'?'#16a34a':'#dc2626' ?>"><?= $u['k1_kesesuaian_strategis'] ?></span> |
                            K2: <span style="font-weight:700;color:<?= $u['k2_kebutuhan_daya_ungkit']==='YA'?'#16a34a':'#dc2626' ?>"><?= $u['k2_kebutuhan_daya_ungkit'] ?></span> |
                            K3: <span style="font-weight:700;color:<?= $u['k3_kelayakan_mitra']==='YA'?'#16a34a':'#dc2626' ?>"><?= $u['k3_kelayakan_mitra'] ?></span><br>
                            K4: <span style="font-weight:700;color:<?= $u['k4_kesiapan_sumber_daya']==='YA'?'#16a34a':'#dc2626' ?>"><?= $u['k4_kesiapan_sumber_daya'] ?></span> |
                            K5: <span style="font-weight:700;color:<?= $u['k5_risiko_keberlanjutan']==='YA'?'#16a34a':'#dc2626' ?>"><?= $u['k5_risiko_keberlanjutan'] ?></span>
                        </div>
                    </td>
                    <td>
                        <?php if (empty($activeTriggers)): ?>
                        <span class="badge badge-success" style="font-size:10px;">Aman (0 Trigger)</span>
                        <?php else: ?>
                        <span class="badge badge-warning" style="font-size:10px;">⚠️ <?= count($activeTriggers) ?> Trigger Aktif</span>
                        <div class="muted" style="font-size:10px;margin-top:2px;max-width:140px;"><?= h(implode(', ', $activeTriggers)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-<?= $rekBadge ?>"><?= h($u['status_rekomendasi']) ?></span></td>
                    <td>
                        <span class="badge badge-<?= $appBadge ?>"><?= h($stApp) ?></span>
                        <?php if ($u['catatan_pimpinan']): ?>
                        <div class="muted" style="font-size:11px;margin-top:3px;max-width:160px;">"<?= h(singkat($u['catatan_pimpinan'], 35)) ?>"</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:4px;min-width:130px;">
                            <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                <?php if ($canShowEdit): ?>
                                <button type="button" class="btn btn-outline btn-sm" onclick='openEditGate0Modal(<?= htmlspecialchars(json_encode($uData), ENT_QUOTES, "UTF-8") ?>)' style="font-size:11px;padding:3px 7px;" title="Edit usulan dalam jendela pop-up">✏️ Edit</button>
                                <?php endif; ?>
                                <a href="gate0.php?view=print&id=<?= $u['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 7px;" title="Cetak formulir resmi">🖨️ Cetak</a>
                                
                                <?php if ($canDecide && in_array($stApp, ['Menunggu Persetujuan Pimpinan', 'Dikembalikan untuk Revisi'], true)): ?>
                                <button onclick="openDecisionModal(<?= (int)$u['id'] ?>, <?= htmlspecialchars(json_encode((string)$u['nomor_usulan']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$u['calon_mitra']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-warning btn-sm" style="font-size:11px;padding:3px 7px;">⚖️ Putusan</button>
                                <?php endif; ?>

                                <?php if ($stApp === 'Disetujui Pimpinan'): ?>
                                    <?php if (!$u['is_promoted_to_pks']): ?>
                                        <?php if ($canEdit): ?>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Promosikan usulan ini menjadi PKS aktif dengan Rencana Kerja?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="promote">
                                            <input type="hidden" name="usulan_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm" style="font-size:11px;padding:3px 7px;">🚀 Jadi PKS</button>
                                        </form>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge badge-info" style="font-size:10px;">✓ Jadi PKS</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($canEdit && ($stApp === 'Draft' || empty($stApp))): ?>
                            <form method="post" style="margin:0;padding:0;width:100%;" onsubmit="return confirm('Submit usulan <?= h($u['nomor_usulan']) ?> ke Pimpinan? Status akan menjadi final (Menunggu Persetujuan Pimpinan).');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="submit_proposal">
                                <input type="hidden" name="usulan_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-gate0-submit" style="background:#16a34a;color:#ffffff;border:1px solid #15803d;border-radius:4px;font-size:11px;font-weight:700;padding:4px 8px;width:100%;display:flex;align-items:center;justify-content:center;gap:4px;cursor:pointer;margin-top:2px;box-shadow:0 1px 2px rgba(0,0,0,0.06);" title="Submit usulan ke Pimpinan">
                                    <span>✓</span> Submit
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Footer -->
    <div id="g0PaginationWrap" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">
        <div id="g0PageInfo" style="font-size:12.5px;color:#64748b;font-weight:500;">
            Menampilkan 0 usulan
        </div>
        <div id="g0PaginationNav" style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;">
            <!-- Generated dynamically by renderGate0Table() -->
        </div>
    </div>
</div>

<!-- Modal Backdrop Overlay -->
<div id="modalBackdrop" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:9990;backdrop-filter:blur(2px);" onclick="closeAllGate0Modals()"></div>

<!-- Modal Tambah Usulan Lengkap (Formulir Gate 0) -->
<div id="addModal" class="card" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9999;width:92%;max-width:850px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 40px rgba(0,0,0,0.3);background:#fff;">
    <div class="flex-between" style="border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:14px;">
        <h2 style="margin:0;font-size:18px;">Input Usulan Baru Gate 0 (Formulir Resmi)</h2>
        <button type="button" onclick="closeAllGate0Modals()" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">

        <h3 style="font-size:14px;color:#1e40af;margin:10px 0 8px;">A. Identitas Usulan Kerja Sama</h3>
        <div class="form-grid">
            <div class="field"><label>Nomor Usulan *</label><input type="text" name="nomor_usulan" required placeholder="PRA-2026-003"></div>
            <div class="field"><label>Tipe Kerja Sama</label><select name="tipe_kerjasama"><option>Dalam Negeri</option><option>Luar Negeri</option></select></div>
            <div class="field"><label>Jenis Naskah</label><select name="jenis_naskah"><option>PKS</option><option>MoU</option><option>Lainnya</option></select></div>
            <div class="field"><label>Unit Pemrakarsa *</label><input type="text" name="unit_pemrakarsa" required placeholder="Contoh: Divisi Pelayanan Hukum"></div>
            <div class="field"><label>Penanggung Jawab Usulan (PIC)</label><input type="text" name="penanggung_jawab_usulan" placeholder="Nama pejabat/penyusun"></div>
            <div class="field"><label>Calon Mitra *</label><input type="text" name="calon_mitra" required placeholder="Nama instansi/lembaga mitra"></div>
            <div class="field" style="grid-column:1/-1;"><label>Judul Rencana Kerja Sama *</label><input type="text" name="judul_rencana" required placeholder="Judul kerja sama yang direncanakan"></div>
            <div class="field" style="grid-column:1/-1;"><label>Tujuan Singkat</label><textarea name="tujuan_singkat" rows="2" placeholder="Tujuan kerja sama..."></textarea></div>
            <div class="field" style="grid-column:1/-1;"><label>Ruang Lingkup Utama</label><textarea name="ruang_lingkup" rows="2" placeholder="Bidang/kegiatan yang disepakati..."></textarea></div>
            <div class="field" style="grid-column:1/-1;"><label>Penerima Manfaat</label><input type="text" name="penerima_manfaat" placeholder="Kelompok masyarakat / instansi penerima manfaat"></div>
            <div class="field"><label>Perkiraan Mulai</label><input type="date" name="perkiraan_mulai"></div>
            <div class="field"><label>Perkiraan Selesai</label><input type="date" name="perkiraan_selesai"></div>
        </div>

        <h3 style="font-size:14px;color:#1e40af;margin:18px 0 8px;border-top:1px solid #e2e8f0;padding-top:14px;">B. 18 Pertanyaan Uji (5 Kriteria Inti Gate 0)</h3>
        <p class="muted" style="font-size:12px;margin-bottom:12px;">Satu kriteria dinyatakan YA apabila seluruh sub-pertanyaan wajib di dalamnya dijawab YA berdasarkan bukti/keterangan yang memadai.</p>

        <?php foreach (GATE0_CRITERIA as $kCode => $crit): ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;margin-bottom:12px;">
            <div style="font-weight:700;font-size:13px;color:#1e293b;margin-bottom:8px;">
                <?= $kCode ?>. <?= $crit['nama'] ?>
            </div>
            <?php foreach ($crit['questions'] as $qNum => $qText): ?>
            <div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px dashed #cbd5e1;">
                <div style="font-size:12px;color:#334155;margin-bottom:4px;">
                    <strong><?= $qNum ?>.</strong> <?= h($qText) ?>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <select name="q<?= $qNum ?>_jawab" style="width:100px;">
                        <option value="YA">YA</option>
                        <option value="TIDAK">TIDAK</option>
                    </select>
                    <input type="text" name="q<?= $qNum ?>_bukti" placeholder="Bukti / keterangan pendukung..." style="flex:1;">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <h3 style="font-size:14px;color:#1e40af;margin:18px 0 8px;border-top:1px solid #e2e8f0;padding-top:14px;">C. Trigger Karakteristik Khusus (7 Item)</h3>
        <p class="muted" style="font-size:12px;margin-bottom:12px;">Jika dijawab YA, kerja sama memerlukan reviu tambahan dari fungsi terkait sebelum putusan pimpinan.</p>
        
        <div style="background:#fffbeb;border:1px solid #fef3c7;border-radius:6px;padding:12px;margin-bottom:14px;">
            <?php foreach (GATE0_TRIGGERS as $tNum => $trig): ?>
            <div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px dashed #fde68a;">
                <div style="font-size:12px;font-weight:700;color:#92400e;"><?= $tNum ?>. <?= h($trig['judul']) ?></div>
                <div style="font-size:11px;color:#78350f;margin-bottom:4px;"><?= h($trig['teks']) ?></div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <select name="trigger<?= $tNum ?>_jawab" style="width:100px;">
                        <option value="TIDAK">TIDAK</option>
                        <option value="YA">YA</option>
                    </select>
                    <input type="text" name="trigger<?= $tNum ?>_catatan" placeholder="Catatan / SOP reviu: <?= h($trig['reviu']) ?>" style="flex:1;">
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <h3 style="font-size:14px;color:#1e40af;margin:18px 0 8px;border-top:1px solid #e2e8f0;padding-top:14px;">D. Catatan &amp; Gap Verifikasi</h3>
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Catatan Verifikasi Ringkas</label>
                <textarea name="catatan_verifikasi" placeholder="Catatan kelayakan umum..."></textarea>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Gap yang Harus Ditutup (Bila Perlu Penyempurnaan)</label>
                <textarea name="gap_penyempurnaan" placeholder="Daftar kekurangan yang wajib dilengkapi unit pemrakarsa..."></textarea>
            </div>
            <div class="field">
                <label>Unit / Fungsi Reviu Tambahan</label>
                <input type="text" name="unit_review_tambahan" placeholder="Contoh: Biro Hukerma / Tim TI / Bagian Keuangan">
            </div>
            <div class="field">
                <label>Batas Waktu Penyempurnaan</label>
                <input type="date" name="batas_waktu_penyempurnaan">
            </div>
        </div>

        <div style="margin-top:18px;display:flex;gap:10px;border-top:1px solid #e2e8f0;padding-top:14px;">
            <button type="submit" class="btn btn-primary">Simpan &amp; Ajukan Usulan</button>
            <button type="button" onclick="closeAllGate0Modals()" class="btn btn-outline">Batal</button>
        </div>
    </form>
</div>

<!-- Modal Keputusan Pimpinan -->
<div id="decisionModal" class="card" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9999;width:90%;max-width:500px;box-shadow:0 20px 40px rgba(0,0,0,0.3);background:#fff;">
    <div class="flex-between" style="border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:14px;">
        <h2 style="margin:0;font-size:18px;">Keputusan Pimpinan (Gate 0)</h2>
        <button type="button" onclick="closeAllGate0Modals()" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="decision">
        <input type="hidden" name="usulan_id" id="decUsulanId">
        <div style="margin-bottom:12px;font-size:13px;">
            Usulan: <strong id="decUsulanNo"></strong> &mdash; <span id="decCalonMitra"></span>
        </div>
        <div class="field">
            <label>Keputusan</label>
            <select name="status_persetujuan" required>
                <option value="Disetujui Pimpinan">Disetujui Pimpinan (Layak Diproses ke PKS)</option>
                <option value="Dikembalikan untuk Revisi">Dikembalikan untuk Revisi (Tutup Gap)</option>
                <option value="Ditolak Pimpinan">Ditolak Pimpinan (Tidak Prioritas / Tidak Layak)</option>
            </select>
        </div>
        <div class="field" style="margin-top:10px;">
            <label>Arahan / Disposisi Pimpinan</label>
            <textarea name="catatan_pimpinan" rows="3" placeholder="Instruksi, disposisi, atau alasan keputusan..."></textarea>
        </div>
        <div style="margin-top:16px;display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Simpan Keputusan</button>
            <button type="button" onclick="closeAllGate0Modals()" class="btn btn-outline">Batal</button>
        </div>
    </form>
</div>

<!-- Modal Import Excel (.xlsx) / CSV -->
<div id="importModal" class="card" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9999;width:90%;max-width:500px;box-shadow:0 20px 40px rgba(0,0,0,0.3);background:#fff;">
    <div class="flex-between" style="border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:14px;">
        <h2 style="margin:0;font-size:18px;">Import Usulan Pra-PKS (Excel / CSV)</h2>
        <button type="button" onclick="closeAllGate0Modals()" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="import_csv">
        <p style="font-size:13px;" class="muted">
            Gunakan format Excel (.xlsx) atau CSV sesuai template yang disediakan pada tombol di kanan atas.
        </p>
        <div class="field" style="margin:14px 0;">
            <label>Pilih File Excel (.xlsx) atau CSV</label>
            <input type="file" name="csv_file" accept=".xlsx,.csv" required>
        </div>
        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn btn-primary">Upload &amp; Import</button>
            <button type="button" onclick="closeAllGate0Modals()" class="btn btn-outline">Batal</button>
        </div>
    </form>
</div>

<!-- Modal Edit Usulan (Jendela Pop-up Form & Upload File Baru) -->
<div id="editModal" class="card" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9999;width:92%;max-width:860px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 40px rgba(0,0,0,0.35);background:#fff;border-radius:10px;">
    <div class="flex-between" style="border-bottom:1px solid #e2e8f0;padding-bottom:12px;margin-bottom:16px;">
        <div>
            <h2 style="margin:0;font-size:18px;color:#1e293b;">Edit Usulan Pra-PKS: <span id="editModalNomorTitle" style="color:#2563eb;"></span></h2>
            <div class="muted" style="font-size:12px;margin-top:2px;">Perbarui data formulir usulan kerja sama atau unggah file baru</div>
        </div>
        <button type="button" onclick="closeAllGate0Modals()" style="background:none;border:none;font-size:24px;cursor:pointer;color:#64748b;">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="usulan_id" id="editUsulanId">

        <h3 style="font-size:14px;color:#1e40af;margin:10px 0 8px;">A. Identitas Usulan Kerja Sama</h3>
        <div class="form-grid">
            <div class="field"><label>Nomor Usulan *</label><input type="text" name="nomor_usulan" id="editNomorUsulan" required></div>
            <div class="field"><label>Tipe Kerja Sama</label><select name="tipe_kerjasama" id="editTipeKerjasama"><option>Dalam Negeri</option><option>Luar Negeri</option></select></div>
            <div class="field"><label>Jenis Naskah</label><select name="jenis_naskah" id="editJenisNaskah"><option>PKS</option><option>MoU</option><option>Lainnya</option></select></div>
            <div class="field"><label>Unit Pemrakarsa *</label><input type="text" name="unit_pemrakarsa" id="editUnitPemrakarsa" required></div>
            <div class="field"><label>Penanggung Jawab Usulan (PIC)</label><input type="text" name="penanggung_jawab_usulan" id="editPenanggungJawab"></div>
            <div class="field"><label>Calon Mitra *</label><input type="text" name="calon_mitra" id="editCalonMitra" required></div>
            <div class="field" style="grid-column:1/-1;"><label>Judul Rencana Kerja Sama *</label><input type="text" name="judul_rencana" id="editJudulRencana" required></div>
            <div class="field" style="grid-column:1/-1;"><label>Tujuan Singkat</label><textarea name="tujuan_singkat" id="editTujuanSingkat" rows="2"></textarea></div>
            <div class="field" style="grid-column:1/-1;"><label>Ruang Lingkup Utama</label><textarea name="ruang_lingkup" id="editRuangLingkup" rows="2"></textarea></div>
            <div class="field" style="grid-column:1/-1;"><label>Penerima Manfaat</label><input type="text" name="penerima_manfaat" id="editPenerimaManfaat"></div>
            <div class="field"><label>Perkiraan Mulai</label><input type="date" name="perkiraan_mulai" id="editPerkiraanMulai"></div>
            <div class="field"><label>Perkiraan Selesai</label><input type="date" name="perkiraan_selesai" id="editPerkiraanSelesai"></div>
            <div class="field" style="grid-column:1/-1;background:#f8fafc;padding:10px 12px;border:1px dashed #cbd5e1;border-radius:6px;">
                <label style="font-weight:700;color:#1e293b;margin-bottom:4px;">📎 File Lampiran / Dokumen Usulan Baru</label>
                <div id="editCurrentFileBox" style="margin-bottom:6px;font-size:12px;"></div>
                <input type="file" name="file_naskah_baru" accept=".pdf,.docx,.xlsx,.csv">
                <div class="muted" style="font-size:11px;margin-top:2px;">Format didukung: PDF, DOCX, XLSX, CSV (maksimal 25MB). Unggah file baru untuk mengganti file lama.</div>
            </div>
        </div>

        <h3 style="font-size:14px;color:#1e40af;margin:18px 0 8px;border-top:1px solid #e2e8f0;padding-top:14px;">B. 18 Pertanyaan Uji (5 Kriteria Inti Gate 0)</h3>
        <p class="muted" style="font-size:12px;margin-bottom:12px;">Satu kriteria dinyatakan YA apabila seluruh sub-pertanyaan wajib di dalamnya dijawab YA berdasarkan bukti/keterangan yang memadai.</p>

        <?php foreach (GATE0_CRITERIA as $kCode => $crit): ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:12px;margin-bottom:12px;">
            <div style="font-weight:700;font-size:13px;color:#1e293b;margin-bottom:8px;">
                <?= $kCode ?>. <?= $crit['nama'] ?>
            </div>
            <?php foreach ($crit['questions'] as $qNum => $qText): ?>
            <div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px dashed #cbd5e1;">
                <div style="font-size:12px;color:#334155;margin-bottom:4px;">
                    <strong><?= $qNum ?>.</strong> <?= h($qText) ?>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <select name="q<?= $qNum ?>_jawab" id="edit_q<?= $qNum ?>_jawab" style="width:100px;">
                        <option value="YA">YA</option>
                        <option value="TIDAK">TIDAK</option>
                    </select>
                    <input type="text" name="q<?= $qNum ?>_bukti" id="edit_q<?= $qNum ?>_bukti" placeholder="Bukti / keterangan pendukung..." style="flex:1;">
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <h3 style="font-size:14px;color:#1e40af;margin:18px 0 8px;border-top:1px solid #e2e8f0;padding-top:14px;">C. Trigger Karakteristik Khusus (7 Item)</h3>
        <p class="muted" style="font-size:12px;margin-bottom:12px;">Jika dijawab YA, kerja sama memerlukan reviu tambahan dari fungsi terkait sebelum putusan pimpinan.</p>
        
        <div style="background:#fffbeb;border:1px solid #fef3c7;border-radius:6px;padding:12px;margin-bottom:14px;">
            <?php foreach (GATE0_TRIGGERS as $tNum => $trig): ?>
            <div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px dashed #fde68a;">
                <div style="font-size:12px;font-weight:700;color:#92400e;"><?= $tNum ?>. <?= h($trig['judul']) ?></div>
                <div style="font-size:11px;color:#78350f;margin-bottom:4px;"><?= h($trig['teks']) ?></div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <select name="trigger<?= $tNum ?>_jawab" id="edit_trigger<?= $tNum ?>_jawab" style="width:100px;">
                        <option value="TIDAK">TIDAK</option>
                        <option value="YA">YA</option>
                    </select>
                    <input type="text" name="trigger<?= $tNum ?>_catatan" id="edit_trigger<?= $tNum ?>_catatan" placeholder="Catatan / SOP reviu: <?= h($trig['reviu']) ?>" style="flex:1;">
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <h3 style="font-size:14px;color:#1e40af;margin:18px 0 8px;border-top:1px solid #e2e8f0;padding-top:14px;">D. Catatan &amp; Gap Verifikasi</h3>
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Catatan Verifikasi Ringkas</label>
                <textarea name="catatan_verifikasi" id="editCatatanVerifikasi" placeholder="Catatan kelayakan umum..."></textarea>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Gap yang Harus Ditutup (Bila Perlu Penyempurnaan)</label>
                <textarea name="gap_penyempurnaan" id="editGapPenyempurnaan" placeholder="Daftar kekurangan yang wajib dilengkapi unit pemrakarsa..."></textarea>
            </div>
            <div class="field">
                <label>Unit / Fungsi Reviu Tambahan</label>
                <input type="text" name="unit_review_tambahan" id="editUnitReviewTambahan" placeholder="Contoh: Biro Hukerma / Tim TI / Bagian Keuangan">
            </div>
            <div class="field">
                <label>Batas Waktu Penyempurnaan</label>
                <input type="date" name="batas_waktu_penyempurnaan" id="editBatasWaktu">
            </div>
        </div>

        <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;border-top:1px solid #e2e8f0;padding-top:14px;">
            <button type="submit" name="submit_mode" value="save_draft" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:4px;">💾 Simpan Usulan</button>
            <button type="submit" name="submit_mode" value="submit_final" class="btn btn-success" style="background:#16a34a;border-color:#15803d;color:#ffffff;display:inline-flex;align-items:center;gap:4px;">🚀 Simpan &amp; Ajukan ke Pimpinan</button>
            <button type="button" onclick="closeAllGate0Modals()" class="btn btn-outline">Batal</button>
        </div>
    </form>
</div>

<script>
function openGate0Modal(modalId) {
    var m = document.getElementById(modalId);
    if (m) m.style.display = 'block';
    var bg = document.getElementById('modalBackdrop');
    if (bg) bg.style.display = 'block';
}

function closeAllGate0Modals() {
    ['addModal', 'editModal', 'decisionModal', 'importModal'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    var bg = document.getElementById('modalBackdrop');
    if (bg) bg.style.display = 'none';
}

function openEditGate0Modal(data) {
    document.getElementById('editUsulanId').value = data.id || '';
    document.getElementById('editModalNomorTitle').textContent = data.nomor_usulan || '';
    document.getElementById('editNomorUsulan').value = data.nomor_usulan || '';
    document.getElementById('editTipeKerjasama').value = data.tipe_kerjasama || 'Dalam Negeri';
    document.getElementById('editJenisNaskah').value = data.jenis_naskah || 'PKS';
    document.getElementById('editUnitPemrakarsa').value = data.unit_pemrakarsa || '';
    document.getElementById('editPenanggungJawab').value = data.penanggung_jawab_usulan || '';
    document.getElementById('editCalonMitra').value = data.calon_mitra || '';
    document.getElementById('editJudulRencana').value = data.judul_rencana || '';
    document.getElementById('editTujuanSingkat').value = data.tujuan_singkat || '';
    document.getElementById('editRuangLingkup').value = data.ruang_lingkup || '';
    document.getElementById('editPenerimaManfaat').value = data.penerima_manfaat || '';
    document.getElementById('editPerkiraanMulai').value = data.perkiraan_mulai || '';
    document.getElementById('editPerkiraanSelesai').value = data.perkiraan_selesai || '';

    var fileBox = document.getElementById('editCurrentFileBox');
    if (fileBox) {
        if (data.file_usulan) {
            fileBox.innerHTML = '<span style="color:#16a34a;font-weight:600;">✓ File saat ini:</span> <a href="' + encodeURI(data.file_usulan) + '" target="_blank" style="color:#2563eb;text-decoration:underline;font-weight:600;">' + data.file_usulan.split('/').pop() + '</a>';
        } else {
            fileBox.innerHTML = '<span style="color:#64748b;">Belum ada lampiran file.</span>';
        }
    }

    var pData = data.pertanyaan_uji || {};
    for (var q = 1; q <= 18; q++) {
        var qObj = pData['q' + q] || {};
        var jEl = document.getElementById('edit_q' + q + '_jawab');
        var bEl = document.getElementById('edit_q' + q + '_bukti');
        if (jEl) jEl.value = (qObj.jawab === 'TIDAK') ? 'TIDAK' : 'YA';
        if (bEl) bEl.value = qObj.bukti || '';
    }

    var tData = data.trigger_khusus || {};
    for (var t = 1; t <= 7; t++) {
        var tObj = tData['t' + t] || {};
        var tjEl = document.getElementById('edit_trigger' + t + '_jawab');
        var tcEl = document.getElementById('edit_trigger' + t + '_catatan');
        if (tjEl) tjEl.value = (tObj.jawab === 'YA') ? 'YA' : 'TIDAK';
        if (tcEl) tcEl.value = tObj.catatan || '';
    }

    document.getElementById('editCatatanVerifikasi').value = data.catatan_verifikasi || '';
    document.getElementById('editGapPenyempurnaan').value = data.gap_penyempurnaan || '';
    document.getElementById('editUnitReviewTambahan').value = data.unit_review_tambahan || '';
    document.getElementById('editBatasWaktu').value = data.batas_waktu_penyempurnaan || '';

    openGate0Modal('editModal');
}

function openDecisionModal(id, no, mitra) {
    document.getElementById('decUsulanId').value = id;
    document.getElementById('decUsulanNo').textContent = no;
    document.getElementById('decCalonMitra').textContent = mitra;
    // In openDecisionModal(), clear/reset catatan_pimpinan textarea and status_persetujuan select on open
    var statusSelect = document.querySelector('#decisionModal select[name="status_persetujuan"]');
    if (statusSelect) statusSelect.selectedIndex = 0;
    var catatanText = document.querySelector('#decisionModal textarea[name="catatan_pimpinan"]');
    if (catatanText) catatanText.value = '';
    openGate0Modal('decisionModal');
}

var g0CurrentPage = 1;
var g0PageSize = 10;
var g0ActiveStatus = '';

function setGate0StatusTab(statusVal, btnEl) {
    g0ActiveStatus = statusVal;
    var statusSelect = document.getElementById('g0StatusFilter');
    if (statusSelect) statusSelect.value = statusVal;

    var tabs = document.querySelectorAll('.g0-tab-btn');
    tabs.forEach(function(t) { t.classList.remove('active'); });
    if (btnEl) btnEl.classList.add('active');

    g0CurrentPage = 1;
    renderGate0Table();
}

function onGate0StatusSelectChange() {
    var statusSelect = document.getElementById('g0StatusFilter');
    g0ActiveStatus = statusSelect ? statusSelect.value : '';

    var tabs = document.querySelectorAll('.g0-tab-btn');
    tabs.forEach(function(t) {
        var tabVal = t.getAttribute('data-status') || '';
        if (tabVal === g0ActiveStatus) t.classList.add('active');
        else t.classList.remove('active');
    });

    g0CurrentPage = 1;
    renderGate0Table();
}

function onGate0FilterChange() {
    g0CurrentPage = 1;
    renderGate0Table();
}

function onGate0PageSizeChange() {
    var psEl = document.getElementById('g0PageSize');
    var val = psEl ? psEl.value : '10';
    g0PageSize = (val === 'all') ? 999999 : parseInt(val, 10);
    g0CurrentPage = 1;
    renderGate0Table();
}

function resetGate0Filters() {
    document.getElementById('g0Search').value = '';
    document.getElementById('g0StatusFilter').value = '';
    var tipeEl = document.getElementById('g0TipeFilter');
    if (tipeEl) tipeEl.value = '';
    var psEl = document.getElementById('g0PageSize');
    if (psEl) psEl.value = '10';
    g0PageSize = 10;
    g0ActiveStatus = '';

    var tabs = document.querySelectorAll('.g0-tab-btn');
    tabs.forEach(function(t, idx) {
        if (idx === 0) t.classList.add('active');
        else t.classList.remove('active');
    });

    g0CurrentPage = 1;
    renderGate0Table();
}

function renderGate0Table() {
    var q = (document.getElementById('g0Search').value || '').toLowerCase().trim();
    var st = g0ActiveStatus;
    var tipeSelect = document.getElementById('g0TipeFilter');
    var tp = tipeSelect ? tipeSelect.value.trim() : '';

    var rows = Array.from(document.querySelectorAll('#tblGate0 tbody tr.g0-row'));
    var matchedRows = [];

    rows.forEach(function(r) {
        var no = (r.getAttribute('data-nomor') || '').toLowerCase();
        var mitra = (r.getAttribute('data-mitra') || '').toLowerCase();
        var judul = (r.getAttribute('data-judul') || '').toLowerCase();
        var rStatus = (r.getAttribute('data-status-persetujuan') || '').trim();
        var rTipe = (r.getAttribute('data-tipe') || '').trim();

        var matchQ = !q || no.indexOf(q) !== -1 || mitra.indexOf(q) !== -1 || judul.indexOf(q) !== -1 || r.textContent.toLowerCase().indexOf(q) !== -1;
        var matchSt = !st || rStatus === st;
        var matchTp = !tp || rTipe === tp;

        if (matchQ && matchSt && matchTp) {
            matchedRows.push(r);
        }
    });

    var totalMatched = matchedRows.length;
    var totalPages = Math.ceil(totalMatched / g0PageSize) || 1;
    if (g0CurrentPage > totalPages) g0CurrentPage = totalPages;
    if (g0CurrentPage < 1) g0CurrentPage = 1;

    var startIdx = (g0CurrentPage - 1) * g0PageSize;
    var endIdx = Math.min(startIdx + g0PageSize, totalMatched);

    // Hide all rows first
    rows.forEach(function(r) { r.style.display = 'none'; });

    // Show only current page slice
    for (var i = startIdx; i < endIdx; i++) {
        matchedRows[i].style.display = '';
    }

    // No match row
    var emptyRow = document.getElementById('g0NoMatchRow');
    if (emptyRow) {
        emptyRow.style.display = (rows.length > 0 && totalMatched === 0) ? '' : 'none';
    }

    // Page Info
    var pageInfo = document.getElementById('g0PageInfo');
    if (pageInfo) {
        if (totalMatched === 0) {
            pageInfo.textContent = 'Tidak ada usulan yang sesuai dengan filter pencarian';
        } else {
            pageInfo.textContent = 'Menampilkan ' + (startIdx + 1) + ' – ' + endIdx + ' dari ' + totalMatched + ' usulan';
        }
    }

    // Render Navigation
    renderPaginationButtons(totalMatched, totalPages);
}

function goToGate0Page(page) {
    g0CurrentPage = page;
    renderGate0Table();
}

function renderPaginationButtons(totalMatched, totalPages) {
    var nav = document.getElementById('g0PaginationNav');
    if (!nav) return;
    nav.innerHTML = '';

    if (totalMatched <= g0PageSize) {
        return;
    }

    // First button «
    var btnFirst = document.createElement('button');
    btnFirst.type = 'button';
    btnFirst.className = 'g0-page-btn';
    btnFirst.innerHTML = '&laquo;';
    btnFirst.title = 'Halaman Pertama';
    btnFirst.disabled = (g0CurrentPage === 1);
    btnFirst.onclick = function() { goToGate0Page(1); };
    nav.appendChild(btnFirst);

    // Prev button ‹
    var btnPrev = document.createElement('button');
    btnPrev.type = 'button';
    btnPrev.className = 'g0-page-btn';
    btnPrev.innerHTML = '&lsaquo;';
    btnPrev.title = 'Halaman Sebelumnya';
    btnPrev.disabled = (g0CurrentPage === 1);
    btnPrev.onclick = function() { goToGate0Page(g0CurrentPage - 1); };
    nav.appendChild(btnPrev);

    // Page numbers
    var maxVisible = 5;
    var startP = Math.max(1, g0CurrentPage - Math.floor(maxVisible / 2));
    var endP = Math.min(totalPages, startP + maxVisible - 1);
    if (endP - startP + 1 < maxVisible) {
        startP = Math.max(1, endP - maxVisible + 1);
    }

    if (startP > 1) {
        nav.appendChild(createPageBtn(1));
        if (startP > 2) {
            var ell = document.createElement('span');
            ell.textContent = '...';
            ell.style.padding = '0 4px';
            ell.style.color = '#94a3b8';
            nav.appendChild(ell);
        }
    }

    for (var p = startP; p <= endP; p++) {
        nav.appendChild(createPageBtn(p));
    }

    if (endP < totalPages) {
        if (endP < totalPages - 1) {
            var ell2 = document.createElement('span');
            ell2.textContent = '...';
            ell2.style.padding = '0 4px';
            ell2.style.color = '#94a3b8';
            nav.appendChild(ell2);
        }
        nav.appendChild(createPageBtn(totalPages));
    }

    // Next button ›
    var btnNext = document.createElement('button');
    btnNext.type = 'button';
    btnNext.className = 'g0-page-btn';
    btnNext.innerHTML = '&rsaquo;';
    btnNext.title = 'Halaman Berikutnya';
    btnNext.disabled = (g0CurrentPage === totalPages);
    btnNext.onclick = function() { goToGate0Page(g0CurrentPage + 1); };
    nav.appendChild(btnNext);

    // Last button »
    var btnLast = document.createElement('button');
    btnLast.type = 'button';
    btnLast.className = 'g0-page-btn';
    btnLast.innerHTML = '&raquo;';
    btnLast.title = 'Halaman Terakhir';
    btnLast.disabled = (g0CurrentPage === totalPages);
    btnLast.onclick = function() { goToGate0Page(totalPages); };
    nav.appendChild(btnLast);
}

function createPageBtn(p) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'g0-page-btn' + (p === g0CurrentPage ? ' active' : '');
    b.textContent = p;
    b.onclick = function() { goToGate0Page(p); };
    return b;
}

document.addEventListener('DOMContentLoaded', renderGate0Table);
window.addEventListener('pageshow', renderGate0Table);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>