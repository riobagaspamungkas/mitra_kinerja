<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied: CLI only.');
}
require_once dirname(__DIR__) . '/config/database.php';
$pdo = getDB();

$baseline12Definitions = [
    1 => [
        'kelompok' => 'IDENTITAS',
        'nama_elemen' => 'Identitas naskah',
        'yang_diperiksa' => 'Jenis, seluruh nomor para pihak, judul, dan nama resmi mitra sesuai naskah.',
        'sumber_bukti_minimum' => 'Naskah bertanda tangan; P2MA sebagai pembanding.'
    ],
    2 => [
        'kelompok' => 'MASA BERLAKU',
        'nama_elemen' => 'Masa berlaku',
        'yang_diperiksa' => 'Tanggal efektif, durasi, dan tanggal berakhir sesuai klausul naskah.',
        'sumber_bukti_minimum' => 'Klausul jangka waktu; halaman tanda tangan; P2MA.'
    ],
    3 => [
        'kelompok' => 'SUBSTANSI',
        'nama_elemen' => 'Ruang lingkup',
        'yang_diperiksa' => 'Ruang kerja, kewajiban, atau kegiatan utama yang disepakati.',
        'sumber_bukti_minimum' => 'Pasal ruang lingkup/hak-kewajiban; lampiran.'
    ],
    4 => [
        'kelompok' => 'TATA KELOLA',
        'nama_elemen' => 'Status arsip',
        'yang_diperiksa' => 'Ketersediaan naskah lengkap pada lokasi arsip resmi dan dapat ditemukan kembali.',
        'sumber_bukti_minimum' => 'Arsip resmi; register; folder organisasi.'
    ],
    5 => [
        'kelompok' => 'TATA KELOLA',
        'nama_elemen' => 'Status P2MA',
        'yang_diperiksa' => 'Keberadaan entri dan kesesuaian metadata P2MA dengan naskah resmi.',
        'sumber_bukti_minimum' => 'P2MA dan naskah bertanda tangan.'
    ],
    6 => [
        'kelompok' => 'PENGAMPU',
        'nama_elemen' => 'Unit pengampu',
        'yang_diperiksa' => 'Unit internal yang bertanggung jawab atas substansi dan implementasi kerja sama.',
        'sumber_bukti_minimum' => 'ND/SK/pembagian tugas; konfirmasi tertulis unit.'
    ],
    7 => [
        'kelompok' => 'PIC',
        'nama_elemen' => 'PIC internal',
        'yang_diperiksa' => 'PIC utama dan cadangan yang aktif, lengkap dengan jabatan, kontak, dan dasar penetapan.',
        'sumber_bukti_minimum' => 'ND/SK/daftar PIC; konfirmasi tertulis unit.'
    ],
    8 => [
        'kelompok' => 'PIC',
        'nama_elemen' => 'PIC mitra',
        'yang_diperiksa' => 'Penghubung operasional pihak mitra yang telah dikonfirmasi.',
        'sumber_bukti_minimum' => 'Surat/email/form konfirmasi resmi dari mitra.'
    ],
    9 => [
        'kelompok' => 'TINDAK LANJUT',
        'nama_elemen' => 'Rencana tindak lanjut',
        'yang_diperiksa' => 'Dokumen atau komitmen operasional yang memuat kegiatan, periode, target, dan/atau PIC.',
        'sumber_bukti_minimum' => 'Rencana aksi; matriks kerja; kalender; notula.'
    ],
    10 => [
        'kelompok' => 'PELAKSANAAN',
        'nama_elemen' => 'Pelaksanaan dan hasil',
        'yang_diperiksa' => 'Kegiatan aktual, realisasi terhadap target jatuh tempo, serta output yang dihasilkan.',
        'sumber_bukti_minimum' => 'Laporan; undangan; notula; daftar hadir; data hasil.'
    ],
    11 => [
        'kelompok' => 'EVIDEN',
        'nama_elemen' => 'Eviden implementasi',
        'yang_diperiksa' => 'Bukti pelaksanaan/output, lokasi penyimpanan, dan tingkat keteraturannya.',
        'sumber_bukti_minimum' => 'Folder resmi; indeks bukti; dokumen/data kegiatan.'
    ],
    12 => [
        'kelompok' => 'HAMBATAN',
        'nama_elemen' => 'Hambatan/gap',
        'yang_diperiksa' => 'Kendala faktual atau kekosongan data yang memengaruhi implementasi dan sudah dikonfirmasi.',
        'sumber_bukti_minimum' => 'Konfirmasi unit/PIC/mitra; notula; laporan; bukti keterlambatan.'
    ]
];

