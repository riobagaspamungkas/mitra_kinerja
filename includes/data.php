<?php
/**
 * Helper akses data: mengambil baris dari DB lalu menjalankan business logic
 * di functions.php supaya PHP dan tampilan selalu konsisten dengan aturan
 * yang sama (tidak ada logika ganda antara form dan dashboard).
 */
require_once __DIR__ . '/functions.php';

/** Ambil ringkasan lengkap SEMUA naskah untuk dashboard & daftar. */
function getAllMitraSummary(PDO $pdo): array {
    $mitraRows = $pdo->query('SELECT * FROM mitra_kinerja ORDER BY kode')->fetchAll();
    $result = [];
    foreach ($mitraRows as $m) {
        $result[] = getMitraSummary($pdo, $m);
    }
    return $result;
}

/** Ambil ringkasan lengkap SATU naskah (dipakai juga oleh mitra_list & dashboard). */
function getMitraSummary(PDO $pdo, array $mitra, bool $forceRecalculate = false): array {
    $stmt = $pdo->prepare('SELECT * FROM indikator_skor WHERE mitra_id = ? ORDER BY kode_indikator');
    $stmt->execute([$mitra['id']]);
    $indikatorRows = $stmt->fetchAll();

    // Self-healing: jika naskah belum memiliki 7 indikator standar V3 Result-Chain, inisialisasi otomatis
    if (empty($indikatorRows)) {
        $defs = [
            ['I1', INDIKATOR_APA_YANG_DINILAI['I1'] ?? 'Pengelolaan & RTL', 10],
            ['I2', INDIKATOR_APA_YANG_DINILAI['I2'] ?? 'Implementasi', 15],
            ['I3', INDIKATOR_APA_YANG_DINILAI['I3'] ?? 'Output', 15],
            ['I4', INDIKATOR_APA_YANG_DINILAI['I4'] ?? 'Outcome', 20],
            ['I5', INDIKATOR_APA_YANG_DINILAI['I5'] ?? 'Dampak', 20],
            ['I6', INDIKATOR_APA_YANG_DINILAI['I6'] ?? 'Evidence & Data', 10],
            ['I7', INDIKATOR_APA_YANG_DINILAI['I7'] ?? 'Risiko & Keberlanjutan', 10],
        ];
        $stmtIns = $pdo->prepare('INSERT INTO indikator_skor (mitra_id, kode_indikator, deskripsi, bobot, referensi_baseline, status_pemeriksaan) VALUES (?, ?, ?, ?, \'Baseline awal\', \'BELUM DITELAAH\') ON DUPLICATE KEY UPDATE id=id');
        foreach ($defs as $d) {
            $stmtIns->execute([$mitra['id'], $d[0], $d[1], $d[2]]);
        }
        $stmt->execute([$mitra['id']]);
        $indikatorRows = $stmt->fetchAll();
    }

    $stmt = $pdo->prepare('SELECT * FROM early_warning WHERE mitra_id = ? ORDER BY dimensi');
    $stmt->execute([$mitra['id']]);
    $warningRows = $stmt->fetchAll();

    // Self-healing: jika naskah belum memiliki 4 dimensi early warning, inisialisasi otomatis dengan status V0
    if (empty($warningRows)) {
        $ewDims = ['Masa berlaku', 'Aktivitas/tenggat', 'Data/eviden', 'PIC'];
        $stmtInsEW = $pdo->prepare('INSERT INTO early_warning (mitra_id, dimensi, status, progres) VALUES (?, ?, \'V0\', \'BELUM MULAI\') ON DUPLICATE KEY UPDATE id=id');
        foreach ($ewDims as $d) {
            $stmtInsEW->execute([$mitra['id'], $d]);
        }
        $stmt->execute([$mitra['id']]);
        $warningRows = $stmt->fetchAll();
    }

    $stmt = $pdo->prepare('SELECT * FROM intervensi_pimpinan WHERE mitra_id = ? ORDER BY no_pemicu');
    $stmt->execute([$mitra['id']]);
    $pemicuRows = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM validasi WHERE mitra_id = ?');
    $stmt->execute([$mitra['id']]);
    $validasi = $stmt->fetch() ?: ['status' => 'BELUM'];

    $stmt = $pdo->prepare('SELECT * FROM intervensi_usulan WHERE mitra_id = ?');
    $stmt->execute([$mitra['id']]);
    $usulan = $stmt->fetch() ?: ['upaya_dilakukan' => null, 'keputusan_diminta' => null, 'uraian_kendala' => null];

    // --- Baseline FIX 12 Elemen ---
    $baselineRows = [];
    try {
        $stmtB = $pdo->prepare('SELECT * FROM baseline_elemen WHERE mitra_id = ? ORDER BY nomor_elemen ASC');
        $stmtB->execute([$mitra['id']]);
        $baselineRows = $stmtB->fetchAll();
    } catch (Throwable $e) {}

    $bVerified = 0;
    $bFilled = 0;
    foreach ($baselineRows as $b) {
        if (($b['status'] ?? '') === 'TERVERIFIKASI') $bVerified++;
        if (($b['status'] ?? '') !== 'BELUM DIISI') $bFilled++;
    }
    $bTotal = count($baselineRows) ?: 12;
    $baselineSummary = [
        'rows' => $baselineRows,
        'terverifikasi' => $bVerified,
        'terisi' => $bFilled,
        'total' => $bTotal,
        'persentase' => round(($bVerified / $bTotal) * 100),
        'is_locked' => ($mitra['baseline_status'] ?? '') === 'TERVERIFIKASI / DIKUNCI',
        'status' => $mitra['baseline_status'] ?? 'BELUM DIISI',
    ];

    // --- Early warning: Masa berlaku 100% otomatis; 3 dimensi lain: status diturunkan dari kondisi ---
    $statusList = [];
    foreach ($warningRows as &$w) {
        if ($w['dimensi'] === 'Masa berlaku') {
            $mb = hitungMasaBerlaku($mitra['tanggal_berakhir'], $mitra['cutoff_date'], $mitra['status_tanggal']);
            $w['kondisi'] = $mb['kondisi'];
            $w['status']  = $mb['status'];
            $w['sisa_hari'] = $mb['sisa_hari'];
        } else {
            $w['status'] = statusDariKondisi($w['dimensi'], $w['kondisi'] ?? 'BELUM DIPERIKSA');
        }
        $statusList[] = $w['status'];
    }
    unset($w);

    $ringkasan = ringkasanIndikator($indikatorRows);
    $warning = warningTertinggi($statusList);
    $hasilUji = hasilUjiIntervensi($pemicuRows, $warning['status']);
    $cekUsulan = cekUsulanIntervensi($pemicuRows, $hasilUji, $usulan['upaya_dilakukan'], $usulan['keputusan_diminta']);
    $calculatedStatus = hitungStatusScorecard($ringkasan['skor_lengkap'], $ringkasan['cek_lengkap_ok'], $validasi['status'], $ringkasan['bukti_kurang_n'], $ringkasan['bdn_n'], $ringkasan['dapat_dinilai_n'], $ringkasan['all_evaluable']);
    if ($calculatedStatus === 'BUKTI BELUM MEMADAI') {
        $statusScorecard = 'BUKTI BELUM MEMADAI';
    } elseif ($validasi['status'] === 'PERLU PERBAIKAN') {
        $statusScorecard = 'PERLU PERBAIKAN';
    } elseif ($ringkasan['bdn_n'] > 0 || !$ringkasan['all_evaluable']) {
        // Do not let legacy DISETUJUI promote un-evaluable cards to FINAL/TERVALIDASI
        $statusScorecard = $calculatedStatus;
    } elseif ($forceRecalculate || $calculatedStatus === 'DALAM PENILAIAN') {
        // Allow status_scorecard to revert from 'SIAP DIVALIDASI' to 'DALAM PENILAIAN'
        $statusScorecard = $calculatedStatus;
    } elseif (!empty($mitra['status_scorecard']) && in_array($mitra['status_scorecard'], ['FINAL', 'FINAL/TERVALIDASI', 'SIAP DIVALIDASI'], true)) {
        if ($validasi['status'] === 'DISETUJUI') {
            $statusScorecard = 'FINAL/TERVALIDASI';
        } else {
            $statusScorecard = $mitra['status_scorecard'];
        }
    } else {
        $statusScorecard = $calculatedStatus;
    }

    $calculatedPosisi = hitungPosisiPortofolio($indikatorRows);
    if ($ringkasan['dapat_dinilai_n'] === 0 || $forceRecalculate || !isset($mitra['posisi_portofolio']) || $mitra['posisi_portofolio'] === '') {
        $posisiPortofolio = $calculatedPosisi;
    } else {
        $posisiPortofolio = $mitra['posisi_portofolio'];
    }

    $sisaHari = null;
    foreach ($warningRows as $wr) {
        if ($wr['dimensi'] === 'Masa berlaku' && isset($wr['sisa_hari'])) {
            $sisaHari = $wr['sisa_hari'];
            break;
        }
    }

    $calculatedRekomendasi = ($ringkasan['dapat_dinilai_n'] === 0 || $ringkasan['nilai_berjalan'] <= 0 || $posisiPortofolio === 'BELUM DAPAT DITENTUKAN')
        ? 'BELUM DITENTUKAN'
        : hitungRekomendasi($ringkasan['nilai_berjalan'], $warning['status'], $posisiPortofolio, $sisaHari);
    if (!empty($mitra['rekomendasi']) && !$forceRecalculate) {
        $rekomendasi = $mitra['rekomendasi'];
    } elseif ($ringkasan['dapat_dinilai_n'] === 0 || $posisiPortofolio === 'BELUM DAPAT DITENTUKAN') {
        $rekomendasi = 'BELUM DITENTUKAN';
    } else {
        $rekomendasi = $calculatedRekomendasi;
    }

    $evaluasiPerTahun = (int)($mitra['evaluasi_per_tahun'] ?? 4);
    $monev = hitungKebutuhanScorecard($mitra['tanggal_mulai'] ?? null, $mitra['tanggal_berakhir'] ?? null, $evaluasiPerTahun);

    // Integrasi jadwal aktual dari tabel siklus_monev jika tersedia
    try {
        $stmtSM = $pdo->prepare('SELECT * FROM siklus_monev WHERE mitra_id = ? ORDER BY tanggal_target_evaluasi ASC');
        $stmtSM->execute([$mitra['id']]);
        $realMilestones = $stmtSM->fetchAll();
        if (!empty($realMilestones)) {
            $todayTs = strtotime(date('Y-m-d'));
            $nearestUpcomingTarget = null;
            $nearestUpcomingDiff = null;
            $hasOverdue = false;
            $overdueList = [];
            $warning1Bulan = false;
            $msList = [];
            foreach ($realMilestones as $rm) {
                $tgtStr = $rm['tanggal_target_evaluasi'];
                $diff = (int)round((strtotime($tgtStr) - $todayTs) / 86400);
                $isCompleted = in_array(strtolower(trim($rm['status_siklus'] ?? '')), ['selesai', 'selesai evaluasi'], true);
                $isDueSoon = (!$isCompleted && $diff >= 0 && $diff <= 30);
                $isPast = ($diff < 0);
                $isOverdue = (!$isCompleted && $diff < 0);

                if ($isOverdue) {
                    $hasOverdue = true;
                    $overdueList[] = [
                        'siklus_ke'     => (int)$rm['siklus_ke'],
                        'nama'          => $rm['nama_siklus'],
                        'target_tgl'    => $tgtStr,
                        'sisa_hari'     => $diff,
                        'status_siklus' => $rm['status_siklus'],
                    ];
                }

                $msList[] = [
                    'siklus_ke'     => (int)$rm['siklus_ke'],
                    'nama'          => $rm['nama_siklus'],
                    'target_tgl'    => $tgtStr,
                    'sisa_hari'     => $diff,
                    'is_due_soon'   => $isDueSoon,
                    'is_past'       => $isPast,
                    'is_overdue'    => $isOverdue,
                    'status_siklus' => $rm['status_siklus']
                ];

                // Milestone mendatang (diff >= 0) belum selesai pertama
                if (!$isCompleted && $diff >= 0 && $nearestUpcomingTarget === null) {
                    $nearestUpcomingTarget = $tgtStr;
                    $nearestUpcomingDiff = $diff;
                    if ($diff <= 30) {
                        $warning1Bulan = true;
                    }
                }
            }

            if (!empty($msList)) {
                $monev['milestones'] = $msList;
                $monev['total_siklus'] = count($msList);
                $monev['has_overdue'] = $hasOverdue;
                $monev['overdue_milestones'] = $overdueList;

                if ($nearestUpcomingTarget !== null) {
                    $monev['target_evaluasi_terdekat'] = $nearestUpcomingTarget;
                    $monev['hari_menuju_evaluasi'] = $nearestUpcomingDiff;
                    $monev['warning_1_bulan'] = $warning1Bulan;
                } else {
                    // Jika tidak ada target aktif mendatang, cari target pending/aktif terdekat
                    $lastUnfinished = null;
                    foreach ($msList as $ms) {
                        $isCompleted = in_array(strtolower(trim($ms['status_siklus'] ?? '')), ['selesai', 'selesai evaluasi'], true);
                        if (!$isCompleted) {
                            $lastUnfinished = $ms;
                        }
                    }
                    if ($lastUnfinished) {
                        $monev['target_evaluasi_terdekat'] = $lastUnfinished['target_tgl'];
                        $monev['hari_menuju_evaluasi'] = $lastUnfinished['sisa_hari'];
                    } else {
                        $monev['target_evaluasi_terdekat'] = null;
                        $monev['hari_menuju_evaluasi'] = null;
                    }
                    $monev['warning_1_bulan'] = false;
                }
            }
        }
    } catch (Throwable $e) {}

    return [
        'mitra'             => $mitra,
        'indikator'         => $indikatorRows,
        'warning_rows'      => $warningRows,
        'pemicu_rows'       => $pemicuRows,
        'validasi'          => $validasi,
        'usulan'            => $usulan,
        'nilai_berjalan'    => $ringkasan['nilai_berjalan'],
        'nilai_final'       => $ringkasan['nilai_final'],
        'all_evaluable'     => $ringkasan['all_evaluable'],
        'bobot_dinilai'     => $ringkasan['bobot_dinilai'],
        'kelengkapan'       => $ringkasan['kelengkapan'],
        'skor_lengkap'      => $ringkasan['skor_lengkap'],
        'cek_lengkap_ok'    => $ringkasan['cek_lengkap_ok'],
        'kategori'          => $ringkasan['kategori'],
        'status_scorecard'  => $statusScorecard,
        'posisi_portofolio' => $posisiPortofolio,
        'rekomendasi'       => $rekomendasi,
        'warning'           => $warning,
        'hasil_uji'         => $hasilUji,
        'cek_usulan'        => $cekUsulan,
        'dapat_dinilai_n'   => $ringkasan['dapat_dinilai_n'],
        'bdn_n'             => $ringkasan['bdn_n'],
        'bukti_kurang_n'    => $ringkasan['bukti_kurang_n'],
        'monev'             => $monev,
        'baseline'          => $baselineSummary,
    ];
}

