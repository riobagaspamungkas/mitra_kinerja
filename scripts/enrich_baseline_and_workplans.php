<?php
require_once dirname(__DIR__) . '/config/database.php';
$pdo = getDB();

$plans = [
    'P02' => [
        [
            'judul' => 'Integrasi Materi Pembelajaran E-Book & Modul Literasi Hukum pada BESTI Learning Hub',
            'ruang' => 'Integrasi e-book/modul literasi digital, edukasi hukum, kesadaran hukum, dan kekayaan intelektual',
            'tujuan' => 'Meningkatkan literasi hukum dan pencegahan narkoba melalui platform digital',
            'mulai' => '2026-09-01',
            'selesai' => '2027-08-31',
            'alasan' => 'Disetujui berdasarkan tindak lanjut PKS BNN Kepri'
        ],
        [
            'judul' => 'Kolaborasi Konten Edukasi Hukum & Pencegahan Narkoba (Podcast & Live Streaming)',
            'ruang' => 'Podcast bersama, webinar edukasi hukum, dan kampanye publik terpadu',
            'tujuan' => 'Penyebarluasan informasi hukum dan bahaya narkoba bagi generasi muda Kepri',
            'mulai' => '2026-10-01',
            'selesai' => '2027-03-31',
            'alasan' => 'Sesuai kesepakatan rapat penguatan PIC'
        ]
    ],
    'P08' => [
        [
            'judul' => 'Fasilitasi dan Pendampingan Pengurusan Kekayaan Intelektual Produk Hilirisasi Kampus Vokasi',
            'ruang' => 'Pemeriksaan substantif awal, pendampingan penyusunan spesifikasi paten, koreksi dokumen penolakan',
            'tujuan' => 'Percepatan pendaftaran dan perolehan granted paten produk inovasi Polibatam',
            'mulai' => '2026-10-01',
            'selesai' => '2026-12-31',
            'alasan' => 'Usulan prioritas Sentra HKI Polibatam'
        ],
        [
            'judul' => 'Pendampingan Teknis dan Lisensi Komersialisasi Kekayaan Intelektual Granted',
            'ruang' => 'Workshop lisensi, drafting perjanjian royalti, pendampingan komersialisasi ke industri',
            'tujuan' => 'Menjadikan KI kampus memiliki nilai ekonomi dan daya serap industri',
            'mulai' => '2026-11-01',
            'selesai' => '2027-02-28',
            'alasan' => 'Mendukung program Merdeka Belajar Kampus Berdampak'
        ]
    ],
    'P09' => [
        [
            'judul' => 'Pembentukan dan Penguatan Sentra Kekayaan Intelektual (Sentra KI) STAI/IAI Natuna',
            'ruang' => 'Penetapan struktur organisasi, tata kerja, sosialisasi dosen/mahasiswa, klinik pendaftaran',
            'tujuan' => 'Membangun ekosistem perlindungan KI di wilayah perbatasan utara Natuna',
            'mulai' => '2026-09-15',
            'selesai' => '2027-09-14',
            'alasan' => 'Tindak lanjut MoU penguatan Tri Dharma wilayah perbatasan'
        ],
        [
            'judul' => 'Klinik dan Fasilitasi Pendaftaran Hak Cipta & Merek Berbasis Khazanah Melayu Natuna',
            'ruang' => 'Inventarisasi karya buku, modul, karya budaya, dan merek produk lokal Natuna',
            'tujuan' => 'Perlindungan hukum atas karya intelektual dan kekayaan komunal Natuna',
            'mulai' => '2026-11-01',
            'selesai' => '2026-12-31',
            'alasan' => 'Prioritas pelindungan budaya perbatasan'
        ]
    ],
    'P05' => [
        [
            'judul' => 'Peningkatan Literasi Hukum dan Penguatan Tri Dharma Perguruan Tinggi Perbatasan',
            'ruang' => 'Penyuluhan hukum sivitas akademika, klinik konsultasi hukum, dan pembinaan desa sadar hukum',
            'tujuan' => 'Mewujudkan kesadaran hukum masyarakat kampus dan perbatasan Bintan-Tanjungpinang',
            'mulai' => '2026-09-01',
            'selesai' => '2027-08-31',
            'alasan' => 'Kesepakatan bersama STAIN SAR Kepri'
        ]
    ],
    'C01' => [
        [
            'judul' => 'Sosialisasi dan Klinik Pengajuan Paten Sederhana Sivitas Akademika',
            'ruang' => 'Bimbingan teknis drafting paten sederhana, inventarisasi invensi dosen dan mahasiswa',
            'tujuan' => 'Peningkatan jumlah permohonan paten terdaftar dari kampus Politeknik Bintan Cakrawala',
            'mulai' => '2026-10-01',
            'selesai' => '2026-12-31',
            'alasan' => 'Tindak lanjut rapat penguatan Sentra KI'
        ]
    ],
    'C03' => [
        [
            'judul' => 'Kuliah Pakar dan Pembentukan Pusat Konsultasi Layanan Hukum Terpadu',
            'ruang' => 'Kuliah umum berkala oleh narasumber Kanwil, pos aduan konsultasi hukum dan pendaftaran hak cipta',
            'tujuan' => 'Edukasi hukum mahasiswa dan masyarakat sekitar kampus Batam',
            'mulai' => '2026-09-01',
            'selesai' => '2027-08-31',
            'alasan' => 'Sesuai usulan stakeholder Universitas Ibnu Sina'
        ]
    ],
    'P01' => [
        [
            'judul' => 'Pelindungan dan Inventarisasi Kekayaan Intelektual Komunal Wastra dan Kriya Kepri',
            'ruang' => 'Pendataan motif batik/tenun Kepri, pendaftaran hak cipta dan merek kolektif pengrajin',
            'tujuan' => 'Pemberdayaan ekonomi pengrajin lokal melalui kepastian hukum hak kekayaan intelektual',
            'mulai' => '2025-06-01',
            'selesai' => '2026-05-31',
            'alasan' => 'Implementasi PKS Dekranasda 2025-2030'
        ]
    ],
    'P03' => [
        [
            'judul' => 'Pelayanan Hukum Terpadu dan Pembinaan Kelurahan Sadar Hukum Kota Tanjungpinang',
            'ruang' => 'Konsultasi hukum gratis, sosialisasi peraturan daerah, mediasi non-litigasi tingkat kelurahan',
            'tujuan' => 'Meningkatkan kesadaran hukum masyarakat ibukota provinsi',
            'mulai' => '2025-05-01',
            'selesai' => '2026-04-30',
            'alasan' => 'Nota Kesepakatan Pemda Tanjungpinang'
        ]
    ],
    'P06' => [
        [
            'judul' => 'Klinik Advokasi Hukum Nelayan Pesisir dan Sentra Riset Hukum Maritim',
            'ruang' => 'Konsultasi hukum keliling pulau, riset regulasi kelautan dan perikanan, pengabdian masyarakat',
            'tujuan' => 'Mendekatkan keadilan hukum bagi nelayan tradisional Kepulauan Riau',
            'mulai' => '2025-10-01',
            'selesai' => '2026-09-30',
            'alasan' => 'Nota Kesepahaman UMRAH'
        ]
    ]
];

