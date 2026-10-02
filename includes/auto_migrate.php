<?php
/**
 * Auto-migration and self-healing schema helper.
 * Ensures all required V2.1 tables and columns exist automatically,
 * preventing HTTP 500 errors on newly pulled environments.
 */

function ensureDatabaseSchema(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;

    // ── 0. FAST-PATH HEALTH CHECK (Sub-millisecond) ─────────────────────────
    // Jika database sudah aktif, memiliki mitra_kinerja, bidang, dan pra_pks,
    // langsung return tanpa menjalankan DDL / ALTER TABLE apa pun.
    try {
        $fastCheck = $pdo->query("SELECT m.bidang, m.evaluasi_per_tahun, p.id FROM mitra_kinerja m, pra_pks p LIMIT 1");
        if ($fastCheck !== false && $fastCheck->fetch() !== false) {
            $checked = true;
            return;
        }
    } catch (Throwable $e) {
        // Skema belum lengkap atau database baru, lanjutkan ke migrasi di bawah
    }

    $checked = true;

    try {
        // 0. SELF-HEALING DATABASE BOOTSTRAP:
        // Jika database baru/kosong tanpa tabel mitra_kinerja, otomatis lakukan bootstrap dari dump
        try {
            $checkMk = $pdo->query("SHOW TABLES LIKE 'mitra_kinerja'")->fetchAll();
            if (empty($checkMk)) {
                $dumpPath = __DIR__ . '/../database/mitra_kinerja_dump.sql';
                if (file_exists($dumpPath)) {
                    $sqlDump = file_get_contents($dumpPath);
                    if (!empty($sqlDump)) {
                        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                        $pdo->exec($sqlDump);
                        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
                        return; // Selesai bootstrap lengkap
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Database bootstrap check error: ' . $e->getMessage());
        }

        // 1. Pastikan seluruh tabel terstruktur ada
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS baseline_elemen (
                id                      INT AUTO_INCREMENT PRIMARY KEY,
                mitra_id                INT NOT NULL,
                nomor_elemen            INT NOT NULL,
                kelompok                VARCHAR(50) NOT NULL,
                nama_elemen             VARCHAR(100) NOT NULL,
                yang_diperiksa          TEXT NOT NULL,
                sumber_bukti_minimum    TEXT NOT NULL,
                status                  ENUM('TERVERIFIKASI', 'BELUM TERVERIFIKASI', 'BELUM TERSEDIA', 'TIDAK RELEVAN', 'BELUM DIISI') NOT NULL DEFAULT 'BELUM DIISI',
                fakta_pemeriksaan       TEXT NULL,
                link_sumber_bukti       TEXT NULL,
                catatan                 TEXT NULL,
                created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (mitra_id) REFERENCES mitra_kinerja(id) ON DELETE CASCADE,
                UNIQUE KEY uq_mitra_elemen (mitra_id, nomor_elemen)
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS pra_pks (
                id                      INT AUTO_INCREMENT PRIMARY KEY,
                nomor_usulan            VARCHAR(50) NOT NULL UNIQUE,
                tipe_kerjasama          ENUM('Dalam Negeri', 'Luar Negeri') NOT NULL DEFAULT 'Dalam Negeri',
                jenis_naskah            ENUM('MoU', 'PKS', 'Lainnya') NOT NULL DEFAULT 'PKS',
                unit_pemrakarsa         VARCHAR(255) NOT NULL,
                penanggung_jawab_usulan VARCHAR(255) NULL,
                calon_mitra             VARCHAR(255) NOT NULL,
                judul_rencana           TEXT NOT NULL,
                tujuan_singkat          TEXT NULL,
                ruang_lingkup           TEXT NULL,
                penerima_manfaat        TEXT NULL,
                perkiraan_mulai         DATE NULL,
                perkiraan_selesai       DATE NULL,
                k1_kesesuaian_strategis ENUM('YA', 'TIDAK') NOT NULL DEFAULT 'YA',
                k2_kebutuhan_daya_ungkit ENUM('YA', 'TIDAK') NOT NULL DEFAULT 'YA',
                k3_kelayakan_mitra      ENUM('YA', 'TIDAK') NOT NULL DEFAULT 'YA',
                k4_kesiapan_sumber_daya ENUM('YA', 'TIDAK') NOT NULL DEFAULT 'YA',
                k5_risiko_keberlanjutan ENUM('YA', 'TIDAK') NOT NULL DEFAULT 'YA',
                pertanyaan_uji          LONGTEXT NULL,
                trigger_khusus          LONGTEXT NULL,
                catatan_verifikasi      TEXT NULL,
                gap_penyempurnaan       TEXT NULL,
                unit_review_tambahan    TEXT NULL,
                batas_waktu_penyempurnaan DATE NULL,
                status_rekomendasi      ENUM('Layak', 'Perlu Penyempurnaan', 'Tidak Prioritas / Tidak Layak') NOT NULL DEFAULT 'Layak',
                status_persetujuan      ENUM('Menunggu Persetujuan Pimpinan', 'Disetujui Pimpinan', 'Dikembalikan untuk Revisi', 'Ditolak Pimpinan') NOT NULL DEFAULT 'Menunggu Persetujuan Pimpinan',
                catatan_pimpinan        TEXT NULL,
                tanggal_persetujuan     DATE NULL,
                pimpinan_id             INT NULL,
                is_promoted_to_pks      TINYINT(1) NOT NULL DEFAULT 0,
                created_by              INT NULL,
                created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS rencana_kerja (
                id                  INT AUTO_INCREMENT PRIMARY KEY,
                mitra_id            INT NOT NULL,
                judul_rencana       VARCHAR(255) NOT NULL,
                ruang_lingkup       TEXT NULL,
                maksud_tujuan       TEXT NULL,
                tanggal_mulai       DATE NOT NULL,
                tanggal_selesai     DATE NOT NULL,
                status              ENUM('Draft', 'Proses Persetujuan', 'Disetujui', 'Selesai') NOT NULL DEFAULT 'Disetujui',
                alasan_persetujuan  TEXT NULL,
                draft_naskah        VARCHAR(255) NULL,
                created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (mitra_id) REFERENCES mitra_kinerja(id) ON DELETE CASCADE
            ) ENGINE=InnoDB;

            CREATE TABLE IF NOT EXISTS siklus_monev (
                id                          INT AUTO_INCREMENT PRIMARY KEY,
                mitra_id                    INT NOT NULL,
                rencana_kerja_id            INT NULL,
                siklus_ke                   INT NOT NULL,
                nama_siklus                 VARCHAR(100) NOT NULL,
                tanggal_target_evaluasi     DATE NOT NULL,
                tanggal_realisasi_evaluasi  DATE NULL,
                status_siklus               ENUM('Menunggu', 'Perlu Penilaian Segera', 'Sedang Dinilai', 'Selesai') NOT NULL DEFAULT 'Menunggu',
                catatan_monev               TEXT NULL,
                nilai_siklus                DECIMAL(6,2) NULL,
                created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (mitra_id) REFERENCES mitra_kinerja(id) ON DELETE CASCADE,
                FOREIGN KEY (rencana_kerja_id) REFERENCES rencana_kerja(id) ON DELETE SET NULL
            ) ENGINE=InnoDB;
        ");

        // ── 3. CEK DAN TAMBAH KOLOM YANG KURANG SECARA EFISIEN ──────────────
        try {
            $colsMk = $pdo->query("SHOW COLUMNS FROM mitra_kinerja")->fetchAll(PDO::FETCH_COLUMN);
            $colsMkMap = array_flip($colsMk);

            $neededColsMk = [
                'bidang' => "ALTER TABLE mitra_kinerja ADD COLUMN bidang ENUM('AHU', 'KI', 'P3H', 'PPL', 'Keuangan', 'Humas', 'SDM') NULL DEFAULT 'AHU' AFTER jenis",
                'pks_induk_id' => "ALTER TABLE mitra_kinerja ADD COLUMN pks_induk_id INT NULL AFTER jenis",
                'baseline_status' => "ALTER TABLE mitra_kinerja ADD COLUMN baseline_status ENUM('BELUM DIISI', 'DALAM PROSES', 'TERVERIFIKASI / DIKUNCI') NOT NULL DEFAULT 'BELUM DIISI' AFTER status_tanggal",
                'baseline_locked_at' => "ALTER TABLE mitra_kinerja ADD COLUMN baseline_locked_at DATETIME NULL AFTER baseline_status",
                'baseline_locked_by' => "ALTER TABLE mitra_kinerja ADD COLUMN baseline_locked_by INT NULL AFTER baseline_locked_at",
                'baseline_pemeriksa' => "ALTER TABLE mitra_kinerja ADD COLUMN baseline_pemeriksa VARCHAR(255) NULL AFTER baseline_locked_by",
                'baseline_catatan_ringkasan' => "ALTER TABLE mitra_kinerja ADD COLUMN baseline_catatan_ringkasan TEXT NULL AFTER baseline_pemeriksa",
                'pic_internal' => "ALTER TABLE mitra_kinerja ADD COLUMN pic_internal VARCHAR(255) NULL AFTER sumber_baseline",
                'pic_mitra' => "ALTER TABLE mitra_kinerja ADD COLUMN pic_mitra VARCHAR(255) NULL AFTER pic_internal",
                'pic_focal_point' => "ALTER TABLE mitra_kinerja ADD COLUMN pic_focal_point VARCHAR(255) NULL AFTER pic_mitra",
                'evaluasi_per_tahun' => "ALTER TABLE mitra_kinerja ADD COLUMN evaluasi_per_tahun INT NOT NULL DEFAULT 4 AFTER status_tanggal",
            ];

            foreach ($neededColsMk as $cName => $sqlAlter) {
                if (!isset($colsMkMap[$cName])) {
                    try { $pdo->exec($sqlAlter); } catch (Throwable $e) {}
                }
            }
        } catch (Throwable $e) {}

        // Tindak lanjut file_bukti
        try {
            $colsTl = $pdo->query("SHOW COLUMNS FROM tindak_lanjut")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('file_bukti', $colsTl, true)) {
                $pdo->exec("ALTER TABLE tindak_lanjut ADD COLUMN file_bukti VARCHAR(255) NULL AFTER status");
            }
        } catch (Throwable $e) {}

        // Intervensi usulan uraian_kendala
        try {
            $colsIu = $pdo->query("SHOW COLUMNS FROM intervensi_usulan")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('uraian_kendala', $colsIu, true)) {
                $pdo->exec("ALTER TABLE intervensi_usulan ADD COLUMN uraian_kendala TEXT NULL AFTER upaya_dilakukan");
            }
        } catch (Throwable $e) {}

        // Indikator skor kondisi_baseline & kondisi_saat_ini
        try {
            $colsIs = $pdo->query("SHOW COLUMNS FROM indikator_skor")->fetchAll(PDO::FETCH_COLUMN);
            $colsIsMap = array_flip($colsIs);
            if (!isset($colsIsMap['kondisi_baseline'])) {
                $pdo->exec("ALTER TABLE indikator_skor ADD COLUMN kondisi_baseline TEXT NULL AFTER referensi_baseline");
            }
            if (!isset($colsIsMap['kondisi_saat_ini'])) {
                $pdo->exec("ALTER TABLE indikator_skor ADD COLUMN kondisi_saat_ini TEXT NULL AFTER kondisi_baseline");
            }
        } catch (Throwable $e) {}

        // Users role enum check
        try {
            $roleCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
            if ($roleCol && (!str_contains($roleCol['Type'] ?? '', 'pengampu') || !str_contains($roleCol['Type'] ?? '', 'pic'))) {
                $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','pemeriksa','validator','pimpinan','pengampu','pic') NOT NULL");
            }
        } catch (Throwable $e) {}

        // 3. Pastikan row validasi ada untuk tiap naskah
        try {
            $pdo->exec("INSERT IGNORE INTO validasi (mitra_id, status) SELECT id, 'BELUM' FROM mitra_kinerja WHERE id NOT IN (SELECT mitra_id FROM validasi)");
        } catch (Throwable $e) {}

        // 4. Pastikan akun demo standar ada (admin, pemeriksa, validator, pimpinan, pengampu, pic)
        try {
            $demoAccounts = [
                ['Administrator', 'admin', 'admin123', 'admin'],
                ['Pemeriksa Kerja Sama', 'pemeriksa', 'pemeriksa123', 'pemeriksa'],
                ['Validator Unit', 'validator', 'validator123', 'validator'],
                ['Pimpinan Wilayah', 'pimpinan', 'pimpinan123', 'pimpinan'],
                ['Unit Pengampu (Divisi/Bagian)', 'pengampu', 'pengampu123', 'pengampu'],
                ['PIC Operasional Kerja Sama', 'pic', 'pic123', 'pic'],
            ];
            foreach ($demoAccounts as $da) {
                $stmtU = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmtU->execute([$da[1]]);
                $uRow = $stmtU->fetch();
                $h = password_hash($da[2], PASSWORD_BCRYPT);
                if (!$uRow) {
                    $pdo->prepare("INSERT INTO users (nama, username, password_hash, role, aktif) VALUES (?, ?, ?, ?, 1)")
                        ->execute([$da[0], $da[1], $h, $da[3]]);
                }
            }
        } catch (Throwable $e) {}

        // 5. SELF-HEALING: Pastikan Gate 0 (pra_pks) otomatis terisi bila kosong pada perangkat tim
        try {
            $cntPra = (int)$pdo->query("SELECT COUNT(*) FROM pra_pks")->fetchColumn();
            if ($cntPra === 0) {
                $qDef = [];
                for ($qi = 1; $qi <= 18; $qi++) {
                    $qDef["q$qi"] = ['jawab' => 'YA', 'bukti' => 'Dokumen pendukung dan verifikasi awal telah ditelaah'];
                }
                $qJson = json_encode($qDef);

                $tClean = [];
                for ($ti = 1; $ti <= 7; $ti++) {
                    $tClean["t$ti"] = ['jawab' => 'TIDAK', 'catatan' => ''];
                }
                $tCleanJson = json_encode($tClean);

                $tForeign = $tClean;
                $tForeign['t1'] = ['jawab' => 'YA', 'catatan' => 'Melibatkan entitas asing (Singapore Academy of Law). Diperlukan koordinasi clearance dengan Biro Hukerma Kementerian Hukum RI.'];
                $tForeignJson = json_encode($tForeign);

                $tPaten = $tClean;
                $tPaten['t4'] = ['jawab' => 'YA', 'catatan' => 'Fasilitasi pendaftaran paten dan hak cipta bersama Sentra KI Kampus'];
                $tPatenJson = json_encode($tPaten);

                $proposals = [
                    [
                        1, 'PRA-2026-001', 'Dalam Negeri', 'PKS', 'Divisi Pelayanan Hukum', 'Tim Kerja Sama & Fasilitasi Hukum',
                        'Universitas Maritim Raja Ali Haji (UMRAH)', 'Fasilitasi Sentra Riset Hukum Maritim & Pos Bantuan Hukum Masyarakat Pesisir',
                        'Mendekatkan akses keadilan dan pendampingan hukum pro-bono bagi masyarakat nelayan pesisir Kepulauan Riau',
                        'Penyuluhan hukum, riset kebijakan maritim, dan klinik konsultasi hukum keliling', 'Masyarakat nelayan tradisional dan sivitas akademika UMRAH',
                        '2026-10-01', '2029-09-30', $qJson, $tCleanJson, 'Dokumen proposal lengkap, rekam jejak mitra sangat baik, mendukung prioritas Kanwil',
                        'Tidak ada gap material.', 'Subbagian Humas, RB, dan TI', 'Layak', 'Disetujui Pimpinan',
                        'Disetujui untuk ditindaklanjuti penyusunan naskah PKS dan rencana kerja rinci', '2026-09-22', 1
                    ],
                    [
                        2, 'PRA-2026-002', 'Luar Negeri', 'MoU', 'Bagian Tata Usaha dan Kerjasama', 'Tim Kerja Sama & Fasilitasi Hukum',
                        'Singapore Academy of Law', 'Penguatan Kapasitas Penyelesaian Sengketa Komersial Lintas Batas',
                        'Benchmarking dan workshop mediasi hukum komersial lintas yurisdiksi Batam-Singapura',
                        'Pelatihan bersama kurator, mediator, dan pertukaran materi literasi hukum arbitrase', 'Aparatur Kanwil Kepri dan praktisi hukum wilayah perbatasan',
                        '2027-01-15', '2028-01-14', $qJson, $tForeignJson, 'Konsultasi awal dengan Biro Kerja Sama Luar Negeri Kementerian Hukum Pusat sedang berjalan',
                        'Menunggu surat rekomendasi / clearance dari Biro Hukerma Kementerian Hukum RI.', 'Biro Hukerma Kementerian Hukum RI & Ditjen AHU', 'Layak', 'Menunggu Persetujuan Pimpinan',
                        null, null, 0
                    ],
                    [
                        3, 'PRA-DN-001', 'Dalam Negeri', 'PKS', 'Divisi Pelayanan Hukum', 'Tim Kerja Sama & Fasilitasi Hukum',
                        'Universitas Maritim Raja Ali Haji (UMRAH)', 'Fasilitasi Sentra Riset Hukum Maritim dan Bantuan Hukum Nelayan Pesisir',
                        'Mendekatkan akses keadilan masyarakat nelayan pesisir',
                        'Penyuluhan hukum, riset kebijakan maritim, dan klinik konsultasi hukum keliling', 'Masyarakat nelayan tradisional dan sivitas akademika UMRAH',
                        '2026-11-01', '2029-10-31', $qJson, $tCleanJson, 'Proposal lengkap dan telah dicek legalitas mitra',
                        'Tidak ada gap material.', 'Subbagian Humas, RB, dan TI', 'Layak', 'Menunggu Persetujuan Pimpinan',
                        null, null, 0
                    ],
                    [
                        4, 'PRA-DN-002', 'Dalam Negeri', 'MoU', 'Bagian Tata Usaha dan Umum', 'Tim Kerja Sama & Fasilitasi Hukum',
                        'Pemerintah Kabupaten Bintan', 'Penguatan Literasi Hukum dan Layanan Terpadu Desa Sadar Hukum',
                        'Membentuk desa binaan sadar hukum di pesisir',
                        'Pelatihan paralegal dan sosialisasi peraturan', 'Masyarakat desa pesisir Kabupaten Bintan',
                        '2027-01-01', '2030-12-31', $qJson, $tCleanJson, 'Telah dikoordinasikan dalam forum Renja',
                        'Tidak ada gap material.', 'Subbagian Humas, RB, dan TI', 'Layak', 'Disetujui Pimpinan',
                        'Disetujui. Sangat mendukung target Desa Sadar Hukum di Kepri.', '2026-09-23', 1
                    ],
                    [
                        5, 'PRA-TEST-999', 'Dalam Negeri', 'PKS', 'Divisi Pelayanan Hukum', 'Tim Kerja Sama & Fasilitasi Hukum',
                        'Politeknik Negeri Batam - Sentra KI', 'Inkubasi Paten dan Desain Industri Kampus Vokasi',
                        'Mendorong hilirisasi riset terapan kampus vokasi ke pendaftaran paten resmi',
                        'Inkubasi dan klinik paten dosen/mahasiswa vokasi', 'Civitas akademika Polibatam dan inventor lokal',
                        '2026-09-01', '2029-08-31', $qJson, $tCleanJson, 'Proposal paten dan riset terapan telah diverifikasi',
                        'Tidak ada gap material.', 'Subbagian Humas, RB, dan TI', 'Layak', 'Disetujui Pimpinan',
                        'Disetujui oleh Kakanwil, segera koordinasikan naskah PKS dan rencana kerja.', '2026-09-23', 1
                    ],
                    [
                        6, 'PRA-2026-TEST-FULL', 'Dalam Negeri', 'PKS', 'Divisi Pelayanan Hukum', 'Kasubbid Penyuluhan Hukum',
                        'Universitas Batam (UNIBA)', 'Kerja Sama Pembentukan Pos Bantuan Hukum Terpadu dan Edukasi Kekayaan Intelektual',
                        'Meningkatkan literasi hukum dan perlindungan paten sivitas akademika',
                        'Konsultasi hukum gratis, pendaftaran hak cipta, workshop paten', 'Mahasiswa, dosen, dan masyarakat umum Kota Batam',
                        '2026-10-15', '2029-10-14', $qJson, $tPatenJson, 'Klarifikasi administrasi dan kelembagaan posbankum',
                        'Perlu penyesuaian klausul hak cipta dan paten hasil penelitian.', 'Biro Hukum & Ditjen KI', 'Perlu Penyempurnaan', 'Menunggu Persetujuan Pimpinan',
                        null, null, 0
                    ]
                ];

                $sqlIns = "INSERT INTO pra_pks (
                    id, nomor_usulan, tipe_kerjasama, jenis_naskah, unit_pemrakarsa, penanggung_jawab_usulan,
                    calon_mitra, judul_rencana, tujuan_singkat, ruang_lingkup, penerima_manfaat,
                    perkiraan_mulai, perkiraan_selesai, k1_kesesuaian_strategis, k2_kebutuhan_daya_ungkit,
                    k3_kelayakan_mitra, k4_kesiapan_sumber_daya, k5_risiko_keberlanjutan, pertanyaan_uji,
                    trigger_khusus, catatan_verifikasi, gap_penyempurnaan, unit_review_tambahan,
                    status_rekomendasi, status_persetujuan, catatan_pimpinan, tanggal_persetujuan, is_promoted_to_pks
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'YA', 'YA', 'YA', 'YA', 'YA', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )";
                $stmtIns = $pdo->prepare($sqlIns);
                foreach ($proposals as $p) {
                    $stmtIns->execute($p);
                }
            }
        } catch (Throwable $e) {
            error_log('pra_pks self-heal error: ' . $e->getMessage());
        }

        // 6. SELF-HEALING: Pastikan mitra_kinerja tidak kosong jika seed tersedia
        try {
            $cntMitra = (int)$pdo->query("SELECT COUNT(*) FROM mitra_kinerja")->fetchColumn();
            if ($cntMitra === 0) {
                $seedFile = __DIR__ . '/../database/seed_data.sql';
                if (file_exists($seedFile)) {
                    $sqlContent = file_get_contents($seedFile);
                    if ($sqlContent) {
                        $pdo->exec($sqlContent);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('mitra_kinerja seed error: ' . $e->getMessage());
        }
    } catch (Throwable $e) {
        error_log('ensureDatabaseSchema error: ' . $e->getMessage());
    }
}
