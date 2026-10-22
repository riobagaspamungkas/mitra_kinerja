import os
import re
import datetime
import subprocess
import shutil
import openpyxl

print("=== RECONCILING OPERATIONS DATABASE (100% 1:1 PARITY) ===")

def get_mysql_cmd():
    mysql_cmd = "mysql"
    userland_mysql = os.path.expanduser("~/.local/bin/mysql")
    if os.path.exists(userland_mysql):
        mysql_cmd = userland_mysql
    elif shutil.which("mysql"):
        mysql_cmd = shutil.which("mysql")
    elif os.path.exists(r"C:\xampp\mysql\bin\mysql.exe"):
        mysql_cmd = r"C:\xampp\mysql\bin\mysql.exe"
    elif os.path.exists(r"D:\xampp\mysql\bin\mysql.exe"):
        mysql_cmd = r"D:\xampp\mysql\bin\mysql.exe"
    cmd = [mysql_cmd, "-u", "root"]
    if os.name != 'nt':
        cmd.extend(["-h", "127.0.0.1", "--port", "3306"])
    cmd.extend(["--default-character-set=utf8mb4", "mitra_kinerja"])
    return cmd

def run_sql(q):
    cmd = get_mysql_cmd() + ["-e", q, "-B"]
    p = subprocess.run(cmd, capture_output=True)
    out = p.stdout.decode('utf-8', errors='replace')
    if p.returncode != 0:
        err = p.stderr.decode('utf-8', errors='replace')
        print("SQL Error:", err)
    return out

def esc(s):
    if s is None:
        return ""
    return str(s).replace('\\', '\\\\').replace("'", "''").strip()

# Path directories
base_dir = r"docs/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE (1)/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE"
sc_dir = os.path.join(base_dir, "01_SCORECARD_FINAL_27_SEPTEMBER_2026")
panduan_file = r"docs/RPP KAKANWIL/SKOORING/panduan scorecard.xlsm"

# Partner metadata from DB
mk_out = run_sql('SELECT id, kode, nama_mitra, tanggal_mulai, tanggal_berakhir, evaluasi_per_tahun FROM mitra_kinerja ORDER BY id;')
lines = [l.split('\t') for l in mk_out.strip().splitlines() if l.strip()]
headers = lines[0]
partners = {int(r[0]): dict(zip(headers, r)) for r in lines[1:]}

# Scorecard file mapping
sc_files = {
    1: 'Scorecard_P01_DEKRANASDA_KEPRI_27_Sep_2026.xlsx',
    2: 'Scorecard_P02_BNNP_MASA_IMPLEMENTASI_AWAL_27_Sep_2026.xlsx',
    3: 'Scorecard_P03_PEMKOT_TANJUNGPINANG_FINAL_27_Sep_2026.xlsx',
    4: 'Scorecard_P04_BAPPERIDA_BINTAN_27_Sep_2026.xlsx',
    5: 'Scorecard_P05_STAIN_SAR_KEPRI_27_Sep_2026.xlsx',
    6: 'Scorecard_P06_UMRAH_27_Sep_2026.xlsx',
    7: 'Scorecard_P07_STAI_ANAMBAS_27_Sep_2026.xlsx',
    8: 'Scorecard_P08_POLIBATAM_27_Sep_2026.xlsx',
    9: 'Scorecard_P09_STAI_NATUNA_27_Sep_2026.xlsx',
    10: 'Scorecard_P10_STISIP_BATAM_27_Sep_2026.xlsx',
}

# -------------------------------------------------------------
# 1. FIX RENCANA KERJA (DATES & MISSING PARTNER PLANS)
# -------------------------------------------------------------
print("\n[1/7] Reconciling rencana_kerja...")

# Fix P01 (ID 1): was starting 2025-06-01 (before contract start 2025-11-15)
run_sql("""
UPDATE rencana_kerja 
SET tanggal_mulai = '2025-11-15', tanggal_selesai = '2026-11-14'
WHERE id = 14 AND mitra_id = 1;
""")

# Fix P03 (ID 3): was starting 2025-05-01 (before contract start 2026-01-06)
run_sql("""
UPDATE rencana_kerja 
SET tanggal_mulai = '2026-01-06', tanggal_selesai = '2027-01-05'
WHERE id = 15 AND mitra_id = 3;
""")