$insertedPlans = 0;
$insertedMonev = 0;

foreach ($plans as $kode => $planList) {
    $stmtM = $pdo->prepare('SELECT id, tanggal_mulai, tanggal_berakhir FROM mitra_kinerja WHERE kode = ?');
    $stmtM->execute([$kode]);
    $m = $stmtM->fetch();
    if (!$m) continue;

    $mid = (int)$m['id'];

    foreach ($planList as $p) {
        // Cek duplikat judul
        $stmtC = $pdo->prepare('SELECT id FROM rencana_kerja WHERE mitra_id = ? AND judul_rencana = ?');
        $stmtC->execute([$mid, $p['judul']]);
        $existingRkId = $stmtC->fetchColumn();

        if (!$existingRkId) {
            $stmtI = $pdo->prepare('INSERT INTO rencana_kerja (
                mitra_id, judul_rencana, ruang_lingkup, maksud_tujuan, tanggal_mulai, tanggal_selesai, status, alasan_persetujuan
            ) VALUES (?, ?, ?, ?, ?, ?, \'Disetujui\', ?)');
            $stmtI->execute([$mid, $p['judul'], $p['ruang'], $p['tujuan'], $p['mulai'], $p['selesai'], $p['alasan']]);
            $rkId = (int)$pdo->lastInsertId();
            $insertedPlans++;
        } else {
            $rkId = (int)$existingRkId;
        }

        // Generate siklus monev jika belum ada
        $stmtSMCheck = $pdo->prepare('SELECT COUNT(*) FROM siklus_monev WHERE mitra_id = ? AND rencana_kerja_id = ?');
        $stmtSMCheck->execute([$mid, $rkId]);
        if ((int)$stmtSMCheck->fetchColumn() === 0) {
            // Milestone 1: Baseline & Tata Kelola Awal (Selesai)
            $stmtSm1 = $pdo->prepare('INSERT INTO siklus_monev (
                mitra_id, rencana_kerja_id, siklus_ke, nama_siklus, tanggal_target_evaluasi, tanggal_realisasi_evaluasi, status_siklus, catatan_monev, nilai_siklus
            ) VALUES (?, ?, 1, \'SC-1: Baseline & Tata Kelola Awal\', ?, ?, \'Selesai\', \'Penilaian kondisi awal selesai tervalidasi.\', 70.00)');
            $stmtSm1->execute([$mid, $rkId, $p['mulai'], $p['mulai']]);

            // Milestone 2: Evaluasi Triwulan (Perlu Penilaian Segera / Mendekati Tenggat)
            $t2 = date('Y-m-d', strtotime($p['mulai'] . ' +3 months'));
            $statusT2 = (strtotime($t2) <= strtotime('+35 days')) ? 'Perlu Penilaian Segera' : 'Sedang Dinilai';
            $stmtSm2 = $pdo->prepare('INSERT INTO siklus_monev (
                mitra_id, rencana_kerja_id, siklus_ke, nama_siklus, tanggal_target_evaluasi, status_siklus, catatan_monev
            ) VALUES (?, ?, 2, \'SC-2: Evaluasi Triwulan (Output)\', ?, ?, \'Memeriksa pemenuhan target output dan keteraturan eviden.\')');
            $stmtSm2->execute([$mid, $rkId, $t2, $statusT2]);

            // Milestone 3: Evaluasi Tengah Tahun (Outcome)
            $t3 = date('Y-m-d', strtotime($p['mulai'] . ' +6 months'));
            $stmtSm3 = $pdo->prepare('INSERT INTO siklus_monev (
                mitra_id, rencana_kerja_id, siklus_ke, nama_siklus, tanggal_target_evaluasi, status_siklus, catatan_monev
            ) VALUES (?, ?, 3, \'SC-3: Evaluasi Semester (Outcome)\', ?, \'Menunggu\', \'Pemeriksaan manfaat berkelanjutan bagi pemangku kepentingan.\')');
            $stmtSm3->execute([$mid, $rkId, $t3]);

            $insertedMonev += 3;
        }
    }
}