// Seed for P04 (exact from Scorecard_P04_BAPPERIDA_BINTAN.xlsx)
$p04Data = [
    1 => [
        'status' => 'TERVERIFIKASI',
        'fakta' => 'Jenis, mitra, judul, nomor narahubung serta halaman tanda tangan dapat dicocokkan dengan hasil pencarian/naskah P2MA.',
        'link' => 'https://p2ma.kemenkum.go.id/uploads/kerjasama/naskah/1785760267.pdf'
    ],
    2 => [
        'status' => 'TERVERIFIKASI',
        'fakta' => 'P2MA publik menampilkan status Berlangsung, mulai 30 Juni 2026 dan berakhir 29 Juni 2029; tanggal sesuai data awal.',
        'link' => 'https://p2ma.kemenkum.go.id/kdn/pencarian?tipe=1&keyword=Badan%20Perencanaan%20Pembangunan%2C%20Riset%2C%20dan%20Inovasi%20Daerah%20Kabupaten%20Bintan'
    ],
    3 => [
        'status' => 'TERVERIFIKASI',
        'fakta' => 'Ruang lingkup meliputi sosialisasi dan bimbingan teknis KI; peningkatan kapasitas SDM; fasilitasi pendaftaran KI; pembentukan Sentra KI.',
        'link' => 'https://p2ma.kemenkum.go.id/uploads/kerjasama/naskah/1785760267.pdf'
    ],
    4 => [
        'status' => 'TERVERIFIKASI',
        'fakta' => 'Salinan naskah dapat dibuka melalui P2MA. Lokasi arsip resmi internal berada di Bagian Tata Usaha dan Umum Kantor Wilayah.',
        'link' => 'Naskah P2MA pada baris 5; arsip internal di Subbagian Tata Usaha'
    ],
    5 => [
        'status' => 'TERVERIFIKASI',
        'fakta' => 'Entri publik P2MA ditemukan dengan status Berlangsung dan tautan naskah PDF resmi dapat dibuka.',
        'link' => 'https://p2ma.kemenkum.go.id/kdn/pencarian?tipe=1&keyword=BAPPERIDA'
    ],
    6 => [
        'status' => 'BELUM TERSEDIA',
        'fakta' => 'Naskah dan P2MA publik belum cukup untuk menetapkan unit internal yang bertanggung jawab atas implementasi kerja sama.',
        'link' => 'ND, SK pembagian tugas, atau konfirmasi unit belum diperoleh.'
    ],
    7 => [
        'status' => 'BELUM TERSEDIA',
        'fakta' => 'PIC internal utama dan cadangan belum dapat dipastikan dari sumber publik yang diperiksa.',
        'link' => 'Daftar PIC, ND/SK, dan konfirmasi tertulis unit belum diperoleh.'
    ],
    8 => [
        'status' => 'BELUM TERSEDIA',
        'fakta' => 'PIC operasional pihak mitra belum dikonfirmasi melalui surat, email resmi, atau formulir penunjukan.',
        'link' => 'Surat/email/formulir penunjukan PIC mitra belum diperoleh.'
    ],
    9 => [
        'status' => 'BELUM TERSEDIA',
        'fakta' => 'Ruang lingkup naskah tersedia, tetapi belum ditemukan rencana aksi atau komitmen operasional yang memuat kegiatan, periode, target, dan PIC.',
        'link' => 'Rencana aksi, matriks kerja, kalender, atau notula belum diperoleh.'
    ],
    10 => [
        'status' => 'BELUM TERSEDIA',
        'fakta' => 'Status Berlangsung pada P2MA belum membuktikan kegiatan atau output. Laporan pelaksanaan dan data hasil belum diperiksa.',
        'link' => 'Laporan, undangan, notula, daftar hadir, dan data hasil belum diperoleh.'
    ],
    11 => [
        'status' => 'BELUM TERSEDIA',
        'fakta' => 'Naskah dan entri P2MA tersedia, tetapi folder resmi, indeks, serta bukti implementasi belum diperiksa.',
        'link' => 'Folder eviden, indeks bukti, dan dokumen/data kegiatan belum diperoleh.'
    ],
    12 => [
        'status' => 'BELUM TERSEDIA',
        'fakta' => 'Hambatan operasional belum dikonfirmasi. Gap verifikasi sementara mencakup arsip internal, unit pengampu, PIC, rencana tindak lanjut, pelaksanaan, dan eviden.',
        'link' => 'Penelusuran publik P2MA; konfirmasi unit/PIC/mitra belum dilakukan.'
    ]
];

