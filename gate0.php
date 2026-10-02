<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/data.php';
requireLogin();

$pdo = getDB();
$user = currentUser();
$role = $user['role'];
$canEdit = in_array($role, ['admin', 'pemeriksa'], true);
$canDecide = in_array($role, ['admin', 'pimpinan'], true);

$errors = [];
$success = '';

/* ── DEFINISI 18 PERTANYAAN UJI (5 KRITERIA INTI) ────────── */
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

/* ── DEFINISI 7 TRIGGER KARAKTERISTIK KHUSUS ─────────────── */
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

/* ── 1. TAMBAH USULAN PRA-PKS ────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!$canEdit) {
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

        if ($semuaKriteriaYa && !$adaTriggerYa) {
            $statusRekomendasi = 'Layak';
        } elseif ($jumlahKriteriaYa >= 3 || $adaTriggerYa) {
            $statusRekomendasi = 'Perlu Penyempurnaan';
        } else {
            $statusRekomendasi = 'Tidak Prioritas / Tidak Layak';
        }

        $catatanVerif   = trim($_POST['catatan_verifikasi'] ?? '');
        $gapPenutupan   = trim($_POST['gap_penyempurnaan'] ?? '');
        $unitReviu      = trim($_POST['unit_review_tambahan'] ?? '');
        $batasWaktu     = ($_POST['batas_waktu_penyempurnaan'] ?? '') ?: null;

        if ($nomorUsulan === '' || $calonMitra === '' || $judulRencana === '') {
            $errors[] = 'Nomor Usulan, Calon Mitra, dan Judul Rencana wajib diisi.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO pra_pks (
                    nomor_usulan, tipe_kerjasama, jenis_naskah, unit_pemrakarsa, penanggung_jawab_usulan,
                    calon_mitra, judul_rencana, tujuan_singkat, ruang_lingkup, penerima_manfaat,
                    perkiraan_mulai, perkiraan_selesai,
                    k1_kesesuaian_strategis, k2_kebutuhan_daya_ungkit, k3_kelayakan_mitra, k4_kesiapan_sumber_daya, k5_risiko_keberlanjutan,
                    pertanyaan_uji, trigger_khusus, catatan_verifikasi, gap_penyempurnaan, unit_review_tambahan, batas_waktu_penyempurnaan,
                    status_rekomendasi, status_persetujuan, created_by
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'Menunggu Persetujuan Pimpinan\',?)');
                $stmt->execute([
                    $nomorUsulan, $tipeKerjasama, $jenisNaskah, $unitPemrakarsa, $pjUsulan ?: null,
                    $calonMitra, $judulRencana, $tujuanSingkat, $ruangLingkup, $penerimaManfaat,
                    $mulai, $selesai,
                    $kSummary['K1'], $kSummary['K2'], $kSummary['K3'], $kSummary['K4'], $kSummary['K5'],
                    json_encode($pertanyaanUji), json_encode($triggerKhusus),
                    $catatanVerif, $gapPenutupan, $unitReviu, $batasWaktu,
                    $statusRekomendasi, $user['id']
                ]);
                $success = 'Usulan Gate 0 ' . htmlspecialchars($nomorUsulan) . ' berhasil diajukan dengan evaluasi 18 pertanyaan uji & 7 trigger khusus.';
            } catch (PDOException $e) {
                $errors[] = str_contains($e->getMessage(), 'Duplicate') ? 'Nomor usulan sudah terdaftar.' : 'Gagal menyimpan: ' . $e->getMessage();
            }
        }
    }
}

/* ── 2. KEPUTUSAN PIMPINAN ────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'decision') {
    if (!$canDecide) {
        $errors[] = 'Hanya pimpinan atau admin yang berhak memberikan keputusan Gate 0.';
    } else {
        $idUsulan = (int)($_POST['usulan_id'] ?? 0);
        $statusPersetujuan = $_POST['status_persetujuan'] ?? '';
        $catatanPimpinan   = trim($_POST['catatan_pimpinan'] ?? '');

        if ($idUsulan <= 0 || !in_array($statusPersetujuan, ['Disetujui Pimpinan', 'Dikembalikan untuk Revisi', 'Ditolak Pimpinan'], true)) {
            $errors[] = 'Data keputusan tidak valid.';
        } else {
            $stmt = $pdo->prepare('UPDATE pra_pks SET status_persetujuan = ?, catatan_pimpinan = ?, tanggal_persetujuan = CURDATE(), pimpinan_id = ? WHERE id = ?');
            $stmt->execute([$statusPersetujuan, $catatanPimpinan ?: null, $user['id'], $idUsulan]);
            $success = 'Keputusan dan disposisi pimpinan berhasil disimpan.';
        }
    }
}

/* ── 3. PROMOSI OTOMATIS KE PKS AKTIF ────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'promote') {
    if (!$canEdit) {
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
                // Tentukan kode baru berikutnya
                $lastP = $pdo->query("SELECT kode FROM mitra_kinerja WHERE kode LIKE 'P%' ORDER BY id DESC LIMIT 1")->fetchColumn();
                $nextNum = $lastP ? ((int)substr($lastP, 1) + 1) : 11;
                $newKode = 'P' . str_pad($nextNum, 2, '0', STR_PAD_LEFT);

                // Tentukan Bidang terkait
                $bidangCandidate = 'AHU';
                $unitLower = strtolower($pra['unit_pemrakarsa'] . ' ' . ($pra['penanggung_jawab_usulan'] ?? ''));
                if (str_contains($unitLower, 'kekayaan intelektual') || str_contains($unitLower, ' ki ') || str_contains($unitLower, 'ki')) $bidangCandidate = 'KI';
                elseif (str_contains($unitLower, 'ham') || str_contains($unitLower, 'p3h')) $bidangCandidate = 'P3H';
                elseif (str_contains($unitLower, 'peraturan') || str_contains($unitLower, 'perundang') || str_contains($unitLower, 'ppl')) $bidangCandidate = 'PPL';
                elseif (str_contains($unitLower, 'keuangan')) $bidangCandidate = 'Keuangan';
                elseif (str_contains($unitLower, 'humas')) $bidangCandidate = 'Humas';
                elseif (str_contains($unitLower, 'sdm') || str_contains($unitLower, 'kepegawaian')) $bidangCandidate = 'SDM';

                $mulaiPks = $pra['perkiraan_mulai'] ?: date('Y-m-d');
                $selesaiPks = $pra['perkiraan_selesai'] ?: date('Y-m-d', strtotime('+3 years'));

                // Insert ke mitra_kinerja
                $stmtM = $pdo->prepare('INSERT INTO mitra_kinerja (
                    kode, portofolio, nama_mitra, judul, bidang, jenis, tanggal_mulai, tanggal_berakhir,
                    status_tanggal, evaluasi_per_tahun, cutoff_date, sumber_baseline, status_scorecard, posisi_portofolio, rekomendasi,
                    pic_internal
                ) VALUES (?, \'Pilot Utama\', ?, ?, ?, ?, ?, ?, \'TERVERIFIKASI\', 4, CURDATE(), \'Gate 0 Promoted\', \'BELUM LENGKAP\', \'AKTIF\', \'LANJUT\', ?)');
                $stmtM->execute([
                    $newKode, $pra['calon_mitra'], $pra['judul_rencana'], $bidangCandidate, $pra['jenis_naskah'],
                    $mulaiPks, $selesaiPks, $pra['penanggung_jawab_usulan'] ?: $pra['unit_pemrakarsa']
                ]);
                $newMitraId = (int)$pdo->lastInsertId();

                // Insert Rencana Kerja terkait
                $stmtRK = $pdo->prepare('INSERT INTO rencana_kerja (
                    mitra_id, judul_rencana, ruang_lingkup, maksud_tujuan, tanggal_mulai, tanggal_selesai, status, alasan_persetujuan
                ) VALUES (?, ?, ?, ?, ?, ?, \'Disetujui\', ?)');
                $stmtRK->execute([
                    $newMitraId,
                    'Rencana Kerja ' . $pra['judul_rencana'],
                    $pra['ruang_lingkup'],
                    $pra['tujuan_singkat'],
                    $mulaiPks,
                    date('Y-m-d', strtotime($mulaiPks . ' +1 year')),
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

                // Inisialisasi Siklus Monev Berkala
                $keb = hitungKebutuhanScorecard($mulaiPks, $selesaiPks, 3);
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
                    $desc = $ind[1] . "\nCara periksa: Evaluasi berkala siklus monev";
                    $pdo->prepare('INSERT INTO indikator_skor (mitra_id, kode_indikator, deskripsi, bobot, referensi_baseline, status_pemeriksaan) VALUES (?, ?, ?, ?, \'Dipromosikan dari Gate 0\', \'BELUM DITELAAH\')')
                        ->execute([$newMitraId, $ind[0], $desc, $ind[2]]);
                }

                // Inisialisasi Early Warning (4 dimensi)
                foreach (['Masa berlaku','Aktivitas/tenggat','Data/eviden','PIC'] as $dim) {
                    $pdo->prepare("INSERT INTO early_warning (mitra_id, dimensi, status, progres) VALUES (?, ?, 'E0', 'DALAM PROSES')")
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
                $success = 'Usulan ' . htmlspecialchars($pra['nomor_usulan']) . ' berhasil dipromosikan menjadi PKS baru dengan kode ' . $newKode . '!';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Gagal mempromosikan usulan: ' . $e->getMessage();
            }
        }
    }
}

/* ── 4. HELPER PARSER EXCEL (.xlsx) & CSV ──────────────────── */
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
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
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
        $content = file_get_contents($tmpPath);
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $lines = preg_split("/\r\n|\n|\r/", trim($content));
        if (empty($lines)) return [];
        $firstLine = $lines[0];
        $delim = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $rows[] = str_getcsv($line, $delim);
        }
        return $rows;
    }
}