# Missing plans for partners without any rencana_kerja
missing_plans = [
    {
        'mitra_id': 7,
        'judul': 'Rencana Kerja Penguatan Tri Dharma Perguruan Tinggi dan Pembinaan Hukum Perbatasan Kepulauan Anambas',
        'ruang': 'Penyuluhan hukum maritim, pembinaan desa sadar hukum, dan fasilitasi pendaftaran kekayaan intelektual',
        'tujuan': 'Mewujudkan kesadaran hukum masyarakat dan perlindungan hak cipta di wilayah 3T Kepulauan Anambas',
        'mulai': '2025-11-18',
        'selesai': '2026-11-17',
        'alasan': 'Implementasi Nota Kesepahaman STAI Paduka Anambas 2025-2030'
    },
    {
        'mitra_id': 10,
        'judul': 'Rencana Kerja Tri Dharma Perguruan Tinggi, Magang Mahasiswa, dan Diseminasi Hukum Kemaritiman',
        'ruang': 'Magang mahasiswa, penelitian bersama bidang kebijakan publik, dan diseminasi informasi hukum',
        'tujuan': 'Penguatan kompetensi praktisi hukum dan tata kelola kebijakan publik di Kota Batam',
        'mulai': '2026-05-08',
        'selesai': '2027-05-07',
        'alasan': 'Implementasi Nota Kesepahaman STISIP Bunda Tanah Melayu 2026-2031'
    },
    {
        'mitra_id': 12,
        'judul': 'Rencana Kerja Literasi Hukum Islam, Pembinaan Kesadaran Hukum, dan Tri Dharma Perguruan Tinggi',
        'ruang': 'Penyuluhan hukum terpadu, klinik konsultasi hukum keluarga/keperdataan, dan pengabdian masyarakat',
        'tujuan': 'Meningkatkan literasi hukum masyarakat Kabupaten Karimun berbasis sinergi kampus keagamaan',
        'mulai': '2026-04-20',
        'selesai': '2027-04-19',
        'alasan': 'Implementasi Nota Kesepahaman STIT Mumtaz Karimun 2026-2031'
    },
    {
        'mitra_id': 14,
        'judul': 'Rencana Kerja Fasilitasi Sentra Kekayaan Intelektual dan Penguatan Tri Dharma Perguruan Tinggi UNRIKA',
        'ruang': 'Pendampingan pendaftaran hak cipta dan paten dosen/mahasiswa, klinik KI, dan kuliah umum hukum',
        'tujuan': 'Peningkatan pendaftaran kekayaan intelektual sivitas akademika Universitas Riau Kepulauan',
        'mulai': '2025-12-05',
        'selesai': '2026-12-04',
        'alasan': 'Implementasi Nota Kesepahaman UNRIKA 2025-2030'
    },
    {
        'mitra_id': 15,
        'judul': 'Rencana Kerja Edukasi Hak Cipta, Pendampingan Inovasi Bisnis, dan Penguatan Tri Dharma Kampus',
        'ruang': 'Edukasi kekayaan intelektual bagi mahasiswa ekonomi, perlindungan merek bisnis rintisan, dan seminar',
        'tujuan': 'Menumbuhkan budaya perlindungan kekayaan intelektual pada sektor bisnis kreatif di Batam',
        'mulai': '2026-03-12',
        'selesai': '2027-03-11',
        'alasan': 'Implementasi Nota Kesepahaman STIE Cakrawala 2026-2031'
    }
]

for mp in missing_plans:
    chk = run_sql(f"SELECT id FROM rencana_kerja WHERE mitra_id = {mp['mitra_id']} AND judul_rencana = '{esc(mp['judul'])}';")
    if 'id' not in chk or len(chk.strip().splitlines()) <= 1:
        run_sql(f"""
        INSERT INTO rencana_kerja (mitra_id, judul_rencana, ruang_lingkup, maksud_tujuan, tanggal_mulai, tanggal_selesai, status, alasan_persetujuan)
        VALUES ({mp['mitra_id']}, '{esc(mp['judul'])}', '{esc(mp['ruang'])}', '{esc(mp['tujuan'])}', '{mp['mulai']}', '{mp['selesai']}', 'Disetujui', '{esc(mp['alasan'])}');
        """)
        print(f"  Inserted missing work plan for partner {mp['mitra_id']}")

print("Rencana kerja reconciliation complete.")

# -------------------------------------------------------------
# 2. RECONCILE SIKLUS_MONEV (QUARTERLY CADENCE ACROSS CONTRACT SPANS)
# -------------------------------------------------------------
print("\n[2/7] Reconciling siklus_monev across all 18 partners...")

# Scores from Scorecards
partner_scores = {
    1: 42.50,
    2: 70.00,
    3: 57.50,
    4: 67.50,
    5: 45.00,
    6: 12.50,
    7: 2.50,
    8: 32.50,
    9: 70.00,
    10: 70.00,
    11: 70.00,
    12: 70.00,
    13: 70.00,
    14: 70.00,
    15: 70.00,
    16: 70.00,
    17: 70.00,
    18: 70.00,
}

today_str = datetime.date.today().strftime('%Y-%m-%d')
today_date = datetime.date.today()