/** Sinkronkan status_scorecard yang tersimpan di tabel mitra_kinerja (dipanggil setiap kali data disimpan). */
function syncStatusScorecard(PDO $pdo, int $mitraId, bool $forceRecalculate = false): void {
    $stmt = $pdo->prepare('SELECT * FROM mitra_kinerja WHERE id = ?');
    $stmt->execute([$mitraId]);
    $mitra = $stmt->fetch();
    if (!$mitra) return;

    $summary = getMitraSummary($pdo, $mitra, $forceRecalculate);
    $stmt = $pdo->prepare('UPDATE mitra_kinerja SET status_scorecard = ?, posisi_portofolio = ?, rekomendasi = ? WHERE id = ?');
    $stmt->execute([$summary['status_scorecard'], $summary['posisi_portofolio'], $summary['rekomendasi'], $mitraId]);
}

/** Reset status validasi jika sebelumnya berstatus 'PERLU PERBAIKAN' karena adanya revisi baru dari pemeriksa. */
function resetValidasiJikaPerluPerbaikan(PDO $pdo, int $mitraId): bool {
    $stmt = $pdo->prepare('SELECT status FROM validasi WHERE mitra_id = ?');
    $stmt->execute([$mitraId]);
    if ($stmt->fetchColumn() === 'PERLU PERBAIKAN') {
        $pdo->prepare("UPDATE validasi SET status = 'BELUM', validator_id = NULL, tanggal_validasi = NULL, catatan = NULL WHERE mitra_id = ?")->execute([$mitraId]);
        return true;
    }
    return false;
}