$allMitra = $pdo->query("SELECT id, kode, nama_mitra, jenis, baseline_status FROM mitra_kinerja WHERE baseline_status != 'TERVERIFIKASI / DIKUNCI'")->fetchAll();

foreach ($allMitra as $m) {
    $mid = (int)$m['id'];
    $isP04 = ($m['kode'] === 'P04');

    foreach ($baseline12Definitions as $num => $def) {
        $status = 'BELUM TERVERIFIKASI';
        $fakta = 'Telah dilakukan pengecekan awal dokumen identitas dan naskah.';
        $link = 'P2MA / Berkas Arsip';

        if ($isP04 && isset($p04Data[$num])) {
            $status = $p04Data[$num]['status'];
            $fakta  = $p04Data[$num]['fakta'];
            $link   = $p04Data[$num]['link'];
        }

        $stmt = $pdo->prepare('INSERT INTO baseline_elemen (
            mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa, sumber_bukti_minimum,
            status, fakta_pemeriksaan, link_sumber_bukti
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            fakta_pemeriksaan = VALUES(fakta_pemeriksaan),
            status = VALUES(status),
            link_sumber_bukti = VALUES(link_sumber_bukti)');
        $stmt->execute([
            $mid, $num, $def['kelompok'], $def['nama_elemen'], $def['yang_diperiksa'], $def['sumber_bukti_minimum'],
            $status, $fakta, $link
        ]);
    }

    // Update baseline_status on mitra_kinerja
    $bStatus = $isP04 ? 'TERVERIFIKASI / DIKUNCI' : 'DALAM PROSES';
    $lockedAt = $isP04 ? '2026-08-28 10:00:00' : null;
    $pemeriksa = $isP04 ? 'Tim Penilai Proper Edison Manik' : 'Pemeriksa Kerja Sama';
    $catatan = $isP04 ? 'Baseline FIX P04 BAPPERIDA Bintan telah dikunci sesuai dokumen resmi per cut-off 28 Agustus 2026.' : 'Verifikasi kondisi awal 12 elemen sedang berjalan.';

    $stmtUp = $pdo->prepare('UPDATE mitra_kinerja SET
        baseline_status = ?,
        baseline_locked_at = ?,
        baseline_pemeriksa = ?,
        baseline_catatan_ringkasan = ?
        WHERE id = ?');
    $stmtUp->execute([$bStatus, $lockedAt, $pemeriksa, $catatan, $mid]);
}

echo "Successfully initialized 12 Baseline FIX elements for all " . count($allMitra) . " partnerships.\n";