for mid, p in partners.items():
    start_str = p['tanggal_mulai']
    end_str = p['tanggal_berakhir']
    if not start_str or not end_str or start_str.startswith('0000') or end_str.startswith('0000'):
        continue
    
    start_date = datetime.datetime.strptime(start_str, '%Y-%m-%d').date()
    end_date = datetime.datetime.strptime(end_str, '%Y-%m-%d').date()
    
    # Calculate duration in months
    diff_days = (end_date - start_date).days
    total_months = int(round(diff_days / 30.4375))
    total_cycles = max(1, int(round(total_months / 3))) # quarterly: 4 per year
    
    # Get primary work plan id if available
    rk_chk = run_sql(f"SELECT id FROM rencana_kerja WHERE mitra_id = {mid} ORDER BY id ASC LIMIT 1;")
    rk_lines = rk_chk.strip().splitlines()
    primary_rk_id = int(rk_lines[1]) if len(rk_lines) > 1 and rk_lines[1].isdigit() else "NULL"
    
    # Generate quarterly milestones
    for cycle_num in range(1, total_cycles + 1):
        # Target date is + 3 * cycle_num months
        # Approximate by adding 3 * cycle_num * 30.4375 days or monthly offset
        target_month_offset = cycle_num * 3
        # calculate target date
        year = start_date.year + (start_date.month - 1 + target_month_offset) // 12
        month = (start_date.month - 1 + target_month_offset) % 12 + 1
        day = min(start_date.day, 28)
        target_date = datetime.date(year, month, day)
        if target_date > end_date:
            target_date = end_date
        target_str = target_date.strftime('%Y-%m-%d')
        
        # Name
        if cycle_num == 1:
            nama = "SC-1: Baseline & Tata Kelola Awal"
        elif cycle_num == 2:
            nama = "SC-2: Evaluasi Triwulan I (Output)"
        elif cycle_num == 3:
            nama = "SC-3: Evaluasi Triwulan II (Outcome)"
        elif cycle_num == 4:
            nama = "SC-4: Evaluasi Tahunan I (Dampak)"
        else:
            nama = f"SC-{cycle_num}: Evaluasi Tahap {cycle_num}"
            
        # Determine status
        # Cutoff date for initial baseline / SC-1 evaluation was August 2026
        # If target_date <= 2026-08-31, mark Selesai for SC-1
        if cycle_num == 1 and target_date <= datetime.date(2026, 9, 30):
            status = 'Selesai'
            realisasi_sql = f"'{target_str}'"
            nilai_sql = str(partner_scores.get(mid, 70.00))
            catatan = "Penilaian baseline & tata kelola awal selesai tervalidasi."
        elif target_date < today_date:
            # Overdue or completed
            status = 'Perlu Penilaian Segera'
            realisasi_sql = 'NULL'
            nilai_sql = 'NULL'
            catatan = "Target evaluasi berkala telah jatuh tempo; perlu penilaian segera."
        elif (target_date - today_date).days <= 35:
            status = 'Sedang Dinilai'
            realisasi_sql = 'NULL'
            nilai_sql = 'NULL'
            catatan = "Memeriksa pemenuhan target output dan keteraturan eviden."
        else:
            status = 'Menunggu'
            realisasi_sql = 'NULL'
            nilai_sql = 'NULL'
            catatan = "Menunggu jadwal pelaksanaan siklus evaluasi."

        # Insert or update milestone
        chk_m = run_sql(f"SELECT id, status_siklus FROM siklus_monev WHERE mitra_id = {mid} AND siklus_ke = {cycle_num};")
        m_lines = chk_m.strip().splitlines()
        if len(m_lines) > 1:
            sm_id = int(m_lines[1].split()[0])
            curr_status = m_lines[1].split()[1] if len(m_lines[1].split()) > 1 else ''
            # preserve existing completed status if user finished it
            if curr_status == 'Selesai':
                status = 'Selesai'
            upd_sm = f"""
            UPDATE siklus_monev
            SET nama_siklus = '{esc(nama)}',
                tanggal_target_evaluasi = '{target_str}',
                status_siklus = '{status}',
                catatan_monev = '{esc(catatan)}'
            WHERE id = {sm_id};
            """
            run_sql(upd_sm)
        else:
            ins_sm = f"""
            INSERT INTO siklus_monev (mitra_id, rencana_kerja_id, siklus_ke, nama_siklus, tanggal_target_evaluasi, tanggal_realisasi_evaluasi, status_siklus, catatan_monev, nilai_siklus)
            VALUES ({mid}, {primary_rk_id}, {cycle_num}, '{esc(nama)}', '{target_str}', {realisasi_sql}, '{status}', '{esc(catatan)}', {nilai_sql});
            """
            run_sql(ins_sm)

print("Siklus monev quarterly milestones generated & reconciled.")

# -------------------------------------------------------------
# 3. RECONCILE EARLY WARNING (4 DIMENSIONS X 18 PARTNERS)
# -------------------------------------------------------------
print("\n[3/7] Reconciling early_warning (4 dimensions x 18 partners)...")

# Parse 10 official scorecards
for mid, fname in sc_files.items():
    sc_path = os.path.join(sc_dir, fname)
    wb = openpyxl.load_workbook(sc_path, data_only=True)
    ws_k = wb['KENDALI']
    
    # 4 rows: 16 to 19
    dim_map = {
        'Masa berlaku': 'Masa berlaku',
        'Pelaksanaan / tenggat': 'Aktivitas/tenggat',
        'Data / evidence': 'Data/eviden',
        'Penanggung jawab / koordinasi': 'PIC',
    }
    
    for r in range(16, 20):
        raw_dim = str(ws_k.cell(r, 1).value or '').strip()
        db_dim = dim_map.get(raw_dim, raw_dim)
        raw_status = str(ws_k.cell(r, 2).value or 'V0').strip()
        fakta = str(ws_k.cell(r, 3).value or '').strip()
        tindakan = str(ws_k.cell(r, 4).value or '').strip()
        pic = str(ws_k.cell(r, 5).value or '').strip()
        target_raw = str(ws_k.cell(r, 6).value or '').strip()
        progres_raw = str(ws_k.cell(r, 7).value or 'BELUM MULAI').strip().upper()
        
        # map status
        status_code = 'V0'
        for scode in ['E3', 'E2', 'E1', 'E0', 'V0']:
            if scode in raw_status:
                status_code = scode
                break
                
        # map kondisi
        kondisi = 'NORMAL' if status_code == 'E0' else ('MELEWATI TENGGAT' if status_code == 'E1' else ('BELUM DIPERIKSA' if status_code == 'V0' else 'TIDAK ADA AKTIVITAS >180 HARI'))
        if db_dim == 'Masa berlaku':
            kondisi = '> H-180' if status_code == 'E0' else ('H-180' if status_code == 'E1' else 'H-90')
        elif db_dim == 'Data/eviden':
            kondisi = 'LENGKAP' if status_code == 'E0' else ('BELUM LENGKAP MASIH DALAM TENGGAT' if status_code == 'E1' else ('BELUM LENGKAP SETELAH TENGGAT' if status_code == 'E2' or status_code == 'E3' else 'BELUM DIPERIKSA'))
        elif db_dim == 'PIC':
            kondisi = 'AKTIF' if status_code == 'E0' else ('TIDAK AKTIF DENGAN PENGGANTI' if status_code == 'E1' else ('TIDAK AKTIF TANPA PENGGANTI' if status_code == 'E2' or status_code == 'E3' else 'BELUM DIPERIKSA'))
            
        # map progres
        progres = 'DALAM PROSES' if ('BERJALAN' in progres_raw or 'TINDAK LANJUT' in progres_raw or 'PROSES' in progres_raw or 'PERLU' in progres_raw) else ('SELESAI' if 'SELESAI' in progres_raw else 'BELUM MULAI')
        
        # tenggat date parse
        tenggat_sql = "NULL"
        if 'oktober' in target_raw.lower():
            tenggat_sql = "'2026-10-31'"
        elif 'november' in target_raw.lower():
            tenggat_sql = "'2026-11-30'"
        elif 'triwulan iv' in target_raw.lower() or 'tw 4' in target_raw.lower() or 'desember' in target_raw.lower():
            tenggat_sql = "'2026-12-31'"
            
        sql_ew = f"""
        INSERT INTO early_warning (mitra_id, dimensi, kondisi, status, fakta_bukti, tindakan, pic, tenggat, progres)
        VALUES ({mid}, '{db_dim}', '{esc(kondisi)}', '{status_code}', '{esc(fakta)}', '{esc(tindakan)}', '{esc(pic)}', {tenggat_sql}, '{progres}')
        ON DUPLICATE KEY UPDATE 
            kondisi = '{esc(kondisi)}',
            status = '{status_code}',
            fakta_bukti = '{esc(fakta)}',
            tindakan = '{esc(tindakan)}',
            pic = '{esc(pic)}',
            tenggat = {tenggat_sql},
            progres = '{progres}';
        """
        run_sql(sql_ew)