/* ============================================================
 * TINDAK LANJUT
 * ============================================================ */

/** Ambil data tindak lanjut, opsional difilter per mitra atau mengecualikan item yang telah selesai. */
function getTindakLanjut(PDO $pdo, ?int $mitraId = null, int $limit = 0, bool $excludeSelesai = false): array {
    $sql = 'SELECT tl.*, mk.kode, mk.nama_mitra
            FROM tindak_lanjut tl
            JOIN mitra_kinerja mk ON tl.mitra_id = mk.id';
    $where = [];
    $params = [];
    if ($mitraId) {
        $where[] = 'tl.mitra_id = ?';
        $params[] = $mitraId;
    }
    if ($excludeSelesai) {
        $where[] = "tl.status != 'Selesai'";
    }
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY CASE WHEN tl.status = 'Selesai' THEN 1 ELSE 0 END ASC, CASE WHEN tl.tenggat IS NULL OR tl.tenggat = '0000-00-00' THEN 1 ELSE 0 END ASC, tl.tenggat ASC, tl.id DESC";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/* ============================================================
 * DASHBOARD STATISTICS
 * ============================================================ */

/** Statistik lengkap untuk dashboard. */
function getDashboardStats(array $all): array {
    $total = count($all);
    $pilotCount = 0;
    $efektif = 0;
    $perluPerhatian = 0;
    $berisiko = 0;
    $totalNilai = 0;
    $nilaiCount = 0;
    $aspekScores = ['I1' => [], 'I2' => [], 'I3' => [], 'I4' => [], 'I5' => [], 'I6' => [], 'I7' => []];

    // North Star Metrics (flow.pdf)
    $aktifCount = 0;
    $outputOutcomeCount = 0;
    $berdampakCount = 0;
    $rekomendasiCount = [
        'LANJUT' => 0,
        'PERBAIKI' => 0,
        'PERPANJANG' => 0,
        'REPLIKASI' => 0,
        'HENTIKAN' => 0,
        'BELUM DITENTUKAN' => 0,
    ];

    foreach ($all as $s) {
        $m = $s['mitra'];
        if ($m['portofolio'] === 'Pilot Utama') $pilotCount++;

        // Mendukung posisi portofolio deskriptif / hasil audit V3
        $pos = strtoupper(trim((string)($s['posisi_portofolio'] ?? '')));
        $isBerdampak = (str_contains($pos, 'BERDAMPAK') || str_contains($pos, 'DAMPAK')) && !str_contains($pos, 'BELUM') && !str_contains($pos, 'TIDAK');
        $isOutputOutcome = $isBerdampak 
            || (str_contains($pos, 'OUTCOME') && !str_contains($pos, 'BELUM') && !str_contains($pos, 'TIDAK'))
            || (str_contains($pos, 'OUTPUT') && !str_contains($pos, 'BELUM') && !str_contains($pos, 'TIDAK'));
        $isAktif = $isOutputOutcome 
            || (str_contains($pos, 'AKTIF') && !str_contains($pos, 'TIDAK') && !str_contains($pos, 'BELUM')) 
            || (str_contains($pos, 'IMPLEMENTASI') && !str_contains($pos, 'BELUM') && !str_contains($pos, 'TIDAK') && !str_contains($pos, 'MASA IMPLEMENTASI AWAL'));

        if ($isAktif) $aktifCount++;
        if ($isOutputOutcome) $outputOutcomeCount++;
        if ($isBerdampak) $berdampakCount++;

        // Ekstrak kata kunci dasar dari rekomendasi multi-line
        $rekKey = ekstrakKeywordRekomendasi($s['rekomendasi'] ?? null);
        if (isset($rekomendasiCount[$rekKey])) {
            $rekomendasiCount[$rekKey]++;
        }

        $efektivitas = statusEfektivitas($s['kategori']);
        if ($efektivitas === 'Efektif') $efektif++;
        elseif ($efektivitas === 'Berisiko') $berisiko++;
        else $perluPerhatian++;

        // Rata-rata nilai: hanya gunakan nilai_final jika tidak null dan skor_lengkap bernilai true
        $scoreToUse = null;
        if (isset($s['nilai_final']) && $s['nilai_final'] !== null && !empty($s['skor_lengkap'])) {
            $scoreToUse = (float)$s['nilai_final'];
        }

        if ($scoreToUse !== null) {
            $totalNilai += $scoreToUse;
            $nilaiCount++;

            foreach ($s['indikator'] as $ind) {
                if ($ind['nilai'] !== null && isset($aspekScores[$ind['kode_indikator']])) {
                    $aspekScores[$ind['kode_indikator']][] = (float)$ind['nilai'];
                }
            }
        }
    }

    $rataRataNilai = $nilaiCount > 0 ? round($totalNilai / $nilaiCount, 1) : 0;
    $aspekRataRata = [];
    foreach ($aspekScores as $kode => $scores) {
        $aspekRataRata[$kode] = count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : 0;
    }

    return compact(
        'total', 'pilotCount', 'efektif', 'perluPerhatian', 'berisiko', 
        'rataRataNilai', 'nilaiCount', 'aspekRataRata',
        'aktifCount', 'outputOutcomeCount', 'berdampakCount', 'rekomendasiCount'
    );
}

/** Ringkasan operasional tambahan untuk dashboard (Gate 0, Validasi, Rencana Kerja, Masa Berlaku). */
function getOperationalStats(PDO $pdo, array $all): array {
    $g0Total = 0;
    $g0Pending = 0;
    $g0Approved = 0;
    try {
        $g0Total = (int)$pdo->query('SELECT COUNT(*) FROM pra_pks')->fetchColumn();
        $g0Pending = (int)$pdo->query("SELECT COUNT(*) FROM pra_pks WHERE status_persetujuan = 'Menunggu Persetujuan Pimpinan'")->fetchColumn();
        $g0Approved = (int)$pdo->query("SELECT COUNT(*) FROM pra_pks WHERE status_persetujuan = 'Disetujui Pimpinan'")->fetchColumn();
    } catch (Throwable $e) {}

    $siapValidasi = 0;
    $disetujuiValidasi = 0;
    $belumLengkapValidasi = 0;
    foreach ($all as $s) {
        $vStatus = $s['validasi']['status'] ?? '';
        $scStatus = $s['status_scorecard'] ?? '';
        if ($vStatus === 'DISETUJUI' || $scStatus === 'FINAL/TERVALIDASI' || $scStatus === 'FINAL') {
            $disetujuiValidasi++;
        } elseif ($scStatus === 'SIAP DIVALIDASI') {
            $siapValidasi++;
        } else {
            $belumLengkapValidasi++;
        }
    }

    $rkCount = 0;
    $smCount = 0;
    try {
        $rkCount = (int)$pdo->query('SELECT COUNT(*) FROM rencana_kerja')->fetchColumn();
        $smCount = (int)$pdo->query('SELECT COUNT(*) FROM siklus_monev')->fetchColumn();
    } catch (Throwable $e) {}

    $mouCount = 0;
    $pksCount = 0;
    $validAman = 0;
    $validPerhatian = 0;
    $validKritis = 0;

    foreach ($all as $s) {
        $m = $s['mitra'] ?? [];
        if (($m['jenis'] ?? '') === 'MoU') {
            $mouCount++;
        } else {
            $pksCount++;
        }

        $mb = hitungMasaBerlaku($m['tanggal_berakhir'] ?? null, $m['cutoff_date'] ?? null, $m['status_tanggal'] ?? null);
        $sisa = $mb['sisa_hari'] ?? null;
        if ($sisa === null || $sisa <= 30) {
            $validKritis++;
        } elseif ($sisa <= 180) {
            $validPerhatian++;
        } else {
            $validAman++;
        }
    }

    return compact(
        'g0Total', 'g0Pending', 'g0Approved',
        'siapValidasi', 'disetujuiValidasi', 'belumLengkapValidasi',
        'rkCount', 'smCount',
        'mouCount', 'pksCount',
        'validAman', 'validPerhatian', 'validKritis'
    );
}