/* ── 5. IMPORT EXCEL (.xlsx) & CSV ────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    if (!$canEdit) {
        $errors[] = 'Akses ditolak.';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Pilih file Excel (.xlsx) atau CSV yang valid.';
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
                            $ans = strtoupper($cVal) === 'TIDAK' ? 'TIDAK' : 'YA';
                            $pertanyaanUji["q{$qIdx}"] = [
                                'jawab' => $ans,
                                'bukti' => $cNote ?: 'Dokumen terverifikasi'
                            ];
                            if ($ans === 'TIDAK') {
                                if ($qIdx <= 3) $kSummary['K1'] = 'TIDAK';
                                elseif ($qIdx <= 7) $kSummary['K2'] = 'TIDAK';
                                elseif ($qIdx <= 10) $kSummary['K3'] = 'TIDAK';
                                elseif ($qIdx <= 14) $kSummary['K4'] = 'TIDAK';
                                else $kSummary['K5'] = 'TIDAK';
                            }
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

                // Lengkapi pertanyaan uji jika belum terisi
                for ($q = 1; $q <= 18; $q++) {
                    if (!isset($pertanyaanUji["q{$q}"])) {
                        $pertanyaanUji["q{$q}"] = ['jawab' => 'YA', 'bukti' => 'Dokumen terverifikasi'];
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
                $mulai    = $getV(['Perkiraan Tanggal Mulai', 'perkiraan_mulai']) ?: null;
                $selesai  = $getV(['Perkiraan Tanggal Selesai', 'perkiraan_selesai']) ?: null;

                $catatan = $getV(['Catatan Verifikator', 'catatan_verifikasi'], 'Diimpor dari formulir vertikal Gate 0.');
                $gap = $getV(['Gap yang Harus Ditutup', 'gap_penyempurnaan'], 'Tidak ada gap material.');
                $unitRev = $getV(['Unit/Fungsi Reviu Tambahan', 'unit_review_tambahan'], 'Subbagian Humas, RB, dan TI');

                $adaTidak = in_array('TIDAK', $kSummary, true);
                $adaTrigger = false;
                foreach ($triggerKhusus as $t) {
                    if ($t['jawab'] === 'YA') { $adaTrigger = true; break; }
                }

                $rekomendasi = (!$adaTidak && !$adaTrigger) ? 'Layak' : 'Perlu Penyempurnaan';

                $pertanyaanJson = json_encode($pertanyaanUji, JSON_UNESCAPED_UNICODE);
                $triggerJson = json_encode($triggerKhusus, JSON_UNESCAPED_UNICODE);

                $stmt = $pdo->prepare('
                    INSERT INTO pra_pks (
                        nomor_usulan, tipe_kerjasama, jenis_naskah, unit_pemrakarsa, penanggung_jawab_usulan,
                        calon_mitra, judul_rencana, tujuan_singkat, ruang_lingkup, penerima_manfaat,
                        perkiraan_mulai, perkiraan_selesai,
                        k1_kesesuaian_strategis, k2_kebutuhan_daya_ungkit, k3_kelayakan_mitra,
                        k4_kesiapan_sumber_daya, k5_risiko_keberlanjutan,
                        pertanyaan_uji, trigger_khusus,
                        catatan_verifikasi, gap_penyempurnaan, unit_review_tambahan,
                        status_rekomendasi, status_persetujuan, created_by
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, \'Menunggu Persetujuan Pimpinan\', ?
                    )
                    ON DUPLICATE KEY UPDATE
                        calon_mitra=VALUES(calon_mitra), judul_rencana=VALUES(judul_rencana),
                        tujuan_singkat=VALUES(tujuan_singkat), ruang_lingkup=VALUES(ruang_lingkup),
                        pertanyaan_uji=VALUES(pertanyaan_uji), trigger_khusus=VALUES(trigger_khusus),
                        status_rekomendasi=VALUES(status_rekomendasi)
                ');
                $stmt->execute([
                    $noUsulan, $tipe, $jenis, $unit, $pj,
                    $mitra, $judul, $tujuan, $ruang, $manfaat,
                    $mulai, $selesai,
                    $kSummary['K1'], $kSummary['K2'], $kSummary['K3'],
                    $kSummary['K4'], $kSummary['K5'],
                    $pertanyaanJson, $triggerJson,
                    $catatan, $gap, $unitRev,
                    $rekomendasi, $user['id']
                ]);
                $importedCount = 1;
                $success = "Berhasil mengimpor 1 usulan dari formulir vertikal: <strong>{$noUsulan}</strong> ({$mitra}).";
            } else {
                // Parsing format horizontal (tabel kolom)
                $headerRow = array_map('strtolower', array_map('trim', $rows[0]));
                $dataRows = array_slice($rows, 1);

                $colMap = [];
                foreach ($headerRow as $idx => $hName) {
                    $colMap[$hName] = $idx;
                }

                foreach ($dataRows as $row) {
                    if (empty($row[0])) continue;

                    $getVal = function($keys, $def = '') use ($row, $colMap) {
                        foreach ((array)$keys as $k) {
                            if (isset($colMap[$k]) && isset($row[$colMap[$k]])) {
                                $v = trim($row[$colMap[$k]]);
                                if ($v !== '') return $v;
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
                $mulai    = $getVal(['perkiraan_mulai', 10]) ?: null;
                $selesai  = $getVal(['perkiraan_selesai', 11]) ?: null;

                // Cek apakah ada kolom Q1..Q18
                $pertanyaanUji = [];
                $kSummary = ['K1' => 'YA', 'K2' => 'YA', 'K3' => 'YA', 'K4' => 'YA', 'K5' => 'YA'];

                $hasQCols = isset($colMap['q1_tusi_kewenangan']) || isset($colMap['q1_jawab']);
                if ($hasQCols) {
                    for ($q = 1; $q <= 18; $q++) {
                        $qAns = strtoupper($getVal(["q{$q}_jawab", "q{$q}_tusi_kewenangan", "q{$q}_sasaran_kinerja", "q{$q}_batas_kewenangan", "q{$q}_kebutuhan_nyata", "q{$q}_kejelasan_manfaat", "q{$q}_output_outcome", "q{$q}_daya_ungkit", "q{$q}_legalitas_mitra", "q{$q}_kapasitas_mitra", "q{$q}_integritas_reputasi", "q{$q}_peran_kontribusi", "q{$q}_focal_point", "q{$q}_kesiapan_sdm_anggaran", "q{$q}_indikator_awal", "q{$q}_manajemen_risiko", "q{$q}_tindak_lanjut_pascattd", "q{$q}_keberlanjutan_manfaat", "q{$q}_mitigasi_hambatan"], 'YA')) === 'TIDAK' ? 'TIDAK' : 'YA';
                        $qBukti = $getVal(["q{$q}_bukti"], 'Dokumen terverifikasi');
                        $pertanyaanUji["q{$q}"] = ['jawab' => $qAns, 'bukti' => $qBukti];
                        if ($qAns === 'TIDAK') {
                            if ($q <= 3) $kSummary['K1'] = 'TIDAK';
                            elseif ($q <= 7) $kSummary['K2'] = 'TIDAK';
                            elseif ($q <= 10) $kSummary['K3'] = 'TIDAK';
                            elseif ($q <= 14) $kSummary['K4'] = 'TIDAK';
                            else $kSummary['K5'] = 'TIDAK';
                        }
                    }
                } else {
                    // Fallback kolom K1..K5 sederhana
                    $kSummary['K1'] = strtoupper($getVal(['k1_kesesuaian_strategis', 11], 'YA')) === 'TIDAK' ? 'TIDAK' : 'YA';
                    $kSummary['K2'] = strtoupper($getVal(['k2_kebutuhan_daya_ungkit', 12], 'YA')) === 'TIDAK' ? 'TIDAK' : 'YA';
                    $kSummary['K3'] = strtoupper($getVal(['k3_kelayakan_mitra', 13], 'YA')) === 'TIDAK' ? 'TIDAK' : 'YA';
                    $kSummary['K4'] = strtoupper($getVal(['k4_kesiapan_sumber_daya', 14], 'YA')) === 'TIDAK' ? 'TIDAK' : 'YA';
                    $kSummary['K5'] = strtoupper($getVal(['k5_risiko_keberlanjutan', 15], 'YA')) === 'TIDAK' ? 'TIDAK' : 'YA';
                    for ($q = 1; $q <= 18; $q++) {
                        $pertanyaanUji["q{$q}"] = ['jawab' => 'YA', 'bukti' => 'Terverifikasi'];
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

                $catatan = $getVal(['catatan_verifikasi', 16], 'Diimpor melalui template Excel/CSV');
                $gap = $getVal(['gap_penyempurnaan'], 'Tidak ada gap material.');
                $unitRev = $getVal(['unit_review_tambahan'], 'Subbagian Humas, RB, dan TI');

                $semuaYa = ($kSummary['K1'] === 'YA' && $kSummary['K2'] === 'YA' && $kSummary['K3'] === 'YA' && $kSummary['K4'] === 'YA' && $kSummary['K5'] === 'YA');
                $rek = ($semuaYa && !$adaTrigger) ? 'Layak' : 'Perlu Penyempurnaan';

                try {
                    $stmt = $pdo->prepare('INSERT INTO pra_pks (
                        nomor_usulan, tipe_kerjasama, jenis_naskah, unit_pemrakarsa, penanggung_jawab_usulan,
                        calon_mitra, judul_rencana, tujuan_singkat, ruang_lingkup, penerima_manfaat,
                        perkiraan_mulai, perkiraan_selesai,
                        k1_kesesuaian_strategis, k2_kebutuhan_daya_ungkit, k3_kelayakan_mitra, k4_kesiapan_sumber_daya, k5_risiko_keberlanjutan,
                        pertanyaan_uji, trigger_khusus, catatan_verifikasi, gap_penyempurnaan, unit_review_tambahan,
                        status_rekomendasi, status_persetujuan, created_by
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'Menunggu Persetujuan Pimpinan\',?)
                    ON DUPLICATE KEY UPDATE judul_rencana=VALUES(judul_rencana), calon_mitra=VALUES(calon_mitra)');
                    $stmt->execute([
                        $noUsulan, $tipe, $jenis, $unit, $pj,
                        $mitra, $judul, $tujuan, $ruang, $manfaat,
                        $mulai, $selesai,
                        $kSummary['K1'], $kSummary['K2'], $kSummary['K3'], $kSummary['K4'], $kSummary['K5'],
                        json_encode($pertanyaanUji), json_encode($triggerKhusus),
                        $catatan, $gap, $unitRev, $rek, $user['id']
                    ]);
                    $importedCount++;
                } catch (Throwable $e) {}
            }
            $success = "Berhasil mengimpor $importedCount usulan Pra-PKS dari file $origName.";
            }
        }
    }
}

/* ── VIEW ROUTER: DETAIL / PRINT FORM RESMI ───────────────── */
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
                    $qItem = $pertanyaanData["q{$qNum}"] ?? ['jawab' => 'YA', 'bukti' => '-'];
                ?>
                <tr>
                    <td style="text-align:center;"><?= $qNum ?></td>
                    <td><?= h($qText) ?></td>
                    <td style="text-align:center;">
                        <strong style="color:<?= $qItem['jawab'] === 'YA' ? '#16a34a' : '#dc2626' ?>;"><?= $qItem['jawab'] ?></strong>
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

