# Aplikasi Scorecard Efektivitas Mitra Kinerja

Aplikasi web (PHP + MySQL) untuk evaluasi terstruktur, pengisian form penilaian, dan dashboard eksekutif
Scorecard Efektivitas Mitra Kinerja — Kantor Wilayah Kementerian Hukum Kepulauan Riau.

Dibangun berdasarkan metodologi evaluasi kerja sama terpadu:
- Arsitektur **V2.1 / V3 Result-Chain** (7 Indikator Kinerja Utama)
- **Baseline 12 Elemen** & Ledger Bukti Dukung
- Matriks Penilaian Portofolio & Kuadran Kinerja
- **Gate 0 (Pra-PKS) Feasibility Screening** (18 Parameter Kelayakan)
- *Format Scorecard Efektivitas MITRA KINERJA V1.1 / V2.1 / V3*
- *Panduan Pengisian Scorecard MITRA KINERJA Kanwil Kemenkumham Kepri*

## 1. Menjalankan Aplikasi

### Cara Cepat (Windows / Jaringan WLAN / Wi-Fi)
Cukup **klik dua kali** (double-click) file:
```cmd
start_server_wlan.bat
```
Script ini secara otomatis akan:
1. Memeriksa ketersediaan PHP dan memastikan database MySQL (XAMPP) aktif pada port 3306.
2. Mendeteksi IP Address Wi-Fi / WLAN komputer (dengan filter cerdas membuang adapter virtual seperti vEthernet, VirtualBox, WSL, dan CGNAT/Tailscale 100.*).
3. Melakukan pemeriksaan port otomatis (menguji port `8080`, lalu `8085`, kemudian port bebas berikutnya jika port terpakai).
4. Membuka browser default ke halaman login dan menampilkan URL akses untuk perangkat lain (HP / tablet / laptop) dalam jaringan Wi-Fi yang sama (misal: `http://192.168.1.x:8080/login.php`).

---

## 2. Instalasi & Basis Data Manual

Aplikasi ini **aman dijalankan di domain root atau di subfolder mana pun** (contoh:
`/home/USER/public_html/`) tanpa mengubah kode — semua link internal pakai
path relatif, dan koneksi database diatur lewat file konfigurasi terpisah yang
tidak ikut dipindah otomatis saat naik ke production. Lihat bagian 1b untuk detail.

### Opsi A: 1-Klik Import Database Lengkap (Direkomendasikan)
Cukup import file dump lengkap (sudah mencakup skema seluruh tabel V2.2, 18 naskah, rencana kerja, siklus monev, dan baseline):
```bash
mysql -u root -p < database/mitra_kinerja_dump.sql
```
*(Atau buka phpMyAdmin, buat/pilih database `mitra_kinerja`, lalu import `database/mitra_kinerja_dump.sql`).*

### Opsi B: Import Skema & Seed Terpisah
1. Buat database dan struktur tabel:
   ```bash
   mysql -u root -p < database/schema.sql
   ```
2. Isi data awal:
   ```bash
   mysql -u root -p mitra_kinerja < database/seed_data.sql
   ```

*Catatan: Jika lupa mengimpor database, aplikasi memiliki mesin **auto-migration mandiri** (`includes/auto_migrate.php`) yang otomatis membuat tabel dan kolom yang belum ada saat aplikasi dibuka.*
3. Upload seluruh isi folder ini ke document root/subfolder tujuan (mis. `public_html/`).
4. Atur kredensial database — **jangan edit `config/database.php` langsung**, ikuti 1b.
5. Login pertama kali:
   - Username: `admin`
   - Password: `admin123`
   - **Segera ganti password ini** (lewat menu Pengguna → buat admin baru, lalu nonaktifkan akun default).

### 1b. Konfigurasi per-environment (dev vs production) — TANPA mengubah kode

Supaya folder yang sama bisa dipakai di dev dan production dengan kredensial berbeda
tanpa risiko lupa mengganti sebelum go-live:

1. Di **setiap server** (dev maupun production), salin:
   ```
   cp config/database.local.php.example config/database.local.php
   ```
   lalu isi kredensial database server itu di `config/database.local.php`. File ini
   TIDAK ada di paket asli/git — jadi aman beda isi antar server, dan tidak akan
   tertimpa saat kode di-update/di-deploy ulang (asalkan file ini tidak ikut disalin).