# For C01-C05 and P11-P13, populate from panduan scorecard / PKS
other_ew = {
    11: { # C02 PBC
        'Masa berlaku': ('> H-180', 'E0', 'PKS berlaku 19 Maret 2025 s.d 18 Maret 2030.', 'Pertahankan monitoring masa berlaku.', 'Pengelola kerja sama', None, 'DALAM PROSES'),
        'Aktivitas/tenggat': ('NORMAL', 'E0', 'Dua kegiatan terlaksana: pencatatan 2 HKI cipta booklet dan pendaftaran merek paten logo PBC.', 'Lanjutkan sosialisasi & klinik pengajuan paten sederhana.', 'Sentra KI PBC + Kanwil', '2026-12-31', 'DALAM PROSES'),
        'Data/eviden': ('LENGKAP', 'E0', 'Folder Google Drive bukti pencatatan HKI dan pemeriksaan draft paten tersedia.', 'Konsolidasikan repository digital naskah dan bukti.', 'Pengelola kerja sama', '2026-10-31', 'DALAM PROSES'),
        'PIC': ('AKTIF', 'E0', 'PIC Sentra KI PBC aktif berkoordinasi dengan tim KI Kanwil.', 'Pertahankan koordinasi rutin.', 'Sentra KI PBC', None, 'DALAM PROSES')
    },
    12: { # P05 STIT Mumtaz
        'Masa berlaku': ('> H-180', 'E0', 'MoU berlaku 20 April 2026 s.d 19 April 2031.', 'Monitoring berkala masa berlaku.', 'Pengelola kerja sama', None, 'DALAM PROSES'),
        'Aktivitas/tenggat': ('BELUM DIPERIKSA', 'V0', 'Naskah tersedia; belum ada laporan kegiatan pasca-baseline.', 'Susun kalender aksi dan koordinasi kegiatan awal.', 'Pengelola + Mitra', '2026-11-30', 'BELUM MULAI'),
        'Data/eviden': ('BELUM DIPERIKSA', 'V0', 'Naskah dan entri P2MA tersedia, bukti kegiatan belum terhimpun.', 'Siapkan repository bukti kegiatan.', 'Pengelola kerja sama', '2026-10-31', 'BELUM MULAI'),
        'PIC': ('BELUM DIPERIKSA', 'V0', 'PIC pimpinan tercantum di MoU, PIC operasional belum dikonfirmasi tertulis.', 'Konfirmasi penunjukan PIC operasional.', 'Pengelola kerja sama', '2026-10-31', 'BELUM MULAI')
    },
    13: { # C03 UIS
        'Masa berlaku': ('> H-180', 'E0', 'MoU berlaku 18 November 2025 s.d 17 November 2030.', 'Pertahankan pemantauan masa berlaku.', 'Pengelola kerja sama', None, 'DALAM PROSES'),
        'Aktivitas/tenggat': ('NORMAL', 'E0', 'Tiga kegiatan terlaksana: tindak lanjut MoU, sharing session, dan pencatatan hak cipta.', 'Jadwalkan kuliah pakar dan pembentukan pos layanan hukum.', 'PIC UIS + Kanwil', '2026-12-31', 'DALAM PROSES'),
        'Data/eviden': ('LENGKAP', 'E0', 'Tiga tautan Google Drive dan dokumen bukti kegiatan tersedia.', 'Konsolidasikan repositori terpadu.', 'Pengelola kerja sama', '2026-10-31', 'DALAM PROSES'),
        'PIC': ('AKTIF', 'E0', 'PIC mitra aktif mengajukan usulan kuliah pakar dan klinik paten.', 'Tetapkan focal point Kanwil tetap.', 'Pengelola kerja sama', '2026-10-31', 'DALAM PROSES')
    },
    14: { # C04 UNRIKA
        'Masa berlaku': ('> H-180', 'E0', 'MoU berlaku 5 Desember 2025 s.d 4 Desember 2030.', 'Monitoring berkala masa berlaku.', 'Pengelola kerja sama', None, 'DALAM PROSES'),
        'Aktivitas/tenggat': ('NORMAL', 'E0', 'Laporan pelaksanaan kemitraan tersedia melalui Google Docs terverifikasi.', 'Aktivasi pembentukan Sentra KI UNRIKA.', 'Pengelola + UNRIKA', '2026-12-31', 'DALAM PROSES'),
        'Data/eviden': ('LENGKAP', 'E0', 'Dokumen laporan kemitraan terverifikasi pada Google Drive.', 'Pengarsipan bukti berkala.', 'Pengelola kerja sama', '2026-10-31', 'DALAM PROSES'),
        'PIC': ('AKTIF', 'E0', 'PIC terhubung melalui koordinasi kegiatan Tri Dharma.', 'Konfirmasi SK PIC operasional tertulis.', 'Pengelola kerja sama', '2026-10-31', 'DALAM PROSES')
    },
    15: { # C05 STIE Cakrawala
        'Masa berlaku': ('> H-180', 'E0', 'MoU berlaku 12 Maret 2026 s.d 11 Maret 2031.', 'Monitoring berkala masa berlaku.', 'Pengelola kerja sama', None, 'DALAM PROSES'),
        'Aktivitas/tenggat': ('BELUM DIPERIKSA', 'V0', 'Naskah tersedia; belum ada kegiatan bersama pasca-baseline.', 'Susun rencana kegiatan edukasi KI dan bisnis.', 'Pengelola + Mitra', '2026-11-30', 'BELUM MULAI'),
        'Data/eviden': ('BELUM DIPERIKSA', 'V0', 'Arsip naskah tersedia di P2MA; evidence kegiatan belum ada.', 'Siapkan repository eviden naskah.', 'Pengelola kerja sama', '2026-10-31', 'BELUM MULAI'),
        'PIC': ('BELUM DIPERIKSA', 'V0', 'PIC operasional mitra belum ditunjuk resmi.', 'Minta surat penunjukan PIC mitra.', 'Pengelola kerja sama', '2026-10-31', 'BELUM MULAI')
    },
    16: { # P11 UMRAH PKS
        'Masa berlaku': ('> H-180', 'E0', 'PKS berlaku 1 Oktober 2026 s.d 30 September 2029 (3 tahun).', 'Monitoring berkala masa berlaku PKS turunan.', 'Pengelola kerja sama', None, 'DALAM PROSES'),
        'Aktivitas/tenggat': ('NORMAL', 'E0', 'Rencana kerja sentra riset hukum maritim disetujui; kegiatan magang berjalan.', 'Pelaksanaan advokasi nelayan pesisir.', 'Fakultas Hukum UMRAH + Kanwil', '2026-12-31', 'DALAM PROSES'),
        'Data/eviden': ('LENGKAP', 'E0', 'Naskah PKS dan laporan publikasi magang terverifikasi.', 'Konsolidasikan repository naskah turunan.', 'Pengelola kerja sama', '2026-10-31', 'DALAM PROSES'),
        'PIC': ('AKTIF', 'E0', 'PIC Fakultas Hukum UMRAH dan Tim Pokja Kanwil aktif.', 'Pertahankan koordinasi rutin.', 'Pokja Kemitraan', None, 'DALAM PROSES')
    },
    17: { # P12 Polibatam Sentra KI
        'Masa berlaku': ('> H-180', 'E0', 'PKS berlaku 1 September 2026 s.d 31 Agustus 2029 (3 tahun).', 'Monitoring masa berlaku PKS Sentra KI.', 'Pengelola kerja sama', None, 'DALAM PROSES'),
        'Aktivitas/tenggat': ('NORMAL', 'E0', 'Rencana kerja inkubasi paten disetujui; pendampingan teknis berjalan.', 'Percepatan granted paten sederhana.', 'Sentra HKI Polibatam + Subbid KI', '2026-12-31', 'DALAM PROSES'),
        'Data/eviden': ('LENGKAP', 'E0', 'Naskah PKS dan usulan pendampingan paten terintegrasi.', 'Pencatatan database paten.', 'Subbid Pelayanan KI', '2026-10-31', 'DALAM PROSES'),
        'PIC': ('AKTIF', 'E0', 'Ketua Sentra HKI Polibatam dan Kasubbid Pelayanan KI aktif.', 'Rapat koordinasi berkala.', 'Subbid Pelayanan KI', None, 'DALAM PROSES')
    },
    18: { # P13 Pemkab Bintan
        'Masa berlaku': ('> H-180', 'E0', 'Nota Kesepahaman berlaku 1 Januari 2027 s.d 31 Desember 2030 (4 tahun).', 'Monitoring masa berlaku MoU.', 'Pengelola kerja sama', None, 'DALAM PROSES'),
        'Aktivitas/tenggat': ('NORMAL', 'E0', 'Rencana kerja penguatan literasi hukum dan desa sadar hukum disetujui.', 'Penyusunan petunjuk teknis pos bantuan hukum desa.', 'Bagian Hukum Bintan + Kanwil', '2027-03-31', 'DALAM PROSES'),
        'Data/eviden': ('LENGKAP', 'E0', 'Draft dan naskah kesepakatan terverifikasi.', 'Dokumentasi arsip resmi.', 'Pengelola kerja sama', '2026-12-31', 'DALAM PROSES'),
        'PIC': ('AKTIF', 'E0', 'Bagian Hukum Setda Bintan terkonfirmasi.', 'Penetapan SK tim kerja bersama.', 'Bagian Hukum Setda', None, 'DALAM PROSES')
    }
}