/* ── DATA QUERY ───────────────────────────────────────────── */
$usulanList = $pdo->query('SELECT * FROM pra_pks ORDER BY id DESC')->fetchAll();

$totalUsulan = count($usulanList);
$disetujuiCount = count(array_filter($usulanList, fn($u) => $u['status_persetujuan'] === 'Disetujui Pimpinan'));
$menungguCount = count(array_filter($usulanList, fn($u) => $u['status_persetujuan'] === 'Menunggu Persetujuan Pimpinan'));
$siapPromosiCount = count(array_filter($usulanList, fn($u) => $u['status_persetujuan'] === 'Disetujui Pimpinan' && !$u['is_promoted_to_pks']));

$pageTitle = 'Gate 0 — Pra-Kerja Sama';
require __DIR__ . '/includes/header.php';
?>

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

<?php if ($success): ?><div class="alert alert-info"><?= h($success) ?></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-warning"><?= h($e) ?></div><?php endforeach; ?>

<!-- KPI Row -->
<div class="kpi-grid" style="margin-bottom:20px;grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));">
    <div class="kpi-card">
        <div class="kpi-value"><?= $totalUsulan ?></div>
        <div class="kpi-label">Total Usulan</div>
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
    <div class="flex-between" style="margin-bottom:14px;">
        <h2 style="margin:0;">Daftar Usulan Pra-PKS</h2>
        <div>
            <?php if ($canEdit): ?>
            <button onclick="document.getElementById('importModal').style.display='block'" class="btn btn-outline btn-sm">📤 Import Excel / CSV</button>
            <button onclick="document.getElementById('addModal').style.display='block'" class="btn btn-primary btn-sm">+ Usulan Baru</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-wrap">
        <table>
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
                <?php else: foreach ($usulanList as $u): 
                    $rekBadge = match($u['status_rekomendasi']) {
                        'Layak' => 'success',
                        'Perlu Penyempurnaan' => 'warning',
                        default => 'danger'
                    };
                    $appBadge = match($u['status_persetujuan']) {
                        'Disetujui Pimpinan' => 'success',
                        'Dikembalikan untuk Revisi' => 'warning',
                        'Ditolak Pimpinan' => 'danger',
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
                ?>
                <tr>
                    <td>
                        <strong><?= h($u['nomor_usulan']) ?></strong><br>
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
                        <span class="badge badge-<?= $appBadge ?>"><?= h($u['status_persetujuan']) ?></span>
                        <?php if ($u['catatan_pimpinan']): ?>
                        <div class="muted" style="font-size:11px;margin-top:3px;max-width:160px;">"<?= h(singkat($u['catatan_pimpinan'], 35)) ?>"</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;flex-wrap:wrap;">
                            <a href="gate0.php?view=detail&id=<?= $u['id'] ?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 7px;">📄 Form</a>
                            <a href="gate0.php?view=print&id=<?= $u['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 7px;">🖨️ PDF</a>
                            
                            <?php if ($canDecide && $u['status_persetujuan'] === 'Menunggu Persetujuan Pimpinan'): ?>
                            <button onclick="openDecisionModal(<?= (int)$u['id'] ?>, <?= htmlspecialchars(json_encode((string)$u['nomor_usulan']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode((string)$u['calon_mitra']), ENT_QUOTES, 'UTF-8') ?>)" class="btn btn-warning btn-sm" style="font-size:11px;padding:3px 7px;">⚖️ Putusan</button>
                            <?php endif; ?>

                            <?php if ($u['status_persetujuan'] === 'Disetujui Pimpinan'): ?>
                                <?php if (!$u['is_promoted_to_pks']): ?>
                                    <?php if ($canEdit): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Promosikan usulan ini menjadi PKS aktif dengan Rencana Kerja?');">
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
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Tambah Usulan Lengkap (Formulir Gate 0) -->
<div id="addModal" class="card" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9999;width:92%;max-width:850px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 40px rgba(0,0,0,0.3);background:#fff;">
    <div class="flex-between" style="border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:14px;">
        <h2 style="margin:0;font-size:18px;">Input Usulan Baru Gate 0 (Formulir Resmi)</h2>
        <button onclick="document.getElementById('addModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="post">
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
            <button type="button" onclick="document.getElementById('addModal').style.display='none'" class="btn btn-outline">Batal</button>
        </div>
    </form>
</div>

<!-- Modal Keputusan Pimpinan -->
<div id="decisionModal" class="card" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9999;width:90%;max-width:500px;box-shadow:0 20px 40px rgba(0,0,0,0.3);background:#fff;">
    <div class="flex-between" style="border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:14px;">
        <h2 style="margin:0;font-size:18px;">Keputusan Pimpinan (Gate 0)</h2>
        <button onclick="document.getElementById('decisionModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="post">
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
            <button type="button" onclick="document.getElementById('decisionModal').style.display='none'" class="btn btn-outline">Batal</button>
        </div>
    </form>
</div>

<!-- Modal Import Excel (.xlsx) / CSV -->
<div id="importModal" class="card" style="display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9999;width:90%;max-width:500px;box-shadow:0 20px 40px rgba(0,0,0,0.3);background:#fff;">
    <div class="flex-between" style="border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:14px;">
        <h2 style="margin:0;font-size:18px;">Import Usulan Pra-PKS (Excel / CSV)</h2>
        <button onclick="document.getElementById('importModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="post" enctype="multipart/form-data">
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
            <button type="button" onclick="document.getElementById('importModal').style.display='none'" class="btn btn-outline">Batal</button>
        </div>
    </form>
</div>

<script>
function openDecisionModal(id, no, mitra) {
    document.getElementById('decUsulanId').value = id;
    document.getElementById('decUsulanNo').textContent = no;
    document.getElementById('decCalonMitra').textContent = mitra;
    document.getElementById('decisionModal').style.display = 'block';
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>