2. **Hanya di server dev**, tambahan salin:
   ```
   cp config/env.local.php.example config/env.local.php
   ```
   Ini mengaktifkan tampilan error PHP detail untuk debugging. **JANGAN dibuat di
   production** — defaultnya (tanpa file ini) sudah aman: error disembunyikan dari
   pengunjung dan hanya dicatat di error log server.
3. Kalau hosting mendukung Environment Variables (banyak panel cPanel modern punya
   ini), bisa juga set `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_ENV` di sana
   sebagai alternatif langkah 1-2 — aplikasi otomatis membacanya.

### 1c. Checklist pindah dev → production

- [ ] `config/database.local.php` di production sudah pakai user MySQL khusus
      (bukan root) dengan privilege terbatas ke database ini saja.
- [ ] `config/env.local.php` **tidak ada** di production (pastikan tidak ikut ter-upload).
- [ ] Password admin default (`admin123`) sudah diganti.
- [ ] HTTPS aktif — kalau sudah, aktifkan blok redirect HTTPS di `.htaccess` (lihat
      komentar di file itu).
- [ ] Cek folder `config/`, `includes/`, `database/` tidak bisa diakses langsung lewat
      browser (harus 403) — sudah otomatis diblokir lewat `.htaccess` di masing-masing
      folder, tinggal pastikan hosting mengizinkan `.htaccess` (`AllowOverride All`).
- [ ] Backup database sebelum import ulang seed data apa pun.

## 2. Struktur Role Pengguna (6 Role)

Sistem menerapkan Role-Based Access Control (RBAC) ketat dengan 6 tingkatan hak akses:

| Role | Hak Akses & Tanggung Jawab |
|---|---|
| **admin** | Akses penuh seluruh modul sistem, manajemen pengguna, konfigurasi lingkungan, serta mesin import/export workbook Excel. |
| **pemeriksa** | Pengisian identitas naskah (8-field header), penilaian 7 Indikator Result-Chain, EWS 4 area kontrol, instrumen uji intervensi, serta Gate 0 screening. |
| **validator** | Penelaahan komprehensif, validasi bukti dukung, dan penetapan status scorecard (*Disetujui* / *Perlu Perbaikan* / *Final*). |
| **pimpinan** | Pemantauan dashboard eksekutif, analisis ringkasan portofolio, penetapan keputusan Gate 0, dan pengesahan intervensi strategis. |
| **pengampu** | Unit Pengampu Teknis: pemantauan operasional bidang, pengelolaan ledger data baseline 12 elemen, dan koordinasi rencana tindak lanjut. |
| **pic** | PIC Operasional Kerja Sama: pelaksanaan kegiatan harian, pemantauan masa berlaku, pelaporan berkala, dan penyiapan eviden mitra. |

### Akun Demo (Tersedia Tombol Cepat di Layar Login):
- **Admin**: `admin` / `admin123`
- **Pemeriksa**: `pemeriksa` / `pemeriksa123`
- **Validator**: `validator` / `validator123`
- **Pimpinan**: `pimpinan` / `pimpinan123`
- **Pengampu**: `pengampu` / `pengampu123`
- **PIC Kerja Sama**: `pic` / `pic123`

## 3. Struktur Modul & Navigasi Aplikasi