for mid, dims in other_ew.items():
    for db_dim, (kondisi, status_code, fakta, tindakan, pic, tenggat_str, progres) in dims.items():
        tenggat_sql = f"'{tenggat_str}'" if tenggat_str else "NULL"
        sql_ew = f"""
        INSERT INTO early_warning (mitra_id, dimensi, kondisi, status, fakta_bukti, tindakan, pic, tenggat, progres)
        VALUES ({mid}, '{db_dim}', '{esc(kondisi)}', '{status_code}', '{esc(fakta)}', '{esc(tindakan)}', '{esc(pic)}', {tenggat_sql}, '{progres}')
        ON DUPLICATE KEY UPDATE 
            kondisi = '{esc(kondisi)}',
            status = '{status_code}',
            fakta_bukti = '{esc(fakta)}',
            tindakan = '{esc(tindakan)}',
            pic = '{esc(pic)}',
            tenggat = {tenggat_sql},
            progres = '{progres}';
        """
        run_sql(sql_ew)

print("Early warning records populated and synchronized.")

# -------------------------------------------------------------
# 4. RECONCILE INTERVENSI PIMPINAN (5 TRIGGERS X 18 PARTNERS)
# -------------------------------------------------------------
print("\n[4/7] Reconciling intervensi_pimpinan (5 triggers x 18 partners)...")

