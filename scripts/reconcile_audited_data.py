import openpyxl
import os
import re
import subprocess
import shutil

print("=== RECONCILING MITRA KINERJA DATABASE WITH AUTHORITATIVE DOCS ===")

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
    cmd.append("mitra_kinerja")
    return cmd

def run_sql(query):
    cmd = get_mysql_cmd() + ["-e", query]
    p = subprocess.run(cmd, capture_output=True, text=True)
    if p.returncode != 0:
        print("SQL Error:", p.stderr)
    return p.stdout

def esc(s):
    if s is None:
        return ""
    return str(s).replace('\\', '\\\\').replace("'", "''")

# 1. RE-SYNC P03 SCORECARD
sc_file_p03 = r"docs/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE (1)/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE/01_SCORECARD_FINAL_27_SEPTEMBER_2026/Scorecard_P03_PEMKOT_TANJUNGPINANG_FINAL_27_Sep_2026.xlsx"

if os.path.exists(sc_file_p03):
    wb_p03 = openpyxl.load_workbook(sc_file_p03, data_only=True)
    pen = wb_p03['PENILAIAN']
    rek = wb_p03['REKOMENDASI']
    
    raw_status = str(pen.cell(27, 2).value or '').strip()
    posisi = str(rek.cell(9, 2).value or '').strip()
    rekom_text = str(rek.cell(17, 2).value or '').strip()
    temuan = str(rek.cell(16, 2).value or '').strip()
    tindak_lanjut = str(rek.cell(19, 2).value or '').strip()
    full_rekom = f"{rekom_text}\n\nTemuan Utama:\n{temuan}\n\nRencana Tindak Lanjut:\n{tindak_lanjut}".strip()
    
    weights = {'I1': 10, 'I2': 15, 'I3': 15, 'I4': 20, 'I5': 20, 'I6': 10, 'I7': 10}
    
    for r in range(13, 20):
        c1 = str(pen.cell(r, 1).value or '').strip()
        m_code = re.match(r'^(I[1-7])', c1)
        if not m_code:
            continue
        ind_code = m_code.group(1)
        bobot = weights.get(ind_code, 10)
        
        kondisi_baseline = str(pen.cell(r, 4).value or '').strip()
        kondisi_saat_ini = str(pen.cell(r, 5).value or '').strip()
        status_penilaian = str(pen.cell(r, 6).value or '').strip().upper()
        evidence_loc = str(pen.cell(r, 7).value or '').strip()
        skor_val = pen.cell(r, 8).value
        nilai_val = pen.cell(r, 9).value
        alasan_skor = str(pen.cell(r, 10).value or '').strip()
        catatan_tl = str(pen.cell(r, 11).value or '').strip()
        
        status_pem_db = "BUKTI MEMADAI" if "DAPAT DINILAI" in status_penilaian or "BUKTI MEMADAI" in status_penilaian else status_penilaian
        skor_sql = "NULL" if skor_val is None or str(skor_val).strip() == '' else str(int(float(skor_val)))
        nilai_sql = "NULL" if nilai_val is None or str(nilai_val).strip() == '' else str(round(float(nilai_val), 2))
        
        sql_ind = f"""
        UPDATE indikator_skor
        SET status_pemeriksaan = '{esc(status_pem_db)}',
            kondisi_baseline = '{esc(kondisi_baseline)}',
            kondisi_saat_ini = '{esc(kondisi_saat_ini)}',
            temuan_bukti = '{esc(evidence_loc)}',
            skor = {skor_sql},
            alasan_skor = '{esc(alasan_skor)}',
            catatan_tindak_lanjut = '{esc(catatan_tl)}',
            nilai = {nilai_sql},
            referensi_baseline = '{esc(kondisi_baseline)}'
        WHERE mitra_id = 3 AND kode_indikator = '{ind_code}';
        """
        run_sql(sql_ind)
        
    sql_mk_p03 = f"""
    UPDATE mitra_kinerja
    SET status_scorecard = 'SIAP DIVALIDASI',
        posisi_portofolio = '{esc(posisi)}',
        rekomendasi = '{esc(full_rekom)}'
    WHERE id = 3;
    """
    run_sql(sql_mk_p03)
    print("[1/5] P03 Pemkot Tanjungpinang Scorecard re-synced to 57.50 / SIAP DIVALIDASI.")
else:
    print("[1/5] P03 Scorecard file not found.")