```
├── config/
│   ├── database.php              Koneksi PDO terisolasi & fallback environment
│   ├── database.local.php.example Template kredensial per-server
│   ├── env.php                   Konfigurasi error handling & cookie session aman
│   └── .htaccess                 Proteksi akses langsung direktori konfigurasi (403 Forbidden)
├── includes/
│   ├── auth.php                  Manajemen sesi, login guard, proteksi CSRF, audit logger
│   ├── functions.php             Logika bisnis inti Result-Chain V3, rumus nilai, EWS, Uji Intervensi
│   ├── data.php                  Abstraksi data & query agregasi KPI
│   ├── ai_service.php            Layanan AI Insight terintegrasi (Gemini, OpenRouter, Groq)
│   ├── auto_migrate.php          Skrip migrasi mandiri skema database
│   └── header.php / footer.php   Komponen layout antarmuka bersama & navigasi responsif
├── public/
│   ├── css/style.css             Sistem desain antarmuka responsif (desktop, tablet, mobile <480px)
│   └── js/                       Skrip interaktivitas klien & Chart.js UMD bundle
├── database/
│   ├── schema.sql                Definisi struktur tabel DDL
│   ├── seed_data.sql             Data benih 18 naskah mitra & indikator
│   └── mitra_kinerja_dump.sql    Snapshot lengkap basis data produksi
├── scripts/
│   ├── live_test_suite_v2_4_2.py Suite pengujian komprehensif end-to-end
│   ├── make_backup.py            Utilitas backup arsip otomatis & rotasi retensi 5 backup
│   └── ...                       Skrip rekonsiliasi data dan audit paritas
├── dashboard.php                 Dashboard eksekutif (North Star KPI, grafik distribusi, AI Insight)
├── scorecard.php                 Matriks tabel Scorecard Result-Chain V3 (I1–I7) & aksi cepat
├── baseline.php                  Ledger evaluasi 12 Elemen Baseline & penguncian data
├── portofolio.php                Matriks pemetaan kuadran kinerja & filter multi-dimensi
├── gate0.php                     Modul Gate 0 (Pra-PKS) Feasibility Screening (18 aspek kelayakan)
├── mitra_list.php                Daftar 18 naskah mitra kerja sama (13 Pilot Utama + 5 Cadangan)
├── mitra_edit.php                Form evaluasi komprehensif naskah mitra & input eviden
├── mitra_validasi.php            Panel penelaahan & pengesahan validasi naskah
├── import.php                    Mesin import mandiri berkas Excel (.xlsx) Baseline & Scorecard
├── laporan.php / tindak_lanjut.php Rekomendasi strategis, agenda monev & cetak laporan formal
├── users_list.php                Manajemen pengguna & otorisasi RBAC (Admin)
├── ai_insight.php / ai_debug.php Endpoint AI Executive Summary & diagnostic utility
└── start_server_wlan.bat         Launcher otomatis server lokal & jaringan nirkabel
```

Semua tautan internal (menu, redirect login/logout, referensi CSS) memakai **path relatif** (tanpa garis miring di depan), sehingga otomatis bekerja dengan sempurna baik dipasang di domain root maupun di subfolder web server.

## 4. Logika Bisnis Kunci & Arsitektur Result-Chain (V2.1 / V3)

Seluruh formula dan aturan bisnis dikonversi langsung dari instrumen evaluasi resmi dan ditegakkan di sisi server (PHP):

### A. 7 Indikator Result-Chain (I1–I7) & Pembobotan (Total 100%)
1. **I1: Relevansi & Keselarasan Tugas Fungsi** (Bobot 10%)
2. **I2: Kualitas Perencanaan & Rencana Kerja** (Bobot 15%)
3. **I3: Realisasi & Implementasi Kegiatan** (Bobot 15%)
4. **I4: Capaian Output & Deliverables** (Bobot 20%)
5. **I5: Kemanfaatan & Dampak/Outcome** (Bobot 20%)
6. **I6: Ketersediaan & Ketertelusuran Bukti/Eviden** (Bobot 10%)
7. **I7: Manajemen Risiko, Keberlanjutan & Evaluasi Monev** (Bobot 10%)

### B. Formula Penilaian & Konversi Skor
- **Status Pemeriksaan**: `DAPAT DINILAI` (atau `BUKTI CUKUP`), `BUKTI BELUM MEMADAI` (atau `BUKTI BELUM CUKUP`), `BELUM DAPAT DINILAI`, `BELUM DITELAAH`.
- **Aturan Skor**: Skor berkisar antara **0 sampai 4**, dan hanya sah dihitung apabila status pemeriksaan bernilai `DAPAT DINILAI` / `BUKTI CUKUP`.
- **Nilai Indikator**: Dihitung dengan rumus: `Nilai = (Skor / 4) * Bobot`.
- **Nilai Final Total**: Akumulasi nilai dari seluruh indikator I1–I7 (skala 0.0 – 100.0). Hanya dihitung jika seluruh 7 indikator lengkap dan berstatus DAPAT DINILAI.
- **Kelengkapan Pengisian**: Persentase indikator yang telah terisi skor terhadap indikator yang dapat dinilai.

### C. Klasifikasi Kategori Kinerja
Nilai final diklasifikasikan ke dalam 4 kategori kinerja Result-Chain V3:
- **KUAT** : 80.0 – 100.0
- **CUKUP / PERLU PENGUATAN** : 60.0 – <80.0
- **PERLU PERBAIKAN** : 40.0 – <60.0
- **KRITIS** : <40.0
- *(Belum lengkap seluruh 7 indikator: **DALAM PROSES**)*
*(Didukung juga backward compatibility pemetaan kategori V1.1: PRODUKTIF, BERJALAN, PERLU AKTIVASI).*