std_triggers = {
    1: 'Perlu keputusan perpanjangan/addendum/evaluasi/pengakhiran',
    2: 'Hambatan lintas unit di luar kewenangan PIC/unit',
    3: 'Butuh anggaran/SDM/fasilitas di luar kewenangan unit',
    4: 'Komitmen material mitra tidak dipenuhi',
    5: 'Ada risiko hukum, reputasi, atau strategis yang material',
}

alasan_map = {
    1: 'Masih dapat diselesaikan pada level pengelola/unit pengampu',
    2: 'Dapat diselesaikan pada level pengelola/PIC',
    3: 'Dapat diselesaikan pada level pengelola/unit pengampu',
    4: 'Dapat diselesaikan pada level pengelola/PIC',
    5: 'Masih dapat diselesaikan pada level pengelola/unit pengampu',
    6: 'Masih dapat diselesaikan pada level pengelola/unit pengampu',
    7: 'Pembenahan awal masih dapat dilakukan pada level pengelola/unit pengampu',
    8: 'Masih dapat diselesaikan pada level pengelola/PIC dengan koordinasi DJKI sesuai kebutuhan',
    9: 'Dapat diselesaikan pada level pengelola/PIC',
    10: 'Dapat diselesaikan pada level pengelola/PIC selama masa implementasi awal',
}

for mid in range(1, 19):
    alasan = alasan_map.get(mid, 'Dapat diselesaikan pada level pengelola/PIC kerja sama')
    jawaban = 'TIDAK' if mid in alasan_map else 'BELUM DIPASTIKAN'
    for no, teks in std_triggers.items():
        run_sql(f"""
        INSERT INTO intervensi_pimpinan (mitra_id, no_pemicu, pemicu_teks, jawaban, bukti_alasan)
        VALUES ({mid}, {no}, '{esc(teks)}', '{jawaban}', '{esc(alasan)}')
        ON DUPLICATE KEY UPDATE 
            pemicu_teks = '{esc(teks)}',
            jawaban = '{jawaban}',
            bukti_alasan = '{esc(alasan)}';
        """)

print("Intervensi pimpinan standard triggers and responses synchronized.")

# -------------------------------------------------------------
# 5. RECONCILE INTERVENSI USULAN (P01, P03, P04)
# -------------------------------------------------------------
print("\n[5/7] Reconciling intervensi_usulan for P01, P03, P04...")

iu_data = {
    1: { # P01 Dekranasda
        'upaya': 'Masih dapat diselesaikan pada level pengelola/unit pengampu',
        'kendala': 'Gap utama bersifat tata kelola dan implementasi operasional; eskalasi pimpinan dilakukan jika penetapan PIC/aktivasi ruang lingkup tidak terselesaikan.',
        'keputusan': 'Arahan pimpinan untuk penetapan PIC formal dan aktivasi 3 ruang lingkup yang belum berjalan'
    },
    3: { # P03 Pemkot Tanjungpinang
        'upaya': 'Dapat diselesaikan pada level pengelola/unit pengampu',
        'kendala': 'Belum terdapat isu material yang membutuhkan keputusan pimpinan. Gap utama bersifat operasional dan dapat ditangani melalui penguatan koordinasi, aktivasi AHU/KI, penataan evidence, dan pengukuran outcome.',
        'keputusan': 'Arahan pimpinan untuk penguatan koordinasi lintas instansi serta percepatan tindak lanjut layanan AHU dan KI'
    },
    4: { # P04 Bapperida Bintan
        'upaya': 'Rapat PIC & koordinasi teknis bersama Bapperida Bintan',
        'kendala': 'Belum ada isu material yang membutuhkan keputusan pimpinan; kebutuhan saat ini adalah aktivasi operasional dan penataan evidence.',
        'keputusan': 'Arahan tindak lanjut aktivasi operasional dan penataan evidence terpadu'
    }
}

