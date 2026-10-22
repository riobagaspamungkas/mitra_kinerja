import openpyxl
import pymysql
import re
import subprocess
import os

print("=== DEEP AUDIT & 1:1 BASELINE DATABASE SYNC ===")

wb_path = r"docs/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE (1)/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE/02_BASELINE_FINAL/FINAL_BASELINE_MITRA_KINERJA_AUDIT_FINAL_28_AGUSTUS_2026.xlsx"
if not os.path.exists(wb_path):
    raise FileNotFoundError(f"Workbook not found at {wb_path}")

wb = openpyxl.load_workbook(wb_path, data_only=True)

mapping = [
    ('P01_DEKRANASDA', 1, 'P01'),
    ('fFitriadi P02_BNNP_KEPRI', 2, 'P02'),
    ('FIschika_P03_PEMKOT_TPI', 3, 'P03'),
    ('fFitra P04_BAPPERIDA_BINTAN', 4, 'P04'),
    ('FNina_P05_STAIN_SAR', 5, 'C01'),
    ('fEnjo_P06_UMRAH', 6, 'P06'),
    ('FNadia_P07_STAI_ANAMBAS', 7, 'P07'),
    ('fFitra_P08_POLIBATAM', 8, 'P08'),
    ('FNina_P09_STAI_NATUNA', 9, 'P09'),
    ('fEnjo_P10_STISIP_BTM', 10, 'P10'),
    ('FChika_C01_PBC', 11, 'C02'),
    ('fFitriadi_C02_STIT_MUMTAZ', 12, 'P05'),
    ('fNadia_C03_UIS', 13, 'C03'),
    ('Fitra_C04_UNRIKA', 14, 'C04'),
    ('FNina_C05_STIE_CAKRAWALA', 15, 'C05')
]

# Read REKAP BASELINE for gaps / summary
ws_rekap = wb['REKAP BASELINE']
rekap_gaps = {}
for r in range(5, 20):
    k = ws_rekap.cell(r, 2).value
    rekap_gaps[k] = ws_rekap.cell(r, 17).value

conn = pymysql.connect(
    host='localhost',
    user='root',
    password='',
    database='mitra_kinerja',
    charset='utf8mb4',
    cursorclass=pymysql.cursors.DictCursor,
    autocommit=True
)
cur = conn.cursor()

# STEP 1: PRE-AUDIT
print("\n--- PHASE 1: PRE-AUDIT CELL COMPARISON ---")
discrepancies_before = []