echo "Inserted $insertedPlans authentic work plans and $insertedMonev monitoring milestones.\n";

/* ── UPDATE 12 ELEMEN BASELINE FIX DENGAN DATA RESMI STAKEHOLDER ── */

$stakeholderEnrichment = [
    'P08' => [ // Politeknik Negeri Batam
        1 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Nomor para pihak resmi: 118/MOU.PL29/XI/2025 dan W.32-HH.04.04-1120. Naskah bertanda tangan cocok dengan P2MA.', 'link' => 'https://p2ma.kemenkum.go.id/uploads/kerjasama/naskah/1785760267.pdf'],
        6 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Unit Pengampu substansi: Divisi Pelayanan Hukum dan HAM (Subbid Pelayanan Kekayaan Intelektual).', 'link' => 'SK Pembagian Tugas Pelayanan KI Kanwil Kepri'],
        7 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Internal: Kepala Subbidang Pelayanan Kekayaan Intelektual Kanwil Kementerian Hukum Kepri.', 'link' => 'Daftar PIC Tim Proper Edison Manik'],
        8 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Mitra terkonfirmasi: Ridwan Purwanto, S.Sos., M.I.Kom. (Kepala Humas & Kerja Sama, HP: 085264686864) & Fitriyanti Nakul, S.Pd., M.Si (Ketua Sentra HKI, HP: 085830206709).', 'link' => 'Formulir Konfirmasi PIC Data Stakeholder Polibatam'],
        9 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Rencana tindak lanjut disepakati: Fasilitasi pendaftaran paten produk hilirisasi, kajian tarif khusus PP 20, dan lisensi komersialisasi KI.', 'link' => 'Sheet Usulan Tindak Lanjut Data Stakeholder Polibatam'],
        10 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Pelaksanaan berjalan: Sosialisasi Perlindungan Hak Cipta di Era AI telah terlaksana di Auditorium Polibatam.', 'link' => 'https://www.polibatam.ac.id/en/polibatam-strengthens-creative-ecosystem-and-copyright-protection-with-kemenkum-kepri-and-djki/'],
        11 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Folder eviden resmi tersedia lengkap: nota kesepahaman bertanda tangan, daftar hadir sosialisasi, dan dokumentasi foto acara.', 'link' => 'Folder Eviden_Politeknik Negeri Batam & P2MA'],
        12 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Gap/isu teridentifikasi: Dibutuhkan percepatan pemeriksaan substantif paten sederhana ajuan tahun 2023-2024 yang masih status dalam proses di DJKI.', 'link' => 'Catatan Kebutuhan PIC Mitra Polibatam']
    ],
    'P02' => [ // BNN Kepri
        1 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Nomor resmi para pihak: PKS/22/VII/KA/HK.10/2026/BNNP dan W.32-HH.04.05-30 bertanda tangan sah.', 'link' => 'Perjanjian Kerja Sama_BNN Kepri_Pencegahan dan Pemberantasan.pdf'],
        6 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Unit Pengampu: Divisi Pelayanan Hukum dan HAM & Bagian Umum.', 'link' => 'Nota Dinas Pembagian Peran Kerja Sama Kanwil Kepri'],
        7 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Internal: Kasubbid Pelayanan Administrasi Hukum Umum & Penyuluh Narkoba Kanwil.', 'link' => 'SK Tim Fasilitasi PKS Kanwil Kepri'],
        8 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Mitra terkonfirmasi: Ratih Frayunita Sari, S.I.Kom., M.A (Kepala Bagian Umum) & Melisa Dwi Putri, S.Psi (Analis SDM Aparatur Ahli Muda).', 'link' => 'Formulir Konfirmasi PIC Data Stakeholder BNN Kepri'],
        9 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Rencana tindak lanjut: Integrasi materi e-book literasi hukum di BESTI Learning Hub dan talkshow podcast hukum terpadu.', 'link' => 'Matriks Usulan Data Stakeholder BNN Kepri'],
        10 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Pelaksanaan berjalan: Koordinasi teknis integrasi konten pembelajaran hukum di platform edukasi digital.', 'link' => 'Notula Rapat Koordinasi Kanwil - BNNP Kepri'],
        11 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Eviden lengkap: naskah PKS asli, notula rapat penguatan PIC, dan dokumentasi platform.', 'link' => 'Folder Eviden_ BNN Kepri'],
        12 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Hambatan/gap: Sinkronisasi teknis format infografis dan modul pembelajaran agar sesuai standar kurikulum BNN.', 'link' => 'Catatan Teknis Rapat Koordinasi']
    ],
    'P09' => [ // STAI Natuna
        1 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Nomor naskah resmi: W.32-HH.04.04-24 dan 03/MOU/STAI-N/V/2026 bertanda tangan sah.', 'link' => 'NOTA KESEPAHAMAN_STAI NATUNA.pdf'],
        6 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Unit Pengampu: Divisi Pelayanan Hukum dan HAM & Kantor Imigrasi Kelas II TPI Ranai.', 'link' => 'Pembagian Wilayah Kerja Sama Perbatasan Kanwil Kepri'],
        7 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Internal: Subbagian Humas, RB, dan TI / Penghubung Wilayah Natuna.', 'link' => 'Daftar PIC Wilayah Perbatasan'],
        8 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Mitra terkonfirmasi: Dedi Supriadi, SE (HP: 081268307074) & Natuna Adi Putra, S.E.', 'link' => 'Formulir Konfirmasi PIC Data Stakeholder STAI Natuna'],
        9 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Rencana tindak lanjut: Pembentukan Sentra KI STAI Natuna dan klinik pendaftaran Hak Cipta karya budaya lokal.', 'link' => 'Matriks Usulan STAI Natuna'],
        10 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Pelaksanaan berjalan: Sosialisasi awal perlindungan kekayaan intelektual bagi sivitas akademika perbatasan.', 'link' => 'Laporan Singkat Sosialisasi Daring STAI Natuna'],
        11 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Folder eviden: scan nota kesepahaman, surat tindak lanjut daring, dan daftar kontak narahubung.', 'link' => 'Folder Eviden_Sekolah Tinggi Agama Islam Natuna'],
        12 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Gap/isu: Keterbatasan jarak geografis dan jaringan internet di pulau terluar; koordinasi dioptimalkan daring.', 'link' => 'Catatan Koordinasi Daring STAI Natuna']
    ],
    'P05' => [ // STAIN Sultan Abdurrahman
        1 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Nomor resmi: W.32.HH.04.04-1114 dan B-700/Sti.20/1.2/HM.01/03/2025 bertanda tangan sah.', 'link' => 'KESEPAKATAN BERSAMA_STAIN Sultan Abdurrahman Kepri.pdf'],
        6 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Unit Pengampu: Divisi Pelayanan Hukum dan HAM (Subbid Luhbankum dan JDIH).', 'link' => 'SK Penunjukan Pengampu Kerja Sama'],
        7 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Internal: Penyuluh Hukum Madya Kanwil Kementerian Hukum Kepri.', 'link' => 'Daftar PIC Internal Kanwil'],
        8 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Mitra terkonfirmasi: Dr. Fadhila Yonata, M.Pd & Dwi Rio Sudarroji, M.Psi.', 'link' => 'Formulir PIC Data Stakeholder STAIN SAR'],
        9 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Rencana tindak lanjut: Penyuluhan hukum serentak dan klinik bantuan hukum mahasiswa.', 'link' => 'Matriks Usulan STAIN SAR'],
        10 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Pelaksanaan: Telah terlaksana kuliah umum kesadaran hukum dan pembinaan jurnal ilmiah hukum.', 'link' => 'Dokumentasi Kuliah Umum STAIN SAR'],
        11 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Folder eviden: Nota kesepahaman resmi, daftar peserta kuliah umum, dan surat penunjukan.', 'link' => 'Folder Eviden_STAIN Sultan Abdurrahman Kepulauan Riau'],
        12 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Hambatan: Sinkronisasi kalender akademik semester ganjil dengan jadwal monev Kanwil.', 'link' => 'Notula Rapat Koordinasi STAIN SAR']
    ],
    'C01' => [ // Politeknik Bintan Cakrawala
        1 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Nomor naskah resmi: 002/MoU-PBC/III/2025 dan W.32-HH.04.04-1116 bertanda tangan.', 'link' => 'bintan cakrawala.pdf'],
        6 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Unit Pengampu: Divisi Pelayanan Hukum dan HAM (Pelayanan KI).', 'link' => 'SK Pembagian Tugas Tim KI'],
        7 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Internal: Analis Permohonan Kekayaan Intelektual Kanwil.', 'link' => 'Daftar PIC Proper Edison Manik'],
        8 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Mitra: Indah Andesta, S.Par., M.Par & Henricus Yayan Setyanto, S.S., M.Hum.', 'link' => 'Formulir PIC Data Stakeholder PBC'],
        9 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Rencana tindak lanjut: Klinik drafting paten sederhana dan penguatan Sentra KI kampus pariwisata.', 'link' => 'Sheet Usulan PBC'],
        10 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Pelaksanaan: Bimbingan teknis dasar pencarian paten dan hak cipta vokasi.', 'link' => 'Daftar Hadir Bimtek PBC'],
        11 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Folder eviden: scan nota kesepahaman dan notula pendampingan Sentra KI.', 'link' => 'Folder Eviden_Politeknik Bintan Cakrawala'],
        12 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Hambatan: Penyesuaian invensi riset terapan perhotelan/pariwisata ke format klaim paten sederhana.', 'link' => 'Catatan Teknis Sentra KI PBC']
    ],
    'C03' => [ // Universitas Ibnu Sina
        1 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Nomor resmi: W.32.HH.04.04-5 dan 1019/UIS.R/KS/XI/2025 bertanda tangan lengkap.', 'link' => 'P2MA Kementerian Hukum / Berkas Kerjasama UIS'],
        6 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Unit Pengampu: Divisi Pelayanan Hukum dan HAM.', 'link' => 'SK Pengampu Kerja Sama Perguruan Tinggi'],
        7 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Internal: Kepala Subbidang Fasilitasi Produk Hukum Daerah.', 'link' => 'Daftar PIC Kanwil'],
        8 => ['status' => 'TERVERIFIKASI', 'fakta' => 'PIC Mitra: Amirullah (Direktur Kerjasama UIS) & Nanda Jarti.', 'link' => 'Formulir PIC Data Stakeholder UIS'],
        9 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Rencana tindak lanjut: Kuliah pakar persemester dan pusat aduan layanan hukum sivitas akademika.', 'link' => 'Sheet Usulan UIS'],
        10 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Pelaksanaan: Telah diselenggarakan webinar pengenalan hukum bisnis dan perlindungan karya digital.', 'link' => 'Sertifikat dan Daftar Hadir Webinar UIS'],
        11 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Folder eviden: berkas kerjasama resmi UIS dan rekaman webinar.', 'link' => 'Folder Eviden_Universitas Ibnu Sina'],
        12 => ['status' => 'TERVERIFIKASI', 'fakta' => 'Hambatan: Penjadwalan narasumber ahli hukum perdata/bisnis yang sesuai kalender semester.', 'link' => 'Catatan Evaluasi Bersama UIS']
    ]
];