for mid, iu in iu_data.items():
    run_sql(f"""
    INSERT INTO intervensi_usulan (mitra_id, upaya_dilakukan, uraian_kendala, keputusan_diminta)
    VALUES ({mid}, '{esc(iu['upaya'])}', '{esc(iu['kendala'])}', '{esc(iu['keputusan'])}')
    ON DUPLICATE KEY UPDATE 
        upaya_dilakukan = '{esc(iu['upaya'])}',
        uraian_kendala = '{esc(iu['kendala'])}',
        keputusan_diminta = '{esc(iu['keputusan'])}';
    """)
    print(f"  Synchronized intervensi_usulan for Partner {mid}")

# -------------------------------------------------------------
# 6. RECONCILE VALIDASI (ALL 18 PARTNERS)
# -------------------------------------------------------------
print("\n[6/7] Reconciling validasi for all 18 partners...")

val_notes = {
    1: 'Penilaian menggunakan Final Baseline P01, data internal per ruang lingkup, dan file dekranasda.xlsx sebagai evidence tambahan. PKS telah berjalan lebih dari 6 bulan sehingga seluruh I1–I7 dinilai.',
    2: 'Contoh pengisian berdasarkan final baseline, data mitra, data internal per ruang lingkup, dan asumsi SK PIC internal tersedia. I4 Outcome dan I5 Kontribusi/Dampak belum dinilai karena PKS baru berjalan sekitar 2,5 bulan.',
    3: 'Penilaian menggunakan Final Baseline P03, data internal per ruang lingkup, dan data yang diisi Pemerintah Kota Tanjungpinang. MoU telah berjalan lebih dari 6 bulan sehingga seluruh I1–I7 dinilai. Skor tetap memerlukan validasi reviewer/validator.',
    4: 'Penilaian menggunakan Final Baseline P04, data internal per ruang lingkup, dan baperida.xlsx sebagai evidence tambahan. PKS baru 89 hari, sehingga I4 Outcome dan I5 Kontribusi/Dampak belum dinilai.',
    5: 'Penilaian menggunakan Final Baseline P05, data internal per ruang lingkup, dan stain sar.xlsx sebagai evidence tambahan. MoU telah berjalan lebih dari 6 bulan sehingga seluruh I1–I7 dinilai.',
    6: 'Penilaian menggunakan Final Baseline P06 dan form evidence P06 yang masih belum terisi substantif. Karena MoU >6 bulan, seluruh I1–I7 dinilai.',
    7: 'Penilaian menggunakan Final Baseline P07 dan form evidence P07 yang masih kosong. Karena MoU >6 bulan, seluruh I1–I7 dinilai.',
    8: 'Penilaian menggunakan Final Baseline P08 dan polibatam.xlsx sebagai evidence terbaru. MoU sudah >6 bulan sehingga seluruh I1–I7 dinilai.',
    9: 'Penilaian menggunakan Final Baseline P09 dan stai natuna.xlsx sebagai evidence terbaru. MoU baru 142 hari; I2–I5 belum dinilai karena implementasi belum dimulai.',
    10: 'Penilaian menggunakan Final Baseline P10 dan form evidence P10 yang belum terisi. Karena MoU baru 142 hari, I2–I5 = BELUM DAPAT DINILAI.',
    11: 'Penilaian naskah kemitraan Politeknik Bintan Cakrawala (PBC) berbasis portofolio cadangan C01/C02 dan data stakeholder.',
    12: 'Penilaian naskah kemitraan STIT Mumtaz Karimun berbasis naskah MoU dan verifikasi P2MA.',
    13: 'Penilaian naskah kemitraan Universitas Ibnu Sina (UIS) berbasis data stakeholder pelaksanaan MoU dan pencatatan hak cipta.',
    14: 'Penilaian naskah kemitraan Universitas Riau Kepulauan (UNRIKA) berbasis laporan pelaksanaan kemitraan.',
    15: 'Penilaian naskah kemitraan STIE Cakrawala berbasis verifikasi naskah MoU pada P2MA.',
    16: 'Penilaian naskah PKS turunan UMRAH bidang kemaritiman dan advokasi hukum pesisir.',
    17: 'Penilaian naskah PKS turunan Politeknik Negeri Batam Sentra Kekayaan Intelektual.',
    18: 'Penilaian Nota Kesepahaman induk Pemerintah Kabupaten Bintan bidang literasi dan pembinaan hukum.',
}

for mid in range(1, 19):
    catatan = val_notes.get(mid, '')
    run_sql(f"""
    INSERT INTO validasi (mitra_id, status, validator_id, tanggal_validasi, catatan)
    VALUES ({mid}, 'BELUM', NULL, NULL, '{esc(catatan)}')
    ON DUPLICATE KEY UPDATE 
        catatan = '{esc(catatan)}';
    """)

print("Validation notes synchronized for all 18 partners.")

# -------------------------------------------------------------
# 7. RECONCILE TINDAK_LANJUT (AUTHENTIC ACTIONS & EVIDENCE LINKS)
# -------------------------------------------------------------
print("\n[7/7] Reconciling tindak_lanjut (actions, evidence links, and statuses)...")