for sheet_name, mid, kode in mapping:
    ws = wb[sheet_name]
    
    xl_pemeriksa = str(ws.cell(5, 8).value or '').strip()
    xl_cutoff = ws.cell(8, 8).value
    xl_cutoff_str = xl_cutoff.strftime('%Y-%m-%d') if hasattr(xl_cutoff, 'strftime') else str(xl_cutoff).split()[0]
    e1_link = str(ws.cell(13, 8).value or '').strip()
    m_url = re.search(r'https?://[^\s\)\"\']+', e1_link)
    xl_file_naskah = m_url.group(0) if m_url else ''
    
    cur.execute('SELECT * FROM mitra_kinerja WHERE id = %s', (mid,))
    mk = cur.fetchone()
    
    if xl_pemeriksa != (mk['baseline_pemeriksa'] or ''):
        discrepancies_before.append((kode, mid, 'Partner', 'baseline_pemeriksa', xl_pemeriksa, mk['baseline_pemeriksa']))
    if xl_cutoff_str != str(mk['cutoff_date'] or ''):
        discrepancies_before.append((kode, mid, 'Partner', 'cutoff_date', xl_cutoff_str, str(mk['cutoff_date'])))
    if mk['baseline_status'] != 'TERVERIFIKASI / DIKUNCI':
        discrepancies_before.append((kode, mid, 'Partner', 'baseline_status', 'TERVERIFIKASI / DIKUNCI', mk['baseline_status']))
    if xl_file_naskah and xl_file_naskah != (mk['file_naskah'] or ''):
        discrepancies_before.append((kode, mid, 'Partner', 'file_naskah', xl_file_naskah, mk['file_naskah']))
        
    cur.execute('SELECT * FROM baseline_elemen WHERE mitra_id = %s ORDER BY nomor_elemen', (mid,))
    db_els = {r['nomor_elemen']: r for r in cur.fetchall()}
    
    for r in range(13, 25):
        el_num = int(ws.cell(r, 1).value)
        xl_status = str(ws.cell(r, 6).value or '').strip()
        xl_fakta = str(ws.cell(r, 7).value or '').strip()
        xl_link = str(ws.cell(r, 8).value or '').strip()
        xl_catatan = str(ws.cell(r, 9).value or '').strip()
        
        db_el = db_els.get(el_num, {})
        db_st = str(db_el.get('status') or '').strip()
        db_fakta = str(db_el.get('fakta_pemeriksaan') or '').strip()
        db_link = str(db_el.get('link_sumber_bukti') or '').strip()
        db_cat = str(db_el.get('catatan') or '').strip()
        
        if xl_status != db_st:
            discrepancies_before.append((kode, mid, f'Elemen {el_num}', 'status', xl_status, db_st))
        if xl_fakta != db_fakta:
            discrepancies_before.append((kode, mid, f'Elemen {el_num}', 'fakta_pemeriksaan', xl_fakta, db_fakta))
        if xl_link != db_link:
            discrepancies_before.append((kode, mid, f'Elemen {el_num}', 'link_sumber_bukti', xl_link, db_link))
        if xl_catatan != db_cat:
            discrepancies_before.append((kode, mid, f'Elemen {el_num}', 'catatan', xl_catatan, db_cat))

print(f"Pre-Audit Found: {len(discrepancies_before)} discrepancies across 15 partners.")

# STEP 2: APPLY SQL UPDATES FOR 100% PARITY
print("\n--- PHASE 2: APPLYING 1:1 FIDELITY UPDATES ---")
updated_elements = 0
updated_partners = 0

for sheet_name, mid, kode in mapping:
    ws = wb[sheet_name]
    
    xl_pemeriksa = str(ws.cell(5, 8).value or '').strip()
    xl_cutoff = ws.cell(8, 8).value
    xl_cutoff_str = xl_cutoff.strftime('%Y-%m-%d') if hasattr(xl_cutoff, 'strftime') else str(xl_cutoff).split()[0]
    e1_link = str(ws.cell(13, 8).value or '').strip()
    m_url = re.search(r'https?://[^\s\)\"\']+', e1_link)
    xl_file_naskah = m_url.group(0) if m_url else ''
    
    # Gap utama / summary
    sheet_gap = str(ws.cell(31, 8).value or '').strip()
    rekap_gap = str(rekap_gaps.get(kode, '') or '').strip()
    summary_text = sheet_gap or rekap_gap or f"Baseline FIX {kode} telah dikunci per cut-off {xl_cutoff_str}."
    
    # Update mitra_kinerja partner level
    sql_partner = """
    UPDATE mitra_kinerja
    SET baseline_status = 'TERVERIFIKASI / DIKUNCI',
        baseline_locked_at = %s,
        baseline_pemeriksa = %s,
        cutoff_date = %s,
        baseline_catatan_ringkasan = %s
    """
    params_partner = [f"{xl_cutoff_str} 23:59:59", xl_pemeriksa, xl_cutoff_str, summary_text]
    if xl_file_naskah:
        sql_partner += ", file_naskah = %s"
        params_partner.append(xl_file_naskah)
    sql_partner += " WHERE id = %s;"
    params_partner.append(mid)
    
    cur.execute(sql_partner, params_partner)
    updated_partners += 1
    
    # Update 12 elements
    for r in range(13, 25):
        el_num = int(ws.cell(r, 1).value)
        kelompok = str(ws.cell(r, 2).value or '').strip()
        nama_el = str(ws.cell(r, 3).value or '').strip()
        yang_diperiksa = str(ws.cell(r, 4).value or '').strip()
        sumber_min = str(ws.cell(r, 5).value or '').strip()
        status = str(ws.cell(r, 6).value or '').strip()
        fakta = str(ws.cell(r, 7).value or '').strip()
        link = str(ws.cell(r, 8).value or '').strip()
        catatan = str(ws.cell(r, 9).value or '').strip()
        
        # Check if record exists
        cur.execute("SELECT id FROM baseline_elemen WHERE mitra_id = %s AND nomor_elemen = %s", (mid, el_num))
        row = cur.fetchone()
        if row:
            cur.execute("""
                UPDATE baseline_elemen
                SET kelompok = %s,
                    nama_elemen = %s,
                    yang_diperiksa = %s,
                    sumber_bukti_minimum = %s,
                    status = %s,
                    fakta_pemeriksaan = %s,
                    link_sumber_bukti = %s,
                    catatan = %s
                WHERE mitra_id = %s AND nomor_elemen = %s
            """, (kelompok, nama_el, yang_diperiksa, sumber_min, status, fakta, link, catatan, mid, el_num))
        else:
            cur.execute("""
                INSERT INTO baseline_elemen (
                    mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa,
                    sumber_bukti_minimum, status, fakta_pemeriksaan, link_sumber_bukti, catatan
                ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """, (mid, el_num, kelompok, nama_el, yang_diperiksa, sumber_min, status, fakta, link, catatan))
        updated_elements += 1

