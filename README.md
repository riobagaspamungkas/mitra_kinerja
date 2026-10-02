# Aplikasi Scorecard Efektivitas Mitra Kinerja

Aplikasi web (PHP + MySQL) untuk pengisian form penilaian dan dashboard eksekutif
Scorecard Efektivitas Mitra Kinerja — Kantor Wilayah Kementerian Hukum Kepulauan Riau.

Dibangun berdasarkan:
- `Format_Scorecard_Efektivitas_MITRA_KINERJA_V1_1_29_Agustus_2026.xlsx`
- `Panduan_Pengisian_Scorecard_MITRA_KINERJA_V1_1.docx`

## 1. Menjalankan Aplikasi

### Cara Cepat (Windows / Jaringan WLAN / Wi-Fi)
Cukup **klik dua kali** (double click) file:
```
start_server_wlan.bat
```
Script ini akan:
1. Memeriksa PHP dan memastikan database MySQL (XAMPP) menyala.
2. Mendeteksi IP Address Wi-Fi / WLAN komputer Anda secara otomatis.
3. Menjalankan web server pada port `8080`.
4. Membuka browser secara otomatis dan menampilkan tautan yang bisa dibuka dari HP atau laptop lain yang terhubung di Wi-Fi yang sama (contoh: `http://172.16.x.x:8080/login.php`).

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

## 2. Struktur Role

| Role       | Akses                                                              |
|------------|---------------------------------------------------------------------|
| admin      | Semua akses + kelola pengguna                                      |
| pemeriksa  | Mengisi form penilaian (identitas, 6 indikator, warning, uji intervensi) |
| validator  | Meninjau & memvalidasi (Disetujui / Perlu Perbaikan)               |
| pimpinan   | Dashboard eksekutif & detail naskah — mode baca saja                |

Buat akun pemeriksa/validator/pimpinan lewat menu **Pengguna** (login sebagai admin).

## 3. Struktur Aplikasi

```
config/database.php              Koneksi PDO (baca dari database.local.php / env var)
config/database.local.php.example  Contoh kredensial per-server (salin, jangan commit)
config/env.php                    APP_ENV, error display, keamanan cookie sesi
config/env.local.php.example      Aktifkan mode dev (salin HANYA di server dev)
config/.htaccess                  Blokir akses browser ke folder ini
includes/auth.php         Session, login, role guard, audit log
includes/functions.php    Logika bisnis inti (Cek, Kategori, Status, Warning tertinggi, Uji Intervensi)
includes/data.php         Helper gabungan DB + logika bisnis
includes/header.php/footer.php   Layout bersama
includes/.htaccess         Blokir akses browser ke folder ini

login.php / logout.php    Autentikasi
dashboard.php              Dashboard eksekutif (KPI, distribusi kategori, filter, drill-down)
mitra_list.php             Daftar 15 naskah (untuk pemeriksa/validator)
mitra_edit.php             Form pengisian scorecard per naskah
mitra_validasi.php         Panel keputusan validasi
users_list.php             Manajemen pengguna (admin)

database/schema.sql        Struktur tabel
database/seed_data.sql     Data awal 15 naskah (dari file xlsx yang diunggah)
database/.htaccess         Blokir akses browser ke folder ini (berisi hash password!)

.htaccess                   Hardening dasar folder aplikasi (aman di root maupun subfolder)
```

Semua link internal (menu, redirect login/logout, referensi CSS) memakai **path
relatif** (tanpa garis miring di depan), jadi otomatis bekerja baik dipasang di
domain root maupun di subfolder seperti `/` — sudah diuji langsung dengan
Apache di kedua skenario.

## 4. Logika Bisnis Kunci (lihat `includes/functions.php`)

**Semua rumus di bawah diterjemahkan LANGSUNG dari formula Excel asli** (dibaca dengan
`data_only=False` dari file xlsx, bukan diterka dari nilai cache-nya), lalu diverifikasi
dengan unit test dan uji end-to-end di server sungguhan. Semua ditegakkan di server (PHP),
bukan hanya di tampilan:

- **Cek per indikator**: urutan pemeriksaan PERSIS rumus `J13` — skor kosong dicek **paling
  duluan** (→ `BELUM DIISI`), baru status BELUM DIPERIKSA (→ `PERIKSA BUKTI`), status
  BUKTI BELUM CUKUP/BELUM DAPAT DINILAI dengan skor terlanjur ada (→ `HAPUS SKOR`), temuan
  kosong (→ `TULIS TEMUAN/BUKTI`), alasan kosong (→ `TULIS ALASAN`), baru `OK`.
- **Skor 0–4** hanya tersimpan jika Status Pemeriksaan = `BUKTI CUKUP`.
- **Kelengkapan** = jumlah indikator yang skornya **terisi** ÷ 6 (bukan jumlah yang
  Cek-nya OK — dua ukuran ini beda dan dipakai untuk gerbang yang berbeda).
- **Kategori/Status Scorecard** dua gerbang bertingkat:
  1. Skor belum 6/6 terisi → `BELUM LENGKAP`
  2. Skor 6/6 tapi Cek belum semua `OK` → `BELUM FINAL` (kategori) / `PERLU DILENGKAPI` (status)
  3. Cek 6/6 `OK` → kategori dari nilai (`PRODUKTIF` 75–100, `BERJALAN` 50–<75,
     `PERLU AKTIVASI` 25–<50, `KRITIS` <25); status → `SIAP DIVALIDASI` /
     `FINAL/TERVALIDASI` / `PERLU PERBAIKAN` mengikuti keputusan validator.
- **Early warning — Masa berlaku 100% OTOMATIS**, tidak diisi manual sama sekali:
  dihitung dari `tanggal berakhir − cut-off` (Sisa Hari), hanya berlaku jika Status
  Tanggal = TERVERIFIKASI. Ambang: ≤30 hari atau sudah berakhir → `E3`, ≤90 → `E2`,
  ≤180 → `E1`, lebih dari itu → `E0`; jika Status Tanggal belum terverifikasi → `V0`.
- **Early warning — 3 dimensi lain** (Aktivitas/tenggat, Data/eviden, PIC): yang diisi
  manual adalah **Kondisi** (pilihan tetap per dimensi, lihat `KONDISI_OPTIONS`), Status
  (E0/E1/E3) **diturunkan otomatis** dari Kondisi itu — bukan dipilih langsung.
- **Warning tertinggi**: urutan keparahan **E3 > E2 > E1 > V0 > E0**. Tingkat penanganan:
  `E2`/`E3` → *Perlu Koordinasi Project Leader*, `V0` → *Lengkapi Data*, `E0`/`E1` →
  *Ditangani PIC/Unit*.
- **Uji Kebutuhan Intervensi Pimpinan** bertingkat: ada pemicu `YA` → `CALON BUTUH
  INTERVENSI PIMPINAN`; ada yang `BELUM DIPASTIKAN` → `LENGKAPI UJI INTERVENSI`; kalau
  semua `TIDAK`, hasil ikut status warning tertinggi. **Cek usulan** lebih detail lagi:
  `LENGKAPI BUKTI PEMICU` (ada YA tanpa bukti) → `LENGKAPI UPAYA DAN KEPUTUSAN` (bukti
  ada, upaya/keputusan kosong) → `SIAP DIBAWA KE FORUM` (semua lengkap).

## 5. Yang Masih Perlu Diputuskan / Dikembangkan

- Import ulang data historis dari cut-off berikutnya (belum ada fitur upload xlsx
  di aplikasi — saat ini hanya via `seed_data.sql`).
- Notifikasi/reminder otomatis untuk tenggat early warning.
- Export laporan (PDF/xlsx) dari dashboard.
- Riwayat perubahan per field (saat ini audit log hanya mencatat aksi level naskah).
- Hardening keamanan produksi: CSRF token pada form, rate limiting login, HTTPS wajib.