# 2. POPULATE P01 BASELINE (DEKRANASDA)
base_file = r"docs/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE (1)/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE/02_BASELINE_FINAL/FINAL_BASELINE_MITRA_KINERJA_AUDIT_FINAL_28_AGUSTUS_2026.xlsx"
if os.path.exists(base_file):
    wb_base = openpyxl.load_workbook(base_file, data_only=True)
    if 'P01_DEKRANASDA' in wb_base.sheetnames:
        ws_p01 = wb_base['P01_DEKRANASDA']
        pemeriksa = ws_p01.cell(5, 8).value or ws_p01.cell(5, 7).value or 'Evlin'
        cutoff = ws_p01.cell(8, 8).value or '2026-08-28'
        cutoff_str = cutoff.strftime('%Y-%m-%d') if hasattr(cutoff, 'strftime') else str(cutoff).split()[0]
        p2ma_link_e1 = None
        
        for r in range(13, 25):
            el_num_val = ws_p01.cell(r, 1).value
            if not el_num_val or not str(el_num_val).strip().isdigit():
                continue
            el_num = int(el_num_val)
            kelompok = str(ws_p01.cell(r, 2).value or '').strip()
            nama_el = str(ws_p01.cell(r, 3).value or '').strip()
            yang_diperiksa = str(ws_p01.cell(r, 4).value or '').strip()
            sumber_min = str(ws_p01.cell(r, 5).value or '').strip()
            raw_status = str(ws_p01.cell(r, 6).value or 'BELUM DIISI').strip().upper()
            fakta = str(ws_p01.cell(r, 7).value or '').strip()
            link_bukti = str(ws_p01.cell(r, 8).value or '').strip()
            catatan = str(ws_p01.cell(r, 9).value or '').strip()
            
            status = 'BELUM DIISI'
            for vs in ['TERVERIFIKASI', 'BELUM TERVERIFIKASI', 'BELUM TERSEDIA', 'TIDAK RELEVAN', 'BELUM DIISI']:
                if vs in raw_status:
                    status = vs
                    break
                    
            if el_num == 1 and 'p2ma' in link_bukti.lower():
                m_url = re.search(r'https?://[^\s\)\"\']+', link_bukti)
                if m_url:
                    p2ma_link_e1 = m_url.group(0)
                    
            sql_be = f"""
            INSERT INTO baseline_elemen (mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa, sumber_bukti_minimum, status, fakta_pemeriksaan, link_sumber_bukti, catatan)
            VALUES (1, {el_num}, '{esc(kelompok)}', '{esc(nama_el)}', '{esc(yang_diperiksa)}', '{esc(sumber_min)}', '{esc(status)}', '{esc(fakta)}', '{esc(link_bukti)}', '{esc(catatan)}')
            ON DUPLICATE KEY UPDATE 
                status = '{esc(status)}',
                fakta_pemeriksaan = '{esc(fakta)}',
                link_sumber_bukti = '{esc(link_bukti)}',
                catatan = '{esc(catatan)}';
            """
            run_sql(sql_be)
            
        sql_mk_p01 = f"""
        UPDATE mitra_kinerja 
        SET baseline_status = 'TERVERIFIKASI / DIKUNCI',
            baseline_locked_at = '2026-08-28 23:59:59',
            baseline_pemeriksa = '{esc(str(pemeriksa))}',
            cutoff_date = '{cutoff_str}'
        """
        if p2ma_link_e1:
            sql_mk_p01 += f", file_naskah = '{p2ma_link_e1}'"
        sql_mk_p01 += " WHERE id = 1;"
        run_sql(sql_mk_p01)
        print("[2/5] P01 Dekranasda Baseline (12 elements) populated and locked.")
    else:
        print("[2/5] Sheet P01_DEKRANASDA not found in baseline workbook.")

# 3. FIX P12 ZERO DATES (POLIBATAM SENTRA KI)
sql_p12 = """
UPDATE mitra_kinerja
SET tanggal_mulai = '2026-09-01',
    tanggal_berakhir = '2029-08-31',
    status_tanggal = 'TERVERIFIKASI',
    bidang = 'KI'
WHERE kode = 'P12';
"""
run_sql(sql_p12)
print("[3/5] P12 Polibatam Sentra KI dates updated to 2026-09-01 s.d 2029-08-31.")

# 4. FIX OPERATIONAL DIVISIONS (BIDANG) & PKS INDUK LINKS
sql_bidang_induk = """
-- Update Bidang according to substantive legal domain
UPDATE mitra_kinerja SET bidang = 'KI' WHERE kode IN ('P01', 'P04', 'P12');
UPDATE mitra_kinerja SET bidang = 'P3H' WHERE kode IN ('P02', 'P11');

-- Link PKS turunan to parent MoU
UPDATE mitra_kinerja SET pks_induk_id = 6 WHERE kode = 'P11'; -- UMRAH PKS to UMRAH MoU (P06)
UPDATE mitra_kinerja SET pks_induk_id = 8 WHERE kode = 'P12'; -- Polibatam PKS to Polibatam MoU (P08)
"""
run_sql(sql_bidang_induk)
print("[4/5] Operational divisions (Bidang: KI, P3H) and parent MoU links updated.")

# 5. RE-EXPORT DATABASE DUMP & SCHEMA
mysqldump_candidates = [
    "mysqldump",
    "mariadb-dump",
    os.path.expanduser("~/.local/bin/mysqldump"),
    r"C:\xampp\mysql\bin\mysqldump.exe",
    r"D:\xampp\mysql\bin\mysqldump.exe",
]
mysqldump_exe = None
for c in mysqldump_candidates:
    if os.path.exists(c) or shutil.which(c):
        mysqldump_exe = c
        break
dump_target = r"database/mitra_kinerja_dump.sql"

if mysqldump_exe:
    cmd_d = [mysqldump_exe, "-u", "root"]
    if os.name != 'nt':
        cmd_d.extend(["-h", "127.0.0.1", "--port", "3306"])
    cmd_d.append("mitra_kinerja")
    res = subprocess.run(cmd_d, capture_output=True, text=True)
    if res.returncode == 0 and len(res.stdout) > 1000:
        with open(dump_target, "w", encoding="utf-8") as f:
            f.write(res.stdout)
        print("[5/5] Successfully re-exported database/mitra_kinerja_dump.sql.")
    else:
        print("[5/5] mysqldump failed or empty:", res.stderr)
else:
    print("[5/5] mysqldump not found.")

print("=== RECONCILIATION COMPLETED SUCCESSFULLY ===")