### D. Early Warning System (EWS) 4 Area Kontrol
1. **Masa Berlaku & Ketepatan Waktu**: Dihitung 100% otomatis dari selisih tanggal berakhir terhadap cut-off.
   - <= 30 hari / Kedaluwarsa → **E3** (Kritis)
   - <= 90 hari → **E2** (Peringatan Tinggi)
   - <= 180 hari → **E1** (Perhatian)
   - > 180 hari → **E0** (Aman)
   - Belum terverifikasi → **V0**
2. **Pelaksanaan Aktivitas & Rencana Kerja** (E0–E3 diturunkan dari kondisi riil).
3. **Kelengkapan Data & Bukti Dukung** (E0–E3 diturunkan dari ketersediaan dokumen).
4. **Komitmen & Peran PIC/Focal Point** (E0–E3 diturunkan dari keaktifan koordinasi).

Tingkat eskalasi penanganan warning tertinggi:
- `E2` / `E3`: *Perlu Koordinasi Project Leader / Pimpinan*
- `V0`: *Lengkapi Validasi Data Legalitas*
- `E0` / `E1`: *Ditangani Mandiri oleh PIC / Unit Pengampu*

### E. Gate 0 (Pra-PKS) Feasibility Screening
Instrumen uji kelayakan pra-penandatanganan kerja sama mencakup 18 pertanyaan strategis yang terbagi dalam 4 dimensi:
1. Relevansi dan Dasar Kewenangan
2. Kebutuhan Nyata dan Kejelasan Manfaat
3. Profil, Kapasitas, dan Komitmen Mitra
4. Kesiapan Operasional, Tata Kelola, dan Keberlanjutan

---

## 5. Fitur Keamanan, Import/Export & Pelaporan

1. **Proteksi CSRF Aktif**:
   Seluruh formulir interaktif (Login, Edit Mitra, Validasi, Import Excel, Gate 0) dilindungi secara ketat menggunakan sinkronisasi token anti-CSRF per-sesi (`csrf_token`).
2. **Mesin Import Excel Terintegrasi (`import.php`)**:
   - Mendukung format workbook `.xlsx` untuk sinkronisasi Baseline 12 Elemen dan Scorecard I1–I7 secara instan.
   - Dilengkapi validasi ekstensi, MIME type checking, boundary sanitization, dan pencatatan audit log otomatis.
3. **Pelaporan Eksekutif & Format Cetak Standar**:
   - Tampilan ramah cetak dokumen resmi (`@media print` clean layout berstandar A4 dengan kop instansi).
   - Matriks rekomendasi tindak lanjut dan pengelompokan portofolio (`tindak_lanjut.php` & `laporan.php`).
4. **AI Executive Insight Multi-Provider**:
   - Dashboard dilengkapi ringkasan analitik cerdas yang mendukung failover multi-provider: Google Gemini → OpenRouter → Groq.
   - Dilengkapi caching lokal, sanitasi output anti-XSS, dan halaman diagnostik konektivitas (`ai_debug.php`).
5. **Utilitas Pencadangan & Retensi Otomatis (`scripts/make_backup.py`)**:
   - Menghasilkan arsip `.zip` proyek lengkap termasuk dump basis data MySQL.
   - Pengecualian berkas media besar dari direktori `docs/` (`.mp4`, `.mov`, `.avi`, `.wmv`, `.pdf`) untuk menghemat kapasitas penyimpanan (dari 235 MB menjadi ~14 MB).
   - Rotasi otomatis: mempertahankan 5 cadangan terbaru dan memangkas arsip lama.

---

## 6. Verifikasi & Pengujian Sistem

Sistem dilengkapi suite pengujian otomatis Python untuk memvalidasi integritas aplikasi secara end-to-end:
```bash
python scripts/live_test_suite_v2_4_2.py
```
Suite ini memverifikasi:
- Jalur autentikasi, cookie sesi, dan validasi token CSRF.
- Ketahanan terhadap manipulasi tipe data POST array pada PHP 8.
- Ketercukupan 18 naskah mitra, integritas KPI dashboard, dan proteksi XSS pada AI Insight.
- Paritas kolom Scorecard Result-Chain V3 dan penguncian baseline 12 elemen.
- Responsivitas halaman Gate 0, Portofolio, dan form evaluasi mitra.