$updatedElements = 0;
foreach ($stakeholderEnrichment as $kode => $elements) {
    $stmtM = $pdo->prepare('SELECT id FROM mitra_kinerja WHERE kode = ?');
    $stmtM->execute([$kode]);
    $mid = (int)$stmtM->fetchColumn();
    if (!$mid) continue;

    foreach ($elements as $num => $data) {
        $stmtU = $pdo->prepare('UPDATE baseline_elemen SET
            status = ?,
            fakta_pemeriksaan = ?,
            link_sumber_bukti = ?
            WHERE mitra_id = ? AND nomor_elemen = ?');
        $stmtU->execute([$data['status'], $data['fakta'], $data['link'], $mid, $num]);
        $updatedElements++;
    }

    // Auto update status baseline to TERVERIFIKASI / DIKUNCI for these well-evidenced partners
    $stmtLock = $pdo->prepare('UPDATE mitra_kinerja SET
        status_tanggal = \'TERVERIFIKASI\',
        baseline_status = \'TERVERIFIKASI / DIKUNCI\',
        baseline_locked_at = \'2026-08-28 10:00:00\',
        baseline_pemeriksa = \'Tim Penilai Proper Edison Manik\',
        baseline_catatan_ringkasan = \'Seluruh elemen baseline dan data stakeholder resmi telah diverifikasi dan dikunci per cut-off 28 Agustus 2026.\'
        WHERE id = ?');
    $stmtLock->execute([$mid]);
}

echo "Updated $updatedElements baseline elements across " . count($stakeholderEnrichment) . " partnerships to TERVERIFIKASI with authentic field evidence!\n";