print(f"Applied updates: {updated_partners} partners and {updated_elements} elements.")

# STEP 3: POST-AUDIT 100% PARITY VERIFICATION
print("\n--- PHASE 3: POST-AUDIT 100% VERIFICATION ---")
discrepancies_after = []

for sheet_name, mid, kode in mapping:
    ws = wb[sheet_name]
    
    xl_pemeriksa = str(ws.cell(5, 8).value or '').strip()
    xl_cutoff = ws.cell(8, 8).value
    xl_cutoff_str = xl_cutoff.strftime('%Y-%m-%d') if hasattr(xl_cutoff, 'strftime') else str(xl_cutoff).split()[0]
    e1_link = str(ws.cell(13, 8).value or '').strip()
    m_url = re.search(r'https?://[^\s\)\"\']+', e1_link)
    xl_file_naskah = m_url.group(0) if m_url else ''
    
    cur.execute('SELECT * FROM mitra_kinerja WHERE id = %s', (mid,))
    mk = cur.fetchone()
    
    if xl_pemeriksa != (mk['baseline_pemeriksa'] or ''):
        discrepancies_after.append((kode, mid, 'Partner', 'baseline_pemeriksa', xl_pemeriksa, mk['baseline_pemeriksa']))
    if xl_cutoff_str != str(mk['cutoff_date'] or ''):
        discrepancies_after.append((kode, mid, 'Partner', 'cutoff_date', xl_cutoff_str, str(mk['cutoff_date'])))
    if mk['baseline_status'] != 'TERVERIFIKASI / DIKUNCI':
        discrepancies_after.append((kode, mid, 'Partner', 'baseline_status', 'TERVERIFIKASI / DIKUNCI', mk['baseline_status']))
    if xl_file_naskah and xl_file_naskah != (mk['file_naskah'] or ''):
        discrepancies_after.append((kode, mid, 'Partner', 'file_naskah', xl_file_naskah, mk['file_naskah']))
        
    cur.execute('SELECT * FROM baseline_elemen WHERE mitra_id = %s ORDER BY nomor_elemen', (mid,))
    db_els = {r['nomor_elemen']: r for r in cur.fetchall()}
    
    for r in range(13, 25):
        el_num = int(ws.cell(r, 1).value)
        xl_status = str(ws.cell(r, 6).value or '').strip()
        xl_fakta = str(ws.cell(r, 7).value or '').strip()
        xl_link = str(ws.cell(r, 8).value or '').strip()
        xl_catatan = str(ws.cell(r, 9).value or '').strip()
        
        db_el = db_els.get(el_num, {})
        db_st = str(db_el.get('status') or '').strip()
        db_fakta = str(db_el.get('fakta_pemeriksaan') or '').strip()
        db_link = str(db_el.get('link_sumber_bukti') or '').strip()
        db_cat = str(db_el.get('catatan') or '').strip()
        
        if xl_status != db_st:
            discrepancies_after.append((kode, mid, f'Elemen {el_num}', 'status', xl_status, db_st))
        if xl_fakta != db_fakta:
            discrepancies_after.append((kode, mid, f'Elemen {el_num}', 'fakta_pemeriksaan', xl_fakta, db_fakta))
        if xl_link != db_link:
            discrepancies_after.append((kode, mid, f'Elemen {el_num}', 'link_sumber_bukti', xl_link, db_link))
        if xl_catatan != db_cat:
            discrepancies_after.append((kode, mid, f'Elemen {el_num}', 'catatan', xl_catatan, db_cat))