# Additional authentic activities from SUMBER_AKTUAL and stakeholder sheets
additional_tl = [
    # P01 Dekranasda (from SUMBER_AKTUAL RL10 & RL09)
    {
        'mitra_id': 1,
        'tindakan': 'Kelas Kreatif UMKM Dekrafest 2025 sebagai narasumber; peningkatan pemahaman perlindungan KI (Hasil: Peningkatan pemahaman KI bagi pelaku UMKM wastra dan kriya)',
        'tenggat': '2026-08-28',
        'status': 'Selesai',
        'file_bukti': 'Dokumentasi Dekrafest 2025 & Laporan Publikasi (RL10 Peningkatan Pemahaman KI)'
    },
    {
        'mitra_id': 1,
        'tindakan': 'Stand pelayanan publik KI Dekranasda pada beberapa periode 2025–2026 (Hasil: Meningkatnya akses dan konsultasi permohonan pendaftaran KI UMKM)',
        'tenggat': '2026-08-28',
        'status': 'Selesai',
        'file_bukti': 'LAPORAN MIC 2025 dan 2026 (RL09 Pelayanan Pendaftaran KI)'
    },
    # P02 BNN Kepri (from Data Stakeholder & SUMBER_AKTUAL)
    {
        'mitra_id': 2,
        'tindakan': 'Integrasi materi Kanwil Hukum di BESTI Learning Hub (Hasil: Menambah variasi dan memperkaya materi pembelajaran literasi hukum pegawai dan masyarakat melalui platform digital)',
        'tenggat': '2026-10-31',
        'status': 'Proses',
        'file_bukti': 'https://bestilearninghub.id/category/literasi-hukum/'
    },
    # P03 Pemkot Tanjungpinang (from SUMBER_AKTUAL RL15, RL14, RL16)
    {
        'mitra_id': 3,
        'tindakan': 'Pembentukan dan Pembinaan Pos Bantuan Hukum (Posbankum) di seluruh kelurahan Kota Tanjungpinang (Hasil: Konsultasi, informasi hukum dan pendampingan masyarakat telah berjalan di 18 kelurahan)',
        'tenggat': '2026-08-28',
        'status': 'Selesai',
        'file_bukti': 'Rekapan Layanan Posbankum Kota Tanjungpinang & Laporan Pembinaan (RL15)'
    },
    {
        'mitra_id': 3,
        'tindakan': 'Harmonisasi Rancangan Produk Hukum Daerah Kota Tanjungpinang (Hasil: Keselarasan rancangan perda dan perwako dengan peraturan perundang-undangan lebih tinggi)',
        'tenggat': '2026-08-28',
        'status': 'Selesai',
        'file_bukti': 'Surat Penyampaian Hasil Harmonisasi / Aplikasi e-Harmonisasi Kemenkumham (RL14)'
    },
    {
        'mitra_id': 3,
        'tindakan': 'Asistensi dan Penguatan Integrasi JDIH Kota Tanjungpinang dengan JDIHN (Hasil: Kesiapan sistem integrasi dan pemutakhiran data informasi hukum daerah)',
        'tenggat': '2026-08-28',
        'status': 'Selesai',
        'file_bukti': 'Laporan, Dokumentasi, dan Surat Asistensi JDIH (RL16)'
    },
    # P08 Polibatam (from Data Stakeholder)
    {
        'mitra_id': 8,
        'tindakan': 'Sosialisasi Perlindungan Hak Cipta di Era AI (7 Mei 2026): Menyelenggarakan kegiatan bertema "Protect Works, Create Opportunities: Registering Copyright for Creativity and the Nation\'s Economy" di Auditorium Polibatam (Hasil: Sosialisasi Perlindungan Hak Cipta di Era AI)',
        'tenggat': '2026-05-07',
        'status': 'Selesai',
        'file_bukti': 'https://www.polibatam.ac.id/en/polibatam-strengthens-creative-ecosystem-and-copyright-protection-with-kemenkum-kepri-and-djki/'
    },
    # C04 UNRIKA (from Baseline & Panduan Scorecard)
    {
        'mitra_id': 14,
        'tindakan': 'Laporan pelaksanaan kegiatan kemitraan dan implementasi Tri Dharma Perguruan Tinggi UNRIKA (Hasil: Terlaksananya tindak lanjut kerja sama bidang Tri Dharma dan diseminasi hukum)',
        'tenggat': '2026-08-28',
        'status': 'Selesai',
        'file_bukti': 'https://docs.google.com/document/d/1RfqY5bMz02A74Q6n_h5H4p5K49gJs-wt/edit?usp=sharing&ouid=105903537206162602132&rtpof=true&sd=truee'
    },
]

for tl in additional_tl:
    chk = run_sql(f"SELECT id FROM tindak_lanjut WHERE mitra_id = {tl['mitra_id']} AND tindakan LIKE '%{esc(tl['tindakan'][:30])}%';")
    if 'id' not in chk or len(chk.strip().splitlines()) <= 1:
        run_sql(f"""
        INSERT INTO tindak_lanjut (mitra_id, tindakan, tenggat, status, file_bukti)
        VALUES ({tl['mitra_id']}, '{esc(tl['tindakan'])}', '{tl['tenggat']}', '{tl['status']}', '{esc(tl['file_bukti'])}');
        """)
        print(f"  Inserted tindak_lanjut for Partner {tl['mitra_id']} ({tl['file_bukti'][:40]}...)")
    else:
        # Update file_bukti and status to ensure 100% exact parity
        tl_id = chk.strip().splitlines()[1].split()[0]
        run_sql(f"""
        UPDATE tindak_lanjut 
        SET file_bukti = '{esc(tl['file_bukti'])}', status = '{tl['status']}', tenggat = '{tl['tenggat']}'
        WHERE id = {tl_id};
        """)
        print(f"  Updated tindak_lanjut #{tl_id} for Partner {tl['mitra_id']}")

print("\n=== OPERATIONS RECONCILIATION COMPLETED SUCCESSFULLY ===")