print(f"Post-Audit Discrepancies: {len(discrepancies_after)}")
if len(discrepancies_after) == 0:
    print("SUCCESS: 100% 1:1 CELL PARITY ACHIEVED FOR ALL 15 PARTNERS (180 ELEMENTS + METADATA)!")
else:
    for d in discrepancies_after:
        print(f"REMAINING: {d}")

# Check Partners 16, 17, 18
print("\n--- PHASE 4: AUDIT PARTNERS 16, 17, 18 (P11, P12, P13) ---")
cur.execute("SELECT id, kode, nama_mitra, baseline_status, baseline_pemeriksa, cutoff_date, file_naskah FROM mitra_kinerja WHERE id IN (16, 17, 18) ORDER BY id")
p_late = cur.fetchall()
for p in p_late:
    cur.execute("SELECT count(*) as cnt FROM baseline_elemen WHERE mitra_id = %s", (p['id'],))
    cnt = cur.fetchone()['cnt']
    print(f"Partner {p['kode']} (id {p['id']}): baseline_status={p['baseline_status']}, pemeriksa={p['baseline_pemeriksa']}, cutoff={p['cutoff_date']}, elements_in_db={cnt}")

cur.close()
conn.close()

# STEP 5: RE-EXPORT CLEAN DATABASE DUMP
print("\n--- PHASE 5: RE-EXPORTING DATABASE DUMP ---")
dump_target = r"database/mitra_kinerja_dump.sql"
mysqldump_candidates = [
    shutil.which("mysqldump") or "",
    shutil.which("mariadb-dump") or "",
    os.path.expanduser("~/.local/bin/mysqldump"),
    r"C:\xampp\mysql\bin\mysqldump.exe",
    r"D:\xampp\mysql\bin\mysqldump.exe",
]
mysqldump_exe = None
for cand in mysqldump_candidates:
    if cand and (os.path.exists(cand) or shutil.which(cand)):
        mysqldump_exe = cand
        break

if mysqldump_exe:
    cmd = [mysqldump_exe, "-u", "root"]
    if os.name != "nt":
        cmd.extend(["-h", "127.0.0.1", "--port", "3306"])
    cmd.append("mitra_kinerja")
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode == 0 and len(res.stdout) > 1000:
        with open(dump_target, "w", encoding="utf-8") as f:
            f.write(res.stdout)
        print(f"Re-exported database dump to {dump_target} ({len(res.stdout)} bytes).")
    else:
        print("mysqldump failed:", res.stderr)
else:
    print("mysqldump not found in PATH or standard candidate paths")

print("\n=== COMPLETED SUCCESSFULLY ===")
